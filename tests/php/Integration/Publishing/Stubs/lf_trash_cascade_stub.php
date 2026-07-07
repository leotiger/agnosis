<?php
/**
 * Global stub for Lingua Forge's linguaforge_trash_translation_group() (LF 2.5.4+).
 *
 * Stands in for the real LF function so RemovalEndpointsIntegrationTest can
 * exercise Publishing\RemovalEndpoints::trash_post_and_translations()'s
 * LF-active branch without the LF plugin installed. Every call is recorded
 * in RemovalEndpointsIntegrationTest::$trash_cascade_calls so tests can
 * assert on the exact arguments passed (in particular, that $check_caps is
 * left at its default false — this is a trusted, token-authenticated caller,
 * not a logged-in wp-admin action).
 *
 * By default this stub actually calls wp_trash_post( $post_id ) and reports
 * the outcome — i.e. it behaves exactly like the real trash_group() would
 * for a post with no configured translation siblings — so every EXISTING
 * RemovalEndpointsIntegrationTest assertion (post ends up in the trash,
 * confirm() reports success) keeps passing once this stub makes
 * function_exists('linguaforge_trash_translation_group') true for the rest
 * of the process. A test that specifically wants to exercise the "cascade
 * reported nothing trashed" edge case sets
 * RemovalEndpointsIntegrationTest::$trash_cascade_return_override instead.
 *
 * Once loaded, this function exists as a real global PHP function for the
 * rest of the process — PHP has no mechanism to undefine it — so it will
 * keep answering calls from any test class that runs afterward in the same
 * PHPUnit process. That's harmless here: nothing outside
 * Publishing\RemovalEndpoints checks for this function's existence.
 *
 * This file contains a function declaration only (no OO structures) to
 * satisfy Universal.Files.SeparateFunctionsFromOO, matching the existing
 * convention in tests/php/Integration/Compat/Stubs/lf_global_stubs.php.
 *
 * Required by: RemovalEndpointsIntegrationTest.php
 *
 * @package Agnosis\Tests\Integration\Publishing\Stubs
 */

declare(strict_types=1);

if ( ! function_exists( 'linguaforge_trash_translation_group' ) ) {
	/**
	 * @return array{trashed:int,skipped:int}
	 */
	function linguaforge_trash_translation_group( int $post_id, bool $check_caps = false ): array {
		\Agnosis\Tests\Integration\Publishing\RemovalEndpointsIntegrationTest::$trash_cascade_calls[] = [
			'post_id'    => $post_id,
			'check_caps' => $check_caps,
		];

		$override = \Agnosis\Tests\Integration\Publishing\RemovalEndpointsIntegrationTest::$trash_cascade_return_override;
		if ( null !== $override ) {
			return $override;
		}

		return [ 'trashed' => wp_trash_post( $post_id ) ? 1 : 0, 'skipped' => 0 ];
	}
}
