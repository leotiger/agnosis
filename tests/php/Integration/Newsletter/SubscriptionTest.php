<?php
/**
 * Integration tests — public newsletter subscribe REST endpoint.
 *
 * POST /agnosis/v1/newsletter/subscribe is unauthenticated and rate-limited.
 * Routes are already registered via Plugin::register_services() on
 * rest_api_init during test bootstrap (same assumption AdmissionIntegrationTest
 * makes for /agnosis/v1/admission/apply).
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Core\RateLimiter;
use Agnosis\Newsletter\Subscriber;

class SubscriptionTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Isolate each test's rate-limit counter — RateLimiter buckets by
		// IP + time-window slot, and the CLI test environment has no real
		// REMOTE_ADDR, so every test would otherwise share one bucket.
		RateLimiter::reset( 'newsletter_subscribe', RateLimiter::client_ip(), 300 );
	}

	private function subscribe( string $email, string $language = '' ): \WP_REST_Response|\WP_Error {
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/newsletter/subscribe' );
		$request->set_param( 'email', $email );
		if ( '' !== $language ) {
			$request->set_param( 'language', $language );
		}
		return rest_do_request( $request );
	}

	/** @param array<string, mixed>|null $captured */
	private function capture_mail( ?array &$captured ): callable {
		$filter = function ( $pre, array $atts ) use ( &$captured ) {
			$captured = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		return $filter;
	}

	// =========================================================================
	// Happy path
	// =========================================================================

	public function test_subscribe_creates_pending_subscriber(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$response = $this->subscribe( 'newartlover@example.com' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending_confirmation', $response->get_data()['status'] );

		$counts = Subscriber::counts();
		$this->assertSame( 1, $counts['pending'] );
	}

	public function test_subscribe_sends_confirmation_email_with_link(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->subscribe( 'confirmlink@example.com' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertSame( 'confirmlink@example.com', $captured['to'] );
		$this->assertStringContainsString( 'agnosis_newsletter=1', $captured['message'] );
		$this->assertStringContainsString( 'action=confirm', $captured['message'] );
		$this->assertStringContainsString( 'type=public', $captured['message'] );
	}

	// =========================================================================
	// Validation
	// =========================================================================

	public function test_subscribe_rejects_invalid_email(): void {
		$response = $this->subscribe( 'not-an-email' );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_subscribe_rejects_already_confirmed_email(): void {
		$result = Subscriber::subscribe( 'already@example.com' );
		Subscriber::confirm( $result['token'] );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$response = $this->subscribe( 'already@example.com' );
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertSame( 409, $response->get_status() );
		$this->assertNull( $captured, 'No confirmation email should be sent for an already-confirmed address.' );
	}

	public function test_subscribe_resends_for_a_still_pending_email(): void {
		$captured1 = null;
		$filter1   = $this->capture_mail( $captured1 );
		$this->subscribe( 'stillpending@example.com' );
		remove_filter( 'pre_wp_mail', $filter1, 10 );

		$captured2 = null;
		$filter2   = $this->capture_mail( $captured2 );
		$response  = $this->subscribe( 'stillpending@example.com' );
		remove_filter( 'pre_wp_mail', $filter2, 10 );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotNull( $captured2, 'Re-subscribing a pending address must resend the confirmation email.' );
	}

	// =========================================================================
	// Rate limiting
	// =========================================================================

	public function test_rate_limit_blocks_after_five_requests(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$captured = null;
			$filter   = $this->capture_mail( $captured );
			$this->subscribe( "ratelimit{$i}@example.com" );
			remove_filter( 'pre_wp_mail', $filter, 10 );
		}

		$response = $this->subscribe( 'ratelimit-sixth@example.com' );

		$this->assertSame( 429, $response->get_status() );
	}
}
