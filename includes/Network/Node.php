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
			// This node's own AS2 actor id — the identity a trusted rhizome
			// peer resolves via resolve_peer_node_card() below (RN2,
			// RHIZOME-NETWORK-ROADMAP.md §8). Same URL
			// ActivityPub::actor_url_for('node', 0) already builds for every
			// node-level Announce/Follow this node itself sends; added here so
			// a fetching peer's approval-time resolution has an 'actor' field
			// to read at all — previously absent, since nothing before RN1
			// ever needed to resolve a REMOTE node's own actor id.
			'actor'       => rest_url( 'agnosis/v1/activitypub/actor' ),
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

		$peer_url    = esc_url_raw( $request->get_param( 'url' ) ?? '' );
		$peer_label  = sanitize_text_field( $request->get_param( 'label' ) ?? '' );
		$public_key  = sanitize_textarea_field( $request->get_param( 'publicKey' ) ?? '' );
		// RN2 (RHIZOME-NETWORK-ROADMAP.md §4, ANSWERED): optional, human-readable
		// context a peer supplies about itself — shown in the admin approval UI
		// (RhizomeManager) so a pending row reads as more than a bare URL. Absent
		// entirely from pre-RN1 registrations, hence the default ''.
		$description = sanitize_textarea_field( $request->get_param( 'description' ) ?? '' );

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no WP abstraction available.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$wpdb->prefix}agnosis_nodes WHERE url = %s", $peer_url ) );

		// Audit §2d: only a genuinely new URL grows the pending count — an
		// already-known peer re-announcing itself doesn't need room made.
		if ( ! $existing ) {
			$this->enforce_pending_peer_cap( $wpdb );
		}

		// RN1/RN2 fix (RHIZOME-NETWORK-ROADMAP.md §8, 2026-07-30): the original
		// 0.9.28 version of this method always REPLACE INTO'd the full row,
		// unconditionally resetting status back to 'pending' on every single
		// re-registration — which, now that trust_scope/actor_id/inbox_url are
		// real approval-time state (not just a status flag), would also
		// silently wipe an admin's prior approval and resolved actor identity
		// every time a peer's own heartbeat/re-registration fired. An already
		// `trusted` or `blocked` row now only has its label/description/
		// public_key/last_seen refreshed — the trust decision itself and its
		// resolved actor_id/inbox_url are left alone; only a currently
		// `pending` (or brand-new) row can have its status re-set by this
		// endpoint, which is the only case where "pending" is actually correct
		// to (re-)write.
		if ( $existing && 'pending' !== $existing->status ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write.
				$wpdb->prefix . 'agnosis_nodes',
				[
					'public_key'  => $public_key,
					'label'       => $peer_label,
					'description' => $description,
					'last_seen'   => current_time( 'mysql' ),
				],
				[ 'id' => $existing->id ], // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				[ '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write; caching not applicable to REPLACE.
				$wpdb->prefix . 'agnosis_nodes',
				[
					'url'         => $peer_url,
					'public_key'  => $public_key,
					'label'       => $peer_label,
					'description' => $description,
					'status'      => 'pending',
					'last_seen'   => current_time( 'mysql' ),
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		}

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

	/**
	 * Resolve a peer's real actor id and inbox URL from its own node card.
	 *
	 * RN2 (RHIZOME-NETWORK-ROADMAP.md §8, 2026-07-30) — called at approval
	 * time by RhizomeManager::handle_approve(), for a `pending` row that
	 * arrived via the self-registration flow above (register_peer() only
	 * ever captures a bare site `url`, never an actor id or inbox). Two-hop
	 * fetch, matching the actual discovery path this node's own
	 * register_well_known_rewrite() implements on the OTHER side: `GET
	 * {peer_url}/.well-known/agnosis-node` returns only a pointer
	 * (`{"endpoint": ...}`), never the node card itself — the real card, with
	 * the `actor`/`inbox` fields added to identity_card() above, lives at
	 * whatever REST URL that pointer names.
	 *
	 * Not used for a manually-added third-party (non-Agnosis) actor —
	 * RhizomeManager's manual-add path takes an admin-pasted actor/inbox URL
	 * directly, since a generic Fediverse server has no reason to expose this
	 * node-card format at all.
	 *
	 * @return array{actor_id: string, inbox_url: string, label: string}|WP_Error
	 */
	public function resolve_peer_node_card( string $peer_url ) {
		$wellknown = wp_remote_get(
			trailingslashit( $peer_url ) . '.well-known/agnosis-node',
			[ 'timeout' => 10, 'sslverify' => true ]
		);

		if ( is_wp_error( $wellknown ) || 200 !== wp_remote_retrieve_response_code( $wellknown ) ) {
			return new WP_Error( 'agnosis_peer_unreachable', __( 'Could not reach this peer\'s node-discovery endpoint.', 'agnosis' ) );
		}

		$pointer  = json_decode( wp_remote_retrieve_body( $wellknown ), true );
		$endpoint = is_array( $pointer ) ? esc_url_raw( (string) ( $pointer['endpoint'] ?? '' ) ) : '';

		if ( '' === $endpoint ) {
			return new WP_Error( 'agnosis_peer_no_endpoint', __( 'This peer\'s node-discovery response did not include a node card endpoint.', 'agnosis' ) );
		}

		$card_response = wp_remote_get( $endpoint, [ 'timeout' => 10, 'sslverify' => true ] );

		if ( is_wp_error( $card_response ) || 200 !== wp_remote_retrieve_response_code( $card_response ) ) {
			return new WP_Error( 'agnosis_peer_card_unreachable', __( 'Could not fetch this peer\'s node card.', 'agnosis' ) );
		}

		$card      = json_decode( wp_remote_retrieve_body( $card_response ), true );
		$actor_id  = is_array( $card ) ? esc_url_raw( (string) ( $card['actor'] ?? '' ) ) : '';
		$inbox_url = is_array( $card ) ? esc_url_raw( (string) ( $card['inbox'] ?? '' ) ) : '';

		if ( '' === $actor_id || '' === $inbox_url ) {
			return new WP_Error( 'agnosis_peer_card_incomplete', __( 'This peer\'s node card is missing an actor id or inbox URL.', 'agnosis' ) );
		}

		return [
			'actor_id'  => $actor_id,
			'inbox_url' => $inbox_url,
			'label'     => is_array( $card ) ? sanitize_text_field( (string) ( $card['label'] ?? '' ) ) : '',
		];
	}

	/**
	 * Whether a trusted Agnosis peer also trusts THIS node back — RN4
	 * (RHIZOME-NETWORK-ROADMAP.md §4/§8, 2026-07-30). Trust here is just a
	 * per-row `status` on each side's own table, not a handshake, so the
	 * only way to know whether a partner trusts this node back is to ask:
	 * `list_peers()` above is already a public, unauthenticated `GET`
	 * returning exactly the `trusted` rows a site currently has — querying
	 * a peer's own copy of that same endpoint and looking for this node's
	 * own `home_url()` in the result is the mechanism Ulises confirmed over
	 * a purely manual admin-set label ("what's best for future
	 * development, querying seems more safe").
	 *
	 * Only meaningful for a genuinely Agnosis peer — a manually-added
	 * third-party Fediverse actor (RN1) has no reason to expose this
	 * endpoint at all, and simply comes back unreachable/malformed here,
	 * same as any other network failure; the caller (RhizomeManager) shows
	 * one "unknown" state for both cases rather than distinguishing them.
	 *
	 * Matches on TWO of this node's own identifiers, not one (§13 F6,
	 * 2026-07-30). A peer that trusted this node through the ordinary
	 * self-registration flow has a row whose `url` is this node's
	 * `home_url()` — that's the only thing `register_peer()` ever captures.
	 * But a peer that trusted this node through RN1's manual-add path has a
	 * row whose `url` is whatever the admin pasted, and that form asks for
	 * an ACTOR url (`RhizomeManager::handle_add_manual()` writes the same
	 * value to both `url` and `actor_id`) — so a genuinely mutual pair
	 * established that way advertised this node's actor URL and a
	 * `home_url()`-only comparison reported it one-directional. Checking
	 * both is the honest read of "does this peer list us"; anything looser
	 * (bare host matching) would call a peer mutual on the strength of it
	 * trusting some entirely different actor on this same domain.
	 *
	 * @return bool|WP_Error True if mutual, false if one-directional, WP_Error if the peer's own peer list couldn't be reached or read.
	 */
	public function check_reciprocity( string $peer_url ) {
		$response = wp_remote_get(
			trailingslashit( $peer_url ) . 'wp-json/agnosis/v1/node/peers',
			[ 'timeout' => 10, 'sslverify' => true ]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'agnosis_reciprocity_unreachable', __( 'Could not reach this peer\'s own peer list.', 'agnosis' ) );
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$peers = is_array( $body ) && is_array( $body['peers'] ?? null ) ? $body['peers'] : null;

		if ( null === $peers ) {
			return new WP_Error( 'agnosis_reciprocity_malformed', __( 'This peer\'s peer list response was not in the expected format.', 'agnosis' ) );
		}

		$self = array_filter( [
			untrailingslashit( home_url() ),
			untrailingslashit( ( new ActivityPub() )->actor_url_for( 'node', 0 ) ),
		] );

		foreach ( $peers as $row ) {
			$row_url = is_array( $row ) ? untrailingslashit( (string) ( $row['url'] ?? '' ) ) : '';
			if ( '' !== $row_url && in_array( $row_url, $self, true ) ) {
				return true;
			}
		}

		return false;
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
