<?php
/**
 * Thin require shim.
 *
 * The actual wp_ai_client_prompt()/function_exists() stubs live in
 * tests/php/Integration/AI/Stubs/wp_ai_provider_namespace_stubs.php, which
 * declares BOTH a namespace-scoped Agnosis\AI\Providers function_exists()
 * override (for WordPressAI.php's literal, unqualified checks) AND a real
 * global wp_ai_client_prompt() (required because every real call site
 * reaches it via call_user_func( 'wp_ai_client_prompt', ... ) — a string
 * callable, which PHP always resolves against the global namespace,
 * regardless of the calling file's own namespace — see that file's docblock
 * for the full explanation of why a namespace-scoped-only version silently
 * never ran).
 *
 * This file is kept (rather than removed) purely so
 * CommunityBroadcastLanguageGroupingTest's existing
 * `require_once __DIR__ . '/Stubs/wp_ai_global_stubs.php';` doesn't need to
 * change; it just forwards to the real, shared stub.
 *
 * @package Agnosis\Tests\Integration\Artist\Stubs
 */

declare(strict_types=1);

require_once __DIR__ . '/../../AI/Stubs/WpAiClientTestRegistry.php';
require_once __DIR__ . '/../../AI/Stubs/wp_ai_provider_namespace_stubs.php';
