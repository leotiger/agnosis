<?php
/**
 * SubmissionTranslator — normalise artist email submissions to the site's primary language.
 *
 * Artists submit their work by email in their native language (Spanish, German,
 * Chinese, etc.). Before the AI pipeline processes their text (subject + body),
 * it must be in the site's primary language so that title, excerpt, body, and
 * tags are published in the correct language.
 *
 * Target language resolution order (mirrors Lingua Forge's own resolution):
 *   1. `linguaforge_primary_language` WordPress option — set in Lingua Forge →
 *      Settings → Primary Language.
 *   2. WordPress site locale (first two characters of `get_locale()`), so an
 *      unconfigured install still behaves sensibly.
 *   3. `'en'` — hard fallback when the locale cannot be resolved to a known code.
 *
 * The translation is performed in a single `chat()` call with a JSON envelope
 * so subject and body are translated together without two round trips. The
 * envelope is stripped on parse. If translation fails (empty or non-JSON
 * response) the original text is preserved — the pipeline always continues,
 * just potentially with untranslated text.
 *
 * @package Agnosis\AI
 */

declare(strict_types=1);

namespace Agnosis\AI;

use Agnosis\AI\Providers\Anthropic;
use Agnosis\AI\Providers\OpenAI;
use Agnosis\AI\Providers\WordPressAI;
use Agnosis\Core\Logger;

class SubmissionTranslator {

	/**
	 * ISO 639-1 → human-readable language name used in prompts.
	 * Mirrors the subset of Lingua Forge's Translation::LANGUAGES that are most
	 * common among international artists; extended via the `agnosis_translation_languages`
	 * filter.
	 *
	 * @var array<string, string>
	 */
	private const LANGUAGE_NAMES = [
		'en'    => 'English',
		'es'    => 'Spanish',
		'pt'    => 'Portuguese',
		'fr'    => 'French',
		'it'    => 'Italian',
		'de'    => 'German',
		'nl'    => 'Dutch',
		'ca'    => 'Catalan',
		'sv'    => 'Swedish',
		'da'    => 'Danish',
		'nb'    => 'Norwegian',
		'fi'    => 'Finnish',
		'pl'    => 'Polish',
		'cs'    => 'Czech',
		'hu'    => 'Hungarian',
		'ro'    => 'Romanian',
		'el'    => 'Greek',
		'uk'    => 'Ukrainian',
		'ru'    => 'Russian',
		'ar'    => 'Arabic',
		'tr'    => 'Turkish',
		'hi'    => 'Hindi',
		'id'    => 'Indonesian',
		'vi'    => 'Vietnamese',
		'th'    => 'Thai',
		'zh'    => 'Chinese (Simplified)',
		'zh-tw' => 'Chinese (Traditional)',
		'ja'    => 'Japanese',
		'ko'    => 'Korean',
	];

	public function __construct( private readonly ProviderInterface $provider ) {}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Return the active language map: ISO 639-1 code → English name.
	 *
	 * Derives the list from the WordPress language packs installed on this site
	 * (get_available_languages() + the site locale) so the join form and any
	 * other language selects only show languages the site can actually handle.
	 * Falls back to the full LANGUAGE_NAMES constant when no packs are installed
	 * (a fresh English-only site) so the form is never empty.
	 *
	 * Filterable via `agnosis_translation_languages` for operator overrides.
	 *
	 * @return array<string, string>  ISO-639-1 code => English name.
	 */
	public static function language_names(): array {
		// Collect every locale the site has a language pack for, plus the site
		// locale itself (en_US is the WP default and has no separate pack).
		$locales = array_merge( get_available_languages(), [ get_locale() ] );

		// Reduce to 2-letter primary subtags ('es_ES' → 'es', 'zh_CN' → 'zh').
		$active_codes = array_unique( array_map(
			static fn( string $locale ): string => strtolower( substr( $locale, 0, 2 ) ),
			$locales
		) );

		// Intersect against our known map to get code → English name pairs.
		$filtered = array_filter(
			self::LANGUAGE_NAMES,
			static fn( string $code ): bool => in_array( $code, $active_codes, true ),
			ARRAY_FILTER_USE_KEY
		);

		// If nothing matched (all packs are for unknown scripts) use the full map.
		$map = ! empty( $filtered ) ? $filtered : self::LANGUAGE_NAMES;

		/** @var array<string, string> */
		return (array) apply_filters( 'agnosis_translation_languages', $map );
	}

	/**
	 * Translate the submission's subject and description to the site's primary
	 * language. Returns the submission array with those keys replaced; all other
	 * keys are passed through unchanged.
	 *
	 * No-ops when:
	 *   • Subject and description are both empty.
	 *   • The resolved target language code is not in the LANGUAGE_NAMES map
	 *     (prevents sending a prompt with an unknown language name).
	 *
	 * @param array<string, mixed> $submission  Parsed email submission.
	 * @return array<string, mixed>             Submission with translated text.
	 */
	public function translate( array $submission ): array {
		$target_code = $this->resolve_target_language();
		$target_name = $this->resolve_language_name( $target_code );

		if ( null === $target_name ) {
			// Unknown language code — skip translation rather than sending a broken prompt.
			Logger::warning(
				sprintf( 'SubmissionTranslator: unknown target language code "%s" — skipping translation.', $target_code ),
				'pipeline'
			);
			return $submission;
		}

		$subject = trim( (string) ( $submission['subject']     ?? '' ) );
		$body    = trim( (string) ( $submission['description'] ?? '' ) );

		if ( '' === $subject && '' === $body ) {
			return $submission; // Nothing to translate.
		}

		$translated = $this->call_translate( $subject, $body, $target_name );

		if ( null === $translated ) {
			// Translation failed — log and return original text.
			Logger::warning( 'SubmissionTranslator: translation call failed or returned non-JSON; using original text.', 'pipeline' );
			return $submission;
		}

		Logger::info(
			sprintf( 'SubmissionTranslator: submission translated to %s.', $target_name ),
			'pipeline'
		);

		// Merge translated fields, leaving all other submission keys intact.
		return array_merge( $submission, array_filter( [
			'subject'     => $translated['subject']     ?? null,
			'description' => $translated['description'] ?? null,
		], static fn( $v ) => $v !== null ) );
	}

	/**
	 * Translate a single piece of text to the given ISO 639-1 target language code.
	 *
	 * Intended for back-translation: converting AI-generated post content (title,
	 * excerpt) from the site's primary language into the artist's preferred language
	 * before including it in a review email.
	 *
	 * No-ops (returns original text) when:
	 *   • $content is empty.
	 *   • $target_code is not in the LANGUAGE_NAMES map.
	 *   • The AI call fails.
	 *
	 * @param string $content     Plain text to translate.
	 * @param string $target_code ISO 639-1 code (e.g. 'es', 'fr', 'zh').
	 * @return string Translated text, or the original on failure.
	 */
	public function translate_text( string $content, string $target_code ): string {
		$content = trim( $content );
		if ( '' === $content ) {
			return $content;
		}

		$target_name = $this->resolve_language_name( $target_code );
		if ( null === $target_name ) {
			Logger::warning(
				sprintf( 'SubmissionTranslator::translate_text: unknown target language code "%s" — skipping.', $target_code ),
				'pipeline'
			);
			return $content;
		}

		// Reuse call_translate() — pass as the body field so the result comes back
		// under the 'description' key.
		$translated = $this->call_translate( '', $content, $target_name );
		return $translated['description'] ?? $content;
	}

	/**
	 * Create a SubmissionTranslator from the site's currently configured AI provider.
	 *
	 * Returns null when no API key is configured so callers can skip translation
	 * gracefully. Uses the same provider option read by Pipeline.
	 */
	public static function from_settings(): ?self {
		$config   = PromptConfig::from_options();
		$provider = (string) get_option( 'agnosis_ai_provider', 'openai' );

		switch ( $provider ) {
			case 'anthropic':
				$key = (string) get_option( 'agnosis_anthropic_api_key', '' );
				if ( '' === $key ) {
					return null;
				}
				$model = (string) get_option( 'agnosis_anthropic_model', 'claude-opus-4-8' );
				return new self( new Anthropic( $key, $config, $model ) );

			case 'wp_ai':
				return new self( new WordPressAI( $config ) );

			case 'openai':
			default:
				$key = (string) get_option( 'agnosis_openai_api_key', '' );
				if ( '' === $key ) {
					return null;
				}
				$model = (string) get_option( 'agnosis_openai_description_model', 'gpt-4o' );
				return new self( new OpenAI( $key, $config, $model ) );
		}
	}

	// -------------------------------------------------------------------------
	// Language resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolve the target language ISO code.
	 *
	 * Priority:
	 *   1. `linguaforge_primary_language` option (Lingua Forge primary language).
	 *   2. First two characters of `get_locale()` (WP site language).
	 *   3. `'en'` as ultimate fallback.
	 */
	public function resolve_target_language(): string {
		// 1. Lingua Forge primary language setting.
		$lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );

		// 2. WP site locale fallback.
		if ( '' === $lang ) {
			$locale = get_locale();
			// get_locale() returns e.g. 'en_US', 'de_DE', 'zh_CN' — take the first two characters.
			$lang = sanitize_key( substr( $locale, 0, 2 ) );
		}

		// 3. Hard fallback.
		return $lang ?: 'en';
	}

	/**
	 * Return the human-readable name for an ISO code, or null if unknown.
	 * Filterable via `agnosis_translation_languages` so operators can add
	 * languages not in the built-in map.
	 */
	private function resolve_language_name( string $code ): ?string {
		/** @var array<string, string> $map */
		$map = (array) apply_filters( 'agnosis_translation_languages', self::LANGUAGE_NAMES );
		return $map[ $code ] ?? null;
	}

	// -------------------------------------------------------------------------
	// Translation call
	// -------------------------------------------------------------------------

	/**
	 * Send a single chat() call that translates both subject and body together.
	 *
	 * Returns an associative array with 'subject' and/or 'description' keys, or
	 * null on failure (empty response, non-JSON, or missing keys).
	 *
	 * The prompt includes both fields in one round trip. Fields that are empty
	 * in the input are omitted from the prompt and from the returned array so
	 * the caller knows not to overwrite them.
	 *
	 * @return array<string, string>|null
	 */
	private function call_translate( string $subject, string $body, string $target_language_name ): ?array {
		$sections = '';
		if ( '' !== $subject ) {
			$sections .= "SUBJECT:\n{$subject}\n\n";
		}
		if ( '' !== $body ) {
			$sections .= "BODY:\n{$body}";
		}

		$keys_present = array_filter( [ 'subject' => $subject !== '', 'body' => $body !== '' ] );
		$json_keys    = implode( ', ', array_map(
			static fn( $k ) => '"' . ( $k === 'body' ? 'description' : $k ) . '"',
			array_keys( $keys_present )
		) );

		$prompt = "Translate the sections below to {$target_language_name}.\n"
			. "If a section is already in {$target_language_name}, include it in the output unchanged.\n"
			. "Return ONLY a JSON object with these keys: {$json_keys}.\n"
			. "No markdown fences. No preamble. No explanation.\n\n"
			. $sections;

		$response = $this->provider->chat( $prompt );

		if ( '' === trim( $response ) ) {
			return null;
		}

		// Strip markdown fences if present.
		$json_str = trim( (string) preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $response ) ) );
		$decoded  = json_decode( $json_str, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$result = [];

		if ( '' !== $subject && isset( $decoded['subject'] ) ) {
			$result['subject'] = sanitize_text_field( (string) $decoded['subject'] );
		}

		if ( '' !== $body && isset( $decoded['description'] ) ) {
			$result['description'] = sanitize_textarea_field( (string) $decoded['description'] );
		}

		return ! empty( $result ) ? $result : null;
	}
}
