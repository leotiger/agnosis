<?php
/**
 * Stateless unsubscribe tokens for the artist newsletter.
 *
 * Admitted artists are auto-enrolled in the artist newsletter (no signup row
 * to key off of), so their one-click unsubscribe link uses a stateless HMAC
 * token — the same pattern as VouchConfirm's vote links — instead of a stored
 * per-user secret. Nothing needs to be provisioned ahead of time and the
 * token is cheap to verify.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

class Tokens {

	/** Build the artist newsletter unsubscribe token for a given user. */
	public static function artist_unsubscribe_token( int $user_id ): string {
		return hash_hmac( 'sha256', 'newsletter-optout|' . $user_id, wp_salt( 'auth' ) );
	}

	/** Constant-time verification of an artist unsubscribe token. */
	public static function verify_artist_unsubscribe_token( int $user_id, string $token ): bool {
		return hash_equals( self::artist_unsubscribe_token( $user_id ), $token );
	}
}
