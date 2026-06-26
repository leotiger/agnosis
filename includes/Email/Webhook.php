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
		$parser  = new Parser();

		$submission = $parser->parse_webhook_payload( $payload );

		if ( null === $submission ) {
			return new WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'no_images' ], 200 );
		}

		global $wpdb;

		$uid = 'webhook-' . md5( ( $payload['Message-Id'] ?? $payload['message-id'] ?? '' ) . time() );

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
