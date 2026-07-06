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
 * shell_exec() and exec() are overridden separately, for MediaAdapterTest
 * rather than SubmissionTranslatorTest — see their own docblocks below.
 * shell_exec() reads MediaAdapterTest::$ffmpeg_path_override (null = pass
 * through to the real shell_exec(), i.e. genuinely ask the machine whether
 * ffmpeg is installed); exec() reads MediaAdapterTest::$exec_override (null
 * = pass through to the real exec()). Together these let every branch of
 * MediaAdapter::adapt_video()'s ffmpeg handling — not installed, installed
 * and extraction succeeds, installed but extraction fails — be forced
 * deterministically on any machine, with no dependency on a real ffmpeg
 * binary being present at all.
 *
 * extension_loaded() is overridden the same way for MediaAdapterTest's
 * Imagick-dependent tests (PDF rasterisation, vision-input downscaling), via
 * MediaAdapterTest::$imagick_available_override. Unlike ffmpeg, the "Imagick
 * available" branch also needs a real \Imagick class to instantiate — see
 * the conditional fake Imagick/ImagickPixel/ImagickException classes in
 * dev/bootstrap.php (only defined when the real extension isn't loaded),
 * which this override is designed to work with: forcing "available" makes
 * MediaAdapter proceed to `new \Imagick()`, which resolves to the real
 * extension when installed, or the fake stand-in when not — either way, no
 * test in this suite depends on which machine it happens to run on.
 *
 * @package Agnosis\Tests\Unit\AI\Stubs
 */

declare(strict_types=1);

namespace Agnosis\AI;

use Agnosis\Tests\Unit\AI\MediaAdapterTest;
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

/**
 * Namespace-scoped shell_exec override — exists solely so
 * MediaAdapter::adapt_video()'s ffmpeg-detection probe ("which ffmpeg") can be
 * forced deterministically in tests, regardless of whether ffmpeg is actually
 * installed on the machine running the suite.
 *
 * Without this, a test asserting "no ffmpeg" behaviour can only run truthfully
 * on a machine that genuinely lacks ffmpeg — it has to self-skip everywhere
 * else, since the real shell_exec() would always report ffmpeg as present.
 * Intercepting only the exact "which ffmpeg" probe (and passing every other
 * command straight through to the real global shell_exec()) means that
 * specific test can force the "not installed" branch unconditionally, while
 * every other use of shell_exec()/exec() elsewhere in MediaAdapter — the
 * actual frame-extraction command — is completely unaffected and still talks
 * to the real ffmpeg binary when one is genuinely needed and available.
 *
 * @param string $command Shell command, exactly as MediaAdapter builds it.
 * @return string|null Overridden output for the ffmpeg probe, or the real shell_exec() result for anything else.
 */
function shell_exec( string $command ): ?string {
	if ( MediaAdapterTest::$ffmpeg_path_override !== null && str_starts_with( $command, 'which ffmpeg' ) ) {
		return MediaAdapterTest::$ffmpeg_path_override;
	}
	return \shell_exec( $command );
}

/**
 * Namespace-scoped exec() override — lets MediaAdapterTest deterministically
 * force the *outcome* of MediaAdapter::adapt_video()'s actual ffmpeg
 * frame-extraction command (not just the "which ffmpeg" presence probe
 * shell_exec() fakes above), regardless of whether ffmpeg is genuinely
 * installed on the machine running the suite.
 *
 * Previously this command always hit the real global exec(), which meant the
 * "ffmpeg present and extraction succeeds" test could only pass truthfully on
 * a box that genuinely has a working ffmpeg + lavfi, and had to self-skip
 * everywhere else — and the "ffmpeg present but extraction fails" branch
 * (a real ffmpeg crash, unsupported codec, etc.) had no test at all, since
 * there was no way to force a real ffmpeg binary to fail on demand.
 *
 * Only intercepts the exact frame-extraction command (identified by its
 * `-f image2` flag, unique to adapt_video()) when
 * MediaAdapterTest::$exec_override is set; every other exec() call anywhere
 * else in the codebase passes straight through to the real global exec().
 *
 * MediaAdapterTest::$exec_override:
 *   null      → pass through to the real exec() (default).
 *   'success' → write a minimal-but-valid JPEG (SOI + EOI markers) to the
 *               command's own output path, exactly as a real ffmpeg
 *               extraction would leave behind; simulates exit code 0.
 *   'failure' → write nothing (the output path is left absent, matching a
 *               real ffmpeg crash or "no such filter" failure); simulates a
 *               non-zero exit code.
 *
 * @param string     $command
 * @param array|null $output
 * @param int|null   $return_var
 * @return string|false
 */
function exec( string $command, ?array &$output = [], ?int &$return_var = 0 ): string|false {
	// MediaAdapter::adapt_video() calls exec($cmd, $output, $return) without
	// pre-initialising $output/$return — harmless for the real internal
	// exec(), which is lenient about auto-vivifying undefined by-ref
	// arguments regardless of their target type, but a user-defined function
	// (this one) enforces its declared parameter types strictly even for an
	// auto-vivified null. Nullable types here accept that null, matching the
	// real exec()'s actual leniency instead of erroring on it.
	if ( MediaAdapterTest::$exec_override !== null && str_contains( $command, '-f image2' )
		&& preg_match( "/-f\\s+image2\\s+'([^']+)'/", $command, $m ) ) {
		$out_path = $m[1];

		if ( 'success' === MediaAdapterTest::$exec_override ) {
			// SOI (0xFFD8) + EOI (0xFFD9) — the smallest byte sequence that is
			// still unambiguously a JPEG per the file-format markers
			// adapt_video()'s own caller checks (see MediaAdapterTest's
			// assertion on the leading two bytes). MediaAdapter never
			// decodes this frame itself, only passes it through, so a
			// genuinely renderable image isn't needed for this unit test.
			file_put_contents( $out_path, "\xFF\xD8\xFF\xD9" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$output     = [];
			$return_var = 0;
			return '';
		}

		// 'failure' — deliberately leave $out_path absent so adapt_video()'s
		// is_file() check reports no frame, exactly like a real failed
		// extraction would.
		$output     = [];
		$return_var = 1;
		return false;
	}

	return \exec( $command, $output, $return_var );
}

/**
 * Namespace-scoped extension_loaded() override — lets MediaAdapterTest force
 * MediaAdapter's "is Imagick available" check in either direction,
 * regardless of whether the real `imagick` PHP extension is actually
 * installed on the machine running the suite.
 *
 * Only intercepts the exact 'imagick' argument; every other extension name
 * (there are none elsewhere in this codebase's extension_loaded() calls, but
 * this keeps the override narrowly scoped on principle) passes straight
 * through to the real global extension_loaded() unchanged.
 *
 * MediaAdapterTest::$imagick_available_override:
 *   null  → pass through to the real extension_loaded('imagick') (default) —
 *           genuinely ask the machine.
 *   true  → force "available". MediaAdapter then proceeds to `new \Imagick()`,
 *           which resolves to the real extension when installed, or the
 *           conditional fake defined in dev/bootstrap.php when it isn't —
 *           either way this test suite no longer cares which.
 *   false → force "unavailable", even on a machine where Imagick genuinely
 *           is installed — lets the fallback-path tests run deterministically
 *           everywhere instead of self-skipping whenever Imagick happens to
 *           be present.
 *
 * Deliberately doesn't touch class_exists() at all: MediaAdapter's guard is
 * `!extension_loaded('imagick') || !class_exists(\Imagick::class)`, and since
 * *some* Imagick class (real or the dev/bootstrap.php fake) is always
 * defined in a unit-test process, class_exists() is always true on its own —
 * forcing this one check is sufficient to control both branches.
 *
 * @param string $extension
 * @return bool
 */
function extension_loaded( string $extension ): bool {
	if ( 'imagick' === $extension && MediaAdapterTest::$imagick_available_override !== null ) {
		return MediaAdapterTest::$imagick_available_override;
	}
	return \extension_loaded( $extension );
}
