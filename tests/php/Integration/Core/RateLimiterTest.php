<?php
/**
 * Integration tests for Core\RateLimiter.
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\RateLimiter;
use WP_UnitTestCase;

/**
 * @covers \Agnosis\Core\RateLimiter
 */
class RateLimiterTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Setup / teardown
	// -------------------------------------------------------------------------

	protected function setUp(): void {
		parent::setUp();
		// Ensure REMOTE_ADDR is set for client_ip().
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	protected function tearDown(): void {
		// Reset counter for each test action so tests don't bleed into each other.
		RateLimiter::reset( 'test_action', '127.0.0.1', 60 );
		RateLimiter::reset( 'test_action_short', '127.0.0.1', 1 );
		RateLimiter::reset( 'test_action_reset', '127.0.0.1', 60 );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_first_request_is_allowed(): void {
		$result = RateLimiter::check( 'test_action', 5, 60 );

		$this->assertTrue( $result );
	}

	public function test_requests_within_limit_are_allowed(): void {
		for ( $i = 0; $i < 4; $i++ ) {
			$result = RateLimiter::check( 'test_action', 5, 60 );
			$this->assertTrue( $result, "Request $i should be allowed" );
		}
	}

	public function test_request_at_limit_is_blocked(): void {
		// Exhaust the limit.
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimiter::check( 'test_action', 5, 60 );
		}

		$result = RateLimiter::check( 'test_action', 5, 60 );

		$this->assertWPError( $result );
		$this->assertSame( 'agnosis_rate_limit', $result->get_error_code() );
	}

	public function test_rate_limit_error_has_429_status(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimiter::check( 'test_action', 5, 60 );
		}

		$result = RateLimiter::check( 'test_action', 5, 60 );

		$this->assertWPError( $result );
		$data = $result->get_error_data( 'agnosis_rate_limit' );
		$this->assertSame( 429, $data['status'] );
	}

	public function test_different_actions_have_independent_counters(): void {
		// Exhaust 'test_action'.
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimiter::check( 'test_action', 5, 60 );
		}

		// A different action should still be allowed.
		$result = RateLimiter::check( 'test_action_short', 5, 1 );

		$this->assertTrue( $result );
	}

	public function test_different_ips_have_independent_counters(): void {
		$_SERVER['REMOTE_ADDR'] = '1.2.3.4';
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimiter::check( 'test_action', 5, 60 );
		}

		$_SERVER['REMOTE_ADDR'] = '5.6.7.8';
		$result                 = RateLimiter::check( 'test_action', 5, 60 );

		$this->assertTrue( $result );

		// Cleanup.
		RateLimiter::reset( 'test_action', '1.2.3.4', 60 );
		RateLimiter::reset( 'test_action', '5.6.7.8', 60 );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	public function test_reset_clears_counter(): void {
		// Exhaust the limit.
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimiter::check( 'test_action_reset', 5, 60 );
		}

		// Verify it's blocked.
		$blocked = RateLimiter::check( 'test_action_reset', 5, 60 );
		$this->assertWPError( $blocked );

		// Reset and verify it's allowed again.
		RateLimiter::reset( 'test_action_reset', '127.0.0.1', 60 );
		$result = RateLimiter::check( 'test_action_reset', 5, 60 );

		$this->assertTrue( $result );
	}

	public function test_client_ip_returns_remote_addr(): void {
		$_SERVER['REMOTE_ADDR'] = '192.168.1.50';

		$ip = RateLimiter::client_ip();

		$this->assertSame( '192.168.1.50', $ip );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	public function test_client_ip_falls_back_to_default(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		$ip = RateLimiter::client_ip();

		$this->assertSame( '0.0.0.0', $ip );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	public function test_limit_of_one_blocks_on_second_request(): void {
		RateLimiter::check( 'test_action', 1, 60 );

		$result = RateLimiter::check( 'test_action', 1, 60 );

		$this->assertWPError( $result );
	}
}
