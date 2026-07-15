<?php
/**
 * Integration tests — Core\EmailTemplate, the shared branded HTML email
 * shell every notification class was converted onto in this pass (audit-
 * adjacent finding, not a numbered audit item — see CHANGELOG.md 0.9.29).
 *
 * Coverage:
 *   - header_bg()/accent() default to the plugin's original hardcoded
 *     colours (#0d0d12 / #7c6af7) when unset.
 *   - header_bg()/accent() honour a configured Settings value.
 *   - header_bg()/accent() fall back to the default when the stored value
 *     isn't a real hex colour (defends against a pre-validation stored
 *     value or a direct option write bypassing Settings' own sanitize
 *     callback).
 *   - render() produces a full HTML document containing the configured
 *     header colour, the site's tagline footer, the caller's body HTML,
 *     and optional extra footer HTML.
 *   - button() renders an accent-coloured link by default, and honours
 *     style overrides (e.g. the DANGER colour, a custom border).
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\EmailTemplate;

class EmailTemplateTest extends \WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( 'agnosis_email_header_bg' );
		delete_option( 'agnosis_email_accent' );
		parent::tearDown();
	}

	// =========================================================================
	// header_bg() / accent()
	// =========================================================================

	public function test_header_bg_defaults_to_original_hardcoded_colour(): void {
		delete_option( 'agnosis_email_header_bg' );
		$this->assertSame( '#0d0d12', EmailTemplate::header_bg() );
	}

	public function test_accent_defaults_to_original_hardcoded_colour(): void {
		delete_option( 'agnosis_email_accent' );
		$this->assertSame( '#7c6af7', EmailTemplate::accent() );
	}

	public function test_header_bg_honours_configured_value(): void {
		update_option( 'agnosis_email_header_bg', '#123456' );
		$this->assertSame( '#123456', EmailTemplate::header_bg() );
	}

	public function test_accent_honours_configured_value(): void {
		update_option( 'agnosis_email_accent', '#abcdef' );
		$this->assertSame( '#abcdef', EmailTemplate::accent() );
	}

	public function test_header_bg_falls_back_to_default_when_stored_value_is_not_a_real_hex_colour(): void {
		update_option( 'agnosis_email_header_bg', 'not-a-colour' );
		$this->assertSame( '#0d0d12', EmailTemplate::header_bg() );
	}

	public function test_accent_falls_back_to_default_when_stored_value_is_not_a_real_hex_colour(): void {
		update_option( 'agnosis_email_accent', 'javascript:alert(1)' );
		$this->assertSame( '#7c6af7', EmailTemplate::accent() );
	}

	// =========================================================================
	// render()
	// =========================================================================

	public function test_render_produces_a_full_html_document(): void {
		$html = EmailTemplate::render( 'en', '<p>Body content</p>' );

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( '<html lang="en">', $html );
		$this->assertStringContainsString( '<p>Body content</p>', $html );
	}

	public function test_render_uses_the_configured_header_background(): void {
		update_option( 'agnosis_email_header_bg', '#ff00ff' );

		$html = EmailTemplate::render( 'en', '<p>Body</p>' );

		$this->assertStringContainsString( '#ff00ff', $html );
		$this->assertStringNotContainsString( '#0d0d12', $html, 'The original hardcoded header colour must not appear once a custom one is configured.' );
	}

	public function test_render_includes_the_site_tagline_in_the_footer(): void {
		$html = EmailTemplate::render( 'en', '<p>Body</p>' );

		$this->assertStringContainsString( get_bloginfo( 'name' ), $html );
		$this->assertStringContainsString( 'art blooming out of oblivion', $html );
	}

	public function test_render_includes_extra_footer_html_when_provided(): void {
		$html = EmailTemplate::render( 'en', '<p>Body</p>', '<p>Extra footer content</p>' );

		$this->assertStringContainsString( 'Extra footer content', $html );
	}

	public function test_render_omits_extra_footer_html_when_not_provided(): void {
		$html = EmailTemplate::render( 'en', '<p>Body</p>' );

		// The default (empty) third argument must not leave any stray markup.
		$this->assertStringNotContainsString( 'Extra footer content', $html );
	}

	// =========================================================================
	// button()
	// =========================================================================

	public function test_button_defaults_to_the_configured_accent_colour(): void {
		update_option( 'agnosis_email_accent', '#333333' );

		$html = EmailTemplate::button( 'https://example.com/confirm', 'Confirm' );

		$this->assertStringContainsString( 'href="https://example.com/confirm"', $html );
		$this->assertStringContainsString( 'Confirm', $html );
		$this->assertStringContainsString( 'background:#333333', $html );
	}

	public function test_button_honours_style_overrides(): void {
		$html = EmailTemplate::button( 'https://example.com/remove', 'Remove', [
			'bg'     => EmailTemplate::DANGER,
			'color'  => '#fff',
			'border' => EmailTemplate::DANGER,
		] );

		$this->assertStringContainsString( 'background:' . EmailTemplate::DANGER, $html );
		$this->assertStringContainsString( 'border:1px solid ' . EmailTemplate::DANGER, $html );
	}

	public function test_button_escapes_the_label(): void {
		$html = EmailTemplate::button( 'https://example.com', '<script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}
}
