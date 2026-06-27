<?php
/**
 * Unit tests for PostCreator::generate_token().
 *
 * generate_token() is the single source of truth for all review and removal
 * tokens. Tests verify that every token:
 *
 *   • is exactly 64 hex characters (32 bytes of entropy, hex-encoded)
 *   • contains only lowercase hexadecimal characters
 *   • is unique across consecutive calls (birthday collision would require
 *     ~2^128 calls — verifying two tokens differ is a sanity check, not a
 *     statistical proof, but it catches accidental constant returns)
 *   • survives a timing-safe hash_equals comparison against itself
 *   • fails a hash_equals comparison against a different token
 *
 * @package Agnosis\Tests\Unit\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Publishing;

use Agnosis\Publishing\PostCreator;
use PHPUnit\Framework\TestCase;

class TokenGenerationTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helper — call private generate_token() via reflection
	// -------------------------------------------------------------------------

	/**
	 * Invoke PostCreator::generate_token() without running the constructor
	 * (which would try to instantiate Pipeline and hit real AI dependencies).
	 */
	private function generate(): string {
		$instance = ( new \ReflectionClass( PostCreator::class ) )
			->newInstanceWithoutConstructor();

		$method = new \ReflectionMethod( PostCreator::class, 'generate_token' );
		$method->setAccessible( true );

		/** @var string */
		return $method->invoke( $instance );
	}

	// -------------------------------------------------------------------------
	// Format
	// -------------------------------------------------------------------------

	public function test_token_is_64_characters_long(): void {
		$token = $this->generate();
		$this->assertSame( 64, strlen( $token ), 'Expected 32 bytes (64 hex chars).' );
	}

	public function test_token_contains_only_lowercase_hex_characters(): void {
		$token = $this->generate();
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{64}$/',
			$token,
			'Token must be lowercase hex — no uppercase, no special characters.'
		);
	}

	// -------------------------------------------------------------------------
	// Uniqueness
	// -------------------------------------------------------------------------

	public function test_two_consecutive_tokens_differ(): void {
		$a = $this->generate();
		$b = $this->generate();
		$this->assertNotSame( $a, $b, 'Two consecutive tokens must not be identical.' );
	}

	public function test_ten_tokens_are_all_unique(): void {
		$tokens = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$tokens[] = $this->generate();
		}
		$unique = array_unique( $tokens );
		$this->assertCount( 10, $unique, 'All 10 generated tokens must be unique.' );
	}

	// -------------------------------------------------------------------------
	// Verification compatibility (hash_equals)
	// -------------------------------------------------------------------------

	public function test_token_passes_hash_equals_against_itself(): void {
		$token = $this->generate();
		$this->assertTrue(
			hash_equals( $token, $token ),
			'A token must verify against itself with hash_equals.'
		);
	}

	public function test_different_token_fails_hash_equals(): void {
		$stored   = $this->generate();
		$supplied = $this->generate();
		$this->assertFalse(
			hash_equals( $stored, $supplied ),
			'A different token must not pass hash_equals verification.'
		);
	}

	public function test_empty_string_fails_hash_equals_against_real_token(): void {
		$stored = $this->generate();
		$this->assertFalse(
			hash_equals( $stored, '' ),
			'An empty string must not pass hash_equals verification.'
		);
	}
}
