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

use Agnosis\Core\Debug;
use Agnosis\Core\Logger;
use Webklex\PHPIMAP\Message;

class Parser {

	/**
	 * Set right before parse_imap_message()/parse_webhook_payload() returns
	 * null, so the caller (Inbox::process_messages()) can tell WHY without
	 * changing the long-established `?array` return contract itself.
	 * '' means the most recent parse either succeeded or fell through to the
	 * generic no-attachments/no-text rejection (Inbox's existing default).
	 * Reset at the top of each call — a single Parser instance is reused
	 * across every message in one poll cycle, so a stale value from a
	 * previous message must never leak into the next one's decision.
	 *
	 * @var string
	 */
	private string $last_rejection_reason = '';

	/**
	 * @see $last_rejection_reason
	 */
	public function last_rejection_reason(): string {
		return $this->last_rejection_reason;
	}

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
		// HEIC/HEIF — the default photo format on iPhones (Settings → Camera →
		// Formats → "High Efficiency") unless the artist switched to "Most
		// Compatible". Accepted here so the file isn't silently dropped at
		// intake; MediaAdapter::adapt() converts it to JPEG (when the server's
		// Imagick build supports it) before it ever reaches the AI vision call
		// or WordPress's media library — neither can read raw HEIC/HEIF.
		'image/heic',
		'image/heif',
		'image/heic-sequence',
		'image/heif-sequence',
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
		'image/heic'       => 'heic',
		'image/heif'       => 'heif',
		'image/heic-sequence' => 'heic',
		'image/heif-sequence' => 'heif',
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
		$this->last_rejection_reason = '';

		// Inbox::query_messages() deliberately builds its IMAP query with
		// fetchBody(false) — the initial listing pulls headers only, for
		// speed, so the cheap gates in Inbox::process_messages() (admitted
		// sender, throttle, auth) can reject a message before ever paying
		// for a full body fetch. webklex's Message does NOT lazily fetch the
		// body on its own: getAttachments()/getTextBody()/getStructure()
		// simply return whatever was populated when the message object was
		// built. This class's query path goes through Query::populate(),
		// which always constructs a Structure object — even for a
		// fetchBody(false) message, from an empty raw body string — so
		// getStructure() is NEVER null here; a first attempt at this fix
		// guarded on `null === getStructure()` and consequently never
		// actually re-fetched anything (confirmed via the body-fetch
		// diagnostic below logging "structure null before=no" — i.e.
		// already non-null, just empty, before parseBody() was ever
		// attempted). The real signal that the body was never fetched is a
		// Structure with zero parts: even a plain single-part email always
		// produces at least one Part once real content has been parsed
		// (Structure::find_parts()'s non-multipart branch), so zero parts
		// only happens when the raw body behind it was empty.
		$structure = $message->getStructure();
		$needs_fetch = ( null === $structure || empty( $structure->parts ) );
		$parse_body_threw = null;

		if ( $needs_fetch ) {
			try {
				$message->parseBody();
			} catch ( \Throwable $e ) {
				$parse_body_threw = $e->getMessage();
			}
			$structure = $message->getStructure();
		}

		Logger::info(
			sprintf(
				'Parser: UID %s body-fetch diagnostic — fetch attempted=%s, parseBody() threw=%s, parts after=%d.',
				(string) $message->getUid(),
				$needs_fetch ? 'yes' : 'no (structure already populated)',
				$parse_body_threw ?? '(no)',
				$structure ? count( $structure->parts ) : 0
			),
			'inbox'
		);

		if ( null !== $parse_body_threw ) {
			return null;
		}

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

		// --- Primary recipient only (2026-07-15) ---
		// PostCreator::resolve_post_type() matches aliases against this list —
		// previously (fifth audit §5a) it held every To:/Cc: recipient, so a
		// message reached remove@/promote@/etc. even via Cc: or a non-first To:
		// address. That broadening is now intentionally reversed per explicit
		// product policy: we don't accept Cc:, and only the FIRST To: address
		// counts — a message addressed to us any other way is rejected outright
		// by Inbox's recognised-recipient/BCC gate before parse_imap_message()
		// is even reached (see Inbox::message_recipient_addresses()'s own
		// docblock for the IMAP-side half of this same policy). $all_recipients
		// is therefore just $to_address itself now, wrapped as a single-element
		// array purely so resolve_post_type()'s existing array-shaped
		// 'to_addresses' contract needs no changes.
		$all_recipients = '' !== $to_address ? [ $to_address ] : [];

		// --- Subject ---
		$subject = sanitize_text_field( (string) $message->getSubject() );

		// --- Plain-text body ---
		// getTextBody() returns string — cast directly, no ?? needed.
		$text_body = (string) $message->getTextBody();

		// --- Reply/quote rejection (2026-07-15) — checked before attachment
		// processing, deliberately: a reply carrying a genuine attachment is
		// still rejected, since the policy is about original, curated content,
		// not about whether a file happens to be attached. See
		// IntakeGates::is_reply_or_quote()'s own docblock for exactly what's
		// matched and why (including the Reply-To-driven "just hit reply to
		// submit again" feature this is meant to catch when the artist doesn't
		// clear the quoted thread first).
		if ( IntakeGates::is_reply_or_quote( $subject, $text_body ) ) {
			Logger::info(
				'Parser: UID ' . (string) $message->getUid() . ' looks like a reply or quoted message, not an original submission — skipped.',
				'inbox'
			);
			$this->last_rejection_reason = 'looks_like_reply';
			return null;
		}

		// --- Attachments (image, audio, or video) ---
		$attachments = [];

		// Verbose trace, only ever assembled when Debug::enabled() — a plain
		// array of lines rather than string concatenation so the expensive
		// part is skipped entirely (empty array, no work) when debug logging
		// is off. Always written at the end of this method, on every path
		// (success, size-cap skip, or an empty result), because the most
		// useful case ("no valid attachments" with a genuinely attached
		// photo) is exactly the one that previously left zero trace of why.
		$debug_enabled = Debug::enabled();
		$debug_lines   = [];

		if ( $debug_enabled ) {
			$debug_lines[] = sprintf( 'UID: %s', (string) $message->getUid() );
			$debug_lines[] = sprintf( 'From: %s', $from );
			$debug_lines[] = sprintf( 'To: %s', $to_address );
			$debug_lines[] = sprintf( 'Subject: %s', $subject );
			$debug_lines[] = '';

			// Raw MIME part tree, straight from webklex's own structure parser —
			// this is the ground truth of what the message actually contains,
			// independent of whatever getAttachments() decided counts as an
			// "attachment". If a genuinely attached photo is missing from the
			// getAttachments() dump below but shows up here, the problem is in
			// webklex's Part::isAttachment() filtering, not in this class's MIME
			// allowlist or size cap.
			$debug_lines[] = '--- Raw structure (all MIME parts) ---';
			try {
				$structure = $message->getStructure();
				$parts     = $structure ? $structure->parts : [];
				if ( empty( $parts ) ) {
					$debug_lines[] = '(no parts — getStructure() returned null or empty)';
				}
				foreach ( $parts as $i => $part ) {
					$debug_lines[] = sprintf(
						'  [%d] type=%s subtype=%s disposition=%s filename=%s name=%s bytes=%d isAttachment=%s',
						$i,
						(string) ( $part->type ?? '?' ),
						(string) ( $part->subtype ?? '(none)' ),
						(string) ( $part->disposition ?? '(none)' ),
						(string) ( $part->filename ?? '(none)' ),
						(string) ( $part->name ?? '(none)' ),
						strlen( (string) ( $part->content ?? '' ) ),
						method_exists( $part, 'isAttachment' ) ? ( $part->isAttachment() ? 'yes' : 'no' ) : '?'
					);
				}
			} catch ( \Throwable $e ) {
				$debug_lines[] = sprintf( '(exception reading structure: %s)', $e->getMessage() );
			}
			$debug_lines[] = '';

			$debug_lines[] = '--- getAttachments() (webklex\'s own attachment filter) ---';
		}

		try {
			$raw_attachments = $message->getAttachments();
		} catch ( \Throwable $e ) {
			if ( $debug_enabled ) {
				$debug_lines[] = sprintf( '(exception calling getAttachments(): %s)', $e->getMessage() );
				Debug::write( 'parser-attachments', implode( "\n", $debug_lines ) );
			}
			Logger::error( 'Parser: getAttachments() threw: ' . $e->getMessage(), 'inbox' );
			return null;
		}

		if ( $debug_enabled ) {
			$debug_lines[] = sprintf( 'Count: %d', count( $raw_attachments ) );
		}

		foreach ( $raw_attachments as $i => $attachment ) {
			$mime      = strtolower( (string) $attachment->getMimeType() );
			$declared  = strtolower( (string) $attachment->getContentType() );
			$name      = (string) ( $attachment->getName() ?? '' );
			$disp      = (string) ( $attachment->getDisposition() ?: '(none)' );
			$mime_used = $mime;
			$decision  = 'accepted';

			if ( ! $this->is_allowed_mime( $mime ) ) {
				// webklex's Attachment::getMimeType() never reads the email's own
				// declared Content-Type header — it re-sniffs the type from the
				// decoded binary via PHP's fileinfo extension only. If that sniff
				// returns something unexpected (a decoding hiccup, an unusual
				// encoder, unrelated server fileinfo quirks) a genuine, properly
				// attached photo can be wrongly rejected here even though the
				// sending mail client correctly labelled it. Fall back to the
				// declared Content-Type header in that case — a real image/jpeg
				// (etc.) declaration from the sending mail client is trustworthy,
				// and is the same value most other mail tooling relies on.
				Logger::info(
					sprintf(
						'Parser: attachment "%s" — sniffed mime "%s" not allowed; declared Content-Type "%s"; disposition "%s".',
						$name ?: 'unknown',
						$mime ?: '(empty)',
						$declared ?: '(empty)',
						$disp
					),
					'inbox'
				);

				if ( $this->is_allowed_mime( $declared ) ) {
					$mime_used = $declared;
				} else {
					$decision = 'rejected: neither sniffed nor declared mime is allowed';
					if ( $debug_enabled ) {
						$debug_lines[] = sprintf(
							'  [%d] name=%s sniffed=%s declared=%s disposition=%s -> %s',
							$i, $name ?: '(none)', $mime ?: '(empty)', $declared ?: '(empty)', $disp, $decision
						);
					}
					continue;
				}
			}

			$data = (string) $attachment->getContent();
			$size = strlen( $data );

			if ( $size > $this->max_bytes_for( $mime_used ) ) {
				$decision = sprintf( 'rejected: %d bytes exceeds cap for %s', $size, $mime_used );
				Logger::warning(
					sprintf( 'Parser: attachment "%s" (%s, %d bytes) exceeds the size cap for its type — skipped.', $name ?: 'unknown', $mime_used, $size ),
					'inbox'
				);
				if ( $debug_enabled ) {
					$debug_lines[] = sprintf( '  [%d] name=%s sniffed=%s declared=%s disposition=%s bytes=%d -> %s', $i, $name ?: '(none)', $mime ?: '(empty)', $declared ?: '(empty)', $disp, $size, $decision );
				}
				continue;
			}

			$attachments[] = [
				'filename' => sanitize_file_name( $name ?: 'submission-' . uniqid() . '.' . $this->extension_for( $mime_used ) ),
				'mime'     => $mime_used,
				'data'     => $data,
			];

			if ( $debug_enabled ) {
				$debug_lines[] = sprintf(
					'  [%d] name=%s sniffed=%s declared=%s disposition=%s bytes=%d -> %s (used mime: %s)',
					$i, $name ?: '(none)', $mime ?: '(empty)', $declared ?: '(empty)', $disp, $size, $decision, $mime_used
				);
			}
		}

		if ( $debug_enabled ) {
			$debug_lines[] = '';
			$debug_lines[] = sprintf( 'Result: %d attachment(s) accepted.', count( $attachments ) );
			Debug::write( 'parser-attachments', implode( "\n", $debug_lines ) );
		}

		$cleaned_description = $this->clean_text( $text_body );

		// remove@/promote@ identify their target purely by SUBJECT line — no
		// attachment, and typically no body text either (a quick "delete this"
		// email is often sent with an empty body). Those two are the one case
		// this parser must let through on subject alone: PostCreator's
		// handle_removal_request()/handle_promote_request() only ever read
		// $submission['subject'], never description or attachments. Without
		// this exemption, a genuinely empty-body remove@/promote@ email was
		// silently rejected right here as "nothing usable" before PostCreator
		// ever got a chance to route it — surfacing as a false "no valid
		// image, audio, or video attachment" skip in the Inbox admin table for
		// a request that was never supposed to need one (2026-07-14).
		$remove_addr    = strtolower( trim( (string) get_option( 'agnosis_email_remove', '' ) ) );
		$promote_addr   = strtolower( trim( (string) get_option( 'agnosis_email_promote', '' ) ) );
		$is_management  = '' !== $subject && (
			( '' !== $remove_addr && in_array( $remove_addr, $all_recipients, true ) )
			|| ( '' !== $promote_addr && in_array( $promote_addr, $all_recipients, true ) )
		);

		// Poetry is art too, and a biography can be text-only — an email with
		// no attachment isn't automatically empty. Only reject when there's
		// truly nothing to publish: no attachment, no real text, and it isn't
		// a remove@/promote@ request (which needs neither — see above). This
		// lets bio@/pure@/event@ (none of which ever depended on an
		// attachment downstream) through on body text alone; PostCreator
		// already has its own attachment-optional handling for those post
		// types (see PostCreator::handle()'s "Biography/event emails may have
		// no attachments" branch) — a genuinely new agnosis_artwork submission
		// still needs either an attachment or text, which this same check
		// still enforces.
		if ( empty( $attachments ) && '' === $cleaned_description && ! $is_management ) {
			return null; // Nothing usable — skip.
		}

		$artist_id = $this->resolve_artist( $from );

		return [
			'from'         => $from,
			'to_address'   => $to_address,
			'to_addresses' => $all_recipients,
			'subject'      => $subject,
			'description'  => $cleaned_description,
			'attachments'  => $attachments,
			'artist_id'    => $artist_id,
			'source'       => 'imap',
		];
	}

	/**
	 * Parse a webklex IMAP Message for its subject + plain-text body only —
	 * no attachment handling, no artist resolution.
	 *
	 * Used solely by the community-broadcast alias
	 * (Inbox::handle_community_email()), which relays a message as-is rather
	 * than treating it as an artwork/bio/event submission, so none of
	 * parse_imap_message()'s attachment logic applies. Mirrors that method's
	 * body-fetch guard: Inbox::query_messages() fetches headers only, so the
	 * body must be pulled explicitly for any message that needs one.
	 *
	 * @param Message $message Webklex message object from a connected folder query.
	 * @return array{subject: string, body: string}
	 */
	public function parse_broadcast_body( Message $message ): array {
		$structure   = $message->getStructure();
		$needs_fetch = ( null === $structure || empty( $structure->parts ) );

		if ( $needs_fetch ) {
			try {
				$message->parseBody();
			} catch ( \Throwable $e ) {
				Logger::error(
					'Parser: parse_broadcast_body() parseBody() threw for UID ' . (string) $message->getUid() . ': ' . $e->getMessage(),
					'inbox'
				);
			}
		}

		return [
			'subject' => sanitize_text_field( (string) $message->getSubject() ),
			'body'    => $this->clean_text( (string) $message->getTextBody() ),
		];
	}

	/**
	 * Parse a raw email payload forwarded by a webhook provider (Mailgun, SendGrid…).
	 *
	 * @param array<string, mixed> $payload POST data from webhook.
	 * @return array<string, mixed>|null
	 */
	public function parse_webhook_payload( array $payload ): ?array {
		$this->last_rejection_reason = '';

		$from         = sanitize_email( $payload['sender'] ?? $payload['from'] ?? '' );
		$to_address   = strtolower( sanitize_email( $payload['recipient'] ?? $payload['to'] ?? '' ) );
		// IntakeGates::recipient_addresses() (sixth audit §6) is the same
		// parser Webhook::handle() uses — previously each file had its own
		// byte-identical copy.
		$to_addresses = IntakeGates::recipient_addresses( $payload );
		$subject      = sanitize_text_field( $payload['subject'] ?? '' );
		$description  = sanitize_textarea_field( $payload['stripped-text'] ?? $payload['text'] ?? '' );

		// Reply/quote rejection (2026-07-15) — mirrors parse_imap_message()'s
		// identical check; see IntakeGates::is_reply_or_quote()'s own docblock.
		if ( IntakeGates::is_reply_or_quote( $subject, $description ) ) {
			Logger::info( 'Parser: webhook payload looks like a reply or quoted message, not an original submission — skipped.', 'inbox' );
			$this->last_rejection_reason = 'looks_like_reply';
			return null;
		}

		$attachments = [];

		// Mailgun / SendGrid attach files differently — handle both.
		$attachment_count = (int) ( $payload['attachment-count'] ?? 0 );
		for ( $i = 1; $i <= $attachment_count; $i++ ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- request authenticated via HMAC in Webhook::verify_signature(); file type/size validated below.
			$file = $_FILES[ 'attachment-' . $i ] ?? null;
			if ( null === $file ) {
				continue;
			}

			// audit §3e: previously trusted $file['type'] — entirely
			// sender-declared, no byte-level check at all — while the IMAP
			// path always fileinfo-sniffs first. Same fallback order as
			// parse_imap_message() above: the declared type is only trusted
			// once the actual bytes fail to sniff as anything on the
			// allow-list, never trusted first.
			$declared  = strtolower( (string) ( $file['type'] ?? '' ) );
			$sniffed   = $this->sniff_mime_from_path( (string) ( $file['tmp_name'] ?? '' ) );
			$mime_used = $sniffed;

			if ( ! $this->is_allowed_mime( $sniffed ) ) {
				Logger::info(
					sprintf(
						'Parser: webhook attachment "%s" — sniffed mime "%s" not allowed; declared type "%s".',
						(string) ( $file['name'] ?? '' ) ?: 'unknown',
						$sniffed ?: '(empty)',
						$declared ?: '(empty)'
					),
					'inbox'
				);

				if ( $this->is_allowed_mime( $declared ) ) {
					$mime_used = $declared;
				} else {
					continue;
				}
			}

			if ( (int) ( $file['size'] ?? 0 ) > $this->max_bytes_for( $mime_used ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data = file_get_contents( $file['tmp_name'] );
			if ( false !== $data ) {
				$attachments[] = [
					'filename' => sanitize_file_name( $file['name'] ),
					'mime'     => $mime_used,
					'data'     => $data,
				];
			}
		}

		$cleaned_description = $this->clean_text( $description );

		// See parse_imap_message()'s identical guard for the full rationale —
		// text-only bio@/pure@ submissions (and event@/remove@/promote@, which
		// never needed an attachment at all) must not be dropped here just
		// because no file was attached.
		if ( empty( $attachments ) && '' === $cleaned_description ) {
			return null;
		}

		return [
			'from'         => $from,
			'to_address'   => $to_address,
			'to_addresses' => $to_addresses,
			'subject'      => $subject,
			'description'  => $cleaned_description,
			'attachments'  => $attachments,
			'artist_id'    => $this->resolve_artist( $from ),
			'source'       => 'webhook',
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function is_allowed_mime( string $mime ): bool {
		return in_array( strtolower( $mime ), self::ALLOWED_MIME, true );
	}

	/**
	 * Sniff a file's real MIME type from its actual bytes via PHP's fileinfo
	 * extension (audit §3e). Mirrors parse_imap_message()'s own convention —
	 * webklex's Attachment::getMimeType() never trusts a declared
	 * Content-Type on its own either — so the webhook path (previously the
	 * only one of the two intake transports that accepted
	 * `$_FILES[...]['type']`, entirely sender-declared, with no byte-level
	 * check at all) now applies the same sniff-first rule. Returns '' if the
	 * file can't be read or fileinfo itself is unavailable, so callers fall
	 * through to the declared-type fallback exactly as the IMAP path does
	 * when its own sniff comes back empty or unrecognized.
	 */
	private function sniff_mime_from_path( string $path ): string {
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		if ( ! function_exists( 'finfo_open' ) ) {
			return '';
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $finfo ) {
			return '';
		}
		$mime = finfo_file( $finfo, $path );
		finfo_close( $finfo );
		return strtolower( (string) ( $mime ?: '' ) );
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
