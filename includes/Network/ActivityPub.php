<?php
/**
 * ActivityPub — the federation subsystem's entry point and orchestrator.
 *
 * Implements ActivityPub actors for the Agnosis node, making published
 * artworks discoverable from Mastodon, Pixelfed, and the broader Fediverse
 * without any central server.
 *
 * **This class does almost nothing itself.** Until 0.9.67 it was a single
 * 6,337-line file owning eight cohesive subsystems; the sixteenth audit's Q-2
 * split (agnosis-audit/ACTIVITYPUB-SPLIT-ROADMAP.md) moved the behaviour into
 * `Network\Federation\*` and left this class with three jobs: build the units,
 * route WordPress's hooks and REST routes into them, and hold the public
 * constants the rest of the plugin references. The name stayed — it *is* the
 * ActivityPub subsystem, and ~290 call sites across 42 files would have changed
 * for a naming preference (§0a).
 *
 * The eight units, in dependency order — each depends only on those to its left,
 * which is enforced by construction in the accessors below, so a cycle cannot be
 * written:
 *
 *     Identity / Language          who this node and its artists are; what language a post speaks
 *       -> Delivery                signing, sending, and the retry queue
 *         -> Interactions          likes and boosts, inbound and on-site
 *         -> Rhizome               relay-boosts from trusted peer nodes
 *         -> Follows               the follower relationship and its Follow button
 *           -> Replies             local, federated, gateway, and cross-language mirroring
 *             -> Serialization     artwork Notes and their Create/Update/Delete lifecycle
 *
 * Collaborators are built lazily, not in a constructor (§5d): they are stateless
 * and take no configuration, so eager construction bought nothing while making
 * this class unusable to any subclass that did not chain `parent::__construct()`.
 *
 * Most public methods below are delegators, kept because something outside calls
 * them by this name — a REST callback, a `Core\Plugin` hook, another class, or a
 * test. That is the split's Invariant 4/5: no caller anywhere had to change, and
 * no test file was edited.
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

use Agnosis\AI\Pipeline;
use Agnosis\AI\SubmissionTranslator;
use Agnosis\Artist\NotificationPreferences;
use Agnosis\Network\Federation\Delivery;
use Agnosis\Network\Federation\Follows;
use Agnosis\Network\Federation\Identity;
use Agnosis\Network\Federation\Interactions;
use Agnosis\Network\Federation\Language;
use Agnosis\Network\Federation\Replies;
use Agnosis\Network\Federation\Rhizome;
use Agnosis\Network\Federation\Serialization;
use Agnosis\Core\Logger;
use Agnosis\Core\Turnstile;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use WP_Error;

class ActivityPub {

	/**
	 * The eight public reply constants, aliased from their owner (Q-2, WP5).
	 *
	 * They now live on `Network\Federation\Replies`, which is the class that
	 * reads and writes every one of them. They are re-declared here because
	 * Invariant 1 of the split plan requires `ActivityPub::REPLY_COMMENT_TYPE`
	 * and its siblings to keep resolving: `Core\Privacy` reads three of them for
	 * the G-5 reply exporter/eraser, `Artist\ContentEditor` and
	 * `Publishing\ReviewConfirm` read others, and eight test files reference
	 * them by this name. An alias costs nothing and keeps a single source of
	 * truth — change the value on `Replies` and it changes here.
	 */
	public const REPLY_COMMENT_TYPE             = Replies::REPLY_COMMENT_TYPE;
	public const LOCAL_REPLY_COMMENT_TYPE       = Replies::LOCAL_REPLY_COMMENT_TYPE;
	public const REPLY_COMMENT_TYPES            = Replies::REPLY_COMMENT_TYPES;
	public const REPLY_TRANSLATED_CONTENT_META  = Replies::REPLY_TRANSLATED_CONTENT_META;
	public const REPLY_TRANSLATED_PRIMARY_META  = Replies::REPLY_TRANSLATED_PRIMARY_META;
	public const REPLY_SOURCE_LANG_META         = Replies::REPLY_SOURCE_LANG_META;
	public const REPLY_FEDERATE_REQUESTED_META  = Replies::REPLY_FEDERATE_REQUESTED_META;
	public const REPLIES_DISABLED_META          = Replies::REPLIES_DISABLED_META;

	/**
	 * Federation collaborators (sixteenth audit, Q-2 — see
	 * agnosis-audit/ACTIVITYPUB-SPLIT-ROADMAP.md).
	 *
	 * This class is an orchestrator: it builds the units of the federation
	 * subsystem, routes WordPress's hooks and REST routes into them, and holds
	 * the public constants the rest of the plugin references. The behaviour
	 * itself lives in the collaborators.
	 *
	 * **Built on first use, not in a constructor — deliberately (WP4).** The
	 * original WP1 design assigned all of these in `__construct()`. That made the
	 * orchestrator silently unusable to any subclass whose own constructor does
	 * not chain to `parent::__construct()`, which is exactly the shape of the
	 * anonymous `pipeline()`-stubbing subclass used in the test suite: the
	 * properties stayed uninitialized and the first collaborator call fatally
	 * errored. Accessors close that hole at the root, and there was never
	 * anything to gain from eager construction — every collaborator is stateless
	 * and takes no configuration, so building four (soon six) objects up front
	 * only to use one, as `Newsletter\Digest` does, was pure waste.
	 *
	 * **The layering is enforced here**, in the accessors, by what each one is
	 * allowed to pass: `Identity` receives nothing, `Delivery` receives only
	 * `Identity`, and everything above receives only what sits below it. A cycle
	 * is not merely discouraged, it is unwritable.
	 *
	 * A collaborator is not state in the problematic sense (§1 counted zero
	 * instance properties on this class): it is built once, never mutated, and
	 * never reaches back into this class (§0c).
	 *
	 * @var Identity|null
	 */
	private ?Identity $identity = null;

	/**
	 * Federation transport (WP2).
	 *
	 * @var Delivery|null
	 */
	private ?Delivery $delivery = null;

	/**
	 * Node-level relay relationships, inbound and outbound (WP3).
	 *
	 * @var Rhizome|null
	 */
	private ?Rhizome $rhizome = null;

	/**
	 * Likes and boosts, inbound and on-site (WP4).
	 *
	 * @var Interactions|null
	 */
	private ?Interactions $interactions = null;

	/**
	 * The whole reply subsystem — local, federated, gateway, mirroring (WP5).
	 *
	 * @var Replies|null
	 */
	private ?Replies $replies = null;

	/**
	 * AS2 documents for this node's own artworks, and their lifecycle (WP6).
	 *
	 * Constructed last, because it consumes every unit below it.
	 *
	 * @var Serialization|null
	 */
	private ?Serialization $serialization = null;

	/**
	 * Which language a post speaks (WP7). Bottom layer, beside Identity.
	 *
	 * @var Language|null
	 */
	private ?Language $language = null;

	/**
	 * The follower relationship and its Follow button (WP7).
	 *
	 * @var Follows|null
	 */
	private ?Follows $follows = null;

	private function identity(): Identity {
		return $this->identity ??= new Identity();
	}

	private function delivery(): Delivery {
		return $this->delivery ??= new Delivery( $this->identity() );
	}

	private function rhizome(): Rhizome {
		return $this->rhizome ??= new Rhizome( $this->identity(), $this->delivery() );
	}

	private function interactions(): Interactions {
		return $this->interactions ??= new Interactions( $this->identity(), $this->delivery() );
	}

	private function language(): Language {
		return $this->language ??= new Language();
	}

	private function follows(): Follows {
		return $this->follows ??= new Follows( $this->identity(), $this->delivery() );
	}

	private function replies(): Replies {
		return $this->replies ??= new Replies(
			$this->identity(),
			$this->language(),
			$this->delivery(),
			$this->interactions(),
			fn (): Pipeline => $this->pipeline()
		);
	}

	private function serialization(): Serialization {
		return $this->serialization ??= new Serialization(
			$this->identity(),
			$this->delivery(),
			$this->interactions(),
			$this->replies(),
			$this->language()
		);
	}

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

		// Interaction-surface roadmap, Phase 3, WP4 — a site visitor without a
		// fediverse account can now reply too (§4 Phase 3A). Modeled line-for-
		// line on Artist\ContactForm::register_routes(): permission_callback
		// is only the coarse per-IP gate, everything else (Turnstile, the
		// per-sender/per-(artist,sender) rate tiers, AI moderation) runs inside
		// submit_reply() itself once the request body is available.
		register_rest_route( 'agnosis/v1', '/content/(?P<id>\d+)/replies', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'submit_reply' ],
			'permission_callback' => [ $this, 'rate_limit_reply' ],
			'args'                => [
				'name'            => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => fn( string $v ): bool|WP_Error => Replies::validate_reply_field_length( $v, Replies::REPLY_MAX_NAME_LENGTH, __( 'Name', 'agnosis' ) ),
				],
				'email'           => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => fn( string $v ): bool => (bool) is_email( $v ),
				],
				'message'         => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => fn( string $v ): bool|WP_Error => Replies::validate_reply_field_length( $v, Replies::REPLY_MAX_MESSAGE_LENGTH, __( 'Reply', 'agnosis' ) ),
				],
				'parent'          => [
					'type'    => 'integer',
					'default' => 0,
				],
				'turnstile_token' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

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

		// Interaction-surface roadmap, Phase 3, WP6 — the dereferenceable AS2
		// id for one federated artist reply (reply_object_id_for()). A plain
		// REST route rather than a template_redirect content-negotiated one
		// like the artwork's own serve_artwork_activity_json(): a comment has
		// no permalink to hang content negotiation off of, so this follows
		// actor()/outbox()/followers()'s own plain-WP_REST_Response
		// convention instead.
		register_rest_route( 'agnosis/v1', '/activitypub/replies/(?P<id>\d+)', array_merge( $args, [ 'methods' => 'GET', 'callback' => [ $this, 'serve_reply_activity_json' ] ] ) );
	}

	// -------------------------------------------------------------------------
	// Identity (WP1) — delegation only; see Network\Federation\Identity
	// -------------------------------------------------------------------------

	/**
	 * Delegates to Network\Federation\Identity (WP1).
	 *
	 * Kept on this class because it is part of the orchestrator's public
	 * surface — a REST callback, a hooked handler, or called from another
	 * class. See ACTIVITYPUB-SPLIT-ROADMAP.md §4 invariant 4.
	 */
	public function actor( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->identity()->actor( $request );
	}

	/**
	 * The actor id/URL for the node, or for one specific artist.
	 *
	 * Public (WP3, interaction-surface roadmap, Phase 3): the newsletter
	 * gateway (Newsletter\InteractionGateway) calls this directly to resolve
	 * an artist's real actor URL from a verified token's artist_id, without
	 * needing a logged-in session (there never is one — the no-login rule).
	 *
	 * **Delegates to Network\Federation\Identity (Q-2, WP1).** The body moved;
	 * this stays because it is part of the orchestrator's public surface — a
	 * REST callback, a hooked handler, or called from another class. Signature
	 * and behaviour are unchanged (ACTIVITYPUB-SPLIT-ROADMAP.md §4).
	 */
	public function actor_url_for( string $owner_type, int $owner_id ): string {
		return $this->identity()->actor_url_for( $owner_type, $owner_id );
	}

	/**
	 * The Fediverse handle string ("nicename@host" / "agnosis@host") for the
	 * node or one specific artist — the display counterpart to
	 * actor_url_for() (which returns the machine id/URL, not something a
	 * person reads or types).
	 *
	 * Always resolves the base/apex domain (`agnosis_base_domain`, falling
	 * back to home_url()'s host exactly like SubdomainRouter::url_for_artist()
	 * does), never the CURRENT request's possibly-rewritten subdomain host —
	 * SubdomainRouter::rewrite_home() repoints home_url() (and everything
	 * derived from it, rest_url() included) at an artist's own subdomain for
	 * the whole request, but per-artist actors deliberately share the node's
	 * own host, not a subdomain (see resolve_webfinger_subject()'s own
	 * docblock: "@artistname@agnosis.art" is what a fediverse user actually
	 * follows). Calling this from a block rendered ON an artist's own
	 * subdomain page (agnosis/site-copyright, agnosis/follow-overlay) would
	 * otherwise silently print a handle that doesn't match what WebFinger
	 * resolves.
	 *
	 * Returns '' when $owner_type is 'artist' and $owner_id doesn't resolve
	 * to a real user.
	 *
	 * **Delegates to Network\Federation\Identity (Q-2, WP1).** The body moved;
	 * this stays because it is part of the orchestrator's public surface — a
	 * REST callback, a hooked handler, or called from another class. Signature
	 * and behaviour are unchanged (ACTIVITYPUB-SPLIT-ROADMAP.md §4).
	 */
	public function handle_for( string $owner_type, int $owner_id = 0 ): string {
		return $this->identity()->handle_for( $owner_type, $owner_id );
	}

	// -------------------------------------------------------------------------
	// Inbox — signature verification and activity dispatch
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
				$this->interactions()->record_interaction( $body, strtolower( $type ) );
				if ( 'Announce' === $type ) {
					// RN3 (RHIZOME-NETWORK-ROADMAP.md §3/§8, 2026-07-30): a
					// trusted peer's Announce of something NOT local to this
					// node — previously silently discarded by
					// record_interaction() above finding nothing to attach
					// it to — is now relayed onward to this node's own
					// followers. No-op for every other case (see
					// relay_trusted_announce()'s own docblock).
					$this->rhizome()->relay_trusted_announce( $body );
				}
				do_action( 'agnosis_activity_received', $body );
				return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
			case 'Undo':
				return $this->handle_undo( $body );
			case 'Create':
				// Interaction-surface roadmap, Phase 2 (2026-07-25): a remote
				// reply to a federated artwork. See handle_create_reply()'s
				// own docblock for the full gating order.
				return $this->replies()->handle_create_reply( $body );
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

	// -------------------------------------------------------------------------
	// Identity (WP1) — WebFinger delegation
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
	 *
	 * **Delegates to Network\Federation\Identity (Q-2, WP1).** The body moved;
	 * this stays because it is part of the orchestrator's public surface — a
	 * REST callback, a hooked handler, or called from another class. Signature
	 * and behaviour are unchanged (ACTIVITYPUB-SPLIT-ROADMAP.md §4).
	 */
	public function handle_webfinger(): void {
		$this->identity()->handle_webfinger();
	}

	/**
	 * Resolve a WebFinger `resource` param to its JRD response body, or null
	 * when unresolvable. Split from handle_webfinger() so the resolution
	 * logic is directly testable without the exit — mirrors
	 * singular_activity_json()'s existing split from its own exit-wrapper
	 * (serve_artwork_activity_json()) elsewhere in this file.
	 *
	 * @return array{subject: string, links: array<int, array<string, string>>}|null
	 *
	 * **Delegates to Network\Federation\Identity (Q-2, WP1).** The body moved;
	 * this stays because it is part of the orchestrator's public surface — a
	 * REST callback, a hooked handler, or called from another class. Signature
	 * and behaviour are unchanged (ACTIVITYPUB-SPLIT-ROADMAP.md §4).
	 */
	public function resolve_webfinger_subject( string $webfinger_resource ): ?array {
		return $this->identity()->resolve_webfinger_subject( $webfinger_resource );
	}

	// -------------------------------------------------------------------------
	// Inbox activity handlers — parse, then hand to the owning unit
	// -------------------------------------------------------------------------

	/**
	 * Inbox dispatch for `Follow` — the follower relationship itself lives in
	 * Network\Federation\Follows (Q-2, WP7).
	 *
	 * @param array<string, mixed> $body
	 */
	private function handle_follow( array $body ): WP_REST_Response {
		$this->follows()->accept_follow( $body );

		return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
	}

	/** @param array<string, mixed> $body */
	private function handle_undo( array $body ): WP_REST_Response {
		$object      = $body['object'] ?? [];
		$object_type = $object['type'] ?? '';

		if ( 'Follow' === $object_type ) {
			$this->follows()->undo_follow( $body );
		} elseif ( 'Like' === $object_type || 'Announce' === $object_type ) {
			// Interaction-surface roadmap, Phase 1 (2026-07-24) — see
			// undo_interaction()'s own docblock.
			$this->interactions()->undo_interaction( $body, strtolower( $object_type ) );
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
			$this->follows()->forget_actor( $actor );
		} else {
			// Not a self-account-delete — check whether it's a remote actor
			// deleting one of their own previously-stored federated replies
			// (interaction-surface roadmap, Phase 2 step 4).
			$this->replies()->maybe_delete_reply( $body );
		}

		return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
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
	 *
	 * **Delegates to Network\Federation\Delivery (Q-2, WP2).** Kept here
	 * because it is the `agnosis_ap_retry_deliveries` cron callback wired in
	 * Core\Plugin, and is driven directly by ActivityPubTest.
	 */
	public function process_delivery_retry_queue(): void {
		$this->delivery()->process_delivery_retry_queue();
	}

	// -------------------------------------------------------------------------
	// Rhizome (WP3) — delegation only; see Network\Federation\Rhizome
	// -------------------------------------------------------------------------

	/**
	 * Retention sweep for `agnosis_rhizome_relay_log` — sixteenth audit, G-4
	 * (2026-07-31). Hooked to the existing daily `agnosis_cleanup_inbox`, the
	 * same cron `Logger::prune()` and `ContactForm::prune_old_messages()`
	 * already run on, rather than adding a fifteenth cron hook for one DELETE.
	 *
	 * RN3b's log records the *announcing remote actor's id* on every relayed
	 * peer boost — a direct identifier of a natural person in most cases — and
	 * shipped in 0.9.65 with no pruning, no cap and no retention statement,
	 * the only content- or identity-bearing table in the plugin without one
	 * (`agnosis_log` is pruned here, `agnosis_queue` at 7 days, contact
	 * messages at 90). It is also unbounded operationally: the roadmap sized
	 * the rhizome as single-digit-peer-scale, which bounds the number of
	 * PEERS, not the number of relays a busy peer generates.
	 *
	 * The default is deliberately 90 days rather than something tighter: the
	 * only reader is `Newsletter\Digest::rhizome_activity_summary()`, which
	 * only ever queries `relayed_at > $since` for one digest window, so 90
	 * days covers the default 30-day artist-digest cadence three times over
	 * and pruning past it costs the digest nothing.
	 *
	 * **Delegates to Network\Federation\Rhizome (Q-2, WP3).** Kept here
	 * because it is hooked to `agnosis_cleanup_inbox` against this object in
	 * Core\Plugin.
	 */
	public function prune_relay_log(): void {
		$this->rhizome()->prune_relay_log();
	}

	/**
	 * Subscribe to a relay (WP8) — Agnosis could already receive an inbound
	 * Follow (handle_follow()) but had no code path to send one; this is
	 * that path. A relay typically re-broadcasts every Follow it accepts to
	 * every OTHER subscriber, which is the entire point: it is the
	 * cheapest real discoverability mechanism that works with the
	 * fediverse exactly as deployed today, no FASP or new protocol needed.
	 *
	 * Best-effort and silent on failure to resolve the relay's own inbox —
	 * same tolerance federate_artist_reply()'s own resolve_inbox() call
	 * already has; an admin adding a malformed or unreachable relay URL
	 * gets no fatal error, just no subscription.
	 *
	 * **Delegates to Network\Federation\Rhizome (Q-2, WP3).** Kept here
	 * because Admin\Dashboards\RelayManager calls it on this class, as does
	 * ActivityPubRelayTest.
	 */
	public function follow_relay( string $relay_actor_url ): void {
		$this->rhizome()->follow_relay( $relay_actor_url );
	}

	/**
	 * Unsubscribe from a relay (WP8) — sent when an admin disables or
	 * removes a previously-added relay, so leaving is clean rather than
	 * just going quiet on our own end while the relay keeps us subscribed
	 * indefinitely.
	 *
	 * **Delegates to Network\Federation\Rhizome (Q-2, WP3).** Kept here for
	 * the same reason as follow_relay() above.
	 */
	public function unfollow_relay( string $relay_actor_url ): void {
		$this->rhizome()->unfollow_relay( $relay_actor_url );
	}

	// -------------------------------------------------------------------------
	// Interactions (WP4) — delegation only; see Network\Federation\Interactions
	// -------------------------------------------------------------------------

	/**
	 * Like/boost counts for one artwork (interaction-surface roadmap, Phase
	 * 1). Used both by the on-site agnosis/interaction-counts display block
	 * and by post_to_note()'s outbound likesCount/sharesCount.
	 *
	 * **Delegates to Network\Federation\Interactions (Q-2, WP4).** Called by `post_to_note()` here and directly by ActivityPubTest.
	 *
	 * @param int $post_id Artwork post id.
	 * @return array{like: int, announce: int}
	 */
	public function interaction_counts( int $post_id ): array {
		return $this->interactions()->interaction_counts( $post_id );
	}

	/**
	 * Register the agnosis/interaction-counts dynamic block.
	 *
	 * block.json lives in blocks/interaction-counts/ relative to the plugin
	 * root — same directory-registration shape as agnosis/artwork-copyright
	 * (Artist\Profile::register_blocks()), chosen over a bare
	 * register_block_type() call so an admin gets real Color/Typography
	 * Inspector controls for what is otherwise a plain server-rendered string.
	 *
	 * **Delegates to Network\Federation\Interactions (Q-2, WP4).** Hooked to `init` against this object in Core\Plugin.
	 */
	public function register_interaction_counts_block(): void {
		$this->interactions()->register_interaction_counts_block();
	}

	/**
	 * Aggregate like/boost counts across ALL of one artist's own artwork,
	 * since a given digest window — NL1 (RHIZOME-NETWORK-ROADMAP.md §11a,
	 * 2026-07-30). Unlike interaction_counts() above (one post, all time),
	 * this is one artist across every post they own, scoped to a time
	 * window — the per-recipient personalization
	 * Newsletter\Digest::build_artist()'s shared, once-per-locale render
	 * can't compute itself (see that class's own
	 * `{{AGNOSIS_INTERACTION_SUMMARY}}` placeholder note); resolved later,
	 * per recipient, at actual send time
	 * (Digest::substitute_interaction_summary(), called from
	 * QueueProcessor::send_one()).
	 *
	 * **Delegates to Network\Federation\Interactions (Q-2, WP4).** Called by Newsletter\Digest on a fresh instance of this class.
	 *
	 * @return array{like: int, announce: int}
	 */
	public function personal_interaction_counts( int $artist_id, string $since ): array {
		return $this->interactions()->personal_interaction_counts( $artist_id, $since );
	}

	/**
	 * REST `permission_callback` for the likes toggle routes — coarse per-IP gate, same convention as every other public-write route in this codebase.
	 *
	 * **Delegates to Network\Federation\Interactions (Q-2, WP4).** It is the `permission_callback` for both like routes in `register_routes()`.
	 */
	public function rate_limit_like(): bool|WP_Error {
		return $this->interactions()->rate_limit_like();
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
	 *
	 * Public (WP3, interaction-surface roadmap, Phase 3): the newsletter
	 * gateway (Newsletter\InteractionGateway) reuses this exact same
	 * resolution for a PUBLIC-newsletter subscriber's like click — they have
	 * no actor either, so their click is identified exactly like any other
	 * anonymous on-site visitor, resolved fresh from whatever request is
	 * actually making the click, not from anything encoded in the emailed
	 * link's token.
	 *
	 * **Delegates to Network\Federation\Interactions (Q-2, WP4).** Called by Newsletter\InteractionGateway.
	 */
	public function like_identity(): string {
		return $this->interactions()->like_identity();
	}

	/**
	 * Is $post_id a real agnosis_artwork? Shared guard for both like routes,
	 * and (WP3, public) for the newsletter gateway's confirm page, which
	 * needs the same 404 semantics before it ever renders a token-authenticated
	 * "Like this artwork?" confirm page.
	 *
	 * **Delegates to Network\Federation\Interactions (Q-2, WP4).** Called by `repliable_artwork()` here and by Newsletter\InteractionGateway.
	 */
	public function likeable_artwork( int $post_id ): \WP_Post|WP_Error {
		return $this->interactions()->likeable_artwork( $post_id );
	}

	/**
	 * POST /agnosis/v1/content/{id}/likes — record a like from the current
	 * visitor/artist identity (like_identity()). Idempotent on repeat calls
	 * from the same identity, same $wpdb->replace() pattern record_interaction()
	 * already uses for a redelivered remote Like — a double-click just
	 * refreshes received_at rather than erroring or double-counting.
	 *
	 * **Delegates to Network\Federation\Interactions (Q-2, WP4).** REST `callback` for the like route in `register_routes()`.
	 */
	public function like_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->interactions()->like_content( $request );
	}

	/**
	 * DELETE /agnosis/v1/content/{id}/likes — remove the current visitor/
	 * artist identity's own like, if any. A no-op (not an error) when that
	 * identity never had one — same "undo of something already undone is
	 * fine" tolerance undo_interaction() already has for a re-delivered
	 * Undo{Like}.
	 *
	 * **Delegates to Network\Federation\Interactions (Q-2, WP4).** REST `callback` for the unlike route in `register_routes()`.
	 */
	public function unlike_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->interactions()->unlike_content( $request );
	}

	/**
	 * Record or remove a like under an EXPLICITLY given actor_id.
	 *
	 * Public — used by like_content()/unlike_content() above (which resolve
	 * $actor_id themselves via like_identity(), reading the CURRENT request)
	 * AND by the newsletter gateway (Newsletter\InteractionGateway, WP3),
	 * which already knows the acting identity from a verified emailed-link
	 * token and must NOT re-resolve it from the current request — the
	 * artist clicking that link is, by design (the no-login rule), not
	 * logged in, so like_identity()'s own is_user_logged_in() branch would
	 * never fire for them anyway.
	 *
	 * **Delegates to Network\Federation\Interactions (Q-2, WP4).** Called by Newsletter\InteractionGateway.
	 *
	 * @return array{liked: bool, like: int}
	 */
	public function write_like( int $post_id, string $actor_id, bool $liked ): array {
		return $this->interactions()->write_like( $post_id, $actor_id, $liked );
	}

	/**
	 * agnosis_rotate_like_salt cron callback (daily) — overwrites the salt
	 * used by like_identity()'s anonymous-visitor hash. Unconditional
	 * update_option(), not add_option(): the whole point is that the
	 * previous value is gone once this runs, not preserved if already set
	 * (§7 Q5's explicit "no previous salt retained").
	 *
	 * **Delegates to Network\Federation\Interactions (Q-2, WP4).** The `agnosis_rotate_like_salt` cron callback wired in Core\Plugin.
	 */
	public function rotate_like_salt(): void {
		$this->interactions()->rotate_like_salt();
	}

	/**
	 * Record or remove a LOCAL boost under an explicit booster artist id
	 * (§4 Phase 3E). Unlike write_like() — which accepts a pre-resolved
	 * $actor_id string usable by either an artist OR an anonymous visitor —
	 * a boost is only ever performable by an Agnosis artist (§4 3E step 1: a
	 * boost is republication under a real identity, unlike a like, so
	 * anonymous boosting was never on the table). This takes the artist's
	 * own real WP user id directly and resolves their actor from it, rather
	 * than accepting an arbitrary caller-supplied actor string.
	 *
	 * Self-boost is deliberately permitted (§4 3E step 1: re-surfacing one's
	 * own older work to one's own followers is legitimate, and Mastodon
	 * itself allows it) — no special-case guard here for $artist_id being
	 * the artwork's own author.
	 *
	 * Reached only via the artist-newsletter gateway link today
	 * (Newsletter\InteractionGateway) — there is no on-site UI for this (the
	 * on-site interaction-counts boost count is deliberately not a button,
	 * unlike the like heart; Ulises never asked for one, and none is built
	 * here — see agnosis-audit/INTERACTION-SURFACE-ROADMAP.md WP5's own
	 * note on this being a known, disclosed gap rather than a silent one).
	 *
	 * **Delegates to Network\Federation\Interactions (Q-2, WP4).** Called by Newsletter\InteractionGateway, and driven directly by ActivityPubBoostTest.
	 *
	 * @return array{boosted: bool, announce: int}
	 */
	public function write_boost( int $post_id, int $artist_id, bool $boosted ): array {
		return $this->interactions()->write_boost( $post_id, $artist_id, $boosted );
	}

	// -------------------------------------------------------------------------
	// Replies (WP5) — delegation only; see Network\Federation\Replies
	// -------------------------------------------------------------------------

	/**
	 * The AI Pipeline factory `Replies` is constructed with.
	 *
	 * Kept on the orchestrator rather than moved with the reply subsystem
	 * (WP5), because it is a deliberate test seam: an anonymous subclass
	 * overrides it to stub `classify_text()` without a real AI provider, the
	 * same convention Artist\ContactForm uses. Moving it into `Replies` would
	 * have left those overrides silently ineffective and pointed reply
	 * moderation at a live provider during tests. `replies()` passes it in as
	 * a closure, so `Replies` never has to know where its Pipeline comes from.
	 */
	protected function pipeline(): Pipeline {
		return new Pipeline();
	}

	/**
	 * Public, unauthenticated read of one artwork's approved replies — feeds
	 * the agnosis/reply-overlay block's own fetch, not a general-purpose
	 * comments API. Federated AND local replies both count (WP4).
	 *
	 * WP4 (§4 Phase 3A step 6): serves whichever stored version matches the
	 * CURRENT post's own LF language — resolve_post_lf_lang( $post_id ), the
	 * same "this post's own _lf_lang, or the site's primary language when
	 * unset" signal used throughout this class — rather than always the
	 * artist's own language the way Phase 2 shipped it (correct for the
	 * artist's own moderation email, not for a general-audience public page).
	 * See display_reply_content()'s own docblock for the exact fallback order.
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** REST `callback` for the public replies read in `register_routes()`.
	 */
	public function get_replies( WP_REST_Request $request ): WP_REST_Response {
		return $this->replies()->get_replies( $request );
	}

	/**
	 * POST /agnosis/v1/content/{id}/replies — a site visitor's own reply,
	 * with no fediverse account and no WP login (§4 Phase 3A). Modeled
	 * line-for-line on Artist\ContactForm::submit()'s four-part shape:
	 * Turnstile → per-post/per-artist gate → sender rate tiers → AI
	 * moderation → store held, notify the artist via the exact same
	 * one-click Approve/Reject flow Phase 2 already built
	 * (notify_artist_of_reply(), $is_local = true for the right email copy).
	 *
	 * A rejected message and a stored one get an IDENTICAL response (see
	 * ContactForm's own docblock for the same discipline) — the visitor
	 * always sees success, so the response itself can never be used as an
	 * oracle to probe what the content filter blocks. Structural gates
	 * (no such artwork, replies switched off) are NOT covered by that
	 * discipline — those return their own distinct errors, same as
	 * ContactForm's contactable_artist() 404.
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** REST `callback` for the reply-submit route, and driven directly by ActivityPubLocalReplyTest.
	 */
	public function submit_reply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->replies()->submit_reply( $request );
	}

	/**
	 * REST `permission_callback` for the reply form — coarse per-IP gate, checked before Turnstile/DB work, same convention as Artist\ContactForm::rate_limit().
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** REST `permission_callback` for the reply-submit route.
	 */
	public function rate_limit_reply(): bool|WP_Error {
		return $this->replies()->rate_limit_reply();
	}

	/**
	 * GET /agnosis/v1/activitypub/replies/{id} — the dereferenceable AS2 id
	 * for one federated artist reply (WP6). Returns the live Note (200), a
	 * Tombstone (410) once removed, or a 404 for anything else — an
	 * ordinary visitor reply, an artist reply that was never federated, or a
	 * comment id that isn't a reply at all. No attempt is made to
	 * distinguish those 404 cases in the response: unlike likeable_artwork()
	 * there's no secrecy concern here, it's simply that none of them have a
	 * real object to serve.
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** REST `callback` for the per-reply AS2 route.
	 */
	public function serve_reply_activity_json( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->replies()->serve_reply_activity_json( $request );
	}

	/**
	 * Register the agnosis/reply-overlay dynamic block — a "N replies"
	 * trigger that opens a native-Popover-API panel (same mechanism as
	 * Newsletter\PopoverBlock's subscribe popover; no bespoke modal JS/CSS
	 * invented for this) fetching get_replies() once opened.
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** Hooked to `init` against this object in Core\Plugin.
	 */
	public function register_reply_overlay_block(): void {
		$this->replies()->register_reply_overlay_block();
	}

	/**
	 * Register the template_redirect handler for the gateway link above.
	 * Called from Core\Plugin, same as every other stateless-token flow.
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** Called directly from Core\Plugin during boot.
	 */
	public function register_reply_moderation_handler(): void {
		$this->replies()->register_reply_moderation_handler();
	}

	/**
	 * `admin_comment_types_dropdown` filter (WP4, §4 Phase 3A step 10) — adds
	 * "Fediverse replies" and "Site replies" as filter options in the
	 * Comments → All screen's own type dropdown (`WP_Comments_List_Table::
	 * comment_type_dropdown()`), so an admin can isolate either kind without
	 * digging through every comment type on a busy site. Admin moderation of
	 * BOTH types already works today with no other build at all — the list
	 * table's default query applies no type filter, and neither does
	 * `get_comment_count()` (verified against the core checkout, §4 Phase 3A's
	 * own "free wins" note) — this is purely a filtering convenience layered
	 * on top of an already-working feature, not a prerequisite for it.
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** Filters `admin_comment_types_dropdown` against this object in Core\Plugin.
	 *
	 * @param array<string, string> $types Comment type labels keyed by slug.
	 * @return array<string, string>
	 */
	public function add_reply_type_filters( array $types ): array {
		return $this->replies()->add_reply_type_filters( $types );
	}

	/**
	 * WP-Cron drain for the reply-translation queue —
	 * `agnosis_drain_reply_translation_queue`, `every_five_minutes` (mirrors
	 * Compat\LinguaForge::drain_translation_queue()'s own shape: walk every
	 * row still flagged pending, time-budgeted, resumable). Translation
	 * happens here, off the signed inbox()/submit_reply()/reply-gateway
	 * request path (roadmap §4 Phase 2 step 8) — the caller already returned
	 * a response the moment the comment was inserted; this only ever refines
	 * what get_replies() and the federated Note later serve. Federated AND
	 * local replies, AND (since WP13) an artist's OWN reply, all share this
	 * one queue.
	 *
	 * Three-version model (§4 Phase 3A step 6 / §7 Q4, both directions):
	 * every reply gets a source (untouched), a site-primary-language
	 * translation (REPLY_TRANSLATED_PRIMARY_META), and one more
	 * general-purpose translation (REPLY_TRANSLATED_CONTENT_META) whose
	 * TARGET depends on which direction this comment is:
	 *   - INBOUND (a visitor's or a remote actor's reply, `user_id` unset —
	 *     handled directly in this method, below): the target is the
	 *     ARTIST'S own language, so they can read and judge it before
	 *     approving — Phase 2/WP4's original meaning of this field.
	 *   - OUTBOUND (WP13 — an artist's own reply, written via the reply
	 *     gateway, always carrying a real `user_id`): delegated to
	 *     drain_outbound_reply_translation() below. The target is the
	 *     ORIGINAL COMMENTER'S own language instead, so the person the
	 *     artist is actually answering can read the reply — see that
	 *     method's own docblock.
	 * Both directions reuse the exact same three meta constants and the
	 * exact same display_reply_content()/get_replies() read path with no
	 * changes there at all — see REPLY_SOURCE_LANG_META's own docblock for
	 * why that works.
	 *
	 * Efficiencies, required either direction: when two of the three
	 * languages in play coincide, the already-computed result is reused (or
	 * the call is skipped and the meta left `''`) rather than spending a
	 * second identical AI call — display_reply_content()'s own fallback
	 * order already resolves an unset/empty meta to whichever OTHER stored
	 * version is actually correct for that case.
	 *
	 * WP13 §13.2/13.3: for a FEDERATED (remote) inbound reply whose own
	 * language isn't yet known (REPLY_SOURCE_LANG_META unset), and only when
	 * `agnosis_federate_languages` is `all` (see that constant's own
	 * docblock for why the default `primary-only` needs no detection at
	 * all), this method calls SubmissionTranslator::detect_language() once
	 * before anything else — an undetectable/unsupported result short-
	 * circuits straight to notify_artist_of_unsupported_reply_language()
	 * instead of the normal translation+notification flow (§13.5).
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** The `agnosis_drain_reply_translation_queue` cron callback wired in Core\Plugin.
	 */
	public function drain_reply_translation_queue(): void {
		$this->replies()->drain_reply_translation_queue();
	}

	/**
	 * Hook callback: 'transition_comment_status' fires for wp_set_comment_status()
	 * AND wp_trash_comment()/wp_untrash_comment()/wp_spam_comment() alike
	 * (WP6) — the admin path a previously-federated artist reply is removed
	 * through, even though the artist who authored it never logs in and so
	 * can never trigger this themselves. Only 'trash' counts as removal
	 * here; every other transition (including back OUT of trash) is a
	 * no-op — re-approving out of trash does not currently re-federate,
	 * matching Create's own "no re-publish on undo" behavior elsewhere in
	 * this class.
	 *
	 * RLM2/RLM3 (REPLY-LANGUAGE-MIRRORING-ROADMAP.md) widen this same
	 * callback with two more branches: 'approved' triggers
	 * mirror_reply_across_languages() (an artist approving a reply, even
	 * without answering, is the signal that it should now also appear on
	 * whichever of the reply's source/primary/artist-native sibling posts
	 * actually exist), and 'trash' — already handled below for federation
	 * cleanup — also cascades a delete to every mirror sharing this reply's
	 * group id. Per core's own wp_transition_comment_status(), $new_status
	 * here is the human-readable, already-translated form ('approved', not
	 * 'approve' — see that function's own $comment_statuses map; only
	 * 'approve'/'hold'/0/1 get translated, 'trash'/'spam' pass through
	 * unchanged, which is why the check just below still reads literal
	 * 'trash').
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** Hooked to `transition_comment_status` in Core\Plugin.
	 */
	public function handle_reply_status_transition( string $new_status, string $old_status, \WP_Comment $comment ): void {
		$this->replies()->handle_reply_status_transition( $new_status, $old_status, $comment );
	}

	/**
	 * Hook callback: 'delete_comment' fires for a hard/force delete that
	 * bypasses trash entirely (e.g. wp_delete_comment( $id, true )) — a case
	 * 'transition_comment_status' above never sees, since that path never
	 * calls wp_set_comment_status() at all (WP6).
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** Hooked to `delete_comment` in Core\Plugin.
	 */
	public function handle_reply_hard_delete( int $comment_id ): void {
		$this->replies()->handle_reply_hard_delete( $comment_id );
	}

	/**
	 * Suppress WordPress core's own native comment-notification emails
	 * (`comment_notification_recipients`/`comment_moderation_recipients`)
	 * for Agnosis's own reply comment types — local and federated alike.
	 * Every one of these already gets its own fully branded, translated,
	 * gateway-linked notification from notify_artist_of_reply() once
	 * drain_reply_translation_queue() resolves it (see that method's own
	 * docblock). Core's raw "New comment on ..." notice firing on top of
	 * that isn't just redundant — it fires SYNCHRONOUSLY at comment-insert
	 * time (the `comment_post` action), so it always reaches the artist
	 * BEFORE our own translated version does, in whichever language core's
	 * admin-area locale happens to be, with no gateway link at all. Exactly
	 * the "plain-text-before-translation" problem notify_artist_of_reply()
	 * itself was already fixed to avoid (0.9.59) — just via a second,
	 * entirely separate code path this plugin doesn't own.
	 *
	 * Found 2026-07-28: a live artist received exactly this raw core email
	 * for a held reply, on top of our own branded one.
	 * `agnosis_artist` has no `moderate_comments` capability, so
	 * `wp_notify_moderator()` was already correctly reasoned as a non-issue
	 * (see notify_artist_of_reply()'s own docblock) — but
	 * `wp_notify_postauthor()` needs no capability at all, only that the
	 * POST AUTHOR's own account has "Email me whenever anyone posts a
	 * comment" enabled in Settings -> Discussion, which is exactly what
	 * fired here. Filtered to an empty recipient list ONLY for
	 * REPLY_COMMENT_TYPES — an ordinary WordPress comment on any other post
	 * type (should this site ever have one) is completely unaffected.
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** Filters both `comment_notification_recipients` and `comment_moderation_recipients` in Core\Plugin.
	 *
	 * @param array<int, string> $emails     Recipients core is about to notify.
	 * @param int                $comment_id
	 * @return array<int, string>
	 */
	public function suppress_native_reply_notifications( array $emails, int $comment_id ): array {
		return $this->replies()->suppress_native_reply_notifications( $emails, $comment_id );
	}

	/**
	 * RLM4 (REPLY-LANGUAGE-MIRRORING-ROADMAP.md §4 Q4): hook callback for
	 * 'edit_comment' — when the CANONICAL row of a mirrored reply has its
	 * own text edited (an artist/admin correcting it via wp-admin), every
	 * existing mirror's stored translation is regenerated from the NEW text
	 * and pushed to the mirror row itself, so mirrors never silently drift
	 * from a corrected original.
	 *
	 * Deliberately one-directional, same convention as
	 * cascade_delete_reply_group(): editing an individual MIRROR's own text
	 * does not cascade back to the canonical or sideways to other mirrors —
	 * §4 Q2 answered mirrors as fully interactive replies in their own
	 * right, not read-only reflections, so a mirror's own text is treated
	 * the same way any other approved reply's text would be once it exists.
	 *
	 * wp_update_comment() (called below, once per mirror) itself re-fires
	 * 'edit_comment' for that mirror — verified safe against recursion by
	 * this same "only a canonical row's own group id equals its own id"
	 * guard every other reply-group method here uses; a mirror's group id
	 * always differs from its own id, so the second-level call returns
	 * immediately.
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** Hooked to `edit_comment` in Core\Plugin.
	 */
	public function handle_reply_content_edit( int $comment_id ): void {
		$this->replies()->handle_reply_content_edit( $comment_id );
	}

	/**
	 * RLM9 (REPLY-LANGUAGE-MIRRORING-ROADMAP.md §4 Q1): once Lingua Forge
	 * creates or re-translates a sibling into $target_lang, back-fill any
	 * already-approved reply anywhere in that artwork's translation group
	 * whose own three-language target set includes $target_lang but didn't
	 * yet have a mirror there — most commonly a reply approved before this
	 * sibling existed. Ulises's own answer: "we want additional
	 * translations... to show up... right now we conform with our three
	 * language approach" — i.e. this backfills a newly-available one of the
	 * SAME three slots, never an expansion beyond them (that's RLM8, gated
	 * on RLM7).
	 *
	 * Hooked on 'linguaforge_translation_complete' (fires on both creation
	 * AND re-translation — see Compat\LinguaForge::copy_translated_meta()'s
	 * own docblock for that same fact), so this may run more than once for
	 * the same sibling; mirror_reply_across_languages()'s own idempotency
	 * guard (insert_reply_mirror()) makes every re-run beyond the first a
	 * cheap no-op.
	 *
	 * Sweeps EVERY post in the artwork's translation group, not just
	 * $source_id/$translated_id — a reply could be canonical on any
	 * sibling, not only the two this specific firing names. Processes
	 * canonical replies oldest-first: since a reply can only ever be
	 * submitted after its own parent already exists, this ordering
	 * guarantees a parent's mirror on the new sibling is attempted before
	 * its children's, at any nesting depth, in this same sweep.
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** Hooked to `linguaforge_translation_complete` in Core\Plugin.
	 */
	public function backfill_reply_mirrors_for_new_sibling( int $translated_id, int $source_id, string $target_lang ): void {
		$this->replies()->backfill_reply_mirrors_for_new_sibling( $translated_id, $source_id, $target_lang );
	}

	/**
	 * Handle a click on the gateway link from notify_artist_of_reply(). No WP
	 * nonce — this is an unauthenticated email-link recipient with no WP
	 * session; the HMAC token plays the nonce's role, same as
	 * NotificationPreferences/VouchConfirm/AdmissionConfirm.
	 *
	 * WP0 fix (agnosis-audit/INTERACTION-SURFACE-ROADMAP.md §7a/§8): this used
	 * to act on a bare GET — approving or trashing the comment the instant
	 * the link was fetched, with no confirmation step at all. Corporate
	 * mail-security scanners (Outlook SafeLinks, Mimecast, Proofpoint, etc.)
	 * prefetch links in incoming email to scan them, issuing a GET and never
	 * clicking anything — so the prefetch alone was enough to silently
	 * approve or trash a held reply before the artist ever saw the email.
	 * The GET/POST split below still holds under WP7's richer page:
	 *
	 *   GET  → token+expiry verified, comment existence verified, then the
	 *          gateway page renders (original + translated text, Approve/
	 *          Reject, an optional reply textarea, an optional federate
	 *          checkbox). No state change yet, so a scanner's prefetch is
	 *          harmless.
	 *   POST → token+expiry re-verified, then the artist's actual decision
	 *          (reply_action, optional artist_reply, optional federate) is
	 *          applied.
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** The `template_redirect` handler `register_reply_moderation_handler()` installs, and the single most-driven method in the test suite (23 direct calls).
	 */
	public function handle_reply_moderation(): void {
		$this->replies()->handle_reply_moderation();
	}

	/**
	 * Approved reply count for one artwork — federated AND local replies both
	 * count (WP4 widened this from federated-only) — backs the
	 * agnosis/reply-overlay trigger button (render_reply_overlay()). Only
	 * `comment_approved = 1` rows count: an artist who hasn't reviewed a held
	 * reply yet, or rejected one, must never be visible to the public even as
	 * a bare number ("N replies" implies N readable replies).
	 *
	 * **Delegates to Network\Federation\Replies (Q-2, WP5).** Read by `post_to_note()` here and asserted directly by ActivityPubTest.
	 */
	public function reply_count( int $post_id ): int {
		return $this->replies()->reply_count( $post_id );
	}

	// -------------------------------------------------------------------------
	// Replies (WP5) — delegators kept ONLY for the test harness's reflection
	// -------------------------------------------------------------------------

	/**
	 * Nothing in `includes/` calls these four. They exist because four test files
	 * reach them via `ReflectionMethod( ActivityPub::class, … )`, and Invariant 5
	 * of the split plan is that no test file is edited. Reflection made them part
	 * of the tested contract whatever their visibility suggested; delegating is
	 * the honest way to keep that contract while the behaviour lives in
	 * `Network\Federation\Replies`.
	 *
	 * `protected`, not `private`, for the same reason `pipeline()` above is: it
	 * is the visibility this file already uses for "reachable by the test
	 * harness, not part of the public API". It also stops static analysis from
	 * reporting them as dead, which — for a private method only ever called by
	 * reflection — it would be right to do.
	 *
	 * @param int    $post_id            Artwork the reply belongs to.
	 * @param int    $parent_comment_id  Comment being replied to.
	 * @param string $message            The artist's reply text.
	 * @param bool   $federate_requested Whether the artist asked to federate it.
	 */
	protected function store_artist_gateway_reply( int $post_id, int $parent_comment_id, string $message, bool $federate_requested ): void {
		$this->replies()->store_artist_gateway_reply( $post_id, $parent_comment_id, $message, $federate_requested );
	}

	/**
	 * Delegates to Network\Federation\Replies (Q-2, WP5) — see the note above.
	 *
	 * @return array<string, mixed>
	 */
	protected function reply_to_note( \WP_Comment $comment ): array {
		return $this->replies()->reply_to_note( $comment );
	}

	/** Delegates to Network\Federation\Replies (Q-2, WP5) — see the note above. */
	protected function reply_object_id_for( int $comment_id ): string {
		return $this->replies()->reply_object_id_for( $comment_id );
	}

	/** Delegates to Network\Federation\Replies (Q-2, WP5) — see the note above. */
	protected static function reply_gateway_token( int $comment_id ): string {
		return Replies::reply_gateway_token( $comment_id );
	}

	// -------------------------------------------------------------------------
	// Serialization (WP6) — delegation only; see Network\Federation\Serialization
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
	 *
	 * **Delegates to Network\Federation\Serialization (Q-2, WP6).** REST `callback` for both outbox routes in `register_routes()`.
	 */
	public function outbox( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->serialization()->outbox( $request );
	}

	/** **Delegates to Network\Federation\Serialization (Q-2, WP6).** Hooked to `agnosis_federation_settled` in Core\Plugin, and driven directly by eight ActivityPubTest cases. */
	public function broadcast( int $post_id ): void {
		$this->serialization()->broadcast( $post_id );
	}

	/**
	 * transition_post_status handler: federate an artwork leaving `publish`.
	 *
	 * Covers trash (the community removal-vote flow's RemovalEndpoints path
	 * ends in wp_trash_post()), unpublish/draft, and any other transition out
	 * of publish. Transitions INTO publish clear a stale tombstone for the
	 * slug so a restored or re-slugged artwork dereferences again.
	 *
	 * **Delegates to Network\Federation\Serialization (Q-2, WP6).** Hooked to `transition_post_status` in Core\Plugin.
	 */
	public function federate_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		$this->serialization()->federate_status_transition( $new_status, $old_status, $post );
	}

	/**
	 * before_delete_post handler: federate a force-deleted published artwork.
	 *
	 * wp_delete_post() (e.g. Departure's force_delete of a leaving/banned
	 * artist's works) never fires transition_post_status, so the trash-path
	 * hook alone would miss it. A post force-deleted FROM trash was already
	 * tombstoned at trash time and is skipped by the status guard.
	 *
	 * **Delegates to Network\Federation\Serialization (Q-2, WP6).** Hooked to `before_delete_post` in Core\Plugin.
	 */
	public function federate_force_delete( int $post_id ): void {
		$this->serialization()->federate_force_delete( $post_id );
	}

	/**
	 * post_updated handler: federate a meaningful edit of a published artwork.
	 *
	 * "Meaningful" = title, content, or excerpt changed (ContentEditor's
	 * title/text edits land here via wp_update_post()). Both sides must be
	 * `publish` — that also keeps the wp_trash_post()-internal update from
	 * double-firing next to the Delete.
	 *
	 * **Delegates to Network\Federation\Serialization (Q-2, WP6).** Hooked to `post_updated` in Core\Plugin.
	 */
	public function federate_update( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		$this->serialization()->federate_update( $post_id, $post_after, $post_before );
	}

	/**
	 * updated_post_meta / added_post_meta handler: a replaced or newly set
	 * featured image on a published artwork is a meaningful edit too —
	 * ContentEditor's photo replacement goes through set_post_thumbnail(),
	 * which never fires post_updated.
	 *
	 * **Delegates to Network\Federation\Serialization (Q-2, WP6).** Hooked to both `updated_post_meta` and `added_post_meta` in Core\Plugin.
	 */
	public function federate_thumbnail_update( int $meta_id, int $post_id, string $meta_key ): void {
		$this->serialization()->federate_thumbnail_update( $meta_id, $post_id, $meta_key );
	}

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
	 *
	 * **Delegates to Network\Federation\Serialization (Q-2, WP6).** Hooked to `template_redirect` in Core\Plugin.
	 */
	public function serve_artwork_activity_json(): void {
		$this->serialization()->serve_artwork_activity_json();
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
	 * **Delegates to Network\Federation\Serialization (Q-2, WP6).** Asserted directly by ActivityPubTest.
	 *
	 * @return string|null JSON payload, or null to let the theme render HTML.
	 */
	public function singular_activity_json(): ?string {
		return $this->serialization()->singular_activity_json();
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
	 * **Delegates to Network\Federation\Serialization (Q-2, WP6).** Asserted directly by ActivityPubTest.
	 *
	 * @return string|null JSON payload (serve with HTTP 410), or null.
	 */
	public function tombstone_activity_json(): ?string {
		return $this->serialization()->tombstone_activity_json();
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
	 * **Delegates to Network\Federation\Serialization (Q-2, WP6).** Reached only by `ReflectionMethod( ActivityPub::class, 'post_to_note' )` in three test files — `protected` for the same reason WP5's four are; see that section's note.
	 *
	 * @return array<string, mixed>
	 */
	protected function post_to_note( \WP_Post $post ): array {
		return $this->serialization()->post_to_note( $post );
	}

	// -------------------------------------------------------------------------
	// Identity / Follows (WP7) — NodeInfo and the follower collection
	// -------------------------------------------------------------------------

	/**
	 * The NodeInfo 2.0 document itself — static, cheap, and the thing that
	 * makes this node visible to the Fediverse's own observatories/census
	 * tools, which was the whole point of the audit's note (an Agnosis node
	 * was previously invisible to that ecosystem even while being a working
	 * federation participant). `usage.users.total` now genuinely means
	 * something once per-artist actors exist — each admitted artist is a
	 * distinct fediverse-followable "user", not just an internal role.
	 *
	 * **Delegates to Network\Federation\Identity (Q-2, WP7).** NodeInfo moved there to sit beside the node actor and WebFinger — the same discovery family. Kept here as the REST `callback` for the nodeinfo route, and asserted directly by ActivityPubTest.
	 */
	public function nodeinfo(): WP_REST_Response {
		return $this->identity()->nodeinfo();
	}

	/**
	 * /.well-known/nodeinfo — points at the versioned document below. Kept as
	 * its own tiny discovery doc per spec, mirroring WebFinger's own
	 * well-known-rewrite-rule pattern in register_routes().
	 *
	 * **Delegates to Network\Federation\Identity (Q-2, WP7).** Kept here because `register_routes()` hooks it to `template_redirect` against this object.
	 */
	public function handle_nodeinfo_discovery(): void {
		$this->identity()->handle_nodeinfo_discovery();
	}

	/** **Delegates to Network\Federation\Follows (Q-2, WP7).** Kept here as the REST `callback` for both followers routes, and driven directly by ActivityPubTest. */
	public function followers( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->follows()->followers( $request );
	}

	/**
	 * Register the agnosis/follow-overlay dynamic block — a "Follow" trigger
	 * that sits next to agnosis/reply-overlay's own "Reply" trigger, opening
	 * a native-Popover-API panel (same mechanism, no bespoke modal JS/CSS)
	 * with plain-language Fediverse/ActivityPub instructions, this artwork's
	 * artist's copyable @handle, and a "remote follow" form.
	 *
	 * There is no single URL a browser can open to complete a follow across
	 * two different, independent Fediverse servers — the follow has to be
	 * authorized FROM the visitor's own instance, not this one.
	 * `authorize_interaction` is the de-facto standard endpoint Mastodon
	 * (and most compatible software) exposes for exactly this: given
	 * `?uri=<actor URL>`, the visitor's own instance resolves it and shows
	 * them a normal in-app follow confirmation. The copyable handle is the
	 * fallback for any visitor whose own app doesn't support that redirect.
	 *
	 * **Delegates to Network\Federation\Follows (Q-2, WP7).** Kept here because Core\Plugin hooks it to `init` against this object.
	 */
	public function register_follow_overlay_block(): void {
		$this->follows()->register_follow_overlay_block();
	}
}
