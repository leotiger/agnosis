<?php
/**
 * Test-only, in-memory stand-in for the handful of linguaforge_*() global
 * functions Agnosis calls directly (Digest::recent_posts()/localized_post(),
 * Scheduler::resolve_lf_lang()), all guarded via function_exists() so a real
 * Lingua Forge install takes over unchanged in production.
 *
 * The *real* Lingua Forge plugin is deliberately NOT loaded in the integration
 * test bootstrap: its Router singleton self-registers a pre_get_posts filter
 * that scopes every secondary WP_Query site-wide once LF_LANG is defined —
 * which it always is, even in this CLI/test context (detect_lang_safe()
 * falls through to the source language rather than returning empty) — so
 * doing so would silently affect every other integration test in the suite
 * that creates posts without _lf_lang meta, not just the newsletter
 * localization tests. This class plus linguaforge-function-stubs.php give
 * Agnosis's own code the exact two function signatures it calls, under full
 * test control, without any of that footprint.
 *
 * @package Agnosis\Tests\Integration\Support
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Support;

class FakeLinguaForge {

	/** Mirrors linguaforge_source_language(). */
	public static string $source_language = 'en';

	/**
	 * Mirrors linguaforge_get_translations(): original post ID => [lang code => translated post ID].
	 *
	 * @var array<int, array<string, int>>
	 */
	public static array $translations = [];

	/** Call from setUp()/tearDown() so state never bleeds between tests. */
	public static function reset(): void {
		self::$source_language = 'en';
		self::$translations    = [];
	}

	/** Convenience for tests: record post $translated_id as $lang's translation of $original_id. */
	public static function link( int $original_id, string $lang, int $translated_id ): void {
		self::$translations[ $original_id ][ $lang ] = $translated_id;
	}
}
