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
 * This file contains a function declaration only (no OO structures) to
 * satisfy Universal.Files.SeparateFunctionsFromOO.
 *
 * Required by: ResolvePostTypeTest.php
 *
 * @package Agnosis\Tests\Unit\Publishing\Stubs
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\Tests\Unit\Publishing\ResolvePostTypeTest;

/**
 * Namespace-scoped get_option() override — reads ResolvePostTypeTest::$options.
 */
function get_option( string $key, mixed $default_value = false ): mixed {
	return ResolvePostTypeTest::$options[ $key ] ?? $default_value;
}
