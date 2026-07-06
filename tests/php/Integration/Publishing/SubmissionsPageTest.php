<?php
/**
 * Integration tests — SubmissionsPage's logged-out login form.
 *
 * First test coverage for this class; scoped to the "Forgot your password?"
 * link added alongside Core\LoginBranding (see that class's own tests) rather
 * than backfilling full coverage of the rest of SubmissionsPage in the same
 * pass. Before this fix, wp_login_form() (used here so artists never have to
 * visit wp-login.php directly) rendered with no password-recovery path at
 * all — a core WP omission for that function, unlike the full wp-login.php
 * page — and this was the only login surface anywhere in the plugin or theme.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\SubmissionsPage;

class SubmissionsPageTest extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 ); // Logged out — render_login_form() is only reached in this state.
	}

	public function test_logged_out_view_includes_a_lost_password_link(): void {
		$page = new SubmissionsPage();
		$html = $page->render_shortcode();

		$this->assertStringContainsString( 'Forgot your password?', $html );

		$expected_url = wp_lostpassword_url( home_url( '/my-submissions/' ) );
		$this->assertStringContainsString( esc_url( $expected_url ), $html );
	}

	public function test_logged_out_view_still_renders_the_login_form(): void {
		$page = new SubmissionsPage();
		$html = $page->render_shortcode();

		// The lost-password link is additive — it must not have replaced or
		// broken the actual login form itself.
		$this->assertStringContainsString( 'id="loginform"', $html );
		$this->assertStringContainsString( 'name="log"', $html );
		$this->assertStringContainsString( 'name="pwd"', $html );
	}
}
