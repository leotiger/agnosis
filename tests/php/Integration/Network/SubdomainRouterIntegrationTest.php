<?php
/**
 * Integration tests for SubdomainRouter.
 *
 * Runs inside the wp-env container where WordPress functions and the database
 * are fully available. Tests cover:
 *
 *  • Early bail conditions (no base domain, main domain, unknown user, multi-level).
 *  • User resolution by nicename and by login fallback.
 *  • LinguaForge subdomain-mode conflict disables the router entirely.
 *  • Static accessors reflect boot() state correctly.
 *  • url_for_artist() builds the correct subdomain URL.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\SubdomainRouter;

class SubdomainRouterIntegrationTest extends \WP_UnitTestCase {

	/** @var string Original HTTP_HOST value, restored in tearDown. */
	private string $original_host = '';

	// -------------------------------------------------------------------------
	// Set-up / tear-down
	// -------------------------------------------------------------------------

	protected function setUp(): void {
		parent::setUp();

		$this->original_host = $_SERVER['HTTP_HOST'] ?? '';

		update_option( 'agnosis_base_domain', 'agnosis.art' );
		// LinguaForge path mode — leaves subdomains free.
		update_option( 'linguaforge_routing_mode', 'path' );

		$this->reset_router_state();
	}

	protected function tearDown(): void {
		$_SERVER['HTTP_HOST'] = $this->original_host;

		delete_option( 'agnosis_base_domain' );
		delete_option( 'linguaforge_routing_mode' );

		$this->reset_router_state();
		parent::tearDown();
	}

	/**
	 * Reset the static properties so tests are fully isolated.
	 */
	private function reset_router_state(): void {
		$ref_id = new \ReflectionProperty( SubdomainRouter::class, 'artist_id' );
		$ref_id->setAccessible( true );
		$ref_id->setValue( null, null );

		$ref_slug = new \ReflectionProperty( SubdomainRouter::class, 'slug' );
		$ref_slug->setAccessible( true );
		$ref_slug->setValue( null, '' );
	}

	private function boot( string $host ): void {
		$_SERVER['HTTP_HOST'] = $host;
		( new SubdomainRouter() )->boot();
	}

	// -------------------------------------------------------------------------
	// Early bail — no base domain
	// -------------------------------------------------------------------------

	public function test_boot_does_nothing_when_base_domain_not_configured(): void {
		delete_option( 'agnosis_base_domain' );

		$this->boot( 'artist.agnosis.art' );

		$this->assertFalse( SubdomainRouter::is_artist_subdomain() );
		$this->assertNull( SubdomainRouter::current_artist_id() );
	}

	// -------------------------------------------------------------------------
	// Early bail — main domain
	// -------------------------------------------------------------------------

	public function test_boot_does_nothing_on_main_domain(): void {
		$this->boot( 'agnosis.art' );

		$this->assertFalse( SubdomainRouter::is_artist_subdomain() );
	}

	public function test_boot_does_nothing_on_www_main_domain(): void {
		// www.agnosis.art ends with .agnosis.art but "www" is not a registered user.
		$this->boot( 'www.agnosis.art' );

		$this->assertFalse( SubdomainRouter::is_artist_subdomain() );
	}

	// -------------------------------------------------------------------------
	// Early bail — multi-level subdomain (SECURITY)
	// -------------------------------------------------------------------------

	public function test_boot_rejects_multilevel_subdomain(): void {
		// Create a user whose nicename is "artist" so that if the router
		// mistakenly parsed "artist" from "sub.artist.agnosis.art" it would
		// resolve — proving the guard is what prevents routing, not a missing user.
		self::factory()->user->create( [ 'user_nicename' => 'artist' ] );

		$this->boot( 'sub.artist.agnosis.art' );

		$this->assertFalse(
			SubdomainRouter::is_artist_subdomain(),
			'Multi-level subdomains must never be routed to an artist.'
		);
	}

	public function test_boot_rejects_deeply_nested_subdomain(): void {
		$this->boot( 'a.b.c.agnosis.art' );

		$this->assertFalse( SubdomainRouter::is_artist_subdomain() );
	}

	// -------------------------------------------------------------------------
	// Early bail — unknown user
	// -------------------------------------------------------------------------

	public function test_boot_does_nothing_for_unknown_user_subdomain(): void {
		$this->boot( 'nobody-registered.agnosis.art' );

		$this->assertFalse( SubdomainRouter::is_artist_subdomain() );
		$this->assertNull( SubdomainRouter::current_artist_id() );
	}

	// -------------------------------------------------------------------------
	// User resolution — nicename (primary)
	// -------------------------------------------------------------------------

	public function test_boot_resolves_artist_by_nicename(): void {
		$user_id = self::factory()->user->create( [
			'user_nicename' => 'mariapainter',
			'user_login'    => 'maria_painter_login',
		] );

		$this->boot( 'mariapainter.agnosis.art' );

		$this->assertTrue( SubdomainRouter::is_artist_subdomain() );
		$this->assertSame( $user_id, SubdomainRouter::current_artist_id() );
	}

	// -------------------------------------------------------------------------
	// User resolution — login fallback
	// -------------------------------------------------------------------------

	public function test_boot_falls_back_to_login_when_nicename_does_not_match(): void {
		// nicename differs from the subdomain slug; login matches.
		$user_id = self::factory()->user->create( [
			'user_login'    => 'joseartist',
			'user_nicename' => 'jose-artist-nicename',
		] );

		$this->boot( 'joseartist.agnosis.art' );

		$this->assertTrue( SubdomainRouter::is_artist_subdomain() );
		$this->assertSame( $user_id, SubdomainRouter::current_artist_id() );
	}

	// -------------------------------------------------------------------------
	// LinguaForge conflict guard
	// -------------------------------------------------------------------------

	public function test_boot_disabled_when_linguaforge_uses_subdomain_routing(): void {
		// Simulate LinguaForge being active if it isn't already — boot() only
		// checks defined('LINGUAFORGE_VERSION'), so defining it here is sufficient.
		if ( ! defined( 'LINGUAFORGE_VERSION' ) ) {
			define( 'LINGUAFORGE_VERSION', 'test' );
		}

		update_option( 'linguaforge_routing_mode', 'subdomain' );

		// Even with a matching user, the router must stand down.
		self::factory()->user->create( [ 'user_nicename' => 'conflictartist' ] );
		$this->boot( 'conflictartist.agnosis.art' );

		$this->assertFalse(
			SubdomainRouter::is_artist_subdomain(),
			'Router must be inactive when LinguaForge is in subdomain mode.'
		);
	}

	public function test_boot_active_when_linguaforge_uses_path_routing(): void {
		update_option( 'linguaforge_routing_mode', 'path' );

		$user_id = self::factory()->user->create( [ 'user_nicename' => 'pathartist' ] );
		$this->boot( 'pathartist.agnosis.art' );

		$this->assertTrue( SubdomainRouter::is_artist_subdomain() );
		$this->assertSame( $user_id, SubdomainRouter::current_artist_id() );
	}

	// -------------------------------------------------------------------------
	// url_for_artist()
	// -------------------------------------------------------------------------

	public function test_url_for_artist_builds_correct_subdomain_url(): void {
		$user_id = self::factory()->user->create( [ 'user_nicename' => 'evacrafter' ] );

		$url = SubdomainRouter::url_for_artist( $user_id );

		$this->assertStringContainsString( 'evacrafter.agnosis.art', $url );
	}

	public function test_url_for_artist_uses_nicename_not_login(): void {
		$user_id = self::factory()->user->create( [
			'user_login'    => 'login_handle',
			'user_nicename' => 'nicename-handle',
		] );

		$url = SubdomainRouter::url_for_artist( $user_id );

		$this->assertStringContainsString( 'nicename-handle', $url );
		$this->assertStringNotContainsString( 'login_handle', $url );
	}

	public function test_url_for_artist_returns_home_url_when_no_base_domain(): void {
		delete_option( 'agnosis_base_domain' );

		$user_id = self::factory()->user->create();

		$url = SubdomainRouter::url_for_artist( $user_id );

		$this->assertSame( home_url(), $url );
	}

	public function test_url_for_artist_returns_home_url_for_nonexistent_user(): void {
		$url = SubdomainRouter::url_for_artist( 999999 );

		$this->assertSame( home_url(), $url );
	}

	// -------------------------------------------------------------------------
	// Port stripping — localhost:8080 style hosts
	// -------------------------------------------------------------------------

	public function test_boot_strips_port_from_host(): void {
		update_option( 'agnosis_base_domain', 'localhost' );

		$user_id = self::factory()->user->create( [ 'user_nicename' => 'devartist' ] );
		$this->boot( 'devartist.localhost:8080' );

		$this->assertTrue( SubdomainRouter::is_artist_subdomain() );
		$this->assertSame( $user_id, SubdomainRouter::current_artist_id() );
	}
}
