<?php
/**
 * Integration tests — admission status endpoint access control.
 *
 * Verifies that GET /agnosis/v1/admission/status/{id} is:
 *   - Blocked for anonymous requests (401)
 *   - Blocked when a logged-in user requests another user's status (403)
 *   - Allowed when a user requests their own status (200)
 *   - Allowed for admins requesting any user's status (200)
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

class AdmissionStatusTest extends \WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_artist(): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_user_by( 'id', $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	private function create_subscriber(): int {
		return self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	private function create_admin(): int {
		return self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function rest_get_status( int $target_id, ?int $as_user_id = null ): \WP_REST_Response|\WP_Error {
		wp_set_current_user( $as_user_id ?? 0 );
		return rest_do_request( new \WP_REST_Request( 'GET', "/agnosis/v1/admission/status/$target_id" ) );
	}

	// -------------------------------------------------------------------------
	// Authentication gate (permission_callback)
	// -------------------------------------------------------------------------

	public function test_anonymous_request_returns_401(): void {
		$applicant = $this->create_subscriber();

		$response = $this->rest_get_status( $applicant );

		$this->assertSame( 401, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Ownership guard (inside callback)
	// -------------------------------------------------------------------------

	public function test_logged_in_user_cannot_see_another_users_status(): void {
		$viewer    = $this->create_subscriber();
		$applicant = $this->create_subscriber();

		$response = $this->rest_get_status( $applicant, $viewer );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_logged_in_user_can_see_own_status(): void {
		$applicant = $this->create_subscriber();
		update_user_meta( $applicant, '_agnosis_applied', current_time( 'mysql' ) );

		$response = $this->rest_get_status( $applicant, $applicant );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_own_status_response_contains_expected_fields(): void {
		$applicant = $this->create_subscriber();
		update_user_meta( $applicant, '_agnosis_applied', current_time( 'mysql' ) );
		update_option( 'agnosis_vouches_required', 3 );

		$response = $this->rest_get_status( $applicant, $applicant );
		$data     = $response->get_data();

		$this->assertSame( $applicant, $data['user_id'] );
		$this->assertFalse( $data['is_artist'] );
		$this->assertTrue( $data['has_applied'] );
		$this->assertSame( 0, $data['vouches_received'] );
		$this->assertSame( 3, $data['vouches_required'] );
	}

	// -------------------------------------------------------------------------
	// Admin access
	// -------------------------------------------------------------------------

	public function test_admin_can_see_any_users_status(): void {
		$admin     = $this->create_admin();
		$applicant = $this->create_subscriber();

		$response = $this->rest_get_status( $applicant, $admin );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_admin_response_reflects_correct_user(): void {
		$admin  = $this->create_admin();
		$artist = $this->create_artist();

		$response = $this->rest_get_status( $artist, $admin );
		$data     = $response->get_data();

		$this->assertSame( $artist, $data['user_id'] );
		$this->assertTrue( $data['is_artist'] );
	}

	// -------------------------------------------------------------------------
	// Artist viewing own status
	// -------------------------------------------------------------------------

	public function test_artist_can_see_own_status(): void {
		$artist = $this->create_artist();

		$response = $this->rest_get_status( $artist, $artist );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['is_artist'] );
	}
}
