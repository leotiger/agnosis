<?php
/**
 * Namespace-scoped extension_loaded() override for
 * PureLaneMediumClassificationTest (an Integration test — real WordPress is
 * loaded, unlike the Unit suite).
 *
 * 2026-07-21, take 2: this originally required Unit/Publishing/Stubs/
 * publishing_namespace_stubs.php directly to get this override, but that
 * file ALSO declares a namespace-scoped get_option() (namespace
 * Agnosis\Publishing) needed by the Unit suite's ResolvePostTypeTest —
 * correct there, but catastrophic once pulled into an Integration run:
 * PHPUnit requires every test file in the configured testsuite directory
 * during discovery, before --filter narrows anything down, so simply having
 * this test class exist alongside that require_once was enough to declare
 * Agnosis\Publishing\get_option() for the REST of the Integration process —
 * permanently shadowing the real global get_option() for every other
 * Agnosis\Publishing-namespaced call site (PostCreator::resolve_post_type(),
 * EmbedPolicy, etc.) with a stub that always returns ''
 * (ResolvePostTypeTest::$options is never populated outside its own Unit
 * test). That silently broke pure@/photo@ routing, embed-policy trust tiers,
 * and anything else in that namespace reading a real option, across the
 * whole Integration suite.
 *
 * The first fix attempt swapped in this dedicated stub file (extension_loaded()
 * only, no get_option()) but still referenced
 * Agnosis\Tests\Unit\Publishing\TextPosterGeneratorTest::$imagick_available_override
 * as the backing flag — merely NAMING that class triggers the autoloader to
 * load TextPosterGeneratorTest.php, which itself has its own
 * `require_once .../Stubs/publishing_namespace_stubs.php` at the top (needed
 * for ITS OWN Unit-suite use) — reintroducing the exact same get_option()
 * collision transitively, plus a "Cannot redeclare extension_loaded()" fatal
 * from the two stub files' overlapping declarations.
 *
 * Fix: this file owns its OWN static flag, now split out into its own
 * ImagickAvailabilityOverride.php (Universal.Files.SeparateFunctionsFromOO
 * forbids mixing a function declaration with an OO structure in one file,
 * and Universal.Namespaces.OneDeclarationPerFile forbids the two-namespace
 * layout this file used at first) instead of reusing anything from the Unit
 * test tree, so requiring this one file has zero transitive dependency on
 * Unit/Publishing/Stubs/publishing_namespace_stubs.php or any class that
 * requires it.
 *
 * This file contains a function declaration only (no OO structures) to
 * satisfy Universal.Files.SeparateFunctionsFromOO — see
 * ImagickAvailabilityOverride.php for the class half.
 *
 * @package Agnosis\Tests\Integration\Publishing\Stubs
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\Tests\Integration\Publishing\Stubs\ImagickAvailabilityOverride;

/**
 * Namespace-scoped extension_loaded() override — reads
 * ImagickAvailabilityOverride::$value.
 *
 *   null  → pass through to the real extension_loaded('imagick') (default).
 *   true  → force "available".
 *   false → force "unavailable", even when Imagick genuinely is installed.
 */
function extension_loaded( string $extension ): bool {
	if ( 'imagick' === $extension && ImagickAvailabilityOverride::$value !== null ) {
		return ImagickAvailabilityOverride::$value;
	}
	return \extension_loaded( $extension );
}
