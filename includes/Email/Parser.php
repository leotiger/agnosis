<?php
/**
 * Email parser.
 *
 * Extracts the artist-supplied description (prompt) and attached images
 * from a webklex Message object (IMAP path) or a raw RFC-2822 payload
 * (webhook path).
 *
 * @package Agnosis\Email
 */

declare(strict_types=1);

namespace Agnosis\Email;

use Webklex\PHPIMAP\Message;

class Parser {

	/** Allowed image MIME types. */
	private const ALLOWED_MIME = [
		'image/jpeg',
		'image/jpg',
		'image/png',
		'image/webp',
		'image/gif',
		'image/tiff',
	];

	/** Maximum attachment size: 20 MB. */
	private const MAX_BYTES = 20 * 1024 * 1024;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Parse a webklex IMAP Message into a submission array.
	 *
	 * webklex/php-imap handles all MIME decoding, base64/QP decoding, and
	 * header unfolding — no manual structure walking required.
	 *
	 * @param Message $message Webklex message object from a connected folder query.
	 * @return array<string, mixed>|null Submission data, or null if the message should be skipped.
	 */
	public function parse_imap_message( Message $message ): ?array {
		// --- Sender ---
		// getFrom() returns an Attribute whose ->toArray() yields address objects.
		$from_addresses = $message->getFrom()->toArray();
		$from = '';
		if ( ! empty( $from_addresses ) ) {
			$from = sanitize_email( (string) $from_addresses[0]->mail );
		}

		// --- Subject ---
		$subject = sanitize_text_field( (string) $message->getSubject() );

		// --- Plain-text body ---
		// getTextBody() returns string — cast directly, no ?? needed.
		$text_body = (string) $message->getTextBody();

		// --- Image attachments ---
		$attachments = [];

		foreach ( $message->getAttachments() as $attachment ) {
			$mime = strtolower( (string) $attachment->getMimeType() );

			if ( ! $this->is_allowed_mime( $mime ) ) {
				continue;
			}

			$data = (string) $attachment->getContent();

			if ( strlen( $data ) > self::MAX_BYTES ) {
				continue;
			}

			$name = (string) ( $attachment->getName() ?? '' );

			$attachments[] = [
				'filename' => sanitize_file_name( $name ?: 'artwork-' . uniqid() . '.jpg' ),
				'mime'     => $mime,
				'data'     => $data,
			];
		}

		if ( empty( $attachments ) ) {
			return null; // No images — skip.
		}

		$artist_id = $this->resolve_artist( $from );

		return [
			'from'        => $from,
			'subject'     => $subject,
			'description' => $this->clean_text( $text_body ),
			'attachments' => $attachments,
			'artist_id'   => $artist_id,
			'source'      => 'imap',
		];
	}

	/**
	 * Parse a raw email payload forwarded by a webhook provider (Mailgun, SendGrid…).
	 *
	 * @param array<string, mixed> $payload POST data from webhook.
	 * @return array<string, mixed>|null
	 */
	public function parse_webhook_payload( array $payload ): ?array {
		$from        = sanitize_email( $payload['sender'] ?? $payload['from'] ?? '' );
		$subject     = sanitize_text_field( $payload['subject'] ?? '' );
		$description = sanitize_textarea_field( $payload['stripped-text'] ?? $payload['text'] ?? '' );
		$attachments = [];

		// Mailgun / SendGrid attach files differently — handle both.
		$attachment_count = (int) ( $payload['attachment-count'] ?? 0 );
		for ( $i = 1; $i <= $attachment_count; $i++ ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- request authenticated via HMAC in Webhook::verify_signature(); file type/size validated below.
			$file = $_FILES[ 'attachment-' . $i ] ?? null;
			if ( null === $file || ! $this->is_allowed_mime( $file['type'] ) ) {
				continue;
			}
			if ( $file['size'] > self::MAX_BYTES ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data = file_get_contents( $file['tmp_name'] );
			if ( false !== $data ) {
				$attachments[] = [
					'filename' => sanitize_file_name( $file['name'] ),
					'mime'     => $file['type'],
					'data'     => $data,
				];
			}
		}

		if ( empty( $attachments ) ) {
			return null;
		}

		return [
			'from'        => $from,
			'subject'     => $subject,
			'description' => $this->clean_text( $description ),
			'attachments' => $attachments,
			'artist_id'   => $this->resolve_artist( $from ),
			'source'      => 'webhook',
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function is_allowed_mime( string $mime ): bool {
		return in_array( strtolower( $mime ), self::ALLOWED_MIME, true );
	}

	private function clean_text( string $text ): string {
		// Strip email signatures (lines starting with -- ).
		$text = preg_replace( '/^--\s*$.*/ms', '', $text ) ?? '';
		return sanitize_textarea_field( trim( $text ) );
	}

	/**
	 * Resolve artist WordPress user ID from email address.
	 * Returns null if the artist is not yet registered/admitted.
	 */
	private function resolve_artist( string $email ): ?int {
		if ( empty( $email ) ) {
			return null;
		}
		$user = get_user_by( 'email', $email );
		return $user instanceof \WP_User ? $user->ID : null;
	}
}
