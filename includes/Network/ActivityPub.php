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
 * refreshed Note. Language siblings are dereferenceable and lifecycle-correct
 * (TAG-REDESIGN.md F2): a sibling's own permalink content-negotiates its own
 * Note (contentMap-scoped, F1) and its own Delete/Update pushes and tombstone
 * just like the primary post's; only the initial Create stays primary-only —
 * broadcast() only ever runs for a post id `Network\FederationSettlement`
 * has itself decided is ready (see F3 below), and that class only ever
 * considers the primary post plus its OWN already-existing/later-arriving
 * siblings, never pushing a sibling's Create independently of its primary
 * having settled first — so a sibling is never actively pushed into being on
 * its own, but once it exists (and the group has settled) it is
 * dereferenceable and its own edits/removal federate. The outbox (no
 * language filter) already lists every published post, siblings included.
 *
 * `broadcast()` (the Create) does NOT fire directly on `agnosis_post_published`
 * (TAG-REDESIGN.md F3, §6c) — it fires on `agnosis_federation_settled`
 * instead, raised by `Network\FederationSettlement` only once a post's tags
 * AND medium category have zero pending admin proposals (immediately at
 * approval for the common case — every candidate already matched the
 * vocabulary — or later, once the last pending proposal resolves, or after
 * a bounded timeout either way): a Note that arrives with no hashtags and
 * only gains them via a later Update mostly misses hashtag-timeline
 * discovery, which only happens at delivery time. See FederationSettlement's
 * own class docblock for the full trigger design.
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

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Artist\NotificationPreferences;
use Agnosis\Compat\LinguaForge;
use Agnosis\Core\Logger;
use Agnosis\Core\RateLimiter;
use Agnosis\Publishing\ReviewConfirm;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use WP_Error;

class ActivityPub {

	private const CONTEXT = 'https://www.w3.org/ns/activitystreams';

	/**
	 * Interaction-surface roadmap, Phase 2 (2026-07-25) — federated replies.
	 *
	 * A `Create{Note, inReplyTo: <artwork-url>}` from a remote fediverse
	 * account becomes an ordinary WP comment, tagged with this comment_type
	 * so it can be styled/queried distinctly from any future native on-site
	 * commenting. Held for moderation (`comment_approved = 0`) unconditionally
	 * — Ulises: "we should never allow auto-publish... artists in charge of
	 * what's published in the context of their work" — there is no settings
	 * toggle to relax this, unlike the roadmap doc's own tentative "a settings
	 * toggle can relax this later" suggestion, which his answer supersedes.
	 * `handle_reply_moderation()` below is how an artist actually approves or
	 * rejects one, since the `agnosis_artist` role carries no WP
	 * `moderate_comments` capability (confirmed against Admission.php — the
	 * role is a bare marker, no capabilities attached) and was never meant to;
	 * granting it broadly would let an artist moderate every comment
	 * site-wide, not just their own.
	 */
	// wp_comments.comment_type is varchar(20) in WP core's own schema
	// (wp-admin/includes/schema.php) — 'agnosis_federated_reply' (23 chars)
	// silently overflowed it under the test DB's strict SQL mode, making
	// every single wp_insert_comment() call here fail at the $wpdb->insert()
	// level and return false, which is what actually caused every Phase 2
	// reply test to see 'ignored' instead of 'accepted' (root-caused via a
	// temporary error_log()/STDERR diagnostic in handle_create_reply(),
	// 2026-07-25 — see that method's git history for the diagnostic itself).
	public const REPLY_COMMENT_TYPE = 'agnosis_ap_reply';

	/** Comment meta: the Note's own AS2 id — idempotent redelivery + the anchor Delete{object} matches against. */
	private const REPLY_ACTIVITY_ID_META = '_agnosis_reply_activity_id';

	/** Comment meta: the replying actor's URL — ownership check before honoring a Delete-of-reply. */
	private const REPLY_ACTOR_META = '_agnosis_reply_actor';

	/** Comment meta: queue flag drained by drain_reply_translation_queue(); cleared once translated. */
	private const REPLY_PENDING_TRANSLATION_META = '_agnosis_reply_pending_translation';

	/**
	 * Comment meta: the artist's-language translation, once resolved.
	 * comment_content itself always stays the untouched original — "never
	 * discard the source" (roadmap §4 Phase 2 step 8) — so a caller (the
	 * replies REST endpoint) reads this meta first and falls back to
	 * comment_content only while translation is still pending.
	 */
	private const REPLY_TRANSLATED_CONTENT_META = '_agnosis_reply_translated_content';

	/**
	 * Comment meta: when the reply's moderation link (notify_artist_of_reply())
	 * expires — WP0, agnosis-audit/INTERACTION-SURFACE-ROADMAP.md §8. Written
	 * once at email-send time; see that method's docblock for why it's stored
	 * rather than recomputed from the option at verify time. Absent (falsy)
	 * on any comment that got its notification email before this fix shipped —
	 * treated as "never expires" by verify_reply_moderation_token(), same
	 * backward-compat convention ReviewEndpoints::verify_token() already uses
	 * for `_agnosis_review_expiry`.
	 */
	private const REPLY_MODERATION_EXPIRY_META_KEY = '_agnosis_reply_moderation_expiry';

	/** Post meta: per-artwork override turning replies off for this one piece (Artist\ContentEditor). */
	public const REPLIES_DISABLED_META = '_agnosis_replies_disabled';

	/** Wall-clock budget for one drain_reply_translation_queue() cron tick — same reasoning/value as Compat\LinguaForge's own term-translation queue budget. */
	private const REPLY_TRANSLATION_TIME_BUDGET_SECONDS = 15;

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

	/**
	 * On-site like toggle rate limit (interaction-surface roadmap, Phase 3,
	 * WP2, §5) — pure abuse prevention, not a product feature (Ulises: no
	 * artist-level gate and no rate limit "as a feature" on likes at all).
	 * Generous relative to admission_apply's 5/60 since a visitor
	 * legitimately liking/unliking a few artworks in a minute while browsing
	 * is completely ordinary, unlike submitting an admission application.
	 */
	private const LIKE_RATE_LIMIT   = 20;
	private const LIKE_RATE_WINDOW  = 60;

	public function register_routes(): void {
		$args = [ 'permission_callback' => '__return_true' ];

		register_rest_route( 'agnosis/v1', '/activitypub/actor',                            array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'actor'     ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/actor/(?P<artist_id>\d+)',          array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'actor'     ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/outbox',                            array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'outbox'    ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/actor/(?P<artist_id>\d+)/outbox',   array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'outbox'    ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/followers',                         array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'followers' ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/actor/(?P<artist_id>\d+)/followers', array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'followers' ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/nodeinfo',                          array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'nodeinfo'  ] ] ) );

		// Interaction-surface roadmap, Phase 2 — public, unauthenticated read
		// of one artwork's APPROVED federated replies, for the front-end
		// agnosis/reply-overlay block's own fetch (get_replies()). Lives here
		// (not Artist\ContentEditor) since replies are entirely this class's
		// domain — ingestion, moderation, and now reading them back.
		register_rest_route( 'agnosis/v1', '/content/(?P<id>\d+)/replies', array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'get_replies' ] ] ) );

		// Interaction-surface roadmap, Phase 3, WP2 — public, unauthenticated
		// on-site like toggle. permission_callback is the per-IP rate limiter,
		// not '__return_true' like the read-only routes above: this is the
		// only route here a visitor can actually WRITE through with no
		// identity check beyond that. Two routes (not one dispatching on
		// $request->get_method()) to match every other method-specific route
		// pair already in this file (e.g. the inbox routes below).
		register_rest_route( 'agnosis/v1', '/content/(?P<id>\d+)/likes', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'like_content' ],
			'permission_callback' => [ $this, 'rate_limit_like' ],
		] );
		register_rest_route( 'agnosis/v1', '/content/(?P<id>\d+)/likes', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'unlike_content' ],
			'permission_callback' => [ $this, 'rate_limit_like' ],
		] );

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
			// FEP-5feb (Interaction-surface roadmap, Phase 3, WP1): a compliant
			// search-indexing/FASP consumer must ignore an actor with neither
			// flag set, so these must be emitted, not merely implied by their
			// absence. The node itself isn't an artist — there is no per-artist
			// consent question here, just the operator's own node choosing to
			// run a public, federated, indexable presence — so this is always
			// true, unlike artist_actor()'s per-artist opt-out below.
			'discoverable'      => true,
			'indexable'         => true,
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

		// FEP-5feb (Interaction-surface roadmap, Phase 3, WP1) — per-artist,
		// account-wide consent (§7 Q7: this is an actor-level property, not a
		// per-artwork one — it governs everything this artist has ever
		// published, not just one piece). Default discoverable/indexable
		// (Ulises: "Default ON"); NotificationPreferences::is_discovery_opted_out()
		// is the single canonical read, shared with the mirrored checkbox on
		// the artwork/biography approval pages (Publishing\ReviewConfirm).
		$discoverable = ! NotificationPreferences::is_discovery_opted_out( $user_id );

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
			'discoverable'      => $discoverable,
			'indexable'         => $discoverable,
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
	 * inbox() callback has a chance to mutate any state. Returns WP_Error on
	 * failure so WordPress sends the appropriate 4xx without running inbox() —
	 * except for one narrow, deliberate exception: a signature failure caused
	 * specifically by the signing actor's key document now returning 410
	 * Gone, on a request that is itself a self-Delete for that exact actor,
	 * is corroborated instead of rejected (audit §4a — see
	 * corroborated_self_delete()'s own docblock for why this is evidence,
	 * not a bypass).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function verify_inbox_signature( WP_REST_Request $request ): bool|WP_Error {
		$verified = HttpSignature::verify( $request );
		if ( is_wp_error( $verified ) ) {
			$corroborated = $this->corroborated_self_delete( $request, $verified );
			return is_wp_error( $corroborated ) ? $verified : true;
		}

		// Audit §3b: verify() proves the request was signed by the key at
		// keyId — this additionally binds that identity to the actor the
		// activity claims to be from, so a valid key on some other server
		// can't forge a Follow/Undo in another actor's name.
		return HttpSignature::verify_actor_binding( $request );
	}

	/**
	 * Audit §4a's optional "key-410 corroboration": when signature
	 * verification failed specifically because the signing actor's public
	 * key could no longer be fetched — the remote actor document itself
	 * returned HTTP 410 Gone, the server's own explicit "this is
	 * permanently gone," not a timeout or a transient error — and the
	 * activity being delivered is itself a genuine self-Delete (`object`
	 * resolves to the same actor as `actor`) for that SAME actor (bound via
	 * the Signature header's keyId, exactly as verify_actor_binding() binds
	 * it after a successful verify()), treat that as sufficient evidence to
	 * accept the deletion without a cryptographic signature check.
	 *
	 * A cryptographic check is structurally impossible to ever obtain here:
	 * once an actor is truly gone, its public key can never be fetched
	 * again, by anyone, forever — refusing to ever act on the Delete in
	 * that case (the previous behavior) meant the follower row could only
	 * ever be cleaned up as a side effect of the next broadcast's dead-inbox
	 * fast path, and even then only the delivery attempts stopped, not the
	 * `agnosis_followers` row itself.
	 *
	 * This is corroboration, not a bypass, and it's why the actor-binding
	 * check still runs (via signing_key_owner(), independent of the failed
	 * signature): an attacker cannot forge a 410 response from a peer
	 * server for a URL they don't control, so this path only ever accepts a
	 * Delete for an actor whose own real endpoint has genuinely stopped
	 * existing — an attacker's request body alone, no matter what it
	 * claims, can never produce that 410 for someone else's real actor URL.
	 *
	 * @param WP_REST_Request $request        Incoming request.
	 * @param WP_Error         $original_error verify()'s own failure.
	 * @return true|WP_Error True when corroborated; the original error otherwise.
	 */
	private function corroborated_self_delete( WP_REST_Request $request, WP_Error $original_error ): bool|WP_Error {
		if ( 'ap_key_fetch_failed' !== $original_error->get_error_code() ) {
			return $original_error;
		}

		$error_data    = $original_error->get_error_data();
		$remote_status = is_array( $error_data ) ? (int) ( $error_data['remote_status'] ?? 0 ) : 0;
		if ( 410 !== $remote_status ) {
			return $original_error;
		}

		// get_json_params() only parses when Content-Type is a JSON media
		// type; fall back to the raw body so a peer sending a bare or unusual
		// Content-Type on its Delete isn't denied corroboration for a reason
		// unrelated to the actual claim — the same fallback
		// HttpSignature::verify_actor_binding() already relies on.
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || [] === $body ) {
			$body = json_decode( $request->get_body(), true );
		}

		if ( ! is_array( $body ) || 'Delete' !== ( $body['type'] ?? '' ) ) {
			return $original_error;
		}

		$claimed_actor = self::self_delete_actor( $body );
		if ( '' === $claimed_actor || HttpSignature::signing_key_owner( $request ) !== $claimed_actor ) {
			return $original_error;
		}

		return true;
	}

	public function inbox( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		$type = $body['type'] ?? '';

		switch ( $type ) {
			case 'Follow':
				return $this->handle_follow( $body );
			case 'Like':
			case 'Announce':
				// Interaction-surface roadmap, Phase 1 (2026-07-24): this used
				// to only fire an unlistened do_action() and discard the
				// activity — see record_interaction()'s own docblock for what
				// actually happens now. do_action() is kept alongside the new
				// persistence so any future listener (e.g. a notification)
				// still has a hook to attach to.
				$this->record_interaction( $body, strtolower( $type ) );
				do_action( 'agnosis_activity_received', $body );
				return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
			case 'Undo':
				return $this->handle_undo( $body );
			case 'Create':
				// Interaction-surface roadmap, Phase 2 (2026-07-25): a remote
				// reply to a federated artwork. See handle_create_reply()'s
				// own docblock for the full gating order.
				return $this->handle_create_reply( $body );
			case 'Delete':
				return $this->handle_delete( $body );
			case 'Move':
				// Audit §2b, AUDIT-1.0.0.md — deliberately unhandled at this
				// scale; recording the decision here rather than leaving it
				// silently falling through to the generic 'ignored' default.
				// Move means "I migrated my account; re-follow me at X" —
				// Mastodon sends this instead of a Delete on a genuine
				// account migration. A follow relationship built on Move
				// simply lapses rather than transferring: the old actor's
				// eventual Delete (handled above) removes its
				// agnosis_followers row, and nothing re-follows the new
				// actor automatically. Acceptable at gallery scale; revisit
				// if follower counts or migration reports ever make a real
				// re-follow-on-Move worth building.
				return new WP_REST_Response( [ 'status' => 'ignored', 'type' => $type ], 200 );
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
		// Per AS2/ActivityPub, a followers collection's items are the
		// followers' actor IDs, not the delivery-plumbing inbox URLs — a
		// consumer that dereferences an item expects an actor document, and
		// an inbox URL only answers signed POSTs (audit §2a, AUDIT-1.0.0.md).
		// Delivery code (broadcast()/enqueue_delivery_retry() and friends)
		// keeps reading inbox_url internally; this is the one public-facing
		// read of this table that needed to change.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small, node-scale table (audit §3g note iii); parameterized via prepare().
		$actor_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT actor_id FROM {$wpdb->prefix}agnosis_followers WHERE owner_type = %s AND owner_id = %d ORDER BY id ASC",
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
			'totalItems' => count( $actor_ids ),
			'orderedItems' => $actor_ids,
		], 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	// -------------------------------------------------------------------------
	// Interaction counts — on-site display (interaction-surface roadmap,
	// Phase 1, 2026-07-24)
	// -------------------------------------------------------------------------

	/**
	 * Register the agnosis/interaction-counts dynamic block.
	 *
	 * block.json lives in blocks/interaction-counts/ relative to the plugin
	 * root — same directory-registration shape as agnosis/artwork-copyright
	 * (Artist\Profile::register_blocks()), chosen over a bare
	 * register_block_type() call so an admin gets real Color/Typography
	 * Inspector controls for what is otherwise a plain server-rendered string.
	 */
	public function register_interaction_counts_block(): void {
		register_block_type(
			\AGNOSIS_DIR . 'blocks/interaction-counts',
			[ 'render_callback' => [ $this, 'render_interaction_counts' ] ]
		);
	}

	/**
	 * Render callback for the agnosis/interaction-counts block.
	 *
	 * Ulises's design intent (agnosis-audit/INTERACTION-SURFACE-ROADMAP.md
	 * §3/§5): likes shown inline, small, never competing visually with the
	 * artwork; boosts counted AND displayed independently from likes, not
	 * combined into one number. Renders nothing — not an empty element — on a
	 * non-artwork post, but ALWAYS renders both counts on an artwork, even
	 * "♥ 0 like · ⟲ 0 boosts" — an earlier version of this method hid the
	 * whole line at zero-of-both to avoid a "permanent fixture", but that
	 * left the "Universe:" label above it (agnosis-theme's
	 * templates/single.html) dangling with nothing after it on every artwork
	 * that hadn't yet been liked/boosted, which reads as a broken layout
	 * rather than "nothing to show" (caught 2026-07-25 on a live front-end
	 * check). Always-show is the corrected, deliberate call.
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 * @return string HTML output or empty string when not applicable.
	 */
	public function render_interaction_counts( array $attrs, string $content, \WP_Block $block ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );

		if ( ! $post || 'agnosis_artwork' !== $post->post_type ) {
			return '';
		}

		$counts = $this->interaction_counts( $post_id );

		// Interaction-surface roadmap, Phase 3, WP2 (2026-07-27) — the like
		// half of this block is now a real toggle, not just a display. The
		// visitor's own liked/not-liked state is computed here, server-side,
		// at render time, using the exact same like_identity() the REST
		// toggle itself hashes against — so the button's initial state is
		// already correct on first paint, no separate fetch needed just to
		// learn it (frontend.js only has to call the REST endpoint on an
		// actual click). Boosts stay exactly as they were: plain text, no
		// interactivity — WP2 is on-site LIKES only (boosting is WP5, per the
		// roadmap's own dependency note), and the display deliberately
		// resists growing into a toolbar.
		$liked = $this->has_liked( $post_id, $this->like_identity() );

		wp_enqueue_style( 'agnosis-interaction-counts', \AGNOSIS_URL . 'blocks/interaction-counts/frontend.css', [], \AGNOSIS_VERSION );
		wp_enqueue_script( 'agnosis-interaction-counts', \AGNOSIS_URL . 'blocks/interaction-counts/frontend.js', [], \AGNOSIS_VERSION, [ 'in_footer' => true ] );
		wp_localize_script( 'agnosis-interaction-counts', 'agnosisInteractionCounts', [
			'apiUrlBase' => rest_url( 'agnosis/v1/content/' ),
			// A logged-in artist's own session has auth cookies, which makes
			// WordPress's cookie-auth REST layer require a valid nonce on any
			// write request regardless of this route's own permission_callback
			// (rest_cookie_check_errors() runs before route dispatch) — same
			// reason blocks/content-editor/frontend.js carries one. A fully
			// anonymous visitor has no auth cookie, so the check never
			// triggers and this nonce is simply unused for them.
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'i18n'       => [
				// translators: %d: number of likes.
				'like'  => __( '♥ %d like', 'agnosis' ),
				// translators: %d: number of likes.
				'likes' => __( '♥ %d likes', 'agnosis' ),
				'error' => __( 'Could not update like.', 'agnosis' ),
			],
		] );

		$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'agnosis-interaction-counts' ] );

		$parts   = [];
		$parts[] = sprintf(
			'<button type="button" class="agnosis-interaction-counts__likes" data-agnosis-like-post-id="%1$s" aria-pressed="%2$s"><span class="agnosis-interaction-counts__likes-text">%3$s</span></button>',
			esc_attr( (string) $post_id ),
			esc_attr( $liked ? 'true' : 'false' ),
			esc_html(
				sprintf(
					/* translators: %d: number of likes. */
					_n( '♥ %d like', '♥ %d likes', $counts['like'], 'agnosis' ),
					$counts['like']
				)
			)
		);
		$parts[] = sprintf(
			'<span class="agnosis-interaction-counts__boosts">%s</span>',
			esc_html(
				sprintf(
					/* translators: %d: number of boosts. */
					_n( '⟲ %d boost', '⟲ %d boosts', $counts['announce'], 'agnosis' ),
					$counts['announce']
				)
			)
		);

		return sprintf(
			'<p %s>%s</p>',
			$wrapper_attributes,
			implode( ' · ', $parts )
		);
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
	 *
	 * TAG-REDESIGN.md F2: no longer primary-only. A language sibling was
	 * never remotely Created (that stays primary-only per F2's "no pushes
	 * yet" scope), but its own permalink is dereferenceable (§3c content
	 * negotiation has no language guard), so it still needs its own
	 * tombstone recorded here — otherwise deleting a sibling would silently
	 * leave its now-stale Note dereferenceable at 200 instead of 410.
	 */
	private function federate_delete( \WP_Post $post ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
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
	 *
	 * TAG-REDESIGN.md F2: no longer primary-only. A sibling's Note is
	 * already dereferenceable (§3c has no language guard), so an editorial
	 * change to a sibling needs its own Update pushed too — only Create
	 * stays primary-only under F2 ("no pushes yet" for the initial publish).
	 */
	private function broadcast_update( \WP_Post $post ): void {
		static $sent = [];

		if ( isset( $sent[ $post->ID ] ) ) {
			return;
		}

		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
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
	 * The BCP-47-ish language code a Note's `contentMap` should be keyed
	 * with (TAG-REDESIGN.md F1). Same resolution chain as
	 * is_primary_language_post() above — `_lf_lang` meta when the post has
	 * one, otherwise the configured primary language, otherwise the site
	 * locale — but RETURNS the resolved code instead of comparing it,
	 * since only post_to_note() (every post it's ever called for is
	 * primary-language today — F2 is what extends federation to siblings)
	 * needs an actual language string rather than a yes/no.
	 *
	 * Deliberately a separate method rather than refactoring
	 * is_primary_language_post() to share it — F1 is scoped as "the
	 * smallest possible change to post_to_activity()"; that method is
	 * already tested and unrelated to this addition.
	 */
	private function resolve_note_language( int $post_id ): string {
		$lf_lang = sanitize_key( (string) get_post_meta( $post_id, '_lf_lang', true ) );
		if ( '' !== $lf_lang ) {
			return $lf_lang;
		}

		$primary = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		if ( '' !== $primary ) {
			return $primary;
		}

		return LinguaForge::locale_to_lang( get_locale() );
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

		$language_switch = $this->build_language_switch_line( $post->ID );
		if ( '' !== $language_switch ) {
			$content .= '<p>' . $language_switch . '</p>';
		}

		$note = [
			'@context'     => self::CONTEXT,
			'type'         => 'Note',
			'id'           => $object_id,
			'url'          => $object_id,
			'attributedTo' => $actor,
			'name'         => wp_strip_all_tags( $post->post_title ),
			'content'      => $content,
			// TAG-REDESIGN.md F1 — the language-unknown gap: this Note
			// otherwise carries no language hint at all, so a Mastodon
			// follower's per-followed-account language filter can't act on
			// it. `contentMap` is the mechanism Mastodon actually reads to
			// infer a Note's language. Always a single entry: each language
			// sibling is its own separate post with its own separate Note
			// (F2 makes a sibling's Note dereferenceable/lifecycle-correct
			// in its own right, via resolve_note_language()'s own _lf_lang
			// lookup for that post) — there is no single Note that
			// aggregates every language's content into one contentMap.
			'contentMap'   => [ $this->resolve_note_language( $post->ID ) => $content ],
			'published'    => gmdate( 'c', (int) strtotime( $post->post_date_gmt ) ),
			'to'           => [ 'https://www.w3.org/ns/activitystreams#Public' ],
		];

		if ( [] !== $hashtags ) {
			$note['tag'] = $hashtags;
		}

		// Interaction-surface roadmap, Phase 1 (2026-07-24) — cosmetic parity
		// item 5: a remote server that fetched this Note directly (rather
		// than relying on its own locally-computed count) has something to
		// show. Not required for Likes/Announces to actually work — Mastodon
		// computes its own count independently of what's reported here — so
		// this is deliberately best-effort and never blocks building the Note
		// if interaction_counts() finds nothing (0/0 on a brand-new artwork).
		$counts              = $this->interaction_counts( $post->ID );
		$note['likesCount']  = $counts['like'];
		$note['sharesCount'] = $counts['announce'];

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
	 * A compact "Also available in: X, Y" line linking every OTHER published
	 * language sibling of $post_id — TAG-REDESIGN.md F4 (§6b): "each sibling
	 * Note linking its translations informally in content, matching what the
	 * theme's language badge already does for HTML readers" (see
	 * `Network\SubdomainNavigation::render_language_badge()` for that HTML
	 * equivalent — same native-name source, `AI\SubmissionTranslator::
	 * language_names()`).
	 *
	 * Deliberately built for EVERY Note this method is called for, not only
	 * a sibling's own — under `agnosis_federate_languages`'s default
	 * `primary-only`, a sibling's Note is dereferenceable (F2) but never
	 * actively federated (F3/F4), so the PRIMARY Note is the only one an AP
	 * reader ever actually sees; without this line ALSO appearing there, the
	 * feature would be invisible in the default configuration. This is a
	 * pure content addition — it has no bearing on whether a sibling itself
	 * gets pushed (that's `Network\FederationSettlement`'s own
	 * `agnosis_federate_languages` gate, unrelated to what any one Note's
	 * `content` says).
	 *
	 * Returns '' (nothing appended) when Lingua Forge isn't active or no
	 * OTHER language currently has a published sibling for this post.
	 */
	private function build_language_switch_line( int $post_id ): string {
		if ( ! function_exists( 'linguaforge_get_translations' ) ) {
			return '';
		}

		$language_names = SubmissionTranslator::language_names();
		$links          = [];

		foreach ( linguaforge_get_translations( $post_id ) as $lang => $sibling_id ) {
			$sibling = get_post( (int) $sibling_id );
			if ( ! $sibling instanceof \WP_Post || 'publish' !== $sibling->post_status ) {
				continue;
			}

			$native_name = $language_names[ $lang ] ?? strtoupper( (string) $lang );
			$links[]     = sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_permalink( $sibling ) ), esc_html( $native_name ) );
		}

		if ( [] === $links ) {
			return '';
		}

		return sprintf(
			/* translators: %s: comma-separated, already-linked list of language names, e.g. "Deutsch, Español" */
			esc_html__( 'Also available in: %s', 'agnosis' ),
			implode( ', ', $links )
		);
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

	/**
	 * Resolve a Like/Announce/Undo activity's `object` URL back to a local
	 * artwork post id — the mirror image of resolve_local_owner() (which
	 * resolves an ACTOR url) and of object_id_for() (which builds this same
	 * permalink FROM a post going the other direction).
	 *
	 * Interaction-surface roadmap, Phase 1 (2026-07-24). Tries core's own
	 * url_to_postid() first — it reverses whatever rewrite rules are in
	 * effect for the 'art' permalink structure, so this doesn't need to
	 * duplicate that logic under pretty permalinks.
	 *
	 * **Real bug, caught by a real PHPUnit run (2026-07-24) — not just a test
	 * artifact.** url_to_postid() unconditionally returns 0 whenever
	 * `$wp_rewrite->wp_rewrite_rules()` is empty (its own "not using rewrite
	 * rules... out of options" early return) — i.e. under PLAIN permalinks,
	 * where no rewrite rules exist at all, only its hardcoded `p=`/`page_id=`/
	 * `attachment_id=` numeric-query fallback works. `get_post_permalink()`
	 * (what get_permalink()/object_id_for() actually call for this non-
	 * builtin CPT) falls back, under plain permalinks, to
	 * `add_query_arg( $post_type->query_var, $post->post_name, '' )` — i.e.
	 * `?agnosis_artwork=<slug>`, keyed by the CPT's own query var and the
	 * post's SLUG, not `p=<id>` — a shape url_to_postid() was never going to
	 * resolve. A remote Like/Announce/Undo sent back against exactly the
	 * permalink Agnosis itself published therefore silently failed to
	 * resolve on any site running plain permalinks, with inbox() still
	 * returning 200 (verify_inbox_signature() and the rest of inbox()'s own
	 * error handling never surfaces this — it just looks identical to "not
	 * ours to count"). Caught because the test suite defaults to plain
	 * permalinks and asserted the actual row got written, not just that
	 * inbox() didn't error.
	 *
	 * Fix: when url_to_postid() comes back empty, parse the URL's own query
	 * string for the agnosis_artwork query var directly and resolve that
	 * slug via get_page_by_path() (the standard core idiom for "resolve a
	 * post by slug for a given post type," despite the page-flavored name —
	 * used the same way regardless of post type elsewhere in core). This
	 * mirrors the outbound direction's own dual-mode correctness
	 * (test_note_id_equals_permalink_under_plain_permalinks() /
	 * ..._under_pretty_permalinks() already hold post_to_note() to both
	 * permalink modes) — the reverse direction needed the same rigor and
	 * didn't have it.
	 *
	 * Returns 0 (never a WP_Error/exception) for anything that isn't
	 * recognizably a local agnosis_artwork post — a Like on some other local
	 * page, or a URL that resolves to nothing at all — so callers can treat
	 * "not ours to count" as a plain, silent no-op.
	 *
	 * @param string $object_url The activity's `object` field (a permalink).
	 * @return int Post ID, or 0 if it doesn't resolve to a local artwork.
	 */
	private function resolve_local_post_id( string $object_url ): int {
		if ( '' === $object_url ) {
			return 0;
		}

		$post_id = url_to_postid( $object_url );

		if ( $post_id <= 0 ) {
			$query = (string) wp_parse_url( $object_url, PHP_URL_QUERY );
			wp_parse_str( $query, $query_vars );
			// wp_parse_str() types each value as string|array (parse_str()'s own
			// PHP semantics allow "foo[]=1&foo[]=2" to produce an array) — a
			// blind (string) cast on an array both fails PHPStan (invalid cast)
			// and would be wrong at runtime too (PHP casts an array to the
			// literal string "Array", not an error, so get_page_by_path()
			// would silently look up a bogus "Array" slug instead of treating
			// this as the no-slug case it actually is). is_string() guards both.
			$raw_slug = $query_vars['agnosis_artwork'] ?? '';
			$slug     = is_string( $raw_slug ) ? $raw_slug : '';

			if ( '' !== $slug ) {
				$post    = get_page_by_path( $slug, OBJECT, 'agnosis_artwork' );
				$post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
			}
		}

		if ( $post_id <= 0 ) {
			return 0;
		}

		return 'agnosis_artwork' === get_post_type( $post_id ) ? $post_id : 0;
	}

	/**
	 * Persist an inbound Like/Announce (interaction-surface roadmap, Phase 1,
	 * 2026-07-24). Before this, inbox() acknowledged both activity types with
	 * a 200 and discarded them — see the class docblock history and
	 * COMPETITIVE-ANALYSIS.md §3b for why this was a deliberately deferred gap
	 * through fifteen audits, not an oversight.
	 *
	 * Ulises's answer on boosts (agnosis-audit/INTERACTION-SURFACE-
	 * ROADMAP.md §5): counted and displayed independently from likes, not
	 * combined into one number — hence a plain `activity_type` column rather
	 * than folding Announce into the same bucket as Like.
	 *
	 * Deliberately silent (no error response) when the activity doesn't
	 * resolve to a local artwork or carries no actor — a Like/Announce for
	 * something Agnosis doesn't recognize, or a malformed delivery, is simply
	 * not counted; inbox() already returns 200 regardless, matching how every
	 * other "not applicable here" activity in this class is handled (e.g. the
	 * `Move` case).
	 *
	 * Upserts by the table's own (post_id, activity_type, actor_id) unique
	 * key via $wpdb->replace() — same idempotent-on-redelivery pattern
	 * handle_follow() already uses for agnosis_followers, so a re-delivered
	 * or re-Liked activity refreshes received_at rather than double-counting.
	 * No artist-level or rate-limit gate here — Ulises's answer (§5): likes
	 * and boosts are always allowed, no approval workflow, no restriction.
	 *
	 * @param array<string, mixed> $body Raw activity payload.
	 * @param string                $type 'like' or 'announce'.
	 */
	private function record_interaction( array $body, string $type ): void {
		$object = $body['object'] ?? '';
		if ( is_array( $object ) ) {
			$object_url = is_string( $object['id'] ?? null ) ? $object['id'] : '';
		} else {
			$object_url = is_string( $object ) ? $object : '';
		}

		$post_id = $this->resolve_local_post_id( $object_url );
		if ( ! $post_id ) {
			return;
		}

		$actor_id = is_string( $body['actor'] ?? null ) ? $body['actor'] : '';
		if ( '' === $actor_id ) {
			return;
		}

		$activity_id = is_string( $body['id'] ?? null ) ? $body['id'] : null;

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->replace() parameterizes every value; small, per-artwork-scale table.
		$wpdb->replace(
			$wpdb->prefix . 'agnosis_interactions',
			[
				'post_id'       => $post_id,
				'activity_type' => $type,
				'actor_id'      => $actor_id,
				'object_id'     => $activity_id,
			],
			[ '%d', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Delete a previously-recorded Like/Announce on Undo (interaction-surface
	 * roadmap, Phase 1). Mirrors handle_undo()'s existing Undo{Follow}
	 * handling — same "read the nested activity's own object" shape, since
	 * AS2's Undo{Like}/Undo{Announce} wraps the original activity in `object`
	 * rather than naming the target directly the way Undo{Follow} does.
	 *
	 * Without this, a like/boost count could only ever grow — see the
	 * roadmap doc's §2 assessment for why this was called out as a gap the
	 * moment Phase 1 started persisting anything at all.
	 *
	 * @param array<string, mixed> $body Undo activity payload.
	 * @param string                $type 'like' or 'announce' (the nested
	 *                                    object's own AS2 type, lowercased).
	 */
	private function undo_interaction( array $body, string $type ): void {
		$inner = $body['object'] ?? [];
		if ( ! is_array( $inner ) ) {
			return;
		}

		$inner_object = $inner['object'] ?? '';
		$object_url   = is_array( $inner_object )
			? ( is_string( $inner_object['id'] ?? null ) ? $inner_object['id'] : '' )
			: ( is_string( $inner_object ) ? $inner_object : '' );

		$post_id = $this->resolve_local_post_id( $object_url );
		if ( ! $post_id ) {
			return;
		}

		// The outer Undo's own `actor` is who's undoing — matches AS2 (an
		// Undo must be signed by the same actor as the activity it undoes),
		// same assumption resolve_inbox_signature()'s actor-binding check
		// already enforces before inbox() is ever reached.
		$actor_id = is_string( $body['actor'] ?? null ) ? $body['actor'] : '';
		if ( '' === $actor_id ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes every value; small, per-artwork-scale table.
		$wpdb->delete(
			$wpdb->prefix . 'agnosis_interactions',
			[ 'post_id' => $post_id, 'activity_type' => $type, 'actor_id' => $actor_id ],
			[ '%d', '%s', '%s' ]
		);
	}

	/**
	 * Like/boost counts for one artwork (interaction-surface roadmap, Phase
	 * 1). Used both by the on-site agnosis/interaction-counts display block
	 * and by post_to_note()'s outbound likesCount/sharesCount.
	 *
	 * @param int $post_id Artwork post id.
	 * @return array{like: int, announce: int}
	 */
	public function interaction_counts( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only aggregate over a small, per-artwork-scale table; $post_id is an int cast, not user input reaching SQL directly.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT activity_type, COUNT(*) AS c FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d GROUP BY activity_type",
				$post_id
			)
		);

		$counts = [ 'like' => 0, 'announce' => 0 ];
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->activity_type ] ) ) {
				$counts[ $row->activity_type ] = (int) $row->c;
			}
		}

		return $counts;
	}

	// -------------------------------------------------------------------------
	// On-site likes (interaction-surface roadmap, Phase 3, WP2, 2026-07-27)
	// -------------------------------------------------------------------------

	/** REST `permission_callback` for the likes toggle routes — coarse per-IP gate, same convention as every other public-write route in this codebase. */
	public function rate_limit_like(): bool|WP_Error {
		return RateLimiter::check( 'agnosis_like_toggle', self::LIKE_RATE_LIMIT, self::LIKE_RATE_WINDOW );
	}

	/**
	 * Which identity a same-origin, unauthenticated "like" is recorded under
	 * (§7 Q5). A logged-in artist's ordinary front-end/wp-admin session (not
	 * to be confused with the no-login rule for artist-facing ACTION LINKS —
	 * that's a different thing) likes under their own real actor URL, so
	 * their on-site like and a fediverse Like of the same artwork by the same
	 * person naturally share one actor_id. Every other visitor gets a
	 * same-day rotating salted hash of IP+UA: dedups repeat likes from the
	 * same visitor within one day, stores nothing identifiable, and
	 * deliberately does NOT support unliking after the salt rotates —
	 * rotate_like_salt() overwriting the option with nothing retained is the
	 * whole point of the rotation; a low-stakes, no-login feature accepts
	 * that trade rather than retaining anything to avoid it.
	 */
	private function like_identity(): string {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( in_array( 'agnosis_artist', (array) $user->roles, true ) ) {
				return $this->actor_url_for( 'artist', $user->ID );
			}
		}

		$salt = (string) get_option( 'agnosis_like_salt', '' );
		$ip   = RateLimiter::client_ip();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HTTP_USER_AGENT is hashed below, never stored or output raw.
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		return 'anon:' . hash( 'sha256', $salt . '|' . $ip . '|' . $ua );
	}

	/** Does $actor_id already have a recorded 'like' row on $post_id? */
	private function has_liked( int $post_id, string $actor_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row existence check; $wpdb->prepare() parameterizes both values.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d AND activity_type = 'like' AND actor_id = %s LIMIT 1",
				$post_id,
				$actor_id
			)
		);

		return null !== $found;
	}

	/**
	 * Shared response shape for like_content()/unlike_content() — the current toggle state plus the refreshed like count.
	 *
	 * @return array{liked: bool, like: int}
	 */
	private function like_response( int $post_id, string $actor_id ): array {
		return [
			'liked' => $this->has_liked( $post_id, $actor_id ),
			'like'  => $this->interaction_counts( $post_id )['like'],
		];
	}

	/** Is $post_id a real, currently-published agnosis_artwork? Shared guard for both like routes. */
	private function likeable_artwork( int $post_id ): \WP_Post|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'agnosis_artwork' !== $post->post_type ) {
			return new WP_Error( 'agnosis_like_not_found', __( 'No such artwork.', 'agnosis' ), [ 'status' => 404 ] );
		}
		return $post;
	}

	/**
	 * POST /agnosis/v1/content/{id}/likes — record a like from the current
	 * visitor/artist identity (like_identity()). Idempotent on repeat calls
	 * from the same identity, same $wpdb->replace() pattern record_interaction()
	 * already uses for a redelivered remote Like — a double-click just
	 * refreshes received_at rather than erroring or double-counting.
	 */
	public function like_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$post    = $this->likeable_artwork( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$actor_id = $this->like_identity();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->replace() parameterizes every value; small, per-artwork-scale table.
		$wpdb->replace(
			$wpdb->prefix . 'agnosis_interactions',
			[
				'post_id'       => $post_id,
				'activity_type' => 'like',
				'actor_id'      => $actor_id,
				'origin'        => 'local',
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		return new WP_REST_Response( $this->like_response( $post_id, $actor_id ), 200 );
	}

	/**
	 * DELETE /agnosis/v1/content/{id}/likes — remove the current visitor/
	 * artist identity's own like, if any. A no-op (not an error) when that
	 * identity never had one — same "undo of something already undone is
	 * fine" tolerance undo_interaction() already has for a re-delivered
	 * Undo{Like}.
	 */
	public function unlike_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$post    = $this->likeable_artwork( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$actor_id = $this->like_identity();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes every value; small, per-artwork-scale table.
		$wpdb->delete(
			$wpdb->prefix . 'agnosis_interactions',
			[ 'post_id' => $post_id, 'activity_type' => 'like', 'actor_id' => $actor_id ],
			[ '%d', '%s', '%s' ]
		);

		return new WP_REST_Response( $this->like_response( $post_id, $actor_id ), 200 );
	}

	/**
	 * agnosis_rotate_like_salt cron callback (daily) — overwrites the salt
	 * used by like_identity()'s anonymous-visitor hash. Unconditional
	 * update_option(), not add_option(): the whole point is that the
	 * previous value is gone once this runs, not preserved if already set
	 * (§7 Q5's explicit "no previous salt retained").
	 */
	public function rotate_like_salt(): void {
		update_option( 'agnosis_like_salt', wp_generate_password( 32, false ) );
	}

	/**
	 * Approved federated-reply count for one artwork — backs the
	 * agnosis/reply-overlay trigger button (render_reply_overlay()). Only
	 * `comment_approved = 1` rows count: an artist who hasn't reviewed a held
	 * reply yet, or rejected one, must never be visible to the public even as
	 * a bare number ("N replies" implies N readable replies).
	 */
	public function reply_count( int $post_id ): int {
		return (int) get_comments( [
			'post_id' => $post_id,
			'type'    => self::REPLY_COMMENT_TYPE,
			'status'  => 'approve',
			'count'   => true,
		] );
	}

	/**
	 * Public, unauthenticated read of one artwork's approved replies — feeds
	 * the agnosis/reply-overlay block's own fetch, not a general-purpose
	 * comments API. Returns the artist's-language translation once
	 * drain_reply_translation_queue() has resolved it, falling back to the
	 * untouched original while translation is still pending (never blocks on
	 * it — this is a live, cacheable GET, not the place to run an AI call).
	 */
	public function get_replies( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );

		$comments = get_comments( [
			'post_id' => $post_id,
			'type'    => self::REPLY_COMMENT_TYPE,
			'status'  => 'approve',
			'orderby' => 'comment_date_gmt',
			'order'   => 'ASC',
		] );

		$replies = [];
		// get_comments() only ever returns an int when 'count' => true is
		// passed (not the case here) — this guard is for PHPStan's generic
		// stub, not a real runtime branch, same reasoning as every other
		// get_comments() call site in this class.
		foreach ( is_array( $comments ) ? $comments : [] as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}
			$translated = get_comment_meta( (int) $comment->comment_ID, self::REPLY_TRANSLATED_CONTENT_META, true );

			$replies[] = [
				'author'  => $comment->comment_author,
				'url'     => $comment->comment_author_url,
				'content' => '' !== (string) $translated ? (string) $translated : $comment->comment_content,
				'date'    => mysql2date( 'c', $comment->comment_date_gmt, false ),
			];
		}

		return new WP_REST_Response( [ 'count' => count( $replies ), 'replies' => $replies ], 200 );
	}

	/**
	 * Register the agnosis/reply-overlay dynamic block — a "N replies"
	 * trigger that opens a native-Popover-API panel (same mechanism as
	 * Newsletter\PopoverBlock's subscribe popover; no bespoke modal JS/CSS
	 * invented for this) fetching get_replies() once opened.
	 */
	public function register_reply_overlay_block(): void {
		register_block_type(
			\AGNOSIS_DIR . 'blocks/reply-overlay',
			[ 'render_callback' => [ $this, 'render_reply_overlay' ] ]
		);
	}

	/**
	 * Render callback for agnosis/reply-overlay. Renders nothing on a
	 * non-artwork post. On an artwork with zero APPROVED replies, renders a
	 * plain, non-interactive "0 replies" line instead — no button, no
	 * popover, no enqueued JS/CSS — since there's nothing to open and (as of
	 * 2026-07-25) this block still only surfaces existing fediverse replies,
	 * not a local reply/comment form (that's a separate, not-yet-built
	 * feature, deliberately out of scope here — a held-but-unreviewed reply
	 * must never tease its own existence via a clickable trigger either way).
	 * Once there's at least one approved reply, the real button + popover
	 * panel takes over, same as before.
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 */
	public function render_reply_overlay( array $attrs, string $content, \WP_Block $block ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );

		if ( ! $post || 'agnosis_artwork' !== $post->post_type ) {
			return '';
		}

		$count = $this->reply_count( $post_id );
		if ( 0 === $count ) {
			$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'agnosis-reply-overlay agnosis-reply-overlay--empty' ] );
			return sprintf(
				'<span %s>%s</span>',
				$wrapper_attributes,
				esc_html__( '0 replies', 'agnosis' )
			);
		}

		wp_enqueue_style( 'agnosis-reply-overlay', \AGNOSIS_URL . 'blocks/reply-overlay/frontend.css', [], \AGNOSIS_VERSION );
		wp_enqueue_script( 'agnosis-reply-overlay', \AGNOSIS_URL . 'blocks/reply-overlay/frontend.js', [], \AGNOSIS_VERSION, [ 'in_footer' => true ] );
		wp_localize_script( 'agnosis-reply-overlay', 'agnosisReplyOverlay', [
			'apiUrl' => rest_url( 'agnosis/v1/content/' . $post_id . '/replies' ),
			'i18n'   => [
				'loading' => __( 'Loading replies…', 'agnosis' ),
				'error'   => __( 'Could not load replies.', 'agnosis' ),
			],
		] );

		$panel_id           = 'agnosis-reply-overlay-' . $post_id;
		$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'agnosis-reply-overlay' ] );

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() output is already escaped. ?>>
			<button
				type="button"
				class="agnosis-reply-overlay__trigger"
				popovertarget="<?php echo esc_attr( $panel_id ); ?>"
				popovertargetaction="show"
			>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of replies. */
						_n( '%d reply', '%d replies', $count, 'agnosis' ),
						$count
					)
				);
				?>
			</button>

			<div id="<?php echo esc_attr( $panel_id ); ?>" class="agnosis-reply-overlay__panel" popover="auto" data-agnosis-reply-list data-agnosis-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
				<button
					type="button"
					class="lf-icon-btn lf-popover-close"
					popovertarget="<?php echo esc_attr( $panel_id ); ?>"
					popovertargetaction="hide"
					aria-label="<?php esc_attr_e( 'Close', 'agnosis' ); ?>"
				>
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">
						<path d="M4 4l16 16M20 4 4 20"></path>
					</svg>
				</button>
				<div class="agnosis-reply-overlay__inner"></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * WP-Cron drain for the reply-translation queue —
	 * `agnosis_drain_reply_translation_queue`, `every_five_minutes` (mirrors
	 * Compat\LinguaForge::drain_translation_queue()'s own shape: walk every
	 * row still flagged pending, time-budgeted, resumable). Translation
	 * happens here, off the signed inbox() request path (roadmap §4 Phase 2
	 * step 8) — inbox() already returned 200 the moment the comment was
	 * inserted; this only ever refines what get_replies() later returns.
	 *
	 * Target language: the artist's own declared locale
	 * (SubmissionTranslator::resolve_artist_lang()) falling back to the
	 * site's own resolve_target_language() chain when the artist has none —
	 * same fallback order already used for submissions. Ulises confirmed
	 * every real Agnosis artist has a declared language via their profile, so
	 * this is a defensive fallback, not an expected real-world path.
	 */
	public function drain_reply_translation_queue(): void {
		$deadline = microtime( true ) + self::REPLY_TRANSLATION_TIME_BUDGET_SECONDS;

		$comments = get_comments( [
			'type'     => self::REPLY_COMMENT_TYPE,
			'status'   => 'any',
			'meta_key' => self::REPLY_PENDING_TRANSLATION_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- cron-only path, bounded by the queue's own (small, self-draining) size.
		] );

		// get_comments() only ever returns an int when 'count' => true is
		// passed (not the case here) — the is_array() check is for PHPStan's
		// generic stub, not a real runtime branch.
		if ( ! is_array( $comments ) || empty( $comments ) ) {
			return;
		}

		$translator = SubmissionTranslator::from_settings();
		if ( null === $translator ) {
			return;
		}

		foreach ( $comments as $comment ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}

			$post_id = (int) $comment->comment_post_ID;
			$target  = SubmissionTranslator::resolve_artist_lang( (int) get_post_field( 'post_author', $post_id ) );
			if ( '' === $target ) {
				$target = SubmissionTranslator::resolve_target_language();
			}

			$translated = $translator->translate_text( $comment->comment_content, $target );

			update_comment_meta( (int) $comment->comment_ID, self::REPLY_TRANSLATED_CONTENT_META, $translated );
			delete_comment_meta( (int) $comment->comment_ID, self::REPLY_PENDING_TRANSLATION_META );
		}
	}

	// -------------------------------------------------------------------------
	// Federated replies (interaction-surface roadmap, Phase 2, 2026-07-25)
	// -------------------------------------------------------------------------

	/**
	 * Ingest a remote `Create{Note, inReplyTo: <artwork-url>}` as a held WP
	 * comment. Every gate below returns the same `{"status":"ignored"}`/200 —
	 * matching every other "not accepted" branch already in inbox() (e.g. the
	 * `Move` case) — a remote server never needs to know WHY a reply wasn't
	 * kept, and a 2xx means it won't retry.
	 *
	 * Gating order (cheapest/most-certain-to-reject first):
	 *   1. Shape check — must be a real Note with a resolvable inReplyTo.
	 *   2. Idempotent redelivery — already-stored activity id is a no-op accept.
	 *   3. Artist-level gate (roadmap §4 step 5) — per-post
	 *      `_agnosis_replies_disabled` (Artist\ContentEditor) or account-wide
	 *      `_agnosis_replies_optout` (Artist\NotificationPreferences) declines
	 *      outright, not held-for-moderation — same "don't offer what nobody
	 *      will honor" precedent as Artist\ContactForm::contactable_artist().
	 *   4. Per-actor rate gate (roadmap §4 step 6) — `Core\RateLimiter`, same
	 *      method the contact form and email intake already use, keyed on the
	 *      actor URL instead of an email address.
	 *   5. Insert, held (`comment_approved = 0`) — see REPLY_COMMENT_TYPE's
	 *      own docblock for why this is unconditional.
	 *
	 * Translation is deliberately NOT done here — inbox() is a live,
	 * signature-verified webhook a remote server expects a fast ack from;
	 * see drain_reply_translation_queue()'s own docblock for the async leg.
	 *
	 * @param array<string, mixed> $body Create activity payload.
	 */
	private function handle_create_reply( array $body ): WP_REST_Response {
		$ignored = new WP_REST_Response( [ 'status' => 'ignored', 'type' => 'Create' ], 200 );

		$object = $body['object'] ?? [];
		if ( ! is_array( $object ) || 'Note' !== ( $object['type'] ?? '' ) ) {
			return $ignored;
		}

		$in_reply_to = is_string( $object['inReplyTo'] ?? null ) ? $object['inReplyTo'] : '';
		$post_id     = $this->resolve_local_post_id( $in_reply_to );
		if ( ! $post_id ) {
			return $ignored;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return $ignored;
		}

		$actor_url = is_string( $body['actor'] ?? null ) ? $body['actor'] : '';
		if ( '' === $actor_url ) {
			return $ignored;
		}

		// The NOTE's own AS2 id (not the wrapping Create's) — a future
		// Delete{object: <note-id>} names this same value, per AS2/Mastodon
		// convention (mirrors how object_id_for() is the Note id every other
		// activity type here keys off).
		$activity_id = is_string( $object['id'] ?? null ) ? $object['id'] : '';
		if ( '' === $activity_id ) {
			return $ignored;
		}

		// Idempotent redelivery — a re-sent Create must not insert a second
		// comment (same "replay must upsert, not duplicate" discipline as
		// record_interaction()'s Like/Announce handling above).
		$already_stored = get_comments( [
			'post_id'    => $post_id,
			'meta_key'   => self::REPLY_ACTIVITY_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off idempotency check per inbound reply, not a listing query.
			'meta_value' => $activity_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value_field
			'number'     => 1,
			'count'      => true,
		] );
		if ( $already_stored > 0 ) {
			return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
		}

		if ( '1' === (string) get_post_meta( $post_id, self::REPLIES_DISABLED_META, true ) ) {
			return $ignored;
		}
		if ( '1' === (string) get_user_meta( (int) $post->post_author, '_agnosis_replies_optout', true ) ) {
			return $ignored;
		}

		$limit         = max( 1, (int) get_option( 'agnosis_ap_reply_per_actor_limit', 2 ) );
		$window_hours  = max( 1, (int) get_option( 'agnosis_ap_reply_per_actor_limit_window_hours', 1 ) );
		if ( is_wp_error( RateLimiter::check_sender( 'ap_reply', $actor_url, $limit, $window_hours * HOUR_IN_SECONDS ) ) ) {
			return $ignored;
		}

		$content = is_string( $object['content'] ?? null ) ? $object['content'] : '';
		$content = trim( wp_kses( $content, [
			'p'      => [],
			'br'     => [],
			'span'   => [],
			'a'      => [ 'href' => true, 'rel' => true ],
		] ) );
		if ( '' === $content ) {
			return $ignored;
		}

		$profile   = $this->fetch_remote_actor_profile( $actor_url );
		$published = is_string( $object['published'] ?? null ) ? strtotime( $object['published'] ) : false;
		$date_gmt  = false !== $published ? gmdate( 'Y-m-d H:i:s', $published ) : current_time( 'mysql', true );

		$comment_id = wp_insert_comment( [
			'comment_post_ID'    => $post_id,
			'comment_author'     => '' !== $profile['name'] ? $profile['name'] : $actor_url,
			'comment_author_url' => $profile['url'],
			'comment_content'    => $content,
			'comment_type'       => self::REPLY_COMMENT_TYPE,
			'comment_approved'   => 0,
			'comment_agent'      => 'ActivityPub',
			'comment_date_gmt'   => $date_gmt,
			'comment_date'       => get_date_from_gmt( $date_gmt ),
		] );

		if ( ! $comment_id ) {
			return $ignored;
		}

		update_comment_meta( $comment_id, self::REPLY_ACTIVITY_ID_META, $activity_id );
		update_comment_meta( $comment_id, self::REPLY_ACTOR_META, $actor_url );
		update_comment_meta( $comment_id, self::REPLY_PENDING_TRANSLATION_META, '1' );

		$this->notify_artist_of_reply( $post, $comment_id, $content );

		return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
	}

	/**
	 * Fetch a remote actor's display name + profile URL for use as a
	 * federated reply's comment_author/comment_author_url. Mirrors
	 * resolve_inbox()'s own wp_safe_remote_get() shape (same peer-supplied-URL
	 * safety reasoning, audit §3b) but reads `name`/`preferredUsername`/`url`
	 * instead of `inbox`. Cached an hour per actor (transient) so a repeat
	 * replier doesn't cost a live fetch on every single reply.
	 *
	 * @return array{name: string, url: string}
	 */
	private function fetch_remote_actor_profile( string $actor_url ): array {
		$cache_key = 'agnosis_ap_actor_' . md5( $actor_url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['name'], $cached['url'] ) ) {
			return $cached;
		}

		$profile = [ 'name' => '', 'url' => $actor_url ];

		$response = wp_safe_remote_get( $actor_url, [
			'headers' => [ 'Accept' => 'application/activity+json' ],
			'timeout' => 10,
		] );

		if ( ! is_wp_error( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) ) {
				$name = is_string( $data['name'] ?? null )
					? $data['name']
					: ( is_string( $data['preferredUsername'] ?? null ) ? $data['preferredUsername'] : '' );
				$profile = [
					'name' => $name,
					'url'  => is_string( $data['url'] ?? null ) ? $data['url'] : $actor_url,
				];
			}
		}

		set_transient( $cache_key, $profile, HOUR_IN_SECONDS );

		return $profile;
	}

	/**
	 * Email the artwork's own artist that a reply arrived and is awaiting
	 * their approval — the `agnosis_artist` role has no `moderate_comments`
	 * capability (see REPLY_COMMENT_TYPE's own docblock), so wp-admin's
	 * ordinary "comment held for moderation" notice (wp_notify_moderator(),
	 * which mails the site admin, not the post author) would never reach
	 * them at all. Includes a one-click Approve/Reject pair
	 * (handle_reply_moderation()) — the same stateless, emailed-HMAC-link
	 * pattern already used throughout this plugin (VouchConfirm,
	 * AdmissionConfirm) for an artist to act without a WP login — plus the
	 * existing NotificationPreferences link so "opt out of reply
	 * notifications" is reachable from every single email, not just the first
	 * (Ulises: "on by default and possible to opt-out on... every reply").
	 *
	 * WP0 (agnosis-audit/INTERACTION-SURFACE-ROADMAP.md §8): the moderation
	 * link's token itself stays a stateless HMAC (reply_moderation_token()) —
	 * nothing new to store there — but it never used to expire at all. An
	 * expiry timestamp is now written once here, as comment meta, using the
	 * same `agnosis_review_token_expiry_days` option (default 7) every other
	 * stateless emailed action link in the plugin already honours
	 * (ApplicationBiography, PostCreator, Notification) — one consistent
	 * "how long do I have" window for an artist, not a second bespoke number
	 * just for replies. Stored once at send time rather than recomputed from
	 * the option at verify time: recomputing would silently move the
	 * deadline on a link already sitting in an artist's inbox the moment an
	 * admin changed the setting, exactly the trap ReviewEndpoints's stored
	 * `_agnosis_review_expiry` already avoids.
	 */
	private function notify_artist_of_reply( \WP_Post $post, int $comment_id, string $content ): void {
		$author_id = (int) $post->post_author;
		$author    = get_userdata( $author_id );
		if ( ! $author || '' === $author->user_email ) {
			return;
		}

		$expiry_days = max( 1, (int) get_option( 'agnosis_review_token_expiry_days', 7 ) );
		update_comment_meta( $comment_id, self::REPLY_MODERATION_EXPIRY_META_KEY, time() + $expiry_days * DAY_IN_SECONDS );

		$locale = (string) get_user_meta( $author_id, 'locale', true );
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		$excerpt = wp_strip_all_tags( $content );
		if ( mb_strlen( $excerpt ) > 300 ) {
			$excerpt = mb_substr( $excerpt, 0, 300 ) . '…';
		}

		$subject = sprintf(
			/* translators: %s: artwork title. */
			__( 'New reply on "%s"', 'agnosis' ),
			$post->post_title
		);

		$message = sprintf(
			/* translators: 1: reply excerpt, 2: approve link, 3: reject link, 4: notification-preferences link. */
			__(
				"Someone replied to your artwork from the Fediverse:\n\n\"%1\$s\"\n\nIt's being held until you approve or reject it:\n\nApprove: %2\$s\nReject: %3\$s\n\nDon't want reply notifications? Manage that here: %4\$s",
				'agnosis'
			),
			$excerpt,
			self::reply_moderation_url( $comment_id, 'approve' ),
			self::reply_moderation_url( $comment_id, 'reject' ),
			NotificationPreferences::prefs_url( $author_id )
		);

		wp_mail( $author->user_email, $subject, $message );

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	// -------------------------------------------------------------------------
	// Reply moderation — one-click emailed action (no WP login required)
	// -------------------------------------------------------------------------

	/** Build the stateless one-click moderation URL for one comment + action. */
	private static function reply_moderation_url( int $comment_id, string $action ): string {
		return add_query_arg(
			[
				'agnosis_reply' => $comment_id,
				'action'        => $action,
				'token'         => self::reply_moderation_token( $comment_id, $action ),
			],
			home_url( '/' )
		);
	}

	private static function reply_moderation_token( int $comment_id, string $action ): string {
		return hash_hmac( 'sha256', "{$comment_id}|{$action}|reply_moderate", wp_salt( 'auth' ) );
	}

	/**
	 * Verify a reply-moderation link's token and expiry (WP0, agnosis-audit/
	 * INTERACTION-SURFACE-ROADMAP.md §8). Returns null when valid, or a
	 * user-facing error message when not. There's no REST layer on this path
	 * to hand a WP_Error to (unlike ReviewEndpoints::verify_token()), just a
	 * wp_die() page, so this returns a plain translated string instead.
	 */
	private static function verify_reply_moderation_token( int $comment_id, string $action, string $token ): ?string {
		if ( '' === $token || ! hash_equals( self::reply_moderation_token( $comment_id, $action ), $token ) ) {
			return __( 'This link is invalid or has already expired.', 'agnosis' );
		}

		$expiry = (int) get_comment_meta( $comment_id, self::REPLY_MODERATION_EXPIRY_META_KEY, true );
		if ( $expiry && time() > $expiry ) {
			return __( 'This link has expired.', 'agnosis' );
		}

		return null;
	}

	/**
	 * Register the template_redirect handler for the moderation link above.
	 * Called from Core\Plugin, same as every other stateless-token flow.
	 */
	public function register_reply_moderation_handler(): void {
		add_action( 'template_redirect', [ $this, 'handle_reply_moderation' ] );
	}

	/**
	 * Handle a click on the Approve/Reject link from notify_artist_of_reply().
	 * No WP nonce — this is an unauthenticated email-link recipient with no
	 * WP session; the HMAC token plays the nonce's role, same as
	 * NotificationPreferences/VouchConfirm/AdmissionConfirm.
	 *
	 * WP0 fix (agnosis-audit/INTERACTION-SURFACE-ROADMAP.md §7a/§8): this used
	 * to act on a bare GET — approving or trashing the comment the instant
	 * the link was fetched, with no confirmation step at all. Corporate
	 * mail-security scanners (Outlook SafeLinks, Mimecast, Proofpoint, etc.)
	 * prefetch links in incoming email to scan them, issuing a GET and never
	 * clicking anything — so the prefetch alone was enough to silently
	 * approve or trash a held reply before the artist ever saw the email.
	 * `Publishing\ReviewConfirm` solved exactly this for review/removal links
	 * in July (see its class docblock); this now reuses that same
	 * GET-renders/POST-acts split via its shared
	 * `render_action_confirm_page()` interstitial rather than a second
	 * hand-rolled copy of the same page:
	 *
	 *   GET  → token+expiry verified, comment existence verified, then a
	 *          confirm interstitial renders with a single POST button. No
	 *          state change yet, so a scanner's prefetch is harmless.
	 *   POST → token+expiry re-verified, then the comment is actually
	 *          approved/trashed.
	 */
	public function handle_reply_moderation(): void {
		$is_post = ReviewConfirm::is_post_request();

		// No WP nonce for the same reason as always on this flow (see method
		// docblock): an unauthenticated email-link recipient has no WP
		// session, so the HMAC token is this flow's nonce equivalent, not
		// $_POST/$_GET itself.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$source = $is_post ? $_POST : $_GET;

		if ( ! isset( $source['agnosis_reply'] ) ) {
			return;
		}

		$comment_id = absint( wp_unslash( $source['agnosis_reply'] ) );
		$action     = sanitize_key( wp_unslash( $source['action'] ?? '' ) );
		$token      = sanitize_text_field( wp_unslash( $source['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		if ( ! $comment_id || ! in_array( $action, [ 'approve', 'reject' ], true ) ) {
			wp_die(
				esc_html__( 'This link is invalid or has already expired.', 'agnosis' ),
				esc_html__( 'Link error', 'agnosis' ),
				[ 'response' => 400 ]
			);
		}

		$token_error = self::verify_reply_moderation_token( $comment_id, $action, $token );
		if ( null !== $token_error ) {
			wp_die( esc_html( $token_error ), esc_html__( 'Link error', 'agnosis' ), [ 'response' => 400 ] );
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment || self::REPLY_COMMENT_TYPE !== $comment->comment_type ) {
			wp_die(
				esc_html__( 'This reply no longer exists.', 'agnosis' ),
				esc_html__( 'Link error', 'agnosis' ),
				[ 'response' => 404 ]
			);
		}

		// GET only renders the confirm interstitial — see method docblock.
		// The token travels back to the POST in the confirm form's hidden
		// field, never in the form's action URL (same reasoning as
		// ReviewConfirm's own review/removal links).
		if ( ! $is_post ) {
			$prompts = [
				'approve' => [
					__( 'Approve this reply?', 'agnosis' ),
					__( 'This will publish the reply on your artwork.', 'agnosis' ),
					__( 'Yes, approve it', 'agnosis' ),
				],
				'reject'  => [
					__( 'Reject this reply?', 'agnosis' ),
					__( 'This will discard the reply — it will not be shown.', 'agnosis' ),
					__( 'Yes, reject it', 'agnosis' ),
				],
			];
			[ $heading, $description, $button ] = $prompts[ $action ];

			ReviewConfirm::render_action_confirm_page(
				$heading,
				$description,
				$button,
				[
					'agnosis_reply' => (string) $comment_id,
					'action'        => $action,
					'token'         => $token,
				]
			);
			return; // render_action_confirm_page() always exits via wp_die().
		}

		if ( 'approve' === $action ) {
			wp_set_comment_status( $comment_id, 'approve' );
			$message = __( 'Reply approved — it now appears on your artwork.', 'agnosis' );
		} else {
			wp_trash_comment( $comment_id );
			$message = __( 'Reply rejected — it will not be shown.', 'agnosis' );
		}

		wp_die( esc_html( $message ), esc_html__( 'Reply moderated', 'agnosis' ), [ 'response' => 200 ] );
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
		$object      = $body['object'] ?? [];
		$object_type = $object['type'] ?? '';

		if ( 'Follow' === $object_type ) {
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
		} elseif ( 'Like' === $object_type || 'Announce' === $object_type ) {
			// Interaction-surface roadmap, Phase 1 (2026-07-24) — see
			// undo_interaction()'s own docblock.
			$this->undo_interaction( $body, strtolower( $object_type ) );
		}

		return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
	}

	/**
	 * A remote account self-deleting fans out `Delete { object: actorUrl }`
	 * to every known inbox (audit §2b, AUDIT-1.0.0.md) — without this, the
	 * stale agnosis_followers row lingered and every future broadcast kept
	 * POSTing to a dead inbox until enough failures churned through the
	 * retry queue (handled, but noisily and forever, since a permanent 410
	 * was retried like a transient failure — see
	 * is_permanently_dead_delivery_error()'s own docblock for that half).
	 *
	 * Only acts on a genuine self-account-delete: the activity's `object`
	 * must resolve to the SAME actor as `actor` (self_delete_actor()) — the
	 * verified signer in the normal case (see
	 * verify_inbox_signature()/HttpSignature::verify_actor_binding(), which
	 * already ran before inbox() was ever reached), or, when the signature
	 * itself could never be verified because the actor's key is truly gone,
	 * an actor corroborated instead by a live HTTP 410 on that same actor's
	 * document (audit §4a, verify_inbox_signature()'s
	 * corroborated_self_delete() — also already ran before inbox() is
	 * reached, and applies the identical actor-binding check). A Delete of
	 * some other object — a remote post/note, not the account itself — is a
	 * different, unrelated activity shape this plugin has no reason to act
	 * on; `object` may be a bare actor-URL string or an AS2 Tombstone
	 * `{ type: 'Tombstone', id: actorUrl }` (Mastodon uses the Tombstone
	 * form), both are handled.
	 *
	 * Deletes every agnosis_followers row for that actor_id regardless of
	 * owner — unlike Undo (a targeted unfollow of one specific local
	 * target), the remote actor no longer exists at all, so every row
	 * naming it — the node's own follower list AND any per-artist follower
	 * list it appeared in — is equally stale.
	 *
	 * @param array<string, mixed> $body
	 */
	private function handle_delete( array $body ): WP_REST_Response {
		$actor = self::self_delete_actor( $body );

		if ( '' !== $actor ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes every value; small, node-scale table.
			$wpdb->delete(
				$wpdb->prefix . 'agnosis_followers',
				[ 'actor_id' => $actor ],
				[ '%s' ]
			);
		} else {
			// Not a self-account-delete — check whether it's a remote actor
			// deleting one of their own previously-stored federated replies
			// (interaction-surface roadmap, Phase 2 step 4).
			$this->maybe_delete_reply( $body );
		}

		return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
	}

	/**
	 * Trash the WP comment matching a `Delete{object: <note-id>}` when that
	 * note-id was one we stored via handle_create_reply(). Ownership is
	 * checked against the SAME actor URL that authored the reply
	 * (REPLY_ACTOR_META) — an Undo/Delete must be signed by the same actor
	 * as the activity it undoes, same assumption verify_inbox_signature()'s
	 * actor-binding already enforces before inbox() is ever reached, and the
	 * exact discipline undo_interaction() already applies to Like/Announce.
	 *
	 * @param array<string, mixed> $body Delete activity payload.
	 */
	private function maybe_delete_reply( array $body ): void {
		$object    = $body['object'] ?? '';
		$object_id = is_array( $object ) ? (string) ( $object['id'] ?? '' ) : (string) $object;
		if ( '' === $object_id ) {
			return;
		}

		$actor_url = is_string( $body['actor'] ?? null ) ? $body['actor'] : '';
		if ( '' === $actor_url ) {
			return;
		}

		$comments = get_comments( [
			'meta_key'   => self::REPLY_ACTIVITY_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off lookup per inbound Delete, not a listing query.
			'meta_value' => $object_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value_field
			'type'       => self::REPLY_COMMENT_TYPE,
			'number'     => 1,
			'status'     => 'any',
		] );
		if ( ! is_array( $comments ) || empty( $comments ) || ! ( $comments[0] instanceof \WP_Comment ) ) {
			return;
		}

		$comment      = $comments[0];
		$stored_actor = get_comment_meta( (int) $comment->comment_ID, self::REPLY_ACTOR_META, true );
		if ( $stored_actor !== $actor_url ) {
			return;
		}

		wp_trash_comment( (int) $comment->comment_ID );
	}

	/**
	 * Returns the actor URL when `$body` is a genuine self-account-delete
	 * shape (`object` resolves to the SAME actor as `actor`), or '' when it
	 * isn't — `object` may be a bare actor-URL string or an AS2 Tombstone
	 * `{ type: 'Tombstone', id: actorUrl }` (Mastodon uses the Tombstone
	 * form), both handled identically here. Shared by handle_delete() and
	 * verify_inbox_signature()'s audit §4a key-410 corroboration check —
	 * both need the exact same "is this really a self-delete" test.
	 *
	 * @param array<string, mixed> $body
	 */
	private static function self_delete_actor( array $body ): string {
		$actor  = is_string( $body['actor'] ?? null ) ? $body['actor'] : '';
		$object = $body['object'] ?? '';

		$object_id = is_array( $object ) ? (string) ( $object['id'] ?? '' ) : (string) $object;

		return ( '' !== $actor && $actor === $object_id ) ? $actor : '';
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

		// Audit §2b, AUDIT-1.0.0.md — a definitive 410 Gone/404 Not Found
		// means the inbox is confirmed dead, not transiently unreachable;
		// skip the retry queue's multi-day backoff entirely rather than
		// spending it on an endpoint that's already known gone.
		if ( $this->is_permanently_dead_delivery_error( $result ) ) {
			$this->record_dead_delivery( $inbox_url, $activity_type, $body, $owner_type, $owner_id, $result );
			return;
		}

		// A cron-driven retry queue picks this delivery back up instead of it
		// being lost after this one fire-and-forget attempt (audit §3g note
		// iv) — previously this log line was the only trace a failed
		// delivery ever left.
		$this->enqueue_delivery_retry( $inbox_url, $activity_type, $body, $owner_type, $owner_id );
	}

	/**
	 * A definitive "this inbox no longer exists" signal from attempt_send()'s
	 * own `'HTTP %d: %s'` error format (see that method) — HTTP 410 Gone (the
	 * spec-correct code for a deliberately-removed resource, and what
	 * Mastodon serves for a deleted account's inbox) or 404 Not Found.
	 * Retrying either like a transient failure for the full multi-day
	 * backoff wastes retry-queue cycles on an inbox that's already known
	 * dead (audit §2b, AUDIT-1.0.0.md).
	 */
	private function is_permanently_dead_delivery_error( string $error ): bool {
		return 1 === preg_match( '/^HTTP (410|404):/', $error );
	}

	/**
	 * Insert a delivery-queue row already in its terminal 'failed' state,
	 * for a first-attempt live delivery that failed with a definitive
	 * dead-inbox signal — see is_permanently_dead_delivery_error()'s own
	 * docblock. Skips the normal pending/backoff cycle entirely: still
	 * recorded in the same table/shape a normally-exhausted retry ends up
	 * in (queryable via Settings → Logs the same way), just without ever
	 * occupying a 'pending' row or spending any retry-queue cron cycles on
	 * an inbox that's already confirmed gone.
	 */
	private function record_dead_delivery( string $inbox_url, string $activity_type, string $activity_json, string $owner_type, int $owner_id, string $error ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() parameterizes every value.
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_ap_delivery_queue',
			[
				'inbox_url'     => $inbox_url,
				'activity_type' => $activity_type,
				'activity_json' => $activity_json,
				'owner_type'    => $owner_type,
				'owner_id'      => $owner_id,
				'status'        => 'failed',
				'attempts'      => 0,
				'last_error'    => $error,
				'resolved_at'   => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Sign and POST one already-encoded activity body to one inbox.
	 *
	 * Pure transport: returns success/failure but never logs or enqueues a
	 * retry itself — deliver() (a live, first attempt) and
	 * process_delivery_retry_queue() (a queued retry) each need to react to a
	 * failure differently, so that decision stays with the caller.
	 *
	 * Never actually returns `false` — only `true` or a `string` — but kept
	 * as a native `bool|string` type rather than PHP 8.2's standalone `true`
	 * type: the audit sandbox's bundled linter tops out at PHP 8.1 and can't
	 * parse `true` in a type position (though the plugin's real minimum is
	 * already 8.2), so this stays independently verifiable here instead of
	 * shipping unverified syntax. The `@return` tag below still gives
	 * PHPStan the precise `true|string` shape, so
	 * `is_permanently_dead_delivery_error()`/`record_dead_delivery()`'s
	 * `string`-typed `$error` parameters don't see a phantom `false` branch
	 * after the `true === $result` check both call sites narrow on first.
	 *
	 * @return true|string True on a 2xx response; an error-message string otherwise.
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
			// Audit §2b, AUDIT-1.0.0.md — a definitive 410/404 exhausts
			// immediately rather than working through the remaining backoff
			// intervals; see is_permanently_dead_delivery_error()'s own
			// docblock.
			$exhausted = $this->is_permanently_dead_delivery_error( $result ) || $attempts >= count( self::RETRY_INTERVALS );

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
