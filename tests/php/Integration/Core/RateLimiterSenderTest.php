<?php
/**
 * Integration tests for RateLimiter::check_sender() and reset_sender().
 *
 * check_sender() is keyed by hashed email address instead of IP — it is used
 * for per-artist intake throttling on the IMAP and webhook paths where all
 * traffic arrives from the same ESP relay IP.
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\RateLimiter;
use WP_UnitTestCase;

/**
 * @covers \Agnosis\Core\RateLimiter::check_sender
 * @covers \Agnosis\Core\RateLimiter::reset_sender
 */
class RateLimiterSenderTest extends WP_UnitTestCase {

	private const EMAIL_A = 'artist_a@example.com';
	private const EMAIL_B = 'artist_b@example.com';

	protected function tearDown(): void {
		RateLimiter::reset_sender( 'intake_test', self::EMAIL_A );
		RateLimiter::reset_sender( 'intake_test', self::EMAIL_B );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Basic allow / block
	// -------------------------------------------------------------------------

	public function test_first_submission_is_allowed(): void {
		$result = RateLimiter::check_sender( 'intake_test', self::EMAIL_A, 5 );
		$this->assertTrue( $result );
	}

	public function test_submissions_within_limit_are_allowed(): void {
		for ( $i = 0; $i < 4; $i++ ) {
			$result = RateLimiter::check_sender( 'intake_test', self::EMAIL_A, 5 );
			$this->assertTrue( $result, "Submission $i should be allowed." );
		}
	}

	public function test_submission_at_limit_is_blocked(): void {
		// Fill up to the limit.
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimiter::check_sender( 'intake_test', self::EMAIL_A, 5 );
		}

		$result = RateLimiter::check_sender( 'intake_test', self::EMAIL_A, 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'agnosis_sender_rate_limit', $result->get_error_code() );
	}

	public function test_limit_of_one_blocks_on_second_submission(): void {
		RateLimiter::check_sender( 'intake_test', self::EMAIL_A, 1 );

		$result = RateLimiter::check_sender( 'intake_test', self::EMAIL_A, 1 );

		$this->assertWPError( $result );
	}

	// -------------------------------------------------------------------------
	// Isolation
	// -------------------------------------------------------------------------

	public function test_different_emails_have_independent_counters(): void {
		// Exhaust artist_a.
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimiter::check_sender( 'intake_test', self::EMAIL_A, 5 );
		}

		// artist_b is unaffected.
		$result = RateLimiter::check_sender( 'intake_test', self::EMAIL_B, 5 );

		$this->assertTrue( $result );
	}

	public function test_email_address_is_case_insensitive(): void {
		// Fills counter under lowercase key.
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimiter::check_sender( 'intake_test', 'Artist_A@Example.COM', 5 );
		}

		// Same address in different case should be blocked (same hash).
		$result = RateLimiter::check_sender( 'intake_test', self::EMAIL_A, 5 );

		$this->assertWPError( $result );

		// Cleanup under the normalised form.
		RateLimiter::reset_sender( 'intake_test', self::EMAIL_A );
	}

	// -------------------------------------------------------------------------
	// reset_sender()
	// -------------------------------------------------------------------------

	public function test_reset_sender_clears_counter(): void {
		// Exhaust.
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimiter::check_sender( 'intake_test', self::EMAIL_A, 5 );
		}
		$this->assertWPError( RateLimiter::check_sender( 'intake_test', self::EMAIL_A, 5 ) );

		// Reset and verify allowed again.
		RateLimiter::reset_sender( 'intake_test', self::EMAIL_A );
		$result = RateLimiter::check_sender( 'intake_test', self::EMAIL_A, 5 );

		$this->assertTrue( $result );
	}
}
