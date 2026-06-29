<?php
/**
 * Integration tests — vouch revocation on pending applications.
 *
 * Verifies that:
 *   - Revoked vouches are excluded from count_vouches() (via status endpoint)
 *   - Active vouches still count after unrelated vouches are revoked
 *   - revoke_vouch() is idempotent (double-revoke returns false)
 *   - revoke_vouch() returns false for a non-existent vouch
 *   - An applicant is not admitted when sufficient vouches exist but all are revoked
 *   - Revoked rows are preserved for the audit trail (revoked_at set, row not deleted)
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
		update_option( 'agnosis_admission_percent', 0 );
		update_option( 'agnosis_admission_minimum', 2 );
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

	/** Insert a row into agnosis_applications and return the application ID. */
	private function insert_application( string $email ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => $email,
				'display_name' => 'Test Artist',
				'status'       => 'pending',
			],
			[ '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	/** Insert a vouch row directly (bypasses REST) for a given application. */
	private function insert_vouch( int $voucher_id, int $application_id ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_application_vouches',
			[
				'application_id' => $application_id,
				'voucher_id'     => $voucher_id,
				'vote'           => 'yes',
				'message'        => 'test vouch',
			],
			[ '%d', '%d', '%s', '%s' ]
		);
	}

	/** Count active (non-revoked) vouches via the admin status endpoint. */
	private function get_vouch_count( int $application_id ): int {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$request  = new \WP_REST_Request( 'GET', "/agnosis/v1/admission/status/{$application_id}" );
		$response = rest_do_request( $request );
		return (int) ( $response->get_data()['vouches_received'] ?? -1 );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_revoked_vouch_is_excluded_from_count(): void {
		$artist         = $this->create_artist();
		$application_id = $this->insert_application( 'revoke1@example.com' );

		$this->insert_vouch( $artist, $application_id );
		$this->assertSame( 1, $this->get_vouch_count( $application_id ) );

		$this->admission->revoke_vouch( $artist, $application_id );

		$this->assertSame( 0, $this->get_vouch_count( $application_id ) );
	}

	public function test_active_vouches_still_count_after_partial_revocation(): void {
		$artist_a       = $this->create_artist();
		$artist_b       = $this->create_artist();
		$application_id = $this->insert_application( 'revoke2@example.com' );

		$this->insert_vouch( $artist_a, $application_id );
		$this->insert_vouch( $artist_b, $application_id );
		$this->assertSame( 2, $this->get_vouch_count( $application_id ) );

		$this->admission->revoke_vouch( $artist_a, $application_id );

		$this->assertSame( 1, $this->get_vouch_count( $application_id ) );
	}

	public function test_revoke_vouch_returns_false_for_nonexistent_vouch(): void {
		$artist         = $this->create_artist();
		$application_id = $this->insert_application( 'revoke3@example.com' );

		$result = $this->admission->revoke_vouch( $artist, $application_id );

		$this->assertFalse( $result );
	}

	public function test_revoke_vouch_is_idempotent(): void {
		$artist         = $this->create_artist();
		$application_id = $this->insert_application( 'revoke4@example.com' );

		$this->insert_vouch( $artist, $application_id );

		$first  = $this->admission->revoke_vouch( $artist, $application_id );
		$second = $this->admission->revoke_vouch( $artist, $application_id );

		$this->assertTrue( $first );
		$this->assertFalse( $second ); // already revoked — no row updated
	}

	public function test_applicant_not_admitted_when_all_vouches_revoked(): void {
		$artist_a       = $this->create_artist();
		$artist_b       = $this->create_artist();
		$application_id = $this->insert_application( 'revoke5@example.com' );

		$this->insert_vouch( $artist_a, $application_id );
		$this->insert_vouch( $artist_b, $application_id );

		$this->admission->revoke_vouch( $artist_a, $application_id );
		$this->admission->revoke_vouch( $artist_b, $application_id );

		// Active vouch count is 0 — no WP user should have been created.
		$this->assertFalse( get_user_by( 'email', 'revoke5@example.com' ) );
		$this->assertSame( 0, $this->get_vouch_count( $application_id ) );
	}

	public function test_revoke_vouch_preserves_row_for_audit(): void {
		global $wpdb;

		$artist         = $this->create_artist();
		$application_id = $this->insert_application( 'revoke6@example.com' );

		$this->insert_vouch( $artist, $application_id );
		$this->admission->revoke_vouch( $artist, $application_id );

		// Row must still exist — only revoked_at is set, row is not deleted.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_application_vouches
				 WHERE voucher_id = %d AND application_id = %d",
				$artist,
				$application_id
			)
		);

		$this->assertNotNull( $row );
		$this->assertNotNull( $row->revoked_at );
	}
}
