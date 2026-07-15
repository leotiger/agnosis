<?php
/**
 * Unit tests for SubmissionTranslator.
 *
 * SubmissionTranslator wraps a ProviderInterface and translates the subject +
 * description of an artist email to the site's primary language before the AI
 * pipeline processes it.
 *
 * WP function calls (get_option, get_locale, apply_filters) plus Lingua Forge's
 * public functions (function_exists, linguaforge_languages,
 * linguaforge_language_label) are intercepted by the namespace stubs in
 * Stubs/ai_namespace_stubs.php, which read the static properties on this
 * class. All properties are reset in tearDown() so no test bleeds into another.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\ProviderInterface;
use Agnosis\AI\SubmissionTranslator;
use PHPUnit\Framework\TestCase;

class SubmissionTranslatorTest extends TestCase {

	// -------------------------------------------------------------------------
	// Namespace-stub controls (read by ai_namespace_stubs.php)
	// -------------------------------------------------------------------------

	/** @var array<string, mixed>|null Option key → value overrides; null = return $default. */
	public static ?array $options = null;

	/** @var string|null Locale returned by get_locale(); null = 'en_US'. */
	public static ?string $locale = null;

	/** @var string[]|null Locales returned by get_available_languages(); null = []. */
	public static ?array $available_languages = null;

	/** @var array<string, string>|null Replacement map for 'agnosis_translation_languages'; null = passthrough. */
	public static ?array $languages_override = null;

	/** @var bool|null Whether Lingua Forge's functions "exist"; null = true (active by default). */
	public static ?bool $linguaforge_active = null;

	/** @var string[]|null Codes returned by linguaforge_languages(); null = built-in default. */
	public static ?array $linguaforge_languages = null;

	/** @var array<string, string>|null Labels read by linguaforge_language_label(); null = built-in default. */
	public static ?array $linguaforge_labels = null;

	protected function tearDown(): void {
		self::$options              = null;
		self::$locale               = null;
		self::$available_languages  = null;
		self::$languages_override   = null;
		self::$linguaforge_active   = null;
		self::$linguaforge_languages = null;
		self::$linguaforge_labels    = null;
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	private function make_translator( ProviderInterface $provider ): SubmissionTranslator {
		return new SubmissionTranslator( $provider );
	}

	// -------------------------------------------------------------------------
	// resolve_target_language()
	// -------------------------------------------------------------------------

	public function test_returns_lf_option_when_set(): void {
		self::$options = [ 'linguaforge_primary_language' => 'de' ];

		$lang = $this->make_translator( $this->createMock( ProviderInterface::class ) )
			->resolve_target_language();

		$this->assertSame( 'de', $lang );
	}

	public function test_falls_back_to_first_two_chars_of_locale_when_lf_option_empty(): void {
		self::$options = [ 'linguaforge_primary_language' => '' ];
		self::$locale  = 'fr_FR';

		$lang = $this->make_translator( $this->createMock( ProviderInterface::class ) )
			->resolve_target_language();

		$this->assertSame( 'fr', $lang );
	}

	public function test_falls_back_to_en_when_both_lf_option_and_locale_are_empty(): void {
		self::$options = [ 'linguaforge_primary_language' => '' ];
		self::$locale  = '';

		$lang = $this->make_translator( $this->createMock( ProviderInterface::class ) )
			->resolve_target_language();

		$this->assertSame( 'en', $lang );
	}

	// -------------------------------------------------------------------------
	// translate() — passthrough / short-circuit
	// -------------------------------------------------------------------------

	public function test_empty_subject_and_body_returns_original_without_chat_call(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$submission = [ 'subject' => '', 'description' => '', 'attachments' => [] ];
		$result     = $this->make_translator( $provider )->translate( $submission );

		$this->assertSame( $submission, $result );
	}

	public function test_unknown_language_code_returns_original_without_chat_call(): void {
		// 'xx' is not in the active Lingua Forge language list and no filter override is set.
		self::$options = [ 'linguaforge_primary_language' => 'xx' ];

		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$submission = [ 'subject' => 'Hola', 'description' => 'Texto.' ];
		$result     = $this->make_translator( $provider )->translate( $submission );

		$this->assertSame( $submission, $result );
	}

	// -------------------------------------------------------------------------
	// translate() — happy path
	// -------------------------------------------------------------------------

	public function test_valid_json_updates_subject_and_description(): void {
		// Default target language resolves to 'en' (LF option empty, locale 'en_US').
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn(
			'{"subject":"Translated subject","description":"Translated description."}'
		);

		$submission = [
			'subject'     => 'Hola',
			'description' => 'Este es el cuerpo.',
			'extra'       => 'preserved',
		];
		$result = $this->make_translator( $provider )->translate( $submission );

		$this->assertSame( 'Translated subject', $result['subject'] );
		$this->assertSame( 'Translated description.', $result['description'] );
		$this->assertSame( 'preserved', $result['extra'] ); // unrelated keys pass through
	}

	public function test_body_only_submission_updates_only_description(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"description":"Translated body."}' );

		$result = $this->make_translator( $provider )->translate( [
			'subject'     => '',
			'description' => 'Este es el cuerpo.',
		] );

		$this->assertSame( '', $result['subject'] );
		$this->assertSame( 'Translated body.', $result['description'] );
	}

	public function test_subject_only_submission_updates_only_subject(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"subject":"Translated subject."}' );

		$result = $this->make_translator( $provider )->translate( [
			'subject'     => 'Hola',
			'description' => '',
		] );

		$this->assertSame( 'Translated subject.', $result['subject'] );
		$this->assertSame( '', $result['description'] );
	}

	public function test_target_language_name_appears_in_chat_prompt(): void {
		self::$options = [ 'linguaforge_primary_language' => 'de' ];

		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'German' ) )
			->willReturn( '{"subject":"Übersetzt","description":"Übersetzter Text."}' );

		$this->make_translator( $provider )->translate( [
			'subject'     => 'Hello',
			'description' => 'Some body text.',
		] );
	}

	public function test_strips_markdown_fences_from_chat_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn(
			"```json\n{\"subject\":\"Fenced subject\",\"description\":\"Fenced body.\"}\n```"
		);

		$result = $this->make_translator( $provider )->translate( [
			'subject'     => 'Hola',
			'description' => 'Texto.',
		] );

		$this->assertSame( 'Fenced subject', $result['subject'] );
		$this->assertSame( 'Fenced body.', $result['description'] );
	}

	// -------------------------------------------------------------------------
	// translate() — failure paths (original submission preserved)
	// -------------------------------------------------------------------------

	public function test_empty_chat_response_returns_original_submission(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '' );

		$submission = [ 'subject' => 'Hola', 'description' => 'Mundo.' ];
		$result     = $this->make_translator( $provider )->translate( $submission );

		$this->assertSame( $submission, $result );
	}

	public function test_non_json_chat_response_returns_original_submission(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( 'Sorry, I cannot help with that.' );

		$submission = [ 'subject' => 'Hola', 'description' => 'Mundo.' ];
		$result     = $this->make_translator( $provider )->translate( $submission );

		$this->assertSame( $submission, $result );
	}

	// -------------------------------------------------------------------------
	// translate_text()
	// -------------------------------------------------------------------------

	public function test_translate_text_returns_empty_string_unchanged(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$this->assertSame( '', $this->make_translator( $provider )->translate_text( '', 'es' ) );
	}

	public function test_translate_text_returns_original_for_unknown_code(): void {
		// 'xx' is not in the active Lingua Forge language list and no filter override is active.
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_translator( $provider )->translate_text( 'Hello world', 'xx' );
		$this->assertSame( 'Hello world', $result );
	}

	public function test_translate_text_returns_translated_content(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"description":"Hola mundo"}' );

		$result = $this->make_translator( $provider )->translate_text( 'Hello world', 'es' );
		$this->assertSame( 'Hola mundo', $result );
	}

	public function test_translate_text_includes_target_language_in_prompt(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'French' ) )
			->willReturn( '{"description":"Bonjour monde"}' );

		$this->make_translator( $provider )->translate_text( 'Hello world', 'fr' );
	}

	public function test_translate_text_returns_original_on_empty_provider_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '' );

		$result = $this->make_translator( $provider )->translate_text( 'Hello world', 'es' );
		$this->assertSame( 'Hello world', $result );
	}

	public function test_translate_text_returns_original_on_invalid_json_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( 'Lo siento, no puedo ayudar.' );

		$result = $this->make_translator( $provider )->translate_text( 'Hello world', 'es' );
		$this->assertSame( 'Hello world', $result );
	}

	// audit §5b: a response that starts like the requested JSON object but
	// was cut off mid-object (no closing brace) — the max_tokens-truncation
	// case. Must still fail gracefully, same as any other bad response;
	// log_json_decode_failure()'s own truncation-vs-malformed distinction is
	// purely for the Settings → Logs entry, not the return value.
	public function test_translate_text_returns_original_on_truncated_json_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"description":"Hola mun' );

		$result = $this->make_translator( $provider )->translate_text( 'Hello world', 'es' );
		$this->assertSame( 'Hello world', $result );
	}

	// -------------------------------------------------------------------------
	// from_settings()
	// -------------------------------------------------------------------------

	public function test_from_settings_returns_null_when_openai_key_not_configured(): void {
		self::$options = [
			'agnosis_ai_provider'    => 'openai',
			'agnosis_openai_api_key' => '',
		];

		$this->assertNull( SubmissionTranslator::from_settings() );
	}

	public function test_from_settings_returns_instance_when_openai_key_set(): void {
		self::$options = [
			'agnosis_ai_provider'    => 'openai',
			'agnosis_openai_api_key' => 'sk-test-key-abc123',
		];

		$this->assertInstanceOf( SubmissionTranslator::class, SubmissionTranslator::from_settings() );
	}

	public function test_from_settings_returns_null_when_anthropic_selected_but_key_missing(): void {
		self::$options = [
			'agnosis_ai_provider'       => 'anthropic',
			'agnosis_anthropic_api_key' => '',
		];

		$this->assertNull( SubmissionTranslator::from_settings() );
	}

	public function test_from_settings_returns_instance_when_anthropic_key_set(): void {
		self::$options = [
			'agnosis_ai_provider'       => 'anthropic',
			'agnosis_anthropic_api_key' => 'sk-ant-test-key',
		];

		$this->assertInstanceOf( SubmissionTranslator::class, SubmissionTranslator::from_settings() );
	}

	public function test_from_settings_returns_instance_for_wp_ai_provider(): void {
		// WordPressAI requires no API key so it always returns an instance.
		self::$options = [
			'agnosis_ai_provider' => 'wp_ai',
		];

		$this->assertInstanceOf( SubmissionTranslator::class, SubmissionTranslator::from_settings() );
	}

	// -------------------------------------------------------------------------
	// from_settings() — audit §5c: text_model actually threaded through to
	// the constructed provider, not just the vision model. Reads the
	// provider's own private readonly $text_model property via Reflection,
	// since SubmissionTranslator wraps it and exposes no getter.
	// -------------------------------------------------------------------------

	private function wrapped_provider_text_model( SubmissionTranslator $translator ): string {
		$provider_property = ( new \ReflectionClass( $translator ) )->getProperty( 'provider' );
		$provider_property->setAccessible( true );
		$provider = $provider_property->getValue( $translator );

		$text_model_property = ( new \ReflectionClass( $provider ) )->getProperty( 'text_model' );
		$text_model_property->setAccessible( true );
		return $text_model_property->getValue( $provider );
	}

	public function test_from_settings_passes_the_configured_openai_text_model_to_the_provider(): void {
		self::$options = [
			'agnosis_ai_provider'        => 'openai',
			'agnosis_openai_api_key'     => 'sk-test-key',
			'agnosis_openai_text_model'  => 'gpt-5-nano',
		];

		$translator = SubmissionTranslator::from_settings();
		$this->assertSame( 'gpt-5-nano', $this->wrapped_provider_text_model( $translator ) );
	}

	public function test_from_settings_defaults_the_openai_text_model_when_unconfigured(): void {
		self::$options = [
			'agnosis_ai_provider'    => 'openai',
			'agnosis_openai_api_key' => 'sk-test-key',
		];

		$translator = SubmissionTranslator::from_settings();
		$this->assertSame( 'gpt-4o-mini', $this->wrapped_provider_text_model( $translator ) );
	}

	public function test_from_settings_passes_the_configured_anthropic_text_model_to_the_provider(): void {
		self::$options = [
			'agnosis_ai_provider'          => 'anthropic',
			'agnosis_anthropic_api_key'    => 'sk-ant-test-key',
			'agnosis_anthropic_text_model' => 'claude-haiku-5',
		];

		$translator = SubmissionTranslator::from_settings();
		$this->assertSame( 'claude-haiku-5', $this->wrapped_provider_text_model( $translator ) );
	}

	public function test_from_settings_defaults_the_anthropic_text_model_when_unconfigured(): void {
		self::$options = [
			'agnosis_ai_provider'       => 'anthropic',
			'agnosis_anthropic_api_key' => 'sk-ant-test-key',
		];

		$translator = SubmissionTranslator::from_settings();
		$this->assertSame( 'claude-haiku-4-5-20251001', $this->wrapped_provider_text_model( $translator ) );
	}

	// -------------------------------------------------------------------------
	// language_names()
	//
	// The active language list is sourced entirely from Lingua Forge
	// (linguaforge_languages() + linguaforge_language_label()) — however many
	// languages Lingua Forge is configured for on this site is exactly how many
	// options appear, nothing curated or intersected on the Agnosis side.
	// -------------------------------------------------------------------------

	public function test_language_names_returns_exactly_what_lingua_forge_reports(): void {
		self::$linguaforge_languages = [ 'en', 'ca', 'km' ];
		self::$linguaforge_labels    = [ 'en' => 'English', 'ca' => 'Catalan', 'km' => 'Khmer' ];

		$names = SubmissionTranslator::language_names();

		$this->assertSame( [ 'en' => 'English', 'ca' => 'Catalan', 'km' => 'Khmer' ], $names );
	}

	public function test_language_names_count_matches_lingua_forge_configured_count(): void {
		// 3 configured languages → 3 options. Not 28, not a curated subset.
		self::$linguaforge_languages = [ 'en', 'es', 'ja' ];

		$this->assertCount( 3, SubmissionTranslator::language_names() );

		// Reconfigure to 5 — the count must track it exactly.
		self::$linguaforge_languages = [ 'en', 'es', 'ja', 'ko', 'th' ];

		$this->assertCount( 5, SubmissionTranslator::language_names() );
	}

	public function test_language_names_uses_uppercased_code_when_label_missing(): void {
		self::$linguaforge_languages = [ 'km' ];
		self::$linguaforge_labels    = []; // No label configured for this code.

		$names = SubmissionTranslator::language_names();

		$this->assertSame( 'KM', $names['km'] );
	}

	public function test_language_names_falls_back_to_site_locale_when_lingua_forge_inactive(): void {
		// Lingua Forge not installed — function_exists() reports false for both
		// of its functions, so there is no multi-language configuration to read.
		self::$linguaforge_active = false;
		self::$locale             = 'de_DE';

		$names = SubmissionTranslator::language_names();

		$this->assertSame( [ 'de' => 'DE' ], $names );
	}

	public function test_language_names_filter_applied_on_top_of_lingua_forge_list(): void {
		self::$linguaforge_languages = [ 'en', 'es' ];
		// Filter replaces the result entirely — tests that apply_filters() is called.
		self::$languages_override   = [ 'zz' => 'Zeta' ];

		$names = SubmissionTranslator::language_names();

		$this->assertSame( [ 'zz' => 'Zeta' ], $names );
	}

	// -------------------------------------------------------------------------
	// agnosis_translation_languages filter
	// -------------------------------------------------------------------------

	public function test_filter_can_add_custom_language_code(): void {
		// 'xx' is not in the built-in map; the filter adds it so translation proceeds.
		self::$options            = [ 'linguaforge_primary_language' => 'xx' ];
		self::$languages_override = [ 'xx' => 'Klingon', 'en' => 'English' ];

		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'Klingon' ) )
			->willReturn( '{"subject":"qapla","description":"majQa."}' );

		$result = $this->make_translator( $provider )->translate( [
			'subject'     => 'Hello',
			'description' => 'World.',
		] );

		$this->assertSame( 'qapla', $result['subject'] );
		$this->assertSame( 'majQa.', $result['description'] );
	}

	// -------------------------------------------------------------------------
	// translate_fields() — fifth audit §4b: many fields, one language, one call
	// -------------------------------------------------------------------------

	public function test_translate_fields_returns_empty_array_for_empty_input_without_chat_call(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$this->assertSame( [], $this->make_translator( $provider )->translate_fields( [], 'es' ) );
	}

	public function test_translate_fields_returns_empty_array_when_every_field_is_blank(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_translator( $provider )->translate_fields(
			[ 'title' => '', 'excerpt' => '   ' ],
			'es'
		);

		$this->assertSame( [], $result );
	}

	public function test_translate_fields_returns_empty_array_for_unknown_language_code_without_chat_call(): void {
		// 'xx' is not in the active Lingua Forge language list and no filter override is set.
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_translator( $provider )->translate_fields( [ 'title' => 'Hello' ], 'xx' );

		$this->assertSame( [], $result );
	}

	public function test_translate_fields_makes_exactly_one_chat_call_for_all_fields(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->willReturn( '{"title":"Titulo","excerpt":"Extracto","body":"Cuerpo."}' );

		$this->make_translator( $provider )->translate_fields(
			[ 'title' => 'Title', 'excerpt' => 'Excerpt', 'body' => 'Body.' ],
			'es'
		);
	}

	public function test_translate_fields_returns_all_translated_keys(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn(
			'{"title":"Titulo","excerpt":"Extracto","body":"Cuerpo."}'
		);

		$result = $this->make_translator( $provider )->translate_fields(
			[ 'title' => 'Title', 'excerpt' => 'Excerpt', 'body' => 'Body.' ],
			'es'
		);

		$this->assertSame(
			[ 'title' => 'Titulo', 'excerpt' => 'Extracto', 'body' => 'Cuerpo.' ],
			$result
		);
	}

	public function test_translate_fields_prompt_includes_uppercased_field_names_and_target_language(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->logicalAnd(
				$this->stringContains( 'TITLE:' ),
				$this->stringContains( 'EXCERPT:' ),
				$this->stringContains( 'Spanish' ),
				$this->stringContains( '"title", "excerpt"' )
			) )
			->willReturn( '{"title":"Titulo","excerpt":"Extracto"}' );

		$this->make_translator( $provider )->translate_fields(
			[ 'title' => 'Title', 'excerpt' => 'Excerpt' ],
			'es'
		);
	}

	public function test_translate_fields_omits_blank_fields_from_the_prompt_and_result(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->logicalNot( $this->stringContains( 'EXCERPT:' ) ) )
			->willReturn( '{"title":"Titulo"}' );

		$result = $this->make_translator( $provider )->translate_fields(
			[ 'title' => 'Title', 'excerpt' => '' ],
			'es'
		);

		$this->assertSame( [ 'title' => 'Titulo' ], $result );
	}

	public function test_translate_fields_omits_keys_missing_from_the_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		// Only "title" comes back — "excerpt" was requested but is absent.
		$provider->method( 'chat' )->willReturn( '{"title":"Titulo"}' );

		$result = $this->make_translator( $provider )->translate_fields(
			[ 'title' => 'Title', 'excerpt' => 'Excerpt' ],
			'es'
		);

		$this->assertSame( [ 'title' => 'Titulo' ], $result );
		$this->assertArrayNotHasKey( 'excerpt', $result );
	}

	public function test_translate_fields_omits_non_string_values_from_the_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"title":"Titulo","excerpt":42}' );

		$result = $this->make_translator( $provider )->translate_fields(
			[ 'title' => 'Title', 'excerpt' => 'Excerpt' ],
			'es'
		);

		$this->assertSame( [ 'title' => 'Titulo' ], $result );
	}

	public function test_translate_fields_returns_empty_array_on_empty_chat_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '' );

		$result = $this->make_translator( $provider )->translate_fields( [ 'title' => 'Title' ], 'es' );

		$this->assertSame( [], $result );
	}

	public function test_translate_fields_returns_empty_array_on_non_json_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( 'Sorry, I cannot help with that.' );

		$result = $this->make_translator( $provider )->translate_fields( [ 'title' => 'Title' ], 'es' );

		$this->assertSame( [], $result );
	}

	// audit §5b: cut-off-mid-object response (opens with "{", no closing
	// brace) — the truncation case log_json_decode_failure() distinguishes
	// from a genuinely malformed response for the Settings → Logs entry.
	public function test_translate_fields_returns_empty_array_on_truncated_json_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"title":"Titulo","excerpt":"Extrac' );

		$result = $this->make_translator( $provider )->translate_fields( [ 'title' => 'Title', 'excerpt' => 'Excerpt' ], 'es' );

		$this->assertSame( [], $result );
	}

	public function test_translate_fields_strips_markdown_fences_from_chat_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( "```json\n{\"title\":\"Titulo\"}\n```" );

		$result = $this->make_translator( $provider )->translate_fields( [ 'title' => 'Title' ], 'es' );

		$this->assertSame( [ 'title' => 'Titulo' ], $result );
	}

	// -------------------------------------------------------------------------
	// translate_to_languages() — fifth audit §4d: one field, many languages, one call
	// -------------------------------------------------------------------------

	public function test_translate_to_languages_returns_empty_array_for_empty_text_without_chat_call(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$this->assertSame( [], $this->make_translator( $provider )->translate_to_languages( '', [ 'es', 'fr' ] ) );
	}

	public function test_translate_to_languages_returns_empty_array_when_every_target_code_is_unknown(): void {
		// Neither 'xx' nor 'yy' is in the active Lingua Forge language list.
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'xx', 'yy' ] );

		$this->assertSame( [], $result );
	}

	public function test_translate_to_languages_makes_exactly_one_chat_call_for_every_target_language(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->willReturn( '{"es":"Hola","fr":"Bonjour","de":"Hallo"}' );

		$this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'es', 'fr', 'de' ] );
	}

	public function test_translate_to_languages_returns_a_translation_for_every_valid_target_code(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"es":"Hola","fr":"Bonjour","de":"Hallo"}' );

		$result = $this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'es', 'fr', 'de' ] );

		$this->assertSame( [ 'es' => 'Hola', 'fr' => 'Bonjour', 'de' => 'Hallo' ], $result );
	}

	public function test_translate_to_languages_drops_unknown_codes_but_keeps_valid_ones(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			// The unknown code must never reach the prompt at all.
			->with( $this->logicalNot( $this->stringContains( 'xx' ) ) )
			->willReturn( '{"es":"Hola"}' );

		$result = $this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'es', 'xx' ] );

		$this->assertSame( [ 'es' => 'Hola' ], $result );
	}

	public function test_translate_to_languages_deduplicates_repeated_target_codes(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->callback( static function ( string $prompt ): bool {
				// 'es (Spanish)' must appear exactly once in the language list,
				// not twice, even though 'es' was passed in twice.
				return 1 === substr_count( $prompt, 'es (Spanish)' );
			} ) )
			->willReturn( '{"es":"Hola"}' );

		$this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'es', 'es' ] );
	}

	public function test_translate_to_languages_drops_a_translation_identical_to_the_source_text(): void {
		$provider = $this->createMock( ProviderInterface::class );
		// The model just echoed the English text back for 'en' (source == target).
		$provider->method( 'chat' )->willReturn( '{"es":"Hola","en":"Hello"}' );

		$result = $this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'es', 'en' ] );

		$this->assertSame( [ 'es' => 'Hola' ], $result );
		$this->assertArrayNotHasKey( 'en', $result );
	}

	public function test_translate_to_languages_drops_empty_string_translations(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"es":"Hola","fr":""}' );

		$result = $this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'es', 'fr' ] );

		$this->assertSame( [ 'es' => 'Hola' ], $result );
	}

	public function test_translate_to_languages_prompt_lists_code_and_name_pairs_and_json_keys(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->logicalAnd(
				$this->stringContains( 'es (Spanish)' ),
				$this->stringContains( 'fr (French)' ),
				$this->stringContains( '"es", "fr"' ),
				$this->stringContains( "TEXT:\nHello" )
			) )
			->willReturn( '{"es":"Hola","fr":"Bonjour"}' );

		$this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'es', 'fr' ] );
	}

	public function test_translate_to_languages_returns_empty_array_on_empty_chat_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '' );

		$result = $this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'es' ] );

		$this->assertSame( [], $result );
	}

	public function test_translate_to_languages_returns_empty_array_on_non_json_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( 'Sorry, I cannot help with that.' );

		$result = $this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'es' ] );

		$this->assertSame( [], $result );
	}

	// audit §5b: same cut-off-mid-object truncation case as translate_fields()'s
	// own regression test above.
	public function test_translate_to_languages_returns_empty_array_on_truncated_json_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"es":"Hola","fr":"Bonj' );

		$result = $this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'es', 'fr' ] );

		$this->assertSame( [], $result );
	}

	public function test_translate_to_languages_strips_markdown_fences_from_chat_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( "```json\n{\"es\":\"Hola\"}\n```" );

		$result = $this->make_translator( $provider )->translate_to_languages( 'Hello', [ 'es' ] );

		$this->assertSame( [ 'es' => 'Hola' ], $result );
	}
}
