<?php
/**
 * Integration tests — member-governed community size cap (Phase 1).
 *
 * Covers the cap gate at apply() and maybe_admit(), the FIFO waitlist, the
 * "open a slot, fill a slot" advance on departure, and the admin over-cap
 * override.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Admission;
use Agnosis\Artist\CommunityCap;

class CommunityCapIntegrationTest extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Vouch threshold: 0 % + minimum 2 → always 2 in tests.
		update_option( 'agnosis_admission_percent', 0 );
		update_option( 'agnosis_admission_minimum', 2 );
		update_option( 'agnosis_admission_window_days', 7 );
		update_option( 'agnosis_community_max_artists', 0 ); // per-test override below
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_artist(): int {
		$id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_user_by( 'id', $id )->add_role( 'agnosis_artist' );
		return $id;
	}

	private function apply( string $email, string $name = 'Artist' ): \WP_REST_Response {
		wp_set_current_user( 0 );
		$req = new \WP_REST_Request( 'POST', '/agnosis/v1/admission/apply' );
		$req->set_param( 'email', $email );
		$req->set_param( 'display_name', $name );
		$req->set_param( 'bio', 'I paint seascapes.' );
		$req->set_param( 'language', 'en' ); // now required — see Admission::apply().
		/** @var \WP_REST_Response $res */
		$res = rest_do_request( $req );
		return $res;
	}

	private function status_of( int $application_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}agnosis_applications WHERE id = %d", $application_id )
		);
	}

	// -------------------------------------------------------------------------
	// CommunityCap state
	// -------------------------------------------------------------------------

	public function test_is_full_respects_cap_and_unlimited(): void {
		$this->create_artist();
		$this->create_artist();

		update_option( 'agnosis_community_max_artists', 2 );
		$this->assertTrue( ( new CommunityCap() )->is_full() );
		$this->assertSame( 0, ( new CommunityCap() )->remaining() );

		update_option( 'agnosis_community_max_artists', 3 );
		$this->assertFalse( ( new CommunityCap() )->is_full() );
		$this->assertSame( 1, ( new CommunityCap() )->remaining() );

		update_option( 'agnosis_community_max_artists', 0 ); // unlimited
		$this->assertFalse( ( new CommunityCap() )->is_full() );
		$this->assertTrue( ( new CommunityCap() )->is_unlimited() );
	}

	// -------------------------------------------------------------------------
	// apply() gate
	// -------------------------------------------------------------------------

	public function test_apply_waitlists_when_full(): void {
		$this->create_artist();
		$this->create_artist();
		update_option( 'agnosis_community_max_artists', 2 );

		$res  = $this->apply( 'waitlisted@example.com' );
		$data = $res->get_data();

		$this->assertSame( 202, $res->get_status() );
		$this->assertSame( 'waitlisted', $data['status'] );
		$this->assertSame( 'waitlisted', $this->status_of( (int) $data['application_id'] ) );
	}

	public function test_apply_is_pending_when_capacity(): void {
		$this->create_artist();
		update_option( 'agnosis_community_max_artists', 5 );

		$res  = $this->apply( 'ok@example.com' );
		$data = $res->get_data();

		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'applied', $data['status'] );
		$this->assertSame( 'pending', $this->status_of( (int) $data['application_id'] ) );
	}

	public function test_unlimited_never_waitlists(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_artist();
		}
		update_option( 'agnosis_community_max_artists', 0 );

		$res = $this->apply( 'free@example.com' );

		$this->assertSame( 201, $res->get_status() );
	}

	// -------------------------------------------------------------------------
	// maybe_admit() gate — a pending app that reaches threshold while full
	// -------------------------------------------------------------------------

	public function test_threshold_reached_while_full_waitlists_instead_of_admitting(): void {
		update_option( 'agnosis_community_max_artists', 3 );
		$a1 = $this->create_artist();
		$a2 = $this->create_artist(); // 2 active < cap 3 → capacity at apply time

		$app_id = (int) $this->apply( 'cand@example.com' )->get_data()['application_id'];
		$this->assertSame( 'pending', $this->status_of( $app_id ) );

		$this->create_artist(); // 3 active = cap → now full

		$admission = new Admission();
		$admission->record_vote( $a1, $app_id, 'yes' );
		$admission->record_vote( $a2, $app_id, 'yes' ); // threshold (2) met → maybe_admit

		// Full at admission time → parked, not admitted; no new user created.
		$this->assertSame( 'waitlisted', $this->status_of( $app_id ) );
		$this->assertFalse( (bool) get_user_by( 'email', 'cand@example.com' ) );
	}

	// -------------------------------------------------------------------------
	// advance_waitlist() — open a slot, fill a slot
	// -------------------------------------------------------------------------

	public function test_advance_waitlist_promotes_oldest_when_slot_opens(): void {
		$artist = $this->create_artist();
		update_option( 'agnosis_community_max_artists', 1 );

		$first  = (int) $this->apply( 'first@example.com' )->get_data()['application_id'];
		$second = (int) $this->apply( 'second@example.com' )->get_data()['application_id'];
		$this->assertSame( 'waitlisted', $this->status_of( $first ) );
		$this->assertSame( 'waitlisted', $this->status_of( $second ) );

		// A member leaves — free the slot, then advance.
		get_user_by( 'id', $artist )->remove_role( 'agnosis_artist' );
		$advanced = ( new CommunityCap() )->advance_waitlist();

		$this->assertSame( $first, $advanced );
		$this->assertSame( 'pending', $this->status_of( $first ) );      // oldest advanced
		$this->assertSame( 'waitlisted', $this->status_of( $second ) );  // still waiting
	}

	public function test_advance_waitlist_noop_when_still_full(): void {
		$this->create_artist();
		update_option( 'agnosis_community_max_artists', 1 );
		$app = (int) $this->apply( 'wl@example.com' )->get_data()['application_id'];

		// No slot freed — advance must do nothing.
		$this->assertSame( 0, ( new CommunityCap() )->advance_waitlist() );
		$this->assertSame( 'waitlisted', $this->status_of( $app ) );
	}

	public function test_advanced_application_with_enough_vouches_admits_immediately(): void {
		update_option( 'agnosis_community_max_artists', 3 );
		$a1 = $this->create_artist();
		$a2 = $this->create_artist();
		$app_id = (int) $this->apply( 'loop@example.com' )->get_data()['application_id'];

		$a3 = $this->create_artist(); // full at 3

		$admission = new Admission();
		$admission->record_vote( $a1, $app_id, 'yes' );
		$admission->record_vote( $a2, $app_id, 'yes' ); // parked as waitlisted (full), keeps its vouches
		$this->assertSame( 'waitlisted', $this->status_of( $app_id ) );

		// A3 leaves → slot opens → advance fires agnosis_waitlist_advanced →
		// Admission::reconsider() re-runs maybe_admit() → vouches met + capacity → admitted.
		get_user_by( 'id', $a3 )->remove_role( 'agnosis_artist' );
		( new CommunityCap() )->advance_waitlist();

		$this->assertSame( 'admitted', $this->status_of( $app_id ) );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', 'loop@example.com' ) );
	}

	// -------------------------------------------------------------------------
	// Admin override
	// -------------------------------------------------------------------------

	public function test_admin_admit_allowed_over_cap_for_waitlisted(): void {
		$this->create_artist();
		update_option( 'agnosis_community_max_artists', 1 ); // full

		$app_id = (int) $this->apply( 'vip@example.com' )->get_data()['application_id'];
		$this->assertSame( 'waitlisted', $this->status_of( $app_id ) );

		$ok = ( new Admission() )->admin_admit( $app_id );

		$this->assertTrue( $ok );
		$this->assertSame( 'admitted', $this->status_of( $app_id ) );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', 'vip@example.com' ) );
	}
}
