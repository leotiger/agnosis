<?php
/**
 * Integration tests — Core\Turnstile, the human-verification gate on public forms.
 *
 * Sat at 16.7% of 54 statements before 0.9.67 despite guarding the two
 * unauthenticated write endpoints in the plugin: newsletter signup
 * (`Subscription::subscribe()`) and the artist application (`Admission::apply()`).
 * Both are open POST routes and the obvious spam targets — rate limiting alone
 * does not stop a slow, patient bot.
 *
 * **Why this one was worth covering ahead of bigger gaps.** Its failure mode is
 * silent and it fails *open*: if `verify()` ever started returning true when it
 * should not, nothing visibly breaks. Both forms keep working, no error is
 * logged, no page looks wrong — the only symptom is spam arriving, weeks later,
 * with nothing to connect it to a code change. Every assertion below is
 * therefore about *refusing*, not about accepting.
 *
 * Covered:
 *
 *   is_enabled() — the opt-in contract:
 *     - False with neither key, and with only one of the two
 *     - True only when both are set
 *
 *   verify() — the gate itself:
 *     - Returns true (open) when unconfigured, so existing installs are unaffected
 *     - Rejects an empty/whitespace token before any HTTP call is made
 *     - Rejects when Cloudflare says `success: false`, surfacing a 400
 *     - Rejects with 503 when Cloudflare is unreachable — fails CLOSED
 *     - Sends the secret key and token to the siteverify endpoint, not the site key
 *     - Accepts only on an explicit `success: true`
 *
 *   render_widget() / enqueue_script() — no leakage when disabled:
 *     - Empty markup and no script when unconfigured
 *     - Widget carries the SITE key (public) and never the secret
 *
 * The unreachable-Cloudflare case is the most important single assertion here.
 * An outage must not become an open door: a bot that can cause (or simply wait
 * for) a siteverify failure would otherwise walk straight past the check.
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\Turnstile;

class TurnstileTest extends \WP_UnitTestCase {

	private const SITE_KEY   = '0x4AAAAAAA_test_site_key';
	private const SECRET_KEY = '0x4AAAAAAA_test_secret_key';

	protected function tearDown(): void {
		delete_option( 'agnosis_turnstile_site_key' );
		delete_option( 'agnosis_turnstile_secret_key' );
		remove_all_filters( 'pre_http_request' );
		wp_dequeue_script( 'agnosis-cloudflare-turnstile' );
		wp_deregister_script( 'agnosis-cloudflare-turnstile' );

		parent::tearDown();
	}

	private function configure(): void {
		update_option( 'agnosis_turnstile_site_key', self::SITE_KEY );
		update_option( 'agnosis_turnstile_secret_key', self::SECRET_KEY );
	}

	/**
	 * Stub Cloudflare's siteverify endpoint.
	 *
	 * @param array<string, mixed>|\WP_Error $body   Decoded JSON body to hand back, or a WP_Error to simulate an outage.
	 * @param array<int, array<string, mixed>> $seen Captured request args, by reference.
	 */
	private function stub_siteverify( array|\WP_Error $body, array &$seen ): void {
		add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $body, &$seen ) {
			if ( ! str_contains( (string) $url, 'challenges.cloudflare.com' ) ) {
				return $pre;
			}

			$seen[] = $args;

			if ( $body instanceof \WP_Error ) {
				return $body;
			}

			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => (string) wp_json_encode( $body ),
				'cookies'  => [],
				'filename' => '',
			];
		}, 10, 3 );
	}

	// -------------------------------------------------------------------------
	// is_enabled() — opt-in by configuration, not by toggle
	// -------------------------------------------------------------------------

	public function test_is_disabled_when_no_keys_are_configured(): void {
		$this->assertFalse( Turnstile::is_enabled() );
	}

	public function test_is_disabled_when_only_the_site_key_is_configured(): void {
		update_option( 'agnosis_turnstile_site_key', self::SITE_KEY );
		$this->assertFalse( Turnstile::is_enabled(), 'A site key alone cannot verify anything — the secret is what talks to Cloudflare.' );
	}

	public function test_is_disabled_when_only_the_secret_key_is_configured(): void {
		update_option( 'agnosis_turnstile_secret_key', self::SECRET_KEY );
		$this->assertFalse( Turnstile::is_enabled(), 'A secret alone renders no widget, so no token could ever arrive.' );
	}

	public function test_is_enabled_only_when_both_keys_are_configured(): void {
		$this->configure();
		$this->assertTrue( Turnstile::is_enabled() );
	}

	// -------------------------------------------------------------------------
	// verify() — the gate
	// -------------------------------------------------------------------------

	public function test_verify_passes_through_when_turnstile_is_not_configured(): void {
		$seen = [];
		$this->stub_siteverify( [ 'success' => false ], $seen );

		$this->assertTrue( Turnstile::verify( '' ), 'An unconfigured install must not start rejecting its own forms.' );
		$this->assertSame( [], $seen, 'No outbound request should be made when the feature is off.' );
	}

	public function test_verify_rejects_an_empty_token_without_calling_cloudflare(): void {
		$this->configure();
		$seen = [];
		$this->stub_siteverify( [ 'success' => true ], $seen );

		$result = Turnstile::verify( '   ' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_turnstile_missing', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( [], $seen, 'A blank token is refused locally — no point spending a round trip on it.' );
	}

	public function test_verify_rejects_when_cloudflare_reports_failure(): void {
		$this->configure();
		$seen = [];
		$this->stub_siteverify( [ 'success' => false, 'error-codes' => [ 'invalid-input-response' ] ], $seen );

		$result = Turnstile::verify( 'a-token' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_turnstile_failed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 1, $seen );
	}

	/**
	 * The load-bearing test: an outage must fail CLOSED.
	 *
	 * If an unreachable Cloudflare returned true, anyone able to cause or simply
	 * wait for a siteverify failure would walk straight past the check — and the
	 * only visible symptom would be spam, arriving later, with nothing tying it
	 * to the behaviour that allowed it.
	 */
	public function test_verify_fails_closed_when_cloudflare_is_unreachable(): void {
		$this->configure();
		$seen = [];
		$this->stub_siteverify( new \WP_Error( 'http_request_failed', 'Connection timed out' ), $seen );

		$result = Turnstile::verify( 'a-token' );

		$this->assertInstanceOf( \WP_Error::class, $result, 'An outage must never be treated as a pass.' );
		$this->assertSame( 'agnosis_turnstile_unreachable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'], '503 tells the caller to retry, unlike a 400 that blames the visitor.' );
	}

	public function test_verify_sends_the_secret_key_and_token_and_never_the_site_key(): void {
		$this->configure();
		$seen = [];
		$this->stub_siteverify( [ 'success' => true ], $seen );

		$this->assertTrue( Turnstile::verify( 'the-widget-token' ) );

		$this->assertCount( 1, $seen );
		$body = $seen[0]['body'];
		$this->assertSame( self::SECRET_KEY, $body['secret'] );
		$this->assertSame( 'the-widget-token', $body['response'] );
		$this->assertArrayHasKey( 'remoteip', $body );
		$this->assertNotSame( self::SITE_KEY, $body['secret'], 'Sending the public site key as the secret would fail open against a real Cloudflare.' );
	}

	public function test_verify_accepts_only_on_an_explicit_success(): void {
		$this->configure();

		// A malformed/empty body is not a pass — `empty( $body['success'] )` is
		// what decides, so a response Cloudflare never sent must still refuse.
		$seen = [];
		$this->stub_siteverify( [], $seen );
		$this->assertInstanceOf( \WP_Error::class, Turnstile::verify( 'a-token' ) );
	}

	// -------------------------------------------------------------------------
	// Widget + script — nothing leaks when disabled
	// -------------------------------------------------------------------------

	public function test_widget_is_empty_and_no_script_is_enqueued_when_disabled(): void {
		$this->assertSame( '', Turnstile::render_widget() );

		Turnstile::enqueue_script();
		$this->assertFalse( wp_script_is( 'agnosis-cloudflare-turnstile', 'enqueued' ) );
	}

	public function test_widget_carries_the_site_key_and_never_the_secret(): void {
		$this->configure();

		$html = Turnstile::render_widget();

		$this->assertStringContainsString( 'cf-turnstile', $html );
		$this->assertStringContainsString( 'data-sitekey="' . self::SITE_KEY . '"', $html );
		$this->assertStringNotContainsString( self::SECRET_KEY, $html, 'The secret key must never reach the browser.' );
	}

	public function test_script_is_enqueued_once_when_enabled(): void {
		$this->configure();

		Turnstile::enqueue_script();
		Turnstile::enqueue_script(); // Both public forms may call this on one page.

		$this->assertTrue( wp_script_is( 'agnosis-cloudflare-turnstile', 'enqueued' ) );
	}
}
