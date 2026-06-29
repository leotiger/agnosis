<?php
/**
 * Namespace-scoped function overrides for Agnosis\AI unit tests.
 *
 * PHP resolves unqualified function calls in a namespace by first checking the
 * current namespace, then falling back to global. Defining get_option(),
 * get_locale(), and apply_filters() here means any class in Agnosis\AI (e.g.
 * SubmissionTranslator, Pipeline, PromptConfig) will use these versions instead
 * of the global stubs in bootstrap.php.
 *
 * SubmissionTranslatorTest exposes static properties that these stubs read:
 *
 *   $options              — map of option key → value (null = use $default fallback)
 *   $locale               — value returned by get_locale() (null = 'en_US')
 *   $available_languages  — value returned by get_available_languages() (null = [])
 *   $languages_override   — replacement map for 'agnosis_translation_languages'
 *                           filter (null = pass value through unchanged)
 *
 * All properties default to null so that existing Pipeline / MediaAdapter /
 * ProviderInterface tests are completely unaffected — the stubs just mirror the
 * global bootstrap behaviour when no overrides are set.
 *
 * @package Agnosis\Tests\Unit\AI\Stubs
 */

declare(strict_types=1);

namespace Agnosis\AI;

use Agnosis\Tests\Unit\AI\SubmissionTranslatorTest;

/**
 * Namespace-scoped get_option override.
 *
 * Returns SubmissionTranslatorTest::$options[$key] when the test has set it;
 * otherwise mirrors the global bootstrap stub (return $fallback).
 */
function get_option( string $key, mixed $fallback = false ): mixed {
	if ( SubmissionTranslatorTest::$options !== null && array_key_exists( $key, SubmissionTranslatorTest::$options ) ) {
		return SubmissionTranslatorTest::$options[ $key ];
	}
	return $fallback;
}

/**
 * Namespace-scoped get_locale override.
 *
 * Returns SubmissionTranslatorTest::$locale when set; otherwise 'en_US'.
 */
function get_locale(): string {
	return SubmissionTranslatorTest::$locale ?? 'en_US';
}

/**
 * Namespace-scoped get_available_languages override.
 *
 * Returns SubmissionTranslatorTest::$available_languages when set; otherwise []
 * (fresh install with no packs — same as WP on a new English-only site).
 *
 * @return string[]  Array of locale strings, e.g. ['es_ES', 'fr_FR'].
 */
function get_available_languages(): array {
	return SubmissionTranslatorTest::$available_languages ?? [];
}

/**
 * Namespace-scoped apply_filters override.
 *
 * When the 'agnosis_translation_languages' filter is invoked and
 * SubmissionTranslatorTest::$languages_override is set, the override map is
 * returned instead of the default LANGUAGE_NAMES const. All other tags pass
 * through unchanged, matching the global bootstrap stub.
 */
function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
	if ( 'agnosis_translation_languages' === $tag && SubmissionTranslatorTest::$languages_override !== null ) {
		return SubmissionTranslatorTest::$languages_override;
	}
	return $value;
}
