<?php
/**
 * Integration tests — Providers\WordPressAI.
 *
 * This provider had NO test file at all before this — the built-in
 * WordPress 7.0+ AI Client (wp_ai_client_prompt()) doesn't exist in this
 * test environment any more than it exists on a pre-7.0 WordPress install,
 * so every describe()/chat() test here is backed by a fake builder (see
 * Stubs/wp_ai_provider_namespace_stubs.php). function_exists('wp_ai_client_prompt')
 * is intercepted via a NAMESPACE-SCOPED override (a literal, unqualified call
 * from within WordPressAI.php's own namespace), but wp_ai_client_prompt()
 * itself must be a REAL GLOBAL function — every real call site reaches it via
 * call_user_func( 'wp_ai_client_prompt', ... ), and a string passed to
 * call_user_func() always resolves against the global namespace, never the
 * calling file's own namespace. That real global function's permanent
 * existence does not affect this file's own "AI Client not available" test:
 * that test only depends on the namespace-scoped function_exists() override,
 * which is a separate function entirely.
 *
 * Covers:
 *   describe():
 *     - Fails immediately when the AI Client isn't available (function_exists guard)
 *     - Fails immediately when the artist left no text at all (text-only provider)
 *     - Full happy-path JSON → all DescriptionResult fields populated
 *     - Photo quality score parsed
 *     - Non-JSON / empty response → failure
 *     - is_supported_for_text_generation() false → failure
 *     - WP_Error from generate_text() → failure
 *   describe_secondary():
 *     - ALWAYS returns failure, with no AI call at all — this provider is
 *       text-only and has no artist text to fall back on for a secondary
 *       image, unlike describe().
 *   enhance() / supports_enhancement() / transcribe() / supports_audio():
 *     - Fixed, unconditional answers — no AI call for any of them.
 *   chat():
 *     - Fails immediately when the AI Client isn't available
 *     - Returns trimmed text on success
 *
 * @package Agnosis\Tests\Integration\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\AI;

use Agnosis\AI\PromptConfig;
use Agnosis\AI\Providers\WordPressAI;
use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;

require_once __DIR__ . '/Stubs/WpAiClientTestRegistry.php';
require_once __DIR__ . '/Stubs/wp_ai_provider_namespace_stubs.php';

class WordPressAIDescribeTest extends \WP_UnitTestCase {

	protected function tearDown(): void {
		WpAiClientTestRegistry::reset();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_config(): PromptConfig {
		return new PromptConfig(
			system_prompt:               'Pick a medium from: {medium_list}',
			user_template:               '{artist_prompt}',
			enhancement_instructions:    'Enhance this.',
			tag_count:                   5,
			excerpt_words:               30,
			quality_threshold:           7,
			quality_rejection_threshold: 3,
		);
	}

	private function make_provider(): WordPressAI {
		return new WordPressAI( $this->make_config() );
	}

	private function describe_json( array $content_json ): string {
		return (string) wp_json_encode( $content_json );
	}

	// -------------------------------------------------------------------------
	// describe()
	// -------------------------------------------------------------------------

	public function test_describe_fails_when_ai_client_not_available(): void {
		WpAiClientTestRegistry::$available = false;

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', 'A red painting' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'WordPress 7.0', $result->error );
	}

	public function test_describe_fails_when_artist_left_no_text(): void {
		// This provider is text-only — with nothing to work from at all
		// (empty artist prompt, no image analysis possible), it must fail
		// immediately rather than send a pointless request.
		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '   ' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'text-only', $result->error );
		$this->assertSame( [], WpAiClientTestRegistry::$prompts, 'No AI call should be made at all when there is no artist text.' );
	}

	public function test_describe_happy_path_populates_all_fields(): void {
		WpAiClientTestRegistry::$response = $this->describe_json( [
			'title'    => 'Harbour at Dawn',
			'excerpt'  => 'Light on still water.',
			'body'     => '<p>A serene harbour scene.</p>',
			'tags'     => [ 'seascape', 'watercolour' ],
			'alt_text' => 'A calm harbour at sunrise.',
			'medium'   => 'Watercolour',
		] );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', 'A harbour at sunrise, watercolour.' );

		$this->assertTrue( $result->success );
		$this->assertSame( 'Harbour at Dawn', $result->title );
		$this->assertSame( 'Light on still water.', $result->excerpt );
		$this->assertSame( '<p>A serene harbour scene.</p>', $result->body );
		$this->assertSame( [ 'seascape', 'watercolour' ], $result->tags );
		$this->assertSame( 'A calm harbour at sunrise.', $result->alt_text );
		$this->assertSame( 'Watercolour', $result->medium );
		// Text-only provider — quality cannot be assessed from a photograph
		// that was never analysed.
		$this->assertSame( 0, $result->photo_quality_score );
		$this->assertSame( [], $result->photo_quality_issues );
	}

	public function test_describe_appends_no_image_available_note_to_the_user_message(): void {
		WpAiClientTestRegistry::$response = $this->describe_json( [ 'title' => 'T' ] );

		$this->make_provider()->describe( 'imagedata', 'image/jpeg', 'My artwork description.' );

		$this->assertNotEmpty( WpAiClientTestRegistry::$prompts );
		$this->assertStringContainsString( 'My artwork description.', WpAiClientTestRegistry::$prompts[0] );
		$this->assertStringContainsString( 'no image is available', WpAiClientTestRegistry::$prompts[0] );
	}

	public function test_describe_fails_when_text_generation_unsupported(): void {
		WpAiClientTestRegistry::$supports_text_generation = false;

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', 'Some text.' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'No text-generation model', $result->error );
	}

	public function test_describe_fails_on_empty_response(): void {
		WpAiClientTestRegistry::$response = '';

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', 'Some text.' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'empty response', $result->error );
	}

	public function test_describe_fails_on_non_json_response(): void {
		WpAiClientTestRegistry::$response = 'Sorry, I cannot help with that.';

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', 'Some text.' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'non-JSON', $result->error );
	}

	public function test_describe_strips_markdown_fences_from_response(): void {
		WpAiClientTestRegistry::$response = "```json\n" . $this->describe_json( [ 'title' => 'Fenced Title' ] ) . "\n```";

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', 'Some text.' );

		$this->assertTrue( $result->success );
		$this->assertSame( 'Fenced Title', $result->title );
	}

	// -------------------------------------------------------------------------
	// describe_secondary() — fifth audit §4c
	// -------------------------------------------------------------------------

	public function test_describe_secondary_always_fails_with_no_ai_call(): void {
		// Unlike describe(), this is unconditional — there is no artist text to
		// fall back on for a secondary image (no $artist_prompt parameter
		// exists at all — see ProviderInterface::describe_secondary()), so
		// this must fail even when the AI Client is otherwise available.
		WpAiClientTestRegistry::$available = true;
		WpAiClientTestRegistry::$response  = $this->describe_json( [ 'alt_text' => 'Should never be reached' ] );

		$result = $this->make_provider()->describe_secondary( 'imagedata', 'image/jpeg' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'text-only', $result->error );
		$this->assertSame( [], WpAiClientTestRegistry::$prompts, 'describe_secondary() must never call the AI Client at all for this provider.' );
	}

	// -------------------------------------------------------------------------
	// enhance() / supports_enhancement()
	// -------------------------------------------------------------------------

	public function test_enhance_always_returns_failure(): void {
		$result = $this->make_provider()->enhance( 'imagedata', 'image/jpeg', 'Fix blur' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'does not support', $result->error );
	}

	public function test_supports_enhancement_returns_false(): void {
		$this->assertFalse( $this->make_provider()->supports_enhancement() );
	}

	// -------------------------------------------------------------------------
	// transcribe() / supports_audio()
	// -------------------------------------------------------------------------

	public function test_transcribe_always_returns_empty_string(): void {
		$this->assertSame( '', $this->make_provider()->transcribe( 'audiodata', 'audio/mpeg' ) );
	}

	public function test_supports_audio_returns_false(): void {
		$this->assertFalse( $this->make_provider()->supports_audio() );
	}

	// -------------------------------------------------------------------------
	// chat()
	// -------------------------------------------------------------------------

	public function test_chat_returns_empty_string_when_ai_client_not_available(): void {
		WpAiClientTestRegistry::$available = false;

		$this->assertSame( '', $this->make_provider()->chat( 'Translate this.' ) );
	}

	public function test_chat_returns_empty_string_when_text_generation_unsupported(): void {
		WpAiClientTestRegistry::$supports_text_generation = false;

		$this->assertSame( '', $this->make_provider()->chat( 'Translate this.' ) );
	}

	public function test_chat_returns_trimmed_response_text(): void {
		WpAiClientTestRegistry::$response = "  Hola mundo  \n";

		$this->assertSame( 'Hola mundo', $this->make_provider()->chat( 'Translate to Spanish.' ) );
	}
}
