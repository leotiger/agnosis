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
	 * Once this file is loaded, `linguaforge_languages()` exists as a real
	 * global PHP function for the rest of the process — PHP has no mechanism
	 * to undefine it — so it keeps answering calls from ANY test class that
	 * runs afterward in the same PHPUnit process, not just LinguaForgeCompatTest.
	 * SubmissionTranslator::language_names() checks function_exists() (a
	 * string-name lookup, always global, unaffected by namespaces) and, once
	 * true, trusts this function's return value completely. $lf_languages is
	 * null except while a LinguaForgeCompatTest test method is actively
	 * driving it, so falling back to `[]` here left every *other* Integration
	 * test class that ran after LinguaForgeCompatTest seeing "Lingua Forge is
	 * active but configured with zero languages" — silently emptying
	 * SubmissionTranslator::language_names() and, downstream, both the
	 * newsletter signup language <select> and Admission's locale mapping
	 * (SignupBlockTest::test_language_select_lists_at_least_one_language_option,
	 * SubscriptionTest::test_subscribe_maps_whitelisted_language_to_wp_locale).
	 * `['en']` is a neutral, always-true default that matches what
	 * SubmissionTranslator's own no-LF fallback would offer anyway, so
	 * unrelated tests see one sane language instead of none. Every
	 * LinguaForgeCompatTest test method that cares sets $lf_languages
	 * explicitly before exercising the code under test (including the one
	 * that legitimately wants an empty list — that sets `[]` directly, which
	 * this null-coalesce doesn't touch), so this default only ever applies
	 * to the leaked-into-other-classes case, never to LinguaForgeCompatTest's
	 * own assertions.
	 *
	 * @return string[]
	 */
	function linguaforge_languages(): array {
		return \Agnosis\Tests\Integration\Compat\LinguaForgeCompatTest::$lf_languages ?? [ 'en' ];
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
