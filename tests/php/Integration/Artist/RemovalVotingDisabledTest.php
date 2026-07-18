<?php
/**
 * Integration tests — agnosis_voting_disabled ("admin approval only") for
 * the community removal flow (Departure.php).
 *
 * Covers:
 *   - nominate() refuses to start a NEW nomination (or advance one still in
 *     'nominating' status) while the setting is on.
 *   - nominate() still forwards to a vote when the target request is already
 *     'open' — an in-flight community vote is left to run its normal course
 *     even after the setting is switched on (see agnosis_voting_disabled's
 *     own description in SettingsFields.php).
 *   - record_vote_on_request()/cast_vote() (REST) and, by extension,
 *     RemovalVoteConfirm's email-link vote, are unaffected either way — they
 *     only ever act on an already-'open' request.
 *   - admin_open_removal_vote() (the admin bypass that opens a brand new
 *     community vote) refuses while the setting is on.
 *   - check_expired_removal_votes() (the daily resolution cron) keeps
 *     resolving already-open requests regardless of the setting — it never
 *     opens a new one, so there is nothing for the setting to block there.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Departure;

class RemovalVotingDisabledTest extends \WP_UnitTestCase {

	private Departure $departure;

	protected function setUp(): void {
		parent::setUp();
		$this->departure = new Departure();
		update_option( 'agnosis_removal_nomination_threshold', 3 );
		update_option( 'agnosis_removal_window_days', 7 );
	}

	public function tearDown(): void {
		delete_option( 'agnosis_voting_disabled' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers (mirrors DepartureTest.php)
	// -------------------------------------------------------------------------

	/** @return array{0: int, 1: int} [ user_id, application_id ] */
	private function create_admitted_artist( string $email = 'artist@example.com' ): array {
		global $wpdb;

		$user_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$user    = get_user_by( 'id', $user_id );
		$user->add_role( 'agnosis_artist' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => $email,
				'display_name' => 'Test Artist',
				'status'       => 'admitted',
				'wp_user_id'   => $user_id,
				'resolved_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%d', '%s' ]
		);

		return [ $user_id, (int) $wpdb->insert_id ];
	}

	/** Insert an 'open' removal request for $subject_id and return its row ID. */
	private function create_open_removal_request( int $subject_id ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_removal_requests',
			[
				'subject_user_id' => $subject_id,
				'status'          => 'open',
				'opened_at'       => current_time( 'mysql' ),
				'closes_at'       => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	private function request_status( int $request_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}agnosis_removal_requests WHERE id = %d",
				$request_id
			)
		);
	}

	private function nominate_request( int $subject_id, int $voter_id ): \WP_REST_Response|\WP_Error {
		wp_set_current_user( $voter_id );
		$request = new \WP_REST_Request( 'POST', "/agnosis/v1/removal/nominate/{$subject_id}" );
		$request->set_param( 'id', $subject_id );
		return $this->departure->nominate( $request );
	}

	// -------------------------------------------------------------------------
	// nominate() — starting/advancing a new nomination
	// -------------------------------------------------------------------------

	public function test_nominate_refuses_new_nomination_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		[ $subject_id, ] = $this->create_admitted_artist( 'subject-a@example.com' );
		[ $voter_id, ]    = $this->create_admitted_artist( 'voter-a@example.com' );

		$result = $this->nominate_request( $subject_id, $voter_id );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'agnosis_voting_disabled', $result->get_error_code() );
	}

	public function test_nominate_creates_new_request_when_voting_enabled(): void {
		global $wpdb;

		[ $subject_id, ] = $this->create_admitted_artist( 'subject-b@example.com' );
		[ $voter_id, ]    = $this->create_admitted_artist( 'voter-b@example.com' );

		$result = $this->nominate_request( $subject_id, $voter_id );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'nominated', $result->get_data()['status'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$wpdb->prefix}agnosis_removal_requests WHERE subject_user_id = %d",
			$subject_id
		) );
		$this->assertSame( 'nominating', $status );
	}

	public function test_nominate_refuses_to_advance_still_nominating_request_when_voting_disabled(): void {
		global $wpdb;

		[ $subject_id, ] = $this->create_admitted_artist( 'subject-c@example.com' );
		[ $voter1, ]      = $this->create_admitted_artist( 'voter-c1@example.com' );
		[ $voter2, ]      = $this->create_admitted_artist( 'voter-c2@example.com' );

		// First nomination while voting is still enabled — leaves the request
		// in 'nominating' (threshold is 3, only 1 nomination so far).
		$this->nominate_request( $subject_id, $voter1 );

		update_option( 'agnosis_voting_disabled', true );

		$result = $this->nominate_request( $subject_id, $voter2 );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$wpdb->prefix}agnosis_removal_requests WHERE subject_user_id = %d",
			$subject_id
		) );
		$this->assertSame( 'nominating', $status, 'A still-gathering nomination must not advance once voting is disabled.' );
	}

	// -------------------------------------------------------------------------
	// nominate() forwarding to an already-open vote — left running
	// -------------------------------------------------------------------------

	public function test_nominate_still_casts_vote_on_already_open_request_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		[ $subject_id, ] = $this->create_admitted_artist( 'subject-d@example.com' );
		[ $voter_id, ]    = $this->create_admitted_artist( 'voter-d@example.com' );
		$request_id       = $this->create_open_removal_request( $subject_id );

		$result = $this->nominate_request( $subject_id, $voter_id );

		$this->assertFalse( is_wp_error( $result ), 'Casting a vote on an already-open request must still work while voting is disabled.' );
		$this->assertSame( 1, $result->get_data()['yes_votes'] );
	}

	// -------------------------------------------------------------------------
	// record_vote_on_request() / cast_vote() — unaffected either way
	// -------------------------------------------------------------------------

	public function test_record_vote_on_request_still_works_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		[ $subject_id, ] = $this->create_admitted_artist( 'subject-e@example.com' );
		[ $voter_id, ]    = $this->create_admitted_artist( 'voter-e@example.com' );
		$request_id       = $this->create_open_removal_request( $subject_id );

		$result = $this->departure->record_vote_on_request( $request_id, $voter_id, 'yes' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 1, $result->get_data()['yes_votes'] );
	}

	// -------------------------------------------------------------------------
	// admin_open_removal_vote() — admin bypass that opens a NEW community vote
	// -------------------------------------------------------------------------

	public function test_admin_open_removal_vote_refuses_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		[ $subject_id, ] = $this->create_admitted_artist( 'subject-f@example.com' );
		$admin_id         = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$ok = $this->departure->admin_open_removal_vote( $subject_id, $admin_id );

		$this->assertFalse( $ok );
	}

	public function test_admin_open_removal_vote_still_works_when_voting_enabled(): void {
		global $wpdb;

		[ $subject_id, ] = $this->create_admitted_artist( 'subject-g@example.com' );
		$admin_id         = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$ok = $this->departure->admin_open_removal_vote( $subject_id, $admin_id );

		$this->assertTrue( $ok );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$wpdb->prefix}agnosis_removal_requests WHERE subject_user_id = %d",
			$subject_id
		) );
		$this->assertSame( 'open', $status );
	}

	// -------------------------------------------------------------------------
	// check_expired_removal_votes() — resolves in-flight votes regardless
	// -------------------------------------------------------------------------

	public function test_check_expired_removal_votes_still_resolves_when_voting_disabled(): void {
		global $wpdb;

		update_option( 'agnosis_voting_disabled', true );

		[ $subject_id, $app_id ] = $this->create_admitted_artist( 'subject-h@example.com' );
		[ $voter1_id, ]          = $this->create_admitted_artist( 'voter-h1@example.com' );
		[ $voter2_id, ]          = $this->create_admitted_artist( 'voter-h2@example.com' );

		$request_id = $this->create_open_removal_request( $subject_id );
		// Force the window closed so the cron picks it up.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_removal_requests',
			[ 'closes_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ) ],
			[ 'id' => $request_id ],
			[ '%s' ], [ '%d' ]
		);

		// Majority yes: count_active_artists() counts every agnosis_artist,
		// including the subject themselves (who cannot vote on their own
		// removal) — so with 3 active artists here, 2 yes votes (66%) clears
		// the strict >50% bar; 1 yes vote out of 2 active artists (the subject
		// plus a single voter) would only be exactly 50%, not a majority.
		$this->departure->record_vote_on_request( $request_id, $voter1_id, 'yes' );
		$this->departure->record_vote_on_request( $request_id, $voter2_id, 'yes' );

		$this->departure->check_expired_removal_votes();

		$this->assertSame( 'passed', $this->request_status( $request_id ),
			'An already-open removal vote must still resolve on schedule even while community voting is disabled.' );
	}
}
