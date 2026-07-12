<?php
/**
 * Global function stubs for the handful of linguaforge_*() functions Agnosis
 * calls directly, backed by Agnosis\Tests\Integration\Support\FakeLinguaForge.
 *
 * Required exactly once from tests/php/Integration/bootstrap.php. Guarded by
 * function_exists() so a real Lingua Forge install (which defines the same
 * functions) always takes precedence unchanged.
 *
 * @package Agnosis\Tests\Integration\Support
 */

declare(strict_types=1);

use Agnosis\Tests\Integration\Support\FakeLinguaForge;

if ( ! function_exists( 'linguaforge_source_language' ) ) {
	function linguaforge_source_language(): string {
		return FakeLinguaForge::$source_language;
	}
}

if ( ! function_exists( 'linguaforge_get_translations' ) ) {
	/**
	 * @return array<string, int> Language code => translated post ID.
	 */
	function linguaforge_get_translations( int $post_id ): array {
		return FakeLinguaForge::$translations[ $post_id ] ?? [];
	}
}

// Added for Compat\LinguaForge::sync_native_sibling() coverage (Phase 6,
// agnosis-audit/NATIVE-LANGUAGE-PIPELINE.md). Global (not scoped to any one
// test class) for the same reason the two functions above are: guarded by
// function_exists(), a harmless no-op-by-default recorder for any test that
// never touches FakeLinguaForge's new state, and a real Lingua Forge install
// defining the same names always takes precedence unchanged.

if ( ! function_exists( 'linguaforge_get_trid' ) ) {
	function linguaforge_get_trid( int $post_id ): string {
		return FakeLinguaForge::$trids[ $post_id ] ?? '';
	}
}

if ( ! function_exists( 'linguaforge_set_trid' ) ) {
	function linguaforge_set_trid( int $post_id, string $trid ): void {
		FakeLinguaForge::$trids[ $post_id ] = $trid;
	}
}

if ( ! function_exists( 'linguaforge_clear_translation_cache' ) ) {
	function linguaforge_clear_translation_cache( int $post_id ): void {
		FakeLinguaForge::$cache_cleared_for[] = $post_id;
	}
}

if ( ! function_exists( 'linguaforge_mark_translation_synced' ) ) {
	function linguaforge_mark_translation_synced( int $post_id ): void {
		FakeLinguaForge::$marked_synced[] = $post_id;
	}
}
