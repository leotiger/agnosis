<?php
/**
 * Agnosis Node Identity.
 *
 * Each Agnosis installation is a node in the rhizome.
 * This class generates and maintains the node's cryptographic identity
 * (RSA key pair) and exposes a /.well-known/agnosis-node endpoint
 * that other nodes can read to verify and federate with this one.
 *
 * GET /wp-json/agnosis/v1/node          — node identity card
 * GET /.well-known/agnosis-node         — lightweight node discovery
 * POST /wp-json/agnosis/v1/node/peers   — register a peer node
 *
 * register_peer() is intentionally reachable without WordPress auth (any
 * fediverse/rhizome node, not a logged-in user, calls it) — it is instead
 * gated by three independent controls added for audit §2d: a per-IP rate
 * limit, a cap on total pending rows, and a requirement that the request be
 * signed by the private key matching the public key it presents (see
 * `HttpSignature::verify_with_key()`). A row landing here is still only
 * ever `status = 'pending'`; `list_peers()` exposes `trusted` rows only, and
 * nothing in this codebase promotes a row to `trusted` automatically.
 *
 * @package Agnosis\Network
 */

declare(strict_types=1);

namespace Agnosis\Network;

use Agnosis\Core\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Node {

	/** Max peer-registration requests allowed per IP within the rate-limit window (audit §2d). */
	private const REGISTER_RATE_LIMIT = 5;

	/** Rate-limit window, in seconds, for peer registration (audit §2d). */
	private const REGISTER_RATE_WINDOW = 300;

	/**
	 * Max `status = 'pending'` rows kept in agnosis_nodes. Beyond this, the
	 * oldest pending rows are pruned to make room for a new registration —
	 * same bounded, oldest-first shape as `agnosis_ap_tombstones` (audit
	 * §3e) and the `agnosis_ap_followers` scale lesson (audit §3g note iii).
	 * Registering an already-known URL never counts against this cap; only
	 * a genuinely new row does.
	 */
	private const MAX_PENDING_PEERS = 500;

	public function register_identity(): void {
		$this->ensure_key_pair();
		$this->register_well_known_rewrite();
	}

	public function register_routes(): void {
		// Node identity card.
		register_rest_route( 'agnosis/v1', '/node', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'identity_card' ],
			'permission_callback' => '__return_true',
		] );

		// Peer registration.
		register_rest_route( 'agnosis/v1', '/node/peers', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'register_peer' ],
			'permission_callback' => '__return_true', // Signed with sender's private key.
		] );

		// Peer list.
		register_rest_route( 'agnosis/v1', '/node/peers', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_peers' ],
			'permission_callback' => '__return_true',
		] );
	}

	// -------------------------------------------------------------------------

	public function identity_card( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( [
			'@context'    => 'https://agnosis.art/ns/node/v1',
			'type'        => 'AgnosisNode',
			'id'          => rest_url( 'agnosis/v1/node' ),
			'url'         => home_url(),
			'label'       => get_option( 'agnosis_node_label', get_bloginfo( 'name' ) ),
			'description' => get_bloginfo( 'description' ),
			'publicKey'   => [
				'id'           => rest_url( 'agnosis/v1/node' ) . '#main-key',
				'owner'        => rest_url( 'agnosis/v1/node' ),
				'publicKeyPem' => get_option( 'agnosis_public_key', '' ),
			],
			'inbox'       => rest_url( 'agnosis/v1/activitypub/inbox' ),
			'outbox'      => rest_url( 'agnosis/v1/activitypub/outbox' ),
			'version'     => AGNOSIS_VERSION,
		], 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	public function register_peer( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		// Audit §2d: cheap check first, before any DB work — throttle by IP.
		$rate = RateLimiter::check( 'agnosis_node_register_peer', self::REGISTER_RATE_LIMIT, self::REGISTER_RATE_WINDOW );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$peer_url   = esc_url_raw( $request->get_param( 'url' ) ?? '' );
		$peer_label = sanitize_text_field( $request->get_param( 'label' ) ?? '' );
		$public_key = sanitize_textarea_field( $request->get_param( 'publicKey' ) ?? '' );

		if ( empty( $peer_url ) ) {
			return new WP_Error( 'agnosis_missing_url', __( 'Node URL is required.', 'agnosis' ), [ 'status' => 400 ] );
		}

		if ( empty( $public_key ) ) {
			return new WP_Error( 'agnosis_missing_public_key', __( 'A public key is required to register a peer.', 'agnosis' ), [ 'status' => 400 ] );
		}

		// Audit §2d ("TODO: verify the peer's signature before trusting"): the
		// request itself must be signed by the private key matching the public
		// key it presents. There's no remote actor document to fetch a key
		// from here — the peer submits its key inline — so this is proof of
		// possession of that exact key, not a domain-ownership proof; it's
		// what stops a registration whose claimed key the requester doesn't
		// actually control.
		$signature_check = HttpSignature::verify_with_key( $request, $public_key );
		if ( is_wp_error( $signature_check ) ) {
			return $signature_check;
		}

		// Audit §2d: only a genuinely new URL grows the pending count — an
		// already-known peer re-announcing itself doesn't need room made.
		$is_new_peer = null === $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no WP abstraction available.
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}agnosis_nodes WHERE url = %s", $peer_url )
		);

		if ( $is_new_peer ) {
			$this->enforce_pending_peer_cap( $wpdb );
		}

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write; caching not applicable to REPLACE.
			$wpdb->prefix . 'agnosis_nodes',
			[
				'url'        => $peer_url,
				'public_key' => $public_key,
				'label'      => $peer_label,
				'status'     => 'pending',
				'last_seen'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);

		return new WP_REST_Response( [ 'status' => 'registered', 'url' => $peer_url ], 201 );
	}

	public function list_peers( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no WP abstraction available.
		$peers = $wpdb->get_results(
			"SELECT url, label, status, last_seen FROM {$wpdb->prefix}agnosis_nodes WHERE status = 'trusted' ORDER BY last_seen DESC"
		);

		return new WP_REST_Response( [ 'peers' => $peers, 'count' => count( $peers ) ] );
	}

	// -------------------------------------------------------------------------

	/**
	 * Prune the oldest `status = 'pending'` rows once the table has reached
	 * MAX_PENDING_PEERS, so a new registration always has room (audit §2d).
	 * Signature verification (see register_peer()) stops an attacker from
	 * forging someone ELSE's identity, but it does nothing to stop unlimited
	 * freshly-minted keypairs registering unlimited distinct URLs — this cap
	 * is the backstop for that.
	 *
	 * @param \wpdb $wpdb WordPress database access object.
	 */
	private function enforce_pending_peer_cap( \wpdb $wpdb ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no WP abstraction available.
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_nodes WHERE status = 'pending'" );

		if ( $pending < self::MAX_PENDING_PEERS ) {
			return;
		}

		$overflow = absint( $pending - self::MAX_PENDING_PEERS + 1 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $overflow is a locally computed non-negative int (never request input); MySQL's DELETE...ORDER BY...LIMIT extension doesn't accept a placeholder for LIMIT anyway.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agnosis_nodes WHERE status = 'pending' ORDER BY created_at ASC LIMIT {$overflow}" );
	}

	private function ensure_key_pair(): void {
		if ( get_option( 'agnosis_public_key' ) && get_option( 'agnosis_private_key' ) ) {
			return;
		}

		// openssl_pkey_new() with a 2048-bit RSA key reads from /dev/random, which
		// blocks indefinitely inside Docker containers due to entropy starvation.
		// WP_TESTS_DOMAIN is always set by the WordPress PHPUnit bootstrap, so we
		// skip key generation entirely during tests — the REST routes still register
		// and work; they just expose an empty publicKey until activation runs on a
		// real server.
		if ( defined( 'WP_TESTS_DOMAIN' ) ) {
			return;
		}

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return;
		}

		$key = openssl_pkey_new( [ 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		if ( false === $key ) {
			return;
		}

		$priv = '';
		openssl_pkey_export( $key, $priv );
		$details = openssl_pkey_get_details( $key );
		$pub     = $details['key'] ?? '';

		update_option( 'agnosis_private_key', $priv );
		update_option( 'agnosis_public_key',  $pub );
	}

	private function register_well_known_rewrite(): void {
		add_rewrite_rule( '^\.well-known/agnosis-node$', 'index.php?agnosis_well_known=node', 'top' );
		add_filter( 'query_vars', function ( array $vars ): array {
			$vars[] = 'agnosis_well_known';
			return $vars;
		} );
		add_action( 'template_redirect', function (): void {
			if ( get_query_var( 'agnosis_well_known' ) === 'node' ) {
				wp_send_json( [ 'endpoint' => rest_url( 'agnosis/v1/node' ) ] );
			}
		} );
	}
}
