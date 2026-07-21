<?php
/**
 * Plain static holder for extension_loaded_namespace_stub.php's override
 * flag — deliberately has no dependency on anything in the Unit test tree.
 * See that file's own docblock for the full incident writeup (a previous
 * version of this override reused
 * Agnosis\Tests\Unit\Publishing\TextPosterGeneratorTest::$imagick_available_override,
 * which transitively pulled in a dangerous namespace-scoped get_option()
 * stub via that class's own require_once).
 *
 * Split into its own file (2026-07-21) to satisfy
 * Universal.Files.SeparateFunctionsFromOO / Universal.Namespaces.
 * OneDeclarationPerFile — a single file can't mix a function declaration
 * with an OO structure, or declare two namespaces.
 *
 * @package Agnosis\Tests\Integration\Publishing\Stubs
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing\Stubs;

class ImagickAvailabilityOverride {
	public static ?bool $value = null;
}
