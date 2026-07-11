<?php
/**
 * Integration tests — member vote to change the community size cap (Phase 2).
 *
 * Covers the propose → co-sign → open → vote → tally lifecycle, the cap being
 * written on a passing vote, the waitlist advancing when the cap is raised, and
 * the guard conditions.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\CommunityCapVote;

class CommunityCapVoteIntegrationTest extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		update_option( 'agnosis_cap_proposal_threshold', 2 ); // 2 co-signs opens the vote
		update_option( 'agnosis_cap_vote_window_days', 7 );
		update_option( 'agnosis_community_max_artists', 4 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_artist(): int {
		$id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_user_by( 'id', $id )->add_role( 'agnosis_artist' );
		return $id;
	}

	private function post( string $route, array $params, int $user_id ): \WP_REST_Response {
		wp_set_current_user( $user_id );
		$req = new \WP_REST_Request( 'POST', $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		/** @var \WP_REST_Response $res */
		$res = rest_do_request( $req );
		return $res;
	}

	private function proposal_status( int $id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}agnosis_cap_proposals WHERE id = %d", $id ) );
	}

	private function expire_window( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'agnosis_cap_proposals', [ 'closes_at' => '2000-01-01 00:00:00' ], [ 'id' => $id ] );
	}

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	public function test_propose_then_cosign_opens_the_vote(): void {
		$a1 = $this->create_artist();
		$a2 = $this->create_artist();

		$res = $this->post( '/agnosis/v1/cap/propose', [ 'cap' => 10 ], $a1 );
		$this->assertSame( 201, $res->get_status() );
		$pid = (int) $res->get_data()['proposal_id'];
		$this->assertSame( 'nominating', $this->proposal_status( $pid ) ); // 1 co-sign < threshold 2

		$res2 = $this->post( "/agnosis/v1/cap/cosign/{$pid}", [], $a2 );
		$this->assertSame( 201, $res2->get_status() );
		$this->assertSame( 'open', $this->proposal_status( $pid ) ); // threshold met → opens
	}

	public function test_propose_rejects_the_current_value(): void {
		$a1 = $this->create_artist();
		update_option( 'agnosis_community_max_artists', 7 );

		$res = $this->post( '/agnosis/v1/cap/propose', [ 'cap' => 7 ], $a1 );

		$this->assertSame( 400, $res->get_status() );
	}

	public function test_second_different_proposal_conflicts(): void {
		$a1 = $this->create_artist();
		$a2 = $this->create_artist();

		$this->post( '/agnosis/v1/cap/propose', [ 'cap' => 10 ], $a1 );
		$res = $this->post( '/agnosis/v1/cap/propose', [ 'cap' => 20 ], $a2 );

		$this->assertSame( 409, $res->get_status() );
	}

	public function test_cast_vote_rejected_when_not_open(): void {
		$a1 = $this->create_artist();
		$a2 = $this->create_artist();

		$pid = (int) $this->post( '/agnosis/v1/cap/propose', [ 'cap' => 10 ], $a1 )->get_data()['proposal_id'];
		// Still 'nominating' (only 1 co-sign) → voting not allowed.
		$res = $this->post( "/agnosis/v1/cap/vote/{$pid}", [ 'vote' => 'yes' ], $a2 );

		$this->assertSame( 409, $res->get_status() );
	}

	public function test_non_artist_cannot_propose(): void {
		$res = $this->post( '/agnosis/v1/cap/propose', [ 'cap' => 10 ], 0 );
		$this->assertSame( 403, $res->get_status() );
	}

	// -------------------------------------------------------------------------
	// Tally
	// -------------------------------------------------------------------------

	public function test_cron_passes_with_majority_and_writes_cap(): void {
		$a1 = $this->create_artist();
		$a2 = $this->create_artist();
		$a3 = $this->create_artist();
		$a4 = $this->create_artist(); // 4 active → need > 2 yes

		$pid = (int) $this->post( '/agnosis/v1/cap/propose', [ 'cap' => 12 ], $a1 )->get_data()['proposal_id'];
		$this->post( "/agnosis/v1/cap/cosign/{$pid}", [], $a2 ); // opens (a1,a2 = 2 yes)
		$this->assertSame( 'open', $this->proposal_status( $pid ) );
		$this->post( "/agnosis/v1/cap/vote/{$pid}", [ 'vote' => 'yes' ], $a3 ); // 3 yes / 4 > 0.5

		$this->expire_window( $pid );
		( new CommunityCapVote() )->check_expired_cap_votes();

		$this->assertSame( 'passed', $this->proposal_status( $pid ) );
		$this->assertSame( 12, (int) get_option( 'agnosis_community_max_artists' ) );
	}

	public function test_cron_fails_without_majority_and_leaves_cap(): void {
		$a1 = $this->create_artist();
		$a2 = $this->create_artist();
		$this->create_artist();
		$this->create_artist(); // 4 active → 2 yes = 50 %, not a majority

		$pid = (int) $this->post( '/agnosis/v1/cap/propose', [ 'cap' => 12 ], $a1 )->get_data()['proposal_id'];
		$this->post( "/agnosis/v1/cap/cosign/{$pid}", [], $a2 ); // opens; 2 yes / 4 = 0.5
		$this->assertSame( 'open', $this->proposal_status( $pid ) );

		$this->expire_window( $pid );
		( new CommunityCapVote() )->check_expired_cap_votes();

		$this->assertSame( 'failed', $this->proposal_status( $pid ) );
		$this->assertSame( 4, (int) get_option( 'agnosis_community_max_artists' ) ); // unchanged
	}

	public function test_passed_vote_raising_cap_advances_waitlist(): void {
		// Over-capacity start: cap 1 but 3 artists (existing are grandfathered).
		update_option( 'agnosis_community_max_artists', 1 );
		$a1 = $this->create_artist();
		$a2 = $this->create_artist();
		$a3 = $this->create_artist();

		// Two applicants waitlist (community is full at cap 1).
		global $wpdb;
		foreach ( [ 'w1@example.com', 'w2@example.com' ] as $email ) {
			$req = new \WP_REST_Request( 'POST', '/agnosis/v1/admission/apply' );
			$req->set_param( 'email', $email );
			$req->set_param( 'display_name', 'WL' );
			$req->set_param( 'bio', 'Bio' );
			$req->set_param( 'language', 'en' );
			wp_set_current_user( 0 );
			$apply_response = rest_do_request( $req );

			// Double opt-in (security audit §3a/§4a): apply() only ever parks
			// the row as 'unverified' now — confirm immediately, mirroring the
			// applicant clicking the confirm link right away, so the waitlist
			// decision (made inside confirm_application(), not apply()) fires
			// while the community is still full.
			$application_id = (int) ( $apply_response->get_data()['application_id'] ?? 0 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$token = $wpdb->get_var(
				$wpdb->prepare( "SELECT confirm_token FROM {$wpdb->prefix}agnosis_applications WHERE id = %d", $application_id )
			);
			( new \Agnosis\Artist\Admission() )->confirm_application( (string) $token );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$waitlisted = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_applications WHERE status = 'waitlisted'" );
		$this->assertSame( 2, $waitlisted );

		// Vote to raise the cap to 5.
		$pid = (int) $this->post( '/agnosis/v1/cap/propose', [ 'cap' => 5 ], $a1 )->get_data()['proposal_id'];
		$this->post( "/agnosis/v1/cap/cosign/{$pid}", [], $a2 ); // opens (2 yes)
		$this->post( "/agnosis/v1/cap/vote/{$pid}", [ 'vote' => 'yes' ], $a3 ); // 3 yes / 3 active

		$this->expire_window( $pid );
		( new CommunityCapVote() )->check_expired_cap_votes();

		$this->assertSame( 5, (int) get_option( 'agnosis_community_max_artists' ) );
		// Cap raised → a slot opened → the oldest waitlisted application advanced.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$still_waitlisted = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_applications WHERE status = 'waitlisted'" );
		$this->assertSame( 1, $still_waitlisted );
	}
}
