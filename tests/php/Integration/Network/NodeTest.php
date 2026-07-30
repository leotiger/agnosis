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

	/** @return array{status: string, url: string, public_key: string, label: string, description: string|null, trust_scope: string, actor_id: string|null, inbox_url: string|null}|null */
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

	// -------------------------------------------------------------------------
	// RN1/RN2 (RHIZOME-NETWORK-ROADMAP.md §8, 2026-07-30): re-registration
	// must not silently wipe an already-decided trust state.
	// -------------------------------------------------------------------------

	public function test_register_peer_reregistering_a_trusted_peer_preserves_status_and_resolved_identity(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'agnosis_nodes', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
			'url'         => 'https://trusted-peer.example/',
			'public_key'  => 'old-key',
			'label'       => 'Old Label',
			'description' => 'Old description.',
			'trust_scope' => 'actor',
			'actor_id'    => 'https://trusted-peer.example/wp-json/agnosis/v1/activitypub/actor',
			'inbox_url'   => 'https://trusted-peer.example/wp-json/agnosis/v1/activitypub/inbox',
			'status'      => 'trusted',
		] );

		$request  = $this->build_peer_request( [ 'url' => 'https://trusted-peer.example/', 'label' => 'New Label' ] );
		$response = ( new Node() )->register_peer( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$row = $this->get_node_row( 'https://trusted-peer.example/' );
		$this->assertSame( 'trusted', $row['status'], 'Re-registration must never reset an already-trusted peer back to pending.' );
		$this->assertSame( 'actor', $row['trust_scope'], 'Re-registration must not disturb the admin\'s chosen trust scope.' );
		$this->assertSame( 'https://trusted-peer.example/wp-json/agnosis/v1/activitypub/actor', $row['actor_id'], 'Re-registration must not disturb the resolved actor id.' );
		$this->assertSame( 'https://trusted-peer.example/wp-json/agnosis/v1/activitypub/inbox', $row['inbox_url'], 'Re-registration must not disturb the resolved inbox url.' );
		$this->assertSame( 'New Label', $row['label'], 'Label/description/public_key/last_seen must still refresh on re-registration.' );
	}

	public function test_register_peer_reregistering_a_blocked_peer_preserves_blocked_status(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'agnosis_nodes', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
			'url'        => 'https://blocked-peer.example/',
			'public_key' => 'old-key',
			'status'     => 'blocked',
		] );

		$request  = $this->build_peer_request( [ 'url' => 'https://blocked-peer.example/' ] );
		$response = ( new Node() )->register_peer( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status(), 'The endpoint itself still accepts the request — blocking is enforced at relay time (RN3), not at registration.' );

		$row = $this->get_node_row( 'https://blocked-peer.example/' );
		$this->assertSame( 'blocked', $row['status'], 'A blocked peer re-registering must not un-block itself.' );
	}

	public function test_register_peer_reregistering_a_pending_peer_still_refreshes_it_normally(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'agnosis_nodes', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
			'url'        => 'https://pending-peer.example/',
			'public_key' => 'old-key',
			'label'      => 'Old Label',
			'status'     => 'pending',
		] );

		$request  = $this->build_peer_request( [ 'url' => 'https://pending-peer.example/', 'label' => 'Refreshed Label' ] );
		$response = ( new Node() )->register_peer( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$row = $this->get_node_row( 'https://pending-peer.example/' );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 'Refreshed Label', $row['label'] );
	}

	// -------------------------------------------------------------------------
	// RN2: resolve_peer_node_card()
	// -------------------------------------------------------------------------

	public function test_resolve_peer_node_card_returns_actor_and_inbox_on_success(): void {
		add_filter( 'pre_http_request', [ $this, 'mock_full_node_card_chain' ], 10, 3 );

		$result = ( new Node() )->resolve_peer_node_card( 'https://card-peer.example/' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://card-peer.example/wp-json/agnosis/v1/activitypub/actor', $result['actor_id'] );
		$this->assertSame( 'https://card-peer.example/wp-json/agnosis/v1/activitypub/inbox', $result['inbox_url'] );
	}

	public function test_resolve_peer_node_card_returns_wp_error_when_wellknown_unreachable(): void {
		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'http_request_failed', 'Could not resolve host' ) );

		$result = ( new Node() )->resolve_peer_node_card( 'https://unreachable-peer.example/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_peer_unreachable', $result->get_error_code() );
	}

	public function test_resolve_peer_node_card_returns_wp_error_when_endpoint_field_missing(): void {
		add_filter( 'pre_http_request', static function () {
			return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [], 'body' => '{}', 'cookies' => [], 'filename' => '' ];
		} );

		$result = ( new Node() )->resolve_peer_node_card( 'https://no-endpoint-peer.example/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_peer_no_endpoint', $result->get_error_code() );
	}

	public function test_resolve_peer_node_card_returns_wp_error_when_card_missing_actor_or_inbox(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) {
				if ( str_contains( $url, '.well-known/agnosis-node' ) ) {
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [ 'endpoint' => 'https://incomplete-peer.example/wp-json/agnosis/v1/node' ] ),
						'cookies'  => [],
						'filename' => '',
					];
				}
				// Node card with no 'actor'/'inbox' fields at all.
				return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [], 'body' => '{"label":"Incomplete"}', 'cookies' => [], 'filename' => '' ];
			},
			10,
			3
		);

		$result = ( new Node() )->resolve_peer_node_card( 'https://incomplete-peer.example/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_peer_card_incomplete', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// RN4 (RHIZOME-NETWORK-ROADMAP.md §4/§8, 2026-07-30): check_reciprocity()
	// -------------------------------------------------------------------------

	public function test_check_reciprocity_returns_true_when_this_nodes_url_is_in_the_peers_list(): void {
		add_filter( 'pre_http_request', function () {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => (string) wp_json_encode( [
					'peers' => [
						[ 'url' => home_url(), 'label' => 'This Node', 'status' => 'trusted', 'last_seen' => null ],
						[ 'url' => 'https://someone-else.example/', 'label' => 'Someone Else', 'status' => 'trusted', 'last_seen' => null ],
					],
					'count' => 2,
				] ),
				'cookies'  => [],
				'filename' => '',
			];
		} );

		$result = ( new Node() )->check_reciprocity( 'https://mutual-peer.example/' );

		$this->assertTrue( $result );
	}

	public function test_check_reciprocity_returns_false_when_this_nodes_url_is_not_in_the_peers_list(): void {
		add_filter( 'pre_http_request', static function () {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => (string) wp_json_encode( [
					'peers' => [
						[ 'url' => 'https://someone-else.example/', 'label' => 'Someone Else', 'status' => 'trusted', 'last_seen' => null ],
					],
					'count' => 1,
				] ),
				'cookies'  => [],
				'filename' => '',
			];
		} );

		$result = ( new Node() )->check_reciprocity( 'https://one-directional-peer.example/' );

		$this->assertFalse( $result );
	}

	/**
	 * §13 F6 (2026-07-30). A peer that trusted this node through RN1's
	 * manual-add path has a row whose `url` is this node's ACTOR url —
	 * RhizomeManager::handle_add_manual() writes the pasted actor value to
	 * both `url` and `actor_id`, where self-registration only ever captures
	 * a site root. Comparing against home_url() alone therefore reported a
	 * genuinely mutual pair as one-directional.
	 */
	public function test_check_reciprocity_returns_true_when_the_peer_lists_this_nodes_actor_url_instead_of_its_site_url(): void {
		$node_actor = ( new \Agnosis\Network\ActivityPub() )->actor_url_for( 'node', 0 );

		add_filter( 'pre_http_request', static function () use ( $node_actor ) {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => (string) wp_json_encode( [
					'peers' => [
						[ 'url' => $node_actor, 'label' => 'This Node (added by hand)', 'status' => 'trusted', 'last_seen' => null ],
					],
					'count' => 1,
				] ),
				'cookies'  => [],
				'filename' => '',
			];
		} );

		$result = ( new Node() )->check_reciprocity( 'https://manually-mutual-peer.example/' );

		$this->assertTrue( $result, 'A peer listing this node by its actor URL trusts this node just as much as one listing it by its site URL.' );
	}

	public function test_check_reciprocity_returns_false_when_the_peer_lists_a_different_actor_on_this_same_domain(): void {
		add_filter( 'pre_http_request', static function () {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => (string) wp_json_encode( [
					'peers' => [
						[ 'url' => home_url( '/wp-json/agnosis/v1/activitypub/actor/4242' ), 'label' => 'Some Artist Here', 'status' => 'trusted', 'last_seen' => null ],
					],
					'count' => 1,
				] ),
				'cookies'  => [],
				'filename' => '',
			];
		} );

		$result = ( new Node() )->check_reciprocity( 'https://host-matching-peer.example/' );

		$this->assertFalse( $result, 'Matching on bare host would call this mutual; trusting one artist actor on this domain is not the same as trusting this node.' );
	}

	public function test_check_reciprocity_returns_true_when_this_nodes_url_matches_ignoring_a_trailing_slash(): void {
		add_filter( 'pre_http_request', function () {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				// The peer's own stored copy of this node's URL, with a trailing slash difference from home_url().
				'body'     => (string) wp_json_encode( [
					'peers' => [ [ 'url' => untrailingslashit( home_url() ) . '/', 'label' => '', 'status' => 'trusted', 'last_seen' => null ] ],
					'count' => 1,
				] ),
				'cookies'  => [],
				'filename' => '',
			];
		} );

		$result = ( new Node() )->check_reciprocity( 'https://mutual-peer-2.example/' );

		$this->assertTrue( $result );
	}

	public function test_check_reciprocity_returns_wp_error_when_peer_is_unreachable(): void {
		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'http_request_failed', 'Could not resolve host' ) );

		$result = ( new Node() )->check_reciprocity( 'https://unreachable-peer.example/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_reciprocity_unreachable', $result->get_error_code() );
	}

	public function test_check_reciprocity_returns_wp_error_when_response_has_no_peers_array(): void {
		// A manually-added third-party (non-Agnosis) peer, or any server with no such endpoint —
		// resolve_peer_node_card()'s own docblock makes the same point about this case.
		add_filter( 'pre_http_request', static function () {
			return [ 'response' => [ 'code' => 404, 'message' => 'Not Found' ], 'headers' => [], 'body' => '', 'cookies' => [], 'filename' => '' ];
		} );

		$result = ( new Node() )->check_reciprocity( 'https://non-agnosis-peer.example/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_reciprocity_unreachable', $result->get_error_code() );
	}

	public function test_check_reciprocity_returns_wp_error_when_response_body_is_malformed(): void {
		add_filter( 'pre_http_request', static function () {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => '{"not_peers_at_all": true}',
				'cookies'  => [],
				'filename' => '',
			];
		} );

		$result = ( new Node() )->check_reciprocity( 'https://malformed-peer.example/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_reciprocity_malformed', $result->get_error_code() );
	}

	/** @return array<string, mixed> */
	public function mock_full_node_card_chain( $preempt, array $args, string $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- add_filter callback signature.
		if ( str_contains( $url, '.well-known/agnosis-node' ) ) {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => (string) wp_json_encode( [ 'endpoint' => 'https://card-peer.example/wp-json/agnosis/v1/node' ] ),
				'cookies'  => [],
				'filename' => '',
			];
		}
		if ( 'https://card-peer.example/wp-json/agnosis/v1/node' === $url ) {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => (string) wp_json_encode( [
					'actor' => 'https://card-peer.example/wp-json/agnosis/v1/activitypub/actor',
					'inbox' => 'https://card-peer.example/wp-json/agnosis/v1/activitypub/inbox',
					'label' => 'Card Peer',
				] ),
				'cookies'  => [],
				'filename' => '',
			];
		}
		return $preempt;
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
