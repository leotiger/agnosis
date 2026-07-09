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

		// --- Goodbye alias: self-removal request (no attachment required) ---
		$goodbye_addr = strtolower( trim( (string) get_option( 'agnosis_email_goodbye', '' ) ) );
		if ( $goodbye_addr ) {
			$recipient = strtolower( sanitize_email( $payload['recipient'] ?? $payload['to'] ?? '' ) );
			if ( $recipient === $goodbye_addr ) {
				$user = get_user_by( 'email', $from_email );
				if ( $user ) {
					// Per-sender throttle (fourth audit §3b): a genuine self-removal
					// only ever needs to be requested once. The 'goodbye_request'
					// bucket is shared with Email\Inbox::handle_goodbye_email(),
					// mirroring how 'community_broadcast' is already shared between
					// both intake paths below.
					$limit    = max( 1, (int) get_option( 'agnosis_goodbye_request_limit', 3 ) );
					$throttle = RateLimiter::check_sender( 'goodbye_request', $from_email, $limit, DAY_IN_SECONDS );
					if ( is_wp_error( $throttle ) ) {
						Logger::warning( 'Webhook: goodbye request from <' . $from_email . '> throttled (' . $limit . '/day limit).', 'webhook' );
						return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'goodbye_throttled' ], 200 );
					}

					$departure = new Departure();
					$departure->initiate_removal_for_user( $user->ID );
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
			$recipient = strtolower( sanitize_email( $payload['recipient'] ?? $payload['to'] ?? '' ) );
			if ( $recipient === $community_addr ) {
				// Mail-loop guard (fourth audit §3c): broadcast copies deliberately
				// set Reply-To to this same alias (see CommunityBroadcast::send_one()'s
				// docblock) so a human reply gets translated for everyone — but that
				// means a recipient's vacation auto-responder also fires on the
				// broadcast and lands right back here. Checked first, before
				// resolving the sender at all, so an auto-response never even
				// counts against anyone's throttle.
				if ( $this->is_auto_submitted( $payload ) ) {
					Logger::info( 'Webhook: community broadcast from <' . $from_email . '> looks like an auto-response (Auto-Submitted header) — ignored, not broadcast.', 'webhook' );
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'community_auto_submitted' ], 200 );
				}

				$user = get_user_by( 'email', $from_email );

				if ( ! $user || ! Admission::is_admitted_artist( $user->ID ) ) {
					Logger::warning( 'Webhook: community broadcast from non-artist <' . $from_email . '> — ignored.', 'webhook' );
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'community_not_admitted' ], 200 );
				}

				$limit    = max( 1, (int) get_option( 'agnosis_community_broadcast_limit', 3 ) );
				$throttle = RateLimiter::check_sender( 'community_broadcast', $from_email, $limit, DAY_IN_SECONDS );
				if ( is_wp_error( $throttle ) ) {
					Logger::warning( 'Webhook: community broadcast from <' . $from_email . '> throttled (' . $limit . '/day limit).', 'webhook' );
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'community_throttled' ], 200 );
				}

				$subject = sanitize_text_field( $payload['subject'] ?? '' );
				$body    = sanitize_textarea_field( $payload['stripped-text'] ?? $payload['text'] ?? '' );

				if ( '' === trim( $subject ) && '' === trim( $body ) ) {
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'community_empty' ], 200 );
				}

				$broadcast = new CommunityBroadcast();

				// Checked BEFORE broadcast() — bail out before any AI translation
				// calls are made (one per recipient), not after.
				if ( $broadcast->exceeds_max_length( $subject, $body ) ) {
					$length = mb_strlen( $subject ) + mb_strlen( $body );
					Logger::warning( 'Webhook: community broadcast from <' . $from_email . '> was ' . $length . ' characters — exceeds the configured limit, bounced.', 'webhook' );
					$broadcast->send_too_long_bounce( $user->ID, $length );
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'community_too_long' ], 200 );
				}

				$sent = $broadcast->broadcast( $user->ID, $subject, $body );
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

		$uid = 'webhook-' . md5( ( $payload['Message-Id'] ?? $payload['message-id'] ?? '' ) . time() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table, no WP abstraction available.
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_queue',
			[
				'message_uid' => $uid,
				'artist_id'   => $submission['artist_id'] ?? null,
				'raw_email'   => wp_json_encode( $submission ),
				'status'      => 'pending',
			],
			[ '%s', '%d', '%s', '%s' ]
		);

		$queue_id = $wpdb->insert_id;

		if ( $queue_id ) {
			wp_schedule_single_event( time(), 'agnosis_publish_submission', [ $queue_id ] );
		}

		return new WP_REST_Response( [ 'status' => 'queued', 'id' => $queue_id ], 202 );
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
	 * Verify HMAC signature sent by the webhook provider.
	 *
	 * Each provider has its own signing scheme; we support Mailgun and a generic
	 * X-Agnosis-Signature header (HMAC-SHA256 of the raw body).
	 */
	public function verify_signature( WP_REST_Request $request ): bool|WP_Error {
		$rate = RateLimiter::check( 'email_inbound', 60, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$secret = get_option( 'agnosis_webhook_secret', '' );

		if ( empty( $secret ) ) {
			// No secret configured → reject all webhook requests.
			return new WP_Error( 'agnosis_no_secret', __( 'Webhook secret not configured.', 'agnosis' ), [ 'status' => 403 ] );
		}

		// --- Generic HMAC-SHA256 (default / custom senders) ---
		$signature = $request->get_header( 'X-Agnosis-Signature' );
		if ( $signature ) {
			$body     = $request->get_body();
			$expected = hash_hmac( 'sha256', $body, $secret );
			return hash_equals( $expected, $signature );
		}

		// --- Mailgun ---
		$mg_timestamp = $request->get_param( 'timestamp' );
		$mg_token     = $request->get_param( 'token' );
		$mg_signature = $request->get_param( 'signature' );

		if ( $mg_timestamp && $mg_token && $mg_signature ) {
			$expected = hash_hmac( 'sha256', $mg_timestamp . $mg_token, $secret );
			return hash_equals( $expected, $mg_signature );
		}

		return new WP_Error( 'agnosis_invalid_signature', __( 'Invalid or missing webhook signature.', 'agnosis' ), [ 'status' => 403 ] );
	}
}
