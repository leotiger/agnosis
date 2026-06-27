<?php
/**
 * Integration tests for Network\HttpSignature.
 *
 * Uses a real, freshly-generated RSA-2048 keypair to exercise the full
 * verification chain — signature header parsing → date freshness → digest
 * check → actor fetch → signing string reconstruction → openssl_verify().
 *
 * The remote actor HTTP fetch is intercepted via the 'pre_http_request'
 * filter so no real network calls are made.  Transients are cleared between
 * tests to prevent key-cache leakage.
 *
 * Tests are organised by which guard fires first in HttpSignature::verify():
 *   1. Missing Signature header   → 401
 *   2. Malformed Signature header → 400
 *   3. Stale Date header          → 401
 *   4. Digest mismatch            → 400
 *   5. Actor fetch failure        → 502
 *   6. Bad signature value        → 403
 *   7. Full valid request         → true
 *   8. Key transient caching      → only one HTTP fetch per keyId
 *
 * Private methods are exercised indirectly; parse_signature_header() and
 * build_signing_string() are tested via direct Reflection where the happy-path
 * test alone does not reach every branch.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\HttpSignature;

class HttpSignatureTest extends \WP_UnitTestCase {

	// ── RSA keypair (generated once per class, shared across tests) ──────────

	private static string $private_key_pem;
	private static string $public_key_pem;

	/** Actor / keyId used across every test that needs a valid key. */
	private const ACTOR_URL = 'https://mastodon.example/users/testactor';
	private const KEY_ID    = self::ACTOR_URL . '#main-key';
	// Cache key prefix; full key = 'agnosis_ap_key_' . md5( self::KEY_ID ), computed per test.

	// ── One-time key generation ───────────────────────────────────────────────

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return; // openssl not available; tests will be skipped.
		}

		$resource = openssl_pkey_new( [
			'digest_alg'       => 'sha256',
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		] );

		$private_pem = '';
		openssl_pkey_export( $resource, $private_pem );
		self::$private_key_pem = $private_pem;

		$details              = openssl_pkey_get_details( $resource );
		self::$public_key_pem = (string) $details['key'];
	}

	// ── Per-test setUp ───────────────────────────────────────────────────────

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'OpenSSL extension not available.' );
		}

		// Clear the key transient so cache tests start clean.
		delete_transient( 'agnosis_ap_key_' . md5( self::KEY_ID ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Register a pre_http_request filter that serves the test actor document.
	 *
	 * @param string $public_key_pem PEM to include in publicKey.publicKeyPem.
	 * @param int    $http_status    Response code to simulate (default 200).
	 */
	private function mock_actor_fetch( string $public_key_pem = '', int $http_status = 200 ): void {
		$pem = $public_key_pem ?: self::$public_key_pem;
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( $pem, $http_status ) {
				if ( strpos( $url, self::ACTOR_URL ) !== false ) {
					if ( 200 !== $http_status ) {
						return [
							'response' => [ 'code' => $http_status, 'message' => 'Error' ],
							'headers'  => [],
							'body'     => '',
							'cookies'  => [],
							'filename' => '',
						];
					}
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [
							'type'      => 'Person',
							'id'        => self::ACTOR_URL,
							'publicKey' => [
								'id'           => self::KEY_ID,
								'owner'        => self::ACTOR_URL,
								'publicKeyPem' => $pem,
							],
						] ),
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Build a signed WP_REST_Request for the inbox route.
	 *
	 * @param array<string, mixed> $overrides Override specific request components:
	 *   'body'         — raw JSON body string
	 *   'date'         — Date header value
	 *   'include_digest' — bool, whether to add Digest header (default true)
	 *   'private_key'  — PEM string to sign with (default self::$private_key_pem)
	 *   'signed_headers' — space-separated header list (default '(request-target) host date digest')
	 *   'tamper_sig'   — bool, corrupt the signature before returning
	 * @return WP_REST_Request
	 */
	private function build_signed_request( array $overrides = [] ): \WP_REST_Request {
		$body           = $overrides['body']           ?? (string) wp_json_encode( [ 'type' => 'Follow', 'actor' => self::ACTOR_URL ] );
		$date           = $overrides['date']           ?? gmdate( 'D, d M Y H:i:s \G\M\T' );
		$include_digest = $overrides['include_digest'] ?? true;
		$priv_key       = $overrides['private_key']   ?? self::$private_key_pem;
		$signed_headers = $overrides['signed_headers'] ?? '(request-target) host date' . ( $include_digest ? ' digest' : '' );
		$tamper         = $overrides['tamper_sig']     ?? false;

		$inbox_path = '/' . rest_get_url_prefix() . '/agnosis/v1/activitypub/inbox';
		$host       = (string) wp_parse_url( rest_url( '/' ), PHP_URL_HOST );
		$digest     = 'SHA-256=' . base64_encode( hash( 'sha256', $body, true ) );

		// Build signing string in the declared header order.
		$parts = [];
		foreach ( explode( ' ', $signed_headers ) as $h ) {
			switch ( $h ) {
				case '(request-target)':
					$parts[] = "(request-target): post {$inbox_path}";
					break;
				case 'host':
					$parts[] = "host: {$host}";
					break;
				case 'date':
					$parts[] = "date: {$date}";
					break;
				case 'digest':
					$parts[] = "digest: {$digest}";
					break;
			}
		}
		$signing_string = implode( "\n", $parts );

		openssl_sign( $signing_string, $raw_sig, $priv_key, OPENSSL_ALGO_SHA256 );
		if ( $tamper ) {
			$raw_sig = 'tampered' . $raw_sig; // corrupt the bytes.
		}
		$sig_b64 = base64_encode( $raw_sig );

		$sig_header = 'keyId="' . self::KEY_ID . '"'
			. ',algorithm="rsa-sha256"'
			. ',headers="' . $signed_headers . '"'
			. ',signature="' . $sig_b64 . '"';

		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'signature', $sig_header );
		$request->set_header( 'date', $date );
		$request->set_header( 'host', $host );
		if ( $include_digest ) {
			$request->set_header( 'digest', $digest );
		}
		$request->set_body( $body );

		return $request;
	}

	// ── 1. Missing Signature header ───────────────────────────────────────────

	public function test_verify_returns_401_when_signature_header_is_absent(): void {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_missing', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	// ── 2. Malformed Signature header ─────────────────────────────────────────

	public function test_verify_returns_400_when_signature_header_has_no_key_id(): void {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		// headers and signature present but keyId missing.
		$request->set_header( 'signature', 'headers="date",signature="abc123"' );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_malformed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_verify_returns_400_when_signature_header_is_completely_garbled(): void {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'signature', 'not-a-valid-signature-header' );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_malformed', $result->get_error_code() );
	}

	// ── 3. Stale Date header ──────────────────────────────────────────────────

	public function test_verify_returns_401_when_date_is_more_than_12_hours_old(): void {
		$this->mock_actor_fetch();

		$stale_date = gmdate( 'D, d M Y H:i:s \G\M\T', time() - ( 13 * HOUR_IN_SECONDS ) );
		$request    = $this->build_signed_request( [ 'date' => $stale_date ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_stale', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_verify_returns_401_when_date_is_more_than_12_hours_in_future(): void {
		$this->mock_actor_fetch();

		$future_date = gmdate( 'D, d M Y H:i:s \G\M\T', time() + ( 13 * HOUR_IN_SECONDS ) );
		$request     = $this->build_signed_request( [ 'date' => $future_date ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_stale', $result->get_error_code() );
	}

	// ── 4. Digest mismatch ────────────────────────────────────────────────────

	public function test_verify_returns_400_when_digest_does_not_match_body(): void {
		$this->mock_actor_fetch();

		$request = $this->build_signed_request();
		// Corrupt the body after signing so the digest no longer matches.
		$request->set_body( '{"type":"Follow","actor":"' . self::ACTOR_URL . '","EXTRA":"tampered"}' );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_digest', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// ── 5. Actor fetch failure ────────────────────────────────────────────────

	public function test_verify_returns_502_when_actor_document_cannot_be_fetched(): void {
		// Simulate a network error from wp_remote_get.
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'Connection refused' );
			}
		);

		$request = $this->build_signed_request( [ 'include_digest' => false ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_key_fetch_failed', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	public function test_verify_returns_502_when_actor_document_returns_non_200(): void {
		$this->mock_actor_fetch( '', 404 );

		$request = $this->build_signed_request( [ 'include_digest' => false ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_key_fetch_failed', $result->get_error_code() );
	}

	public function test_verify_returns_502_when_actor_document_has_no_public_key(): void {
		// Serve an actor document without publicKey.
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) {
				if ( strpos( $url, self::ACTOR_URL ) !== false ) {
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [ 'type' => 'Person', 'id' => self::ACTOR_URL ] ),
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$request = $this->build_signed_request( [ 'include_digest' => false ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_key_not_found', $result->get_error_code() );
	}

	// ── 6. Cryptographically invalid signature ────────────────────────────────

	public function test_verify_returns_403_when_signature_bytes_are_wrong(): void {
		$this->mock_actor_fetch();

		$request = $this->build_signed_request( [ 'tamper_sig' => true ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_invalid', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_verify_returns_403_when_signed_with_wrong_key(): void {
		$this->mock_actor_fetch(); // actor document will advertise the test class public key

		// Generate a different private key and sign with it.
		$other = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $other, $other_private );

		$request = $this->build_signed_request( [ 'private_key' => $other_private ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_invalid', $result->get_error_code() );
	}

	// ── 7. Full valid request ─────────────────────────────────────────────────

	public function test_verify_returns_true_for_correctly_signed_request(): void {
		$this->mock_actor_fetch();

		$request = $this->build_signed_request();

		$result = HttpSignature::verify( $request );

		$this->assertTrue( $result );
	}

	public function test_verify_returns_true_when_digest_header_is_absent(): void {
		// Not all Fediverse servers include a Digest — we only verify it when present.
		$this->mock_actor_fetch();

		$request = $this->build_signed_request( [
			'include_digest' => false,
			'signed_headers' => '(request-target) host date',
		] );

		$result = HttpSignature::verify( $request );

		$this->assertTrue( $result );
	}

	public function test_verify_accepts_request_just_within_12_hour_window(): void {
		$this->mock_actor_fetch();

		$almost_stale = gmdate( 'D, d M Y H:i:s \G\M\T', time() - ( 11 * HOUR_IN_SECONDS ) );
		$request      = $this->build_signed_request( [ 'date' => $almost_stale ] );

		$result = HttpSignature::verify( $request );

		$this->assertTrue( $result );
	}

	// ── 8. Key transient caching ──────────────────────────────────────────────

	public function test_public_key_is_fetched_only_once_per_key_id(): void {
		$fetch_count = 0;

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$fetch_count ) {
				if ( strpos( $url, self::ACTOR_URL ) !== false ) {
					$fetch_count++;
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [
							'publicKey' => [ 'publicKeyPem' => self::$public_key_pem ],
						] ),
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);

		// First call — hits the filter.
		$request = $this->build_signed_request();
		HttpSignature::verify( $request );

		// Second call — should be served from the transient, not the filter.
		$request2 = $this->build_signed_request();
		HttpSignature::verify( $request2 );

		$this->assertSame( 1, $fetch_count, 'Actor document should be fetched only once.' );
	}

	// ── Private method: parse_signature_header ────────────────────────────────

	public function test_parse_signature_header_extracts_all_fields(): void {
		$rc     = new \ReflectionClass( HttpSignature::class );
		$method = $rc->getMethod( 'parse_signature_header' );
		$method->setAccessible( true );

		$header = 'keyId="https://example.com/actor#main-key",'
			. 'algorithm="rsa-sha256",'
			. 'headers="(request-target) host date digest",'
			. 'signature="abc123def456"';

		/** @var array<string,string> $result */
		$result = $method->invoke( null, $header );

		$this->assertSame( 'https://example.com/actor#main-key', $result['keyId'] );
		$this->assertSame( 'rsa-sha256', $result['algorithm'] );
		$this->assertSame( '(request-target) host date digest', $result['headers'] );
		$this->assertSame( 'abc123def456', $result['signature'] );
	}

	public function test_parse_signature_header_returns_empty_array_for_garbage(): void {
		$rc     = new \ReflectionClass( HttpSignature::class );
		$method = $rc->getMethod( 'parse_signature_header' );
		$method->setAccessible( true );

		/** @var array<string,string> $result */
		$result = $method->invoke( null, 'not-a-signature-header' );

		$this->assertEmpty( $result );
	}

	// ── Private method: build_signing_string ──────────────────────────────────

	public function test_build_signing_string_includes_request_target_with_post_method(): void {
		$rc     = new \ReflectionClass( HttpSignature::class );
		$method = $rc->getMethod( 'build_signing_string' );
		$method->setAccessible( true );

		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'date', 'Mon, 01 Jan 2024 12:00:00 GMT' );

		/** @var string $signing_string */
		$signing_string = $method->invoke( null, $request, '(request-target) date' );
		$lines          = explode( "\n", $signing_string );

		$this->assertStringStartsWith( '(request-target): post ', $lines[0] );
		$this->assertStringContainsString( 'agnosis/v1/activitypub/inbox', $lines[0] );
		$this->assertSame( 'date: Mon, 01 Jan 2024 12:00:00 GMT', $lines[1] );
	}
}
