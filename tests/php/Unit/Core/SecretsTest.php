<?php
/**
 * Unit tests for Core\Secrets — the wp-config.php constant-override
 * resolver for the plugin's five API keys/secrets (P-4, AUDIT-0.9.39.md §3c).
 *
 * These tests never call PHP's real define() on a MAP constant name
 * (AGNOSIS_WEBHOOK_SECRET etc.) — a real define() can never be undone for
 * the rest of that PHP process, and a first attempt at containing that via
 * @runInSeparateProcess/@preserveGlobalState still let AGNOSIS_WEBHOOK_SECRET
 * leak into WebhookSignatureTest/WebhookReplayProtectionTest whenever they
 * ran later in the same PHPUnit invocation (P-4 follow-up regression, found
 * by Ulises's PHPUnit run 2026-07-20 — those two suites' own fixtures got
 * silently overridden, since Secrets::resolve() checks a defined constant
 * before any option). Instead, Secrets::set_constant_lookup_for_testing()
 * substitutes a plain in-memory fake for defined()/constant() entirely, so
 * correctness here no longer depends on process isolation behaving any
 * particular way in any given environment — nothing in this file can ever
 * leak into another suite.
 *
 * `Core\Secrets::resolve()`'s `get_option()` fallback is intercepted by
 * `tests/php/Unit/Core/Stubs/core_namespace_stubs.php`, which — because an
 * unqualified `get_option()` call resolves by the *calling code's own*
 * namespace, not its caller's — is the file that determines what an
 * unmocked option read returns from inside `Agnosis\Core` at all. That stub
 * reads `SubmissionTranslatorTest::$options`/`WebhookSignatureTest::$options`
 * for backward compatibility with the AI/Email suites' existing fixtures;
 * neither is set from here, so every fallback in this file resolves to
 * plain `''`, the same default `resolve()` always passes.
 *
 * @package Agnosis\Tests\Unit\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Core;

use Agnosis\Core\Secrets;
use PHPUnit\Framework\TestCase;

class SecretsTest extends TestCase {

	/** @var array<string, string> Fake constant name => value, read by the closure set in setUp(). */
	private static array $fake_constants = [];

	protected function setUp(): void {
		parent::setUp();
		self::$fake_constants = [];
		Secrets::set_constant_lookup_for_testing(
			static fn ( string $constant ): ?string => self::$fake_constants[ $constant ] ?? null
		);
	}

	protected function tearDown(): void {
		Secrets::set_constant_lookup_for_testing( null );
		self::$fake_constants = [];
		parent::tearDown();
	}

	public function test_override_constant_name_maps_every_known_option(): void {
		$this->assertSame( 'AGNOSIS_OPENAI_KEY', Secrets::override_constant_name( 'agnosis_openai_api_key' ) );
		$this->assertSame( 'AGNOSIS_ANTHROPIC_KEY', Secrets::override_constant_name( 'agnosis_anthropic_api_key' ) );
		$this->assertSame( 'AGNOSIS_WEBHOOK_SECRET', Secrets::override_constant_name( 'agnosis_webhook_secret' ) );
		$this->assertSame( 'AGNOSIS_TURNSTILE_SITE_KEY', Secrets::override_constant_name( 'agnosis_turnstile_site_key' ) );
		$this->assertSame( 'AGNOSIS_TURNSTILE_SECRET_KEY', Secrets::override_constant_name( 'agnosis_turnstile_secret_key' ) );
	}

	public function test_override_constant_name_returns_null_for_an_unrelated_option(): void {
		// Settings::render_field() calls this for EVERY field on the page, not
		// just the five secret ones — must be a safe no-op for anything else.
		$this->assertNull( Secrets::override_constant_name( 'agnosis_base_domain' ) );
	}

	public function test_is_overridden_and_value_before_and_after_the_constant_is_defined(): void {
		$this->assertFalse( Secrets::is_overridden( 'agnosis_webhook_secret' ) );
		$this->assertSame( '', Secrets::webhook_secret() ); // Falls back to the (stubbed) option default.

		self::$fake_constants['AGNOSIS_WEBHOOK_SECRET'] = 'a-real-secret-from-wp-config';

		$this->assertTrue( Secrets::is_overridden( 'agnosis_webhook_secret' ) );
		$this->assertSame( 'a-real-secret-from-wp-config', Secrets::webhook_secret() );
	}

	public function test_empty_string_constant_does_not_count_as_an_override(): void {
		// An operator accidentally shipping define('AGNOSIS_TURNSTILE_SECRET_KEY', '')
		// must not lock the Settings field — "defined but empty" is "not set."
		self::$fake_constants['AGNOSIS_TURNSTILE_SECRET_KEY'] = '';

		$this->assertFalse( Secrets::is_overridden( 'agnosis_turnstile_secret_key' ) );
		$this->assertSame( '', Secrets::turnstile_secret_key() );
	}

	public function test_whitespace_only_constant_does_not_count_as_an_override_either(): void {
		self::$fake_constants['AGNOSIS_OPENAI_KEY'] = '   ';

		$this->assertFalse( Secrets::is_overridden( 'agnosis_openai_api_key' ) );
		$this->assertSame( '', Secrets::openai_api_key() );
	}

	public function test_defined_constant_wins_and_is_trimmed(): void {
		self::$fake_constants['AGNOSIS_ANTHROPIC_KEY'] = '  sk-ant-a-real-key  ';

		$this->assertTrue( Secrets::is_overridden( 'agnosis_anthropic_api_key' ) );
		$this->assertSame( 'sk-ant-a-real-key', Secrets::anthropic_api_key() );
	}

	public function test_turnstile_site_key_falls_back_when_its_constant_is_never_defined(): void {
		// AGNOSIS_TURNSTILE_SITE_KEY is deliberately never added to
		// $fake_constants anywhere in this file — this is the "operator never
		// used the override" baseline case every other test method's
		// "before" assertion mirrors.
		$this->assertFalse( Secrets::is_overridden( 'agnosis_turnstile_site_key' ) );
		$this->assertSame( '', Secrets::turnstile_site_key() );
	}

	public function test_lookup_override_is_reset_after_each_test(): void {
		// Guards the guard: if tearDown() ever stopped calling
		// set_constant_lookup_for_testing(null), Secrets would keep using
		// this file's fake lookup (always returning null for anything not in
		// $fake_constants) for every test suite that runs afterward in the
		// same process — silently hiding a *real* defined constant from
		// production-shaped code instead of the leak this file exists to
		// prevent. Nothing here sets AGNOSIS_WEBHOOK_SECRET, so this only
		// proves the previous test's teardown actually ran.
		$this->assertFalse( Secrets::is_overridden( 'agnosis_webhook_secret' ) );
	}
}
