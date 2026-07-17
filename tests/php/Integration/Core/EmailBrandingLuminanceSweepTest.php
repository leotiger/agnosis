<?php
/**
 * Integration tests — Core\EmailBranding's WCAG relative-luminance switch
 * (header_text_color() / header_subtitle_color()), added in 0.9.29 to fix
 * invisible white-on-white header text against a light configured "Header
 * background" (see EmailBranding's own class docblock and
 * header_text_color()'s docblock, audit AUDIT-0.9.29.md §2b).
 *
 * Closes deferred-test debt flagged by AUDIT-1.0.0.md §4d: this switch had
 * been hand-verified only (a person visually checking a couple of colors),
 * with nothing asserting it in CI. This sweeps a representative range of
 * background colors — including the exact grayscale value pair the
 * luminance formula's own 0.5 threshold falls between (#bbbbbb / #bcbcbc,
 * independently computed, not guessed) — across both call sites: the
 * public header_subtitle_color() directly, and the private
 * header_text_color() indirectly through header_html()'s wordmark-fallback
 * markup (the no-logo branch, so header_html() embeds header_text_color()'s
 * exact value in a `color:` style attribute).
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\EmailBranding;

class EmailBrandingLuminanceSweepTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		// The no-logo branch is the one that embeds header_text_color()'s
		// value directly — force it regardless of test-run ordering/state.
		delete_option( 'agnosis_email_logo_id' );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_email_header_bg' );
		delete_option( 'agnosis_email_logo_id' );
		parent::tearDown();
	}

	/**
	 * Each row: [configured header background, expected wordmark text color,
	 * expected subtitle color]. Luminance values independently computed from
	 * the exact WCAG relative-luminance formula header_text_color() itself
	 * uses (sRGB gamma-decode + 0.2126/0.7152/0.0722 channel weights), not
	 * eyeballed — see this test's class docblock.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function backgroundColorProvider(): array {
		return [
			'pure black (luminance 0.000000, the darkest possible)'                          => [ '#000000', '#fff', '#ece9ff' ],
			'pure white (luminance 1.000000, the lightest possible)'                         => [ '#ffffff', '#0d0d12', '#4a4a56' ],
			'plugin default header background #0d0d12 (luminance 0.004171 — dark)'           => [ '#0d0d12', '#fff', '#ece9ff' ],
			'default accent purple #7c6af7, used as a background here (luminance 0.213086)'  => [ '#7c6af7', '#fff', '#ece9ff' ],
			'light letterbox default #f5f5f5 (luminance 0.913099 — light)'                   => [ '#f5f5f5', '#0d0d12', '#4a4a56' ],
			'dark navy #1a1a2e (luminance 0.011557 — dark)'                                  => [ '#1a1a2e', '#fff', '#ece9ff' ],
			'just below the 0.5 threshold: #bbbbbb (luminance 0.496933 — still reads dark)'  => [ '#bbbbbb', '#fff', '#ece9ff' ],
			'just above the 0.5 threshold: #bcbcbc (luminance 0.502886 — now reads light)'   => [ '#bcbcbc', '#0d0d12', '#4a4a56' ],
		];
	}

	/**
	 * @dataProvider backgroundColorProvider
	 */
	public function test_header_subtitle_color_follows_luminance_threshold( string $background, string $expected_text_color, string $expected_subtitle_color ): void {
		update_option( 'agnosis_email_header_bg', $background );

		$this->assertSame( $expected_subtitle_color, EmailBranding::header_subtitle_color() );
	}

	/**
	 * @dataProvider backgroundColorProvider
	 */
	public function test_header_html_wordmark_uses_the_luminance_derived_text_color( string $background, string $expected_text_color, string $expected_subtitle_color ): void {
		update_option( 'agnosis_email_header_bg', $background );

		$html = EmailBranding::header_html();

		$this->assertStringContainsString( 'color:' . $expected_text_color . ';', $html, "Background {$background} must render wordmark text color {$expected_text_color}." );
	}

	// =========================================================================
	// Malformed-input fallback (header_text_color()'s own documented default)
	// =========================================================================

	public function test_malformed_header_background_falls_back_to_white_text(): void {
		// EmailTemplate::header_bg() already re-validates with
		// sanitize_hex_color() and falls back to its own #0d0d12 default
		// before header_text_color() ever sees the value, so this can't
		// reach header_text_color()'s own inner ctype_xdigit() defense
		// directly (its docblock notes that inner check "shouldn't happen"
		// for exactly this reason) — but it does confirm the full pipeline,
		// end to end, still lands on the safe white-text default rather than
		// erroring or guessing when given a genuinely malformed stored value.
		update_option( 'agnosis_email_header_bg', 'not-a-real-color' );

		$this->assertStringContainsString( 'color:#fff;', EmailBranding::header_html() );
		$this->assertSame( '#ece9ff', EmailBranding::header_subtitle_color() );
	}
}
