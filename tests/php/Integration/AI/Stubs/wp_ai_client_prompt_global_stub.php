<?php
/**
 * Real GLOBAL stand-in for the real WP 7.0+ AI Client builder function.
 *
 * Deliberately declared with NO namespace (this codebase's phpcs ruleset
 * forbids curly-brace namespace syntax and more than one namespace
 * declaration per file — Universal.Namespaces.DisallowCurlyBraceSyntax /
 * OneDeclarationPerFile — so this can't simply be a second namespace block
 * inside wp_ai_provider_namespace_stubs.php; it has to be its own file).
 *
 * Must be global, not namespace-scoped, because every real call site
 * (Providers\WordPressAI::describe()/chat(), Admin\Settings's own
 * connectivity check) reaches it via call_user_func( 'wp_ai_client_prompt',
 * ... ) rather than a literal call — deliberately, to dodge Plugin Check's
 * static analyser (see WordPressAI.php's own class docblock). A string
 * passed to call_user_func() always resolves against the global namespace,
 * never the calling file's own namespace, so a namespace-scoped declaration
 * of this function (this file's previous approach) is never actually
 * reached at runtime — see wp_ai_provider_namespace_stubs.php's docblock for
 * the fuller explanation and the failures that first exposed this.
 *
 * This does NOT reintroduce the "a real global function, once declared, can
 * never be undefined again" problem that motivated avoiding a global
 * function in the first place: the "AI Client not available" test path is
 * gated entirely by wp_ai_provider_namespace_stubs.php's namespace-scoped
 * function_exists() override — a completely separate function from this
 * one, unaffected by this function's permanent existence.
 *
 * generate_text() (used by WordPressAI::describe()) and get_text() (used by
 * WordPressAI::chat()) both resolve through the same fallback: return
 * WpAiClientTestRegistry::$response when a test has set one explicitly
 * (WordPressAIDescribeTest's own coverage), otherwise echo back a
 * deterministic translation for SubmissionTranslator::call_translate()'s own
 * prompt shape — "Translate the sections below to {name}." plus a
 * "BODY:\n{text}" section — as {"description": "[{name}] {text}"}. That
 * fallback is all CommunityBroadcastLanguageGroupingTest/
 * LinguaForgeCompatTest/NotificationEmailTest need.
 *
 * This file contains a function declaration only (no OO structures) to
 * satisfy Universal.Files.SeparateFunctionsFromOO.
 *
 * Required by: wp_ai_provider_namespace_stubs.php (which every AI-Client test
 * file already requires).
 *
 * @package Agnosis\Tests\Integration\AI\Stubs
 */

declare(strict_types=1);

use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;

function wp_ai_client_prompt( string $prompt ) {
	WpAiClientTestRegistry::$prompts[] = $prompt;

	return new class( $prompt ) {
		public function __construct( private readonly string $prompt ) {}

		public function using_system_instruction( string $instruction ): self {
			return $this;
		}

		public function using_temperature( float $temperature ): self {
			return $this;
		}

		public function using_max_tokens( int $max_tokens ): self {
			return $this;
		}

		public function is_supported_for_text_generation(): bool {
			return WpAiClientTestRegistry::$supports_text_generation ?? true;
		}

		public function generate_text(): string {
			return $this->resolve_text();
		}

		public function get_text(): string {
			return $this->resolve_text();
		}

		private function resolve_text(): string {
			if ( null !== WpAiClientTestRegistry::$response ) {
				return WpAiClientTestRegistry::$response;
			}

			if ( ! preg_match( '/Translate the sections below to ([A-Za-z ]+)\./', $this->prompt, $lang_match )
				|| ! preg_match( '/BODY:\n(.*)$/s', $this->prompt, $body_match )
			) {
				return '';
			}

			return (string) wp_json_encode( [
				'description' => '[' . trim( $lang_match[1] ) . '] ' . trim( $body_match[1] ),
			] );
		}
	};
}
