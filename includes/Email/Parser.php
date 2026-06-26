<?php
/**
 * Email parser.
 *
 * Extracts the artist-supplied description (prompt) and attached images
 * from an IMAP message or a raw RFC-2822 string (webhook path).
 *
 * @package Agnosis\Email
 */

declare(strict_types=1);

namespace Agnosis\Email;

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
	 * Parse an IMAP message into a submission array.
	 *
	 * @param \IMAP\Connection $conn    Open IMAP connection.
	 * @param int              $msg_num Message sequence number.
	 * @param object           $headers stdClass from imap_headerinfo().
	 *
	 * @return array<string, mixed>|null Submission data, or null if the message should be skipped.
	 */
	public function parse_imap_message( $conn, int $msg_num, object $headers ): ?array {
		$from        = $this->extract_from_address( $headers );
		$subject     = $this->decode_header( $headers->subject ?? '' );
		$structure   = imap_fetchstructure( $conn, $msg_num );
		$text_body   = '';
		$attachments = [];

		if ( false === $structure ) {
			return null;
		}

		$this->walk_structure( $conn, $msg_num, $structure, $text_body, $attachments );

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

	/**
	 * Recursively walk the MIME structure collecting text body and image attachments.
	 *
	 * @param \IMAP\Connection      $conn
	 * @param int                   $msg_num
	 * @param object                $structure
	 * @param string                &$text_body   Accumulated plain-text body.
	 * @param array<int, array<string, string>> &$attachments Accumulated attachments.
	 * @param string                $prefix       MIME section prefix.
	 */
	private function walk_structure(
		$conn,
		int $msg_num,
		object $structure,
		string &$text_body,
		array &$attachments,
		string $prefix = ''
	): void {
		if ( isset( $structure->parts ) && is_array( $structure->parts ) ) {
			foreach ( $structure->parts as $index => $part ) {
				$section = $prefix === '' ? (string) ( $index + 1 ) : $prefix . '.' . ( $index + 1 );
				$this->walk_structure( $conn, $msg_num, $part, $text_body, $attachments, $section );
			}
			return;
		}

		$section = $prefix ?: '1';
		$type    = strtolower( $structure->subtype ?? '' );
		$mime    = strtolower( $this->type_to_mime( $structure->type ?? 0 ) ) . '/' . $type;

		// Plain text body.
		if ( $mime === 'text/plain' ) {
			$raw = imap_fetchbody( $conn, $msg_num, $section );
			if ( false !== $raw ) {
				$text_body .= $this->decode_body( $raw, $structure->encoding ?? 0 );
			}
			return;
		}

		// Image attachment.
		if ( $this->is_allowed_mime( $mime ) ) {
			$raw = imap_fetchbody( $conn, $msg_num, $section );
			if ( false === $raw ) {
				return;
			}
			$data = $this->decode_body( $raw, $structure->encoding ?? 0 );

			if ( strlen( $data ) > self::MAX_BYTES ) {
				return;
			}

			$filename = $this->extract_filename( $structure );

			$attachments[] = [
				'filename' => $filename,
				'mime'     => $mime,
				'data'     => $data,
			];
		}
	}

	private function decode_body( string $raw, int $encoding ): string {
		switch ( $encoding ) {
			case 3: // BASE64
				return base64_decode( $raw );
			case 4: // QUOTED-PRINTABLE
				return quoted_printable_decode( $raw );
			default:
				return $raw;
		}
	}

	private function type_to_mime( int $type ): string {
		$map = [ 'text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other' ];
		return $map[ $type ] ?? 'application';
	}

	private function is_allowed_mime( string $mime ): bool {
		return in_array( strtolower( $mime ), self::ALLOWED_MIME, true );
	}

	private function extract_filename( object $structure ): string {
		foreach ( [ $structure->dparameters ?? [], $structure->parameters ?? [] ] as $params ) {
			foreach ( $params as $param ) {
				if ( strtolower( $param->attribute ) === 'filename' || strtolower( $param->attribute ) === 'name' ) {
					return sanitize_file_name( $this->decode_header( $param->value ) );
				}
			}
		}
		return 'artwork-' . uniqid() . '.jpg';
	}

	private function extract_from_address( object $headers ): string {
		$from_objects = $headers->from ?? [];
		if ( empty( $from_objects ) ) {
			return '';
		}
		$f = $from_objects[0];
		return sanitize_email( ( $f->mailbox ?? '' ) . '@' . ( $f->host ?? '' ) );
	}

	private function decode_header( string $header ): string {
		$decoded = imap_mime_header_decode( $header );
		$result  = '';
		if ( false !== $decoded ) {
			foreach ( $decoded as $part ) {
				$result .= $part->text;
			}
		}
		return sanitize_text_field( $result );
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
