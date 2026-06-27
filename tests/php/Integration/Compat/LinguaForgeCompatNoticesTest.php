<?php
/**
 * Integration tests for LinguaForge::compatibility_notices().
 *
 * The method has three guard conditions that must all pass before the notice
 * renders: the current user must have manage_options, the agnosis_base_domain
 * option must be set, and LinguaForge must be active in subdomain routing mode.
 * Each condition is tested independently.
 *
 * LINGUAFORGE_VERSION is defined once for the process so tests can exercise the
 * "LF active in subdomain mode" branch.  The inverse branch (constant absent)
 * cannot be un-defined at runtime; it is effectively covered by testing that the
 * routing-mode guard alone suppresses the notice.
 *
 * @package Agnosis\Tests\Integration\Compat
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Compat;

use Agnosis\Compat\LinguaForge;

if ( ! defined( 'LINGUAFORGE_VERSION' ) ) {
	define( 'LINGUAFORGE_VERSION', '1.0.0-test' );
}

class LinguaForgeCompatNoticesTest extends \WP_UnitTestCase {

	/** @var int Administrator user ID shared across tests. */
	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// Default: all three conditions met so individual tests can disable one.
		update_option( 'agnosis_base_domain', 'agnosis.art' );
		update_option( 'linguaforge_routing_mode', 'subdomain' );
	}

	protected function tearDown(): void {
		parent::tearDown();
		delete_option( 'agnosis_base_domain' );
		delete_option( 'linguaforge_routing_mode' );
		wp_set_current_user( 0 );
	}

	// ── Helper ────────────────────────────────────────────────────────────────

	private function capture(): string {
		ob_start();
		( new LinguaForge() )->compatibility_notices();
		return (string) ob_get_clean();
	}

	// ── Notice shown ──────────────────────────────────────────────────────────

	public function test_notice_shown_when_all_conditions_are_met(): void {
		wp_set_current_user( $this->admin_id );

		$output = $this->capture();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'LinguaForge', $output );
		$this->assertStringContainsString( 'subdomain', $output );
	}

	public function test_notice_includes_base_domain_in_example_url(): void {
		wp_set_current_user( $this->admin_id );

		$output = $this->capture();

		$this->assertStringContainsString( 'agnosis.art', $output );
	}

	// ── Notice suppressed ─────────────────────────────────────────────────────

	public function test_notice_suppressed_when_user_lacks_manage_options(): void {
		wp_set_current_user( 0 ); // unauthenticated

		$this->assertSame( '', $this->capture() );
	}

	public function test_notice_suppressed_when_base_domain_not_configured(): void {
		wp_set_current_user( $this->admin_id );
		delete_option( 'agnosis_base_domain' );

		$this->assertSame( '', $this->capture() );
	}

	public function test_notice_suppressed_when_routing_mode_is_path(): void {
		wp_set_current_user( $this->admin_id );
		update_option( 'linguaforge_routing_mode', 'path' );

		$this->assertSame( '', $this->capture() );
	}

	public function test_notice_suppressed_when_routing_mode_is_absent(): void {
		wp_set_current_user( $this->admin_id );
		delete_option( 'linguaforge_routing_mode' ); // defaults to 'path'

		$this->assertSame( '', $this->capture() );
	}
}
