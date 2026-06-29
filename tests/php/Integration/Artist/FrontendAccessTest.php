<?php
/**
 * Integration tests for FrontendAccess.
 *
 * Verifies that artists are blocked from wp-admin, have the admin bar hidden,
 * and are redirected to the front page after login. Admins with the
 * agnosis_artist capability must not be affected.
 *
 * The admin_init redirect uses exit(), so block_admin_access() cannot be
 * tested end-to-end in PHPUnit. We test the underlying is_frontend_only_artist()
 * logic through the two filter methods that do not exit, and assert the
 * conditions under which the redirect would fire by inspecting what the hook
 * would do.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\FrontendAccess;
use WP_UnitTestCase;

class FrontendAccessTest extends WP_UnitTestCase {

	private FrontendAccess $access;
	private int $artist_id;
	private int $admin_id;
	private int $subscriber_id;

	protected function setUp(): void {
		parent::setUp();

		$this->access = new FrontendAccess();

		// Plain artist.
		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$artist          = get_user_by( 'id', $this->artist_id );
		$artist->add_role( 'agnosis_artist' );

		// Admin who is also an artist — should NOT be restricted.
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$admin          = get_user_by( 'id', $this->admin_id );
		$admin->add_role( 'agnosis_artist' );

		// Plain subscriber — should not be touched.
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// hide_admin_bar()
	// -------------------------------------------------------------------------

	public function test_admin_bar_hidden_for_artist(): void {
		wp_set_current_user( $this->artist_id );

		$result = $this->access->hide_admin_bar( true );

		$this->assertFalse( $result );
	}

	public function test_admin_bar_visible_for_admin_artist(): void {
		wp_set_current_user( $this->admin_id );

		$result = $this->access->hide_admin_bar( true );

		$this->assertTrue( $result );
	}

	public function test_admin_bar_visible_for_subscriber(): void {
		wp_set_current_user( $this->subscriber_id );

		$result = $this->access->hide_admin_bar( true );

		$this->assertTrue( $result );
	}

	public function test_admin_bar_unchanged_when_already_hidden(): void {
		wp_set_current_user( $this->subscriber_id );

		$result = $this->access->hide_admin_bar( false );

		$this->assertFalse( $result );
	}

	public function test_admin_bar_hidden_when_not_logged_in(): void {
		wp_set_current_user( 0 );

		// Logged-out user: show_admin_bar is typically false already —
		// FrontendAccess must not interfere with whatever value is passed.
		$result = $this->access->hide_admin_bar( false );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// redirect_after_login()
	// -------------------------------------------------------------------------

	public function test_artist_redirected_to_home_after_login(): void {
		$user   = get_user_by( 'id', $this->artist_id );
		$result = $this->access->redirect_after_login( admin_url(), '', $user );

		$this->assertSame( home_url( '/' ), $result );
	}

	public function test_admin_artist_redirected_to_dashboard_after_login(): void {
		$user   = get_user_by( 'id', $this->admin_id );
		$result = $this->access->redirect_after_login( admin_url(), '', $user );

		// Admin destination must not be overridden.
		$this->assertSame( admin_url(), $result );
	}

	public function test_subscriber_redirect_unchanged(): void {
		$user   = get_user_by( 'id', $this->subscriber_id );
		$result = $this->access->redirect_after_login( admin_url(), '', $user );

		$this->assertSame( admin_url(), $result );
	}

	public function test_wp_error_redirect_unchanged(): void {
		$error  = new \WP_Error( 'bad_credentials', 'Wrong password.' );
		$result = $this->access->redirect_after_login( admin_url(), '', $error );

		$this->assertSame( admin_url(), $result );
	}

	public function test_artist_redirect_uses_home_url_not_requested(): void {
		// Even if the artist typed a custom redirect_to in the login form,
		// we always send them to home — they should not land in wp-admin.
		$user   = get_user_by( 'id', $this->artist_id );
		$result = $this->access->redirect_after_login( admin_url( 'edit.php' ), admin_url( 'edit.php' ), $user );

		$this->assertSame( home_url( '/' ), $result );
	}

	// -------------------------------------------------------------------------
	// block_admin_access() — test conditions without triggering exit()
	// -------------------------------------------------------------------------

	/**
	 * Confirm that an artist (not admin) satisfies the condition that would
	 * trigger the redirect in block_admin_access(). We cannot call the method
	 * itself because it calls exit(); instead, we verify the underlying
	 * user_can() conditions directly so any future refactor of the method body
	 * keeps the tests meaningful.
	 */
	public function test_artist_would_be_blocked_from_admin(): void {
		$this->assertTrue( user_can( $this->artist_id, 'agnosis_artist' ) );
		$this->assertFalse( user_can( $this->artist_id, 'manage_options' ) );
	}

	public function test_admin_artist_would_not_be_blocked(): void {
		$this->assertTrue( user_can( $this->admin_id, 'agnosis_artist' ) );
		$this->assertTrue( user_can( $this->admin_id, 'manage_options' ) );
	}

	public function test_subscriber_would_not_be_blocked(): void {
		$this->assertFalse( user_can( $this->subscriber_id, 'agnosis_artist' ) );
	}
}
