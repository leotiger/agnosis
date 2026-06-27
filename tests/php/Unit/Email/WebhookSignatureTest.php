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

use Agnosis\Email\Webhook;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase {

	/** @var array<string, mixed> Shared option store for the namespace override in Stubs/email_namespace_stubs.php. */
	public static array $options = [];

	private const TEST_SECRET = 'super-secret-key-for-tests';
	private const TEST_BODY   = '{"sender":"artist@example.com","subject":"My art"}';

	protected function setUp(): void {
		parent::setUp();
		self::$options = [];
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
}
