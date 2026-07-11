<?php
/**
 * Unit tests for Webhook::verify_signature().
 *
 * This is a security-critical method — it is the gate that prevents
 * unauthenticated POST requests from injecting submissions into the queue.
 *
 * Two signing schemes are tested:
 *   1. Generic HMAC-SHA256 via X-Agnosis-Signature header.
 *   2. Mailgun-style three-parameter signing (timestamp + token + signature).
 *
 * The namespace-scoped get_option() override in Stubs/email_namespace_stubs.php
 * is loaded by the unit bootstrap and reads from the public static $options
 * property so each test can control returned values without WP.
 *
 * @package Agnosis\Tests\Unit\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Email;

use Agnosis\Core\RateLimiter;
use Agnosis\Email\Webhook;
use PHPUnit\Framework\TestCase;
use WP_Error;

class WebhookSignatureTest extends TestCase {

	/** @var array<string, mixed> Shared option store for the namespace override in Stubs/email_namespace_stubs.php. */
	public static array $options = [];

	private const TEST_SECRET = 'super-secret-key-for-tests';
	private const TEST_BODY   = '{"sender":"artist@example.com","subject":"My art"}';

	protected function setUp(): void {
		parent::setUp();
		self::$options = [];
		// Bucket split (sixth audit §3c) — reset both so this file's own test
		// count never depends on what ran before it in the same PHPUnit
		// process (transients are a plain in-memory array for the whole run,
		// per dev/bootstrap.php's stub).
		RateLimiter::reset( 'email_inbound_verified', RateLimiter::client_ip(), 60 );
		RateLimiter::reset( 'email_inbound_unverified', RateLimiter::client_ip(), 60 );
	}

	protected function tearDown(): void {
		self::$options = [];
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// No secret configured → always reject
	// -------------------------------------------------------------------------

	public function test_returns_error_when_no_secret_configured(): void {
		// No option set → get_option returns '' (the default).
		$request = new \WP_REST_Request();

		$result = ( new Webhook() )->verify_signature( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_no_secret', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Generic HMAC-SHA256 (X-Agnosis-Signature header)
	// -------------------------------------------------------------------------

	public function test_valid_generic_hmac_returns_true(): void {
		self::$options['agnosis_webhook_secret'] = self::TEST_SECRET;

		$signature = hash_hmac( 'sha256', self::TEST_BODY, self::TEST_SECRET );
		$request   = new \WP_REST_Request(
			params:  [],
			headers: [ 'x-agnosis-signature' => $signature ],
			body:    self::TEST_BODY
		);

		$this->assertTrue( ( new Webhook() )->verify_signature( $request ) );
	}

	public function test_invalid_generic_hmac_returns_false(): void {
		self::$options['agnosis_webhook_secret'] = self::TEST_SECRET;

		$request = new \WP_REST_Request(
			params:  [],
			headers: [ 'x-agnosis-signature' => 'definitely-wrong-signature' ],
			body:    self::TEST_BODY
		);

		$this->assertFalse( ( new Webhook() )->verify_signature( $request ) );
	}

	public function test_generic_hmac_with_wrong_secret_returns_false(): void {
		self::$options['agnosis_webhook_secret'] = self::TEST_SECRET;

		$wrong_sig = hash_hmac( 'sha256', self::TEST_BODY, 'wrong-secret' );
		$request   = new \WP_REST_Request(
			params:  [],
			headers: [ 'x-agnosis-signature' => $wrong_sig ],
			body:    self::TEST_BODY
		);

		$this->assertFalse( ( new Webhook() )->verify_signature( $request ) );
	}

	public function test_generic_hmac_with_tampered_body_returns_false(): void {
		self::$options['agnosis_webhook_secret'] = self::TEST_SECRET;

		// Signature is correct for original body, but body has been tampered with.
		$signature = hash_hmac( 'sha256', self::TEST_BODY, self::TEST_SECRET );
		$request   = new \WP_REST_Request(
			params:  [],
			headers: [ 'x-agnosis-signature' => $signature ],
			body:    self::TEST_BODY . '{"injected":true}'
		);

		$this->assertFalse( ( new Webhook() )->verify_signature( $request ) );
	}

	// -------------------------------------------------------------------------
	// Mailgun signing scheme
	// -------------------------------------------------------------------------

	private function mailgun_signature( string $timestamp, string $token, string $secret ): string {
		return hash_hmac( 'sha256', $timestamp . $token, $secret );
	}

	public function test_valid_mailgun_signature_returns_true(): void {
		self::$options['agnosis_webhook_secret'] = self::TEST_SECRET;

		$timestamp = (string) time();
		$token     = bin2hex( random_bytes( 16 ) );
		$sig       = $this->mailgun_signature( $timestamp, $token, self::TEST_SECRET );

		$request = new \WP_REST_Request( params: [
			'timestamp' => $timestamp,
			'token'     => $token,
			'signature' => $sig,
		] );

		$this->assertTrue( ( new Webhook() )->verify_signature( $request ) );
	}

	public function test_invalid_mailgun_signature_returns_false(): void {
		self::$options['agnosis_webhook_secret'] = self::TEST_SECRET;

		$timestamp = (string) time();
		$token     = bin2hex( random_bytes( 16 ) );

		$request = new \WP_REST_Request( params: [
			'timestamp' => $timestamp,
			'token'     => $token,
			'signature' => 'not-the-right-signature',
		] );

		$this->assertFalse( ( new Webhook() )->verify_signature( $request ) );
	}

	public function test_mailgun_with_tampered_timestamp_returns_false(): void {
		self::$options['agnosis_webhook_secret'] = self::TEST_SECRET;

		$real_ts = (string) time();
		$token   = bin2hex( random_bytes( 16 ) );
		$sig     = $this->mailgun_signature( $real_ts, $token, self::TEST_SECRET );

		// Same token/signature, but timestamp changed — invalidates the HMAC.
		$request = new \WP_REST_Request( params: [
			'timestamp' => (string) ( (int) $real_ts + 999 ),
			'token'     => $token,
			'signature' => $sig,
		] );

		$this->assertFalse( ( new Webhook() )->verify_signature( $request ) );
	}

	// -------------------------------------------------------------------------
	// No signature present at all
	// -------------------------------------------------------------------------

	public function test_returns_error_when_no_signature_provided(): void {
		self::$options['agnosis_webhook_secret'] = self::TEST_SECRET;

		// Request with neither X-Agnosis-Signature header nor Mailgun params.
		$request = new \WP_REST_Request();

		$result = ( new Webhook() )->verify_signature( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_invalid_signature', $result->get_error_code() );
	}

	public function test_returns_error_when_only_partial_mailgun_params_present(): void {
		self::$options['agnosis_webhook_secret'] = self::TEST_SECRET;

		// Only timestamp, missing token and signature → falls through to the error.
		$request = new \WP_REST_Request( params: [ 'timestamp' => '12345' ] );

		$result = ( new Webhook() )->verify_signature( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// -------------------------------------------------------------------------
	// Rate-limit bucket split (sixth audit §3c)
	//
	// Previously a single 'email_inbound' bucket (60/60s, IP-keyed) was
	// checked BEFORE the signature — on a host where a misconfigured reverse
	// proxy collapses every REMOTE_ADDR into one value, that bucket becomes
	// effectively global, so an unauthenticated flood of garbage could starve
	// the ESP's own legitimate signed traffic in the same window. The
	// signature is now matched first, and the matched/unmatched outcomes use
	// two separate buckets (UNVERIFIED_RATE_LIMIT=10, VERIFIED_RATE_LIMIT=300,
	// both per 60s) — these tests can't directly observe "which bucket", but
	// they can observe the outcome: exhausting one must never affect the
	// other.
	// -------------------------------------------------------------------------

	public function test_unverified_requests_are_throttled_after_ten_within_the_window(): void {
		self::$options['agnosis_webhook_secret'] = self::TEST_SECRET;

		for ( $i = 0; $i < 10; $i++ ) {
			$request = new \WP_REST_Request(
				headers: [ 'x-agnosis-signature' => 'definitely-wrong-signature' ],
				body: self::TEST_BODY
			);
			$result = ( new Webhook() )->verify_signature( $request );
			$this->assertFalse( $result, "Attempt #{$i} with a bad signature must return false — the tight bucket has room left." );
		}

		// The 11th unverified attempt within the same 60s window must now be
		// throttled — UNVERIFIED_RATE_LIMIT is 10.
		$request = new \WP_REST_Request(
			headers: [ 'x-agnosis-signature' => 'definitely-wrong-signature' ],
			body: self::TEST_BODY
		);
		$result = ( new Webhook() )->verify_signature( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data( 'agnosis_rate_limit' );
		$this->assertSame( 429, $data['status'] ?? null );
	}

	public function test_verified_requests_use_a_separate_bucket_from_unverified(): void {
		self::$options['agnosis_webhook_secret'] = self::TEST_SECRET;

		// Exhaust the tight unverified bucket with garbage first.
		for ( $i = 0; $i < 10; $i++ ) {
			( new Webhook() )->verify_signature( new \WP_REST_Request(
				headers: [ 'x-agnosis-signature' => 'definitely-wrong-signature' ],
				body: self::TEST_BODY
			) );
		}
		$throttled = ( new Webhook() )->verify_signature( new \WP_REST_Request(
			headers: [ 'x-agnosis-signature' => 'definitely-wrong-signature' ],
			body: self::TEST_BODY
		) );
		$this->assertInstanceOf( WP_Error::class, $throttled, 'Sanity check: the unverified bucket must actually be exhausted at this point.' );

		// A validly signed request must still succeed — proving it draws from
		// the separate, generous "verified" bucket, not the exhausted one.
		$signature = hash_hmac( 'sha256', self::TEST_BODY, self::TEST_SECRET );
		$verified_request = new \WP_REST_Request(
			headers: [ 'x-agnosis-signature' => $signature ],
			body: self::TEST_BODY
		);

		$this->assertTrue(
			( new Webhook() )->verify_signature( $verified_request ),
			'A validly signed request must succeed even while the unverified bucket is fully exhausted.'
		);
	}
}
