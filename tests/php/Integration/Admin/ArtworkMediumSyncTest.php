<?php
/**
 * Integration tests — Admin\ArtworkMediumSync, the on-demand "push this
 * artwork's medium to its translated siblings" meta box + bulk action added
 * for the taxonomy redesign (0.9.38/0.9.39), shipped with zero PHPUnit
 * coverage (audit §2i, AUDIT-0.9.38.md).
 *
 * wp_safe_redirect()/wp_die() both call exit — intercepted via the same
 * RedirectCapture/DieCapture pattern SettingsTermTranslationCacheTest and
 * TaxonomyLanguageFilterTest already use.
 *
 * LINGUAFORGE_FILE/LINGUAFORGE_VERSION come from the shared stub file
 * Compat/Stubs/lf_global_stubs.php, which — once required anywhere in this
 * PHPUnit process — leaves LinguaForge::is_active() permanently true for
 * the rest of the run (PHP constants cannot be undefined). render_meta_box()'s
 * "Requires Lingua Forge." branch is therefore not independently testable
 * here; this is the same accepted limitation LinguaForgeCompatNoticesTest.php
 * already documents for is_active()'s inverse case.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\ArtworkMediumSync;
use Agnosis\Tests\Integration\Support\DieCapture;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;
use Agnosis\Tests\Integration\Support\RedirectCapture;

require_once __DIR__ . '/../Compat/Stubs/lf_global_stubs.php';

class ArtworkMediumSyncTest extends \WP_UnitTestCase {

	private ArtworkMediumSync $sync;

	protected function setUp(): void {
		parent::setUp();

		$this->sync = new ArtworkMediumSync();
		FakeLinguaForge::reset();

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
		FakeLinguaForge::reset();
		delete_option( 'linguaforge_primary_language' );
		unset( $_GET['post_id'], $_GET['agnosis_medium_synced'], $GLOBALS['pagenow'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_REQUEST['_wpnonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		parent::tearDown();
	}

	private function artwork( string $lang ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		update_post_meta( $id, '_lf_lang', $lang );
		return $id;
	}

	// -------------------------------------------------------------------------
	// render_meta_box()
	// -------------------------------------------------------------------------

	private function render_meta_box( \WP_Post $post ): string {
		ob_start();
		$this->sync->render_meta_box( $post );
		return (string) ob_get_clean();
	}

	public function test_meta_box_offers_nothing_to_sync_from_a_translated_post(): void {
		update_option( 'linguaforge_primary_language', 'en' );
		$post_id = $this->artwork( 'de' ); // Not the primary language.

		$html = $this->render_meta_box( get_post( $post_id ) );

		$this->assertStringContainsString( 'nothing to sync from here', $html );
		$this->assertStringNotContainsString( 'agnosis_sync_medium_assignment', $html );
	}

	public function test_meta_box_offers_the_sync_action_on_a_primary_language_post(): void {
		update_option( 'linguaforge_primary_language', 'en' );
		$post_id = $this->artwork( 'en' );

		$html = $this->render_meta_box( get_post( $post_id ) );

		$this->assertStringContainsString( 'agnosis_sync_medium_assignment', $html );
		$this->assertStringContainsString( (string) $post_id, $html );
	}

	// -------------------------------------------------------------------------
	// handle_sync()
	// -------------------------------------------------------------------------

	public function test_handle_sync_rejects_an_invalid_nonce(): void {
		$_GET['post_id']      = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = 'not-a-valid-nonce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->sync->handle_sync();
			$this->fail( 'Expected wp_die() for an invalid nonce.' );
		} catch ( DieCapture $e ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_handle_sync_rejects_a_user_without_edit_post(): void {
		update_option( 'linguaforge_primary_language', 'en' );
		$post_id = $this->artwork( 'en' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_GET['post_id']      = (string) $post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_sync_medium_assignment_' . $post_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->sync->handle_sync();
			$this->fail( 'Expected wp_die() for a user without edit_post capability.' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}
	}

	public function test_handle_sync_pushes_the_medium_to_translated_siblings_and_redirects(): void {
		update_option( 'linguaforge_primary_language', 'en' );
		$post_id    = $this->artwork( 'en' );
		$sibling_id = $this->artwork( 'de' );
		FakeLinguaForge::link( $post_id, 'de', $sibling_id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_GET['post_id']      = (string) $post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_sync_medium_assignment_' . $post_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->sync->handle_sync();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_medium_synced=1', $e->url );
		}
	}

	// -------------------------------------------------------------------------
	// maybe_render_single_notice()
	// -------------------------------------------------------------------------

	private function render_single_notice(): string {
		ob_start();
		$this->sync->maybe_render_single_notice();
		return (string) ob_get_clean();
	}

	public function test_single_notice_is_silent_off_the_post_edit_screen(): void {
		$GLOBALS['pagenow']            = 'edit.php';
		$_GET['agnosis_medium_synced'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->assertSame( '', $this->render_single_notice() );
	}

	public function test_single_notice_reports_how_many_siblings_were_synced(): void {
		$GLOBALS['pagenow']            = 'post.php';
		$_GET['agnosis_medium_synced'] = '2'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$html = $this->render_single_notice();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( '2', $html );
	}

	// -------------------------------------------------------------------------
	// render_bulk_sync_button()
	// -------------------------------------------------------------------------

	private function render_bulk_button( string $post_type ): string {
		ob_start();
		$this->sync->render_bulk_sync_button( $post_type );
		return (string) ob_get_clean();
	}

	public function test_bulk_button_is_omitted_for_a_different_post_type(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( '', $this->render_bulk_button( 'post' ) );
	}

	public function test_bulk_button_is_omitted_for_an_unauthorized_user(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$this->assertSame( '', $this->render_bulk_button( 'agnosis_artwork' ) );
	}

	public function test_bulk_button_renders_for_an_authorized_user_on_the_artwork_screen(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->render_bulk_button( 'agnosis_artwork' );

		$this->assertStringContainsString( 'agnosis_sync_all_medium_assignments', $html );
	}

	// -------------------------------------------------------------------------
	// handle_sync_all()
	// -------------------------------------------------------------------------

	public function test_handle_sync_all_rejects_a_user_without_edit_others_posts(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_sync_all_medium_assignments' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->sync->handle_sync_all();
			$this->fail( 'Expected wp_die() for a user without edit_others_posts.' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}
	}

	public function test_handle_sync_all_pushes_every_primary_artworks_medium_and_redirects(): void {
		update_option( 'linguaforge_primary_language', 'en' );
		$post_id    = $this->artwork( 'en' );
		$sibling_id = $this->artwork( 'de' );
		FakeLinguaForge::link( $post_id, 'de', $sibling_id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_sync_all_medium_assignments' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->sync->handle_sync_all();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_medium_sync_all_artworks=1', $e->url );
			$this->assertStringContainsString( 'agnosis_medium_sync_all_synced=1', $e->url );
		}
	}

	// -------------------------------------------------------------------------
	// maybe_render_bulk_notice()
	// -------------------------------------------------------------------------

	public function test_bulk_notice_is_silent_without_both_query_args(): void {
		$_GET['agnosis_medium_sync_all_artworks'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// agnosis_medium_sync_all_synced deliberately not set.

		ob_start();
		$this->sync->maybe_render_bulk_notice();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );

		unset( $_GET['agnosis_medium_sync_all_artworks'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function test_bulk_notice_reports_artworks_processed_and_siblings_synced(): void {
		$_GET['agnosis_medium_sync_all_artworks'] = '3'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_medium_sync_all_synced']   = '5'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		$this->sync->maybe_render_bulk_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( '3', $html );
		$this->assertStringContainsString( '5', $html );

		unset( $_GET['agnosis_medium_sync_all_artworks'], $_GET['agnosis_medium_sync_all_synced'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
