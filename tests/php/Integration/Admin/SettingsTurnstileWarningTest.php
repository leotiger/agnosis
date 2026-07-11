<?php
/**
 * Integration tests — the "Bot Protection" warning card on Settings →
 * General (security/ops audit §4a, layer (2) of that finding's fix shape).
 *
 * The join application form (and newsletter signup) are always publicly
 * reachable by design — there is no "close the join page" mode in this
 * plugin — so before this, an operator who never set the two Turnstile
 * option fields had no indication anywhere that the form was running on IP
 * rate-limiting alone. render_turnstile_warning() is private, exercised via
 * ReflectionMethod (same pattern SettingsTermTranslationCacheTest and
 * SettingsResettableFieldsTest already use for this class).
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\Settings;

class SettingsTurnstileWarningTest extends \WP_UnitTestCase {

	private Settings $settings;
	private \ReflectionMethod $render_warning;

	protected function setUp(): void {
		parent::setUp();

		$this->settings = new Settings();

		$rc                   = new \ReflectionClass( Settings::class );
		$this->render_warning = $rc->getMethod( 'render_turnstile_warning' );
		$this->render_warning->setAccessible( true );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_turnstile_site_key' );
		delete_option( 'agnosis_turnstile_secret_key' );
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		$this->render_warning->invoke( $this->settings );
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Both keys unset — the default, unconfigured state
	// -------------------------------------------------------------------------

	public function test_shows_warning_when_both_keys_are_unset(): void {
		delete_option( 'agnosis_turnstile_site_key' );
		delete_option( 'agnosis_turnstile_secret_key' );

		$html = $this->render();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'not configured', $html );
		$this->assertStringNotContainsString( 'Turnstile is configured', $html );
	}

	// -------------------------------------------------------------------------
	// Only one of the two keys set — Turnstile::is_enabled() requires both
	// -------------------------------------------------------------------------

	public function test_shows_warning_when_only_site_key_is_set(): void {
		update_option( 'agnosis_turnstile_site_key', 'site-key-only' );
		delete_option( 'agnosis_turnstile_secret_key' );

		$html = $this->render();

		$this->assertStringContainsString( 'notice-warning', $html );
	}

	public function test_shows_warning_when_only_secret_key_is_set(): void {
		delete_option( 'agnosis_turnstile_site_key' );
		update_option( 'agnosis_turnstile_secret_key', 'secret-key-only' );

		$html = $this->render();

		$this->assertStringContainsString( 'notice-warning', $html );
	}

	// -------------------------------------------------------------------------
	// Both keys set — fully configured
	// -------------------------------------------------------------------------

	public function test_shows_confirmation_when_both_keys_are_set(): void {
		update_option( 'agnosis_turnstile_site_key', 'a-real-site-key' );
		update_option( 'agnosis_turnstile_secret_key', 'a-real-secret-key' );

		$html = $this->render();

		$this->assertStringContainsString( 'Turnstile is configured', $html );
		$this->assertStringNotContainsString( 'notice-warning', $html );
	}

	// -------------------------------------------------------------------------
	// Whitespace-only keys must not count as configured — Turnstile::site_key()/
	// secret_key() both trim(), so a key field saved as only spaces is the same
	// as empty.
	// -------------------------------------------------------------------------

	public function test_shows_warning_when_keys_are_whitespace_only(): void {
		update_option( 'agnosis_turnstile_site_key', '   ' );
		update_option( 'agnosis_turnstile_secret_key', '   ' );

		$html = $this->render();

		$this->assertStringContainsString( 'notice-warning', $html );
	}
}
