<?php
/**
 * Unit tests for Webhook::verify_signature()'s replay-protection layer
 * (fifth audit §3a) — timestamp freshness and per-request replay memory,
 * added on top of the pre-existing HMAC checks WebhookSignatureTest covers.
 *
 * Both signing schemes are tested:
 *   1. Mailgun (timestamp + token + signature) — always replay-protected.
 *   2. Generic X-Agnosis-Signature — replay-protected only when the sender
 *      also sends the new, opt-in X-Agnosis-Timestamp header; senders who
 *      don't are left on the original body-only check, unprotected but
 *      unaffected (back-compat).
 *
 * Uses the same namespace-scoped get_option() override as
 * WebhookSignatureTest (Stubs/email_namespace_stubs.php) and the global
 * get_transient()/set_transient() stubs from dev/bootstrap.php, which back
 * both RateLimiter::check() and check_replay_freshness()'s own replay memory.
 *
 * @package Agnosis\Tests\Unit\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Email;

use Agnosis\Core\RateLimiter;
use Agnosis\Email\Webhook;
use PHPUnit\Framework\TestCase;

class WebhookReplayProtectionTest extends TestCase {

	private const TEST_SECRET = 'super-secret-key-for-tests';
	private const TEST_BODY   = '{"sender":"artist@example.com","subject":"My art"}';

	protected function setUp(): void {
		parent::setUp();
		// The namespace-scoped get_option() override in
		// Stubs/email_namespace_stubs.php is hardcoded to read
		// WebhookSignatureTest::$options specifically (not a property on this
		// class) — that's the one static property every Webhook unit test in
		// this directory shares.
		WebhookSignatureTest::$options = [];

		// This suite makes many verify_signature() calls, each consuming one
		// unit of the shared 'email_inbound' RateLimiter bucket (60/60s) — reset
		// it so this file's own test count never depends on what ran before it
		// in the same PHPUnit process (transients are a plain in-memory array
		// for the whole run, per dev/bootstrap.php).
		RateLimiter::reset( 'email_inbound', RateLimiter::client_ip(), 60 );
	}

	protected function tearDown(): void {
		WebhookSignatureTest::$options = [];
		parent::tearDown();
	}

	private function set_secret(): void {
		WebhookSignatureTest::$options['agnosis_webhook_secret'] = self::TEST_SECRET;
	}

	private function mailgun_signature( string $timestamp, string $token, string $secret ): string {
		return hash_hmac( 'sha256', $timestamp . $token, $secret );
	}

	private function mailgun_request( string $timestamp, string $token ): \WP_REST_Request {
		return new \WP_REST_Request( params: [
			'timestamp' => $timestamp,
			'token'     => $token,
			'signature' => $this->mailgun_signature( $timestamp, $token, self::TEST_SECRET ),
		] );
	}

	// -------------------------------------------------------------------------
	// Mailgun scheme — timestamp freshness
	// -------------------------------------------------------------------------

	public function test_mailgun_fresh_timestamp_succeeds(): void {
		$this->set_secret();

		$request = $this->mailgun_request( (string) time(), bin2hex( random_bytes( 16 ) ) );

		$this->assertTrue( ( new Webhook() )->verify_signature( $request ) );
	}

	public function test_mailgun_stale_past_timestamp_is_rejected(): void {
		$this->set_secret();

		// Well outside the 300-second freshness window, but still a validly
		// signed triple for that (now stale) timestamp.
		$request = $this->mailgun_request( (string) ( time() - 1000 ), bin2hex( random_bytes( 16 ) ) );

		$result = ( new Webhook() )->verify_signature( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_webhook_stale_timestamp', $result->get_error_code() );
	}

	public function test_mailgun_future_timestamp_beyond_window_is_rejected(): void {
		$this->set_secret();

		// The freshness check is symmetric (abs()) — a timestamp implausibly
		// far in the future is just as suspicious as one far in the past.
		$request = $this->mailgun_request( (string) ( time() + 1000 ), bin2hex( random_bytes( 16 ) ) );

		$result = ( new Webhook() )->verify_signature( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_webhook_stale_timestamp', $result->get_error_code() );
	}

	public function test_mailgun_non_numeric_timestamp_is_rejected_as_stale(): void {
		$this->set_secret();

		// A genuinely signed triple (the HMAC is computed over the exact same
		// literal string) whose "timestamp" isn't a real timestamp at all —
		// ctype_digit() must reject this rather than letting a non-numeric
		// value silently pass the freshness math.
		$token   = bin2hex( random_bytes( 16 ) );
		$request = $this->mailgun_request( 'not-a-real-timestamp', $token );

		$result = ( new Webhook() )->verify_signature( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_webhook_stale_timestamp', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Mailgun scheme — replay memory
	// -------------------------------------------------------------------------

	public function test_mailgun_replayed_token_is_rejected_on_second_use(): void {
		$this->set_secret();

		$request = $this->mailgun_request( (string) time(), bin2hex( random_bytes( 16 ) ) );

		$first  = ( new Webhook() )->verify_signature( $request );
		$second = ( new Webhook() )->verify_signature( $request ); // Identical request, replayed.

		$this->assertTrue( $first, 'The first use of a fresh, validly-signed request must succeed.' );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'agnosis_webhook_replay', $second->get_error_code() );
	}

	public function test_mailgun_different_tokens_are_not_confused_as_replays_of_each_other(): void {
		$this->set_secret();

		$timestamp = (string) time();
		$first     = ( new Webhook() )->verify_signature( $this->mailgun_request( $timestamp, bin2hex( random_bytes( 16 ) ) ) );
		$second    = ( new Webhook() )->verify_signature( $this->mailgun_request( $timestamp, bin2hex( random_bytes( 16 ) ) ) );

		$this->assertTrue( $first );
		$this->assertTrue( $second, 'Two distinct genuine requests sharing a timestamp (but not a token) must not be treated as a replay of one another.' );
	}

	// -------------------------------------------------------------------------
	// Generic X-Agnosis-Signature scheme — opt-in X-Agnosis-Timestamp header
	// -------------------------------------------------------------------------

	/**
	 * Builds a fresh, uniquely-bodied signed request per call (a random nonce
	 * folded into the body) so two different tests calling this helper with
	 * the same wall-clock timestamp() never produce byte-identical signatures
	 * — which would collide in the shared, process-lifetime replay-memory
	 * transient (dev/bootstrap.php's get_transient()/set_transient() stubs
	 * aren't reset between tests) and make one test's "first use succeeds"
	 * call spuriously look like a replay of an earlier, unrelated test.
	 * verify_signature() only ever uses the raw body as HMAC input (never
	 * parses it), so varying it here changes nothing about what's tested.
	 * A single test that wants an actual replay (reusing the identical
	 * request twice) still gets one — it just calls this helper once and
	 * reuses the returned object for both verify_signature() calls.
	 */
	private function generic_request_with_timestamp( string $timestamp ): \WP_REST_Request {
		$body      = self::TEST_BODY . '|' . bin2hex( random_bytes( 8 ) );
		$signature = hash_hmac( 'sha256', $timestamp . $body, self::TEST_SECRET );
		return new \WP_REST_Request(
			params:  [],
			headers: [
				'x-agnosis-signature' => $signature,
				'x-agnosis-timestamp' => $timestamp,
			],
			body: $body
		);
	}

	public function test_generic_hmac_with_fresh_timestamp_header_succeeds(): void {
		$this->set_secret();

		$request = $this->generic_request_with_timestamp( (string) time() );

		$this->assertTrue( ( new Webhook() )->verify_signature( $request ) );
	}

	public function test_generic_hmac_with_stale_timestamp_header_is_rejected(): void {
		$this->set_secret();

		$request = $this->generic_request_with_timestamp( (string) ( time() - 1000 ) );

		$result = ( new Webhook() )->verify_signature( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_webhook_stale_timestamp', $result->get_error_code() );
	}

	public function test_generic_hmac_with_timestamp_header_rejects_replay(): void {
		$this->set_secret();

		$request = $this->generic_request_with_timestamp( (string) time() );

		$first  = ( new Webhook() )->verify_signature( $request );
		$second = ( new Webhook() )->verify_signature( $request );

		$this->assertTrue( $first );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'agnosis_webhook_replay', $second->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Generic X-Agnosis-Signature scheme — back-compat, no timestamp header
	// -------------------------------------------------------------------------

	public function test_generic_hmac_without_timestamp_header_still_validates_body_only(): void {
		$this->set_secret();

		// No X-Agnosis-Timestamp header at all — senders not yet upgraded must
		// keep working exactly as before this fix (body-only HMAC, no replay
		// protection element to check without a timestamp to anchor it to).
		$signature = hash_hmac( 'sha256', self::TEST_BODY, self::TEST_SECRET );
		$request   = new \WP_REST_Request(
			params:  [],
			headers: [ 'x-agnosis-signature' => $signature ],
			body: self::TEST_BODY
		);

		$this->assertTrue( ( new Webhook() )->verify_signature( $request ) );
	}

	public function test_generic_hmac_without_timestamp_header_is_not_replay_protected(): void {
		$this->set_secret();

		// Documents the deliberate scope limit: without an X-Agnosis-Timestamp
		// header, there is no replay-memory key to check at all, so the exact
		// same signed body validates every time — unlike the two schemes above.
		$signature = hash_hmac( 'sha256', self::TEST_BODY, self::TEST_SECRET );
		$request   = new \WP_REST_Request(
			params:  [],
			headers: [ 'x-agnosis-signature' => $signature ],
			body: self::TEST_BODY
		);

		$first  = ( new Webhook() )->verify_signature( $request );
		$second = ( new Webhook() )->verify_signature( $request );

		$this->assertTrue( $first );
		$this->assertTrue( $second, 'Back-compat: a sender not yet sending X-Agnosis-Timestamp is unprotected against replay, by design — this must not regress into a hard failure.' );
	}
}
