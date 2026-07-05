<?php
/**
 * Email parser.
 *
 * Extracts the artist-supplied description (prompt) and attached media
 * (images, audio, video) from a webklex Message object (IMAP path) or a raw
 * RFC-2822 payload (webhook path).
 *
 * @package Agnosis\Email
 */

declare(strict_types=1);

namespace Agnosis\Email;

use Webklex\PHPIMAP\Message;

class Parser {

	/**
	 * Allowed attachment MIME types.
	 *
	 * Images are described and (optionally) enhanced as-is. Audio is
	 * transcribed and published as-is (MediaAdapter/Pipeline). Video has its
	 * first frame extracted for AI description and a poster image, but the
	 * original video file is what actually gets published — see
	 * MediaAdapter::adapt() and Pipeline::process_video_single().
	 */
	private const ALLOWED_MIME = [
		// Images
		'image/jpeg',
		'image/jpg',
		'image/png',
		'image/webp',
		'image/gif',
		'image/tiff',
		// Audio
		'audio/mpeg',
		'audio/mp3',
		'audio/wav',
		'audio/x-wav',
		'audio/mp4',
		'audio/x-m4a',
		'audio/ogg',
		'audio/flac',
		// Video
		'video/mp4',
		'video/quicktime',
		'video/x-msvideo',
		'video/webm',
		'video/ogg',
		'video/mpeg',
	];

	/**
	 * Maximum attachment size, per media category. Video and audio files are
	 * naturally much larger than a photograph, so a single flat cap either
	 * rejects reasonable short clips or lets images through far larger than
	 * they need to be.
	 *
	 * These are ceilings on what Agnosis itself will accept — they do not
	 * override lower limits imposed upstream: a mail provider's own
	 * attachment cap (Gmail, for example, tops out around 25 MB), or, on the
	 * webhook path, this server's own `upload_max_filesize`/`post_max_size`
	 * PHP settings. A submission can still fail silently below these numbers
	 * if either of those is more restrictive — check both if large video
	 * submissions aren't arriving.
	 */
	private const MAX_BYTES_IMAGE = 20 * 1024 * 1024;   // 20 MB
	private const MAX_BYTES_AUDIO = 50 * 1024 * 1024;   // 50 MB
	private const MAX_BYTES_VIDEO = 150 * 1024 * 1024;  // 150 MB

	/** Fallback file extension per MIME type, used when an attachment arrives with no filename. */
	private const EXTENSION_FOR_MIME = [
		'image/jpeg'       => 'jpg',
		'image/jpg'        => 'jpg',
		'image/png'        => 'png',
		'image/webp'       => 'webp',
		'image/gif'        => 'gif',
		'image/tiff'       => 'tiff',
		'audio/mpeg'       => 'mp3',
		'audio/mp3'        => 'mp3',
		'audio/wav'        => 'wav',
		'audio/x-wav'      => 'wav',
		'audio/mp4'        => 'm4a',
		'audio/x-m4a'      => 'm4a',
		'audio/ogg'        => 'ogg',
		'audio/flac'       => 'flac',
		'video/mp4'        => 'mp4',
		'video/quicktime'  => 'mov',
		'video/x-msvideo'  => 'avi',
		'video/webm'       => 'webm',
		'video/ogg'        => 'ogv',
		'video/mpeg'       => 'mpeg',
	];

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

		// --- Recipient (To:) — primary routing signal ---
		$to_addresses = $message->getTo()->toArray();
		$to_address   = '';
		if ( ! empty( $to_addresses ) ) {
			$to_address = strtolower( sanitize_email( (string) $to_addresses[0]->mail ) );
		}

		// --- Subject ---
		$subject = sanitize_text_field( (string) $message->getSubject() );

		// --- Plain-text body ---
		// getTextBody() returns string — cast directly, no ?? needed.
		$text_body = (string) $message->getTextBody();

		// --- Attachments (image, audio, or video) ---
		$attachments = [];

		foreach ( $message->getAttachments() as $attachment ) {
			$mime = strtolower( (string) $attachment->getMimeType() );

			if ( ! $this->is_allowed_mime( $mime ) ) {
				continue;
			}

			$data = (string) $attachment->getContent();

			if ( strlen( $data ) > $this->max_bytes_for( $mime ) ) {
				continue;
			}

			$name = (string) ( $attachment->getName() ?? '' );

			$attachments[] = [
				'filename' => sanitize_file_name( $name ?: 'submission-' . uniqid() . '.' . $this->extension_for( $mime ) ),
				'mime'     => $mime,
				'data'     => $data,
			];
		}

		if ( empty( $attachments ) ) {
			return null; // No usable attachments — skip.
		}

		$artist_id = $this->resolve_artist( $from );

		return [
			'from'        => $from,
			'to_address'  => $to_address,
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
		$to_address  = strtolower( sanitize_email( $payload['recipient'] ?? $payload['to'] ?? '' ) );
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
			if ( $file['size'] > $this->max_bytes_for( $file['type'] ) ) {
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
			'to_address'  => $to_address,
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

	/** Maximum accepted byte size for a given attachment MIME type. */
	private function max_bytes_for( string $mime ): int {
		$mime = strtolower( $mime );
		if ( str_starts_with( $mime, 'video/' ) ) {
			return self::MAX_BYTES_VIDEO;
		}
		if ( str_starts_with( $mime, 'audio/' ) ) {
			return self::MAX_BYTES_AUDIO;
		}
		return self::MAX_BYTES_IMAGE;
	}

	/** Fallback file extension for an attachment that arrived with no filename. */
	private function extension_for( string $mime ): string {
		return self::EXTENSION_FOR_MIME[ strtolower( $mime ) ] ?? 'jpg';
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
