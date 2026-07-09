<?php
/**
 * Namespace-scoped override for Agnosis\AI\Providers — specifically
 * function_exists('wp_ai_client_prompt'), the one call Providers\WordPressAI
 * makes literally/unqualified from within its own namespace.
 *
 * Declaring it here — `namespace Agnosis\AI\Providers;`, matching
 * WordPressAI.php's own namespace exactly — intercepts that literal call via
 * PHP's ordinary namespace-fallback rule for unqualified function calls
 * (check the current namespace first, then fall back to global), the same
 * technique Unit/AI/Stubs/ai_namespace_stubs.php already uses for
 * SubmissionTranslator.
 *
 * The actual wp_ai_client_prompt() builder function is NOT declared here —
 * it can't be namespace-scoped at all, because every real call site reaches
 * it via call_user_func( 'wp_ai_client_prompt', ... ) rather than a literal
 * call (deliberately, to dodge Plugin Check's static analyser — see
 * WordPressAI.php's own class docblock), and a string passed to
 * call_user_func() always resolves against the GLOBAL namespace, never the
 * calling file's own namespace. A namespace-scoped declaration of that
 * function was this file's original approach and silently never ran —
 * proven by every WordPressAIDescribeTest/CommunityBroadcastLanguageGroupingTest/
 * LinguaForgeCompatTest/NotificationEmailTest test that actually exercises
 * describe()/chat() failing with "function \"wp_ai_client_prompt\" not found
 * or invalid function name". It now lives in its own file,
 * wp_ai_client_prompt_global_stub.php, as a real global function (required
 * below) — this codebase's phpcs ruleset forbids curly-brace namespace
 * syntax and more than one namespace declaration per file
 * (Universal.Namespaces.DisallowCurlyBraceSyntax / OneDeclarationPerFile), so
 * that function can't simply be a second namespace block in this same file.
 *
 * That real global function does NOT reintroduce the "can never undefine a
 * real global function" problem this file was originally written to avoid:
 * the "AI Client not available" test path is gated entirely by the
 * namespace-scoped function_exists() override below, a completely separate
 * function that's unaffected by the global function's permanent existence.
 * WpAiClientTestRegistry::$available still flips that answer per test,
 * freely, in either direction.
 *
 * This file contains a function declaration only (no OO structures) to
 * satisfy Universal.Files.SeparateFunctionsFromOO.
 *
 * Required by: WordPressAIDescribeTest.php (directly), LinguaForgeCompatTest.php
 * and NotificationEmailTest.php (directly), and CommunityBroadcastLanguageGroupingTest.php
 * (indirectly, via Integration/Artist/Stubs/wp_ai_global_stubs.php's require_once).
 *
 * @package Agnosis\Tests\Integration\AI\Stubs
 */

declare(strict_types=1);

namespace Agnosis\AI\Providers;

use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;

require_once __DIR__ . '/wp_ai_client_prompt_global_stub.php';

/**
 * Namespace-scoped function_exists() override — only intercepts the one name
 * WordPressAI.php ever probes via a literal, unqualified call; every other
 * name passes straight through to the real global function_exists(), so
 * nothing else in this namespace is affected.
 */
function function_exists( string $name ): bool {
	if ( 'wp_ai_client_prompt' === $name ) {
		return WpAiClientTestRegistry::$available ?? true;
	}
	return \function_exists( $name );
}
