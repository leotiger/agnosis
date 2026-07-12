<?php
/**
 * Namespace-scoped function overrides for Agnosis\Publishing unit tests.
 *
 * PHP resolves an unqualified function call in a namespaced file by first
 * checking that same namespace, then falling back to global — declaring
 * get_option() here (namespace Agnosis\Publishing, matching PostCreator.php's
 * own namespace exactly) intercepts PostCreator::resolve_post_type()'s
 * get_option() calls without touching dev/bootstrap.php's global stub (which
 * always returns the default and isn't per-test controllable), same
 * technique Unit/AI/Stubs/ai_namespace_stubs.php already uses for
 * SubmissionTranslator.
 *
 * Reads from ResolvePostTypeTest::$options — a plain array, null-coalesced to
 * $default so any option not explicitly set behaves exactly like the real
 * get_option() would on a fresh install.
 *
 * extension_loaded() is overridden the same way TextPosterGenerator's sibling
 * (Agnosis\AI\MediaAdapter) is in Unit/AI/Stubs/ai_namespace_stubs.php — see
 * that file's own docblock for the full rationale. Reads
 * TextPosterGeneratorTest::$imagick_available_override; only intercepts the
 * exact 'imagick' argument, everything else passes straight through to the
 * real global extension_loaded().
 *
 * This file contains function declarations only (no OO structures) to
 * satisfy Universal.Files.SeparateFunctionsFromOO.
 *
 * Required by: ResolvePostTypeTest.php, TextPosterGeneratorTest.php
 *
 * @package Agnosis\Tests\Unit\Publishing\Stubs
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\Tests\Unit\Publishing\ResolvePostTypeTest;
use Agnosis\Tests\Unit\Publishing\TextPosterGeneratorTest;

/**
 * Namespace-scoped get_option() override — reads ResolvePostTypeTest::$options.
 */
function get_option( string $key, mixed $default_value = false ): mixed {
	return ResolvePostTypeTest::$options[ $key ] ?? $default_value;
}

/**
 * Namespace-scoped extension_loaded() override — reads
 * TextPosterGeneratorTest::$imagick_available_override.
 *
 *   null  → pass through to the real extension_loaded('imagick') (default).
 *   true  → force "available" — TextPosterGenerator::generate() proceeds to
 *           `new \Imagick()`, which resolves to the real extension when
 *           installed, or the conditional fake in dev/bootstrap.php when not.
 *   false → force "unavailable", even on a machine where Imagick genuinely is
 *           installed, so the graceful-degradation branch runs deterministically
 *           everywhere instead of self-skipping.
 */
function extension_loaded( string $extension ): bool {
	if ( 'imagick' === $extension && TextPosterGeneratorTest::$imagick_available_override !== null ) {
		return TextPosterGeneratorTest::$imagick_available_override;
	}
	return \extension_loaded( $extension );
}
