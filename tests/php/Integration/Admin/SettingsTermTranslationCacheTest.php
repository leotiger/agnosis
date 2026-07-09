<?php
/**
 * Integration tests — Settings' Term Translation Cache panel and its
 * admin-post clear action (fourth audit §4d).
 *
 * Before this fix, `agnosis_term_translations` (Compat\LinguaForge's
 * AI-translated tag/medium term-name cache) had no UI at all: nothing listed
 * it, nothing cleared it manually. render_term_translation_cache_panel() is
 * private, exercised via ReflectionMethod (same pattern
 * SettingsResettableFieldsTest already uses for this class);
 * handle_clear_term_translations_cache() is public and called directly.
 *
 * wp_safe_redirect()/wp_die() both call exit — intercepted via the same
 * RedirectCapture/DieCapture pattern ReviewConfirmIntegrationTest already
 * established, so these tests can assert on the outcome without killing the
 * test process.
 *
 * LINGUAFORGE_FILE/LINGUAFORGE_VERSION are defined below (guarded) so
 * LinguaForge::is_active() reads true for the panel-rendering tests — the
 * same one-directional-per-process constant pattern LinguaForgeCompatTest
 * and JoinPageTest already rely on.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\Settings;
use Agnosis\Compat\LinguaForge;
use Agnosis\Tests\Integration\Support\DieCapture;
use Agnosis\Tests\Integration\Support\RedirectCapture;

if ( ! defined( 'LINGUAFORGE_FILE' ) ) {
	define( 'LINGUAFORGE_FILE', __FILE__ );
}
if ( ! defined( 'LINGUAFORGE_VERSION' ) ) {
	define( 'LINGUAFORGE_VERSION', '1.0.0-test' );
}

class SettingsTermTranslationCacheTest extends \WP_UnitTestCase {

	private Settings $settings;
	private \ReflectionMethod $render_panel;

	protected function setUp(): void {
		parent::setUp();

		$this->settings = new Settings();

		$rc                 = new \ReflectionClass( Settings::class );
		$this->render_panel = $rc->getMethod( 'render_term_translation_cache_panel' );
		$this->render_panel->setAccessible( true );

		add_filter(
			'wp_redirect',
			static function ( string $url, int $status ): never {
				throw new RedirectCapture( $url, $status );
			},
			10,
			2
		);

		$die_interceptor = static function (): callable {
			return static function ( string|\WP_Error $message, string $title = '', array $args = [] ): never {
				$http_status = (int) ( $args['response'] ?? 200 );
				$title_str   = is_string( $title ) ? $title : '';
				$msg_str     = is_string( $message ) ? wp_strip_all_tags( $message ) : (string) $message->get_error_message();
				throw new DieCapture( $msg_str, $title_str, $http_status );
			};
		};
		add_filter( 'wp_die_handler',      $die_interceptor );
		add_filter( 'wp_die_ajax_handler', $die_interceptor );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_term_translations' );
		unset( $_REQUEST['_wpnonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		$this->render_panel->invoke( $this->settings );
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Panel rendering
	// -------------------------------------------------------------------------

	public function test_panel_shows_current_cache_count(): void {
		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => 'Paisaje' ] ],
		] );

		$html = $this->render();

		$this->assertStringContainsString( 'Term Translation Cache', $html );
		$this->assertStringContainsString( '1', $html );
	}

	public function test_panel_disables_clear_button_when_cache_is_empty(): void {
		delete_option( 'agnosis_term_translations' );

		$html = $this->render();

		$this->assertStringContainsString( 'disabled', $html );
	}

	public function test_panel_enables_clear_button_when_cache_has_entries(): void {
		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => 'Paisaje' ] ],
		] );

		$html = $this->render();

		$this->assertStringContainsString( 'agnosis_clear_term_translations_cache', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
	}

	// -------------------------------------------------------------------------
	// handle_clear_term_translations_cache()
	// -------------------------------------------------------------------------

	public function test_handler_rejects_users_without_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_clear_term_translations_cache' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => 'Paisaje' ] ],
		] );

		try {
			$this->settings->handle_clear_term_translations_cache();
			$this->fail( 'Expected wp_die() for a user without manage_options.' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}

		// Cache must be untouched — permission check runs before the clear.
		$this->assertNotSame( [], get_option( 'agnosis_term_translations' ) );
	}

	public function test_handler_clears_the_cache_and_redirects(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_clear_term_translations_cache' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => 'Paisaje' ] ],
		] );

		try {
			$this->settings->handle_clear_term_translations_cache();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'term_cache_cleared=1', $e->url );
		}

		$this->assertFalse( get_option( 'agnosis_term_translations', false ) );
	}

	public function test_handler_rejects_an_invalid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_REQUEST['_wpnonce'] = 'not-a-valid-nonce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->settings->handle_clear_term_translations_cache();
			$this->fail( 'Expected wp_die() for an invalid nonce.' );
		} catch ( DieCapture $e ) {
			$this->addToAssertionCount( 1 ); // check_admin_referer() itself dies here — reached the right place.
		}
	}
}
