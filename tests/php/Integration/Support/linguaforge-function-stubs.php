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
