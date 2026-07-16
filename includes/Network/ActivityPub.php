<?php
/**
 * ActivityPub implementation.
 *
 * Implements ActivityPub actors for the Agnosis node, making published
 * artworks discoverable from Mastodon, Pixelfed, and the broader Fediverse
 * without any central server.
 *
 * Endpoints — each registered twice, once for the single node-level actor
 * and once per artist (audit §3h: per-artist actors), sharing the same
 * callbacks:
 *   GET  /agnosis/v1/activitypub/actor[/{artist_id}]          — Actor object
 *   GET  /agnosis/v1/activitypub/actor[/{artist_id}]/outbox   — ordered collection of Create activities
 *   POST /agnosis/v1/activitypub/actor[/{artist_id}]/inbox    — receive Follow / Undo / Announce / Like
 *   GET  /agnosis/v1/activitypub/actor[/{artist_id}]/followers — follower list
 *   POST /agnosis/v1/activitypub/inbox                        — the node's inbox; also every actor's sharedInbox
 *   GET  /.well-known/webfinger?resource=acct:*                — WebFinger discovery (node or any artist)
 *   GET  /.well-known/nodeinfo, /agnosis/v1/activitypub/nodeinfo — NodeInfo 2.0 (audit §3h)
 *
 * Per-artist actors (audit §3h — filed by the ninth audit as a deliberate
 * 1.0.0+ roadmap decision, built now on explicit request): before this, the
 * single node-level actor meant a fediverse follower got the whole node's
 * firehose or nothing — there was no way to follow one artist. Every artist
 * with the `agnosis_artist` role now has their own actor (type `Person`,
 * `preferredUsername` = their `user_nicename`, `url` = their subdomain via
 * `SubdomainRouter::url_for_artist()`), own RSA keypair (lazily generated,
 * usermeta `_agnosis_ap_public_key`/`_agnosis_ap_private_key` — see
 * ensure_artist_key_pair(), mirroring how `Network\Node::ensure_key_pair()`
 * already handles the node's own single keypair), own inbox/outbox/followers.
 * A published artwork's Create/Update/Delete is attributed to its author's
 * own actor (owner_for_post()) and delivered to the UNION of that artist's
 * followers and the node's own followers, deduplicated by inbox — so
 * existing node-level followers keep the full firehose, and a new follower
 * can now choose to follow just one artist. `agnosis_followers` and
 * `agnosis_ap_delivery_queue` are both scoped by (owner_type, owner_id) to
 * support this — see resolve_local_owner() and signing_key_for().
 *
 * Artwork permalinks additionally content-negotiate (audit §3c): a GET with
 * an Accept header naming application/activity+json or application/ld+json
 * receives the artwork's Note object as JSON instead of the theme's HTML, so
 * object ids dereference to the object as the AP spec expects (Mastodon
 * re-fetches an object by id to verify or refresh it — e.g. processing a
 * boost seen from a third server, or a URL pasted into search).
 *
 * The artwork lifecycle federates end to end (audit §3e): leaving `publish`
 * (trash, unpublish, force delete — including the community removal-vote and
 * artist-departure flows) delivers `Delete { object: Tombstone }` to every
 * follower and the object id serves HTTP 410 + Tombstone JSON thereafter;
 * a meaningful edit of a published artwork (title/content via
 * `ContentEditor`, or a replaced photo) delivers `Update` with the
 * refreshed Note. Language siblings are never federated — Create fires only
 * for the primary post, so Delete/Update mirror that scope.
 *
 * Followers are stored in the agnosis_followers table, keyed by actor id
 * (audit §3g note iii — replaces an autoloaded, wholesale-rewritten-on-every-
 * Follow/Undo option). A delivery that fails is retried on a backoff
 * schedule by the agnosis_ap_retry_deliveries cron tick (audit §3g note iv)
 * rather than being lost after one fire-and-forget attempt; see
 * process_delivery_retry_queue() and the RETRY_INTERVALS constant.
 *
 * @package Agnosis\Network
 */

declare(strict_types=1);

namespace Agnosis\Network;

use Agnosis\Compat\LinguaForge;
use Agnosis\Core\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use WP_Error;

class ActivityPub {

	private const CONTEXT = 'https://www.w3.org/ns/activitystreams';

	/**
	 * Maximum tombstone-registry entries. Oldest are pruned beyond this —
	 * a remote server re-fetching a years-deleted object simply gets the
	 * theme's 404 instead of a 410, which is a graceful degradation. Keeps
	 * the option bounded (the §3g scale lesson originally flagged against
	 * agnosis_ap_followers, applied here from day one; the option is stored
	 * with autoload=false). Followers themselves moved to a dedicated table
	 * when that same finding was closed — see agnosis_followers below.
	 */
	private const TOMBSTONE_CAP = 500;

	/**
	 * Backoff schedule for the delivery retry queue (audit §3g note iv).
	 * Index N is how long to wait before the (N+2)th attempt at a delivery —
	 * the live deliver() call is attempt 1, so the first agnosis_vendor_retry (index 0) is
	 * scheduled 5 minutes after that fails. A delivery that still hasn't
	 * succeeded after every interval here is marked 'failed' for good — total
	 * span is a little over 4 days, in the neighborhood of how long Mastodon
	 * itself keeps retrying a delivery before giving up on a dead inbox.
	 */
	private const RETRY_INTERVALS = [
		5 * MINUTE_IN_SECONDS,
		30 * MINUTE_IN_SECONDS,
		2 * HOUR_IN_SECONDS,
		12 * HOUR_IN_SECONDS,
		DAY_IN_SECONDS,
		3 * DAY_IN_SECONDS,
	];

	/** Max retry-queue rows processed per agnosis_ap_retry_deliveries cron tick. */
	private const RETRY_BATCH_SIZE = 20;

	/**
	 * How long a delivery-retry row may sit 'claimed' before
	 * process_delivery_retry_queue()'s stale-claim sweep treats it as
	 * abandoned and returns it to 'pending' (security audit §2c) — see that
	 * method's own docblock.
	 */
	private const STALE_CLAIM_MINUTES = 30;

	public function register_routes(): void {
		$args = [ 'permission_callback' => '__return_true' ];

		register_rest_route( 'agnosis/v1', '/activitypub/actor',                            array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'actor'     ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/actor/(?P<artist_id>\d+)',          array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'actor'     ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/outbox',                            array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'outbox'    ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/actor/(?P<artist_id>\d+)/outbox',   array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'outbox'    ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/followers',                         array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'followers' ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/actor/(?P<artist_id>\d+)/followers', array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'followers' ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/nodeinfo',                          array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'nodeinfo'  ] ] ) );

		// Every actor's inbox — the node's own, and each artist's own —
		// shares the same callback pair: resolve_local_owner() determines the
		// activity's actual target from the Follow/Undo body itself (spec-
		// correct — the URL delivered to is just routing, not authoritative),
		// so /activitypub/inbox doubles as both the node's dedicated inbox
		// AND the sharedInbox every actor's `endpoints.sharedInbox` advertises
		// (audit §3h note iii).
		register_rest_route( 'agnosis/v1', '/activitypub/inbox', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'inbox' ],
			'permission_callback' => [ $this, 'verify_inbox_signature' ],
		] );
		register_rest_route( 'agnosis/v1', '/activitypub/actor/(?P<artist_id>\d+)/inbox', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'inbox' ],
			'permission_callback' => [ $this, 'verify_inbox_signature' ],
		] );

		// WebFinger.
		add_filter( 'query_vars', fn( $v ) => array_merge( $v, [ 'agnosis_webfinger' ] ) );
		add_rewrite_rule( '^\.well-known/webfinger$', 'index.php?agnosis_webfinger=1', 'top' );
		add_action( 'template_redirect', [ $this, 'handle_webfinger' ] );

		// NodeInfo discovery (audit §3h note ii) — the document itself is the
		// plain REST route registered above; this is only the well-known
		// pointer to it, mirroring WebFinger's own rewrite-rule pattern.
		add_filter( 'query_vars', fn( $v ) => array_merge( $v, [ 'agnosis_nodeinfo' ] ) );
		add_rewrite_rule( '^\.well-known/nodeinfo$', 'index.php?agnosis_nodeinfo=1', 'top' );
		add_action( 'template_redirect', [ $this, 'handle_nodeinfo_discovery' ] );
	}

	// -------------------------------------------------------------------------
	// Actor
	// -------------------------------------------------------------------------

	public function actor( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$artist_id = $request->get_param( 'artist_id' );
		if ( null !== $artist_id ) {
			return $this->artist_actor( (int) $artist_id );
		}

		$node_url   = rest_url( 'agnosis/v1/activitypub/actor' );
		$public_key = get_option( 'agnosis_public_key', '' );

		return new WP_REST_Response( [
			'@context'          => [ self::CONTEXT, 'https://w3id.org/security/v1' ],
			'type'              => 'Service',
			'id'                => $node_url,
			'url'               => home_url(),
			'name'              => get_option( 'agnosis_node_label', get_bloginfo( 'name' ) ),
			'summary'           => get_bloginfo( 'description' ),
			'preferredUsername' => 'agnosis',
			'inbox'             => rest_url( 'agnosis/v1/activitypub/inbox' ),
			'outbox'            => rest_url( 'agnosis/v1/activitypub/outbox' ),
			'followers'         => rest_url( 'agnosis/v1/activitypub/followers' ),
			// Audit §3h note iii: harmless to advertise even with one actor per
			// node, and now genuinely useful — a remote server delivering the
			// same activity to both the node and one of its artists can use
			// this single endpoint instead of two round trips.
			'endpoints'         => [ 'sharedInbox' => rest_url( 'agnosis/v1/activitypub/inbox' ) ],
			'publicKey'         => [
				'id'           => $node_url . '#main-key',
				'owner'        => $node_url,
				'publicKeyPem' => $public_key,
			],
		], 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	/**
	 * Build one artist's own ActivityPub actor document (audit §3h).
	 *
	 * type `Person`, not the node's `Service` — an artist is a person, and
	 * this is the whole point of the feature: a fediverse user can follow
	 * one artist specifically, not just the node's undifferentiated firehose.
	 * 404s for anything that isn't a real, currently-admitted artist, so a
	 * random/departed user id doesn't leak account existence or resolve to a
	 * stale actor.
	 */
	private function artist_actor( int $user_id ): WP_REST_Response|WP_Error {
		$user = $this->require_artist( $user_id );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$actor_url = $this->actor_url_for( 'artist', $user_id );
		$keys      = $this->ensure_artist_key_pair( $user_id );

		return new WP_REST_Response( [
			'@context'          => [ self::CONTEXT, 'https://w3id.org/security/v1' ],
			'type'              => 'Person',
			'id'                => $actor_url,
			'url'               => SubdomainRouter::url_for_artist( $user_id ),
			'name'              => $user->display_name,
			'preferredUsername' => $user->user_nicename,
			'inbox'             => $actor_url . '/inbox',
			'outbox'            => $actor_url . '/outbox',
			'followers'         => $actor_url . '/followers',
			'endpoints'         => [ 'sharedInbox' => rest_url( 'agnosis/v1/activitypub/inbox' ) ],
			'publicKey'         => [
				'id'           => $actor_url . '#main-key',
				'owner'        => $actor_url,
				'publicKeyPem' => $keys['public'],
			],
		], 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	/**
	 * 404 unless $user_id is a real user currently holding the agnosis_artist
	 * role — shared guard for every per-artist AP route (actor/outbox/followers).
	 *
	 * @return WP_User|WP_Error
	 */
	private function require_artist( int $user_id ): WP_User|WP_Error {
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'agnosis_artist', (array) $user->roles, true ) ) {
			return new WP_Error( 'ap_actor_not_found', __( 'No such artist actor.', 'agnosis' ), [ 'status' => 404 ] );
		}
		return $user;
	}

	/** The actor id/URL for the node, or for one specific artist. */
	private function actor_url_for( string $owner_type, int $owner_id ): string {
		return 'artist' === $owner_type && $owner_id > 0
			? rest_url( 'agnosis/v1/activitypub/actor/' . $owner_id )
			: rest_url( 'agnosis/v1/activitypub/actor' );
	}

	/**
	 * Which local actor a post's Create/Update/Delete is attributed to and
	 * delivered as (audit §3h). An artwork's post_author is the submitting
	 * artist's real WP user id (Publishing\PostCreator sets it directly, not
	 * a system/admin user), so this is a straightforward lookup — falls back
	 * to the node only for the edge case of a post with no real artist author
	 * (shouldn't happen in practice, but post_author does default to 1 when
	 * no artist_id resolved at submission time).
	 *
	 * @return array{type: string, id: int}
	 */
	private function owner_for_post( \WP_Post $post ): array {
		$author_id = (int) $post->post_author;
		if ( $author_id > 0 ) {
			$author = get_userdata( $author_id );
			if ( $author && in_array( 'agnosis_artist', (array) $author->roles, true ) ) {
				return [ 'type' => 'artist', 'id' => $author_id ];
			}
		}
		return [ 'type' => 'node', 'id' => 0 ];
	}

	/**
	 * Lazily generate (once) and return an artist's own RSA keypair for their
	 * personal ActivityPub actor (audit §3h). Stored in usermeta — one pair
	 * per artist, distinct from the single node-level pair
	 * `Network\Node::ensure_key_pair()` manages in options for the node's own
	 * identity. Mirrors that method's own WP_TESTS_DOMAIN guard: 2048-bit RSA
	 * generation reads from /dev/random, which blocks indefinitely under
	 * entropy starvation inside Docker test containers, so key generation is
	 * skipped entirely during tests — routes still register and work, they
	 * just expose an empty publicKeyPem unless a test explicitly seeds one.
	 *
	 * @return array{public: string, private: string}
	 */
	private function ensure_artist_key_pair( int $user_id ): array {
		$public  = (string) get_user_meta( $user_id, '_agnosis_ap_public_key', true );
		$private = (string) get_user_meta( $user_id, '_agnosis_ap_private_key', true );

		if ( '' !== $public && '' !== $private ) {
			return [ 'public' => $public, 'private' => $private ];
		}

		if ( defined( 'WP_TESTS_DOMAIN' ) || ! function_exists( 'openssl_pkey_new' ) ) {
			return [ 'public' => '', 'private' => '' ];
		}

		$key = openssl_pkey_new( [ 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		if ( false === $key ) {
			return [ 'public' => '', 'private' => '' ];
		}

		openssl_pkey_export( $key, $private );
		$details = openssl_pkey_get_details( $key );
		$public  = $details['key'] ?? '';

		if ( '' === $public ) {
			return [ 'public' => '', 'private' => '' ];
		}

		update_user_meta( $user_id, '_agnosis_ap_public_key', $public );
		update_user_meta( $user_id, '_agnosis_ap_private_key', $private );

		return [ 'public' => $public, 'private' => $private ];
	}

	// -------------------------------------------------------------------------
	// Outbox — recent artworks as Create activities
	// -------------------------------------------------------------------------

	/**
	 * GET /agnosis/v1/activitypub/outbox — root discovery when called with no
	 * `page` param, a specific page's items when called with one.
	 *
	 * Audit §3d: this used to always return an `OrderedCollectionPage` — even
	 * at the root, and with no `first`/`next`/`prev` links — so a
	 * spec-conformant consumer (Mastodon's profile backfill, fedi
	 * crawlers/archive tools) GETting the root to discover pagination saw a
	 * page of a collection that was never itself served, with page 2+
	 * unreachable except by guessing the query param. The root now serves an
	 * `OrderedCollection` naming `first`; a paged request gets the existing
	 * page shape plus `next` (while more items remain) and `prev` (beyond
	 * page 1).
	 */
	public function outbox( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$artist_id = $request->get_param( 'artist_id' );

		if ( null !== $artist_id ) {
			$not_found = $this->require_artist( (int) $artist_id );
			if ( is_wp_error( $not_found ) ) {
				return $not_found;
			}
		}

		$base = null !== $artist_id
			? $this->actor_url_for( 'artist', (int) $artist_id ) . '/outbox'
			: rest_url( 'agnosis/v1/activitypub/outbox' );

		// Audit §3h: a per-artist outbox counts only THAT artist's own
		// published artworks. count_user_posts() with $public_only handles
		// this directly; wp_count_posts() has no author filter at all, which
		// is why the node-level (unscoped) branch keeps using it.
		$total = null !== $artist_id
			? (int) count_user_posts( (int) $artist_id, 'agnosis_artwork', true )
			: (int) wp_count_posts( 'agnosis_artwork' )->publish;

		$requested_page = $request->get_param( 'page' );

		if ( null === $requested_page ) {
			return new WP_REST_Response( [
				'@context'   => self::CONTEXT,
				'type'       => 'OrderedCollection',
				'id'         => $base,
				'totalItems' => $total,
				'first'      => $base . '?page=1',
			], 200, [ 'Content-Type' => 'application/activity+json' ] );
		}

		$page  = max( 1, (int) $requested_page );
		$limit = 20;

		$query_args = [
			'post_type'      => 'agnosis_artwork',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => ( $page - 1 ) * $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];
		if ( null !== $artist_id ) {
			$query_args['author'] = (int) $artist_id;
		}

		$posts = get_posts( $query_args );
		$items = array_map( [ $this, 'post_to_activity' ], $posts );

		$page_activity = [
			'@context'     => self::CONTEXT,
			'type'         => 'OrderedCollectionPage',
			'id'           => $base . '?page=' . $page,
			'partOf'       => $base,
			'totalItems'   => $total,
			'orderedItems' => $items,
		];

		if ( ( $page * $limit ) < $total ) {
			$page_activity['next'] = $base . '?page=' . ( $page + 1 );
		}

		if ( $page > 1 ) {
			$page_activity['prev'] = $base . '?page=' . ( $page - 1 );
		}

		return new WP_REST_Response( $page_activity, 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	// -------------------------------------------------------------------------
	// Inbox — receive Follow, Like, Announce
	// -------------------------------------------------------------------------

	/**
	 * Permission callback for POST /activitypub/inbox.
	 *
	 * Verifies the HTTP Signature carried in the incoming request before the
	 * inbox() callback has a chance to mutate any state.  Returns WP_Error on
	 * failure so WordPress sends the appropriate 4xx without running inbox().
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function verify_inbox_signature( WP_REST_Request $request ): bool|WP_Error {
		$verified = HttpSignature::verify( $request );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		// Audit §3b: verify() proves the request was signed by the key at
		// keyId — this additionally binds that identity to the actor the
		// activity claims to be from, so a valid key on some other server
		// can't forge a Follow/Undo in another actor's name.
		return HttpSignature::verify_actor_binding( $request );
	}

	public function inbox( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		$type = $body['type'] ?? '';

		switch ( $type ) {
			case 'Follow':
				return $this->handle_follow( $body );
			case 'Like':
			case 'Announce':
				do_action( 'agnosis_activity_received', $body );
				return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
			case 'Undo':
				return $this->handle_undo( $body );
			default:
				return new WP_REST_Response( [ 'status' => 'ignored', 'type' => $type ], 200 );
		}
	}

	public function followers( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$artist_id = $request->get_param( 'artist_id' );

		if ( null !== $artist_id ) {
			$not_found = $this->require_artist( (int) $artist_id );
			if ( is_wp_error( $not_found ) ) {
				return $not_found;
			}
		}

		[ $owner_type, $owner_id ] = null !== $artist_id ? [ 'artist', (int) $artist_id ] : [ 'node', 0 ];

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small, node-scale table (audit §3g note iii); parameterized via prepare().
		$inbox_urls = $wpdb->get_col( $wpdb->prepare(
			"SELECT inbox_url FROM {$wpdb->prefix}agnosis_followers WHERE owner_type = %s AND owner_id = %d ORDER BY id ASC",
			$owner_type,
			$owner_id
		) );

		$collection_id = null !== $artist_id
			? $this->actor_url_for( 'artist', (int) $artist_id ) . '/followers'
			: rest_url( 'agnosis/v1/activitypub/followers' );

		return new WP_REST_Response( [
			'@context'   => self::CONTEXT,
			'type'       => 'OrderedCollection',
			'id'         => $collection_id,
			'totalItems' => count( $inbox_urls ),
			'orderedItems' => $inbox_urls,
		], 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	// -------------------------------------------------------------------------
	// Broadcast a new artwork to all followers
	// -------------------------------------------------------------------------

	public function broadcast( int $post_id ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'agnosis_artwork' ) {
			return;
		}

		// A (re)published artwork dereferences again — its slug must not
		// shadow a stale tombstone (audit §3e).
		$this->clear_tombstone( $post->post_name );

		$owner = $this->owner_for_post( $post );
		$this->deliver_to_followers( $this->post_to_activity( $post ), $owner['type'], $owner['id'] );
	}

	// -------------------------------------------------------------------------
	// Lifecycle federation — Delete + Tombstone, Update (audit §3e)
	// -------------------------------------------------------------------------

	/**
	 * transition_post_status handler: federate an artwork leaving `publish`.
	 *
	 * Covers trash (the community removal-vote flow's RemovalEndpoints path
	 * ends in wp_trash_post()), unpublish/draft, and any other transition out
	 * of publish. Transitions INTO publish clear a stale tombstone for the
	 * slug so a restored or re-slugged artwork dereferences again.
	 */
	public function federate_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'agnosis_artwork' !== $post->post_type || $new_status === $old_status ) {
			return;
		}

		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			$this->federate_delete( $post );
		} elseif ( 'publish' === $new_status ) {
			$this->clear_tombstone( $post->post_name );
		}
	}

	/**
	 * before_delete_post handler: federate a force-deleted published artwork.
	 *
	 * wp_delete_post() (e.g. Departure's force_delete of a leaving/banned
	 * artist's works) never fires transition_post_status, so the trash-path
	 * hook alone would miss it. A post force-deleted FROM trash was already
	 * tombstoned at trash time and is skipped by the status guard.
	 */
	public function federate_force_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post && 'agnosis_artwork' === $post->post_type && 'publish' === $post->post_status ) {
			$this->federate_delete( $post );
		}
	}

	/**
	 * post_updated handler: federate a meaningful edit of a published artwork.
	 *
	 * "Meaningful" = title, content, or excerpt changed (ContentEditor's
	 * title/text edits land here via wp_update_post()). Both sides must be
	 * `publish` — that also keeps the wp_trash_post()-internal update from
	 * double-firing next to the Delete.
	 */
	public function federate_update( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		unset( $post_id );

		if ( 'agnosis_artwork' !== $post_after->post_type ) {
			return;
		}
		if ( 'publish' !== $post_after->post_status || 'publish' !== $post_before->post_status ) {
			return;
		}
		if ( $post_after->post_title === $post_before->post_title
			&& $post_after->post_content === $post_before->post_content
			&& $post_after->post_excerpt === $post_before->post_excerpt ) {
			return;
		}

		$this->broadcast_update( $post_after );
	}

	/**
	 * updated_post_meta / added_post_meta handler: a replaced or newly set
	 * featured image on a published artwork is a meaningful edit too —
	 * ContentEditor's photo replacement goes through set_post_thumbnail(),
	 * which never fires post_updated.
	 */
	public function federate_thumbnail_update( int $meta_id, int $post_id, string $meta_key ): void {
		unset( $meta_id );

		if ( '_thumbnail_id' !== $meta_key ) {
			return;
		}

		$post = get_post( $post_id );
		if ( $post && 'agnosis_artwork' === $post->post_type && 'publish' === $post->post_status ) {
			$this->broadcast_update( $post );
		}
	}

	// -------------------------------------------------------------------------
	// WebFinger
	// -------------------------------------------------------------------------

	/**
	 * WebFinger discovery (RFC 7033) — resolves `acct:agnosis@{host}` (the
	 * node) or `acct:{nicename}@{host}` for any admitted artist (audit §3h:
	 * per-artist actors use the SAME host as the node — the base domain, not
	 * an artist's own subdomain — so a handle like `@artistname@agnosis.art`
	 * is what a fediverse user actually follows, matching how a Mastodon
	 * instance's own users all share one host).
	 *
	 * Content-Type is `application/jrd+json` (audit §3h note i) — the spec's
	 * actual required type, not `application/json`; most servers tolerate the
	 * looser type, but conformance-checking ones and some client libraries
	 * don't. wp_send_json() can't be used for this response since it always
	 * sets its own `application/json` Content-Type internally after any
	 * header a caller sets first — send_jrd_json() replicates its shape with
	 * the correct type instead.
	 */
	public function handle_webfinger(): void {
		if ( ! get_query_var( 'agnosis_webfinger' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WebFinger is a public unauthenticated discovery endpoint; nonces are not applicable.
		$resource = sanitize_text_field( wp_unslash( $_GET['resource'] ?? '' ) );
		$result   = $this->resolve_webfinger_subject( $resource );

		if ( null === $result ) {
			$this->send_jrd_json( [ 'error' => 'not found' ], 404 );
		}

		$this->send_jrd_json( $result, 200 );
	}

	/**
	 * Resolve a WebFinger `resource` param to its JRD response body, or null
	 * when unresolvable. Split from handle_webfinger() so the resolution
	 * logic is directly testable without the exit — mirrors
	 * singular_activity_json()'s existing split from its own exit-wrapper
	 * (serve_artwork_activity_json()) elsewhere in this file.
	 *
	 * @return array{subject: string, links: array<int, array<string, string>>}|null
	 */
	public function resolve_webfinger_subject( string $webfinger_resource ): ?array {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! preg_match( '/^acct:([^@]+)@' . preg_quote( $host, '/' ) . '$/', $webfinger_resource, $matches ) ) {
			return null;
		}

		$username = $matches[1];

		if ( 'agnosis' === $username ) {
			return [
				'subject' => $webfinger_resource,
				'links'   => [
					[
						'rel'  => 'self',
						'type' => 'application/activity+json',
						'href' => rest_url( 'agnosis/v1/activitypub/actor' ),
					],
				],
			];
		}

		$user = get_user_by( 'slug', $username );
		if ( $user && in_array( 'agnosis_artist', (array) $user->roles, true ) ) {
			return [
				'subject' => $webfinger_resource,
				'links'   => [
					[
						'rel'  => 'self',
						'type' => 'application/activity+json',
						'href' => $this->actor_url_for( 'artist', $user->ID ),
					],
				],
			];
		}

		return null;
	}

	/**
	 * Send a WebFinger (JRD) response and end the request — see
	 * handle_webfinger()'s docblock for why this can't just be wp_send_json().
	 *
	 * @param array<string, mixed> $data
	 */
	private function send_jrd_json( array $data, int $status ): void {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/jrd+json; charset=' . get_option( 'blog_charset' ) );
		}
		status_header( $status );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- machine-readable JSON body built by wp_json_encode(); HTML escaping would corrupt it.
		echo wp_json_encode( $data );
		exit;
	}

	// -------------------------------------------------------------------------
	// NodeInfo (audit §3h note ii)
	// -------------------------------------------------------------------------

	/**
	 * /.well-known/nodeinfo — points at the versioned document below. Kept as
	 * its own tiny discovery doc per spec, mirroring WebFinger's own
	 * well-known-rewrite-rule pattern in register_routes().
	 */
	public function handle_nodeinfo_discovery(): void {
		if ( ! get_query_var( 'agnosis_nodeinfo' ) ) {
			return;
		}

		wp_send_json( [
			'links' => [
				[
					'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
					'href' => rest_url( 'agnosis/v1/activitypub/nodeinfo' ),
				],
			],
		], 200 );
	}

	/**
	 * The NodeInfo 2.0 document itself — static, cheap, and the thing that
	 * makes this node visible to the Fediverse's own observatories/census
	 * tools, which was the whole point of the audit's note (an Agnosis node
	 * was previously invisible to that ecosystem even while being a working
	 * federation participant). `usage.users.total` now genuinely means
	 * something once per-artist actors exist — each admitted artist is a
	 * distinct fediverse-followable "user", not just an internal role.
	 */
	public function nodeinfo(): WP_REST_Response {
		$counts       = count_users();
		$artist_count = (int) ( $counts['avail_roles']['agnosis_artist'] ?? 0 );

		return new WP_REST_Response( [
			'version'   => '2.0',
			'software'  => [
				'name'    => 'agnosis',
				'version' => defined( 'AGNOSIS_VERSION' ) ? AGNOSIS_VERSION : '0.0.0',
			],
			'protocols' => [ 'activitypub' ],
			'services'  => [ 'inbound' => [], 'outbound' => [] ],
			'openRegistrations' => false,
			'usage'     => [
				'users'      => [ 'total' => $artist_count ],
				'localPosts' => (int) wp_count_posts( 'agnosis_artwork' )->publish,
			],
			// NodeInfo requires an OBJECT for metadata, even when empty — a
			// PHP [] would serialize as JSON `[]`, not the `{}` the schema
			// expects.
			'metadata'  => new \stdClass(),
		], 200, [ 'Content-Type' => 'application/json; profile="http://nodeinfo.diaspora.software/ns/schema/2.0#"' ] );
	}

	// -------------------------------------------------------------------------
	// Content negotiation on artwork singulars (audit §3c)
	// -------------------------------------------------------------------------

	/**
	 * template_redirect handler: serve the Note JSON when an ActivityPub
	 * consumer dereferences an artwork's object id.
	 *
	 * Wired on template_redirect (frontend requests), so it fires in every
	 * permalink mode — pretty (/art/<slug>) and plain (?agnosis_artwork=<slug>)
	 * alike. A live artwork serves its Note (200); a tombstoned slug serves
	 * the Tombstone with HTTP 410 (audit §3e), so remote servers get the
	 * fediverse-normative "this object is gone, drop your copy" signal when
	 * they re-fetch.
	 */
	public function serve_artwork_activity_json(): void {
		$json = $this->singular_activity_json();
		if ( null !== $json ) {
			$this->emit_activity_json( $json, 200 );
		}

		$tombstone = $this->tombstone_activity_json();
		if ( null !== $tombstone ) {
			$this->emit_activity_json( $tombstone, 410 );
		}
	}

	/**
	 * Send an ActivityStreams JSON response and end the request.
	 *
	 * @param string $json   Pre-encoded payload.
	 * @param int    $status HTTP status code (200 for a Note, 410 for a Tombstone).
	 */
	private function emit_activity_json( string $json, int $status ): void {
		status_header( $status );
		header( 'Content-Type: application/activity+json; charset=' . get_option( 'blog_charset' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- machine-readable JSON body built by wp_json_encode(); HTML escaping would corrupt it.
		echo $json;
		exit;
	}

	/**
	 * Decide whether the current main query should be answered with the
	 * artwork's Note JSON, and build it if so.
	 *
	 * Split from serve_artwork_activity_json() so the guard-and-build logic
	 * is testable without the exit. Returns null when any guard declines:
	 * not an artwork singular, ActivityPub disabled, not published, or the
	 * Accept header doesn't name an ActivityStreams media type (Mastodon
	 * sends "application/activity+json, application/ld+json;
	 * profile=\"https://www.w3.org/ns/activitystreams\"" when
	 * dereferencing).
	 *
	 * @return string|null JSON payload, or null to let the theme render HTML.
	 */
	public function singular_activity_json(): ?string {
		if ( ! is_singular( 'agnosis_artwork' ) ) {
			return null;
		}

		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return null;
		}

		if ( ! $this->accept_is_activitystreams() ) {
			return null;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		$json = wp_json_encode( $this->post_to_note( $post ) );

		return false === $json ? null : $json;
	}

	/**
	 * Build the Tombstone JSON when an AP consumer dereferences a deleted
	 * artwork's object id (audit §3e).
	 *
	 * A live artwork at the slug is singular_activity_json()'s case; this one
	 * fires when the main query found nothing (trashed, unpublished, or
	 * deleted) but the requested artwork slug is in the tombstone registry.
	 * Browsers (no AS2 Accept) keep the theme's ordinary 404.
	 *
	 * @return string|null JSON payload (serve with HTTP 410), or null.
	 */
	public function tombstone_activity_json(): ?string {
		if ( is_singular( 'agnosis_artwork' ) ) {
			return null;
		}

		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return null;
		}

		if ( ! $this->accept_is_activitystreams() ) {
			return null;
		}

		$slug = (string) get_query_var( 'agnosis_artwork' );
		if ( '' === $slug ) {
			return null;
		}

		$tombstones = get_option( 'agnosis_ap_tombstones', [] );
		if ( ! isset( $tombstones[ $slug ]['id'], $tombstones[ $slug ]['deleted'] ) ) {
			return null;
		}

		$json = wp_json_encode( [
			'@context'   => self::CONTEXT,
			'type'       => 'Tombstone',
			'id'         => $tombstones[ $slug ]['id'],
			'formerType' => 'Note',
			'deleted'    => $tombstones[ $slug ]['deleted'],
		] );

		return false === $json ? null : $json;
	}

	/** Does the request's Accept header name an ActivityStreams media type? */
	private function accept_is_activitystreams(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a request header on a public GET; nonces are not applicable.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';

		return str_contains( $accept, 'application/activity+json' ) || str_contains( $accept, 'application/ld+json' );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Federate `Delete { object: Tombstone }` for a post leaving publish, and
	 * record the tombstone so the object id serves 410 thereafter.
	 *
	 * The tombstone is recorded even when there are currently no followers:
	 * a third server that ever saw the object (via a boost, or §3c
	 * dereferencing) can still learn it's gone when it re-fetches.
	 */
	private function federate_delete( \WP_Post $post ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		if ( ! $this->is_primary_language_post( $post->ID ) ) {
			return; // Language siblings were never Created remotely — nothing to Delete.
		}

		$object_id = $this->object_id_for( $post );
		$deleted   = gmdate( 'c' );

		$this->record_tombstone( preg_replace( '/__trashed$/', '', $post->post_name ), $object_id, $deleted );

		$owner = $this->owner_for_post( $post );

		$this->deliver_to_followers( [
			'@context' => self::CONTEXT,
			'type'     => 'Delete',
			'id'       => $object_id . '#delete',
			'actor'    => $this->actor_url_for( $owner['type'], $owner['id'] ),
			'to'       => [ 'https://www.w3.org/ns/activitystreams#Public' ],
			'object'   => [
				'type'       => 'Tombstone',
				'id'         => $object_id,
				'formerType' => 'Note',
				'deleted'    => $deleted,
			],
		], $owner['type'], $owner['id'] );
	}

	/**
	 * Federate `Update` with the refreshed Note.
	 *
	 * Deduplicated per post per request: a single editorial save can touch
	 * the post row AND the thumbnail meta (two hooks), but one refreshed
	 * Note says everything.
	 */
	private function broadcast_update( \WP_Post $post ): void {
		static $sent = [];

		if ( isset( $sent[ $post->ID ] ) ) {
			return;
		}

		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		if ( ! $this->is_primary_language_post( $post->ID ) ) {
			return; // Same scope as Create/Delete: only the primary post federates.
		}

		$sent[ $post->ID ] = true;

		$note  = $this->post_to_note( $post );
		$owner = $this->owner_for_post( $post );

		$this->deliver_to_followers( [
			'@context'  => self::CONTEXT,
			'type'      => 'Update',
			'id'        => $note['id'] . '#update-' . time(),
			'actor'     => $note['attributedTo'],
			'published' => gmdate( 'c' ),
			'to'        => $note['to'],
			'object'    => $note,
		], $owner['type'], $owner['id'] );
	}

	/**
	 * Deliver one activity to every relevant follower inbox.
	 *
	 * For the node itself, that's the node's own follower list, unchanged.
	 * For an artist (audit §3h), it's the UNION of that artist's own
	 * followers and the node's followers — deduplicated by inbox_url — so
	 * existing node-level followers keep getting the full firehose (nobody's
	 * subscription silently narrows just because artists now have their own
	 * actors) while a new follower can choose to follow just one artist.
	 *
	 * @param array<string, mixed> $activity Activity payload.
	 */
	private function deliver_to_followers( array $activity, string $owner_type = 'node', int $owner_id = 0 ): void {
		global $wpdb;

		if ( 'artist' === $owner_type && $owner_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small, node-scale table (audit §3g note iii); parameterized via prepare().
			$inbox_urls = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT inbox_url FROM {$wpdb->prefix}agnosis_followers
				 WHERE ( owner_type = 'node' AND owner_id = 0 ) OR ( owner_type = 'artist' AND owner_id = %d )",
				$owner_id
			) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small, node-scale table (audit §3g note iii); no caching layer for it exists.
			$inbox_urls = $wpdb->get_col( "SELECT inbox_url FROM {$wpdb->prefix}agnosis_followers WHERE owner_type = 'node' AND owner_id = 0 ORDER BY id ASC" );
		}

		foreach ( $inbox_urls as $follower_inbox ) {
			$this->deliver( $follower_inbox, $activity, $owner_type, $owner_id );
		}
	}

	/**
	 * The object id a post federated under — its published permalink.
	 *
	 * At Delete time the post may already carry a non-publish status (and,
	 * on slug conflict, a `__trashed`-suffixed name), which would make
	 * get_permalink() fall back to a query-var URL that never matched the
	 * Note's id. Resolve via a publish-status clone with the clean slug so
	 * core's own permalink logic produces the id the object was Created with.
	 */
	private function object_id_for( \WP_Post $post ): string {
		$proxy              = clone $post;
		$proxy->post_status = 'publish';
		$proxy->post_name   = preg_replace( '/__trashed$/', '', $post->post_name );

		return (string) get_permalink( $proxy );
	}

	/**
	 * Is this the primary-language post — the only one that federates?
	 *
	 * Mirrors PostCreator::primary_language_meta_query()'s rule: no `_lf_lang`
	 * meta means the post predates/ignores Lingua Forge (primary by
	 * definition); otherwise the meta must equal the configured primary
	 * language (falling back to the site locale's language, as LF does).
	 */
	private function is_primary_language_post( int $post_id ): bool {
		$lf_lang = sanitize_key( (string) get_post_meta( $post_id, '_lf_lang', true ) );
		if ( '' === $lf_lang ) {
			return true;
		}

		$primary = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		if ( '' === $primary ) {
			$primary = LinguaForge::locale_to_lang( get_locale() );
		}

		return $lf_lang === $primary;
	}

	/**
	 * Record a slug in the tombstone registry (bounded, autoload=false).
	 *
	 * @param string $slug      Artwork slug (clean, without `__trashed`).
	 * @param string $object_id The object id the artwork federated under.
	 * @param string $deleted   ISO 8601 deletion timestamp.
	 */
	private function record_tombstone( string $slug, string $object_id, string $deleted ): void {
		$tombstones = get_option( 'agnosis_ap_tombstones', [] );

		$tombstones[ $slug ] = [
			'id'      => $object_id,
			'deleted' => $deleted,
		];

		if ( count( $tombstones ) > self::TOMBSTONE_CAP ) {
			uasort( $tombstones, static fn( array $a, array $b ) => strcmp( $a['deleted'], $b['deleted'] ) );
			$tombstones = array_slice( $tombstones, -self::TOMBSTONE_CAP, null, true );
		}

		update_option( 'agnosis_ap_tombstones', $tombstones, false );
	}

	/** Remove a slug from the tombstone registry (idempotent). */
	private function clear_tombstone( string $slug ): void {
		$tombstones = get_option( 'agnosis_ap_tombstones', [] );

		if ( isset( $tombstones[ $slug ] ) ) {
			unset( $tombstones[ $slug ] );
			update_option( 'agnosis_ap_tombstones', $tombstones, false );
		}
	}

	/**
	 * Build the artwork's Note object.
	 *
	 * The Note's `id` is minted from get_permalink() so that id === url in
	 * every permalink mode (audit §3c): the old hardcoded `/art/<slug>` id
	 * 404'd outright on plain-permalink sites (where the real URL is
	 * `?agnosis_artwork=<slug>`), and even on pretty-permalink sites the two
	 * fields could only agree by construction, not by guarantee. The AP spec
	 * expects an object's id to dereference to the object — served by
	 * serve_artwork_activity_json() via content negotiation on the same URL.
	 *
	 * Audit §3f enrichment pass: the featured image now carries real alt
	 * text and its actual MIME type instead of a hardcoded one; `content` is
	 * the artist's full AI-written description instead of a flat 50-word
	 * truncation; post_tag/agnosis_medium terms become both a `tag` array
	 * and matching `#Name` strings appended to `content` (Mastodon indexes
	 * hashtags from the content text itself, not the `tag` array); and
	 * `sensitive`/`summary` are set when either the artist or the operator
	 * has flagged the piece — see is_post_sensitive().
	 *
	 * @return array<string, mixed>
	 */
	private function post_to_note( \WP_Post $post ): array {
		// Audit §3h: attributed to the artist's own actor when the post has a
		// real artist author, falling back to the node otherwise (see
		// owner_for_post()'s docblock for when that fallback applies).
		$owner        = $this->owner_for_post( $post );
		$actor        = $this->actor_url_for( $owner['type'], $owner['id'] );
		$object_id    = get_permalink( $post->ID );
		// get_post_thumbnail_id() is typed int|false; normalize to int (0 =
		// none) so it satisfies get_post_mime_type()/get_post_meta()'s int
		// parameter below without a separate is-int guard at each call site.
		$thumbnail_id = (int) get_post_thumbnail_id( $post->ID );
		$image_url    = $thumbnail_id > 0 ? ( get_the_post_thumbnail_url( $post->ID, 'agnosis-artwork' ) ?: '' ) : '';

		[ $hashtags, $hashtag_text ] = $this->build_hashtags( $post->ID );

		$content = $this->build_note_content( $post );
		if ( '' !== $hashtag_text ) {
			$content .= '<p>' . $hashtag_text . '</p>';
		}

		$note = [
			'@context'     => self::CONTEXT,
			'type'         => 'Note',
			'id'           => $object_id,
			'url'          => $object_id,
			'attributedTo' => $actor,
			'name'         => wp_strip_all_tags( $post->post_title ),
			'content'      => $content,
			'published'    => gmdate( 'c', (int) strtotime( $post->post_date_gmt ) ),
			'to'           => [ 'https://www.w3.org/ns/activitystreams#Public' ],
		];

		if ( [] !== $hashtags ) {
			$note['tag'] = $hashtags;
		}

		if ( $this->is_post_sensitive( $post->ID ) ) {
			$note['sensitive'] = true;
			$note['summary']   = __( 'Sensitive content', 'agnosis' );
		}

		if ( $image_url ) {
			$attachment = [
				'type'      => 'Image',
				'url'       => $image_url,
				'mediaType' => get_post_mime_type( $thumbnail_id ) ?: 'image/jpeg',
			];

			$alt_text = trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) );
			if ( '' !== $alt_text ) {
				$attachment['name'] = $alt_text;
			}

			$note['attachment'] = [ $attachment ];
		}

		return $note;
	}

	/**
	 * Extract just the freeform text portions of the post's content — the
	 * AI-written description, which build_post_content() (Publishing\PostCreator)
	 * inserts as raw HTML paragraphs, not wrapped in a Gutenberg block, next
	 * to real wp:gallery/wp:image/wp:video/wp:audio/wp:embed blocks for any
	 * attached media (the image is already covered separately via
	 * `attachment`, and video/audio/embeds aren't meaningful in a Note, so
	 * both are deliberately excluded here). Falls back to the previous
	 * 50-word truncated summary only if no freeform text is found at all —
	 * defensive; every current artwork post has some (audit §3f: artists'
	 * carefully AI-written descriptions were previously arriving amputated
	 * mid-sentence at a flat 50-word cap, when AP `content` is HTML and can
	 * carry the whole thing).
	 */
	private function build_note_content( \WP_Post $post ): string {
		$html = '';

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( null === $block['blockName'] ) {
				$html .= $block['innerHTML'];
			}
		}

		$html = trim( $html );

		return '' !== $html ? $html : wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 );
	}

	/**
	 * Build the Note's `tag` array (AS2 Hashtag objects) from the artwork's
	 * post_tag + agnosis_medium terms, plus the matching space-joined
	 * `#Name` text to append to `content` — audit §3f. Term names become
	 * CamelCase hashtags (each word capitalized, no separators): the
	 * community-recommended form, since screen readers announce capitalized
	 * words separately instead of running one long lowercase string
	 * together.
	 *
	 * @return array{0: array<int, array<string, string>>, 1: string}
	 */
	private function build_hashtags( int $post_id ): array {
		// wp_get_post_tags()/wp_get_post_terms() are typed to allow WP_Error
		// (an invalid taxonomy or post id) even though neither can realistically
		// happen here — post_tag and agnosis_medium always exist, $post_id is
		// always a real post — but the return type must still be narrowed
		// before array_merge()/foreach will accept it.
		$terms = wp_get_post_tags( $post_id );
		if ( is_wp_error( $terms ) ) {
			$terms = [];
		}

		if ( taxonomy_exists( 'agnosis_medium' ) ) {
			$medium_terms = wp_get_post_terms( $post_id, 'agnosis_medium' );
			if ( ! is_wp_error( $medium_terms ) ) {
				$terms = array_merge( $terms, $medium_terms );
			}
		}

		$tags = [];
		$seen = [];

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$name = $this->hashtag_name( $term->name );
			if ( '' === $name || isset( $seen[ strtolower( $name ) ] ) ) {
				continue;
			}
			$seen[ strtolower( $name ) ] = true;

			$link   = get_term_link( $term );
			$tags[] = [
				'type' => 'Hashtag',
				'name' => '#' . $name,
				'href' => is_wp_error( $link ) ? home_url( '/' ) : $link,
			];
		}

		return [ $tags, implode( ' ', array_column( $tags, 'name' ) ) ];
	}

	/**
	 * Convert a taxonomy term name into a bare CamelCase hashtag word: every
	 * run of letters/digits capitalized and concatenated, everything else
	 * (spaces, punctuation) stripped — a hashtag can't contain whitespace.
	 */
	private function hashtag_name( string $term_name ): string {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $term_name, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $words ) {
			return '';
		}

		return implode( '', array_map( static fn( string $word ): string => ucfirst( mb_strtolower( $word ) ), $words ) );
	}

	/**
	 * Whether the artwork should federate with AS2 `sensitive: true` + a
	 * content-warning `summary` (audit §3f — filed by the audit as a product
	 * call, not a defect, since nothing in Agnosis previously had any concept
	 * of "sensitive" at all). Two independent levers, either is enough:
	 *
	 *   - Artist\ContentEditor::save_sensitive() — an artist flags a specific
	 *     piece via `_agnosis_sensitive` post meta.
	 *   - Artist\Profile's agnosis_medium term-meta checkbox — an operator
	 *     flags a whole medium (e.g. one used for explicit work) via
	 *     `_agnosis_medium_sensitive`, so every artwork under it federates
	 *     with a warning without the artist needing to flag each piece.
	 */
	private function is_post_sensitive( int $post_id ): bool {
		if ( get_post_meta( $post_id, '_agnosis_sensitive', true ) ) {
			return true;
		}

		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			return false;
		}

		$medium_terms = wp_get_post_terms( $post_id, 'agnosis_medium' );
		if ( is_wp_error( $medium_terms ) ) {
			return false;
		}

		foreach ( $medium_terms as $term ) {
			if ( $term instanceof \WP_Term && get_term_meta( $term->term_id, '_agnosis_medium_sensitive', true ) ) {
				return true;
			}
		}

		return false;
	}

	/** @return array<string, mixed> */
	private function post_to_activity( \WP_Post $post ): array {
		$note = $this->post_to_note( $post );

		return [
			'@context'  => self::CONTEXT,
			'type'      => 'Create',
			'id'        => $note['id'] . '#create',
			'actor'     => $note['attributedTo'],
			'published' => $note['published'],
			'to'        => $note['to'],
			'object'    => $note,
		];
	}

	/**
	 * Resolve which local actor (the node, or a specific artist) an inbound
	 * Follow/Undo's `object` field names (audit §3h). Deliberately reads the
	 * ACTIVITY's own claimed target rather than trusting the URL the request
	 * happened to arrive at — spec-correct (an actor's `object` is the
	 * authoritative target, which is exactly what makes sharedInbox work at
	 * all: multiple local actors can share one URL and still be addressed
	 * individually), and means the dedicated per-artist inbox route and the
	 * shared node/global inbox route can run identical logic.
	 *
	 * @return array{type: string, id: int}|null Null when $object_url matches no known local actor.
	 */
	private function resolve_local_owner( string $object_url ): ?array {
		if ( '' === $object_url ) {
			return null;
		}

		$object_url = untrailingslashit( $object_url );

		if ( untrailingslashit( rest_url( 'agnosis/v1/activitypub/actor' ) ) === $object_url ) {
			return [ 'type' => 'node', 'id' => 0 ];
		}

		$artist_prefix = untrailingslashit( rest_url( 'agnosis/v1/activitypub/actor' ) ) . '/';
		if ( str_starts_with( $object_url, $artist_prefix ) ) {
			$id = (int) substr( $object_url, strlen( $artist_prefix ) );
			if ( $id > 0 ) {
				return [ 'type' => 'artist', 'id' => $id ];
			}
		}

		return null;
	}

	/** @param array<string, mixed> $body */
	private function handle_follow( array $body ): WP_REST_Response {
		global $wpdb;

		$follower_id    = $body['actor'] ?? '';
		$target         = is_string( $body['object'] ?? null ) ? $body['object'] : '';
		// A Follow that doesn't name a recognizable local actor still
		// defaults to the node — matches the pre-§3h behavior for any
		// implementation that omits `object` on a Follow (technically
		// required by AS2, but some senders are loose about it), rather than
		// silently dropping the follow.
		$owner          = $this->resolve_local_owner( $target ) ?? [ 'type' => 'node', 'id' => 0 ];
		$follower_inbox = $this->resolve_inbox( $follower_id );

		if ( $follower_inbox ) {
			// Upsert by (owner_type, owner_id, actor_id) — audit §3g note iii's
			// array-key-upsert-into-an-option is now a table UNIQUE KEY; audit
			// §3h added the owner columns so the same remote actor can follow
			// both the node and one or more individual artists independently.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->replace() parameterizes every value; small, node-scale table.
			$wpdb->replace(
				$wpdb->prefix . 'agnosis_followers',
				[ 'owner_type' => $owner['type'], 'owner_id' => $owner['id'], 'actor_id' => $follower_id, 'inbox_url' => $follower_inbox ],
				[ '%s', '%d', '%s', '%s' ]
			);

			$actor_url = $this->actor_url_for( $owner['type'], $owner['id'] );

			// Send Accept — signed by (and attributed to) whichever local
			// actor was actually followed, not always the node.
			$this->deliver( $follower_inbox, [
				'@context' => self::CONTEXT,
				'type'     => 'Accept',
				'id'       => $actor_url . '#accept-' . uniqid(),
				'actor'    => $actor_url,
				'object'   => $body,
			], $owner['type'], $owner['id'] );
		}

		return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
	}

	/** @param array<string, mixed> $body */
	private function handle_undo( array $body ): WP_REST_Response {
		$object = $body['object'] ?? [];
		if ( ( $object['type'] ?? '' ) === 'Follow' ) {
			global $wpdb;

			$follower_id = $body['actor'] ?? '';
			$target      = is_string( $object['object'] ?? null ) ? $object['object'] : '';
			$owner       = $this->resolve_local_owner( $target ) ?? [ 'type' => 'node', 'id' => 0 ];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes every value; small, node-scale table.
			$wpdb->delete(
				$wpdb->prefix . 'agnosis_followers',
				[ 'owner_type' => $owner['type'], 'owner_id' => $owner['id'], 'actor_id' => $follower_id ],
				[ '%s', '%d', '%s' ]
			);
		}
		return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
	}

	/**
	 * The signing key and keyId for one local actor (audit §3h). Every
	 * delivery must be signed by the private key of the actor it's
	 * ATTRIBUTED to — an artist's Create should carry that artist's own
	 * signature and keyId, not the node's impersonating them, both because
	 * it's spec-correct and because §3b's own verify_actor_binding() now
	 * enforces exactly this symmetry on OUR side against inbound activities;
	 * a stricter federation partner may check the same thing on activities
	 * we send.
	 *
	 * @return array{0: string, 1: string} [private key PEM, keyId URL].
	 */
	private function signing_key_for( string $owner_type, int $owner_id ): array {
		$actor_url = $this->actor_url_for( $owner_type, $owner_id );

		if ( 'artist' === $owner_type && $owner_id > 0 ) {
			$keys = $this->ensure_artist_key_pair( $owner_id );
			return [ $keys['private'], $actor_url . '#main-key' ];
		}

		return [ (string) get_option( 'agnosis_private_key', '' ), $actor_url . '#main-key' ];
	}

	/** @param array<string, mixed> $activity */
	private function deliver( string $inbox_url, array $activity, string $owner_type = 'node', int $owner_id = 0 ): void {
		$body = wp_json_encode( $activity );
		if ( false === $body ) {
			return;
		}

		$activity_type = is_string( $activity['type'] ?? null ) ? $activity['type'] : 'activity';
		$result        = $this->attempt_send( $inbox_url, $body, $owner_type, $owner_id );

		if ( true === $result ) {
			return;
		}

		// Deliveries were fire-and-forget ('blocking' => false) until §3a —
		// which is exactly how a 100%-rejection bug stayed invisible for so
		// long. Block and log anything that isn't a 2xx so delivery failures
		// surface in Settings → Logs.
		Logger::warning(
			sprintf( 'ActivityPub delivery (%s) to %s failed: %s', $activity_type, $inbox_url, $result ),
			'activitypub'
		);

		// A cron-driven retry queue picks this delivery back up instead of it
		// being lost after this one fire-and-forget attempt (audit §3g note
		// iv) — previously this log line was the only trace a failed
		// delivery ever left.
		$this->enqueue_delivery_retry( $inbox_url, $activity_type, $body, $owner_type, $owner_id );
	}

	/**
	 * Sign and POST one already-encoded activity body to one inbox.
	 *
	 * Pure transport: returns success/failure but never logs or enqueues a
	 * retry itself — deliver() (a live, first attempt) and
	 * process_delivery_retry_queue() (a queued retry) each need to react to a
	 * failure differently, so that decision stays with the caller.
	 *
	 * @return bool|string True on a 2xx response; an error-message string otherwise.
	 */
	private function attempt_send( string $inbox_url, string $body, string $owner_type = 'node', int $owner_id = 0 ): bool|string {
		[ $private_key, $key_id ] = $this->signing_key_for( $owner_type, $owner_id );

		$date   = gmdate( 'D, d M Y H:i:s \G\M\T' );
		$digest = 'SHA-256=' . base64_encode( hash( 'sha256', $body, true ) );

		$signature = '';
		if ( $private_key && function_exists( 'openssl_sign' ) ) {
			// Mastodon requires the Digest header to exist AND be covered by
			// the signature on every inbox POST; Pixelfed and most other major
			// implementations inherit the same rule. A signature over only
			// "(request-target) host date" is rejected outright, which made
			// every outbound Accept/Create bounce with a 401 (audit §3a).
			$signing_string = '(request-target): post ' . wp_parse_url( $inbox_url, PHP_URL_PATH )
				. "\nhost: " . wp_parse_url( $inbox_url, PHP_URL_HOST )
				. "\ndate: " . $date
				. "\ndigest: " . $digest;
			openssl_sign( $signing_string, $raw_sig, $private_key, OPENSSL_ALGO_SHA256 );
			$signature = 'keyId="' . $key_id . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . base64_encode( $raw_sig ) . '"';
		}

		// $inbox_url is peer-supplied (the follower's own actor document, or a
		// stored follower inbox), so use the "safe" variant: it rejects
		// private/loopback/link-local/ULA targets, re-checked on every
		// redirect hop (audit §3b).
		$response = wp_safe_remote_post( $inbox_url, [
			'timeout'    => 15,
			'headers'    => array_filter( [
				'Content-Type' => 'application/activity+json',
				'Accept'       => 'application/activity+json',
				'Date'         => $date,
				'Digest'       => $digest,
				'Signature'    => $signature ?: null,
			] ),
			'body'       => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return sprintf( 'HTTP %d: %s', $code, wp_remote_retrieve_body( $response ) );
		}

		return true;
	}

	/**
	 * Insert a delivery retry queue row after a live delivery's first failure.
	 */
	private function enqueue_delivery_retry( string $inbox_url, string $activity_type, string $activity_json, string $owner_type, int $owner_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() parameterizes every value.
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_ap_delivery_queue',
			[
				'inbox_url'       => $inbox_url,
				'activity_type'   => $activity_type,
				'activity_json'   => $activity_json,
				'owner_type'      => $owner_type,
				'owner_id'        => $owner_id,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::RETRY_INTERVALS[0] ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s' ]
		);
	}

	/**
	 * agnosis_ap_retry_deliveries cron callback: work one batch of due
	 * delivery-retry rows (audit §3g note iv).
	 *
	 * A succeeding row is deleted outright — there's nothing further to do
	 * with it. A failing row advances to the next backoff interval in
	 * RETRY_INTERVALS, or — once every interval is exhausted — is left in
	 * place with status='failed' as the terminal record of a delivery that
	 * was never accepted.
	 *
	 * Claim-then-read (security audit §2c): this previously SELECTed due
	 * 'pending' rows and only updated them after attempting delivery — two
	 * overlapping cron ticks could both select the same row and both POST
	 * the same activity to the same inbox, a duplicate delivery. This method
	 * now atomically claims a batch first — a single `UPDATE … WHERE status
	 * = 'pending' AND next_attempt_at <= … ORDER BY id ASC LIMIT %d` tagging
	 * the claimed rows with a per-run `claim_token` — and only reads back
	 * rows carrying that exact token, the same pattern (and the same
	 * InnoDB-row-locking guarantee) as Newsletter\QueueProcessor::process();
	 * see that method's own docblock for the full reasoning. A PHP process
	 * that dies mid-batch after claiming but before finishing would
	 * otherwise strand those rows in 'claimed' forever — reset_stale_claims(),
	 * run at the top of every call, self-heals that automatically.
	 */
	public function process_delivery_retry_queue(): void {
		global $wpdb;

		$this->reset_stale_delivery_claims();

		$claim_token = wp_generate_uuid4();
		$now         = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- RETRY_BATCH_SIZE is a class constant, not user input; $now/$claim_token are bound parameters.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agnosis_ap_delivery_queue
				 SET status = 'claimed', claim_token = %s, claimed_at = %s
				 WHERE status = 'pending' AND next_attempt_at <= %s
				 ORDER BY id ASC
				 LIMIT %d",
				$claim_token,
				$now,
				$now,
				self::RETRY_BATCH_SIZE
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_ap_delivery_queue WHERE claim_token = %s ORDER BY id ASC",
				$claim_token
			)
		);

		foreach ( $rows as $row ) {
			$activity = json_decode( (string) $row->activity_json, true );
			$result   = $this->attempt_send( (string) $row->inbox_url, (string) $row->activity_json, (string) $row->owner_type, (int) $row->owner_id );

			if ( true === $result ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes the id.
				$wpdb->delete( $wpdb->prefix . 'agnosis_ap_delivery_queue', [ 'id' => $row->id ], [ '%d' ] );
				continue;
			}

			$attempts  = (int) $row->attempts + 1;
			$exhausted = $attempts >= count( self::RETRY_INTERVALS );

			$data   = [ 'attempts' => $attempts, 'last_error' => $result, 'claim_token' => null, 'claimed_at' => null ];
			$format = [ '%d', '%s', '%s', '%s' ];

			if ( $exhausted ) {
				$data['status']      = 'failed';
				$data['resolved_at'] = current_time( 'mysql', true );
				$format[]            = '%s';
				$format[]            = '%s';

				Logger::warning(
					sprintf(
						'ActivityPub delivery (%s) to %s permanently failed after %d attempts: %s',
						is_array( $activity ) && is_string( $activity['type'] ?? null ) ? $activity['type'] : (string) $row->activity_type,
						$row->inbox_url,
						$attempts + 1, // +1 for the original live attempt that first enqueued this row.
						$result
					),
					'activitypub'
				);
			} else {
				// Still has retries left — return to 'pending' for its next
				// scheduled attempt (the claim above moved it to 'claimed',
				// so this must be explicit; the pre-claim code never needed
				// to touch status here since the row had never left 'pending').
				$data['status']          = 'pending';
				$data['next_attempt_at'] = gmdate( 'Y-m-d H:i:s', time() + self::RETRY_INTERVALS[ $attempts ] );
				$format[]                = '%s';
				$format[]                = '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() parameterizes every value.
			$wpdb->update( $wpdb->prefix . 'agnosis_ap_delivery_queue', $data, [ 'id' => $row->id ], $format, [ '%d' ] );
		}
	}

	/**
	 * Reset any delivery-retry row stuck in 'claimed' longer than
	 * STALE_CLAIM_MINUTES back to 'pending' (security audit §2c) — same
	 * reasoning as Newsletter\QueueProcessor::reset_stale_claims(): a PHP
	 * process that claimed a batch and then died mid-run before finishing
	 * would otherwise leave those rows permanently unreachable, since the
	 * claim UPDATE only ever targets status = 'pending'.
	 */
	private function reset_stale_delivery_claims(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_CLAIM_MINUTES * MINUTE_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agnosis_ap_delivery_queue
				 SET status = 'pending', claim_token = NULL, claimed_at = NULL
				 WHERE status = 'claimed' AND claimed_at < %s",
				$cutoff
			)
		);
	}

	private function resolve_inbox( string $actor_url ): ?string {
		if ( empty( $actor_url ) ) {
			return null;
		}
		// $actor_url is peer-supplied (from an inbound Follow activity's
		// "actor" field), so use the "safe" variant (audit §3b).
		$response = wp_safe_remote_get( $actor_url, [
			'headers' => [ 'Accept' => 'application/activity+json' ],
			'timeout' => 10,
		] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $data['inbox'] ) ? esc_url_raw( $data['inbox'] ) : null;
	}
}
