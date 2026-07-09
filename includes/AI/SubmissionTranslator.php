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

	public function __construct( private readonly ProviderInterface $provider ) {}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Return the site's active language map: ISO 639-1 code → display name.
	 *
	 * Sourced entirely from Lingua Forge's own configuration — `linguaforge_languages()`
	 * returns exactly the codes this WP instance is set up to route/translate
	 * (Settings → Language Router), and `linguaforge_language_label()` gives each
	 * one a display name. No separate Agnosis-side list is maintained: whatever
	 * Lingua Forge is configured for is what the Join form offers and what the
	 * AI pipeline will attempt to translate — 3 configured languages means 3
	 * options here, 50 means 50. A language enabled in Lingua Forge appears here
	 * automatically; one that isn't never shows up as a false promise.
	 *
	 * Falls back to just the site's own locale when Lingua Forge isn't active,
	 * since there is then no multi-language configuration anywhere to read from.
	 *
	 * Filterable via `agnosis_translation_languages` for operator overrides.
	 *
	 * @return array<string, string>  ISO-639-1 code => display name.
	 */
	public static function language_names(): array {
		if ( function_exists( 'linguaforge_languages' ) ) {
			$map = [];
			foreach ( linguaforge_languages() as $code ) {
				$map[ $code ] = function_exists( 'linguaforge_language_label' )
					? linguaforge_language_label( $code )
					: strtoupper( $code );
			}
		} else {
			// Lingua Forge inactive — nothing to read a language configuration
			// from, so offer just the site's own language rather than an
			// arbitrary guess at what else might be supported.
			$code = sanitize_key( substr( get_locale(), 0, 2 ) ) ?: 'en';
			$map  = [ $code => strtoupper( $code ) ];
		}

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
	 *   • The resolved target language code isn't one Lingua Forge is configured
	 *     for (prevents sending a prompt with an unknown language name).
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
	 *   • $target_code isn't one Lingua Forge is configured for.
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
	 * Translate an arbitrary named set of text fields to $target_code in a
	 * single `chat()` call, returning a same-keyed array of translated
	 * strings.
	 *
	 * Generalises call_translate() (which only ever handles a fixed
	 * subject/description pair) to any field names — used by
	 * `Notification::on_post_drafted()` to batch a review email's title,
	 * excerpt, and body into one round trip (fifth audit §4b) instead of
	 * three separate `translate_text()` calls, each paying its own prompt
	 * envelope.
	 *
	 * Fields that are empty (after trimming) are omitted from both the
	 * prompt and the returned array — same convention `call_translate()`
	 * already uses — so callers should fall back to the original text for
	 * any key missing from the result, exactly as they would for a failed
	 * `translate_text()` call. An entirely failed/unparseable response
	 * returns an empty array; callers can distinguish "nothing needed
	 * translating" from "the call failed" by checking whether $fields was
	 * non-empty going in.
	 *
	 * @param array<string, string> $fields      Field name => plain text.
	 * @param string                $target_code ISO 639-1 code (e.g. 'es', 'fr', 'zh').
	 * @return array<string, string> Field name => translated text, only for
	 *                               fields that were non-empty AND present in
	 *                               the AI's response.
	 */
	public function translate_fields( array $fields, string $target_code ): array {
		$fields = array_filter( $fields, static fn( $v ) => '' !== trim( (string) $v ) );
		if ( empty( $fields ) ) {
			return [];
		}

		$target_name = $this->resolve_language_name( $target_code );
		if ( null === $target_name ) {
			Logger::warning(
				sprintf( 'SubmissionTranslator::translate_fields: unknown target language code "%s" — skipping.', $target_code ),
				'pipeline'
			);
			return [];
		}

		$sections = '';
		foreach ( $fields as $key => $text ) {
			$sections .= strtoupper( $key ) . ":\n" . trim( (string) $text ) . "\n\n";
		}

		$json_keys = implode( ', ', array_map( static fn( $k ) => '"' . $k . '"', array_keys( $fields ) ) );

		$prompt = "Translate the sections below to {$target_name}.\n"
			. "If a section is already in {$target_name}, include it in the output unchanged.\n"
			. "Return ONLY a JSON object with these keys: {$json_keys}.\n"
			. "No markdown fences. No preamble. No explanation.\n\n"
			. trim( $sections );

		$response = $this->provider->chat( $prompt );

		if ( '' === trim( $response ) ) {
			return [];
		}

		// Strip markdown fences if present — same tolerance as call_translate().
		$json_str = trim( (string) preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $response ) ) );
		$decoded  = json_decode( $json_str, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$result = [];
		foreach ( array_keys( $fields ) as $key ) {
			if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
				$result[ $key ] = sanitize_textarea_field( $decoded[ $key ] );
			}
		}

		return $result;
	}

	/**
	 * Translate a single piece of text into MULTIPLE target languages in one
	 * `chat()` call, returning a language-code-keyed array of translations
	 * (fifth audit §4d). Generalises the same JSON-envelope pattern
	 * translate_fields() uses for "many fields, one language" to the
	 * opposite axis — "one field, many languages" — used by
	 * Compat\LinguaForge::build_title_translations() to translate an
	 * artwork's primary title into every enabled site language in a single
	 * round trip instead of one translate_text() call per language.
	 *
	 * Unknown/unconfigured target codes (resolve_language_name() returns
	 * null) are silently dropped from the prompt and the result — same
	 * "skip rather than send a broken prompt" convention every other method
	 * here already uses. A translation identical to the input (e.g. the
	 * target language matches the source, or the model just echoed it back)
	 * is also dropped from the result, mirroring build_title_translations()'s
	 * own prior per-language "only store an actual change" check.
	 *
	 * @param string   $text         Plain text to translate.
	 * @param string[] $target_codes ISO 639-1 codes (e.g. ['es', 'fr', 'zh']).
	 * @return array<string, string> Target code => translated text, only for
	 *                               codes that were valid, present in the AI's
	 *                               response, and different from $text.
	 */
	public function translate_to_languages( string $text, array $target_codes ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return [];
		}

		$names = [];
		foreach ( array_unique( $target_codes ) as $code ) {
			$name = $this->resolve_language_name( $code );
			if ( null !== $name ) {
				$names[ $code ] = $name;
			} else {
				Logger::warning(
					sprintf( 'SubmissionTranslator::translate_to_languages: unknown target language code "%s" — skipping.', $code ),
					'pipeline'
				);
			}
		}

		if ( empty( $names ) ) {
			return [];
		}

		$lang_list = implode( ', ', array_map(
			static fn( string $code, string $name ) => "{$code} ({$name})",
			array_keys( $names ),
			array_values( $names )
		) );
		$json_keys = implode( ', ', array_map( static fn( string $code ) => '"' . $code . '"', array_keys( $names ) ) );

		$prompt = "Translate the text below into EACH of these languages: {$lang_list}.\n"
			. "Return ONLY a JSON object whose keys are exactly these language codes: {$json_keys}, and whose values are the translated text for that language.\n"
			. "No markdown fences. No preamble. No explanation.\n\n"
			. "TEXT:\n{$text}";

		$response = $this->provider->chat( $prompt );

		if ( '' === trim( $response ) ) {
			return [];
		}

		// Strip markdown fences if present — same tolerance as call_translate()/translate_fields().
		$json_str = trim( (string) preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $response ) ) );
		$decoded  = json_decode( $json_str, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$result = [];
		foreach ( array_keys( $names ) as $code ) {
			if ( ! isset( $decoded[ $code ] ) || ! is_string( $decoded[ $code ] ) ) {
				continue;
			}
			$translated = sanitize_text_field( $decoded[ $code ] );
			if ( '' !== $translated && $translated !== $text ) {
				$result[ $code ] = $translated;
			}
		}

		return $result;
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
	 *
	 * Reuses language_names() so "known language" means exactly the same thing
	 * everywhere: the Join form dropdown, this translation check, and
	 * translate_text()'s back-translation all agree with Lingua Forge's own
	 * active language configuration rather than three separately-maintained lists.
	 */
	private function resolve_language_name( string $code ): ?string {
		return self::language_names()[ $code ] ?? null;
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
