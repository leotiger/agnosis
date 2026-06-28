<?php
/**
 * Unit tests for SubmissionTranslator.
 *
 * SubmissionTranslator wraps a ProviderInterface and translates the subject +
 * description of an artist email to the site's primary language before the AI
 * pipeline processes it.
 *
 * WP function calls (get_option, get_locale, apply_filters) are intercepted by
 * the namespace stubs in Stubs/ai_namespace_stubs.php, which read the static
 * properties on this class. All properties are reset in tearDown() so no test
 * bleeds into another.
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

	/** @var array<string, string>|null Replacement map for 'agnosis_translation_languages'; null = passthrough. */
	public static ?array $languages_override = null;

	protected function tearDown(): void {
		self::$options            = null;
		self::$locale             = null;
		self::$languages_override = null;
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
		// 'xx' is not in LANGUAGE_NAMES and no filter override is set.
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
}
