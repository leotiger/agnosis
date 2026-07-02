<?php
/**
 * Integration tests — stateless artist newsletter unsubscribe tokens.
 *
 * Same HMAC pattern as VouchConfirmTest covers for admission voting: a token
 * must verify for the exact user it was minted for, and reject tampering or
 * a mismatched user ID.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\Tokens;

class TokensTest extends \WP_UnitTestCase {

	public function test_token_verifies_for_the_same_user(): void {
		$token = Tokens::artist_unsubscribe_token( 42 );

		$this->assertTrue( Tokens::verify_artist_unsubscribe_token( 42, $token ) );
	}

	public function test_token_does_not_verify_for_a_different_user(): void {
		$token = Tokens::artist_unsubscribe_token( 42 );

		$this->assertFalse( Tokens::verify_artist_unsubscribe_token( 43, $token ) );
	}

	public function test_tampered_token_fails_verification(): void {
		$token   = Tokens::artist_unsubscribe_token( 42 );
		$flipped = substr( $token, 0, -1 ) . ( $token[-1] === 'a' ? 'b' : 'a' );

		$this->assertFalse( Tokens::verify_artist_unsubscribe_token( 42, $flipped ) );
	}

	public function test_empty_token_fails_verification(): void {
		$this->assertFalse( Tokens::verify_artist_unsubscribe_token( 42, '' ) );
	}

	public function test_token_is_deterministic_for_the_same_user(): void {
		$this->assertSame(
			Tokens::artist_unsubscribe_token( 7 ),
			Tokens::artist_unsubscribe_token( 7 )
		);
	}
}
