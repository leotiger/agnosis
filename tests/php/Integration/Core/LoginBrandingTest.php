<?php
/**
 * Integration tests — Core\LoginBranding.
 *
 * Covers the wp-login.php branding added alongside the "Forgot your
 * password?" fix (SubmissionsPageTest covers that link itself): the logo
 * link/text filters, and enqueue_styles()'s two branches (site-name text
 * fallback vs. a configured email logo image).
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\LoginBranding;

class LoginBrandingTest extends \WP_UnitTestCase {

	private LoginBranding $branding;

	public function setUp(): void {
		parent::setUp();
		$this->branding = new LoginBranding();
	}

	public function tearDown(): void {
		delete_option( 'agnosis_email_logo_id' );
		parent::tearDown();
	}

	public function test_header_url_points_home_regardless_of_input(): void {
		$this->assertSame( home_url( '/' ), $this->branding->header_url( 'https://wordpress.org/' ) );
	}

	public function test_header_text_is_the_site_name(): void {
		$this->assertSame( get_bloginfo( 'name' ), $this->branding->header_text( 'Powered by WordPress' ) );
	}

	public function test_enqueue_styles_uses_text_fallback_when_no_logo_configured(): void {
		ob_start();
		$this->branding->enqueue_styles();
		$css = (string) ob_get_clean();

		$this->assertStringContainsString( '<style>', $css );
		$this->assertStringContainsString( 'background-image: none', $css, 'No logo configured — the WP logo image must be hidden in favour of text.' );
		$this->assertStringNotContainsString( 'background-image: url(', $css );
	}

	public function test_enqueue_styles_uses_the_configured_email_logo(): void {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);
		update_option( 'agnosis_email_logo_id', $attachment_id );

		ob_start();
		$this->branding->enqueue_styles();
		$css = (string) ob_get_clean();

		$src = wp_get_attachment_image_src( $attachment_id, 'medium' );
		$this->assertNotFalse( $src );
		$this->assertStringContainsString( 'background-image: url(' . $src[0] . ')', $css );
	}
}
