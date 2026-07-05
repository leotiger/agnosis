<?php
/**
 * Namespace-scoped function overrides for Agnosis\AI unit tests.
 *
 * PHP resolves unqualified function calls in a namespace by first checking the
 * current namespace, then falling back to global. Defining get_option(),
 * get_locale(), apply_filters(), function_exists(), linguaforge_languages(),
 * and linguaforge_language_label() here means any class in Agnosis\AI (e.g.
 * SubmissionTranslator, Pipeline, PromptConfig) will use these versions instead
 * of the global stubs in bootstrap.php or the real Lingua Forge plugin.
 *
 * The function_exists() override exists specifically so "Lingua Forge active
 * vs. not installed" can be flipped per test via an ordinary static property —
 * real PHP functions can never be undefined once declared, so faking the
 * *namespace-relative* function_exists() check SubmissionTranslator makes is
 * the only way to test both branches without leaking global state between
 * tests (or between this suite and the separate LinguaForgeCompatTest
 * integration suite, which stubs the real global functions independently).
 *
 * SubmissionTranslatorTest exposes static properties that these stubs read:
 *
 *   $options              — map of option key → value (null = use $default fallback)
 *   $locale               — value returned by get_locale() (null = 'en_US')
 *   $available_languages  — value returned by get_available_languages() (null = [])
 *   $languages_override   — replacement map for 'agnosis_translation_languages'
 *                           filter (null = pass value through unchanged)
 *   $linguaforge_active   — whether function_exists('linguaforge_languages') /
 *                           ('linguaforge_language_label') reports true (null = true,
 *                           i.e. Lingua Forge active by default — the normal setup)
 *   $linguaforge_languages — codes returned by linguaforge_languages() (null = a
 *                           small realistic default covering the languages this
 *                           test file's non-language-specific tests reference)
 *   $linguaforge_labels   — code => label map read by linguaforge_language_label()
 *                           (null = a default map matching $linguaforge_languages)
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

/** Default active-language set used when a test doesn't care about the exact list. */
const LINGUAFORGE_DEFAULT_LANGUAGES = [ 'en', 'es', 'de', 'fr' ];

/** Default labels matching LINGUAFORGE_DEFAULT_LANGUAGES. */
const LINGUAFORGE_DEFAULT_LABELS = [
	'en' => 'English',
	'es' => 'Spanish',
	'de' => 'German',
	'fr' => 'French',
];

/**
 * Namespace-scoped function_exists override.
 *
 * Only intercepts the two Lingua Forge presence checks SubmissionTranslator
 * makes ('linguaforge_languages', 'linguaforge_language_label') so that
 * "Lingua Forge active" vs. "not installed" is switchable per test via
 * SubmissionTranslatorTest::$linguaforge_active. Every other name is passed
 * straight through to the real global function_exists() so nothing else in
 * SubmissionTranslator (or PHP itself) is affected.
 */
function function_exists( string $function_name ): bool {
	if ( in_array( $function_name, [ 'linguaforge_languages', 'linguaforge_language_label' ], true ) ) {
		return SubmissionTranslatorTest::$linguaforge_active ?? true;
	}
	return \function_exists( $function_name );
}

/**
 * Namespace-scoped linguaforge_languages() stub.
 * Returns SubmissionTranslatorTest::$linguaforge_languages when set, otherwise
 * LINGUAFORGE_DEFAULT_LANGUAGES.
 *
 * @return string[]
 */
function linguaforge_languages(): array {
	return SubmissionTranslatorTest::$linguaforge_languages ?? LINGUAFORGE_DEFAULT_LANGUAGES;
}

/**
 * Namespace-scoped linguaforge_language_label() stub.
 * Returns SubmissionTranslatorTest::$linguaforge_labels[$lang] when set,
 * otherwise LINGUAFORGE_DEFAULT_LABELS[$lang], falling back to the uppercased
 * code — mirroring SubmissionTranslator's own fallback for when
 * linguaforge_language_label() doesn't exist at all.
 */
function linguaforge_language_label( string $lang ): string {
	$labels = SubmissionTranslatorTest::$linguaforge_labels ?? LINGUAFORGE_DEFAULT_LABELS;
	return $labels[ $lang ] ?? strtoupper( $lang );
}
