<?php
/**
 * Verifies that check_permission() is honoured by WordPress's REST dispatch.
 *
 * The existing ReviewEndpoints and RemovalEndpoints tests call the callback
 * methods directly (approve(), reject(), confirm()).  Direct calls bypass WP's
 * permission callback dispatch, so they cannot prove that the permission gate
 * fires when a real REST request arrives.
 *
 * These tests use rest_do_request() — the same code path ReviewConfirm uses
 * internally — to exercise the full dispatch cycle:
 *
 *   register_routes() → WP matches path → check_permission() → callback()
 *
 * A tokenless request must be blocked before the callback has a chance to run.
 * For review routes (token not a declared arg) the permission callback fires
 * first → 401.  For the removal route (token IS a required arg) WP's arg
 * validation fires before the permission callback → 400.  Either way the
 * callback is unreachable without a valid token.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\RemovalEndpoints;
use Agnosis\Publishing\ReviewEndpoints;

class PermissionGateIntegrationTest extends \WP_UnitTestCase {

	private int $artist_id;
	private int $post_id;

	private const REVIEW_TOKEN  = 'gate-test-review-token-abc123';
	private const REMOVAL_TOKEN = 'gate-test-removal-token-xyz987';

	// -------------------------------------------------------------------------
	// Set-up
	// -------------------------------------------------------------------------

	protected function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		// Reinitialise the REST server and register routes via rest_api_init.
		// Calling register_route() outside that action triggers a _doing_it_wrong
		// notice that fails the WP_UnitTestCase incorrect-usage assertion.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		add_action( 'rest_api_init', [ new ReviewEndpoints(), 'register_routes' ] );
		add_action( 'rest_api_init', [ new RemovalEndpoints(), 'register_routes' ] );
		do_action( 'rest_api_init', $wp_rest_server );

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->post_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'draft',
			'post_title'  => 'Permission Gate Test Artwork',
			'post_author' => $this->artist_id,
		] );

		update_post_meta( $this->post_id, '_agnosis_review_token',  self::REVIEW_TOKEN );
		update_post_meta( $this->post_id, '_agnosis_review_expiry', time() + 86400 * 7 );

		update_post_meta( $this->post_id, '_agnosis_removal_token',  self::REMOVAL_TOKEN );
		update_post_meta( $this->post_id, '_agnosis_removal_expiry', time() + 86400 * 7 );
	}

	// -------------------------------------------------------------------------
	// ReviewEndpoints — approve
	// -------------------------------------------------------------------------

	public function test_approve_without_token_and_no_auth_returns_401_via_rest_dispatch(): void {
		$req = new \WP_REST_Request( 'POST', '/agnosis/v1/review/' . $this->post_id . '/approve' );
		// No token, no logged-in user.

		$response = rest_do_request( $req );

		$this->assertSame( 401, $response->get_status() );
		// Post must not have been touched.
		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
	}

	public function test_approve_with_valid_token_passes_permission_gate_via_rest_dispatch(): void {
		$req = new \WP_REST_Request( 'POST', '/agnosis/v1/review/' . $this->post_id . '/approve' );
		$req->set_param( 'token', self::REVIEW_TOKEN );

		$response = rest_do_request( $req );

		// Permission gate must pass; callback runs and publishes the post.
		$this->assertNotSame( 401, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );
	}

	public function test_approve_with_logged_in_author_passes_permission_gate_via_rest_dispatch(): void {
		wp_set_current_user( $this->artist_id );

		$req = new \WP_REST_Request( 'POST', '/agnosis/v1/review/' . $this->post_id . '/approve' );
		// No token — relying on WP auth path.

		$response = rest_do_request( $req );

		$this->assertNotSame( 401, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );

		wp_set_current_user( 0 );
	}

	// -------------------------------------------------------------------------
	// ReviewEndpoints — reject
	// -------------------------------------------------------------------------

	public function test_reject_without_token_and_no_auth_returns_401_via_rest_dispatch(): void {
		$req = new \WP_REST_Request( 'POST', '/agnosis/v1/review/' . $this->post_id . '/reject' );

		$response = rest_do_request( $req );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
	}

	public function test_reject_with_valid_token_passes_permission_gate_via_rest_dispatch(): void {
		$req = new \WP_REST_Request( 'POST', '/agnosis/v1/review/' . $this->post_id . '/reject' );
		$req->set_param( 'token', self::REVIEW_TOKEN );

		$response = rest_do_request( $req );

		$this->assertNotSame( 401, $response->get_status() );
		$this->assertSame( 'trash', get_post_status( $this->post_id ) );
	}

	// -------------------------------------------------------------------------
	// RemovalEndpoints — confirm
	// -------------------------------------------------------------------------

	public function test_removal_confirm_without_token_is_blocked_via_rest_dispatch(): void {
		// Publish the post first — removal acts on published artworks.
		wp_update_post( [ 'ID' => $this->post_id, 'post_status' => 'publish' ] );

		$req = new \WP_REST_Request( 'POST', '/agnosis/v1/removal/' . $this->post_id . '/confirm' );
		// No token set.

		$response = rest_do_request( $req );

		// 'token' is a required arg on the removal route, so WP's arg validation
		// fires before check_permission() and returns 400 (not 401).  Either way
		// the confirm() callback is unreachable.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );
	}

	public function test_removal_confirm_with_valid_token_passes_permission_gate_via_rest_dispatch(): void {
		wp_update_post( [ 'ID' => $this->post_id, 'post_status' => 'publish' ] );

		$req = new \WP_REST_Request( 'POST', '/agnosis/v1/removal/' . $this->post_id . '/confirm' );
		$req->set_param( 'token', self::REMOVAL_TOKEN );

		$response = rest_do_request( $req );

		$this->assertNotSame( 401, $response->get_status() );
		$this->assertSame( 'trash', get_post_status( $this->post_id ) );
	}
}
