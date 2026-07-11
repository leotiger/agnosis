<?php
/**
 * Integration tests — Webhook::handle_bounce_event() (security audit §5a).
 *
 * Covers the three ESP payload shapes handle_bounce_event() recognizes
 * (Postmark's single JSON object, Mailgun's flat-or-nested event-data,
 * SendGrid's raw JSON array) plus the hard/soft distinction each provider's
 * own event data carries — only a hard bounce or spam complaint should ever
 * reach BounceHandler::record(); a soft/transient bounce must not suppress
 * an address over one blip. See handle_bounce_event()'s own docblock in
 * Webhook.php for the full shape rationale.
 *
 * Calls Webhook::handle_bounce_event() directly (bypassing the REST dispatch
 * layer and its verify_signature() permission_callback), same as the other
 * Webhook integration tests in this directory — see
 * WebhookAliasEventCoverageTest.php for the established pattern.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Email\Webhook;
use Agnosis\Newsletter\Subscriber;
use WP_REST_Request;

class WebhookBounceEventTest extends \WP_UnitTestCase {

	private Webhook $webhook;

	protected function setUp(): void {
		parent::setUp();
		$this->webhook = new Webhook();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Form-encoded style request (Postmark's single JSON object and Mailgun's
	 *  classic/current shapes all resolve via get_params(), same as a
	 *  form-encoded POST would — see handle_bounce_event()'s fallthrough). */
	private function params_request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request();
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/** SendGrid posts a genuine raw JSON array body — needs a real JSON
	 *  Content-Type so WP_REST_Request::get_json_params() parses it. */
	private function json_array_request( array $events ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $events ) );
		return $request;
	}

	private function bounced_status( string $email ): ?string {
		global $wpdb;
		return $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}agnosis_newsletter_subscribers WHERE email = %s",
				$email
			)
		);
	}

	// -------------------------------------------------------------------------
	// Postmark
	// -------------------------------------------------------------------------

	public function test_postmark_hard_bounce_suppresses_subscriber(): void {
		Subscriber::subscribe( 'pm-hard@example.com' );

		$response = $this->webhook->handle_bounce_event( $this->params_request( [
			'RecordType' => 'Bounce',
			'Type'       => 'HardBounce',
			'Email'      => 'pm-hard@example.com',
		] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'bounced', $this->bounced_status( 'pm-hard@example.com' ) );
	}

	public function test_postmark_soft_bounce_does_not_suppress(): void {
		Subscriber::subscribe( 'pm-soft@example.com' );

		$this->webhook->handle_bounce_event( $this->params_request( [
			'RecordType' => 'Bounce',
			'Type'       => 'SoftBounce',
			'Email'      => 'pm-soft@example.com',
		] ) );

		$this->assertSame( 'pending', $this->bounced_status( 'pm-soft@example.com' ) );
	}

	public function test_postmark_spam_complaint_suppresses_subscriber(): void {
		Subscriber::subscribe( 'pm-complaint@example.com' );

		$this->webhook->handle_bounce_event( $this->params_request( [
			'RecordType' => 'SpamComplaint',
			'Email'      => 'pm-complaint@example.com',
		] ) );

		$this->assertSame( 'bounced', $this->bounced_status( 'pm-complaint@example.com' ) );
	}

	// -------------------------------------------------------------------------
	// Mailgun
	// -------------------------------------------------------------------------

	public function test_mailgun_classic_permanent_failure_suppresses_subscriber(): void {
		Subscriber::subscribe( 'mg-classic@example.com' );

		$this->webhook->handle_bounce_event( $this->params_request( [
			'event'     => 'failed',
			'severity'  => 'permanent',
			'recipient' => 'mg-classic@example.com',
		] ) );

		$this->assertSame( 'bounced', $this->bounced_status( 'mg-classic@example.com' ) );
	}

	public function test_mailgun_classic_temporary_failure_does_not_suppress(): void {
		Subscriber::subscribe( 'mg-temp@example.com' );

		$this->webhook->handle_bounce_event( $this->params_request( [
			'event'     => 'failed',
			'severity'  => 'temporary',
			'recipient' => 'mg-temp@example.com',
		] ) );

		$this->assertSame( 'pending', $this->bounced_status( 'mg-temp@example.com' ) );
	}

	public function test_mailgun_nested_event_data_permanent_failure_suppresses(): void {
		Subscriber::subscribe( 'mg-nested@example.com' );

		$this->webhook->handle_bounce_event( $this->params_request( [
			'event-data' => [
				'event'     => 'failed',
				'severity'  => 'permanent',
				'recipient' => 'mg-nested@example.com',
			],
		] ) );

		$this->assertSame( 'bounced', $this->bounced_status( 'mg-nested@example.com' ) );
	}

	public function test_mailgun_complained_event_suppresses_subscriber(): void {
		Subscriber::subscribe( 'mg-complained@example.com' );

		$this->webhook->handle_bounce_event( $this->params_request( [
			'event'     => 'complained',
			'recipient' => 'mg-complained@example.com',
		] ) );

		$this->assertSame( 'bounced', $this->bounced_status( 'mg-complained@example.com' ) );
	}

	// -------------------------------------------------------------------------
	// SendGrid
	// -------------------------------------------------------------------------

	public function test_sendgrid_hard_bounce_suppresses_subscriber(): void {
		Subscriber::subscribe( 'sg-hard@example.com' );

		$response = $this->webhook->handle_bounce_event( $this->json_array_request( [
			[ 'email' => 'sg-hard@example.com', 'event' => 'bounce', 'type' => 'bounce' ],
		] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['count'] );
		$this->assertSame( 'bounced', $this->bounced_status( 'sg-hard@example.com' ) );
	}

	public function test_sendgrid_blocked_type_does_not_suppress(): void {
		Subscriber::subscribe( 'sg-blocked@example.com' );

		$this->webhook->handle_bounce_event( $this->json_array_request( [
			[ 'email' => 'sg-blocked@example.com', 'event' => 'bounce', 'type' => 'blocked' ],
		] ) );

		$this->assertSame( 'pending', $this->bounced_status( 'sg-blocked@example.com' ) );
	}

	public function test_sendgrid_spam_report_suppresses_subscriber(): void {
		Subscriber::subscribe( 'sg-spam@example.com' );

		$this->webhook->handle_bounce_event( $this->json_array_request( [
			[ 'email' => 'sg-spam@example.com', 'event' => 'spamreport' ],
		] ) );

		$this->assertSame( 'bounced', $this->bounced_status( 'sg-spam@example.com' ) );
	}

	public function test_sendgrid_array_processes_multiple_events_independently(): void {
		Subscriber::subscribe( 'sg-multi-1@example.com' );
		Subscriber::subscribe( 'sg-multi-2@example.com' );

		$response = $this->webhook->handle_bounce_event( $this->json_array_request( [
			[ 'email' => 'sg-multi-1@example.com', 'event' => 'bounce', 'type' => 'bounce' ],
			[ 'email' => 'sg-multi-2@example.com', 'event' => 'delivered' ],
		] ) );

		$this->assertSame( 1, $response->get_data()['count'] );
		$this->assertSame( 'bounced', $this->bounced_status( 'sg-multi-1@example.com' ) );
		$this->assertSame( 'pending', $this->bounced_status( 'sg-multi-2@example.com' ) );
	}

	// -------------------------------------------------------------------------
	// Unrecognized payload
	// -------------------------------------------------------------------------

	public function test_unrecognized_payload_is_ignored_not_errored(): void {
		$response = $this->webhook->handle_bounce_event( $this->params_request( [
			'something' => 'unrelated',
		] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ignored', $response->get_data()['status'] );
	}
}
