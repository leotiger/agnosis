<?php
/**
 * Integration tests — Admin\LanguageAwareTermsListTable, the WP_Terms_List_Table
 * subclass that renders the language-filter dropdown + "Sync all translations"
 * button above the Tags/Mediums admin screens (0.9.38/0.9.39), shipped with
 * zero PHPUnit coverage (audit §2i, AUDIT-0.9.38.md).
 *
 * extra_tablenav() is protected — exercised via ReflectionMethod, the same
 * pattern SettingsTermTranslationCacheTest already uses for a private method
 * on a different class.
 *
 * WP_Screen/WP_List_Table/WP_Terms_List_Table live in wp-admin/includes/,
 * not loaded by the default front-end test bootstrap — required explicitly
 * below, guarded by class_exists() so this stays safe regardless of what
 * else in the suite may have already loaded them.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\LanguageAwareTermsListTable;
use Agnosis\Artist\Profile;
use Agnosis\Tests\Integration\Compat\LinguaForgeCompatTest;

require_once __DIR__ . '/../Compat/Stubs/lf_global_stubs.php';

class LanguageAwareTermsListTableTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			( new Profile() )->register_taxonomy();
		}

		if ( ! class_exists( \WP_Screen::class ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		if ( ! class_exists( \WP_List_Table::class ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		if ( ! class_exists( \WP_Terms_List_Table::class ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-terms-list-table.php';
		}
	}

	protected function tearDown(): void {
		LinguaForgeCompatTest::$lf_languages = null;
		delete_option( 'linguaforge_primary_language' );
		parent::tearDown();
	}

	private function list_table( string $taxonomy = 'post_tag' ): LanguageAwareTermsListTable {
		return new LanguageAwareTermsListTable( [ 'screen' => \WP_Screen::get( 'edit-' . $taxonomy ) ] );
	}

	private function render( LanguageAwareTermsListTable $table, string $which = 'top' ): string {
		$method = new \ReflectionMethod( LanguageAwareTermsListTable::class, 'extra_tablenav' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $table, $which );
		return (string) ob_get_clean();
	}

	public function test_renders_nothing_for_the_bottom_tablenav(): void {
		LinguaForgeCompatTest::$lf_languages = [ 'en', 'de' ];
		update_option( 'linguaforge_primary_language', 'en' );

		$html = $this->render( $this->list_table(), 'bottom' );

		$this->assertSame( '', $html );
	}

	public function test_renders_nothing_when_no_language_besides_primary_is_configured(): void {
		LinguaForgeCompatTest::$lf_languages = [ 'en' ];
		update_option( 'linguaforge_primary_language', 'en' );

		$html = $this->render( $this->list_table(), 'top' );

		$this->assertSame( '', $html );
	}

	public function test_renders_the_language_dropdown_with_the_configured_target_languages(): void {
		LinguaForgeCompatTest::$lf_languages = [ 'en', 'de' ];
		update_option( 'linguaforge_primary_language', 'en' );

		$html = $this->render( $this->list_table(), 'top' );

		$this->assertStringContainsString( 'agnosis-admin-lang', $html );
		$this->assertStringContainsString( 'value="de"', $html );
		$this->assertStringNotContainsString( 'value="en"', $html, 'The primary language itself must not be offered as a filter target.' );
	}

	public function test_marks_the_currently_selected_language_as_selected(): void {
		LinguaForgeCompatTest::$lf_languages = [ 'en', 'de' ];
		update_option( 'linguaforge_primary_language', 'en' );
		$_GET['agnosis_admin_lang'] = 'de'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$html = $this->render( $this->list_table(), 'top' );

		$this->assertMatchesRegularExpression( '/value="de"[^>]*selected/', $html );

		unset( $_GET['agnosis_admin_lang'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function test_renders_the_sync_all_button_for_a_capable_user(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		LinguaForgeCompatTest::$lf_languages = [ 'en', 'de' ];
		update_option( 'linguaforge_primary_language', 'en' );

		$html = $this->render( $this->list_table(), 'top' );

		$this->assertStringContainsString( 'agnosis_sync_all_terms', $html );
		$this->assertStringContainsString( 'Sync all translations', $html );
	}

	public function test_omits_the_sync_all_button_for_an_unauthorized_user(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		LinguaForgeCompatTest::$lf_languages = [ 'en', 'de' ];
		update_option( 'linguaforge_primary_language', 'en' );

		$html = $this->render( $this->list_table(), 'top' );

		$this->assertStringNotContainsString( 'agnosis_sync_all_terms', $html );
	}
}
