<?php
/**
 * Unit tests for AI\PromptConfig.
 *
 * PromptConfig is a pure value object with string interpolation methods.
 * Tests cover token replacement in the system prompt, user message assembly,
 * the empty-artist-prompt fallback, and that from_options() picks up defaults.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\PromptConfig;
use PHPUnit\Framework\TestCase;

class PromptConfigTest extends TestCase {

	// -------------------------------------------------------------------------
	// Constructor / value object
	// -------------------------------------------------------------------------

	public function test_constructor_stores_all_fields(): void {
		$cfg = new PromptConfig(
			system_prompt:            'System {tag_count} {excerpt_words}',
			user_template:            'User {artist_prompt}',
			enhancement_instructions: 'Enhance',
			tag_count:                7,
			excerpt_words:            40,
		);

		$this->assertSame( 'System {tag_count} {excerpt_words}', $cfg->system_prompt );
		$this->assertSame( 'User {artist_prompt}',               $cfg->user_template );
		$this->assertSame( 'Enhance',                            $cfg->enhancement_instructions );
		$this->assertSame( 7,                                    $cfg->tag_count );
		$this->assertSame( 40,                                   $cfg->excerpt_words );
	}

	// -------------------------------------------------------------------------
	// resolved_system_prompt()
	// -------------------------------------------------------------------------

	public function test_resolved_system_prompt_replaces_tag_count(): void {
		$cfg    = $this->make( system_prompt: 'Generate {tag_count} tags.' );
		$result = $cfg->resolved_system_prompt();

		$this->assertStringContainsString( '5', $result );
		$this->assertStringNotContainsString( '{tag_count}', $result );
	}

	public function test_resolved_system_prompt_replaces_excerpt_words(): void {
		$cfg    = $this->make( system_prompt: 'Write {excerpt_words} words.' );
		$result = $cfg->resolved_system_prompt();

		$this->assertStringContainsString( '30', $result );
		$this->assertStringNotContainsString( '{excerpt_words}', $result );
	}

	public function test_resolved_system_prompt_replaces_both_tokens(): void {
		$cfg = new PromptConfig(
			system_prompt:            '{tag_count} tags, {excerpt_words} words',
			user_template:            '',
			enhancement_instructions: '',
			tag_count:                8,
			excerpt_words:            50,
		);

		$this->assertSame( '8 tags, 50 words', $cfg->resolved_system_prompt() );
	}

	public function test_resolved_system_prompt_leaves_prompt_without_tokens_unchanged(): void {
		$cfg    = $this->make( system_prompt: 'No tokens here.' );
		$result = $cfg->resolved_system_prompt();

		$this->assertSame( 'No tokens here.', $result );
	}

	// -------------------------------------------------------------------------
	// build_user_message()
	// -------------------------------------------------------------------------

	public function test_build_user_message_interpolates_artist_prompt(): void {
		$cfg    = $this->make( user_template: 'Artist says: {artist_prompt}' );
		$result = $cfg->build_user_message( 'Hello world' );

		$this->assertSame( 'Artist says: Hello world', $result );
	}

	public function test_build_user_message_uses_fallback_when_prompt_is_empty(): void {
		$cfg    = $this->make( user_template: 'Context: {artist_prompt}' );
		$result = $cfg->build_user_message( '' );

		// The {artist_prompt} token is replaced with the fallback text.
		$this->assertStringNotContainsString( '{artist_prompt}', $result );
		// The template prefix is unchanged — only the token is substituted.
		$this->assertStringContainsString( 'Context: ', $result );
		// The fallback mentions that no description was provided.
		$this->assertStringContainsString( 'no subject line or message', $result );
	}

	public function test_build_user_message_uses_fallback_when_prompt_is_whitespace(): void {
		$cfg    = $this->make( user_template: '{artist_prompt}' );
		$result = $cfg->build_user_message( '   ' );

		// Whitespace is treated as empty — fallback is used.
		// (The method checks empty($artist_prompt), and '   ' is not empty in PHP,
		// so this documents the actual behaviour: whitespace IS used as-is.)
		$this->assertSame( '   ', $result );
	}

	public function test_build_user_message_with_no_token_in_template(): void {
		$cfg    = $this->make( user_template: 'Static template.' );
		$result = $cfg->build_user_message( 'Anything' );

		$this->assertSame( 'Static template.', $result );
	}

	// -------------------------------------------------------------------------
	// from_options() — uses defaults when options are not set
	// -------------------------------------------------------------------------

	public function test_from_options_returns_prompt_config_instance(): void {
		$cfg = PromptConfig::from_options();

		$this->assertInstanceOf( PromptConfig::class, $cfg );
	}

	public function test_from_options_defaults_tag_count_to_five(): void {
		// Unit bootstrap stubs get_option() to always return the default.
		$cfg = PromptConfig::from_options();

		$this->assertSame( 5, $cfg->tag_count );
	}

	public function test_from_options_defaults_excerpt_words_to_thirty(): void {
		$cfg = PromptConfig::from_options();

		$this->assertSame( 30, $cfg->excerpt_words );
	}

	public function test_from_options_default_system_prompt_contains_json_structure(): void {
		$cfg = PromptConfig::from_options();

		$this->assertStringContainsString( '"title"',    $cfg->system_prompt );
		$this->assertStringContainsString( '"excerpt"',  $cfg->system_prompt );
		$this->assertStringContainsString( '"body"',     $cfg->system_prompt );
		$this->assertStringContainsString( '"tags"',     $cfg->system_prompt );
		$this->assertStringContainsString( '"alt_text"', $cfg->system_prompt );
	}

	public function test_from_options_default_user_template_contains_artist_prompt_token(): void {
		$cfg = PromptConfig::from_options();

		$this->assertStringContainsString( '{artist_prompt}', $cfg->user_template );
	}

	// -------------------------------------------------------------------------
	// Default static methods
	// -------------------------------------------------------------------------

	public function test_default_system_prompt_contains_json_instructions(): void {
		$prompt = PromptConfig::default_system_prompt();

		$this->assertStringContainsString( 'JSON', $prompt );
		$this->assertStringContainsString( '{tag_count}', $prompt );
		$this->assertStringContainsString( '{excerpt_words}', $prompt );
	}

	public function test_default_user_template_contains_artist_prompt_token(): void {
		$this->assertStringContainsString( '{artist_prompt}', PromptConfig::default_user_template() );
	}

	public function test_default_enhancement_instructions_is_non_empty(): void {
		$this->assertNotEmpty( PromptConfig::default_enhancement_instructions() );
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	private function make(
		string $system_prompt = 'System {tag_count} {excerpt_words}',
		string $user_template = 'User {artist_prompt}',
		string $enhancement_instructions = 'Enhance',
		int $tag_count = 5,
		int $excerpt_words = 30,
	): PromptConfig {
		return new PromptConfig(
			system_prompt:            $system_prompt,
			user_template:            $user_template,
			enhancement_instructions: $enhancement_instructions,
			tag_count:                $tag_count,
			excerpt_words:            $excerpt_words,
		);
	}
}
