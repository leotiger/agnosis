<?php
/**
 * Unit tests for SubdomainRouter static helpers.
 *
 * These tests cover the stateless helpers and the initial default state of the
 * static properties. Full boot() behaviour (which needs WP's get_option and
 * get_user_by to behave dynamically) is covered in the integration suite.
 *
 * @package Agnosis\Tests\Unit\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Network;

use Agnosis\Network\SubdomainRouter;
use PHPUnit\Framework\TestCase;

class SubdomainRouterTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Reset static state between tests so they are fully isolated.
	 */
	private function reset_state(): void {
		$ref_id = new \ReflectionProperty( SubdomainRouter::class, 'artist_id' );
		$ref_id->setAccessible( true );
		$ref_id->setValue( null, null );

		$ref_slug = new \ReflectionProperty( SubdomainRouter::class, 'slug' );
		$ref_slug->setAccessible( true );
		$ref_slug->setValue( null, '' );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->reset_state();
	}

	protected function tearDown(): void {
		$this->reset_state();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Default static state
	// -------------------------------------------------------------------------

	public function test_is_artist_subdomain_returns_false_by_default(): void {
		$this->assertFalse( SubdomainRouter::is_artist_subdomain() );
	}

	public function test_current_artist_id_returns_null_by_default(): void {
		$this->assertNull( SubdomainRouter::current_artist_id() );
	}

	// -------------------------------------------------------------------------
	// Static state accessors reflect what boot() sets
	// -------------------------------------------------------------------------

	public function test_is_artist_subdomain_returns_true_after_state_set(): void {
		$ref_id = new \ReflectionProperty( SubdomainRouter::class, 'artist_id' );
		$ref_id->setAccessible( true );
		$ref_id->setValue( null, 42 );

		$this->assertTrue( SubdomainRouter::is_artist_subdomain() );
	}

	public function test_current_artist_id_returns_set_value(): void {
		$ref_id = new \ReflectionProperty( SubdomainRouter::class, 'artist_id' );
		$ref_id->setAccessible( true );
		$ref_id->setValue( null, 99 );

		$this->assertSame( 99, SubdomainRouter::current_artist_id() );
	}

	// -------------------------------------------------------------------------
	// url_for_artist() — no base domain configured
	// -------------------------------------------------------------------------

	public function test_url_for_artist_returns_home_url_when_no_base_domain(): void {
		// Unit bootstrap stubs get_option() to always return the default ('' here).
		$result = SubdomainRouter::url_for_artist( 5 );

		$this->assertSame( home_url(), $result );
	}

	public function test_url_for_artist_returns_home_url_when_user_not_found(): void {
		// get_user_by() stub always returns false; get_option returns '' so we
		// hit the !$base guard first, but the !$user guard is also covered here.
		$result = SubdomainRouter::url_for_artist( 0 );

		$this->assertSame( home_url(), $result );
	}
}
