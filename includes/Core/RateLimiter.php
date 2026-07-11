<?php
/**
 * Transient-based per-IP rate limiter.
 *
 * Counts requests per action per IP address within a window and returns a
 * WP_Error when the limit is exceeded.  Uses WordPress transients so it works
 * on any host without extra infrastructure.
 *
 * This is a FIXED window, not a sliding one (code-quality audit §6 —
 * corrected from an earlier docblock that called it "sliding"/"rolling"):
 * transient_key()'s `floor( time() / $window_seconds )` slot changes the
 * whole counter's identity once per window boundary, rather than continuously
 * decaying older requests out of a moving lookback period. The practical
 * effect is that a burst straddling a boundary (e.g. the limit's worth of
 * requests just before a slot rolls over, plus the limit's worth again just
 * after) can let up to roughly 2x the configured limit through inside one
 * real-world window-length span. Acceptable at this plugin's actual traffic
 * (a handful of low-single-digit-per-minute application/webhook limits, not a
 * high-value target where a 2x burst materially changes the threat model) —
 * documented here rather than switched to a heavier sliding-window
 * implementation.
 *
 * Usage in a permission callback:
 *
 *   $rate = RateLimiter::check( 'admission_apply', 5, 60 );
 *   if ( is_wp_error( $rate ) ) {
 *       return $rate;
 *   }
 *
 * IP detection uses REMOTE_ADDR only — not X-Forwarded-For — to prevent
 * spoofing.  Sites behind a trusted reverse proxy that rewrites REMOTE_ADDR
 * (e.g. via nginx real_ip_from) work correctly without any additional config.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

use WP_Error;

class RateLimiter {

	/**
	 * Check the rate limit for an action from the current IP address.
	 *
	 * @param string $action         Unique action identifier (e.g. 'admission_apply').
	 * @param int    $limit          Maximum number of requests allowed in the window.
	 * @param int    $window_seconds Length of the (fixed, not sliding — see class docblock) window in seconds.
	 * @return true|WP_Error         true if within limit; WP_Error(429) when exceeded.
	 */
	public static function check( string $action, int $limit, int $window_seconds = 60 ): bool|WP_Error {
		$ip  = self::client_ip();
		$key = self::transient_key( $action, $ip, $window_seconds );

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'agnosis_rate_limit',
				/* translators: %d: number of seconds until the rate-limit window resets. */
				sprintf( __( 'Too many requests. Please wait %d seconds and try again.', 'agnosis' ), $window_seconds ),
				[ 'status' => 429 ]
			);
		}

		// Increment or create the counter.  set_transient is atomic enough for
		// our purposes — exact-once semantics are not required here. (A
		// wp_cache_incr() call used to sit here as a supposed object-cache
		// fast path — code-quality audit §6 found it targeted the wrong cache
		// group, transients live under the `transient` group, not a bare
		// `transient_`-prefixed key in the default group — so its result was
		// immediately discarded and overwritten by set_transient() on the very
		// next line regardless. Removed rather than fixed: set_transient()
		// already goes through the object cache transparently on any host
		// that has one configured, so there was nothing this second call
		// added even with the correct group.)
		set_transient( $key, $count + 1, $window_seconds );

		return true;
	}

	/**
	 * Check the rate limit for an action keyed by sender email address.
	 *
	 * Used for per-sender intake throttling on the IMAP and webhook paths where
	 * IP-based limiting is not meaningful (the relay IP is always the ESP).
	 * The email address is hashed so it is never stored in plain text.
	 *
	 * @param string $action         Unique action identifier (e.g. 'email_intake').
	 * @param string $from_email     Sender email address used as the rate-limit key.
	 * @param int    $limit          Maximum submissions allowed in the window.
	 * @param int    $window_seconds Length of the (fixed, not sliding — see class docblock) window in seconds.
	 * @return true|WP_Error         true if within limit; WP_Error(429) when exceeded.
	 */
	public static function check_sender( string $action, string $from_email, int $limit, int $window_seconds = 3600 ): bool|WP_Error {
		$key   = self::transient_key( $action, md5( strtolower( trim( $from_email ) ) ), $window_seconds );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'agnosis_sender_rate_limit',
				/* translators: %d: number of seconds until the rate-limit window resets. */
				sprintf( __( 'Submission limit reached. Please wait before sending again.', 'agnosis' ) ),
				[ 'status' => 429 ]
			);
		}

		// See check()'s identical line for why this is unconditional now — the
		// 0-vs-not-0 branch existed only to gate a dead wp_cache_incr() call
		// (code-quality audit §6); $count + 1 already equals 1 in the 0 case.
		set_transient( $key, $count + 1, $window_seconds );

		return true;
	}

	/**
	 * Reset the rate-limit counter for an action + IP combination.
	 *
	 * Useful in tests and in admin "unblock" flows.
	 *
	 * @param string $action         Action identifier.
	 * @param string $ip             IP address to reset.
	 * @param int    $window_seconds Window length used when the counter was set.
	 */
	public static function reset( string $action, string $ip, int $window_seconds = 60 ): void {
		delete_transient( self::transient_key( $action, $ip, $window_seconds ) );
	}

	/**
	 * Reset the per-sender rate-limit counter.
	 *
	 * @param string $action         Action identifier.
	 * @param string $from_email     Sender email address.
	 * @param int    $window_seconds Window length used when the counter was set.
	 */
	public static function reset_sender( string $action, string $from_email, int $window_seconds = 3600 ): void {
		delete_transient( self::transient_key( $action, md5( strtolower( trim( $from_email ) ) ), $window_seconds ) );
	}

	/**
	 * Return the client IP address.
	 *
	 * Uses REMOTE_ADDR only — not X-Forwarded-For — to prevent header spoofing.
	 * Sites behind a trusted reverse proxy that rewrites REMOTE_ADDR at the
	 * network layer (nginx real_ip_from, AWS ALB, Cloudflare) work correctly
	 * without any additional configuration here.
	 */
	public static function client_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REMOTE_ADDR is set by the server, not by the client.
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a deterministic transient key for the action + IP + window slot.
	 *
	 * The window slot (floor(time() / $window)) ensures the key rotates
	 * automatically every $window_seconds without a scheduled cleanup.
	 *
	 * Key length is kept under 172 characters (WP transient limit is 172 chars
	 * for the option name including the `_transient_` prefix).
	 *
	 * @param string $action         Action identifier.
	 * @param string $ip             Client IP address.
	 * @param int    $window_seconds Window length in seconds.
	 * @return string
	 */
	private static function transient_key( string $action, string $ip, int $window_seconds ): string {
		$slot = (int) floor( time() / max( 1, $window_seconds ) );
		return 'agnrl_' . substr( md5( $action . '|' . $ip . '|' . $slot ), 0, 24 );
	}
}
