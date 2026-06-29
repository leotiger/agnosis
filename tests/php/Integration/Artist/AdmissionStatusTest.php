<?php
/**
 * Integration tests — admission status endpoint access control.
 *
 * GET /agnosis/v1/admission/status/{application_id} is admin-only.
 * The endpoint returns the full application record including membership status.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

class AdmissionStatusTest extends \WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_admin(): int {
		return self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function create_subscriber(): int {
		return self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	/** Insert an application row directly and return its ID. */
	private function insert_application( string $email, string $status = 'pending' ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => $email,
				'display_name' => 'Status Test Artist',
				'status'       => $status,
			],
			[ '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	private function rest_get_status( int $application_id, ?int $as_user_id = null ): \WP_REST_Response|\WP_Error {
		wp_set_current_user( $as_user_id ?? 0 );
		return rest_do_request( new \WP_REST_Request( 'GET', "/agnosis/v1/admission/status/{$application_id}" ) );
	}

	// -------------------------------------------------------------------------
	// Authentication gate
	// -------------------------------------------------------------------------

	public function test_anonymous_request_returns_403(): void {
		$application_id = $this->insert_application( 'anon@example.com' );

		$response = $this->rest_get_status( $application_id );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_non_admin_logged_in_returns_403(): void {
		$subscriber     = $this->create_subscriber();
		$application_id = $this->insert_application( 'sub@example.com' );

		$response = $this->rest_get_status( $application_id, $subscriber );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Admin access
	// -------------------------------------------------------------------------

	public function test_admin_can_access_status(): void {
		$admin          = $this->create_admin();
		$application_id = $this->insert_application( 'admin@example.com' );

		$response = $this->rest_get_status( $application_id, $admin );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_status_response_contains_expected_fields(): void {
		$admin          = $this->create_admin();
		$application_id = $this->insert_application( 'fields@example.com' );

		update_option( 'agnosis_admission_minimum', 3 );
		update_option( 'agnosis_admission_percent', 0 );

		$response = $this->rest_get_status( $application_id, $admin );
		$data     = $response->get_data();

		$this->assertSame( $application_id, $data['id'] );
		$this->assertSame( 'fields@example.com', $data['email'] );
		$this->assertSame( 'Status Test Artist', $data['display_name'] );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertNull( $data['wp_user_id'] );
		$this->assertSame( 0, $data['vouches_received'] );
		$this->assertSame( 3, $data['vouches_required'] );
		$this->assertArrayHasKey( 'applied_at', $data );
		$this->assertArrayHasKey( 'resolved_at', $data );
	}

	public function test_status_returns_404_for_unknown_application(): void {
		$admin = $this->create_admin();

		$response = $this->rest_get_status( 99999, $admin );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_status_reflects_admitted_state(): void {
		$admin          = $this->create_admin();
		$application_id = $this->insert_application( 'admitted@example.com', 'admitted' );

		$response = $this->rest_get_status( $application_id, $admin );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'admitted', $data['status'] );
	}
}
