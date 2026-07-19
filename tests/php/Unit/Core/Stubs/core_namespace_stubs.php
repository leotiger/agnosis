<?php
/**
 * Namespace-scoped get_option() override for Agnosis\Core unit tests.
 *
 * `Core\Secrets::resolve()` (P-4, `agnosis-audit/AUDIT-0.9.39.md` §3c,
 * closed 2026-07-19) is now the one place `get_option()` is called for the
 * plugin's five key/secret options — every call site that used to read them
 * directly (`SubmissionTranslator`, `Pipeline`, `Webhook`, `Turnstile`, ...)
 * goes through it instead. PHP resolves an unqualified function call by the
 * *calling code's own* namespace, not its caller's — so moving that
 * `get_option()` call into `Agnosis\Core` meant it stopped picking up the
 * `Agnosis\AI`/`Agnosis\Email` namespaces' own test-only overrides
 * (`ai_namespace_stubs.php` / `email_namespace_stubs.php`) entirely, and
 * silently fell through to the real global `bootstrap.php` stub instead —
 * which always returns `$fallback` regardless of what a test configured.
 * That's the exact cause of the regression this file fixes: every test that
 * set up "an API key/secret is configured" via those two files' existing
 * mechanisms saw `Secrets::…()` come back empty no matter what, since the
 * value never reached the `get_option()` call that had moved.
 *
 * Rather than invent a third, Core-specific options property and rewrite
 * every affected test to use it, this stub reads the exact same static
 * properties those tests already set — `SubmissionTranslatorTest::$options`
 * (AI-suite tests: `SubmissionTranslatorTest` itself, and anything else that
 * exercises `Pipeline`/`SubmissionTranslator` through it) and
 * `WebhookSignatureTest::$options` (Email-suite tests, including
 * `WebhookReplayProtectionTest`, which sets that same property directly
 * rather than keeping one of its own — see its own setUp()). Checked in
 * that order; the first one that actually has the requested key wins. No
 * existing test file needed to change.
 *
 * @package Agnosis\Tests\Unit\Core\Stubs
 */

declare(strict_types=1);

namespace Agnosis\Core;

use Agnosis\Tests\Unit\AI\SubmissionTranslatorTest;
use Agnosis\Tests\Unit\Email\WebhookSignatureTest;

function get_option( string $key, mixed $fallback = false ): mixed {
	if ( null !== SubmissionTranslatorTest::$options && array_key_exists( $key, SubmissionTranslatorTest::$options ) ) {
		return SubmissionTranslatorTest::$options[ $key ];
	}

	if ( array_key_exists( $key, WebhookSignatureTest::$options ) ) {
		return WebhookSignatureTest::$options[ $key ];
	}

	return $fallback;
}
