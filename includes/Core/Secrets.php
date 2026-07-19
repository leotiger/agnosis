<?php
/**
 * Optional wp-config.php constant overrides for the plugin's API keys/secrets.
 *
 * Every key/secret Agnosis needs (OpenAI, Anthropic, the inbound-webhook
 * HMAC secret, both Cloudflare Turnstile keys) has always lived as a plain
 * `wp_options` row — the WordPress-standard pattern, same as every mainstream
 * plugin, and not a real weakness on its own (anyone with DB read already has
 * worse problems). But an operator who already keeps secrets in wp-config.php
 * (env vars, a secrets manager writing PHP constants at deploy time, etc.)
 * had no way to keep these ones there too — recorded as a considered,
 * deliberately-deferred note in the pre-public audit (P-4, `agnosis-audit/
 * AUDIT-0.9.39.md` §3c) and closed here.
 *
 * This class is the single place that precedence is decided — every call
 * site that used to read one of these five options directly now goes through
 * the matching method here instead, so "which value actually wins" only ever
 * has one answer. A defined, non-empty constant always wins over whatever is
 * saved in the database; an empty-string constant is treated as "not set"
 * (so `define('AGNOSIS_OPENAI_KEY', '')` doesn't silently lock an operator
 * out of the Settings-page field for no reason).
 *
 * `Admin\Settings::render_field()` uses `override_constant_name()` to detect
 * this generically for any option in MAP, rather than special-casing five
 * fields by name — a locked, non-editable field renders in place of the
 * normal input whenever its constant is defined, so an operator can't type a
 * value into a DB option that would then be silently ignored.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class Secrets {

	/**
	 * option_name => wp-config.php constant name.
	 *
	 * The Turnstile site key is technically public-by-design (Cloudflare's
	 * own widget ships it to the browser), but it's included for the same
	 * reason its secret counterpart is — an operator managing both keys as a
	 * pair via the same deploy-time mechanism shouldn't have to special-case
	 * one of them.
	 */
	private const MAP = [
		'agnosis_openai_api_key'       => 'AGNOSIS_OPENAI_KEY',
		'agnosis_anthropic_api_key'    => 'AGNOSIS_ANTHROPIC_KEY',
		'agnosis_webhook_secret'       => 'AGNOSIS_WEBHOOK_SECRET',
		'agnosis_turnstile_site_key'   => 'AGNOSIS_TURNSTILE_SITE_KEY',
		'agnosis_turnstile_secret_key' => 'AGNOSIS_TURNSTILE_SECRET_KEY',
	];

	/**
	 * Test-only substitute for defined()/constant(). SecretsTest uses this
	 * (via set_constant_lookup_for_testing() below) instead of ever calling
	 * define() on a real constant name from MAP above — a real define() can
	 * never be undone for the rest of that PHP process, which previously
	 * broke WebhookSignatureTest/WebhookReplayProtectionTest's own
	 * `AGNOSIS_WEBHOOK_SECRET`-shaped fixtures whenever they ran anywhere
	 * after SecretsTest in the same run (P-4 follow-up regression, found by
	 * Ulises's PHPUnit run 2026-07-20 — `@runInSeparateProcess` was the first
	 * fix attempted and didn't hold, so correctness no longer depends on
	 * process-isolation behaving a particular way in any given environment).
	 * Always null outside of SecretsTest, so production and every other test
	 * suite use the real defined()/constant() below unchanged.
	 *
	 * @var null|callable(string): (string|null)
	 */
	private static $constant_lookup = null;

	/**
	 * @internal Test-only hook — see $constant_lookup above. Not part of the
	 * public API this class exists to provide; production code never calls
	 * this. Pass null to restore the real defined()/constant() behaviour.
	 *
	 * @param null|callable(string): (string|null) $lookup
	 */
	public static function set_constant_lookup_for_testing( ?callable $lookup ): void {
		self::$constant_lookup = $lookup;
	}

	/** Real value of $constant if defined, else null — routed through $constant_lookup when a test has set one. */
	private static function constant_value( string $constant ): ?string {
		if ( null !== self::$constant_lookup ) {
			return ( self::$constant_lookup )( $constant );
		}
		return defined( $constant ) ? (string) constant( $constant ) : null;
	}

	public static function openai_api_key(): string {
		return self::resolve( 'agnosis_openai_api_key' );
	}

	public static function anthropic_api_key(): string {
		return self::resolve( 'agnosis_anthropic_api_key' );
	}

	public static function webhook_secret(): string {
		return self::resolve( 'agnosis_webhook_secret' );
	}

	public static function turnstile_site_key(): string {
		return self::resolve( 'agnosis_turnstile_site_key' );
	}

	public static function turnstile_secret_key(): string {
		return self::resolve( 'agnosis_turnstile_secret_key' );
	}

	/**
	 * True when $option is one of the five above AND its matching constant
	 * is currently defined with a non-empty value — i.e. the DB-stored
	 * option, whatever it holds, is not the value actually in effect.
	 */
	public static function is_overridden( string $option ): bool {
		$constant = self::MAP[ $option ] ?? null;
		if ( null === $constant ) {
			return false;
		}
		$value = self::constant_value( $constant );
		return null !== $value && '' !== trim( $value );
	}

	/**
	 * The wp-config.php constant name that would override $option, or null
	 * if $option isn't one of the five this class covers at all. Used by
	 * Admin\Settings::render_field() to decide whether to render the normal
	 * input or a locked "set via wp-config.php" notice — independent of
	 * whether that constant is actually currently defined (a field with a
	 * mapped-but-undefined constant still renders normally).
	 */
	public static function override_constant_name( string $option ): ?string {
		return self::MAP[ $option ] ?? null;
	}

	/**
	 * Resolve one option: the mapped constant if it's defined and non-empty,
	 * otherwise whatever is in the database (trimmed, matching every call
	 * site this replaces — all of them already trimmed or cast defensively).
	 */
	private static function resolve( string $option ): string {
		$constant = self::MAP[ $option ] ?? null;

		if ( null !== $constant ) {
			$constant_value = self::constant_value( $constant );
			if ( null !== $constant_value ) {
				$value = trim( $constant_value );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return trim( (string) get_option( $option, '' ) );
	}
}
