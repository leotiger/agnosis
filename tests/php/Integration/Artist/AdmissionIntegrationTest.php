<?php
/**
 * Integration tests — artist admission / vouching system.
 *
 * Tests the full apply → vouch → admit flow using WordPress REST API
 * dispatching (no real HTTP — rest_do_request() runs handlers in-process).
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

class AdmissionIntegrationTest extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Routes are registered by the plugin on rest_api_init during WP boot.
		// Calling register_routes() directly here would trigger a _doing_it_wrong notice.

		// Require 2 vouches by default.
		update_option( 'agnosis_vouches_required', 2 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Create a user and grant them the agnosis_artist role. */
	private function create_artist(): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_user_by( 'id', $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	/** Create a plain subscriber (applicant). */
	private function create_applicant(): int {
		return self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	private function rest_post( string $route, array $params = [], ?int $user_id = null ): \WP_REST_Response|\WP_Error {
		if ( $user_id ) {
			wp_set_current_user( $user_id );
		}
		$request = new \WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function rest_get( string $route, ?int $user_id = null ): \WP_REST_Response|\WP_Error {
		if ( $user_id ) {
			wp_set_current_user( $user_id );
		}
		return rest_do_request( new \WP_REST_Request( 'GET', $route ) );
	}

	// -------------------------------------------------------------------------
	// Apply endpoint
	// -------------------------------------------------------------------------

	public function test_apply_returns_401_when_not_logged_in(): void {
		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply' );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_apply_sets_user_meta_on_success(): void {
		$applicant = $this->create_applicant();

		$this->rest_post( '/agnosis/v1/admission/apply', [ 'bio' => 'I paint seascapes.' ], $applicant );

		$this->assertNotEmpty( get_user_meta( $applicant, '_agnosis_applied', true ) );
	}

	public function test_apply_stores_bio(): void {
		$applicant = $this->create_applicant();

		$this->rest_post( '/agnosis/v1/admission/apply', [ 'bio' => 'Watercolour since 2005.' ], $applicant );

		$this->assertSame( 'Watercolour since 2005.', get_user_meta( $applicant, '_agnosis_application_bio', true ) );
	}

	public function test_apply_returns_201_with_vouch_counts(): void {
		$applicant = $this->create_applicant();

		$response = $this->rest_post( '/agnosis/v1/admission/apply', [], $applicant );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'applied', $data['status'] );
		$this->assertSame( 0, $data['vouches_received'] );
		$this->assertSame( 2, $data['vouches_required'] );
	}

	public function test_apply_returns_already_artist_when_admitted(): void {
		$artist   = $this->create_artist();
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [], $artist );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'already_artist', $data['status'] );
	}

	// -------------------------------------------------------------------------
	// Vouch endpoint
	// -------------------------------------------------------------------------

	public function test_vouch_returns_403_when_voucher_is_not_artist(): void {
		$non_artist = $this->create_applicant();
		$applicant  = $this->create_applicant();

		$response = $this->rest_post( "/agnosis/v1/admission/vouch/$applicant", [], $non_artist );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_vouch_returns_400_for_self_vouch(): void {
		$artist = $this->create_artist();
		// Artist must have applied first.
		update_user_meta( $artist, '_agnosis_applied', current_time( 'mysql' ) );

		$response = $this->rest_post( "/agnosis/v1/admission/vouch/$artist", [], $artist );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_vouch_returns_404_when_candidate_has_not_applied(): void {
		$artist    = $this->create_artist();
		$applicant = $this->create_applicant(); // no application meta

		$response = $this->rest_post( "/agnosis/v1/admission/vouch/$applicant", [], $artist );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_vouch_returns_201_on_success(): void {
		$artist    = $this->create_artist();
		$applicant = $this->create_applicant();
		update_user_meta( $applicant, '_agnosis_applied', current_time( 'mysql' ) );

		$response = $this->rest_post( "/agnosis/v1/admission/vouch/$applicant", [ 'message' => 'Great work!' ], $artist );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'vouched', $data['status'] );
		$this->assertSame( 1, $data['vouches_received'] );
	}

	// -------------------------------------------------------------------------
	// Status endpoint
	// -------------------------------------------------------------------------

	public function test_status_endpoint_requires_auth(): void {
		$applicant = $this->create_applicant();
		wp_set_current_user( 0 ); // not logged in

		$response = $this->rest_get( "/agnosis/v1/admission/status/$applicant" );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_status_reflects_applied_state(): void {
		$applicant = $this->create_applicant();
		update_user_meta( $applicant, '_agnosis_applied', current_time( 'mysql' ) );

		// Must be logged in as the applicant to read own status.
		$response = $this->rest_get( "/agnosis/v1/admission/status/$applicant", $applicant );
		$data     = $response->get_data();

		$this->assertTrue( $data['has_applied'] );
		$this->assertFalse( $data['is_artist'] );
		$this->assertSame( 0, $data['vouches_received'] );
	}

	// -------------------------------------------------------------------------
	// Full admission flow
	// -------------------------------------------------------------------------

	public function test_applicant_is_admitted_after_required_vouches(): void {
		$artist1   = $this->create_artist();
		$artist2   = $this->create_artist();
		$applicant = $this->create_applicant();

		// Step 1 — apply.
		$this->rest_post( '/agnosis/v1/admission/apply', [], $applicant );
		$this->assertFalse( user_can( $applicant, 'agnosis_artist' ) );

		// Step 2 — first vouch (not enough yet).
		$this->rest_post( "/agnosis/v1/admission/vouch/$applicant", [], $artist1 );
		$this->assertFalse( user_can( $applicant, 'agnosis_artist' ) );

		// Step 3 — second vouch (threshold reached → admitted).
		$this->rest_post( "/agnosis/v1/admission/vouch/$applicant", [], $artist2 );
		$this->assertTrue( user_can( $applicant, 'agnosis_artist' ), 'Applicant should be admitted after 2 vouches.' );
	}

	public function test_admitted_at_meta_is_set_on_admission(): void {
		$artist1   = $this->create_artist();
		$artist2   = $this->create_artist();
		$applicant = $this->create_applicant();

		$this->rest_post( '/agnosis/v1/admission/apply', [], $applicant );
		$this->rest_post( "/agnosis/v1/admission/vouch/$applicant", [], $artist1 );
		$this->rest_post( "/agnosis/v1/admission/vouch/$applicant", [], $artist2 );

		$this->assertNotEmpty( get_user_meta( $applicant, '_agnosis_admitted_at', true ) );
	}

	public function test_vouch_threshold_is_configurable(): void {
		update_option( 'agnosis_vouches_required', 1 ); // only 1 vouch needed

		$artist    = $this->create_artist();
		$applicant = $this->create_applicant();

		$this->rest_post( '/agnosis/v1/admission/apply', [], $applicant );
		$this->rest_post( "/agnosis/v1/admission/vouch/$applicant", [], $artist );

		$this->assertTrue( user_can( $applicant, 'agnosis_artist' ), 'Should be admitted with just 1 vouch when threshold is 1.' );
	}
}
