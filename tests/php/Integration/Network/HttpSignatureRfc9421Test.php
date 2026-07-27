<?php
/**
 * Integration tests — Network\HttpSignature's RFC 9421 HTTP Message
 * Signatures support (interaction-surface roadmap, Phase 3, WP10, §7 Q9a).
 *
 * Split into its own file rather than growing HttpSignatureTest.php further,
 * following this suite's own "new interaction-surface work gets its own
 * file" convention (see ActivityPubLikesTest.php's docblock). Uses a real,
 * freshly-generated RSA-2048 keypair — same approach as HttpSignatureTest —
 * to build genuine `Signature-Input:`/`Signature:` structured-field
 * requests and exercise the full verify_rfc9421() chain: dispatch on the
 * `Signature-Input` header's presence → parse → unsupported-alg rejection →
 * freshness (`created`/`expires`) → signed-component strictness
 * (content-digest required on POST) → Content-Digest verification → actor
 * key fetch (the SAME fetch_public_key()/cache HttpSignatureTest already
 * covers, not re-tested here) → signature base reconstruction →
 * openssl_verify().
 *
 * Deliberately does NOT test Ed25519 — verify_rfc9421() rejects any `alg`
 * other than `rsa-v1_5-sha256` outright (see HttpSignature's own class
 * docblock for why), so "an ed25519-signed request is rejected" is exactly
 * what test_verify_rfc9421_returns_400_for_an_unsupported_algorithm covers.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\HttpSignature;

class HttpSignatureRfc9421Test extends \WP_UnitTestCase {

	// ── RSA keypair (generated once per class, shared across tests) ──────────

	private static string $private_key_pem;
	private static string $public_key_pem;

	private const ACTOR_URL = 'https://mastodon.example/users/rfc9421actor';
	private const KEY_ID    = self::ACTOR_URL . '#main-key';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return;
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

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'OpenSSL extension not available.' );
		}

		delete_transient( 'agnosis_ap_key_' . md5( self::KEY_ID ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

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
							'publicKey' => [ 'id' => self::KEY_ID, 'owner' => self::ACTOR_URL, 'publicKeyPem' => $pem ],
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
	 * Build a request signed with RFC 9421 `Signature-Input`/`Signature`
	 * structured fields — mirrors HttpSignatureTest::build_signed_request()'s
	 * role for the cavage format, but reconstructs the RFC 9421 signature
	 * base (component lines + a final "@signature-params" line) directly,
	 * independently of HttpSignature::build_rfc9421_signature_base()'s own
	 * implementation, so a bug in one is not masked by the other.
	 *
	 * @param array<string, mixed> $overrides Override specific components:
	 *   'route'          — REST route (default the inbox route)
	 *   'body'           — raw JSON body string
	 *   'components'     — covered-component identifiers, in order
	 *   'created'        — unix timestamp (default now)
	 *   'expires'        — unix timestamp, omitted unless set
	 *   'alg'            — signature-input `alg` param (default 'rsa-v1_5-sha256'; '' omits it)
	 *   'keyid'          — signature-input `keyid` param (default self::KEY_ID)
	 *   'label'          — signature label (default 'sig1')
	 *   'private_key'    — PEM to sign with (default self::$private_key_pem)
	 *   'tamper_sig'     — bool, corrupt the signature bytes
	 *   'content_digest' — override the Content-Digest header entirely
	 */
	private function build_rfc9421_request( array $overrides = [] ): \WP_REST_Request {
		$route      = $overrides['route']      ?? '/agnosis/v1/activitypub/inbox';
		$body       = $overrides['body']       ?? (string) wp_json_encode( [ 'type' => 'Follow', 'actor' => self::ACTOR_URL ] );
		$components = $overrides['components'] ?? [ '@method', '@target-uri', 'content-digest' ];
		$created    = $overrides['created']    ?? time();
		$alg        = array_key_exists( 'alg', $overrides ) ? $overrides['alg'] : 'rsa-v1_5-sha256';
		$keyid      = array_key_exists( 'keyid', $overrides ) ? $overrides['keyid'] : self::KEY_ID;
		$label      = $overrides['label']      ?? 'sig1';
		$priv_key   = $overrides['private_key'] ?? self::$private_key_pem;
		$tamper     = $overrides['tamper_sig']  ?? false;

		$path           = '/' . rest_get_url_prefix() . $route;
		$target_uri     = home_url( $path );
		$content_digest = $overrides['content_digest'] ?? ( 'sha-256=:' . base64_encode( hash( 'sha256', $body, true ) ) . ':' );

		$params_str = ';created=' . $created;
		if ( isset( $overrides['expires'] ) ) {
			$params_str .= ';expires=' . $overrides['expires'];
		}
		if ( '' !== $keyid ) {
			$params_str .= ';keyid="' . $keyid . '"';
		}
		if ( '' !== $alg ) {
			$params_str .= ';alg="' . $alg . '"';
		}

		$component_list = implode( ' ', array_map( static fn ( string $c ) => '"' . $c . '"', $components ) );
		$raw_params     = '(' . $component_list . ')' . $params_str;

		$lines = [];
		foreach ( $components as $component ) {
			$value = match ( $component ) {
				'@method'      => 'POST',
				'@target-uri'  => $target_uri,
				'content-digest' => $content_digest,
				default        => '',
			};
			$lines[] = '"' . $component . '": ' . $value;
		}
		$lines[]         = '"@signature-params": ' . $raw_params;
		$signature_base  = implode( "\n", $lines );

		openssl_sign( $signature_base, $raw_sig, $priv_key, OPENSSL_ALGO_SHA256 );
		if ( $tamper ) {
			$raw_sig = 'tampered' . $raw_sig;
		}
		$sig_b64 = base64_encode( $raw_sig );

		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'signature-input', $label . '=' . $raw_params );
		$request->set_header( 'signature', $label . '=:' . $sig_b64 . ':' );
		if ( in_array( 'content-digest', $components, true ) || isset( $overrides['content_digest'] ) ) {
			$request->set_header( 'content-digest', $content_digest );
		}
		$request->set_body( $body );

		return $request;
	}

	// -------------------------------------------------------------------------
	// Dispatch
	// -------------------------------------------------------------------------

	public function test_verify_dispatches_to_rfc9421_when_signature_input_header_is_present(): void {
		$this->mock_actor_fetch();

		$result = HttpSignature::verify( $this->build_rfc9421_request() );

		$this->assertTrue( $result );
	}

	public function test_verify_still_uses_cavage_when_signature_input_header_is_absent(): void {
		// No Signature-Input header at all → falls through to the pre-existing
		// cavage path, which rejects a bare, empty request the same way
		// HttpSignatureTest's own equivalent test already documents.
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_missing', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Signature-Input parsing / structural rejects
	// -------------------------------------------------------------------------

	public function test_verify_rfc9421_returns_400_when_signature_input_is_malformed(): void {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'signature-input', 'not-a-valid-signature-input' );
		$request->set_header( 'signature', 'sig1=:abc123:' );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_malformed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_verify_rfc9421_returns_400_when_no_components_are_covered(): void {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'signature-input', 'sig1=();created=' . time() . ';keyid="' . self::KEY_ID . '"' );
		$request->set_header( 'signature', 'sig1=:abc123:' );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_malformed', $result->get_error_code() );
	}

	public function test_verify_rfc9421_returns_400_for_an_unsupported_algorithm(): void {
		// The Ed25519 case: verify_rfc9421() rejects it outright rather than
		// attempting (and getting wrong) a verification this class does not
		// implement — see the class docblock for why Ed25519 is WP12's own
		// concern, not this one's.
		$request = $this->build_rfc9421_request( [ 'alg' => 'ed25519' ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_unsupported_alg', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_verify_rfc9421_accepts_a_request_with_no_alg_param_at_all(): void {
		// alg is optional per RFC 9421 (the algorithm can be derived from the
		// key) — verify_rfc9421() must not require it, only reject a WRONG one.
		$this->mock_actor_fetch();

		$result = HttpSignature::verify( $this->build_rfc9421_request( [ 'alg' => '' ] ) );

		$this->assertTrue( $result );
	}

	public function test_verify_rfc9421_returns_400_when_keyid_is_missing(): void {
		$request = $this->build_rfc9421_request( [ 'keyid' => '' ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_malformed', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Freshness
	// -------------------------------------------------------------------------

	public function test_verify_rfc9421_returns_401_when_created_is_more_than_12_hours_old(): void {
		$this->mock_actor_fetch();

		$request = $this->build_rfc9421_request( [ 'created' => time() - ( 13 * HOUR_IN_SECONDS ) ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_stale', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_verify_rfc9421_returns_401_when_created_is_more_than_12_hours_in_future(): void {
		$this->mock_actor_fetch();

		$request = $this->build_rfc9421_request( [ 'created' => time() + ( 13 * HOUR_IN_SECONDS ) ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_stale', $result->get_error_code() );
	}

	public function test_verify_rfc9421_returns_401_when_expires_is_in_the_past(): void {
		$this->mock_actor_fetch();

		$request = $this->build_rfc9421_request( [ 'expires' => time() - 60 ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_stale', $result->get_error_code() );
	}

	public function test_verify_rfc9421_accepts_a_request_with_expires_in_the_future(): void {
		$this->mock_actor_fetch();

		$result = HttpSignature::verify( $this->build_rfc9421_request( [ 'expires' => time() + 60 ] ) );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// Signed-component strictness / Content-Digest
	// -------------------------------------------------------------------------

	public function test_verify_rfc9421_rejects_post_when_content_digest_not_covered(): void {
		$this->mock_actor_fetch();

		$request = $this->build_rfc9421_request( [ 'components' => [ '@method', '@target-uri' ] ] );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_no_digest', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_verify_rfc9421_returns_400_when_content_digest_does_not_match_body(): void {
		$this->mock_actor_fetch();

		$request = $this->build_rfc9421_request();
		// Corrupt the body after signing — Content-Digest was computed and
		// signed against the ORIGINAL body, so this must now fail.
		$request->set_body( '{"type":"Follow","actor":"' . self::ACTOR_URL . '","EXTRA":"tampered"}' );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_digest', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_verify_rfc9421_returns_400_when_content_digest_header_has_no_sha256_value(): void {
		$this->mock_actor_fetch();

		// content-digest is a covered component, but the actual header carries
		// no sha-256 entry to check against.
		$request = $this->build_rfc9421_request();
		$request->set_header( 'content-digest', 'sha-512=:whatever:' );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_digest', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Signature header / value
	// -------------------------------------------------------------------------

	public function test_verify_rfc9421_returns_400_when_signature_header_has_no_matching_label(): void {
		$request = $this->build_rfc9421_request();
		$request->set_header( 'signature', 'sig2=:abc123:' ); // Signature-Input names "sig1".

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_malformed', $result->get_error_code() );
	}

	public function test_verify_rfc9421_returns_400_when_signature_value_is_not_valid_base64(): void {
		$request = $this->build_rfc9421_request();
		$request->set_header( 'signature', 'sig1=:not valid base64!!:' );

		$result = HttpSignature::verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_malformed', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Key fetch / cryptographic verification
	// -------------------------------------------------------------------------

	public function test_verify_rfc9421_returns_502_when_actor_document_cannot_be_fetched(): void {
		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'http_request_failed', 'Connection refused' ) );

		$result = HttpSignature::verify( $this->build_rfc9421_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_key_fetch_failed', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	public function test_verify_rfc9421_returns_403_when_signature_bytes_are_wrong(): void {
		$this->mock_actor_fetch();

		$result = HttpSignature::verify( $this->build_rfc9421_request( [ 'tamper_sig' => true ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_invalid', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_verify_rfc9421_returns_403_when_signed_with_the_wrong_key(): void {
		$this->mock_actor_fetch(); // actor document advertises the class's own public key.

		$other = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $other, $other_private );

		$result = HttpSignature::verify( $this->build_rfc9421_request( [ 'private_key' => $other_private ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_invalid', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// signing_key_owner()
	// -------------------------------------------------------------------------

	public function test_signing_key_owner_reads_keyid_from_signature_input(): void {
		$request = $this->build_rfc9421_request();

		$this->assertSame( self::ACTOR_URL, HttpSignature::signing_key_owner( $request ) );
	}

	public function test_signing_key_owner_returns_empty_string_for_malformed_signature_input(): void {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'signature-input', 'not-a-valid-signature-input' );

		$this->assertSame( '', HttpSignature::signing_key_owner( $request ) );
	}
}
