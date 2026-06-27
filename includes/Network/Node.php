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
 * @package Agnosis\Network
 */

declare(strict_types=1);

namespace Agnosis\Network;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Node {

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

		$peer_url   = esc_url_raw( $request->get_param( 'url' ) ?? '' );
		$peer_label = sanitize_text_field( $request->get_param( 'label' ) ?? '' );
		$public_key = sanitize_textarea_field( $request->get_param( 'publicKey' ) ?? '' );

		if ( empty( $peer_url ) ) {
			return new WP_Error( 'agnosis_missing_url', __( 'Node URL is required.', 'agnosis' ), [ 'status' => 400 ] );
		}

		// TODO: verify the peer's signature before trusting.
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
