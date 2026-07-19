<?php
/**
 * Integration tests — Admin\TaxonomyLanguageFilter, the Tags/Mediums
 * language-filter dropdown and both on-demand sync actions added for the
 * taxonomy redesign (0.9.38/0.9.39), shipped with zero PHPUnit coverage
 * (audit §2i, AUDIT-0.9.38.md).
 *
 * Priority order per the audit's own §2i note ("if time is short"): the §2b
 * collision path (LinguaForgeTermSyncTest.php), then scope_by_language()
 * (the one filter standing between translated terms and every admin term
 * query), then the handlers' redirect/count contract — this file covers the
 * latter two plus the smaller supporting methods on the same class.
 *
 * wp_safe_redirect()/wp_die() both call exit — intercepted via the same
 * RedirectCapture/DieCapture pattern SettingsTermTranslationCacheTest and
 * ReviewConfirmIntegrationTest already established.
 *
 * LINGUAFORGE_FILE/LINGUAFORGE_VERSION + linguaforge_languages() come from
 * the shared stub file Compat/Stubs/lf_global_stubs.php (guarded
 * function_exists()/defined() everywhere, so requiring it again here is
 * safe and makes this file self-contained regardless of load order).
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\TaxonomyLanguageFilter;
use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;
use Agnosis\Tests\Integration\Compat\LinguaForgeCompatTest;
use Agnosis\Tests\Integration\Support\DieCapture;
use Agnosis\Tests\Integration\Support\RedirectCapture;

require_once __DIR__ . '/../Compat/Stubs/lf_global_stubs.php';

class TaxonomyLanguageFilterTest extends \WP_UnitTestCase {

	private TaxonomyLanguageFilter $filter;

	protected function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			( new Profile() )->register_taxonomy();
		}

		$this->filter = new TaxonomyLanguageFilter();

		LinguaForgeCompatTest::$lf_languages = [ 'en', 'de' ];
		update_option( 'linguaforge_primary_language', 'en' );

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
		add_filter( 'wp_die_handler', $die_interceptor );
		add_filter( 'wp_die_ajax_handler', $die_interceptor );
	}

	protected function tearDown(): void {
		LinguaForgeCompatTest::$lf_languages = null;
		delete_option( 'linguaforge_primary_language' );
		delete_option( 'agnosis_term_translations' );
		unset( $_GET['term_id'], $_GET['taxonomy'], $_GET['agnosis_admin_lang'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_REQUEST['_wpnonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		parent::tearDown();
	}

	private function insert_term( string $name, string $taxonomy = 'post_tag' ): int {
		$term = wp_insert_term( $name, $taxonomy );
		$this->assertIsArray( $term, "Fixture setup failed to insert term '$name'." );
		return (int) $term['term_id'];
	}

	// -------------------------------------------------------------------------
	// scope_by_language() — the filter standing between translated terms and
	// every admin term query (audit §2i's second priority).
	// -------------------------------------------------------------------------

	public function test_scope_by_language_ignores_taxonomies_outside_its_target_set(): void {
		$args = $this->filter->scope_by_language( [ 'foo' => 'bar' ], [ 'category' ] );

		$this->assertSame( [ 'foo' => 'bar' ], $args, 'Untargeted taxonomies must pass through untouched.' );
	}

	public function test_scope_by_language_defaults_to_primary_vocabulary(): void {
		unset( $_GET['agnosis_admin_lang'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = $this->filter->scope_by_language( [], [ 'post_tag' ] );

		$this->assertSame(
			[ [ 'key' => LinguaForge::TRANSLATED_TERM_META, 'compare' => 'NOT EXISTS' ] ],
			$args['meta_query']
		);
	}

	public function test_scope_by_language_scopes_to_the_selected_language(): void {
		$_GET['agnosis_admin_lang'] = 'de'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = $this->filter->scope_by_language( [], [ 'agnosis_medium' ] );

		$this->assertSame(
			[ [ 'key' => LinguaForge::TRANSLATED_TERM_META, 'value' => 'de' ] ],
			$args['meta_query']
		);
	}

	public function test_scope_by_language_merges_with_an_existing_meta_query(): void {
		unset( $_GET['agnosis_admin_lang'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = $this->filter->scope_by_language(
			[ 'meta_query' => [ [ 'key' => 'unrelated', 'value' => '1' ] ] ],
			[ 'post_tag' ]
		);

		$this->assertCount( 2, $args['meta_query'], 'The pre-existing meta_query clause must be preserved, not overwritten.' );
		$this->assertSame( 'unrelated', $args['meta_query'][0]['key'] );
		$this->assertSame( LinguaForge::TRANSLATED_TERM_META, $args['meta_query'][1]['key'] );
	}

	// -------------------------------------------------------------------------
	// maybe_swap_list_table_class()
	// -------------------------------------------------------------------------

	private function screen_for( string $taxonomy ): \WP_Screen {
		if ( ! class_exists( \WP_Screen::class ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		return \WP_Screen::get( 'edit-' . $taxonomy );
	}

	public function test_maybe_swap_list_table_class_swaps_for_a_target_taxonomy(): void {
		$result = $this->filter->maybe_swap_list_table_class(
			'WP_Terms_List_Table',
			[ 'screen' => $this->screen_for( 'post_tag' ) ]
		);

		$this->assertSame( \Agnosis\Admin\LanguageAwareTermsListTable::class, $result );
	}

	public function test_maybe_swap_list_table_class_ignores_a_non_target_taxonomy(): void {
		$result = $this->filter->maybe_swap_list_table_class(
			'WP_Terms_List_Table',
			[ 'screen' => $this->screen_for( 'category' ) ]
		);

		$this->assertSame( 'WP_Terms_List_Table', $result );
	}

	public function test_maybe_swap_list_table_class_ignores_a_different_list_table_class(): void {
		$result = $this->filter->maybe_swap_list_table_class(
			'WP_Posts_List_Table',
			[ 'screen' => $this->screen_for( 'post_tag' ) ]
		);

		$this->assertSame( 'WP_Posts_List_Table', $result );
	}

	// -------------------------------------------------------------------------
	// add_sync_row_action()
	// -------------------------------------------------------------------------

	public function test_add_sync_row_action_adds_the_action_for_a_capable_user_on_a_primary_term(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$term_id = $this->insert_term( 'Landscape' );
		$term    = get_term( $term_id, 'post_tag' );

		$actions = $this->filter->add_sync_row_action( [], $term );

		$this->assertArrayHasKey( 'agnosis-sync', $actions );
		$this->assertStringContainsString( 'agnosis_sync_term', $actions['agnosis-sync'] );
		$this->assertStringContainsString( (string) $term_id, $actions['agnosis-sync'] );
	}

	public function test_add_sync_row_action_omits_the_action_on_an_already_translated_term(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$term_id = $this->insert_term( 'Paysage' );
		add_term_meta( $term_id, LinguaForge::TRANSLATED_TERM_META, 'fr', true );
		$term = get_term( $term_id, 'post_tag' );

		$actions = $this->filter->add_sync_row_action( [], $term );

		$this->assertArrayNotHasKey( 'agnosis-sync', $actions );
	}

	public function test_add_sync_row_action_omits_the_action_for_an_unauthorized_user(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$term_id = $this->insert_term( 'Landscape' );
		$term    = get_term( $term_id, 'post_tag' );

		$actions = $this->filter->add_sync_row_action( [], $term );

		$this->assertArrayNotHasKey( 'agnosis-sync', $actions );
	}

	// -------------------------------------------------------------------------
	// sync_all_url()
	// -------------------------------------------------------------------------

	public function test_sync_all_url_carries_the_taxonomy_and_a_valid_nonce(): void {
		$url = TaxonomyLanguageFilter::sync_all_url( 'agnosis_medium' );

		$this->assertStringContainsString( 'action=agnosis_sync_all_terms', $url );
		$this->assertStringContainsString( 'taxonomy=agnosis_medium', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}

	// -------------------------------------------------------------------------
	// handle_sync_term()
	// -------------------------------------------------------------------------

	public function test_handle_sync_term_rejects_an_invalid_nonce(): void {
		$_GET['term_id']     = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = 'not-a-valid-nonce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->filter->handle_sync_term();
			$this->fail( 'Expected wp_die() for an invalid nonce.' );
		} catch ( DieCapture $e ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_handle_sync_term_rejects_users_without_manage_categories(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$term_id              = $this->insert_term( 'Landscape' );
		$_GET['term_id']      = (string) $term_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_sync_term_' . $term_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->filter->handle_sync_term();
			$this->fail( 'Expected wp_die() for a user without manage_categories.' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}
	}

	public function test_handle_sync_term_rejects_an_invalid_taxonomy(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$term_id              = $this->insert_term( 'Landscape' );
		$_GET['term_id']      = (string) $term_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['taxonomy']     = 'category'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_sync_term_' . $term_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->filter->handle_sync_term();
			$this->fail( 'Expected wp_die() for a non-target taxonomy.' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Invalid taxonomy', $e->body );
		}
	}

	public function test_handle_sync_term_syncs_and_redirects_with_counts(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$term_id = $this->insert_term( 'Landscape' );
		$cache   = [ 'post_tag' => [ 'Landscape' => [ 'de' => 'Landschaft' ] ] ];
		update_option( 'agnosis_term_translations', $cache );

		$_GET['term_id']      = (string) $term_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['taxonomy']     = 'post_tag'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_sync_term_' . $term_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->filter->handle_sync_term();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_sync_created=1', $e->url );
			$this->assertStringContainsString( 'agnosis_sync_skipped=0', $e->url );
			$this->assertStringContainsString( 'agnosis_sync_failed=0', $e->url );
		}
	}

	// -------------------------------------------------------------------------
	// handle_sync_all_terms()
	// -------------------------------------------------------------------------

	public function test_handle_sync_all_terms_rejects_users_without_manage_categories(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_GET['taxonomy']      = 'post_tag'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_sync_all_terms_post_tag' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->filter->handle_sync_all_terms();
			$this->fail( 'Expected wp_die() for a user without manage_categories.' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}
	}

	public function test_handle_sync_all_terms_syncs_and_redirects_with_aggregate_counts(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->insert_term( 'Landscape' );
		update_option( 'agnosis_term_translations', [ 'post_tag' => [ 'Landscape' => [ 'de' => 'Landschaft' ] ] ] );

		$_GET['taxonomy']      = 'post_tag'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_sync_all_terms_post_tag' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->filter->handle_sync_all_terms();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_sync_all_terms=1', $e->url );
			$this->assertStringContainsString( 'agnosis_sync_all_total=1', $e->url );
			$this->assertStringContainsString( 'agnosis_sync_all_created=1', $e->url );
			$this->assertStringContainsString( 'agnosis_sync_all_timed_out=0', $e->url );
		}
	}

	// -------------------------------------------------------------------------
	// maybe_render_sync_notice()
	// -------------------------------------------------------------------------

	private function render_notice(): string {
		ob_start();
		$this->filter->maybe_render_sync_notice();
		return (string) ob_get_clean();
	}

	public function test_notice_renders_nothing_without_relevant_query_args(): void {
		$this->assertSame( '', $this->render_notice() );
	}

	public function test_notice_is_a_success_for_a_clean_per_term_sync(): void {
		$_GET['agnosis_sync_created'] = '2'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_skipped'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_failed']  = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$html = $this->render_notice();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( '2', $html );

		unset( $_GET['agnosis_sync_created'], $_GET['agnosis_sync_skipped'], $_GET['agnosis_sync_failed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function test_notice_is_a_warning_when_a_per_term_sync_has_failures(): void {
		$_GET['agnosis_sync_created'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_skipped'] = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_failed']  = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$html = $this->render_notice();

		$this->assertStringContainsString( 'notice-warning', $html );

		unset( $_GET['agnosis_sync_created'], $_GET['agnosis_sync_skipped'], $_GET['agnosis_sync_failed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function test_notice_is_a_warning_when_sync_all_timed_out(): void {
		$_GET['agnosis_sync_all_terms']     = '3'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_all_total']     = '10'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_all_created']   = '3'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_all_skipped']   = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_all_failed']    = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_all_timed_out'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$html = $this->render_notice();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( '3', $html );
		$this->assertStringContainsString( '10', $html );

		unset( // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_GET['agnosis_sync_all_terms'],
			$_GET['agnosis_sync_all_total'],
			$_GET['agnosis_sync_all_created'],
			$_GET['agnosis_sync_all_skipped'],
			$_GET['agnosis_sync_all_failed'],
			$_GET['agnosis_sync_all_timed_out']
		);
	}

	public function test_notice_is_a_success_for_a_complete_sync_all_with_no_failures(): void {
		$_GET['agnosis_sync_all_terms']     = '5'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_all_total']     = '5'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_all_created']   = '4'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_all_skipped']   = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_all_failed']    = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_sync_all_timed_out'] = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$html = $this->render_notice();

		$this->assertStringContainsString( 'notice-success', $html );

		unset( // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_GET['agnosis_sync_all_terms'],
			$_GET['agnosis_sync_all_total'],
			$_GET['agnosis_sync_all_created'],
			$_GET['agnosis_sync_all_skipped'],
			$_GET['agnosis_sync_all_failed'],
			$_GET['agnosis_sync_all_timed_out']
		);
	}
}
