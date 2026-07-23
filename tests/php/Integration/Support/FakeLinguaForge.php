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

	/**
	 * Mirrors linguaforge_get_trid()/linguaforge_set_trid(): post ID => TRID
	 * string. Real Lingua Forge stores this as plain `_lf_trid` postmeta (see
	 * NATIVE-LANGUAGE-PIPELINE.md §4d — "a translation group is nothing more
	 * than a shared _lf_trid value") — this in-memory map is the test-only
	 * equivalent, added for Compat\LinguaForge::sync_native_sibling()
	 * coverage (Phase 6).
	 *
	 * @var array<int, string>
	 */
	public static array $trids = [];

	/** Post IDs passed to linguaforge_clear_translation_cache(), in call order. */
	public static array $cache_cleared_for = [];

	/** Post IDs passed to linguaforge_mark_translation_synced(), in call order. */
	public static array $marked_synced = [];

	/**
	 * Mirrors linguaforge_is_valid_lang(): the site's configured/routed
	 * language codes. Added for
	 * SubdomainNavigation::native_language_url() coverage (2026-07-23) — an
	 * artist's account-locale badge can legitimately name a language the
	 * site was never set up to route (or later dropped), which that method
	 * treats as "nothing to link to."
	 *
	 * @var string[]
	 */
	public static array $valid_langs = [];

	/**
	 * Mirrors linguaforge_lsflr_translate_current_url(): the URL to return
	 * for the non-singular (archive/gallery/home) case. Real Lingua Forge
	 * rewrites the actual current request URL's language segment; this
	 * fake just returns whatever the test sets, since exercising the real
	 * path-rewriting logic isn't this file's own concern — that's Lingua
	 * Forge's, covered in its own test suite.
	 */
	public static string $lsflr_translated_url = '';

	/** Call from setUp()/tearDown() so state never bleeds between tests. */
	public static function reset(): void {
		self::$source_language      = 'en';
		self::$translations         = [];
		self::$trids                = [];
		self::$cache_cleared_for    = [];
		self::$marked_synced        = [];
		self::$valid_langs          = [];
		self::$lsflr_translated_url = '';
	}

	/** Convenience for tests: record post $translated_id as $lang's translation of $original_id. */
	public static function link( int $original_id, string $lang, int $translated_id ): void {
		self::$translations[ $original_id ][ $lang ] = $translated_id;
	}
}
