<?php
/**
 * Global stubs for the Lingua Forge public API.
 *
 * These function definitions stand in for the real Lingua Forge functions so
 * the LF compat integration tests can run without the LF plugin installed.
 * All return values and side-effects are controlled via static properties on
 * LinguaForgeCompatTest, which is defined in the companion test file.
 *
 * This file contains function declarations only (no OO structures) to satisfy
 * Universal.Files.SeparateFunctionsFromOO.
 *
 * Required by: LinguaForgeCompatTest.php
 *
 * @package Agnosis\Tests\Integration\Compat\Stubs
 */

declare(strict_types=1);

// ── LF constants ──────────────────────────────────────────────────────────────
// LINGUAFORGE_VERSION may already be defined by LinguaForgeCompatNoticesTest.
if ( ! defined( 'LINGUAFORGE_VERSION' ) ) {
	define( 'LINGUAFORGE_VERSION', '1.0.0-test' );
}
if ( ! defined( 'LINGUAFORGE_FILE' ) ) {
	define( 'LINGUAFORGE_FILE', '/tmp/linguaforge.php' );
}

// ── LF global function stubs ──────────────────────────────────────────────────

if ( ! function_exists( 'linguaforge_languages' ) ) {
	/**
	 * Stub for LF's language-router public API.
	 * Returns the list configured by the current test via $lf_languages.
	 *
	 * @return string[]
	 */
	function linguaforge_languages(): array {
		return \Agnosis\Tests\Integration\Compat\LinguaForgeCompatTest::$lf_languages ?? [];
	}
}

if ( ! function_exists( 'linguaforge_trigger_translation' ) ) {
	/**
	 * Stub for LF's AI translation trigger.
	 * Records every call in $trigger_calls so tests can assert on them.
	 *
	 * @return int|\WP_Error
	 */
	function linguaforge_trigger_translation( int $post_id, string $target_lang, array $params = [] ): int|\WP_Error {
		\Agnosis\Tests\Integration\Compat\LinguaForgeCompatTest::$trigger_calls[] = [
			'post_id'     => $post_id,
			'target_lang' => $target_lang,
			'params'      => $params,
		];
		$ret = \Agnosis\Tests\Integration\Compat\LinguaForgeCompatTest::$trigger_return;
		return $ret instanceof \WP_Error ? $ret : ( is_int( $ret ) ? $ret : 999 );
	}
}

if ( ! function_exists( 'linguaforge_queue_translation' ) ) {
	/**
	 * Stub for LF's async translation queue (LF 2.4.0+).
	 * Records every call in $queue_calls so tests can assert on them.
	 */
	function linguaforge_queue_translation( int $post_id, string $target_lang, array $params = [] ): void {
		\Agnosis\Tests\Integration\Compat\LinguaForgeCompatTest::$queue_calls[] = [
			'post_id'     => $post_id,
			'target_lang' => $target_lang,
			'params'      => $params,
		];
	}
}
