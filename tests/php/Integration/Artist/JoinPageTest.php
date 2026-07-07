<?php
/**
 * Integration tests — agnosis/join block rendering and its localized config.
 *
 * Focused on the "After applying, send artists to" setting
 * (agnosis_join_success_url): it should reach the frontend via
 * wp_localize_script() as `redirectUrl`, empty by default and unmodified
 * when set, so blocks/join/frontend.js can redirect a successful applicant
 * there (see JoinPage::success_redirect_url()).
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

	public function test_redirect_url_reflects_configured_option(): void {
		update_option( 'agnosis_join_success_url', 'https://example.com/thanks/' );
		wp_set_current_user( 0 );

		( new JoinPage() )->render_block();

		$data = $this->localized_data( 'agnosis-join' );
		$this->assertStringContainsString( 'https:\/\/example.com\/thanks\/', $data );
	}
}
