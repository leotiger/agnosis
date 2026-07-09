<?php
/**
 * Shared, test-agnostic state for the namespace-scoped wp_ai_client_prompt()
 * stub in wp_ai_provider_namespace_stubs.php.
 *
 * Deliberately holds no reference to any specific test class —
 * WordPressAIDescribeTest (calling Providers\WordPressAI::describe()/chat()
 * directly) and CommunityBroadcastLanguageGroupingTest (reaching the same
 * provider indirectly via SubmissionTranslator::from_settings() with
 * agnosis_ai_provider = 'wp_ai') both drive the one fake builder in the
 * companion stub file through this registry, each touching only the
 * properties relevant to what it's testing — WordPressAIDescribeTest sets
 * $available / $response explicitly; CommunityBroadcastLanguageGroupingTest
 * never has to, since the stub's fallback behavior (echoing
 * SubmissionTranslator::call_translate()'s own prompt shape) is exactly what
 * it needs.
 *
 * This file contains a class declaration only (no functions) to satisfy
 * Universal.Files.SeparateFunctionsFromOO — see the companion function-only
 * stub file for the actual global/namespace function overrides.
 *
 * @package Agnosis\Tests\Integration\AI\Stubs
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\AI\Stubs;

class WpAiClientTestRegistry {

	/** Every prompt sent through the fake builder, across all tests using it. */
	public static array $prompts = [];

	/**
	 * Whether the fake AI Client "exists" for function_exists() purposes.
	 * null/true = available (default, matches how CommunityBroadcast's tests
	 * expect a working provider); false = simulates WordPress < 7.0 / the AI
	 * Client not present, for WordPressAI's own guard-clause tests.
	 */
	public static ?bool $available = null;

	/** Forced return value for is_supported_for_text_generation(); null = true. */
	public static ?bool $supports_text_generation = null;

	/**
	 * Forced raw response text for generate_text()/get_text(). When null, the
	 * fake builder falls back to echoing SubmissionTranslator's own
	 * translate_text()-shaped prompt ("Translate the sections below to
	 * {name}." + a "BODY:\n{text}" section) as a small deterministic JSON
	 * translation — see the stub function's own docblock for the exact
	 * format. That fallback is all CommunityBroadcastLanguageGroupingTest
	 * needs, so it never has to set this property.
	 */
	public static ?string $response = null;

	/** Reset every property to its default — call from tearDown(). */
	public static function reset(): void {
		self::$prompts                  = [];
		self::$available                = null;
		self::$supports_text_generation = null;
		self::$response                 = null;
	}
}
