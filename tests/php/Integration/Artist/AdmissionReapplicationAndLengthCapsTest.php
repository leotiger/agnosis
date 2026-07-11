<?php
/**
 * Integration tests — security audit §4b/§3b.
 *
 * §4b: a withdrawn/rejected/left application reapplying is legitimate by
 * design, but with no per-email cooldown the same address could previously
 * cycle apply → confirm → community vote blast → withdraw → apply again at
 * whatever rate the 5/min/IP endpoint limit and the (much shorter)
 * unverified-resend cooldown allowed. `Admission::apply()` now consults
 * `RateLimiter::check_sender( 'admission_apply_email', $email, 1, WEEK_IN_SECONDS )`
 * specifically when a *resolved* prior application (withdrawn/rejected/left)
 * is reapplying — scoped narrowly so it never touches a brand-new address or
 * a still-unconfirmed resend, both of which have their own, separate
 * cooldowns already covered by AdmissionIntegrationTest.
 *
 * §3b: `bio`/`statement`/`display_name` were sanitized but uncapped — the
 * vote email embeds all three for every recipient, so a multi-megabyte
 * statement was a mail-size/storage nuisance at minimum. New REST
 * `validate_callback`s on all three enforce Admission::MAX_DISPLAY_NAME_LENGTH
 * (100) / MAX_BIO_LENGTH / MAX_STATEMENT_LENGTH (5000 each).
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Core\RateLimiter;

class AdmissionReapplicationAndLengthCapsTest extends \WP_UnitTestCase {

	private const REAPPLY_ACTION = 'admission_apply_email';

	protected function setUp(): void {
		parent::setUp();
		update_option( 'agnosis_admission_percent', 0 );
		update_option( 'agnosis_admission_minimum', 2 );
		update_option( 'agnosis_admission_window_days', 7 );
	}

	protected function tearDown(): void {
		foreach ( [ 'withdrawn@example.com', 'rejected@example.com', 'left@example.com', 'fresh@example.com', 'reset-me@example.com' ] as $email ) {
			RateLimiter::reset_sender( self::REAPPLY_ACTION, $email, WEEK_IN_SECONDS );
		}
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function rest_post( string $route, array $params = [] ): \WP_REST_Response|\WP_Error {
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function apply( string $email, array $overrides = [] ): \WP_REST_Response|\WP_Error {
		return $this->rest_post( '/agnosis/v1/admission/apply', array_merge( [
			'email'        => $email,
			'display_name' => 'Test Artist',
			'bio'          => 'I paint seascapes.',
			'statement'    => 'I want to share my work.',
			'language'     => 'en',
		], $overrides ) );
	}

	/** Insert a resolved application row directly (bypassing apply()/confirm), for reapplication scenarios. */
	private function seed_resolved_application( string $email, string $status ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => $email,
				'display_name' => 'Prior Applicant',
				'bio'          => 'An earlier bio.',
				'statement'    => 'An earlier statement.',
				'language'     => 'en',
				'status'       => $status,
				// Well outside any resend/is_recent window — this row's own
				// history shouldn't matter to the reapplication guard, only
				// its status.
				'applied_at'   => gmdate( 'Y-m-d H:i:s', time() - MONTH_IN_SECONDS ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	/** Force an application row's status directly — simulates a full apply → confirm → vote/withdraw cycle completing again, without exercising that machinery. */
	private function set_application_status( string $email, string $status ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'agnosis_applications',
			[ 'status' => $status ],
			[ 'email' => $email ],
			[ '%s' ],
			[ '%s' ]
		);
	}

	// =========================================================================
	// §4b — per-email reapplication cooldown
	// =========================================================================

	// RateLimiter::check_sender( ..., 1, WEEK_IN_SECONDS ) allows ONE
	// reapplication through before it starts blocking — that first
	// successful reapply is what consumes the week's slot. So the guard
	// only becomes visible on a SECOND resolved-application reapplying
	// within the same week; each test below drives that explicitly rather
	// than expecting the very first reapply ever to be throttled.

	public function test_second_reapplication_after_withdrawn_within_a_week_is_throttled(): void {
		$this->seed_resolved_application( 'withdrawn@example.com', 'withdrawn' );

		$first = $this->apply( 'withdrawn@example.com' );
		$this->assertSame( 201, $first->get_status(), 'The first reapplication within the window must be allowed — it consumes the slot; the guard only blocks a second one.' );

		// Simulate a second full cycle (reapplied, confirmed, withdrawn
		// again) landing quickly — RateLimiter::check_sender() is keyed on
		// email + action only, so the real confirm/vouch/withdraw machinery
		// doesn't need to be exercised to prove the guard itself works.
		$this->set_application_status( 'withdrawn@example.com', 'withdrawn' );

		$second = $this->apply( 'withdrawn@example.com' );
		$this->assertSame( 429, $second->get_status() );
	}

	public function test_second_reapplication_after_rejected_within_a_week_is_throttled(): void {
		$this->seed_resolved_application( 'rejected@example.com', 'rejected' );

		$first = $this->apply( 'rejected@example.com' );
		$this->assertSame( 201, $first->get_status(), 'The first reapplication within the window must be allowed — it consumes the slot; the guard only blocks a second one.' );

		$this->set_application_status( 'rejected@example.com', 'rejected' );

		$second = $this->apply( 'rejected@example.com' );
		$this->assertSame( 429, $second->get_status() );
	}

	public function test_second_reapplication_after_left_within_a_week_is_throttled(): void {
		$this->seed_resolved_application( 'left@example.com', 'left' );

		$first = $this->apply( 'left@example.com' );
		$this->assertSame( 201, $first->get_status(), 'The first reapplication within the window must be allowed — it consumes the slot; the guard only blocks a second one.' );

		$this->set_application_status( 'left@example.com', 'left' );

		$second = $this->apply( 'left@example.com' );
		$this->assertSame( 429, $second->get_status() );
	}

	public function test_reapplying_after_cooldown_window_elapses_succeeds_again(): void {
		$this->seed_resolved_application( 'reset-me@example.com', 'rejected' );

		$first = $this->apply( 'reset-me@example.com' );
		$this->assertSame( 201, $first->get_status(), 'Sanity check: the first reapply attempt must succeed and consume the week\'s slot.' );

		$this->set_application_status( 'reset-me@example.com', 'rejected' );
		$second = $this->apply( 'reset-me@example.com' );
		$this->assertSame( 429, $second->get_status(), 'Sanity check: a second reapply within the same week must be throttled.' );

		// Simulate the week-long cooldown having elapsed — same idiom every
		// other per-sender-throttle test in this codebase uses rather than
		// actually waiting out the window.
		RateLimiter::reset_sender( self::REAPPLY_ACTION, 'reset-me@example.com', WEEK_IN_SECONDS );

		$this->set_application_status( 'reset-me@example.com', 'rejected' );
		$third = $this->apply( 'reset-me@example.com' );
		$this->assertSame( 201, $third->get_status(), 'A reapplication after the cooldown window has elapsed must be allowed through to the normal unverified-parking flow.' );
	}

	public function test_brand_new_address_is_never_throttled_by_the_reapply_guard(): void {
		// No prior application row at all for this address — the reapply
		// guard must only ever engage for a resolved PRIOR application, never
		// a genuinely first-time applicant.
		$response = $this->apply( 'fresh@example.com' );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_resend_of_still_unverified_application_is_not_subject_to_the_weekly_guard(): void {
		// A still-'unverified' row (never resolved either way) reapplying is
		// governed entirely by the existing, much shorter resend cooldown
		// (security audit §3a, covered in AdmissionIntegrationTest) — the new
		// weekly per-email guard is scoped to withdrawn/rejected/left only
		// and must never fire here.
		$email = 'still-unverified@example.com';
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => $email,
				'display_name' => 'Prior Applicant',
				'bio'          => '',
				'statement'    => '',
				'language'     => 'en',
				'status'       => 'unverified',
				// Past its own 5-minute resend cooldown, so the existing
				// §3a logic treats this as a fresh resend, not a duplicate.
				'applied_at'   => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$response = $this->apply( $email );

		$this->assertSame( 201, $response->get_status(), 'A resend of a still-unverified application must not be caught by the withdrawn/rejected/left reapplication guard.' );
	}

	// =========================================================================
	// §3b — server-side length caps
	// =========================================================================

	public function test_display_name_over_max_length_is_rejected(): void {
		$response = $this->apply( 'toolongname@example.com', [ 'display_name' => str_repeat( 'a', 101 ) ] );

		// A validate_callback returning WP_Error is wrapped by WP core's own
		// has_valid_params() into a generic 'rest_invalid_param' error with a
		// hardcoded status of 400 — our own WP_Error's ['status' => 400] data
		// happens to match, but the wrapping means we assert the outer
		// response's status, not our inner error object.
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_display_name_at_max_length_is_accepted(): void {
		$response = $this->apply( 'exactlyname@example.com', [ 'display_name' => str_repeat( 'a', 100 ) ] );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_bio_over_max_length_is_rejected(): void {
		$response = $this->apply( 'toolongbio@example.com', [ 'bio' => str_repeat( 'a', 5001 ) ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_bio_at_max_length_is_accepted(): void {
		$response = $this->apply( 'exactlybio@example.com', [ 'bio' => str_repeat( 'a', 5000 ) ] );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_statement_over_max_length_is_rejected(): void {
		$response = $this->apply( 'toolongstatement@example.com', [ 'statement' => str_repeat( 'a', 5001 ) ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_statement_at_max_length_is_accepted(): void {
		$response = $this->apply( 'exactlystatement@example.com', [ 'statement' => str_repeat( 'a', 5000 ) ] );

		$this->assertSame( 201, $response->get_status() );
	}
}
