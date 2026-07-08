<?php
/**
 * Integration tests — agnosis/join block rendering and its localized config.
 *
 * Focused on the "After applying, send artists to" setting
 * (agnosis_join_success_url): it should reach the frontend via
 * wp_localize_script() as `redirectUrl`, empty by default, resolved to a
 * page's permalink when a page is selected, so blocks/join/frontend.js can
 * redirect a successful applicant there (see JoinPage::success_redirect_url()).
 *
 * 2026-07-08: this setting became a WP page selector — the option now
 * normally holds a page ID rather than a raw URL string. A back-compat test
 * below covers sites that configured a raw URL before this change.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\JoinPage;

class JoinPageTest extends \WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( 'agnosis_join_success_url' );
		wp_deregister_script( 'agnosis-join' );
		parent::tearDown();
	}

	/** Pull the JS-encoded localized data object registered for a script handle. */
	private function localized_data( string $handle ): string {
		$scripts = wp_scripts();
		return (string) ( $scripts->get_data( $handle, 'data' ) ?: '' );
	}

	public function test_redirect_url_is_empty_by_default(): void {
		wp_set_current_user( 0 );

		( new JoinPage() )->render_block();

		$data = $this->localized_data( 'agnosis-join' );
		$this->assertStringContainsString( '"redirectUrl":""', $data );
	}

	public function test_redirect_url_reflects_selected_page(): void {
		$page_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'What happens next',
		] );
		update_option( 'agnosis_join_success_url', $page_id );
		wp_set_current_user( 0 );

		( new JoinPage() )->render_block();

		$data      = $this->localized_data( 'agnosis-join' );
		$permalink = str_replace( '/', '\/', get_permalink( $page_id ) );
		$this->assertStringContainsString( $permalink, $data );
	}

	public function test_redirect_url_ignores_unselected_page_option(): void {
		update_option( 'agnosis_join_success_url', 0 );
		wp_set_current_user( 0 );

		( new JoinPage() )->render_block();

		$data = $this->localized_data( 'agnosis-join' );
		$this->assertStringContainsString( '"redirectUrl":""', $data );
	}

	/**
	 * Back-compat: sites configured before this became a page selector stored
	 * a raw URL string directly (including URLs on other domains) — still
	 * honoured until the setting is next saved through the new dropdown.
	 */
	public function test_redirect_url_falls_back_to_legacy_raw_url_string(): void {
		update_option( 'agnosis_join_success_url', 'https://example.com/thanks/' );
		wp_set_current_user( 0 );

		( new JoinPage() )->render_block();

		$data = $this->localized_data( 'agnosis-join' );
		$this->assertStringContainsString( 'https:\/\/example.com\/thanks\/', $data );
	}
}
