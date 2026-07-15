<?php
/**
 * Integration tests — audit §2c: `Admission::apply()`'s reapplication gates
 * never checked for `status = 'banned'` at all. A banned application row
 * falling through to the generic "every other case" branch silently
 * re-parked the row as 'unverified' with a fresh confirm token — overwriting
 * the ban record outright — and the fix text's own "retained-email
 * enforcement" promise (banned artists keep their email specifically so the
 * ban stays enforceable, see Core\Privacy's DSAR eraser) was therefore
 * unimplemented at this specific gate.
 *
 * In practice the earlier `get_user_by( 'email', $email )` check in apply()
 * already blocks reapplication in the common case, since
 * Departure::admin_ban() only removes the agnosis_artist role — it doesn't
 * delete the WP account. These tests deliberately do NOT create a WP user
 * for the banned email, mirroring the scenario this gate actually exists
 * for: the account was deleted independently of Agnosis's own
 * Departure::admin_delete() flow (e.g. a site admin using wp-admin's own
 * Users screen), leaving a 'banned' application row with no WP account
 * behind it — the only case where apply() ever reaches its own
 * `$existing`-status branching for a banned row at all.
 *
 * Also covers the optional half of the fix: honoring `banned_until` — a
 * lapsed temporary ban is treated like a resolved withdrawn/rejected/left
 * row (subject to the same weekly per-email reapplication rate limit,
 * AdmissionReapplicationAndLengthCapsTest's own §4b guard), while a
 * still-future or permanent (`banned_until IS NULL`) ban stays fully
 * blocked.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Core\RateLimiter;

class AdmissionBannedReapplicationTest extends \WP_UnitTestCase {

	private const REAPPLY_ACTION = 'admission_apply_email';

	protected function setUp(): void {
		parent::setUp();
		update_option( 'agnosis_admission_percent', 0 );
		update_option( 'agnosis_admission_minimum', 2 );
	}

	protected function tearDown(): void {
		foreach ( [ 'banned-forever@example.com', 'banned-future@example.com', 'banned-lapsed@example.com' ] as $email ) {
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

	private function apply( string $email ): \WP_REST_Response|\WP_Error {
		return $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => $email,
			'display_name' => 'Test Artist',
			'bio'          => 'I paint seascapes.',
			'statement'    => 'I want to share my work.',
			'language'     => 'en',
		] );
	}

	/**
	 * Insert a 'banned' application row directly — no WP user account is
	 * created, mirroring the scenario this gate actually exists for (see
	 * file docblock).
	 */
	private function seed_banned_application( string $email, ?string $banned_until ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => $email,
				'display_name' => 'Banned Artist',
				'bio'          => 'An old bio.',
				'statement'    => 'An old statement.',
				'language'     => 'en',
				'status'       => 'banned',
				'banned_until' => $banned_until,
				'applied_at'   => gmdate( 'Y-m-d H:i:s', time() - MONTH_IN_SECONDS ),
				'resolved_at'  => gmdate( 'Y-m-d H:i:s', time() - MONTH_IN_SECONDS ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	private function application_row( string $email ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status, banned_until, confirm_token FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
			$email
		) );
	}

	// =========================================================================
	// A permanent ban (banned_until IS NULL) always blocks reapplication
	// =========================================================================

	public function test_permanently_banned_application_blocks_reapplication(): void {
		$this->seed_banned_application( 'banned-forever@example.com', null );

		$response = $this->apply( 'banned-forever@example.com' );

		$this->assertSame( 201, $response->get_status() );

		$row = $this->application_row( 'banned-forever@example.com' );
		$this->assertSame( 'banned', $row->status, 'The ban record must not be overwritten by a reapplication attempt.' );
		$this->assertNull( $row->confirm_token, 'No fresh confirm token should have been issued — nothing was actually re-parked.' );
	}

	public function test_permanently_banned_application_response_is_enumeration_neutral(): void {
		$this->seed_banned_application( 'banned-forever@example.com', null );

		$response = $this->apply( 'banned-forever@example.com' );
		$data     = $response->get_data();

		// Identical shape to a brand-new application's success response — a
		// caller probing this endpoint must not be able to tell "banned"
		// apart from "your application is being processed".
		$this->assertSame( 'pending_confirmation', $data['status'] );
		$this->assertArrayHasKey( 'application_id', $data );
		$this->assertArrayHasKey( 'vouches_required', $data );
	}

	// =========================================================================
	// A still-future banned_until also blocks reapplication
	// =========================================================================

	public function test_ban_with_future_banned_until_blocks_reapplication(): void {
		$this->seed_banned_application( 'banned-future@example.com', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

		$response = $this->apply( 'banned-future@example.com' );

		$this->assertSame( 201, $response->get_status() );

		$row = $this->application_row( 'banned-future@example.com' );
		$this->assertSame( 'banned', $row->status, 'A ban that has not yet expired must still block reapplication.' );
	}

	// =========================================================================
	// A lapsed (expired) temporary ban is honored — falls through to the
	// normal reapply path, same as withdrawn/rejected/left.
	// =========================================================================

	public function test_lapsed_ban_allows_reapplication_and_clears_the_ban_record(): void {
		$this->seed_banned_application( 'banned-lapsed@example.com', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$response = $this->apply( 'banned-lapsed@example.com' );

		$this->assertSame( 201, $response->get_status() );

		$row = $this->application_row( 'banned-lapsed@example.com' );
		$this->assertSame( 'unverified', $row->status, 'A lapsed ban must fall through to the normal reapply flow, re-parking the row as unverified.' );
		$this->assertNull( $row->banned_until, 'Stale ban metadata must not survive a reapplication that was actually allowed through.' );
		$this->assertNotNull( $row->confirm_token, 'A fresh confirm token must have been issued.' );
	}

	public function test_second_reapplication_after_lapsed_ban_within_a_week_is_throttled(): void {
		$this->seed_banned_application( 'banned-lapsed@example.com', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$first = $this->apply( 'banned-lapsed@example.com' );
		$this->assertSame( 201, $first->get_status(), 'The first reapplication after a lapsed ban must be allowed — it consumes the week\'s reapply slot, same as withdrawn/rejected/left.' );

		// Simulate a second full cycle landing quickly, same idiom
		// AdmissionReapplicationAndLengthCapsTest uses for withdrawn/rejected/left.
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_applications',
			[ 'status' => 'banned', 'banned_until' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ],
			[ 'email' => 'banned-lapsed@example.com' ],
			[ '%s', '%s' ],
			[ '%s' ]
		);

		$second = $this->apply( 'banned-lapsed@example.com' );
		$this->assertSame( 429, $second->get_status(), 'A second reapplication within the same week must be throttled by the shared §4b weekly guard, same as any other resolved-application reapply.' );
	}
}
