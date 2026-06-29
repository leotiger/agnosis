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

		// --- Goodbye alias: self-removal request (no attachment required) ---
		$goodbye_addr = strtolower( trim( (string) get_option( 'agnosis_email_goodbye', '' ) ) );
		if ( $goodbye_addr ) {
			$recipient = strtolower( sanitize_email( $payload['recipient'] ?? $payload['to'] ?? '' ) );
			if ( $recipient === $goodbye_addr ) {
				$from_email = sanitize_email( $payload['sender'] ?? $payload['from'] ?? '' );
				$user       = get_user_by( 'email', $from_email );
				if ( $user ) {
					$departure = new Departure();
					$departure->initiate_removal_for_user( $user->ID );
				}
				return new WP_REST_Response( [ 'status' => 'goodbye_received' ], 200 );
			}
		}

		$parser     = new Parser();
		$submission = $parser->parse_webhook_payload( $payload );

		if ( null === $submission ) {
			return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'no_images' ], 200 );
		}

		$from_email = $submission['from'];

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

		// --- Gate 3: SPF/DKIM authentication (opt-in) --------------------------
		// When enabled, reject messages that fail both SPF and DKIM. This stops
		// spoofed From: addresses — an attacker who knows an artist's email but
		// does not control their domain will fail both checks.
		// Disabled by default: requires the site's domain to have SPF/DKIM
		// records configured and the ESP to include Authentication-Results.
		if ( get_option( 'agnosis_require_email_auth' ) ) {
			$auth_header = EmailAuth::extract_from_mailgun_payload( $payload );
			// Generic fallback: some ESPs include it as a top-level field.
			if ( ! $auth_header ) {
				$auth_header = (string) ( $payload['Authentication-Results'] ?? $payload['authentication-results'] ?? '' );
			}
			if ( $auth_header ) {
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
					return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'auth_failed' ], 200 );
				}
			} else {
				// No Authentication-Results header found — cannot verify.
				// When auth is required but not available, reject to be safe.
				Logger::warning( 'Webhook: rejected <' . $from_email . '> — authentication required but no Authentication-Results header found.', 'webhook' );
				return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'no_auth_header' ], 200 );
			}
		}

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
