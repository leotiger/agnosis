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
 *   9. keyId↔actor binding (§3b)  → verify_actor_binding(): exact match of
 *      the keyId's base URL against the activity's actor id, 401 otherwise.
 *      Note: build_signed_request() deliberately sets no Content-Type, so
 *      these tests also cover the raw-body fallback path.
 *  10. verify_with_key() (§2d)    → same signature check, but against a
 *      caller-supplied public key instead of one fetched from a remote actor
 *      document — used by Node::register_peer(). Confirms it accepts a
 *      correctly self-signed request, rejects one signed with a different
 *      key than the one presented, and never makes an outbound HTTP call.
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
		$route          = $overrides['route']          ?? '/agnosis/v1/activitypub/inbox';
		$body           = $overrides['body']           ?? (string) wp_json_encode( [ 'type' => 'Follow', 'actor' => self::ACTOR_URL ] );
		$date           = $overrides['date']           ?? gmdate( 'D, d M Y H:i:s \G\M\T' );
		$include_digest = $overrides['include_digest'] ?? true;
		$priv_key       = $overrides['private_key']   ?? self::$private_key_pem;
		$signed_headers = $overrides['signed_headers'] ?? '(request-target) host date' . ( $include_digest ? ' digest' : '' );
		$tamper         = $overrides['tamper_sig']     ?? false;

		$request_path = '/' . rest_get_url_prefix() . $route;
		$host         = (string) wp_parse_url( rest_url( '/' ), PHP_URL_HOST );
		$digest       = 'SHA-256=' . base64_encode( hash( 'sha256', $body, true ) );

		// Build signing string in the declared header order.
		$parts = [];
		foreach ( explode( ' ', $signed_headers ) as $h ) {
			switch ( $h ) {
				case '(request-target)':
					$parts[] = "(request-target): post {$request_path}";
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

		$request = new \WP_REST_Request( 'POST', $route );
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

		// Audit §3g note i made digest mandatory on every POST's signed-header
		// list, so these actor-fetch-failure tests (which exercise a later
		// stage of verify()) now need a real, correctly-signed digest to get
		// past the earlier strictness check — build_signed_request()'s default
		// already computes and signs one.
		$request = $this->build_signed_request();

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_key_fetch_failed', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	public function test_verify_returns_502_when_actor_document_returns_non_200(): void {
		$this->mock_actor_fetch( '', 404 );

		// Audit §3g note i made digest mandatory on every POST's signed-header
		// list, so these actor-fetch-failure tests (which exercise a later
		// stage of verify()) now need a real, correctly-signed digest to get
		// past the earlier strictness check — build_signed_request()'s default
		// already computes and signs one.
		$request = $this->build_signed_request();

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

		// Audit §3g note i made digest mandatory on every POST's signed-header
		// list, so these actor-fetch-failure tests (which exercise a later
		// stage of verify()) now need a real, correctly-signed digest to get
		// past the earlier strictness check — build_signed_request()'s default
		// already computes and signs one.
		$request = $this->build_signed_request();

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

	public function test_verify_rejects_post_request_when_digest_not_signed(): void {
		// Audit §3g note i: a POST whose signature omits "digest" from its
		// headers list used to verify fine — the digest check only ever ran
		// when "digest" happened to be present, so this was a silent gap, not
		// an enforced optional. Mastodon (and friends) reject exactly this
		// shape outright; we now mirror that rule for symmetry with what we
		// send ourselves per §3a.
		$this->mock_actor_fetch();

		$request = $this->build_signed_request( [
			'include_digest' => false,
			'signed_headers' => '(request-target) host date',
		] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_no_digest', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
	}

	public function test_verify_rejects_request_when_date_not_signed(): void {
		// Same note i: a signature covering only "(request-target) host"
		// verifies fine but silently defeats the freshness check, since that
		// check only ran when a Date header happened to be present.
		$this->mock_actor_fetch();

		$request = $this->build_signed_request( [
			'signed_headers' => '(request-target) host',
		] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_no_date', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
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

	// ── Audit §3g note ii: negative key cache ─────────────────────────────────

	public function test_repeated_requests_for_a_failing_key_id_only_fetch_once(): void {
		$fetch_count = 0;

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$fetch_count ) {
				if ( strpos( $url, self::ACTOR_URL ) !== false ) {
					$fetch_count++;
					return new \WP_Error( 'http_request_failed', 'Connection refused' );
				}
				return $preempt;
			},
			10,
			3
		);

		// First call fails and should cache the failure.
		$result1 = HttpSignature::verify( $this->build_signed_request() );
		$this->assertInstanceOf( \WP_Error::class, $result1 );
		$this->assertSame( 'ap_key_fetch_failed', $result1->get_error_code() );

		// Second call, same keyId — should be served from the negative cache,
		// not the outbound filter again (the amplification vector §3g note ii
		// closes: a flood of requests against one bad keyId now costs one real
		// fetch, not one per request).
		$result2 = HttpSignature::verify( $this->build_signed_request() );
		$this->assertInstanceOf( \WP_Error::class, $result2 );
		$this->assertSame( 'ap_key_fetch_failed', $result2->get_error_code() );

		$this->assertSame( 1, $fetch_count, 'A failing actor-document fetch should only be attempted once within the negative-cache window.' );
	}

	public function test_negative_cache_is_distinct_from_the_success_cache(): void {
		// A keyId that fails once, then succeeds on a later attempt against a
		// DIFFERENT keyId, must not be blocked by the other key's failure
		// entry — the negative cache is per-keyId (md5 of the full keyId),
		// same as the success cache.
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) {
				if ( strpos( $url, self::ACTOR_URL ) !== false ) {
					return new \WP_Error( 'http_request_failed', 'Connection refused' );
				}
				if ( strpos( $url, 'https://another.example/actor' ) !== false ) {
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

		$failing_request = $this->build_signed_request();
		$failing_result   = HttpSignature::verify( $failing_request );
		$this->assertInstanceOf( \WP_Error::class, $failing_result );

		$other_request = $this->build_signed_request( [
			'route' => '/agnosis/v1/activitypub/inbox',
		] );
		// Sign with a different keyId so it isn't served from the first key's
		// (negative) cache entry.
		$other_request->set_header(
			'signature',
			str_replace( self::KEY_ID, 'https://another.example/actor#main-key', (string) $other_request->get_header( 'signature' ) )
		);

		$other_result = HttpSignature::verify( $other_request );
		$this->assertTrue( $other_result, 'A different keyId must not be blocked by another keyId\'s cached failure.' );
	}

	// ── Audit §3b: SSRF guard on the actor-document fetch ─────────────────────

	public function test_fetch_public_key_uses_wp_safe_remote_get(): void {
		// keyId is attacker-controlled (it comes straight from the inbound
		// Signature header), so fetch_public_key() must use wp_safe_remote_get()
		// rather than wp_remote_get(). That's what sets 'reject_unsafe_urls',
		// which is what makes WP core reject private/loopback/link-local/ULA
		// targets (re-checked on every redirect). Assert the flag is present
		// on the outgoing request rather than re-testing WP core's own IP
		// range logic.
		$seen_reject_flag = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$seen_reject_flag ) {
				if ( strpos( $url, self::ACTOR_URL ) !== false ) {
					$seen_reject_flag = $args['reject_unsafe_urls'] ?? null;
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

		$request = $this->build_signed_request();
		HttpSignature::verify( $request );

		$this->assertTrue( $seen_reject_flag, 'fetch_public_key() must request with reject_unsafe_urls => true.' );
	}

	// ── 9. Audit §3b: keyId↔actor binding ─────────────────────────────────────

	public function test_actor_binding_passes_when_key_owner_matches_actor(): void {
		// Default body claims actor === ACTOR_URL and keyId is ACTOR_URL#main-key.
		$request = $this->build_signed_request();

		$this->assertTrue( HttpSignature::verify_actor_binding( $request ) );
	}

	public function test_actor_binding_rejects_actor_forged_in_anothers_name(): void {
		// THE §3b abuse: a correctly-signed request (attacker's own valid key
		// at their own keyId) whose body claims someone else's actor id.
		$request = $this->build_signed_request( [
			'body' => (string) wp_json_encode( [ 'type' => 'Follow', 'actor' => 'https://mastodon.social/users/victim' ] ),
		] );

		$result = HttpSignature::verify_actor_binding( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_actor_mismatch', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_actor_binding_accepts_embedded_actor_object_with_matching_id(): void {
		// AS2 allows the actor to be an embedded object; its id carries the claim.
		$request = $this->build_signed_request( [
			'body' => (string) wp_json_encode( [ 'type' => 'Follow', 'actor' => [ 'type' => 'Person', 'id' => self::ACTOR_URL ] ] ),
		] );

		$this->assertTrue( HttpSignature::verify_actor_binding( $request ) );
	}

	public function test_actor_binding_rejects_embedded_actor_object_with_forged_id(): void {
		$request = $this->build_signed_request( [
			'body' => (string) wp_json_encode( [ 'type' => 'Follow', 'actor' => [ 'type' => 'Person', 'id' => 'https://mastodon.social/users/victim' ] ] ),
		] );

		$result = HttpSignature::verify_actor_binding( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_actor_mismatch', $result->get_error_code() );
	}

	public function test_actor_binding_rejects_activity_without_actor(): void {
		// No actor claim at all: nothing to bind, so nothing to act on — 401.
		$request = $this->build_signed_request( [
			'body' => (string) wp_json_encode( [ 'type' => 'Follow' ] ),
		] );

		$result = HttpSignature::verify_actor_binding( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_actor_mismatch', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_actor_binding_reads_json_content_type_body_too(): void {
		// Belt-and-braces for the primary path: with a proper JSON media type,
		// get_json_params() supplies the activity instead of the raw-body fallback.
		$request = $this->build_signed_request();
		$request->set_header( 'Content-Type', 'application/activity+json' );

		$this->assertTrue( HttpSignature::verify_actor_binding( $request ) );
	}

	// ── Audit §2d: verify_with_key() — caller-supplied public key ─────────────
	// Used by Node::register_peer(): the public key arrives inline in the
	// request body (no remote actor document), so verification is against
	// that presented key directly. These tests build a signed request for
	// the peers route and never mock pre_http_request — verify_with_key()
	// must not make any outbound HTTP call at all.

	public function test_verify_with_key_returns_true_for_correctly_signed_request(): void {
		$request = $this->build_signed_request( [
			'route' => '/agnosis/v1/node/peers',
			'body'  => (string) wp_json_encode( [ 'url' => self::ACTOR_URL, 'publicKey' => self::$public_key_pem ] ),
		] );

		$result = HttpSignature::verify_with_key( $request, self::$public_key_pem );

		$this->assertTrue( $result );
	}

	public function test_verify_with_key_returns_403_when_signed_with_different_key_than_presented(): void {
		// The request is signed with a key that does NOT match the public key
		// being presented for registration — exactly the §2d abuse (claiming a
		// key the requester doesn't actually hold).
		$other = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $other, $other_private );

		$request = $this->build_signed_request( [
			'route'       => '/agnosis/v1/node/peers',
			'body'        => (string) wp_json_encode( [ 'url' => self::ACTOR_URL, 'publicKey' => self::$public_key_pem ] ),
			'private_key' => $other_private,
		] );

		$result = HttpSignature::verify_with_key( $request, self::$public_key_pem );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_invalid', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_verify_with_key_returns_401_when_signature_header_is_absent(): void {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/node/peers' );

		$result = HttpSignature::verify_with_key( $request, self::$public_key_pem );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_missing', $result->get_error_code() );
	}

	public function test_verify_with_key_returns_400_when_digest_does_not_match_body(): void {
		$request = $this->build_signed_request( [
			'route' => '/agnosis/v1/node/peers',
			'body'  => (string) wp_json_encode( [ 'url' => self::ACTOR_URL, 'publicKey' => self::$public_key_pem ] ),
		] );
		$request->set_body( '{"url":"' . self::ACTOR_URL . '","publicKey":"tampered"}' );

		$result = HttpSignature::verify_with_key( $request, self::$public_key_pem );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_digest', $result->get_error_code() );
	}

	public function test_verify_with_key_makes_no_outbound_http_request(): void {
		$fetch_count = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$fetch_count ) {
				$fetch_count++;
				return $preempt;
			}
		);

		$request = $this->build_signed_request( [
			'route' => '/agnosis/v1/node/peers',
			'body'  => (string) wp_json_encode( [ 'url' => self::ACTOR_URL, 'publicKey' => self::$public_key_pem ] ),
		] );
		HttpSignature::verify_with_key( $request, self::$public_key_pem );

		$this->assertSame( 0, $fetch_count, 'verify_with_key() must not fetch a remote actor document — the key is already in hand.' );
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
