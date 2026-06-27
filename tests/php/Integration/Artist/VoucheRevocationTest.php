<?php
/**
 * Integration tests — vouch revocation and expiry filtering.
 *
 * Verifies that:
 *   - Revoked vouches are excluded from count_vouches() (via status endpoint)
 *   - Active vouches still count after unrelated vouches are revoked
 *   - revoke_vouch() is idempotent (double-revoke returns false)
 *   - revoke_vouch() returns false for a non-existent vouch
 *   - A candidate is not admitted when sufficient vouches exist but all are revoked
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Admission;

class VoucheRevocationTest extends \WP_UnitTestCase {

	private Admission $admission;

	protected function setUp(): void {
		parent::setUp();
		$this->admission = new Admission();
		update_option( 'agnosis_vouches_required', 2 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_artist(): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_user_by( 'id', $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	private function create_applicant(): int {
		return self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	/** Insert a vouch row directly (bypasses REST) and return the vouch row ID. */
	private function insert_vouch( int $voucher_id, int $candidate_id ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_vouches',
			[
				'voucher_id'   => $voucher_id,
				'candidate_id' => $candidate_id,
				'message'      => 'test vouch',
			],
			[ '%d', '%d', '%s' ]
		);
	}

	/** Count active (non-revoked) vouches via the status REST endpoint. */
	private function get_vouch_count( int $candidate_id ): int {
		wp_set_current_user( $candidate_id );
		$request = new \WP_REST_Request( 'GET', "/agnosis/v1/admission/status/$candidate_id" );
		$response = rest_do_request( $request );
		return (int) ( $response->get_data()['vouches_received'] ?? -1 );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_revoked_vouch_is_excluded_from_count(): void {
		$artist    = $this->create_artist();
		$applicant = $this->create_applicant();

		$this->insert_vouch( $artist, $applicant );
		$this->assertSame( 1, $this->get_vouch_count( $applicant ) );

		$this->admission->revoke_vouch( $artist, $applicant );

		$this->assertSame( 0, $this->get_vouch_count( $applicant ) );
	}

	public function test_active_vouches_still_count_after_partial_revocation(): void {
		$artist_a  = $this->create_artist();
		$artist_b  = $this->create_artist();
		$applicant = $this->create_applicant();

		$this->insert_vouch( $artist_a, $applicant );
		$this->insert_vouch( $artist_b, $applicant );
		$this->assertSame( 2, $this->get_vouch_count( $applicant ) );

		$this->admission->revoke_vouch( $artist_a, $applicant );

		$this->assertSame( 1, $this->get_vouch_count( $applicant ) );
	}

	public function test_revoke_vouch_returns_false_for_nonexistent_vouch(): void {
		$artist    = $this->create_artist();
		$applicant = $this->create_applicant();

		$result = $this->admission->revoke_vouch( $artist, $applicant );

		$this->assertFalse( $result );
	}

	public function test_revoke_vouch_is_idempotent(): void {
		$artist    = $this->create_artist();
		$applicant = $this->create_applicant();

		$this->insert_vouch( $artist, $applicant );

		$first  = $this->admission->revoke_vouch( $artist, $applicant );
		$second = $this->admission->revoke_vouch( $artist, $applicant );

		$this->assertTrue( $first );
		$this->assertFalse( $second ); // already revoked — no row updated
	}

	public function test_candidate_not_admitted_when_all_vouches_revoked(): void {
		$artist_a  = $this->create_artist();
		$artist_b  = $this->create_artist();
		$applicant = $this->create_applicant();

		update_user_meta( $applicant, '_agnosis_applied', current_time( 'mysql' ) );
		$this->insert_vouch( $artist_a, $applicant );
		$this->insert_vouch( $artist_b, $applicant );

		$this->admission->revoke_vouch( $artist_a, $applicant );
		$this->admission->revoke_vouch( $artist_b, $applicant );

		// Active vouch count is now 0 — applicant should not have been admitted.
		$user = get_userdata( $applicant );
		$this->assertNotContains( 'agnosis_artist', (array) $user->roles );
		$this->assertSame( 0, $this->get_vouch_count( $applicant ) );
	}

	public function test_revoke_vouch_preserves_row_for_audit(): void {
		global $wpdb;
		$artist    = $this->create_artist();
		$applicant = $this->create_applicant();

		$this->insert_vouch( $artist, $applicant );
		$this->admission->revoke_vouch( $artist, $applicant );

		// Row should still exist — only revoked_at is set.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_vouches WHERE voucher_id = %d AND candidate_id = %d",
				$artist,
				$applicant
			)
		);

		$this->assertNotNull( $row );
		$this->assertNotNull( $row->revoked_at );
	}
}
