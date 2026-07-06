<?php
/**
 * Integration tests — Settings' newsletter-dashboard locale-coverage metric
 * (audit §8 — "cheap signal for which LF languages earn their AI translation
 * spend"). locale_label()/format_locale_breakdown() are private, so they're
 * exercised via ReflectionMethod, the same pattern VouchConfirmTest uses for
 * VouchConfirm::verify_token().
 *
 * The real Lingua Forge plugin is deliberately not loaded in this test
 * bootstrap (see Support\FakeLinguaForge's doc), and linguaforge_language_label()
 * isn't among the handful of linguaforge_*() functions stubbed for tests
 * either — so linguaforge_language_label() is never defined here, and
 * locale_label() always takes its guarded fallback branch (the raw locale
 * code) in this suite. That fallback, and the '' -> "Unknown" bucket, are
 * exactly what's covered below; the "LF active" branch is only exercised on
 * a real site with Lingua Forge installed.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\Settings;
use Agnosis\Newsletter\Subscriber;

class SettingsNewsletterLocaleTest extends \WP_UnitTestCase {

	private Settings $settings;
	private \ReflectionMethod $locale_label;
	private \ReflectionMethod $format_locale_breakdown;

	protected function setUp(): void {
		parent::setUp();

		$this->settings = new Settings();

		$rc = new \ReflectionClass( Settings::class );

		$this->locale_label = $rc->getMethod( 'locale_label' );
		$this->locale_label->setAccessible( true );

		$this->format_locale_breakdown = $rc->getMethod( 'format_locale_breakdown' );
		$this->format_locale_breakdown->setAccessible( true );
	}

	// =========================================================================
	// locale_label()
	// =========================================================================

	public function test_locale_label_returns_unknown_for_empty_locale(): void {
		$label = $this->locale_label->invoke( $this->settings, '' );

		$this->assertSame( 'Unknown', $label );
	}

	public function test_locale_label_falls_back_to_raw_locale_when_lf_absent(): void {
		// See class doc — linguaforge_language_label() is never defined in this
		// test bootstrap, so the guarded fallback branch is what's reachable here.
		$label = $this->locale_label->invoke( $this->settings, 'es_ES' );

		$this->assertSame( 'es_ES', $label );
	}

	// =========================================================================
	// format_locale_breakdown()
	// =========================================================================

	public function test_format_locale_breakdown_is_empty_with_no_confirmed_subscribers(): void {
		$breakdown = $this->format_locale_breakdown->invoke( $this->settings );

		$this->assertSame( '', $breakdown );
	}

	public function test_format_locale_breakdown_lists_each_locale_with_its_count(): void {
		$a = Subscriber::subscribe( 'a@example.com', 'es_ES' );
		Subscriber::confirm( $a['token'] );
		$b = Subscriber::subscribe( 'b@example.com', 'es_ES' );
		Subscriber::confirm( $b['token'] );
		$c = Subscriber::subscribe( 'c@example.com', 'fr_FR' );
		Subscriber::confirm( $c['token'] );

		$breakdown = $this->format_locale_breakdown->invoke( $this->settings );

		$this->assertStringContainsString( 'es_ES (2)', $breakdown );
		$this->assertStringContainsString( 'fr_FR (1)', $breakdown );
	}

	public function test_format_locale_breakdown_labels_missing_locale_as_unknown(): void {
		$a = Subscriber::subscribe( 'a@example.com' ); // no locale
		Subscriber::confirm( $a['token'] );

		$breakdown = $this->format_locale_breakdown->invoke( $this->settings );

		$this->assertStringContainsString( 'Unknown (1)', $breakdown );
	}
}
