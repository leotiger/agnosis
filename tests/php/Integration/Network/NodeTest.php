<?php
/**
 * Integration tests for Network\Node::register_peer() (audit §2d).
 *
 * `register_peer()` is deliberately reachable without WordPress auth (a
 * fediverse/rhizome peer, not a logged-in user, calls it), so it previously
 * had no defense at all beyond WordPress's own request handling — an
 * unauthenticated caller could insert unlimited distinct-URL `pending` rows
 * into `agnosis_nodes`, and the endpoint's own "TODO: verify the peer's
 * signature before trusting" comment had never been implemented. Three
 * independent controls close that:
 *
 *   1. Per-IP rate limiting via the existing Core\RateLimiter.
 *   2. A cap on total `pending` rows, oldest pruned first, so the table
 *      cannot grow without bound even from unlimited distinct identities.
 *   3. The registration itself must be signed by the private key matching
 *      the public key it presents (HttpSignature::verify_with_key() —
 *      proof of possession of that key, not a domain-ownership proof).
 *      HttpSignatureTest.php covers verify_with_key() itself in isolation;
 *      these tests cover its wiring into register_peer() specifically.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Core\RateLimiter;
use Agnosis\Network\Node;

class NodeTest extends \WP_UnitTestCase {

	/** Must match Node::MAX_PENDING_PEERS. */
	private const PENDING_CAP = 500;

	/** Must match Node::REGISTER_RATE_LIMIT / REGISTER_RATE_WINDOW. */
	private const RATE_LIMIT        = 5;
	private const RATE_LIMIT_WINDOW = 300;

	private static string $private_key_pem;
	private static string $public_key_pem;

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

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'OpenSSL extension not available.' );
		}

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	protected function tearDown(): void {
		RateLimiter::reset( 'agnosis_node_register_peer', '127.0.0.1', self::RATE_LIMIT_WINDOW );
		RateLimiter::reset( 'agnosis_node_register_peer', '10.0.0.1', self::RATE_LIMIT_WINDOW );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a validly signed POST /agnosis/v1/node/peers request.
	 *
	 * @param array<string, mixed> $overrides 'url', 'label', 'public_key' (presented in the body),
	 *                                          'private_key' (used to sign — defaults to the matching key),
	 *                                          'omit_signature' (bool).
	 */
	private function build_peer_request( array $overrides = [] ): \WP_REST_Request {
		$url        = $overrides['url']         ?? 'https://peer.example/';
		$label      = $overrides['label']       ?? 'Peer Node';
		$public_key = array_key_exists( 'public_key', $overrides ) ? $overrides['public_key'] : self::$public_key_pem;
		$priv_key   = $overrides['private_key'] ?? self::$private_key_pem;
		$omit_sig   = $overrides['omit_signature'] ?? false;

		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/node/peers' );
		$request->set_param( 'url', $url );
		$request->set_param( 'label', $label );
		$request->set_param( 'publicKey', $public_key );

		if ( $omit_sig ) {
			return $request;
		}

		$body   = (string) wp_json_encode( [ 'url' => $url, 'label' => $label, 'publicKey' => $public_key ] );
		$date   = gmdate( 'D, d M Y H:i:s \G\M\T' );
		$host   = (string) wp_parse_url( rest_url( '/' ), PHP_URL_HOST );
		$digest = 'SHA-256=' . base64_encode( hash( 'sha256', $body, true ) );
		$path   = '/' . rest_get_url_prefix() . '/agnosis/v1/node/peers';

		$signing_string = implode( "\n", [
			"(request-target): post {$path}",
			"host: {$host}",
			"date: {$date}",
			"digest: {$digest}",
		] );

		openssl_sign( $signing_string, $raw_sig, $priv_key, OPENSSL_ALGO_SHA256 );
		$sig_header = 'keyId="' . $url . '#main-key"'
			. ',algorithm="rsa-sha256"'
			. ',headers="(request-target) host date digest"'
			. ',signature="' . base64_encode( $raw_sig ) . '"';

		$request->set_header( 'signature', $sig_header );
		$request->set_header( 'date', $date );
		$request->set_header( 'host', $host );
		$request->set_header( 'digest', $digest );
		$request->set_body( $body );

		return $request;
	}

	/** @return array{status: string, url: string, public_key: string, label: string}|null */
	private function get_node_row( string $url ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup/assertion against a custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agnosis_nodes WHERE url = %s", $url ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	// -------------------------------------------------------------------------
	// 1. Valid registration
	// -------------------------------------------------------------------------

	public function test_register_peer_returns_201_and_inserts_pending_row_for_valid_signed_request(): void {
		$response = ( new Node() )->register_peer( $this->build_peer_request( [ 'url' => 'https://valid-peer.example/' ] ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$row = $this->get_node_row( 'https://valid-peer.example/' );
		$this->assertNotNull( $row );
		$this->assertSame( 'pending', $row['status'] );
	}

	// -------------------------------------------------------------------------
	// 2. Basic validation, unchanged
	// -------------------------------------------------------------------------

	public function test_register_peer_returns_400_when_url_is_missing(): void {
		$request = $this->build_peer_request( [ 'url' => '', 'omit_signature' => true ] );

		$result = ( new Node() )->register_peer( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_missing_url', $result->get_error_code() );
	}

	public function test_register_peer_returns_400_when_public_key_is_missing(): void {
		$request = $this->build_peer_request( [ 'url' => 'https://no-key.example/', 'public_key' => '', 'omit_signature' => true ] );

		$result = ( new Node() )->register_peer( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_missing_public_key', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// 3. Audit §2d: signature requirement (the former TODO)
	// -------------------------------------------------------------------------

	public function test_register_peer_returns_401_when_unsigned(): void {
		$request = $this->build_peer_request( [ 'url' => 'https://unsigned-peer.example/', 'omit_signature' => true ] );

		$result = ( new Node() )->register_peer( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_missing', $result->get_error_code() );
		$this->assertNull( $this->get_node_row( 'https://unsigned-peer.example/' ), 'An unsigned registration must not be written to the table.' );
	}

	public function test_register_peer_returns_403_when_signed_with_a_different_key_than_presented(): void {
		$other = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $other, $other_private );

		// Presents self::$public_key_pem but signs with an unrelated key —
		// the concrete abuse the audit's TODO left open: claiming a key the
		// requester doesn't actually control.
		$request = $this->build_peer_request( [
			'url'         => 'https://forged-peer.example/',
			'private_key' => $other_private,
		] );

		$result = ( new Node() )->register_peer( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_sig_invalid', $result->get_error_code() );
		$this->assertNull( $this->get_node_row( 'https://forged-peer.example/' ), 'A registration signed with the wrong key must not be written to the table.' );
	}

	// -------------------------------------------------------------------------
	// 4. Audit §2d: per-IP rate limit
	// -------------------------------------------------------------------------

	public function test_register_peer_rate_limits_after_configured_requests_from_same_ip(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$node = new Node();

		for ( $i = 0; $i < self::RATE_LIMIT; $i++ ) {
			$request = $this->build_peer_request( [ 'url' => "https://rate-test-{$i}.example/" ] );
			$result  = $node->register_peer( $request );

			$this->assertFalse(
				is_wp_error( $result ) && 'agnosis_rate_limit' === $result->get_error_code(),
				"Request {$i} should not be rate-limited yet"
			);
		}

		// One more, from the same IP within the same window — must be blocked
		// before any DB work happens.
		$request = $this->build_peer_request( [ 'url' => 'https://rate-test-overflow.example/' ] );
		$result  = $node->register_peer( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_rate_limit', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertNull( $this->get_node_row( 'https://rate-test-overflow.example/' ) );
	}

	// -------------------------------------------------------------------------
	// 5. Audit §2d: pending-row cap
	// -------------------------------------------------------------------------

	public function test_register_peer_reregistering_a_known_url_does_not_prune_anything(): void {
		global $wpdb;

		$this->seed_pending_rows( self::PENDING_CAP );

		// A peer already known (one of the seeded rows) re-announces itself —
		// this must never trigger cap enforcement, no matter how full the
		// table already is.
		$known_url = 'https://filler-0.example/';
		$request   = $this->build_peer_request( [ 'url' => $known_url ] );

		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_nodes WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion against a custom table.

		$response = ( new Node() )->register_peer( $request );

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_nodes WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion against a custom table.

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $before, $after, 'Re-registering an already-known URL must not change the pending row count.' );
		$this->assertNotNull( $this->get_node_row( $known_url ) );
	}

	public function test_register_peer_prunes_oldest_pending_row_once_cap_is_reached(): void {
		global $wpdb;

		$this->seed_pending_rows( self::PENDING_CAP );

		$this->assertNotNull( $this->get_node_row( 'https://filler-0.example/' ), 'Sanity check: the oldest seeded row exists before registration.' );

		$new_url  = 'https://new-peer-past-cap.example/';
		$request  = $this->build_peer_request( [ 'url' => $new_url ] );
		$response = ( new Node() )->register_peer( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$pending_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_nodes WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion against a custom table.

		$this->assertSame( self::PENDING_CAP, $pending_count, 'The pending count must stay at the cap, not grow past it.' );
		$this->assertNull( $this->get_node_row( 'https://filler-0.example/' ), 'The single oldest pending row must be pruned to make room.' );
		$this->assertNotNull( $this->get_node_row( 'https://filler-1.example/' ), 'The next-oldest row must survive — only one row is pruned per registration.' );
		$this->assertNotNull( $this->get_node_row( $new_url ), 'The new registration itself must be written.' );
	}

	/**
	 * Bulk-insert $count `pending` rows with strictly ascending created_at
	 * timestamps (filler-0 oldest), via one multi-row INSERT rather than
	 * $count round trips through $wpdb->insert().
	 */
	private function seed_pending_rows( int $count ): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'agnosis_nodes';
		$now    = time();
		$tuples = [];
		$args   = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$tuples[] = '(%s, %s, %s, %s, %s, %s)';
			$stamp    = gmdate( 'Y-m-d H:i:s', $now - ( $count - $i ) );
			array_push( $args, "https://filler-{$i}.example/", '', 'filler', 'pending', $stamp, $stamp );
		}

		$sql = "INSERT INTO {$table} (url, public_key, label, status, last_seen, created_at) VALUES " . implode( ',', $tuples );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql's only variable content is the fixed table name; all row values go through $wpdb->prepare() below.
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}
}
