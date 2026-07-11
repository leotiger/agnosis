<?php
/**
 * Webhook email receiver.
 *
 * Registers a REST endpoint that Mailgun, SendGrid, Postmark, etc.
 * can POST inbound email payloads to. Validated via HMAC secret.
 *
 * Endpoint: POST /wp-json/agnosis/v1/email/inbound
 *
 * @package Agnosis\Email
 */

declare(strict_types=1);

namespace Agnosis\Email;

use Agnosis\Artist\Admission;
use Agnosis\Artist\CommunityBroadcast;
use Agnosis\Artist\Departure;
use Agnosis\Core\Logger;
use Agnosis\Core\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Webhook {

	/**
	 * Maximum age (seconds) a signed request's timestamp may have, either
	 * direction, before it's rejected as stale (fifth audit §3a). Mailgun's
	 * own signing documentation prescribes rejecting stale timestamps for
	 * exactly this reason: without it, a signed `timestamp`/`token`/
	 * `signature` triple validates forever, so anyone who ever captures one
	 * (proxy logs, a misconfigured CDN, the artist's own outbox if they run
	 * the ESP) can replay it indefinitely.
	 */
	private const TIMESTAMP_FRESHNESS_SECONDS = 300;

	/**
	 * How long a seen signed request is remembered to reject a replay within
	 * the freshness window above (fifth audit §3a) — comfortably longer than
	 * TIMESTAMP_FRESHNESS_SECONDS so a captured request can't be replayed a
	 * second time before its own timestamp would have aged out anyway.
	 */
	private const REPLAY_MEMORY_SECONDS = 600;

	/**
	 * Rate-limit bucket split (audit §3c). Previously a single
	 * `email_inbound` bucket (60/60s, keyed by IP) was checked BEFORE the
	 * HMAC signature — fine when the key is genuinely per-attacker, but on a
	 * host where a misconfigured reverse proxy or certain CDN setups rewrite
	 * every request to one shared REMOTE_ADDR, that bucket becomes global:
	 * an unauthenticated flood of garbage starves the ESP's own legitimate
	 * signed traffic in the exact same window. Verifying the signature
	 * first and rate-limiting the two outcomes separately means a flood
	 * that can never pass hash_equals() only ever spends the tight
	 * UNVERIFIED bucket; a request that has proven it holds the shared
	 * secret gets a bucket generous enough to never realistically matter,
	 * on any host, regardless of REMOTE_ADDR quality.
	 */
	private const UNVERIFIED_RATE_LIMIT = 10;
	private const VERIFIED_RATE_LIMIT   = 300;
	private const RATE_LIMIT_WINDOW     = 60;

	public function register_routes(): void {
		register_rest_route(
			'agnosis/v1',
			'/email/inbound',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'verify_signature' ],
			]
		);

		// Bounce/complaint events (security audit §5a) — a sibling route, not a
		// branch inside handle() above: event payloads (Mailgun/SendGrid/
		// Postmark) are a structurally different thing from an inbound email
		// payload, and mixing "is this a submission or an event?" guessing
		// into handle()'s already-long alias-routing chain would only make
		// both harder to follow. Reuses verify_signature() unchanged — same
		// shared secret, same Mailgun-classic-triple / generic-HMAC schemes,
		// same replay protection; point your ESP's bounce/complaint webhook at
		// this URL instead of (or alongside) the inbound-routing one.
		register_rest_route(
			'agnosis/v1',
			'/email/events',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_bounce_event' ],
				'permission_callback' => [ $this, 'verify_signature' ],
			]
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $request->get_params();

		// Resolved once, up front (fourth audit §3b) — the auth gate just below,
		// and both alias branches, all need it. Identical to how
		// Parser::parse_webhook_payload() derives 'from' for the normal pipeline
		// further down, so this does not change what a genuine submission sees.
		$from_email = sanitize_email( $payload['sender'] ?? $payload['from'] ?? '' );

		// --- Auth gate (opt-in, fourth audit §3b) — evaluated BEFORE any alias
		// routing. Previously this ran only in the normal artwork/bio/event
		// pipeline, ~90 lines below, so the opt-in SPF/DKIM hardening this
		// option promises never covered the two aliases where a spoofed From
		// does the most damage: an impersonated community@ broadcast (relayed to
		// every other artist under the spoofed sender's own name) and a forged
		// goodbye@ removal request. Moving the check here closes both.
		if ( ! $this->passes_email_auth( $payload, $from_email ) ) {
			return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'auth_failed' ], 200 );
		}

		// All To:/Cc: recipients on this payload (fifth audit §5a) — 'recipient'
		// is Mailgun's own single routed address, but 'To'/'Cc' carry the full
		// raw header, which can list several. Computed once, reused by both
		// alias checks below (mirrors Email\Inbox's IMAP-side equivalent).
		// IntakeGates::recipient_addresses() (sixth audit §6) is the same
		// parser Parser::parse_webhook_payload() uses — previously each file
		// had its own byte-identical copy.
		$recipients = IntakeGates::recipient_addresses( $payload );

		// --- Goodbye alias: self-removal request (no attachment required) ---
		$goodbye_addr = strtolower( trim( (string) get_option( 'agnosis_email_goodbye', '' ) ) );
		if ( $goodbye_addr ) {
			if ( in_array( $goodbye_addr, $recipients, true ) ) {
				$user = get_user_by( 'email', $from_email );

				// Admitted-artist gate (fifth audit §3b) — this branch previously
				// only checked $user existed, not Admission::is_admitted_artist(),
				// unlike Inbox::handle_goodbye_email()'s IMAP-path equivalent below
				// it. Bounded in practice even before this fix — Departure itself
				// still requires an active agnosis_applications row before sending
				// anything — but a registered, non-admitted WP account (a pending
				// applicant, a banned artist, a plain subscriber on a shared
				// install) could reach Departure via this transport and not the
				// other. Same gate on both closes exactly the drift the fourth
				// audit's §3b fix paid to eliminate elsewhere.
				if ( ! $user || ! Admission::is_admitted_artist( $user->ID ) ) {
					Logger::warning( 'Webhook: goodbye request from non-artist <' . $from_email . '> — ignored.', 'webhook' );
					$this->mark_alias_event( $payload, $user ? (int) $user->ID : null, 'goodbye_non_artist', $from_email );
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'goodbye_non_artist' ], 200 );
				}

				// Per-sender throttle (fourth audit §3b): a genuine self-removal
				// only ever needs to be requested once. The 'goodbye_request'
				// bucket is shared with Email\Inbox::handle_goodbye_email(),
				// mirroring how 'community_broadcast' is already shared between
				// both intake paths below.
				$limit    = max( 1, (int) get_option( 'agnosis_goodbye_request_limit', 3 ) );
				$throttle = RateLimiter::check_sender( 'goodbye_request', $from_email, $limit, DAY_IN_SECONDS );
				if ( is_wp_error( $throttle ) ) {
					Logger::warning( 'Webhook: goodbye request from <' . $from_email . '> throttled (' . $limit . '/day limit).', 'webhook' );
					$this->mark_alias_event( $payload, $user->ID, 'goodbye_throttled', $from_email );
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'goodbye_throttled' ], 200 );
				}

				$departure = new Departure();
				$ok        = $departure->initiate_removal_for_user( $user->ID );

				// $ok distinguishes an actually-sent confirmation from "admitted
				// per WP role/capability but no active agnosis_applications row" —
				// mirrors Inbox::handle_goodbye_email()'s identical distinction
				// (see that method's docblock for why these must not share one
				// reason/status).
				if ( $ok ) {
					Logger::info( 'Webhook: goodbye request from <' . $from_email . '> (user #' . $user->ID . '): confirmation sent.', 'webhook' );
					$this->mark_alias_event( $payload, $user->ID, 'goodbye_handled', $from_email );
				} else {
					Logger::warning( 'Webhook: goodbye request from <' . $from_email . '> (user #' . $user->ID . '): no active membership — ignored.', 'webhook' );
					$this->mark_alias_event( $payload, $user->ID, 'goodbye_no_membership', $from_email );
				}

				return new WP_REST_Response( [ 'status' => 'goodbye_received' ], 200 );
			}
		}

		// --- Community alias: broadcast to all other artists (no attachment required) ---
		// Mirrors the goodbye alias above and Email\Inbox::handle_community_email() —
		// never reaches the artwork/bio/event pipeline below, and requires an
		// admitted-artist sender within their daily broadcast limit.
		$community_addr = strtolower( trim( (string) get_option( 'agnosis_email_community', '' ) ) );
		if ( $community_addr ) {
			if ( in_array( $community_addr, $recipients, true ) ) {
				// Mail-loop guard (fourth audit §3c): broadcast copies deliberately
				// set Reply-To to this same alias (see CommunityBroadcast::send_one()'s
				// docblock) so a human reply gets translated for everyone — but that
				// means a recipient's vacation auto-responder also fires on the
				// broadcast and lands right back here. Checked first, before
				// resolving the sender at all, so an auto-response never even
				// counts against anyone's throttle.
				if ( $this->is_auto_submitted( $payload ) ) {
					Logger::info( 'Webhook: community broadcast from <' . $from_email . '> looks like an auto-response (Auto-Submitted header) — ignored, not broadcast.', 'webhook' );
					$this->mark_alias_event( $payload, null, 'community_auto_submitted', $from_email );
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'community_auto_submitted' ], 200 );
				}

				$user = get_user_by( 'email', $from_email );

				// Reason key renamed from 'community_not_admitted' to
				// 'community_non_artist' (fifth audit §5a) to match
				// Inbox::handle_community_email()'s identical reason exactly —
				// no test or integration depended on the old string.
				if ( ! $user || ! Admission::is_admitted_artist( $user->ID ) ) {
					Logger::warning( 'Webhook: community broadcast from non-artist <' . $from_email . '> — ignored.', 'webhook' );
					$this->mark_alias_event( $payload, $user ? (int) $user->ID : null, 'community_non_artist', $from_email );
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'community_non_artist' ], 200 );
				}

				$limit    = max( 1, (int) get_option( 'agnosis_community_broadcast_limit', 3 ) );
				$throttle = RateLimiter::check_sender( 'community_broadcast', $from_email, $limit, DAY_IN_SECONDS );
				if ( is_wp_error( $throttle ) ) {
					Logger::warning( 'Webhook: community broadcast from <' . $from_email . '> throttled (' . $limit . '/day limit).', 'webhook' );
					$this->mark_alias_event( $payload, $user->ID, 'community_throttled', $from_email );
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'community_throttled' ], 200 );
				}

				$subject = sanitize_text_field( $payload['subject'] ?? '' );
				$body    = sanitize_textarea_field( $payload['stripped-text'] ?? $payload['text'] ?? '' );

				if ( '' === trim( $subject ) && '' === trim( $body ) ) {
					( new CommunityBroadcast() )->send_empty_bounce( $user->ID );
					$this->mark_alias_event( $payload, $user->ID, 'community_empty', $from_email );
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'community_empty' ], 200 );
				}

				$broadcast = new CommunityBroadcast();

				// Checked BEFORE broadcast() — bail out before any AI translation
				// calls are made (one per recipient), not after.
				if ( $broadcast->exceeds_max_length( $subject, $body ) ) {
					$length = mb_strlen( $subject ) + mb_strlen( $body );
					Logger::warning( 'Webhook: community broadcast from <' . $from_email . '> was ' . $length . ' characters — exceeds the configured limit, bounced.', 'webhook' );
					$broadcast->send_too_long_bounce( $user->ID, $length );
					$this->mark_alias_event( $payload, $user->ID, 'community_too_long', $from_email );
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'community_too_long' ], 200 );
				}

				$sent = $broadcast->broadcast( $user->ID, $subject, $body );
				$this->mark_alias_event( $payload, $user->ID, 'community_handled', $from_email );
				return new WP_REST_Response( [ 'status' => 'community_broadcast', 'sent' => $sent ], 200 );
			}
		}

		$parser     = new Parser();
		$submission = $parser->parse_webhook_payload( $payload );

		if ( null === $submission ) {
			return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'no_images' ], 200 );
		}

		// --- Gate 1: admitted sender -------------------------------------------
		// Mirror the admission check the IMAP path enforces. Without this, any
		// image-bearing message relayed by the ESP from an unknown sender would be
		// AI-processed and drafted at the platform's cost.
		$artist_id = $submission['artist_id'] ?? null;
		if ( ! Admission::is_admitted_artist( $artist_id ) ) {
			Logger::warning( 'Webhook: skipped — sender <' . $from_email . '> is not an admitted artist.', 'webhook' );
			// Return 200 so the ESP does not retry — this is not a transient error.
			return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'not_admitted' ], 200 );
		}

		// --- Gate 2: per-sender intake throttle --------------------------------
		// Cap the number of webhook submissions an individual artist can enqueue
		// per hour. Prevents a flood from consuming cron budget or AI quota even
		// from an admitted sender.
		$sender_limit  = max( 1, (int) get_option( 'agnosis_intake_per_sender_limit', 5 ) );
		$sender_window = HOUR_IN_SECONDS;
		$throttle      = RateLimiter::check_sender( 'email_intake', $from_email, $sender_limit, $sender_window );
		if ( is_wp_error( $throttle ) ) {
			Logger::warning( 'Webhook: throttled sender <' . $from_email . '> (' . $sender_limit . ' per hour limit reached).', 'webhook' );
			return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'throttled' ], 200 );
		}

		// Gate 3 (SPF/DKIM) used to run here — moved above alias routing (fourth
		// audit §3b); see passes_email_auth() and its call site near the top of
		// this method. Every request reaching this point has already passed it,
		// regardless of which branch (goodbye/community/normal) it took.

		global $wpdb;

		// Deterministic queue UID (fifth audit §3a) — previously embedded
		// time(), which meant an ESP retry (after a slow 202) or an outright
		// replay of the same signed request NEVER collided with the original
		// row: each one was a fresh INSERT and a fresh AI pipeline spend, even
		// though the table's own UNIQUE KEY on message_uid exists precisely to
		// prevent that. Message-Id is the natural per-message identifier and
		// is present on essentially every real email; the rare payload without
		// one falls back to a hash of the parsed submission itself, so at
		// least an exact repeat of the same content still dedupes.
		$message_id = trim( (string) ( $payload['Message-Id'] ?? $payload['message-id'] ?? '' ) );
		$uid        = 'webhook-' . md5( '' !== $message_id ? $message_id : (string) wp_json_encode( $submission ) );

		// INSERT IGNORE — silently skips on UNIQUE KEY collision (a retry or
		// replay of a message already queued). The row's ID is fetched
		// afterward whether it was just inserted or already present, mirroring
		// Email\Inbox::enqueue()'s identical idempotency pattern for the IMAP
		// path — same table, same guarantee, both transports now.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no WP abstraction; INSERT IGNORE is inherently uncacheable.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}agnosis_queue (message_uid, artist_id, raw_email, status)
				 VALUES (%s, %s, %s, 'pending')",
				$uid,
				null !== ( $submission['artist_id'] ?? null ) ? (string) (int) $submission['artist_id'] : null,
				wp_json_encode( $submission )
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$queue_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}agnosis_queue WHERE message_uid = %s LIMIT 1",
				$uid
			)
		);

		if ( $queue_id ) {
			wp_schedule_single_event( time(), 'agnosis_publish_submission', [ $queue_id ] );
		}

		return new WP_REST_Response( [ 'status' => 'queued', 'id' => $queue_id ], 202 );
	}

	// -------------------------------------------------------------------------
	// Bounce/complaint events (security audit §5a)
	// -------------------------------------------------------------------------

	/**
	 * Handle a bounce/complaint event webhook POST.
	 *
	 * Recognizes three payload shapes (each ESP's own native format — this
	 * plugin does not ask the operator to reshape anything):
	 *   - Postmark: a single JSON object, `RecordType` is `'Bounce'` or `'SpamComplaint'`.
	 *   - Mailgun: `event`/`recipient`/`severity`, either at the top level
	 *     (classic form-encoded webhooks) or nested under `event-data`
	 *     (current JSON webhooks) — both shapes are checked.
	 *   - SendGrid: a raw JSON array of event objects, one per address.
	 *
	 * Only HARD bounces and spam complaints suppress anything — a soft/
	 * transient bounce (mailbox full, temporary server issue) is not "this
	 * address is dead" and must not stop future sends on one blip; each
	 * provider's own hard/soft distinction is checked before calling
	 * BounceHandler::record().
	 *
	 * Always returns 200 (never a queue-worthy retry signal) — an
	 * unrecognized payload shape or an address matching neither a
	 * subscriber nor an artist is not an error the ESP should retry.
	 */
	public function handle_bounce_event( WP_REST_Request $request ): WP_REST_Response {
		$json_params = $request->get_json_params();

		// SendGrid posts a raw JSON array — not an object — so it never
		// resembles the other two shapes below, which are always keyed arrays.
		if ( is_array( $json_params ) && array_is_list( $json_params ) ) {
			$processed = 0;
			foreach ( $json_params as $event ) {
				if ( is_array( $event ) && $this->process_sendgrid_event( $event ) ) {
					++$processed;
				}
			}
			return new WP_REST_Response( [ 'status' => 'processed', 'count' => $processed ], 200 );
		}

		$payload = $request->get_params();

		if ( isset( $payload['RecordType'] ) ) {
			$this->process_postmark_event( $payload );
			return new WP_REST_Response( [ 'status' => 'processed' ], 200 );
		}

		// Mailgun: prefer the nested 'event-data' object (current JSON
		// webhooks) when present, otherwise treat the top level as the event
		// itself (classic form-encoded webhooks — the same shape handle()'s
		// own Mailgun signature verification above already expects).
		$event_data = is_array( $payload['event-data'] ?? null ) ? $payload['event-data'] : $payload;
		if ( isset( $event_data['event'] ) ) {
			$this->process_mailgun_event( $event_data );
			return new WP_REST_Response( [ 'status' => 'processed' ], 200 );
		}

		Logger::warning( 'Webhook: /email/events received a payload that matched no known ESP event shape.', 'webhook' );
		return new WP_REST_Response( [ 'status' => 'ignored', 'reason' => 'unrecognized_payload' ], 200 );
	}

	/**
	 * @param array<string, mixed> $payload Postmark's own single-event JSON object.
	 */
	private function process_postmark_event( array $payload ): void {
		$record_type = (string) ( $payload['RecordType'] ?? '' );
		$email       = sanitize_email( (string) ( $payload['Email'] ?? '' ) );

		if ( '' === $email ) {
			return;
		}

		if ( 'SpamComplaint' === $record_type ) {
			BounceHandler::record( $email, 'complaint', 'webhook' );
			return;
		}

		if ( 'Bounce' === $record_type ) {
			// Postmark's 'Type' field distinguishes 'HardBounce' from soft/
			// transient variants (e.g. 'SoftBounce', 'Transient') — only the
			// former means the address is actually dead.
			$bounce_type = (string) ( $payload['Type'] ?? '' );
			if ( false !== stripos( $bounce_type, 'hard' ) ) {
				BounceHandler::record( $email, 'bounce', 'webhook' );
			}
		}
	}

	/**
	 * @param array<string, mixed> $event_data Mailgun's event object — either the
	 *                                          top-level payload (classic) or its
	 *                                          nested 'event-data' (current JSON).
	 */
	private function process_mailgun_event( array $event_data ): void {
		$event     = strtolower( (string) ( $event_data['event'] ?? '' ) );
		$recipient = sanitize_email( (string) ( $event_data['recipient'] ?? '' ) );

		if ( '' === $recipient ) {
			return;
		}

		if ( 'complained' === $event ) {
			BounceHandler::record( $recipient, 'complaint', 'webhook' );
			return;
		}

		if ( 'failed' === $event ) {
			// 'permanent' severity = hard bounce; 'temporary' is a delivery
			// delay/retry Mailgun itself is still working on, not a dead address.
			$severity = strtolower( (string) ( $event_data['severity'] ?? '' ) );
			if ( 'permanent' === $severity ) {
				BounceHandler::record( $recipient, 'bounce', 'webhook' );
			}
		}
	}

	/**
	 * @param array<string, mixed> $event One element of SendGrid's raw JSON event array.
	 * @return bool True when this event was recognized and acted on.
	 */
	private function process_sendgrid_event( array $event ): bool {
		$type  = strtolower( (string) ( $event['event'] ?? '' ) );
		$email = sanitize_email( (string) ( $event['email'] ?? '' ) );

		if ( '' === $email ) {
			return false;
		}

		if ( 'spamreport' === $type ) {
			BounceHandler::record( $email, 'complaint', 'webhook' );
			return true;
		}

		if ( 'bounce' === $type ) {
			// SendGrid's own 'type' field distinguishes a hard 'bounce' from a
			// transient 'blocked' — SendGrid itself retries the latter, so
			// only 'bounce' means the address is actually dead.
			$sg_type = strtolower( (string) ( $event['type'] ?? '' ) );
			if ( 'bounce' === $sg_type ) {
				BounceHandler::record( $email, 'bounce', 'webhook' );
				return true;
			}
		}

		return false;
	}

	/**
	 * Opt-in SPF/DKIM authentication gate (fourth audit §3b).
	 *
	 * Evaluated for EVERY request in handle() — including the goodbye@/
	 * community@ aliases — before any routing decision is made, not just
	 * requests that fall through to the normal artwork/bio/event pipeline.
	 * Returns true immediately (no-op) when the `agnosis_require_email_auth`
	 * option is off, which it is by default.
	 *
	 * @param array<string, mixed> $payload    Raw webhook POST params.
	 * @param string               $from_email Sender address, used only for logging.
	 * @return bool True when the request passes (or auth is not required at all).
	 */
	private function passes_email_auth( array $payload, string $from_email ): bool {
		if ( ! get_option( 'agnosis_require_email_auth' ) ) {
			return true;
		}

		// When enabled, reject messages that fail both SPF and DKIM. This stops
		// spoofed From: addresses — an attacker who knows an artist's email but
		// does not control their domain will fail both checks.
		$auth_header = EmailAuth::extract_from_mailgun_payload( $payload );
		// Generic fallback: some ESPs include it as a top-level field.
		if ( ! $auth_header ) {
			$auth_header = (string) ( $payload['Authentication-Results'] ?? $payload['authentication-results'] ?? '' );
		}

		if ( ! $auth_header ) {
			// No Authentication-Results header found — cannot verify. When auth
			// is required but not available, reject to be safe.
			Logger::warning( 'Webhook: rejected <' . $from_email . '> — authentication required but no Authentication-Results header found.', 'webhook' );
			return false;
		}

		$verdicts = EmailAuth::check_header( $auth_header );
		if ( ! EmailAuth::passes( $verdicts ) ) {
			Logger::warning(
				sprintf(
					'Webhook: rejected <' . $from_email . '> — SPF=%s DKIM=%s DMARC=%s.',
					$verdicts['spf'] ?: 'none',
					$verdicts['dkim'] ?: 'none',
					$verdicts['dmarc'] ?: 'none'
				),
				'webhook'
			);
			return false;
		}

		return true;
	}

	/**
	 * Whether a webhook payload carries an `Auto-Submitted` header indicating
	 * it is an automated response (vacation auto-reply, mailing-list bounce,
	 * etc.) rather than a human-written email (fourth audit §3c).
	 *
	 * Per RFC 3834, genuine auto-responses set this header to a value other
	 * than `no` (typically `auto-replied`) — normal mail either omits the
	 * header or, less commonly, sets it explicitly to `no`; only a present,
	 * non-`no` value trips this guard, so an absent header (the overwhelming
	 * majority of real mail) is not treated as suspicious.
	 *
	 * @param array<string, mixed> $payload Raw webhook POST params.
	 * @return bool True when the payload looks like an automated response.
	 */
	private function is_auto_submitted( array $payload ): bool {
		$raw = EmailAuth::extract_mailgun_header( $payload, 'auto-submitted' );
		if ( ! $raw ) {
			// Generic fallback: some ESPs include it as a top-level field.
			$raw = (string) ( $payload['Auto-Submitted'] ?? $payload['auto-submitted'] ?? '' );
		}

		$value = strtolower( trim( $raw ) );
		return '' !== $value && 'no' !== $value;
	}

	/**
	 * Human-readable error text per alias-event reason — mirrors
	 * Inbox::SKIP_REASONS's goodbye_* and community_* entries exactly (fifth
	 * audit §5a/§3b), so the Inbox admin table reads identically regardless of
	 * which transport a goodbye@/community@ message arrived on. Both this and
	 * Inbox::SKIP_REASONS are now composed from one shared source,
	 * IntakeGates::SHARED_ALIAS_REASONS (sixth audit §6) — previously each
	 * was a separately hand-maintained copy of the same 10 entries.
	 *
	 * @var array<string, string>
	 */
	private const ALIAS_REASONS = IntakeGates::SHARED_ALIAS_REASONS;

	/**
	 * Per-reason queue-row status override — mirrors Inbox::SKIP_STATUSES.
	 * Reasons not listed here default to 'failed'; the three genuine-success
	 * reasons are recorded as 'skipped' instead, so the Inbox admin table
	 * doesn't show a red "Failed" badge for something that worked as
	 * intended. Composed from IntakeGates::SHARED_ALIAS_STATUSES (sixth
	 * audit §6) — see ALIAS_REASONS above.
	 *
	 * @var array<string, string>
	 */
	private const ALIAS_STATUSES = IntakeGates::SHARED_ALIAS_STATUSES;

	/**
	 * Record a goodbye@/community@ webhook event in the queue table so it's
	 * visible in the Inbox admin table (fifth audit §5a) — previously these
	 * events existed only as a REST response and, at best, a Logs-tab entry,
	 * so an operator debugging "my goodbye email did nothing" got a different
	 * (worse) diagnostic experience on webhook sites than on IMAP sites, where
	 * Inbox::mark_no_artwork() records every such outcome as a queue row.
	 *
	 * Deliberately scoped to only the goodbye@/community@ alias reasons, not
	 * the full submission pipeline's own skip reasons (not_admitted,
	 * throttled, auth_failed, no_attachments) — those return a REST response
	 * from handle() well before a $submission exists, and are not what this
	 * finding was about.
	 *
	 * INSERT IGNORE (mirrors mark_no_artwork()) — a retry of the same signed
	 * webhook request already dedupes on message_uid, so this never produces
	 * a duplicate row for the same physical email.
	 *
	 * @param array<string, mixed> $payload    Raw webhook POST payload.
	 * @param int|null             $artist_id  WP user ID of the sender, if resolved.
	 * @param string               $reason     Key into self::ALIAS_REASONS (and, optionally, self::ALIAS_STATUSES).
	 * @param string               $from_email Sender email address, if known.
	 */
	private function mark_alias_event( array $payload, ?int $artist_id, string $reason, string $from_email ): void {
		global $wpdb;

		$error  = self::ALIAS_REASONS[ $reason ] ?? $reason;
		$status = self::ALIAS_STATUSES[ $reason ] ?? 'failed';
		$uid    = $this->alias_event_uid( $payload );

		$meta = [];
		if ( '' !== $from_email ) {
			$meta['from'] = $from_email;
		}
		if ( 'skipped' === $status ) {
			$meta['skip_reason'] = $reason;
		}
		$raw = ! empty( $meta ) ? wp_json_encode( $meta ) : '{}';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table insert; INSERT IGNORE is idempotent, mirrors Inbox::mark_no_artwork().
		$wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}agnosis_queue
				 (message_uid, artist_id, status, raw_email, error)
				 VALUES (%s, %s, %s, %s, %s)",
			$uid,
			null !== $artist_id ? (string) $artist_id : null,
			$status,
			$raw,
			$error
		)
		);
	}

	/**
	 * Deterministic queue UID for a goodbye@/community@ alias event — same
	 * Message-Id-first, payload-hash-fallback scheme as handle()'s own queue
	 * UID (fifth audit §3a), namespaced with an 'alias' segment so it can
	 * never collide with a normal submission's row for the same physical
	 * email (the two are mutually exclusive in practice — an alias match
	 * returns before the submission pipeline runs — but a distinct prefix
	 * keeps that guarantee explicit rather than incidental).
	 *
	 * @param array<string, mixed> $payload Raw webhook POST payload.
	 * @return string
	 */
	private function alias_event_uid( array $payload ): string {
		$message_id = trim( (string) ( $payload['Message-Id'] ?? $payload['message-id'] ?? '' ) );
		return 'webhook-alias-' . md5( '' !== $message_id ? $message_id : (string) wp_json_encode( $payload ) );
	}

	/**
	 * Verify HMAC signature sent by the webhook provider.
	 *
	 * Each provider has its own signing scheme; we support Mailgun and a generic
	 * X-Agnosis-Signature header (HMAC-SHA256 of the raw body).
	 *
	 * Replay protection (fifth audit §3a): both schemes below now also check
	 * that the signed timestamp is fresh (TIMESTAMP_FRESHNESS_SECONDS either
	 * direction) and remember the specific signed request for
	 * REPLAY_MEMORY_SECONDS so it can't be replayed a second time inside that
	 * freshness window. Previously a valid signed triple/pair validated
	 * forever — anyone who ever captured one (proxy logs, a misconfigured
	 * CDN, the artist's own outbox if they run the ESP) could replay it
	 * indefinitely, each replay re-spending the AI pipeline (see handle()'s
	 * queue UID fix, same audit finding, for the other half of this).
	 *
	 * Rate-limit ordering (sixth audit §3c): the signature is now matched
	 * (via match_signature() below) BEFORE any rate limit is applied, and the
	 * matched/unmatched outcomes are checked against two separate buckets —
	 * see UNVERIFIED_RATE_LIMIT/VERIFIED_RATE_LIMIT above. Previously a single
	 * shared bucket was checked first, keyed by IP; on a host where a
	 * misconfigured reverse proxy collapses every REMOTE_ADDR into one value,
	 * that made the bucket effectively global, so an unauthenticated flood of
	 * garbage could starve the ESP's own legitimate signed traffic in the
	 * same window.
	 */
	public function verify_signature( WP_REST_Request $request ): bool|WP_Error {
		$secret = get_option( 'agnosis_webhook_secret', '' );

		if ( empty( $secret ) ) {
			// No secret configured → reject all webhook requests. Not part of
			// the flood-vs-legitimate-traffic bucket split below — a missing
			// secret is an operator misconfiguration, not the abuse case §3c
			// is about, so it isn't worth spending a rate-limit slot on.
			return new WP_Error( 'agnosis_no_secret', __( 'Webhook secret not configured.', 'agnosis' ), [ 'status' => 403 ] );
		}

		$match = $this->match_signature( $request, $secret );

		// Bucket split (audit §3c) — see the class docblock on
		// UNVERIFIED_RATE_LIMIT/VERIFIED_RATE_LIMIT above for why.
		$rate = $match['matched']
			? RateLimiter::check( 'email_inbound_verified', self::VERIFIED_RATE_LIMIT, self::RATE_LIMIT_WINDOW )
			: RateLimiter::check( 'email_inbound_unverified', self::UNVERIFIED_RATE_LIMIT, self::RATE_LIMIT_WINDOW );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		if ( ! $match['attempted'] ) {
			// Neither a X-Agnosis-Signature header nor a full Mailgun triple
			// was present at all — nothing here to have even attempted to
			// verify, as distinct from "attempted and failed" below.
			return new WP_Error( 'agnosis_invalid_signature', __( 'Invalid or missing webhook signature.', 'agnosis' ), [ 'status' => 403 ] );
		}

		if ( ! $match['matched'] ) {
			return false;
		}

		if ( '' === $match['replay_key'] ) {
			// Legacy generic scheme with no X-Agnosis-Timestamp header — no
			// replay-memory key exists to check without one (back-compat).
			return true;
		}

		return $this->check_replay_freshness( $match['timestamp'], $match['replay_key'] );
	}

	/**
	 * Cryptographically match a request's signature against both supported
	 * schemes, without any rate-limiting or replay/freshness side effects —
	 * those happen in verify_signature() above, once, using this result.
	 *
	 * @return array{attempted: bool, matched: bool, timestamp: string, replay_key: string}
	 */
	private function match_signature( WP_REST_Request $request, string $secret ): array {
		// --- Generic HMAC-SHA256 (default / custom senders) ---
		$signature = $request->get_header( 'X-Agnosis-Signature' );
		if ( $signature ) {
			$body = $request->get_body();

			// New, replay-protected form: an explicit X-Agnosis-Timestamp header
			// folded into the HMAC input, so it can't be stripped by a replay
			// without invalidating the signature. Optional, for back-compat with
			// senders that don't send it yet — those fall through to the
			// original body-only scheme below, unchanged, still inherently
			// replayable (no timestamp element exists to check without it).
			$timestamp_header = $request->get_header( 'X-Agnosis-Timestamp' );
			if ( $timestamp_header ) {
				$expected = hash_hmac( 'sha256', $timestamp_header . $body, $secret );
				return [
					'attempted'  => true,
					'matched'    => hash_equals( $expected, $signature ),
					'timestamp'  => $timestamp_header,
					'replay_key' => 'generic|' . $signature,
				];
			}

			$expected = hash_hmac( 'sha256', $body, $secret );
			return [
				'attempted'  => true,
				'matched'    => hash_equals( $expected, $signature ),
				'timestamp'  => '',
				'replay_key' => '',
			];
		}

		// --- Mailgun ---
		$mg_timestamp = (string) $request->get_param( 'timestamp' );
		$mg_token     = (string) $request->get_param( 'token' );
		$mg_signature = (string) $request->get_param( 'signature' );

		if ( '' !== $mg_timestamp && '' !== $mg_token && '' !== $mg_signature ) {
			$expected = hash_hmac( 'sha256', $mg_timestamp . $mg_token, $secret );
			return [
				'attempted'  => true,
				'matched'    => hash_equals( $expected, $mg_signature ),
				'timestamp'  => $mg_timestamp,
				'replay_key' => 'mailgun|' . $mg_token,
			];
		}

		return [ 'attempted' => false, 'matched' => false, 'timestamp' => '', 'replay_key' => '' ];
	}

	/**
	 * Shared timestamp-freshness + replay-memory check for both signing
	 * schemes above (fifth audit §3a). Called only AFTER the HMAC signature
	 * itself has already been verified — a request that fails this check was
	 * genuinely signed by someone holding the secret at some point, just not
	 * recently (or already used) enough to trust now.
	 *
	 * @param string $timestamp_raw  The signed timestamp, as supplied by the sender.
	 * @param string $replay_key_raw A value unique to this specific signed request
	 *                               (e.g. Mailgun's one-time `token`, or the
	 *                               signature itself for the generic scheme) —
	 *                               remembered so the exact same request can't
	 *                               be replayed twice inside the freshness window.
	 * @return true|WP_Error
	 */
	private function check_replay_freshness( string $timestamp_raw, string $replay_key_raw ): bool|WP_Error {
		if ( ! ctype_digit( $timestamp_raw ) || abs( time() - (int) $timestamp_raw ) > self::TIMESTAMP_FRESHNESS_SECONDS ) {
			return new WP_Error(
			'agnosis_webhook_stale_timestamp',
			__( 'Webhook request timestamp is missing, malformed, or too old.', 'agnosis' ),
			[ 'status' => 403 ]
			);
		}

		$replay_key = 'agnosis_wh_' . md5( $replay_key_raw );
		if ( get_transient( $replay_key ) ) {
			Logger::warning( 'Webhook: rejected a replayed request (same signed timestamp/token seen before).', 'webhook' );
			return new WP_Error(
			'agnosis_webhook_replay',
			__( 'This webhook request has already been processed.', 'agnosis' ),
			[ 'status' => 403 ]
			);
		}
		set_transient( $replay_key, 1, self::REPLAY_MEMORY_SECONDS );

		return true;
	}
}
