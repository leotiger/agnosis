<?php
/**
 * Narrowing helpers for WordPress return types that carry a failure arm.
 *
 * A lot of WordPress' API returns `something|WP_Error`, and the test factories
 * are no different — `self::factory()->user->create()` is `int|WP_Error`. In a
 * test, the failure arm is never the case under test: if a fixture could not be
 * created, the test has no subject and every later assertion is noise. What the
 * test wants is "give me the id, or stop now and say why."
 *
 * Before this trait each file that needed it declared its own
 * `private static function id()`. Four still do (PrivacyTest,
 * ActivityPubHandleTest, FollowOverlayTest, DepartureBanContentTest) and are
 * left alone for now — they work, and rewriting green files is not free. The
 * duplication is what argues against adding a *fifth* copy plus a sixth for
 * `term_ids()`: the six dead `is_string( $title )` ternaries baselined across
 * sibling test files all arrived by copy-pasting one of these helpers along
 * with a guard that only made sense in the file it came from. Shared code
 * cannot drift that way.
 *
 * Why narrow rather than cast: `(int) $created` satisfies PHPStan just as well
 * and turns a failed fixture into the silent id `0` — which passes some
 * assertions and makes unrelated ones fail somewhere much further down. These
 * throw instead, naming the fixture and the WordPress error that produced it.
 *
 * @package Agnosis\Tests\Integration\Support
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Support;

trait NarrowsWpReturns {

	/**
	 * Narrow a factory return from int|WP_Error to int.
	 *
	 * @param int|\WP_Error $created
	 */
	private static function id( $created ): int {
		if ( $created instanceof \WP_Error ) {
			throw new \RuntimeException( 'Test fixture could not be created: ' . $created->get_error_message() );
		}
		return $created;
	}

	/**
	 * Narrow `wp_get_post_terms()`/`wp_get_object_terms()` with `fields => ids`.
	 *
	 * Those return `array<int>|WP_Error`, and callers immediately `foreach`,
	 * `array_intersect()`, `sort()` or subscript the result — every one of which
	 * is a fatal against a WP_Error. The taxonomy is registered by the plugin
	 * under test, so a WP_Error here means the fixture is wrong, not that the
	 * assertion failed.
	 *
	 * `array_values()` is not cosmetic: `sort()` and the `list<int>` PHPStan
	 * infers both want a genuine list, and `wp_get_post_terms()` is only
	 * documented as returning an array — not a re-indexed one.
	 *
	 * @param  array<array-key, mixed>|\WP_Error $terms
	 * @return list<int>
	 */
	private static function term_ids( $terms ): array {
		if ( $terms instanceof \WP_Error ) {
			throw new \RuntimeException( 'Term query failed: ' . $terms->get_error_message() );
		}
		return array_values( array_map( 'intval', $terms ) );
	}

	/**
	 * Same, for `fields => names`.
	 *
	 * Kept separate from `term_ids()` rather than folded into one helper
	 * returning `list<mixed>`: the callers `sort()` these and assert on the
	 * contents, and a `list<string>` is what makes both of those checkable.
	 *
	 * @param  array<array-key, mixed>|\WP_Error $terms
	 * @return list<string>
	 */
	private static function term_names( $terms ): array {
		if ( $terms instanceof \WP_Error ) {
			throw new \RuntimeException( 'Term query failed: ' . $terms->get_error_message() );
		}
		return array_values( array_map( 'strval', $terms ) );
	}

	/**
	 * Pull one query parameter out of a URL the code under test just built.
	 *
	 * Replaces the `parse_str( parse_url( $url, PHP_URL_QUERY ), $parsed )`
	 * dance these tests were repeating. `parse_str()` writes
	 * `array<string, array|string>` — the array arm is real (`?a[]=1&a[]=2`),
	 * so `$parsed['token']` is `array|string` and every use of it as a string
	 * is a type error rather than a nitpick.
	 *
	 * A token that arrived as an array would mean the URL builder emitted
	 * something the confirm endpoint could never accept, so this fails loudly
	 * instead of stringifying an array to "Array" and letting the assertion
	 * fail somewhere less informative.
	 */
	private static function query_param( string $url, string $key ): string {
		$parsed = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );

		$value = $parsed[ $key ] ?? null;
		if ( ! is_string( $value ) ) {
			throw new \RuntimeException(
				sprintf( 'Expected a string "%s" parameter in %s, got %s.', $key, $url, get_debug_type( $value ) )
			);
		}
		return $value;
	}
}
