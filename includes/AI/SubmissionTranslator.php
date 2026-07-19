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
	 * Appended to every JSON-envelope translation prompt below (call_translate(),
	 * translate_fields(), translate_to_languages()) — 2026-07-18, prompted by a
	 * live report: the German translation of a short preset biography title
	 * ("Meet the Artist") came back gendered feminine ("...die Künstlerin")
	 * with nothing in the prompt ever asking for anything else. Many
	 * languages Agnosis translates into (German, French, Spanish, etc.)
	 * grammatically require SOME gender choice for nouns like "artist" or
	 * "author" that English leaves unmarked — left unguided, a model has to
	 * pick one, and defaulting to a specific gender for a generic person is
	 * exactly the failure mode this instruction heads off. Deliberately
	 * phrased as a preference ("prefer... where natural"), not an absolute
	 * rule: a source text that already names or clearly implies a specific
	 * person's gender should still translate that faithfully, not be forced
	 * neutral against the source's own meaning.
	 */
	private const GENDER_NEUTRAL_INSTRUCTION =
		'When a term\'s gender is not specified by the source text (e.g. a generic '
		. 'professional noun like "artist", "author", or "photographer"), prefer '
		. 'gender-neutral phrasing in the target language where natural, rather '
		. 'than defaulting to a masculine or feminine form.';

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
	 * @param string $context     Optional extra framing for the AI, appended to
	 *                            the prompt as-is (e.g. "this is a short page
	 *                            heading, not a sentence"). A caller translating
	 *                            a short, context-free phrase — the exact shape
	 *                            that produced the ungendered/ungrammatical
	 *                            "Meet the Artist" → German failure this param
	 *                            was added for — should supply one; back-
	 *                            translating a full sentence/paragraph for a
	 *                            review email generally doesn't need to.
	 * @return string Translated text, or the original on failure.
	 */
	public function translate_text( string $content, string $target_code, string $context = '' ): string {
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
		$translated = $this->call_translate( '', $content, $target_name, $context );
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
	 * $field_instructions lets a caller attach an extra instruction line to
	 * ONE specific field's own section, without a second `chat()` call —
	 * added for Publishing\ReviewEndpoints::translate_native_content_to_primary()'s
	 * 'tags' field: rather than a separate reconciliation call after
	 * translating, the existing-tag vocabulary and a "reuse exact existing
	 * text when it fits" instruction are folded into THIS SAME call,
	 * preserving the one-batched-call-per-approval invariant
	 * NATIVE-LANGUAGE-PIPELINE.md §7 is built around and
	 * ReviewEndpointsNativeLanguagePipelineTest asserts directly. Same trust
	 * model the `medium` field already uses elsewhere ("pick exactly one
	 * from: …") — a bounded copy-or-translate choice within one prompt, not
	 * an independently-derived translation matched against a list it never
	 * saw.
	 *
	 * @param array<string, string> $fields             Field name => plain text.
	 * @param string                $target_code        ISO 639-1 code (e.g. 'es', 'fr', 'zh').
	 * @param array<string, string> $field_instructions Field name => extra
	 *                                                   instruction text inserted
	 *                                                   into that field's own
	 *                                                   section. Optional —
	 *                                                   most callers pass nothing.
	 * @return array<string, string> Field name => translated text, only for
	 *                               fields that were non-empty AND present in
	 *                               the AI's response.
	 */
	public function translate_fields( array $fields, string $target_code, array $field_instructions = [] ): array {
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
			$instruction = trim( (string) ( $field_instructions[ $key ] ?? '' ) );
			$sections   .= strtoupper( $key ) . ":\n"
				. ( '' !== $instruction ? $instruction . "\n" : '' )
				. trim( (string) $text ) . "\n\n";
		}

		$json_keys = implode( ', ', array_map( static fn( $k ) => '"' . $k . '"', array_keys( $fields ) ) );

		$prompt = "Translate the sections below to {$target_name}.\n"
			. "If a section is already in {$target_name}, include it in the output unchanged.\n"
			. self::GENDER_NEUTRAL_INSTRUCTION . "\n"
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
			$this->log_json_decode_failure( 'translate_fields', $json_str, $target_code, count( $fields ) . ' field(s)' );
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
			. self::GENDER_NEUTRAL_INSTRUCTION . "\n"
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
			$this->log_json_decode_failure( 'translate_to_languages', $json_str, implode( ', ', array_keys( $names ) ), '1 text' );
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
				// $model (the vision model) is passed for parity with the
				// constructor's other callers but is inert here — this class
				// only ever calls chat(), never describe(). $text_model is
				// the one that actually matters (audit §5c): previously
				// chat() ignored whatever model was configured entirely and
				// used a hardcoded literal instead.
				$model      = (string) get_option( 'agnosis_anthropic_model', 'claude-opus-4-8' );
				$text_model = (string) get_option( 'agnosis_anthropic_text_model', 'claude-haiku-4-5-20251001' );
				return new self( new Anthropic( $key, $config, $model, $text_model ) );

			case 'wp_ai':
				return new self( new WordPressAI( $config ) );

			case 'openai':
			default:
				$key = (string) get_option( 'agnosis_openai_api_key', '' );
				if ( '' === $key ) {
					return null;
				}
				// Same as the Anthropic branch above — $model is inert here.
				$model      = (string) get_option( 'agnosis_openai_description_model', 'gpt-4o' );
				$text_model = (string) get_option( 'agnosis_openai_text_model', 'gpt-4o-mini' );
				return new self( new OpenAI( $key, $config, $model, text_model: $text_model ) );
		}
	}

	// -------------------------------------------------------------------------
	// Language resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolve an artist's own language code (ISO 639-1) from their WP user locale.
	 *
	 * Single source of truth for "what language does this artist write in" —
	 * used by the native-first AI pipeline (Pipeline::process(), instructing
	 * the description AI to reply in the artist's own language),
	 * PostCreator::create_post() (persisting `_agnosis_native_lang` once at
	 * intake), and ReviewConfirm (display/back-translation decisions) — three
	 * call sites that previously risked drifting apart with their own copies
	 * of this same `substr( $locale, 0, 2 )` conversion.
	 *
	 * Returns '' when the artist has no declared locale (nothing to resolve) —
	 * callers should treat that the same as "language unknown," same
	 * graceful-degradation convention every other resolution method here uses.
	 */
	public static function resolve_artist_lang( int $artist_id ): string {
		if ( ! $artist_id ) {
			return '';
		}
		$locale = (string) get_user_meta( $artist_id, 'locale', true );
		if ( '' === $locale ) {
			return '';
		}
		return strtolower( substr( $locale, 0, 2 ) );
	}

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
	 * @param string $context Optional extra framing appended to the prompt —
	 *                        see translate_text()'s own docblock for why this exists.
	 * @return array<string, string>|null
	 */
	private function call_translate( string $subject, string $body, string $target_language_name, string $context = '' ): ?array {
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
			. self::GENDER_NEUTRAL_INSTRUCTION . "\n"
			. ( '' !== $context ? trim( $context ) . "\n" : '' )
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
			$this->log_json_decode_failure( 'call_translate', $json_str, $target_language_name, implode( '+', array_keys( $keys_present ) ) );
			return null;
		}

		$result = [];

		// is_string() guard (not a blind (string) cast): if the model's JSON
		// response ever has 'subject'/'description' as a non-scalar (an array
		// or nested object — seen in practice translating a single short word
		// like a biography's preset title, "About", with no surrounding
		// sentence context to anchor the model's response shape), casting
		// an array to string doesn't fail loudly — it silently produces the
		// literal string "Array" (with a PHP notice, easy to miss in
		// production), which then gets published as real, user-visible
		// content (e.g. "Array — Cal Talaia" surfaced 2026-07-13 via
		// Artist\BiographyTitle::translate_for_sibling(), the first caller of
		// translate_text() to feed it a single bare word). Treating a
		// non-string response as a failed field — same as the missing-key
		// case just below each check — means every caller's existing
		// "fall back to the original text" convention (translate_text(),
		// translate_fields(), the callers in Compat\LinguaForge) applies
		// here too, instead of ever publishing "Array" as if it were a
		// genuine translation.
		if ( '' !== $subject && isset( $decoded['subject'] ) && is_string( $decoded['subject'] ) ) {
			$result['subject'] = sanitize_text_field( $decoded['subject'] );
		}

		if ( '' !== $body && isset( $decoded['description'] ) && is_string( $decoded['description'] ) ) {
			$result['description'] = sanitize_textarea_field( $decoded['description'] );
		}

		return ! empty( $result ) ? $result : null;
	}

	/**
	 * Log a JSON-decode failure with a distinct marker for the likely
	 * truncation case (audit §5b), so it surfaces in Settings → Logs as
	 * itself rather than as an unexplained, generic translation failure —
	 * previously none of the three JSON-envelope call sites above logged
	 * anything at all on decode failure, silently falling back to the
	 * original untranslated text.
	 *
	 * Distinguishing "truncated mid-response" from "the model returned
	 * something else entirely" doesn't require the provider's own
	 * finish_reason/stop_reason (threading that through would mean widening
	 * ProviderInterface::chat()'s string return type everywhere it's used,
	 * including Pipeline.php's own six call sites and every test double —
	 * far more invasive than this finding calls for). A cheap, reliable
	 * proxy instead: a response only counts as "likely truncated" when it
	 * actually looks like it STARTED as the requested JSON object (begins
	 * with "{") but doesn't END with the matching closing brace — cut off
	 * mid-object. Checking the closing brace alone isn't enough: a response
	 * that never was JSON in the first place (the model refused, or replied
	 * with plain prose) also won't end in "}", but that's a different
	 * failure than truncation and would be mislabeled without the opening-
	 * brace check too.
	 *
	 * @param string $method       Calling method name, for the log message.
	 * @param string $json_str     The fence-stripped string that failed to decode.
	 * @param string $target       Target language name/code(s), for context.
	 * @param string $field_summary Short description of what was being translated.
	 */
	private function log_json_decode_failure( string $method, string $json_str, string $target, string $field_summary ): void {
		$likely_truncated = '' !== $json_str && '{' === $json_str[0] && '}' !== substr( $json_str, -1 );

		Logger::warning(
			sprintf(
				'SubmissionTranslator::%s: AI response was not valid JSON (%s) — target "%s", %s. Falling back to the original untranslated text.',
				$method,
				$likely_truncated
					? 'looks truncated — response does not end with a closing brace; consider whether max_tokens needs raising further'
					: 'malformed or unexpected response shape, not a truncation',
				$target,
				$field_summary
			),
			'pipeline'
		);
	}
}
