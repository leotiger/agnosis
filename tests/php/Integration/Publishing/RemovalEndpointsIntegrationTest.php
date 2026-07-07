<?php
/**
 * Integration tests for Publishing\RemovalEndpoints.
 *
 * RemovalEndpoints is the token-only REST endpoint artists use to confirm
 * takedown requests they initiated via a remove@ email.  Tests cover:
 *
 *   • Token auth: valid token trashes the post; wrong token is rejected;
 *     expired token is rejected; token is deleted after use.
 *   • Idempotency: already-trashed post returns success without re-trashing.
 *   • Guard: non-existent post returns 404; wrong post type returns 404.
 *   • Permission gate: confirm() is unreachable without a non-empty token.
 *   • Translation cascade: when Lingua Forge is active,
 *     linguaforge_trash_translation_group() is called (not a bare
 *     wp_trash_post()) with check_caps left at its trusted-caller default of
 *     false; a cascade that reports nothing trashed surfaces as a 500,
 *     matching the pre-cascade wp_trash_post()-returned-false behaviour.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\RemovalEndpoints;

// LF's linguaforge_trash_translation_group() global stub lives in a separate
// file to satisfy Universal.Files.SeparateFunctionsFromOO (no function
// declarations alongside OO) — same convention as
// tests/php/Integration/Compat/Stubs/lf_global_stubs.php.
require_once __DIR__ . '/Stubs/lf_trash_cascade_stub.php';

class RemovalEndpointsIntegrationTest extends \WP_UnitTestCase {

	private RemovalEndpoints $endpoints;

	/** @var int Artist WP user ID */
	private int $artist_id;

	/** @var int Published artwork post ID */
	private int $post_id;

	private const VALID_TOKEN = 'removal-integ-token-abc123456789';

	// ── linguaforge_trash_translation_group() stub state (reset in setUp) ──────

	/** @var array<int, array{post_id:int, check_caps:bool}> */
	public static array $trash_cascade_calls = [];

	/** @var array{trashed:int,skipped:int}|null Non-null forces the stub's return value instead of actually trashing. */
	public static ?array $trash_cascade_return_override = null;

	// -------------------------------------------------------------------------
	// Set-up
	// -------------------------------------------------------------------------

	protected function setUp(): void {
		parent::setUp();

		self::$trash_cascade_calls           = [];
		self::$trash_cascade_return_override = null;

		$this->endpoints = new RemovalEndpoints();
		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->post_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_title'  => 'Artwork To Remove',
			'post_author' => $this->artist_id,
		] );

		update_post_meta( $this->post_id, '_agnosis_removal_token',  self::VALID_TOKEN );
		update_post_meta( $this->post_id, '_agnosis_removal_expiry', time() + 86400 * 7 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function request( array $params ): \WP_REST_Request {
		$req = new \WP_REST_Request();
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	// -------------------------------------------------------------------------
	// confirm() — happy path
	// -------------------------------------------------------------------------

	public function test_confirm_with_valid_token_trashes_post(): void {
		$req    = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->confirm( $req );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result->get_data()['removed'] );
		$this->assertSame( 'trash', get_post_status( $this->post_id ) );
	}

	public function test_confirm_deletes_token_after_use(): void {
		$req = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$this->endpoints->confirm( $req );

		$this->assertEmpty( get_post_meta( $this->post_id, '_agnosis_removal_token', true ) );
	}

	public function test_confirm_deletes_expiry_after_use(): void {
		$req = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$this->endpoints->confirm( $req );

		$this->assertEmpty( get_post_meta( $this->post_id, '_agnosis_removal_expiry', true ) );
	}

	// -------------------------------------------------------------------------
	// confirm() — translation cascade (Lingua Forge active)
	// -------------------------------------------------------------------------

	/**
	 * Without this, only the primary-language post was ever trashed — every
	 * translation linked into its TRID group stayed live and publicly
	 * visible after an artist's removal request. Asserts the endpoint calls
	 * the LF cascade (not a bare wp_trash_post()) with check_caps left at
	 * its trusted-caller default of false — this is a token-authenticated
	 * REST request that frequently has no logged-in WP session at all for
	 * current_user_can() to check against, same convention already used by
	 * linguaforge_trigger_translation()/linguaforge_queue_translation().
	 */
	public function test_confirm_calls_lf_trash_cascade_with_check_caps_false(): void {
		$req    = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->confirm( $req );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, self::$trash_cascade_calls );
		$this->assertSame( $this->post_id, self::$trash_cascade_calls[0]['post_id'] );
		$this->assertFalse( self::$trash_cascade_calls[0]['check_caps'] );
		$this->assertSame( 'trash', get_post_status( $this->post_id ) );
	}

	/**
	 * A cascade that reports nothing trashed (e.g. every post in the group
	 * was skipped) must surface the same 500 a failed bare wp_trash_post()
	 * always did — not a false "removed" success.
	 */
	public function test_confirm_returns_500_when_cascade_trashes_nothing(): void {
		self::$trash_cascade_return_override = [ 'trashed' => 0, 'skipped' => 1 ];

		$req    = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->confirm( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_removal_failed', $result->get_error_code() );
		$this->assertSame( [ 'status' => 500 ], $result->get_error_data() );
	}

	// -------------------------------------------------------------------------
	// confirm() — idempotency
	// -------------------------------------------------------------------------

	public function test_confirm_on_already_trashed_post_returns_success(): void {
		wp_trash_post( $this->post_id );

		$req    = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->confirm( $req );

		// Already trashed → idempotent success, no 4xx.
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result->get_data()['removed'] );
	}

	// -------------------------------------------------------------------------
	// confirm() — invalid / expired token
	// -------------------------------------------------------------------------

	public function test_confirm_with_wrong_token_returns_403(): void {
		$req    = $this->request( [ 'id' => $this->post_id, 'token' => 'wrong-token' ] );
		$result = $this->endpoints->confirm( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_removal_invalid_token', $result->get_error_code() );
		// Post must be untouched.
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );
	}

	public function test_confirm_with_expired_token_returns_410(): void {
		update_post_meta( $this->post_id, '_agnosis_removal_expiry', time() - 1 );

		$req    = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->confirm( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_removal_token_expired', $result->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );
	}

	public function test_confirm_expired_token_cleans_up_meta(): void {
		update_post_meta( $this->post_id, '_agnosis_removal_expiry', time() - 1 );

		$req = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$this->endpoints->confirm( $req );

		// verify_token() deletes stale meta on expiry.
		$this->assertEmpty( get_post_meta( $this->post_id, '_agnosis_removal_token', true ) );
		$this->assertEmpty( get_post_meta( $this->post_id, '_agnosis_removal_expiry', true ) );
	}

	public function test_confirm_with_no_stored_token_returns_400(): void {
		delete_post_meta( $this->post_id, '_agnosis_removal_token' );

		$req    = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->confirm( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_removal_no_token', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// confirm() — post guard
	// -------------------------------------------------------------------------

	public function test_confirm_nonexistent_post_returns_404(): void {
		$req    = $this->request( [ 'id' => 99999, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->confirm( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_removal_not_found', $result->get_error_code() );
	}

	public function test_confirm_non_removable_post_type_returns_404(): void {
		// 'page' is never removable via this endpoint — only agnosis_artwork
		// and agnosis_event (REMOVABLE_TYPES) are, since 2026-07-06.
		$page_id = (int) self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_post_meta( $page_id, '_agnosis_removal_token',  self::VALID_TOKEN );
		update_post_meta( $page_id, '_agnosis_removal_expiry', time() + 86400 );

		$req    = $this->request( [ 'id' => $page_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->confirm( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_removal_not_found', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// confirm() — agnosis_event (2026-07-06: remove@ is no longer artwork-only)
	// -------------------------------------------------------------------------

	public function test_confirm_with_valid_token_trashes_event(): void {
		$event_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_title'  => 'Event To Remove',
			'post_author' => $this->artist_id,
		] );
		update_post_meta( $event_id, '_agnosis_removal_token',  self::VALID_TOKEN );
		update_post_meta( $event_id, '_agnosis_removal_expiry', time() + 86400 * 7 );

		$req    = $this->request( [ 'id' => $event_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->confirm( $req );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result->get_data()['removed'] );
		$this->assertSame( 'trash', get_post_status( $event_id ) );
		$this->assertStringContainsString( 'event', $result->get_data()['message'] );
	}

	// -------------------------------------------------------------------------
	// check_permission()
	// -------------------------------------------------------------------------

	public function test_check_permission_returns_true_with_token(): void {
		$req    = $this->request( [ 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->check_permission( $req );

		$this->assertTrue( $result );
	}

	public function test_check_permission_returns_401_without_token(): void {
		$req    = $this->request( [] );
		$result = $this->endpoints->check_permission( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_auth_required', $result->get_error_code() );
	}

	public function test_check_permission_returns_401_with_empty_token(): void {
		$req    = $this->request( [ 'token' => '' ] );
		$result = $this->endpoints->check_permission( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_auth_required', $result->get_error_code() );
	}

	public function test_check_permission_returns_401_with_whitespace_only_token(): void {
		$req    = $this->request( [ 'token' => '   ' ] );
		$result = $this->endpoints->check_permission( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_auth_required', $result->get_error_code() );
	}
}
