<?php
/**
 * Integration tests — Admin\ArtworkRetag, the per-artwork "Re-tag" meta box
 * button added in TAG-REDESIGN.md T3(e), mirroring Admin\ArtworkMediumSync's
 * own per-artwork button pattern (admin-post handler, nonce, capability,
 * redirect notice) but wired to `Publishing\Retag::run()` instead of a
 * sibling-assignment push.
 *
 * wp_safe_redirect()/wp_die() both call exit — intercepted via the same
 * RedirectCapture/DieCapture pattern ArtworkMediumSyncTest already uses.
 * The AI call itself is faked via WpAiClientTestRegistry, the same stub
 * Publishing\RetagTest already uses for Retag::run()'s own coverage — this
 * file only exercises the admin-UI layer around that already-tested
 * service, per invariant 8 (Re-tag is not a special path).
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\ArtworkRetag;
use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;
use Agnosis\Tests\Integration\Support\DieCapture;
use Agnosis\Tests\Integration\Support\RedirectCapture;

require_once __DIR__ . '/../AI/Stubs/WpAiClientTestRegistry.php';
require_once __DIR__ . '/../AI/Stubs/wp_ai_provider_namespace_stubs.php';

class ArtworkRetagTest extends \WP_UnitTestCase {

	private int $artist_id;
	private ArtworkRetag $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->controller = new ArtworkRetag();

		update_option( 'agnosis_description_provider', 'wp_ai' );

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
		delete_option( 'agnosis_description_provider' );
		WpAiClientTestRegistry::reset();
		unset( $_GET['post_id'], $_GET['agnosis_retag_success'], $_GET['agnosis_retag_matched'], $_GET['agnosis_retag_proposed'], $_GET['agnosis_retag_gated'], $_GET['agnosis_retag_reason'], $GLOBALS['pagenow'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_REQUEST['_wpnonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false ] ) as $term ) {
			wp_delete_term( $term->term_id, 'post_tag' );
		}
		parent::tearDown();
	}

	private function make_artwork( array $overrides = [] ): int {
		return (int) wp_insert_post( array_merge( [
			'post_type'    => 'agnosis_artwork',
			'post_status'  => 'publish',
			'post_author'  => $this->artist_id,
			'post_title'   => 'Sunset Over the Bay',
			'post_excerpt' => 'A vivid oil painting of a harbor at dusk.',
			'post_content' => '<!-- wp:paragraph --><p>Full body text describing the piece.</p><!-- /wp:paragraph -->',
		], $overrides ) );
	}

	// -------------------------------------------------------------------------
	// render_meta_box()
	// -------------------------------------------------------------------------

	private function render_meta_box( \WP_Post $post ): string {
		ob_start();
		$this->controller->render_meta_box( $post );
		return (string) ob_get_clean();
	}

	public function test_meta_box_offers_nothing_for_a_draft(): void {
		$post_id = $this->make_artwork( [ 'post_status' => 'draft' ] );

		$html = $this->render_meta_box( get_post( $post_id ) );

		$this->assertStringContainsString( 'published', $html );
		$this->assertStringNotContainsString( 'agnosis_retag', $html );
	}

	public function test_meta_box_offers_nothing_for_a_native_language_post(): void {
		$post_id = $this->make_artwork();
		update_post_meta( $post_id, '_agnosis_native_lang', 'es' );

		$html = $this->render_meta_box( get_post( $post_id ) );

		$this->assertStringContainsString( 'nothing to re-tag from here', $html );
		$this->assertStringNotContainsString( 'agnosis_retag', $html );
	}

	public function test_meta_box_offers_the_retag_action_on_a_published_primary_language_post(): void {
		$post_id = $this->make_artwork();

		$html = $this->render_meta_box( get_post( $post_id ) );

		$this->assertStringContainsString( 'agnosis_retag', $html );
		$this->assertStringContainsString( (string) $post_id, $html );
	}

	// -------------------------------------------------------------------------
	// handle_retag()
	// -------------------------------------------------------------------------

	public function test_handle_retag_rejects_an_invalid_nonce(): void {
		$_GET['post_id']      = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = 'not-a-valid-nonce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->controller->handle_retag();
			$this->fail( 'Expected wp_die() for an invalid nonce.' );
		} catch ( DieCapture $e ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_handle_retag_rejects_a_user_without_manage_categories(): void {
		$post_id = $this->make_artwork();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_GET['post_id']      = (string) $post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_retag_' . $post_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->controller->handle_retag();
			$this->fail( 'Expected wp_die() for a user without manage_categories capability.' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}
	}

	public function test_handle_retag_reports_success_counts_and_redirects(): void {
		$existing = wp_insert_term( 'Harbor', 'post_tag' );
		$post_id  = $this->make_artwork();
		WpAiClientTestRegistry::$response = wp_json_encode( [ 'Harbor', 'Sunset' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_GET['post_id']      = (string) $post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_retag_' . $post_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->controller->handle_retag();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_retag_success=1', $e->url );
			$this->assertStringContainsString( 'agnosis_retag_matched=1', $e->url );
			$this->assertStringContainsString( 'agnosis_retag_proposed=1', $e->url );
		}

		$this->assertNotSame( 0, $existing['term_id'] ?? 0 );
	}

	public function test_handle_retag_reports_a_failure_reason_and_redirects(): void {
		$post_id = $this->make_artwork( [ 'post_status' => 'draft' ] ); // Structurally ineligible.

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_GET['post_id']      = (string) $post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_retag_' . $post_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->controller->handle_retag();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_retag_success=0', $e->url );
			$this->assertStringContainsString( 'agnosis_retag_reason=not_published', $e->url );
		}
	}

	// -------------------------------------------------------------------------
	// maybe_render_notice()
	// -------------------------------------------------------------------------

	private function render_notice(): string {
		ob_start();
		$this->controller->maybe_render_notice();
		return (string) ob_get_clean();
	}

	public function test_notice_is_silent_off_the_post_edit_screen(): void {
		$GLOBALS['pagenow']                = 'edit.php';
		$_GET['agnosis_retag_success'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->assertSame( '', $this->render_notice() );
	}

	public function test_notice_reports_success_counts(): void {
		$GLOBALS['pagenow']                 = 'post.php';
		$_GET['agnosis_retag_success']  = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_retag_matched']  = '2'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_retag_proposed'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_retag_gated']    = '3'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$html = $this->render_notice();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( '2', $html );
		$this->assertStringContainsString( '1', $html );
		$this->assertStringContainsString( '3', $html );
	}

	public function test_notice_reports_a_human_readable_failure_reason(): void {
		$GLOBALS['pagenow']               = 'post.php';
		$_GET['agnosis_retag_success'] = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_retag_reason']  = 'not_published'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$html = $this->render_notice();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'not published yet', $html );
	}

	public function test_notice_falls_back_to_a_generic_message_for_an_unknown_reason(): void {
		$GLOBALS['pagenow']               = 'post.php';
		$_GET['agnosis_retag_success'] = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_retag_reason']  = 'something_new_and_unmapped'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$html = $this->render_notice();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'unknown reason', $html );
	}
}
