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

	/**
	 * Enumeration-safe (security audit §2c): re-subscribing an already-confirmed
	 * address must respond identically to a brand-new signup — same 201, same
	 * body — so nobody can probe whether an address is on the list by the HTTP
	 * status alone. Only the (invisible to the caller) confirmation email is
	 * skipped, since there's nothing left to confirm.
	 */
	public function test_subscribe_already_confirmed_email_returns_generic_201_without_sending_email(): void {
		$result = Subscriber::subscribe( 'already@example.com' );
		Subscriber::confirm( $result['token'] );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$response = $this->subscribe( 'already@example.com' );
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertSame( 201, $response->get_status(), 'Must not be distinguishable from a fresh signup.' );
		$this->assertSame( 'pending_confirmation', $response->get_data()['status'], 'Response body must match the fresh-signup shape exactly.' );
		$this->assertNull( $captured, 'No confirmation email should be sent for an already-confirmed address.' );
	}

	/**
	 * Hardening (security audit §2d): an immediate resubmission for the same
	 * still-pending address (impatient double-click, or a bot hammering the
	 * form) is throttled — no second confirmation email — but the response
	 * must still be indistinguishable from any other outcome (enumeration-safe).
	 */
	public function test_subscribe_immediate_resubmission_for_pending_email_is_throttled(): void {
		$captured1 = null;
		$filter1   = $this->capture_mail( $captured1 );
		$this->subscribe( 'immediatepending@example.com' );
		remove_filter( 'pre_wp_mail', $filter1, 10 );

		$captured2 = null;
		$filter2   = $this->capture_mail( $captured2 );
		$response  = $this->subscribe( 'immediatepending@example.com' );
		remove_filter( 'pre_wp_mail', $filter2, 10 );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNull( $captured2, 'An immediate resubmission must not resend the confirmation email.' );
	}

	public function test_subscribe_resends_for_a_still_pending_email_after_cooldown(): void {
		global $wpdb;

		$captured1 = null;
		$filter1   = $this->capture_mail( $captured1 );
		$this->subscribe( 'stillpending@example.com' );
		remove_filter( 'pre_wp_mail', $filter1, 10 );

		// Backdate created_at past the resend cooldown window (400s > 300s).
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			"UPDATE {$wpdb->prefix}agnosis_newsletter_subscribers SET created_at = ( NOW() - INTERVAL 400 SECOND ) WHERE email = %s",
			'stillpending@example.com'
		) );

		$captured2 = null;
		$filter2   = $this->capture_mail( $captured2 );
		$response  = $this->subscribe( 'stillpending@example.com' );
		remove_filter( 'pre_wp_mail', $filter2, 10 );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotNull( $captured2, 'Re-subscribing a pending address past the cooldown must resend the confirmation email.' );
	}

	// =========================================================================
	// Language selector (added alongside JoinPage's) — whitelist + locale mapping
	// =========================================================================

	/**
	 * The signup form's language <select> (SubmissionTranslator::language_names())
	 * only ever offers 'en' in this test bootstrap (Lingua Forge isn't loaded —
	 * see linguaforge-function-stubs.php's doc), so that's the one real,
	 * whitelisted code available to exercise the happy path here.
	 */
	public function test_subscribe_maps_whitelisted_language_to_wp_locale(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->subscribe( 'langpick@example.com', 'en' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$row = Subscriber::find_by_token( $this->find_token_for( 'langpick@example.com' ) );
		$this->assertSame( 'en_US', $row['locale'], "Admission::iso_to_wp_locale('en') must map to 'en_US'." );
	}

	/**
	 * Defensive re-check mirroring Admission::apply()'s own whitelist guard:
	 * a language code that survived sanitize_key() but isn't one of the
	 * languages the form's own <select> actually offered must never reach
	 * the subscriber's locale column — it's silently dropped (empty locale),
	 * not rejected with an error, consistent with this endpoint's
	 * enumeration-safe/always-201 behavior for every other input.
	 */
	public function test_subscribe_ignores_a_non_whitelisted_language_code(): void {
		$this->subscribe( 'badlang@example.com', 'zz' );

		$row = Subscriber::find_by_token( $this->find_token_for( 'badlang@example.com' ) );
		$this->assertEmpty( $row['locale'], 'A language code outside SubmissionTranslator::language_names() must not be stored.' );
	}

	public function test_subscribe_with_no_language_leaves_locale_empty(): void {
		$this->subscribe( 'nolang@example.com' );

		$row = Subscriber::find_by_token( $this->find_token_for( 'nolang@example.com' ) );
		$this->assertEmpty( $row['locale'] );
	}

	private function find_token_for( string $email ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT token FROM {$wpdb->prefix}agnosis_newsletter_subscribers WHERE email = %s", $email )
		);
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
