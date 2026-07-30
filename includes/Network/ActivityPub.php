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

use Agnosis\AI\Pipeline;
use Agnosis\AI\SubmissionTranslator;
use Agnosis\Artist\NotificationPreferences;
use Agnosis\Compat\LinguaForge;
use Agnosis\Core\CommunityMailer;
use Agnosis\Core\EmailFooter;
use Agnosis\Core\EmailTemplate;
use Agnosis\Core\Logger;
use Agnosis\Core\RateLimiter;
use Agnosis\Core\Turnstile;
use Agnosis\Publishing\EmbedPolicy;
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

	/**
	 * Interaction-surface roadmap, Phase 3, WP4 (2026-07-27) — a site visitor
	 * without a fediverse account can now reply too (§4 Phase 3A). Deliberately
	 * a SIBLING constant, not a rename of REPLY_COMMENT_TYPE above: every
	 * existing federated-reply row keeps its own type unchanged, and every
	 * place that used to match REPLY_COMMENT_TYPE alone (reply_count(),
	 * get_replies(), drain_reply_translation_queue(), the
	 * handle_reply_moderation() type guard) is widened to a two-type allowlist
	 * rather than swapped. 13 characters — comment_type is a varchar(20) core
	 * column (wp-admin/includes/schema.php), the exact same column whose
	 * 20-char ceiling silently broke every federated reply once already (see
	 * REPLY_COMMENT_TYPE's own docblock above); 'agnosis_local_reply' (19
	 * chars) would have fit with only one character of headroom, so the
	 * shorter, unambiguous 'agnosis_reply' was used instead.
	 */
	public const LOCAL_REPLY_COMMENT_TYPE = 'agnosis_reply';

	/** Both reply comment types — the allowlist every reply-agnostic query below now matches against. */
	private const REPLY_COMMENT_TYPES = [ self::REPLY_COMMENT_TYPE, self::LOCAL_REPLY_COMMENT_TYPE ];

	/** Comment meta: the Note's own AS2 id — idempotent redelivery + the anchor Delete{object} matches against. Federated replies only — a local reply has no inbound activity id. */
	private const REPLY_ACTIVITY_ID_META = '_agnosis_reply_activity_id';

	/** Comment meta: the replying actor's URL — ownership check before honoring a Delete-of-reply. Federated replies only. */
	private const REPLY_ACTOR_META = '_agnosis_reply_actor';

	/** Comment meta: queue flag drained by drain_reply_translation_queue(); cleared once translated. Shared by both reply types. */
	private const REPLY_PENDING_TRANSLATION_META = '_agnosis_reply_pending_translation';

	/**
	 * Comment meta: the artist's-language translation, once resolved.
	 * comment_content itself always stays the untouched original — "never
	 * discard the source" (roadmap §4 Phase 2 step 8, reaffirmed §4 Phase 3A
	 * step 6) — so a caller (the replies REST endpoint) reads the translated
	 * fields first and falls back to comment_content only while translation is
	 * still pending. Shared by both reply types.
	 */
	private const REPLY_TRANSLATED_CONTENT_META = '_agnosis_reply_translated_content';

	/**
	 * Comment meta: the SITE'S PRIMARY-LANGUAGE translation (WP4, §4 Phase 3A
	 * step 6's three-version model — source, artist's language, site primary).
	 * Phase 2 only ever stored the artist's-language version above, which
	 * meant get_replies() showed every visitor the artist's own language
	 * regardless of which page they were actually reading — correct for the
	 * artist's own moderation email, wrong for a general-audience public page.
	 * This is the version served when the current post being viewed IS the
	 * site's primary-language post (or has no `_lf_lang` meta at all, the same
	 * "primary language" signal used throughout this class — see
	 * resolve_post_lf_lang()); every other viewing language still falls back
	 * to REPLY_TRANSLATED_CONTENT_META, then to the untouched original, same
	 * fallback order as before this field existed.
	 */
	private const REPLY_TRANSLATED_PRIMARY_META = '_agnosis_reply_translated_primary';

	/**
	 * Comment meta: the reply's own known written language — direction- and
	 * authorship-agnostic despite the name, since WP13 widened this from its
	 * original (WP4/Phase 2) scope. Populated three different ways depending
	 * on what kind of comment this is:
	 *   - A LOCAL visitor reply: the page's own `resolve_post_lf_lang()`
	 *     value at submission time (known instantly, no AI call).
	 *   - A FEDERATED (remote) reply: left unset UNLESS
	 *     `agnosis_federate_languages` is `all`, in which case
	 *     drain_reply_translation_queue() calls
	 *     SubmissionTranslator::detect_language() once, at inbound-drain
	 *     time, and stores the result here — WP13 §13.2/13.3. Detection
	 *     failing or being gated off both leave this unset, identical to
	 *     Phase 2's original "generally unknowable" case.
	 *   - An ARTIST-authored reply (WP13 §13.1): `resolve_artist_lang()` —
	 *     always known (an admitted artist necessarily has a declared
	 *     language), never detected.
	 *
	 * Three uses: drain_reply_translation_queue() passes it as
	 * SubmissionTranslator::translate_fields()'s optional `$source_lang_code`
	 * (closing Phase 2 step 7's documented weak spot, for both directions
	 * once a value exists); it skips translating into a target language that
	 * already equals this one — the "store once, skip the call" efficiency
	 * §4 Phase 3A step 6 and WP13 both rely on; and, for an artist's own
	 * reply, WP13 §13.1 reads a FEDERATED parent's own value of this field
	 * directly as "the original commenter's language" — detection only ever
	 * runs once, at inbound time, never re-run when the artist later replies.
	 */
	private const REPLY_SOURCE_LANG_META = '_agnosis_reply_source_lang';

	/**
	 * Comment meta: '1' when a federated (remote) reply's own language could
	 * not be identified as one of the site's configured languages at all —
	 * WP13 §13.5, Ulises's own confirmed answer: only ever relevant inbound,
	 * only ever set when `agnosis_federate_languages` is `all` (the only
	 * setting under which detection is attempted in the first place — see
	 * REPLY_SOURCE_LANG_META's own docblock). A comment flagged this way
	 * skips the normal translation attempt AND the normal
	 * notify_artist_of_reply() gateway email entirely — the artist instead
	 * gets notify_artist_of_unsupported_reply_language()'s plain,
	 * non-actionable, branded-but-linkless email carrying the untouched
	 * original text, because "we don't want to support undetectable or
	 * unsupported languages, this will complicate things a lot" (Ulises,
	 * §13.5). Never set for a local reply (its language is always known at
	 * submission time) or an artist's own reply (an artist's declared
	 * language is always one of the site's configured ones, by construction
	 * — no detection is ever attempted outbound at all).
	 */
	private const REPLY_UNSUPPORTED_LANG_META = '_agnosis_reply_unsupported_lang';

	/**
	 * Comment meta: shared group identifier tying together one visitor
	 * reply's CANONICAL row and every real, mirrored comment row created
	 * for it on other LF-sibling posts (agnosis-audit/
	 * REPLY-LANGUAGE-MIRRORING-ROADMAP.md, RLM1-RLM3). Modeled directly on
	 * Lingua Forge's own `_lf_trid` convention (a shared group value,
	 * distinct from each row's own id) — see that roadmap's §3.3 for why
	 * this is a plain Agnosis-only convention, not an actual integration
	 * with LF's own trid system.
	 *
	 * Written to the CANONICAL row's own value at insertion time as ITS OWN
	 * `comment_ID` (never blank) — `mirror_reply_across_languages()` treats
	 * "this value equals the row's own id" as the definition of "canonical,
	 * not yet mirrored" and "this value differs from the row's own id" as
	 * the definition of a mirror row, without needing a second flag. Only
	 * ever set on a visitor-submitted or federated-inbound reply
	 * (store_local_reply(), handle_create_reply()) — never on an artist's
	 * own reply (store_artist_gateway_reply()), since cross-post mirroring
	 * of artist replies-to-replies is deliberately out of scope for now
	 * (roadmap §4 Q3).
	 *
	 * `get_comments( ['meta_key' => self::REPLY_GROUP_ID_META, 'meta_value'
	 * => $group_id] )` finds every row in one reply's group across every
	 * sibling post — the mechanism cascade_delete_reply_group() uses.
	 */
	private const REPLY_GROUP_ID_META = '_agnosis_reply_group_id';

	/**
	 * Comment meta: when the reply's moderation link (notify_artist_of_reply())
	 * expires — WP0, agnosis-audit/INTERACTION-SURFACE-ROADMAP.md §8. Written
	 * once at email-send time; see that method's docblock for why it's stored
	 * rather than recomputed from the option at verify time. Absent (falsy)
	 * on any comment that got its notification email before this fix shipped —
	 * treated as "never expires" by verify_reply_gateway_token() (renamed
	 * from verify_reply_moderation_token() by WP7's one-token consolidation
	 * — see reply_gateway_url()'s own docblock), same backward-compat
	 * convention ReviewEndpoints::verify_token() already uses for
	 * `_agnosis_review_expiry`.
	 */
	private const REPLY_MODERATION_EXPIRY_META_KEY = '_agnosis_reply_moderation_expiry';

	/**
	 * Comment meta: the artist ticked "also post my reply to the Fediverse"
	 * when writing their own reply from the gateway page (WP7, interaction-
	 * surface roadmap, Phase 3, §4 Phase 3B/WP6). Written only on an
	 * artist-authored comment (store_artist_gateway_reply()), and only when
	 * reply_gateway_federate_offered() actually gated the checkbox into
	 * existence for that submission — never guessed, never defaulted.
	 *
	 * WP6 (federating artist replies outward) reads this flag from
	 * store_artist_gateway_reply() itself, immediately after insert — see
	 * federate_artist_reply() and REPLY_FEDERATED_META (the outcome flag,
	 * distinct from this one, which is only ever the artist's REQUEST).
	 */
	public const REPLY_FEDERATE_REQUESTED_META = '_agnosis_reply_federate_requested';

	/**
	 * Comment meta: '1' once THIS artist reply's own `Create{Note}` has
	 * actually gone out (WP6, interaction-surface roadmap, Phase 3, §4 Phase
	 * 3B). Distinct from REPLY_FEDERATE_REQUESTED_META above — that flag is
	 * only the artist's REQUEST at submission time (WP7); this one is the
	 * outcome, set by federate_artist_reply() right before delivery and
	 * cleared again by maybe_federate_reply_removal() once a Delete{Note}
	 * has gone out for it. serve_reply_activity_json() treats this as the
	 * sole source of truth for "does this reply have a real, dereferenceable
	 * AS2 object" — an ordinary visitor reply, or an artist reply that was
	 * never federated, both correctly 404 rather than exposing a Note nobody
	 * ever actually delivered. Only ever set on a LOCAL_REPLY_COMMENT_TYPE
	 * comment (the federated-inbound REPLY_COMMENT_TYPE already dereferences
	 * under the REMOTE server's own id, never ours).
	 */
	private const REPLY_FEDERATED_META = '_agnosis_reply_federated';

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

	/**
	 * Local (visitor) reply — interaction-surface roadmap, Phase 3, WP4,
	 * §4 Phase 3A step 2. Every constant/tier here is modeled line-for-line on
	 * Artist\ContactForm's own submit()/register_routes() — same field-length
	 * caps, same per-IP/per-sender/per-(artist,sender) three-tier throttle
	 * shape, reused rather than duplicated in spirit even though each class
	 * keeps its own copy of the actual constants (no shared base class exists
	 * for this pattern anywhere else in the codebase either).
	 */
	private const REPLY_MAX_NAME_LENGTH    = 150;
	private const REPLY_MAX_MESSAGE_LENGTH = 4000;

	/** Per-IP throttle — same shape as ContactForm's IP_LIMIT/IP_WINDOW_SECONDS. */
	private const REPLY_IP_LIMIT           = 5;
	private const REPLY_IP_WINDOW_SECONDS  = 60;

	/** Per-visitor-email throttle — second, coarser tier, same shape as ContactForm's SENDER_LIMIT. */
	private const REPLY_SENDER_LIMIT          = 5;
	private const REPLY_SENDER_WINDOW_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Third tier: how many times the SAME visitor (by email) may reply to the
	 * SAME artist's work within the window — reuses ContactForm's own
	 * configured option/default rather than inventing a second Settings
	 * field for what is, from an abuse-prevention standpoint, the identical
	 * question ContactForm already answers.
	 */
	private const REPLY_ARTIST_LIMIT_OPTION        = 'agnosis_contact_artist_limit';
	private const REPLY_ARTIST_LIMIT_DEFAULT       = 2;
	private const REPLY_ARTIST_LIMIT_WINDOW_OPTION = 'agnosis_contact_artist_limit_window_hours';
	private const REPLY_ARTIST_LIMIT_WINDOW_DEFAULT_HOURS = 1;

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
					'validate_callback' => fn( string $v ): bool|WP_Error => self::validate_reply_field_length( $v, self::REPLY_MAX_NAME_LENGTH, __( 'Name', 'agnosis' ) ),
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
					'validate_callback' => fn( string $v ): bool|WP_Error => self::validate_reply_field_length( $v, self::REPLY_MAX_MESSAGE_LENGTH, __( 'Reply', 'agnosis' ) ),
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

	/**
	 * The actor id/URL for the node, or for one specific artist.
	 *
	 * Public (WP3, interaction-surface roadmap, Phase 3): the newsletter
	 * gateway (Newsletter\InteractionGateway) calls this directly to resolve
	 * an artist's real actor URL from a verified token's artist_id, without
	 * needing a logged-in session (there never is one — the no-login rule).
	 */
	public function actor_url_for( string $owner_type, int $owner_id ): string {
		return 'artist' === $owner_type && $owner_id > 0
			? rest_url( 'agnosis/v1/activitypub/actor/' . $owner_id )
			: rest_url( 'agnosis/v1/activitypub/actor' );
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
	 */
	public function handle_for( string $owner_type, int $owner_id = 0 ): string {
		$base = (string) get_option( 'agnosis_base_domain', '' );
		$host = $base ?: (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( 'artist' === $owner_type ) {
			$user = get_userdata( $owner_id );
			if ( ! $user ) {
				return '';
			}
			$username = $user->user_nicename ?: $user->user_login;
			return $username . '@' . $host;
		}

		return 'agnosis@' . $host;
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
	 * The dereferenceable id a federated artist reply lives at — a REST
	 * route rather than a permalink (WP6), since a comment has no permalink
	 * of its own the way an artwork does via object_id_for().
	 */
	private function reply_object_id_for( int $comment_id ): string {
		return rest_url( 'agnosis/v1/activitypub/replies/' . $comment_id );
	}

	/**
	 * Record a federated reply's tombstone (WP6) — same bounded-cap,
	 * autoload=false pattern as record_tombstone() above, just keyed by
	 * comment id instead of artwork slug and stored in its own option so a
	 * years-deleted reply never crowds out artwork tombstones under the same
	 * TOMBSTONE_CAP.
	 */
	private function record_reply_tombstone( int $comment_id, string $object_id, string $deleted ): void {
		$tombstones = get_option( 'agnosis_ap_reply_tombstones', [] );

		$tombstones[ $comment_id ] = [
			'id'      => $object_id,
			'deleted' => $deleted,
		];

		if ( count( $tombstones ) > self::TOMBSTONE_CAP ) {
			uasort( $tombstones, static fn( array $a, array $b ) => strcmp( $a['deleted'], $b['deleted'] ) );
			$tombstones = array_slice( $tombstones, -self::TOMBSTONE_CAP, null, true );
		}

		update_option( 'agnosis_ap_reply_tombstones', $tombstones, false );
	}

	/**
	 * The Tombstone JSON for a removed federated reply, or null if this
	 * comment id was never federated-then-removed at all.
	 *
	 * @return array<string, mixed>|null
	 */
	private function reply_tombstone_json( int $comment_id ): ?array {
		$tombstones = get_option( 'agnosis_ap_reply_tombstones', [] );

		if ( ! isset( $tombstones[ $comment_id ]['id'], $tombstones[ $comment_id ]['deleted'] ) ) {
			return null;
		}

		return [
			'@context'   => self::CONTEXT,
			'type'       => 'Tombstone',
			'id'         => $tombstones[ $comment_id ]['id'],
			'formerType' => 'Note',
			'deleted'    => $tombstones[ $comment_id ]['deleted'],
		];
	}

	/**
	 * What a federated artist reply's `inReplyTo` should point at (WP6) —
	 * three cases, resolved in order:
	 *
	 * 1. The parent is itself a federated-INBOUND reply (REPLY_COMMENT_TYPE)
	 *    — point at its own remote AS2 id (REPLY_ACTIVITY_ID_META), the same
	 *    identity handle_create_reply() stored when it first arrived.
	 * 2. The parent is a previously-federated artist reply of ours
	 *    (LOCAL_REPLY_COMMENT_TYPE + REPLY_FEDERATED_META) — a genuine
	 *    reply-to-a-reply thread; point at that reply's own
	 *    reply_object_id_for().
	 * 3. Anything else (most commonly: replying to an ordinary site
	 *    visitor's local reply, which never federates and so has no AS2
	 *    identity of its own) — fall back to the ARTWORK's own Note id
	 *    (object_id_for()). The artwork is guaranteed to have one: this
	 *    method is only ever reached via federate_artist_reply(), which is
	 *    only ever reached once reply_gateway_federate_offered() has already
	 *    confirmed the artwork itself is federated.
	 */
	private function reply_in_reply_to( \WP_Comment $comment ): string {
		$parent_id = (int) $comment->comment_parent;
		$parent    = $parent_id > 0 ? get_comment( $parent_id ) : null;

		if ( $parent instanceof \WP_Comment ) {
			if ( self::REPLY_COMMENT_TYPE === $parent->comment_type ) {
				$remote_id = (string) get_comment_meta( $parent_id, self::REPLY_ACTIVITY_ID_META, true );
				if ( '' !== $remote_id ) {
					return $remote_id;
				}
			} elseif ( self::LOCAL_REPLY_COMMENT_TYPE === $parent->comment_type
				&& '1' === (string) get_comment_meta( $parent_id, self::REPLY_FEDERATED_META, true )
			) {
				return $this->reply_object_id_for( $parent_id );
			}
		}

		$post = get_post( (int) $comment->comment_post_ID );
		return $post instanceof \WP_Post ? $this->object_id_for( $post ) : '';
	}

	/**
	 * The remote actor a federated artist reply's `cc`/Mention should target
	 * — only meaningful when the parent being answered is itself a
	 * federated-inbound reply (REPLY_ACTOR_META); '' otherwise (a plain
	 * broadcast to followers, no direct addressee).
	 */
	private function reply_parent_actor( \WP_Comment $comment ): string {
		$parent_id = (int) $comment->comment_parent;
		if ( $parent_id <= 0 ) {
			return '';
		}

		$parent = get_comment( $parent_id );
		if ( ! $parent instanceof \WP_Comment || self::REPLY_COMMENT_TYPE !== $parent->comment_type ) {
			return '';
		}

		return (string) get_comment_meta( $parent_id, self::REPLY_ACTOR_META, true );
	}

	/**
	 * Build one artist reply's Note object (WP6). Reused by BOTH
	 * serve_reply_activity_json() (dereferencing the id) and
	 * federate_artist_reply() (the Create{Note} payload) so the two can
	 * never drift out of sync — the object served IS the object delivered.
	 *
	 * WP13 §13.4 fix: `contentMap` used to carry a single entry tagged with
	 * `resolve_note_language( $post_id )` — the ARTWORK's own page language,
	 * not necessarily the language the artist actually wrote this reply in
	 * (an artist can, and often will, reply in their own native language on
	 * an artwork whose page is in the site's primary language). Now a
	 * genuine multi-key AS2 `contentMap` (spec-legitimate, not a hack),
	 * built from whichever of the three WP13 translation-model meta values
	 * are actually populated on this comment — always the source entry
	 * (`REPLY_SOURCE_LANG_META` → `comment_content`), plus the primary-
	 * language entry when distinct (`REPLY_TRANSLATED_PRIMARY_META`), plus
	 * the original commenter's own language when distinct from both
	 * (`REPLY_TRANSLATED_CONTENT_META`, target re-derived via
	 * resolve_original_commenter_lang() — cheap, no AI call, since it's the
	 * exact same derivation drain_outbound_reply_translation() already used
	 * when it stored that translation).
	 *
	 * By the time this runs, `REPLY_SOURCE_LANG_META` should always be
	 * populated — `federate_artist_reply()` (this method's only two callers)
	 * is only ever invoked from `drain_outbound_reply_translation()`, which
	 * writes it unconditionally before federating. The `resolve_note_language()`
	 * fallback below only guards a comment somehow reaching this method
	 * before that, never an expected path.
	 *
	 * Flat, non-map `content` field: defaults to the untouched SOURCE text
	 * (what the artist actually wrote), not the primary-language translation
	 * — a disclosed choice, not silently picked: `post_to_note()` instead
	 * defaults an ARTWORK's flat `content` to its primary/page language, so
	 * this is a deliberately different convention for a reply specifically
	 * (least surprising: "this is literally what the artist wrote" for any
	 * client that ignores `contentMap` entirely). Revisit if Ulises prefers
	 * the artwork's own convention mirrored here instead.
	 *
	 * @return array<string, mixed>
	 */
	private function reply_to_note( \WP_Comment $comment ): array {
		$comment_id = (int) $comment->comment_ID;
		$post_id    = (int) $comment->comment_post_ID;
		$note_id    = $this->reply_object_id_for( $comment_id );

		$source_lang = (string) get_comment_meta( $comment_id, self::REPLY_SOURCE_LANG_META, true );
		if ( '' === $source_lang ) {
			$source_lang = $this->resolve_note_language( $post_id ); // Defensive only — see docblock.
		}
		$primary_lang   = SubmissionTranslator::resolve_target_language();
		$commenter_lang = $this->resolve_original_commenter_lang( (int) $comment->comment_parent, $primary_lang );

		$content_map = [ $source_lang => '<p>' . esc_html( $comment->comment_content ) . '</p>' ];

		if ( $primary_lang !== $source_lang ) {
			$primary_translation = (string) get_comment_meta( $comment_id, self::REPLY_TRANSLATED_PRIMARY_META, true );
			if ( '' !== $primary_translation ) {
				$content_map[ $primary_lang ] = '<p>' . esc_html( $primary_translation ) . '</p>';
			}
		}

		if ( $commenter_lang !== $source_lang && $commenter_lang !== $primary_lang ) {
			$commenter_translation = (string) get_comment_meta( $comment_id, self::REPLY_TRANSLATED_CONTENT_META, true );
			if ( '' !== $commenter_translation ) {
				$content_map[ $commenter_lang ] = '<p>' . esc_html( $commenter_translation ) . '</p>';
			}
		}

		$note = [
			'@context'     => self::CONTEXT,
			'type'         => 'Note',
			'id'           => $note_id,
			'url'          => $note_id,
			'attributedTo' => $this->actor_url_for( 'artist', (int) $comment->user_id ),
			'content'      => $content_map[ $source_lang ],
			'contentMap'   => $content_map,
			'published'    => gmdate( 'c', (int) strtotime( $comment->comment_date_gmt ) ),
			'to'           => [ 'https://www.w3.org/ns/activitystreams#Public' ],
			'inReplyTo'    => $this->reply_in_reply_to( $comment ),
		];

		$parent_actor = $this->reply_parent_actor( $comment );
		if ( '' !== $parent_actor ) {
			$note['cc']  = [ $parent_actor ];
			$note['tag'] = [ [ 'type' => 'Mention', 'href' => $parent_actor ] ];
		}

		return $note;
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
		// Interaction-surface roadmap, Phase 3, WP6 — same best-effort cosmetic
		// parity as likesCount/sharesCount above, now that replies themselves
		// can be genuinely federated (an artist's own reply, when they've
		// opted in via the gateway checkbox). reply_count() already counts
		// both federated and local approved replies; a remote server has no
		// way to enumerate the local-only ones anyway, so `replies` itself
		// stays a bare totalItems rather than a real paged Collection.
		$note['repliesCount'] = $this->reply_count( $post->ID );
		$note['replies']      = [
			'type'       => 'Collection',
			'totalItems' => $note['repliesCount'],
		];

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
	 *
	 * Public (WP3, interaction-surface roadmap, Phase 3): the newsletter
	 * gateway (Newsletter\InteractionGateway) reuses this exact same
	 * resolution for a PUBLIC-newsletter subscriber's like click — they have
	 * no actor either, so their click is identified exactly like any other
	 * anonymous on-site visitor, resolved fresh from whatever request is
	 * actually making the click, not from anything encoded in the emailed
	 * link's token.
	 */
	public function like_identity(): string {
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

	/**
	 * Is $post_id a real agnosis_artwork? Shared guard for both like routes,
	 * and (WP3, public) for the newsletter gateway's confirm page, which
	 * needs the same 404 semantics before it ever renders a token-authenticated
	 * "Like this artwork?" confirm page.
	 */
	public function likeable_artwork( int $post_id ): \WP_Post|WP_Error {
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

		return new WP_REST_Response( $this->write_like( $post_id, $this->like_identity(), true ), 200 );
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

		return new WP_REST_Response( $this->write_like( $post_id, $this->like_identity(), false ), 200 );
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
	 * @return array{liked: bool, like: int}
	 */
	public function write_like( int $post_id, string $actor_id, bool $liked ): array {
		global $wpdb;

		if ( $liked ) {
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
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes every value; small, per-artwork-scale table.
			$wpdb->delete(
				$wpdb->prefix . 'agnosis_interactions',
				[ 'post_id' => $post_id, 'activity_type' => 'like', 'actor_id' => $actor_id ],
				[ '%d', '%s', '%s' ]
			);
		}

		return $this->like_response( $post_id, $actor_id );
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

	// -------------------------------------------------------------------------
	// Boosts (interaction-surface roadmap, Phase 3, WP5, 2026-07-27)
	// -------------------------------------------------------------------------

	/** Does $actor_id already have a recorded 'announce' (boost) row on $post_id? */
	private function has_boosted( int $post_id, string $actor_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row existence check; $wpdb->prepare() parameterizes both values.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d AND activity_type = 'announce' AND actor_id = %s LIMIT 1",
				$post_id,
				$actor_id
			)
		);

		return null !== $found;
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
	 * @return array{boosted: bool, announce: int}
	 */
	public function write_boost( int $post_id, int $artist_id, bool $boosted ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return [ 'boosted' => false, 'announce' => 0 ];
		}

		$actor_id = $this->actor_url_for( 'artist', $artist_id );

		global $wpdb;
		if ( $boosted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->replace() parameterizes every value; small, per-artwork-scale table.
			$wpdb->replace(
				$wpdb->prefix . 'agnosis_interactions',
				[
					'post_id'       => $post_id,
					'activity_type' => 'announce',
					'actor_id'      => $actor_id,
					'origin'        => 'local',
				],
				[ '%d', '%s', '%s', '%s' ]
			);
			$this->federate_boost( $post, $artist_id );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes every value; small, per-artwork-scale table.
			$wpdb->delete(
				$wpdb->prefix . 'agnosis_interactions',
				[ 'post_id' => $post_id, 'activity_type' => 'announce', 'actor_id' => $actor_id ],
				[ '%d', '%s', '%s' ]
			);
			$this->federate_unboost( $post, $artist_id );
		}

		return [
			'boosted'  => $this->has_boosted( $post_id, $actor_id ),
			'announce' => $this->interaction_counts( $post_id )['announce'],
		];
	}

	/**
	 * Federate `Announce` for a local boost (§4 Phase 3E step 2): `{ type:
	 * 'Announce', actor: <booster's actor>, object: <artwork's own object
	 * id>, to: [Public], cc: [ booster's followers, the artwork owner's
	 * actor ] }`, delivered to the booster's own followers (union with node
	 * followers, same as any other artist-attributed activity). The
	 * boosted artist (B) is local, so B's own side of this is simply the
	 * `agnosis_interactions` row write_boost() already made — no separate
	 * delivery to B is needed or attempted (mirrors the roadmap's own "B is
	 * local, so B's side is just a row").
	 *
	 * The activity id is deterministic (`<object_id>#announce-<artist_id>`),
	 * not time-based — at most one active boost can exist per (post,
	 * booster) pair (the interactions table's own unique key), so the same
	 * id naturally identifies "the current boost by this artist of this
	 * artwork" for federate_unboost()'s Undo to reference.
	 */
	private function federate_boost( \WP_Post $post, int $artist_id ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$this->deliver_to_followers( $this->boost_announce_activity( $post, $artist_id ), 'artist', $artist_id );
	}

	/** Federate `Undo{Announce}` for a local un-boost (§4 Phase 3E step 4) — the local mirror of the inbound path undo_interaction() already implements for a remote Undo{Announce}. */
	private function federate_unboost( \WP_Post $post, int $artist_id ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$announce = $this->boost_announce_activity( $post, $artist_id );

		$this->deliver_to_followers( [
			'@context' => self::CONTEXT,
			'type'     => 'Undo',
			'id'       => $announce['id'] . '-undo',
			'actor'    => $announce['actor'],
			'to'       => $announce['to'],
			'cc'       => $announce['cc'],
			'object'   => $announce,
		], 'artist', $artist_id );
	}

	/**
	 * Build the `Announce` activity shape shared by federate_boost() (sent
	 * as-is) and federate_unboost() (wrapped in `Undo`, so the two can
	 * never disagree about what's being un-boosted).
	 *
	 * @return array<string, mixed>
	 */
	private function boost_announce_activity( \WP_Post $post, int $artist_id ): array {
		$actor_url   = $this->actor_url_for( 'artist', $artist_id );
		$owner       = $this->owner_for_post( $post );
		$owner_actor = $this->actor_url_for( $owner['type'], $owner['id'] );
		$object_id   = $this->object_id_for( $post );

		return [
			'@context' => self::CONTEXT,
			'type'     => 'Announce',
			'id'       => $object_id . '#announce-' . $artist_id,
			'actor'    => $actor_url,
			'object'   => $object_id,
			'to'       => [ 'https://www.w3.org/ns/activitystreams#Public' ],
			'cc'       => array_values( array_unique( [ $actor_url . '/followers', $owner_actor ] ) ),
		];
	}

	/**
	 * Approved reply count for one artwork — federated AND local replies both
	 * count (WP4 widened this from federated-only) — backs the
	 * agnosis/reply-overlay trigger button (render_reply_overlay()). Only
	 * `comment_approved = 1` rows count: an artist who hasn't reviewed a held
	 * reply yet, or rejected one, must never be visible to the public even as
	 * a bare number ("N replies" implies N readable replies).
	 */
	public function reply_count( int $post_id ): int {
		return (int) get_comments( [
			'post_id' => $post_id,
			'type'    => self::REPLY_COMMENT_TYPES,
			'status'  => 'approve',
			'count'   => true,
		] );
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
	 */
	public function get_replies( WP_REST_Request $request ): WP_REST_Response {
		$post_id  = (int) $request->get_param( 'id' );
		$page_lang = $this->resolve_post_lf_lang( $post_id );

		$comments = get_comments( [
			'post_id' => $post_id,
			'type'    => self::REPLY_COMMENT_TYPES,
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

			$replies[] = [
				'author'  => $comment->comment_author,
				'url'     => $comment->comment_author_url,
				'content' => $this->display_reply_content( $comment, $page_lang ),
				'date'    => mysql2date( 'c', $comment->comment_date_gmt, false ),
			];
		}

		return new WP_REST_Response( [ 'count' => count( $replies ), 'replies' => $replies ], 200 );
	}

	/**
	 * This post's own LF language, or '' when it IS the site's primary-
	 * language post (no `_lf_lang` meta at all — the same convention already
	 * used elsewhere in this class, e.g. singular_activity_json()'s own
	 * `contentMap` resolution) — never a guess, always read straight off the
	 * post being viewed.
	 */
	private function resolve_post_lf_lang( int $post_id ): string {
		return sanitize_key( (string) get_post_meta( $post_id, '_lf_lang', true ) );
	}

	/**
	 * Which stored version of one reply to actually show for $page_lang
	 * (resolve_post_lf_lang()'s value for the post currently being viewed).
	 *
	 * Fallback order, per §4 Phase 3A step 6:
	 *   1. $page_lang matches this reply's own known source language
	 *      (REPLY_SOURCE_LANG_META, local replies only) → the untouched
	 *      original is the MOST correct answer, not a translation of it.
	 *   2. $page_lang is '' (the viewer is on the site's primary-language
	 *      post) and a primary-language translation exists
	 *      (REPLY_TRANSLATED_PRIMARY_META) → that.
	 *   3. An artist-language translation exists (REPLY_TRANSLATED_CONTENT_META,
	 *      the only version Phase 2 ever stored) → that — the same general-
	 *      audience default every existing federated reply already serves,
	 *      unchanged for every viewing language this doesn't otherwise resolve.
	 *   4. Untouched original — translation still pending, or none configured.
	 */
	private function display_reply_content( \WP_Comment $comment, string $page_lang ): string {
		$comment_id = (int) $comment->comment_ID;

		$source_lang = (string) get_comment_meta( $comment_id, self::REPLY_SOURCE_LANG_META, true );
		if ( '' !== $source_lang && $page_lang === $source_lang ) {
			return $comment->comment_content;
		}

		if ( '' === $page_lang ) {
			$primary = (string) get_comment_meta( $comment_id, self::REPLY_TRANSLATED_PRIMARY_META, true );
			if ( '' !== $primary ) {
				return $primary;
			}
		}

		$artist_translation = (string) get_comment_meta( $comment_id, self::REPLY_TRANSLATED_CONTENT_META, true );
		return '' !== $artist_translation ? $artist_translation : $comment->comment_content;
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
	 */
	public function register_follow_overlay_block(): void {
		register_block_type(
			\AGNOSIS_DIR . 'blocks/follow-overlay',
			[ 'render_callback' => [ $this, 'render_follow_overlay' ] ]
		);
	}

	/**
	 * Render callback for agnosis/follow-overlay. Renders nothing on a
	 * non-artwork post, or when the artwork's author account no longer
	 * resolves to a real (still-admitted) artist — same "empty string takes
	 * no space" convention as render_artwork_copyright().
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 */
	public function render_follow_overlay( array $attrs, string $content, \WP_Block $block ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );

		if ( ! $post || 'agnosis_artwork' !== $post->post_type ) {
			return '';
		}

		$artist_id = (int) $post->post_author;
		$handle    = $this->handle_for( 'artist', $artist_id );

		if ( '' === $handle ) {
			return '';
		}

		$actor_url = $this->actor_url_for( 'artist', $artist_id );

		wp_enqueue_style( 'agnosis-follow-overlay', \AGNOSIS_URL . 'blocks/follow-overlay/frontend.css', [], \AGNOSIS_VERSION );
		wp_enqueue_script( 'agnosis-follow-overlay', \AGNOSIS_URL . 'blocks/follow-overlay/frontend.js', [], \AGNOSIS_VERSION, [ 'in_footer' => true ] );
		wp_localize_script( 'agnosis-follow-overlay', 'agnosisFollowOverlay', [
			'actorUrl' => $actor_url,
			'i18n'     => [
				'invalidInstance' => __( 'Enter your Fediverse instance domain (e.g. mastodon.social).', 'agnosis' ),
				'copied'          => __( 'Copied!', 'agnosis' ),
			],
		] );

		$panel_id           = 'agnosis-follow-overlay-' . $post_id;
		$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'agnosis-follow-overlay' ] );

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() output is already escaped. ?>>
			<button
				type="button"
				class="agnosis-follow-overlay__trigger"
				popovertarget="<?php echo esc_attr( $panel_id ); ?>"
				popovertargetaction="show"
			>
				<?php esc_html_e( 'Follow', 'agnosis' ); ?>
			</button>

			<div id="<?php echo esc_attr( $panel_id ); ?>" class="agnosis-follow-overlay__panel" popover="auto">
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
				<div class="agnosis-follow-overlay__inner">
					<p class="agnosis-follow-overlay__intro">
						<?php esc_html_e( 'This artist publishes to the Fediverse — the open, decentralized social network behind Mastodon, Pixelfed, and other ActivityPub-based apps. Follow them from your own account on any of those, no Agnosis account needed.', 'agnosis' ); ?>
					</p>

					<p class="agnosis-follow-overlay__handle-label"><?php esc_html_e( 'Their handle:', 'agnosis' ); ?></p>
					<div class="agnosis-follow-overlay__handle-row">
						<code class="agnosis-follow-overlay__handle"><?php echo esc_html( '@' . $handle ); ?></code>
						<button type="button" class="agnosis-follow-overlay__copy" data-agnosis-copy-handle="<?php echo esc_attr( '@' . $handle ); ?>">
							<?php esc_html_e( 'Copy', 'agnosis' ); ?>
						</button>
					</div>
					<p class="agnosis-follow-overlay__hint"><?php esc_html_e( 'Paste it into the search bar of your own Fediverse app to follow directly.', 'agnosis' ); ?></p>

					<form class="agnosis-follow-overlay__form" data-agnosis-follow-form>
						<label class="agnosis-follow-overlay__form-label" for="<?php echo esc_attr( $panel_id ); ?>-instance">
							<?php esc_html_e( 'Or enter your instance to follow with one click:', 'agnosis' ); ?>
						</label>
						<div class="agnosis-follow-overlay__form-row">
							<input
								type="text"
								id="<?php echo esc_attr( $panel_id ); ?>-instance"
								name="instance"
								placeholder="<?php esc_attr_e( 'yourinstance.social', 'agnosis' ); ?>"
								autocomplete="off"
								autocapitalize="off"
								spellcheck="false"
							/>
							<button type="submit" class="agnosis-follow-overlay__form-submit"><?php esc_html_e( 'Follow', 'agnosis' ); ?></button>
						</div>
						<p class="agnosis-follow-overlay__form-status" data-agnosis-follow-status></p>
					</form>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
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
	 * non-artwork post.
	 *
	 * WP4 (§4 Phase 3A step 9) changes what happens at zero replies: a plain,
	 * non-interactive "0 replies" line — no button, no popover, no enqueued
	 * JS/CSS — is now shown ONLY when replies are actually switched off
	 * (`replies_open()` false, whether per-artwork or account-wide); there is
	 * nothing to open and nowhere to submit a reply either way. Whenever
	 * replies ARE open, the real trigger + popover panel renders even at zero
	 * — the panel now always carries a reply form alongside the (possibly
	 * empty) list, resolving the "0 replies" case this class's own docblock
	 * previously deferred: "the whole reason this returned a static line was
	 * that no form existed yet to make the trigger worth clicking." The
	 * trigger's own label says "Reply" at zero rather than "0 replies" for
	 * exactly that reason — an invitation, not a dead count.
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
		$open  = $this->replies_open( $post_id );

		if ( 0 === $count && ! $open ) {
			$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'agnosis-reply-overlay agnosis-reply-overlay--empty' ] );
			return sprintf(
				'<span %s>%s</span>',
				$wrapper_attributes,
				esc_html__( '0 replies', 'agnosis' )
			);
		}

		wp_enqueue_style( 'agnosis-reply-overlay', \AGNOSIS_URL . 'blocks/reply-overlay/frontend.css', [], \AGNOSIS_VERSION );
		wp_enqueue_script( 'agnosis-reply-overlay', \AGNOSIS_URL . 'blocks/reply-overlay/frontend.js', [], \AGNOSIS_VERSION, [ 'in_footer' => true ] );
		if ( $open ) {
			Turnstile::enqueue_script();
		}
		wp_localize_script( 'agnosis-reply-overlay', 'agnosisReplyOverlay', [
			'apiUrl' => rest_url( 'agnosis/v1/content/' . $post_id . '/replies' ),
			// Only relevant for a logged-in artist replying via their own
			// cookie-authenticated session — WordPress's cookie-auth REST
			// layer requires a nonce on any write request regardless of this
			// route's own public permission_callback, same reason
			// blocks/content-editor/frontend.js and
			// blocks/interaction-counts/frontend.js each carry one. A fully
			// anonymous visitor has no auth cookie, so the check never
			// triggers and this nonce is simply unused for them.
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'i18n'   => [
				'loading'            => __( 'Loading replies…', 'agnosis' ),
				'error'              => __( 'Could not load replies.', 'agnosis' ),
				'submitting'         => __( 'Sending…', 'agnosis' ),
				'submitSuccess'      => __( 'Thanks — your reply has been submitted and is awaiting approval.', 'agnosis' ),
				'submitError'        => __( 'Could not send your reply. Please try again.', 'agnosis' ),
				'namePlaceholder'    => __( 'Name (optional)', 'agnosis' ),
				'emailPlaceholder'   => __( 'Your email', 'agnosis' ),
				'messagePlaceholder' => __( 'Write a reply…', 'agnosis' ),
				'submitLabel'        => __( 'Send reply', 'agnosis' ),
			],
		] );

		$panel_id           = 'agnosis-reply-overlay-' . $post_id;
		$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'agnosis-reply-overlay' ] );

		$trigger_label = $count > 0
			? sprintf(
				/* translators: %d: number of replies. */
				_n( '%d reply', '%d replies', $count, 'agnosis' ),
				$count
			)
			: __( 'Reply', 'agnosis' );

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() output is already escaped. ?>>
			<button
				type="button"
				class="agnosis-reply-overlay__trigger"
				popovertarget="<?php echo esc_attr( $panel_id ); ?>"
				popovertargetaction="show"
			>
				<?php echo esc_html( $trigger_label ); ?>
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
				<?php if ( $open ) : ?>
					<?php echo $this->render_reply_form( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_reply_form() escapes its own output. ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The reply form itself — name (optional)/email/message, Turnstile widget
	 * when configured (§4 Phase 3A step 2's rate/verification stack), no
	 * federate checkbox here: §4 Phase 3B's "artist replies federate, visitor
	 * replies don't" split is entirely automatic (an artist is identified by
	 * whether they're logged in, not by a form choice) and WP5/3B isn't built
	 * yet regardless — see submit_reply()'s own docblock.
	 */
	private function render_reply_form( int $post_id ): string {
		ob_start();
		?>
		<form class="agnosis-reply-overlay__form" data-agnosis-reply-form data-agnosis-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
			<textarea name="message" class="agnosis-reply-overlay__form-message" rows="4" placeholder="<?php esc_attr_e( 'Write a reply…', 'agnosis' ); ?>" required></textarea>
			<div class="agnosis-reply-overlay__form-row">
				<input type="text" name="name" placeholder="<?php esc_attr_e( 'Name (optional)', 'agnosis' ); ?>">
				<input type="email" name="email" placeholder="<?php esc_attr_e( 'Your email', 'agnosis' ); ?>" required>
			</div>
			<?php echo Turnstile::render_widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Turnstile::render_widget() escapes its own output and returns '' when not configured. ?>
			<button type="submit" class="agnosis-reply-overlay__form-submit"><?php esc_html_e( 'Send reply', 'agnosis' ); ?></button>
			<p class="agnosis-reply-overlay__form-status" aria-live="polite"></p>
		</form>
		<?php
		return (string) ob_get_clean();
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
	 */
	public function drain_reply_translation_queue(): void {
		$deadline = microtime( true ) + self::REPLY_TRANSLATION_TIME_BUDGET_SECONDS;

		$comments = get_comments( [
			'type'     => self::REPLY_COMMENT_TYPES,
			'status'   => 'any',
			'meta_key' => self::REPLY_PENDING_TRANSLATION_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- cron-only path, bounded by the queue's own (small, self-draining) size.
		] );

		// get_comments() only ever returns an int when 'count' => true is
		// passed (not the case here) — the is_array() check is for PHPStan's
		// generic stub, not a real runtime branch.
		if ( ! is_array( $comments ) || empty( $comments ) ) {
			return;
		}

		// A missing/unconfigured translator must not silently strand every
		// pending reply's artist notification forever (it used to — this
		// method returned outright before any comment was ever touched, and
		// notify_artist_of_reply() was never called from anywhere else once
		// it moved here). The queue is still walked and each artist still
		// notified below; only the translation calls themselves are skipped,
		// so notify_artist_of_reply()'s own display_reply_content() call
		// falls back to the untouched original — worse than a translation,
		// never worse than silence.
		$translator = SubmissionTranslator::from_settings();

		foreach ( $comments as $comment ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}

			$comment_id = (int) $comment->comment_ID;
			$post_id    = (int) $comment->comment_post_ID;

			// WP13 §13.6: an artist-authored reply (store_artist_gateway_reply()
			// always sets a real WP user id; every visitor/federated-inbound
			// path never does) takes the OUTBOUND translation model, never
			// the inbound one below — and must NEVER reach
			// notify_artist_of_reply(), or the artist would be notified
			// about, and offered a gateway to approve/reject, their own
			// already-approved reply.
			if ( (int) $comment->user_id > 0 ) {
				$this->drain_outbound_reply_translation( $comment, $translator );
				continue;
			}

			$source_lang = (string) get_comment_meta( $comment_id, self::REPLY_SOURCE_LANG_META, true );

			// WP13 §13.2/13.3 — detect a federated reply's own language, once,
			// only when it's possible for that language to be anything other
			// than the site's primary in the first place.
			if ( null !== $translator
				&& self::REPLY_COMMENT_TYPE === $comment->comment_type
				&& '' === $source_lang
				&& 'all' === get_option( 'agnosis_federate_languages', 'primary-only' )
			) {
				$excerpt  = mb_substr( wp_strip_all_tags( $comment->comment_content ), 0, 300 );
				$detected = $translator->detect_language( $excerpt );

				if ( '' === $detected ) {
					// §13.5: undetectable/unsupported — no translation, no
					// normal gateway, an informational-only email instead.
					update_comment_meta( $comment_id, self::REPLY_UNSUPPORTED_LANG_META, '1' );
					delete_comment_meta( $comment_id, self::REPLY_PENDING_TRANSLATION_META );

					$post = get_post( $post_id );
					if ( $post instanceof \WP_Post ) {
						$this->notify_artist_of_unsupported_reply_language( $post, $comment );
					}
					continue;
				}

				update_comment_meta( $comment_id, self::REPLY_SOURCE_LANG_META, $detected );
				$source_lang = $detected;
			}

			if ( null !== $translator ) {
				$artist_lang = SubmissionTranslator::resolve_artist_lang( (int) get_post_field( 'post_author', $post_id ) );
				if ( '' === $artist_lang ) {
					$artist_lang = SubmissionTranslator::resolve_target_language();
				}
				$primary_lang = SubmissionTranslator::resolve_target_language();

				$artist_translation = $this->reply_translation_for( $translator, $comment->comment_content, $artist_lang, $source_lang );
				update_comment_meta( $comment_id, self::REPLY_TRANSLATED_CONTENT_META, $artist_translation );

				$primary_translation = $primary_lang === $artist_lang
					? $artist_translation
					: $this->reply_translation_for( $translator, $comment->comment_content, $primary_lang, $source_lang );
				update_comment_meta( $comment_id, self::REPLY_TRANSLATED_PRIMARY_META, $primary_translation );
			}

			delete_comment_meta( $comment_id, self::REPLY_PENDING_TRANSLATION_META );

			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				$is_local = self::LOCAL_REPLY_COMMENT_TYPE === $comment->comment_type;
				$this->notify_artist_of_reply( $post, $comment, $is_local );
			}
		}
	}

	/**
	 * WP13 §13.1/§13.6b — the OUTBOUND half of the three-version model, for
	 * an artist's own reply (always carries a real `user_id`, set by
	 * store_artist_gateway_reply()). No email is ever sent from this
	 * method — the artist already knows what they wrote; only the stored
	 * translations and (once ready) the federated Note need to catch up.
	 *
	 * Source is always the artist's own declared language
	 * (SubmissionTranslator::resolve_artist_lang()) — never detected, unlike
	 * the inbound federated case, per Ulises's own confirmed answer (§13.5):
	 * "outbound content by an artist: we can assume that he writes in the
	 * language he defined as his or her native language, which was chosen
	 * from supported languages on the agnosis system." Falls back to the
	 * site's primary language only defensively (an admitted artist always
	 * has a declared language in practice).
	 *
	 * The "original commenter's language" target is resolved via
	 * resolve_original_commenter_lang() — reusing whatever the PARENT
	 * comment's own REPLY_SOURCE_LANG_META already holds (known instantly
	 * for a local visitor's parent; detected, at most once, by this same
	 * method's inbound branch above for a federated parent) rather than
	 * ever detecting anything here.
	 */
	private function drain_outbound_reply_translation( \WP_Comment $comment, ?SubmissionTranslator $translator ): void {
		$comment_id = (int) $comment->comment_ID;

		$artist_lang = SubmissionTranslator::resolve_artist_lang( (int) $comment->user_id );
		if ( '' === $artist_lang ) {
			$artist_lang = SubmissionTranslator::resolve_target_language();
		}
		update_comment_meta( $comment_id, self::REPLY_SOURCE_LANG_META, $artist_lang );

		if ( null !== $translator ) {
			$primary_lang = SubmissionTranslator::resolve_target_language();

			if ( $primary_lang !== $artist_lang ) {
				update_comment_meta(
					$comment_id,
					self::REPLY_TRANSLATED_PRIMARY_META,
					$this->reply_translation_for( $translator, $comment->comment_content, $primary_lang, $artist_lang )
				);
			}

			$commenter_lang = $this->resolve_original_commenter_lang( (int) $comment->comment_parent, $primary_lang );
			if ( $commenter_lang !== $artist_lang && $commenter_lang !== $primary_lang ) {
				update_comment_meta(
					$comment_id,
					self::REPLY_TRANSLATED_CONTENT_META,
					$this->reply_translation_for( $translator, $comment->comment_content, $commenter_lang, $artist_lang )
				);
			}
		}

		delete_comment_meta( $comment_id, self::REPLY_PENDING_TRANSLATION_META );

		// RLM5 (REPLY-LANGUAGE-MIRRORING-ROADMAP.md, roadmap §4 Q3): mirror
		// this artist reply now that its translations are resolved — an
		// artist's reply is already comment_approved => 1 at insert time
		// (store_artist_gateway_reply()), so it never fires
		// transition_comment_status, which is why RLM2's own trigger point
		// can't reach it; this is the equivalent trigger for the outbound
		// direction. Re-fetch first: reply_translation_for() calls above ran
		// against a snapshot taken before this comment's own meta existed.
		$fresh = get_comment( $comment_id );
		if ( $fresh instanceof \WP_Comment ) {
			$this->mirror_reply_across_languages( $fresh );
		}

		// WP13 §13.4: federation moves here from store_artist_gateway_reply()'s
		// own synchronous call site — the Note's contentMap needs the
		// translations resolved above to exist before it's built.
		if ( '1' === (string) get_comment_meta( $comment_id, self::REPLY_FEDERATE_REQUESTED_META, true ) ) {
			$this->federate_artist_reply( $comment );
		}
	}

	/**
	 * The language an artist's reply's own PARENT comment was written in —
	 * WP13 §13.1's "original commenter's language" target. Reused directly
	 * from REPLY_SOURCE_LANG_META, never re-detected here: known instantly
	 * for a local visitor parent (submission-time LF language), or already
	 * detected at most once by drain_reply_translation_queue()'s inbound
	 * branch for a federated parent (see that constant's own docblock).
	 * Empty on either meta or an unresolvable parent means "the site's
	 * primary language" — the same convention used throughout this class —
	 * so $fallback_primary_lang is returned rather than ''.
	 */
	private function resolve_original_commenter_lang( int $parent_id, string $fallback_primary_lang ): string {
		if ( $parent_id <= 0 ) {
			return $fallback_primary_lang;
		}
		$parent = get_comment( $parent_id );
		if ( ! $parent instanceof \WP_Comment ) {
			return $fallback_primary_lang;
		}
		$lang = (string) get_comment_meta( $parent_id, self::REPLY_SOURCE_LANG_META, true );
		return '' !== $lang ? $lang : $fallback_primary_lang;
	}

	/**
	 * One reply's content translated into $target_lang, or '' when
	 * $target_lang already IS this reply's own known source language
	 * (non-empty $source_lang) — display_reply_content() already serves the
	 * untouched original for that exact case, so storing an identical
	 * "translation" would only be a wasted AI call. Uses translate_fields()
	 * rather than translate_text() specifically for its `$source_lang_code`
	 * parameter — reused by both the inbound and outbound (WP13) directions,
	 * $source_lang meaning "the reply's own known written language" either way.
	 */
	private function reply_translation_for( SubmissionTranslator $translator, string $content, string $target_lang, string $source_lang ): string {
		if ( '' !== $source_lang && $target_lang === $source_lang ) {
			return '';
		}

		$translated = $translator->translate_fields( [ 'content' => $content ], $target_lang, [], $source_lang );
		return $translated['content'] ?? '';
	}

	// -------------------------------------------------------------------------
	// Local (visitor) replies (interaction-surface roadmap, Phase 3, WP4, §4 Phase 3A)
	// -------------------------------------------------------------------------

	/** REST `permission_callback` for the reply form — coarse per-IP gate, checked before Turnstile/DB work, same convention as Artist\ContactForm::rate_limit(). */
	public function rate_limit_reply(): bool|WP_Error {
		return RateLimiter::check( 'agnosis_reply_form', self::REPLY_IP_LIMIT, self::REPLY_IP_WINDOW_SECONDS );
	}

	/**
	 * REST `validate_callback` for a length-capped text field — identical
	 * pattern to Artist\ContactForm::validate_max_length()/Artist\Admission's
	 * own version of the same check.
	 *
	 * @return true|WP_Error
	 */
	private static function validate_reply_field_length( string $value, int $max, string $field_label ): bool|WP_Error {
		if ( mb_strlen( $value ) > $max ) {
			return new WP_Error(
				'agnosis_field_too_long',
				sprintf(
					/* translators: 1: field name (e.g. "Name", "Bio", "Message"), 2: maximum character count */
					__( '%1$s must be %2$d characters or fewer.', 'agnosis' ),
					$field_label,
					$max
				),
				[ 'status' => 400 ]
			);
		}
		return true;
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
	 */
	public function submit_reply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$turnstile = Turnstile::verify( (string) ( $request->get_param( 'turnstile_token' ) ?? '' ) );
		if ( is_wp_error( $turnstile ) ) {
			return $turnstile;
		}

		$post_id = (int) $request->get_param( 'id' );
		$post    = $this->repliable_artwork( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$visitor_email = (string) $request->get_param( 'email' );

		$sender_limit = RateLimiter::check_sender( 'reply_form_sender', $visitor_email, self::REPLY_SENDER_LIMIT, self::REPLY_SENDER_WINDOW_SECONDS );
		if ( is_wp_error( $sender_limit ) ) {
			return $sender_limit;
		}

		$artist_limit_result = $this->check_reply_artist_limit( (int) $post->post_author, $visitor_email );
		if ( is_wp_error( $artist_limit_result ) ) {
			return $artist_limit_result;
		}

		$visitor_name = (string) ( $request->get_param( 'name' ) ?? '' );
		$message      = (string) $request->get_param( 'message' );
		$parent_id    = max( 0, (int) $request->get_param( 'parent' ) );

		$rejection_reason = $this->moderate_reply( $message );

		if ( '' === $rejection_reason ) {
			$this->store_local_reply( $post, $post_id, $parent_id, $visitor_name, $visitor_email, $message );
		} else {
			Logger::info(
				sprintf( 'ActivityPub::submit_reply: reply to post #%d rejected by content review — not stored.', $post_id ),
				'reply-form'
			);
		}

		// Deliberately identical response for a stored vs. a silently-rejected
		// reply — see method docblock.
		return new WP_REST_Response( [
			'message' => __( 'Thanks — your reply has been submitted and is awaiting approval.', 'agnosis' ),
		], 200 );
	}

	/**
	 * Insert the held comment, tag it with what's known about its language,
	 * and notify the artist — split out of submit_reply() only so that
	 * method's own gate/rate-limit/moderate sequence reads as one flat list
	 * of steps.
	 */
	private function store_local_reply( \WP_Post $post, int $post_id, int $parent_id, string $visitor_name, string $visitor_email, string $message ): void {
		$comment_id = wp_insert_comment( [
			'comment_post_ID'      => $post_id,
			'comment_author'       => '' !== $visitor_name ? $visitor_name : $visitor_email,
			'comment_author_email' => $visitor_email,
			'comment_content'      => $message,
			'comment_type'         => self::LOCAL_REPLY_COMMENT_TYPE,
			'comment_approved'     => 0,
			'comment_parent'       => $parent_id,
			'comment_agent'        => 'AgnosisReplyForm',
		] );

		if ( ! $comment_id ) {
			return;
		}

		// RLM1 (REPLY-LANGUAGE-MIRRORING-ROADMAP.md): tag this row as its own
		// canonical reply-group — see REPLY_GROUP_ID_META's own docblock.
		update_comment_meta( $comment_id, self::REPLY_GROUP_ID_META, (string) $comment_id );

		// Known ONLY here, for a local reply — the page's own LF language at
		// the moment of submission. See REPLY_SOURCE_LANG_META's own docblock
		// for what this unlocks in drain_reply_translation_queue().
		$source_lang = $this->resolve_post_lf_lang( $post_id );
		if ( '' !== $source_lang ) {
			update_comment_meta( $comment_id, self::REPLY_SOURCE_LANG_META, $source_lang );
		}
		update_comment_meta( $comment_id, self::REPLY_PENDING_TRANSLATION_META, '1' );

		// Notification fires once drain_reply_translation_queue() has produced
		// an artist-language translation (or established there's nothing to
		// translate) — never here, at insert time, before either is known. See
		// notify_artist_of_reply()'s own docblock for why.
	}

	/**
	 * Resolve $post_id to a real artwork accepting replies right now — a real
	 * `agnosis_artwork` (likeable_artwork()'s own 404 shape, reused rather
	 * than duplicated) that isn't per-artwork opted out
	 * (REPLIES_DISABLED_META, Artist\ContentEditor) and whose artist hasn't
	 * opted out account-wide (`_agnosis_replies_optout`,
	 * Artist\NotificationPreferences) — the exact same two gates
	 * handle_create_reply() already checks for a federated reply (roadmap §4
	 * step 5/step 3), applied here to the local form instead. §4 Phase 3A
	 * step 3: "An opted-out artwork must not render the form at all — not
	 * render it and reject on submit" — this is the server-side half of that;
	 * the client-side half is the form simply not rendering (see
	 * render_reply_overlay()).
	 *
	 * @return \WP_Post|WP_Error
	 */
	private function repliable_artwork( int $post_id ): \WP_Post|WP_Error {
		$post = $this->likeable_artwork( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! $this->replies_open( $post_id ) ) {
			return new WP_Error(
				'agnosis_replies_unavailable',
				__( "Replies aren't open on this artwork right now.", 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		return $post;
	}

	/**
	 * Whether $post_id currently accepts a NEW reply — the same two gates
	 * handle_create_reply() already checks for a federated reply (roadmap §4
	 * step 5/step 3): per-artwork `REPLIES_DISABLED_META`
	 * (Artist\ContentEditor) and the artist's own account-wide
	 * `_agnosis_replies_optout` (Artist\NotificationPreferences). Public so
	 * render_reply_overlay() (whether to render the form at all — §4 Phase 3A
	 * step 3: "must not render the form, not render it and reject on submit")
	 * and repliable_artwork() (the server-side half of that same rule) share
	 * one answer rather than two copies of the same two checks.
	 */
	public function replies_open( int $post_id ): bool {
		if ( '1' === (string) get_post_meta( $post_id, self::REPLIES_DISABLED_META, true ) ) {
			return false;
		}

		$author_id = (int) get_post_field( 'post_author', $post_id );
		return '1' !== (string) get_user_meta( $author_id, '_agnosis_replies_optout', true );
	}

	/**
	 * Third rate-limit tier: how many times $visitor_email may reply to
	 * $artist_id's work within the configured window. Deliberately reuses
	 * Artist\ContactForm's own `agnosis_contact_artist_limit`/
	 * `..._window_hours` options rather than adding a second Settings field
	 * for what is, from an abuse-prevention standpoint, the identical
	 * question ContactForm already answers for the same (artist, visitor)
	 * pair.
	 *
	 * @return true|WP_Error
	 */
	private function check_reply_artist_limit( int $artist_id, string $visitor_email ): bool|WP_Error {
		$limit = max( 1, (int) get_option( self::REPLY_ARTIST_LIMIT_OPTION, self::REPLY_ARTIST_LIMIT_DEFAULT ) );
		$hours = max( 1, (int) get_option( self::REPLY_ARTIST_LIMIT_WINDOW_OPTION, self::REPLY_ARTIST_LIMIT_WINDOW_DEFAULT_HOURS ) );

		return RateLimiter::check_sender(
			'reply_form_artist_' . $artist_id,
			$visitor_email,
			$limit,
			$hours * HOUR_IN_SECONDS
		);
	}

	/**
	 * Classify $message against EmbedPolicy::disallowed_categories() plus a
	 * reply-specific spam category — same reuse ContactForm::disallowed_categories()
	 * already established for its own message field, and the same
	 * fail-OPEN contract on an inconclusive AI verdict (Pipeline::classify_text()
	 * returning null): a held reply already gets a human review before
	 * publication, so silently dropping a genuine visitor's reply on a flaky
	 * AI call is judged worse than an occasional unfiltered one reaching that
	 * review.
	 *
	 * Returns '' when the reply is allowed through, or a human-readable
	 * rejection reason (never shown to the visitor — see submit_reply()'s
	 * own "identical response" discipline) when it should be discarded.
	 */
	private function moderate_reply( string $message ): string {
		$categories = array_merge(
			EmbedPolicy::disallowed_categories(),
			[ __( 'Spam, scams, or unsolicited commercial advertising unrelated to genuinely engaging with the artist about their work', 'agnosis' ) ]
		);

		$verdict = $this->pipeline()->classify_text( $message, $categories );

		if ( false === $verdict ) {
			return __( 'Flagged by automatic content review.', 'agnosis' );
		}

		return '';
	}

	/**
	 * Production Pipeline instance. Overridden by an anonymous subclass in
	 * tests to stub classify_text() without a real AI provider — same
	 * "protected factory method, overridden in an anonymous subclass"
	 * convention Artist\ContactForm/EmbedPolicyTest already use for the same
	 * class.
	 */
	protected function pipeline(): Pipeline {
		return new Pipeline();
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
		// RLM1 (REPLY-LANGUAGE-MIRRORING-ROADMAP.md): tag this row as its own
		// canonical reply-group — see REPLY_GROUP_ID_META's own docblock.
		update_comment_meta( $comment_id, self::REPLY_GROUP_ID_META, (string) $comment_id );

		// Notification fires once drain_reply_translation_queue() has produced
		// an artist-language translation (or established there's nothing to
		// translate) — never here, at insert time, before either is known. See
		// notify_artist_of_reply()'s own docblock for why.

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
	 * them at all. Includes a one-click gateway link
	 * (handle_reply_moderation()) — the same stateless, emailed-HMAC-link
	 * pattern already used throughout this plugin (VouchConfirm,
	 * AdmissionConfirm) for an artist to act without a WP login — plus the
	 * existing NotificationPreferences link so "opt out of reply
	 * notifications" is reachable from every single email, not just the first
	 * (Ulises: "on by default and possible to opt-out on... every reply").
	 *
	 * WP0 (agnosis-audit/INTERACTION-SURFACE-ROADMAP.md §8): the moderation
	 * link's token itself stays a stateless HMAC (reply_gateway_token(),
	 * consolidated to one action-agnostic token by WP7) — nothing new to
	 * store there — but it never used to expire at all. An expiry timestamp
	 * is now written once here, as comment meta, using the
	 * same `agnosis_review_token_expiry_days` option (default 7) every other
	 * stateless emailed action link in the plugin already honours
	 * (ApplicationBiography, PostCreator, Notification) — one consistent
	 * "how long do I have" window for an artist, not a second bespoke number
	 * just for replies. Stored once at send time rather than recomputed from
	 * the option at verify time: recomputing would silently move the
	 * deadline on a link already sitting in an artist's inbox the moment an
	 * admin changed the setting, exactly the trap ReviewEndpoints's stored
	 * `_agnosis_review_expiry` already avoids.
	 *
	 * Fixed, 2026-07-28 (same 0.9.59 patch — Ulises caught both in a live
	 * sent email): this only ever fires from drain_reply_translation_queue()
	 * now, once that queue has run for this exact comment — never at insert
	 * time, which is BEFORE either of the two things below can possibly be
	 * known:
	 *   1. Translation. An artist who doesn't read the reply's own source
	 *      language couldn't understand a reply shown untranslated — the
	 *      whole reason §4 Phase 3A step 6 built the three-version
	 *      translation model in the first place. `$comment` (not a raw
	 *      content string) lets this method call display_reply_content()
	 *      itself, resolving to whichever version actually belongs in front
	 *      of THIS artist: the untouched original when it already IS their
	 *      language, the artist-language translation once one exists, or the
	 *      original as a last-resort fallback if translation is
	 *      unavailable — never silently the wrong-language original just
	 *      because the mail went out before translation caught up.
	 *   2. The branded template. This was the only wp_mail() call in the
	 *      entire file — every other transactional email in the plugin
	 *      already goes through Core\EmailTemplate's shared branded shell
	 *      (header/accent/footer all reading from Settings → Branding); this
	 *      one was a bare plain-text string, unstyled and unbranded.
	 *
	 * @param \WP_Post    $post     The artwork the reply belongs to.
	 * @param \WP_Comment $comment  The held reply comment — its OWN content is
	 *                              never shown directly; display_reply_content()
	 *                              resolves the right version for the artist's
	 *                              language.
	 * @param bool        $is_local Whether this is a site-visitor reply (true)
	 *                              or a federated one (false) — only changes
	 *                              the intro line.
	 */
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
	 * @param array<int, string> $emails     Recipients core is about to notify.
	 * @param int                $comment_id
	 * @return array<int, string>
	 */
	public function suppress_native_reply_notifications( array $emails, int $comment_id ): array {
		$comment = get_comment( $comment_id );
		if ( $comment instanceof \WP_Comment && in_array( $comment->comment_type, self::REPLY_COMMENT_TYPES, true ) ) {
			return [];
		}
		return $emails;
	}

	private function notify_artist_of_reply( \WP_Post $post, \WP_Comment $comment, bool $is_local = false ): void {
		$comment_id = (int) $comment->comment_ID;
		$author_id  = (int) $post->post_author;
		$author     = get_userdata( $author_id );
		if ( ! $author || '' === $author->user_email ) {
			return;
		}

		$expiry_days = max( 1, (int) get_option( 'agnosis_review_token_expiry_days', 7 ) );
		update_comment_meta( $comment_id, self::REPLY_MODERATION_EXPIRY_META_KEY, time() + $expiry_days * DAY_IN_SECONDS );

		$locale = (string) get_user_meta( $author_id, 'locale', true );
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		// The artist's own reading language — same code
		// SubmissionTranslator::resolve_artist_lang() already resolves reply
		// translations against in drain_reply_translation_queue(), so this
		// always agrees with whichever version that queue actually stored.
		$artist_lang = SubmissionTranslator::resolve_artist_lang( $author_id );
		$content     = $this->display_reply_content( $comment, $artist_lang );

		$excerpt = wp_strip_all_tags( $content );
		if ( mb_strlen( $excerpt ) > 300 ) {
			$excerpt = mb_substr( $excerpt, 0, 300 ) . '…';
		}

		$subject = sprintf(
			/* translators: %s: artwork title. */
			__( 'New reply on "%s"', 'agnosis' ),
			$post->post_title
		);

		// WP4 (§4 Phase 3A step 7): "Someone replied to your artwork from the
		// Fediverse" is simply wrong for a site visitor with no fediverse
		// account at all — the intro line is the only thing that differs
		// between the two; the rest of the email (excerpt, gateway link,
		// preferences link) is identical regardless of which kind of reply
		// this is.
		$intro = $is_local
			? __( 'Someone left a reply on your artwork:', 'agnosis' )
			: __( 'Someone replied to your artwork from the Fediverse:', 'agnosis' );

		// WP7 (§4 Phase 3A, "the reply gateway page"): one link, not two —
		// the artist chooses approve/reject, optionally writes their own
		// reply, and optionally requests federation, all as one decision on
		// one page, reached via one button.
		$gateway_url = self::reply_gateway_url( $comment_id );
		$accent      = EmailTemplate::accent();

		ob_start();
		?>
		<p style="margin:0 0 20px;font-size:20px;color:#555;">
			<?php
			printf(
				/* translators: %s: the name of the person being greeted (may fall back to a generic greeting if unavailable) */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $author->display_name )
			);
			?>
		</p>
		<p style="margin:0 0 20px;font-size:19px;line-height:1.6;color:#555;">
			<?php echo esc_html( $intro ); ?>
		</p>
		<p style="margin:0 0 28px;padding:16px 20px;background:<?php echo esc_attr( EmailTemplate::notice_bg() ); ?>;border-left:3px solid <?php echo esc_attr( $accent ); ?>;border-radius:4px;font-size:18px;font-style:italic;line-height:1.6;color:#333;">
			&ldquo;<?php echo esc_html( $excerpt ); ?>&rdquo;
		</p>
		<p style="margin:0 0 24px;font-size:17px;line-height:1.6;color:#555;">
			<?php esc_html_e( "It's being held until you approve or reject it — you can also write your own reply from the same page.", 'agnosis' ); ?>
		</p>
		<table cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
		<tr><td>
			<?php echo EmailTemplate::button( $gateway_url, __( 'Review this reply', 'agnosis' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailTemplate::button() escapes internally. ?>
		</td></tr>
		</table>
		<p style="font-size:16px;color:#999;margin:0;">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of days the link stays valid */
					_n(
						'This link expires in %d day.',
						'This link expires in %d days.',
						$expiry_days,
						'agnosis'
					),
					$expiry_days
				)
			);
			?>
		</p>
		<?php
		$body_html = (string) ob_get_clean();

		ob_start();
		$prefs_html = EmailFooter::preferences_html( $author_id );
		if ( '' !== $prefs_html ) :
			?>
		<p style="margin:0;text-align:center;">
			<?php echo $prefs_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::preferences_html() escapes internally. ?>
		</p>
			<?php
		endif;
		$footer_extra_html = (string) ob_get_clean();

		$html_lang = str_replace( '_', '-', '' !== $locale ? $locale : get_locale() );

		wp_mail(
			$author->user_email,
			$subject,
			EmailTemplate::render( '' !== $html_lang ? $html_lang : 'en', $body_html, $footer_extra_html ),
			[
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . CommunityMailer::sender_header(),
				'Auto-Submitted: auto-generated',
				'X-Auto-Response-Suppress: All',
			]
		);

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	/**
	 * Email the artist that a federated reply arrived in a language that
	 * couldn't be identified as one of the site's own configured languages
	 * (WP13 §13.5) — a plain, informational, branded email with NO gateway
	 * link, no Approve/Reject, no "write your own reply" option, and no
	 * moderation-expiry meta, because none of that is offered here at all.
	 * Ulises's own confirmed answer: "we don't want to support undetectable
	 * or unsupported languages, this will complicate things a lot... it's up
	 * to the artist to decide what to do with the comment" — meaning the
	 * comment stays held (`comment_approved = 0`) exactly like any other
	 * reply, reachable only through the existing wp-admin backstop (Comments
	 * → All already lists it — §4 Phase 3A's own verified precedent), never
	 * through a no-login link this class would have to mint and secure.
	 *
	 * Only ever called from drain_reply_translation_queue() for a federated
	 * (REPLY_COMMENT_TYPE) comment flagged REPLY_UNSUPPORTED_LANG_META — see
	 * that constant's own docblock for exactly when this fires instead of
	 * notify_artist_of_reply().
	 *
	 * @param \WP_Post    $post    The artwork the reply belongs to.
	 * @param \WP_Comment $comment The held reply whose language is unsupported.
	 */
	private function notify_artist_of_unsupported_reply_language( \WP_Post $post, \WP_Comment $comment ): void {
		$author_id = (int) $post->post_author;
		$author    = get_userdata( $author_id );
		if ( ! $author || '' === $author->user_email ) {
			return;
		}

		$locale = (string) get_user_meta( $author_id, 'locale', true );
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		$excerpt = wp_strip_all_tags( $comment->comment_content );
		if ( mb_strlen( $excerpt ) > 300 ) {
			$excerpt = mb_substr( $excerpt, 0, 300 ) . '…';
		}

		$subject = sprintf(
			/* translators: %s: artwork title. */
			__( 'A reply on "%s" arrived in a language we could not identify', 'agnosis' ),
			$post->post_title
		);

		ob_start();
		?>
		<p style="margin:0 0 20px;font-size:20px;color:#555;">
			<?php
			printf(
				/* translators: %s: the name of the person being greeted (may fall back to a generic greeting if unavailable) */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $author->display_name )
			);
			?>
		</p>
		<p style="margin:0 0 20px;font-size:19px;line-height:1.6;color:#555;">
			<?php esc_html_e( "Someone replied to your artwork from the Fediverse, but we weren't able to identify the language it's written in, so we can't offer a translation or the usual reply/approval page for it.", 'agnosis' ); ?>
		</p>
		<p style="margin:0 0 28px;padding:16px 20px;background:<?php echo esc_attr( EmailTemplate::notice_bg() ); ?>;border-left:3px solid <?php echo esc_attr( EmailTemplate::accent() ); ?>;border-radius:4px;font-size:18px;font-style:italic;line-height:1.6;color:#333;">
			&ldquo;<?php echo esc_html( $excerpt ); ?>&rdquo;
		</p>
		<p style="margin:0;font-size:17px;line-height:1.6;color:#555;">
			<?php esc_html_e( 'This is for your information only — there is nothing to approve or reject here. If you\'d like to act on it, you can do so from your WordPress dashboard.', 'agnosis' ); ?>
		</p>
		<?php
		$body_html = (string) ob_get_clean();

		ob_start();
		$prefs_html = EmailFooter::preferences_html( $author_id );
		if ( '' !== $prefs_html ) :
			?>
		<p style="margin:0;text-align:center;">
			<?php echo $prefs_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::preferences_html() escapes internally. ?>
		</p>
			<?php
		endif;
		$footer_extra_html = (string) ob_get_clean();

		$html_lang = str_replace( '_', '-', '' !== $locale ? $locale : get_locale() );

		wp_mail(
			$author->user_email,
			$subject,
			EmailTemplate::render( '' !== $html_lang ? $html_lang : 'en', $body_html, $footer_extra_html ),
			[
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . CommunityMailer::sender_header(),
				'Auto-Submitted: auto-generated',
				'X-Auto-Response-Suppress: All',
			]
		);

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	// -------------------------------------------------------------------------
	// Reply moderation — the reply gateway page (no WP login required)
	// -------------------------------------------------------------------------

	/**
	 * Build the stateless one-click gateway URL for one comment.
	 *
	 * WP7 (interaction-surface roadmap, Phase 3, §4 Phase 3A, "the reply
	 * gateway page"): previously this took an `$action` parameter and minted
	 * a separate token per action (one Approve link, one Reject link) — the
	 * artist's decision was baked into WHICH link they clicked. The gateway
	 * page now shows both options (plus an optional reply textarea and
	 * federate checkbox) on ONE page reached via ONE link, and the artist's
	 * actual decision travels in the POST body (`reply_action`) instead — so
	 * the token itself no longer needs to name an action at all.
	 */
	private static function reply_gateway_url( int $comment_id ): string {
		return add_query_arg(
			[
				'agnosis_reply' => $comment_id,
				'token'         => self::reply_gateway_token( $comment_id ),
			],
			home_url( '/' )
		);
	}

	private static function reply_gateway_token( int $comment_id ): string {
		return hash_hmac( 'sha256', "{$comment_id}|reply_gateway", wp_salt( 'auth' ) );
	}

	/**
	 * Verify a reply-gateway link's token and expiry (WP0, agnosis-audit/
	 * INTERACTION-SURFACE-ROADMAP.md §8; consolidated to one action-agnostic
	 * token by WP7 — see reply_gateway_url()'s own docblock). Returns null
	 * when valid, or a user-facing error message when not. There's no REST
	 * layer on this path to hand a WP_Error to (unlike
	 * ReviewEndpoints::verify_token()), just a wp_die() page, so this returns
	 * a plain translated string instead.
	 */
	private static function verify_reply_gateway_token( int $comment_id, string $token ): ?string {
		if ( '' === $token || ! hash_equals( self::reply_gateway_token( $comment_id ), $token ) ) {
			return __( 'This link is invalid or has already expired.', 'agnosis' );
		}

		$expiry = (int) get_comment_meta( $comment_id, self::REPLY_MODERATION_EXPIRY_META_KEY, true );
		if ( $expiry && time() > $expiry ) {
			return __( 'This link has expired.', 'agnosis' );
		}

		return null;
	}

	/**
	 * Register the template_redirect handler for the gateway link above.
	 * Called from Core\Plugin, same as every other stateless-token flow.
	 */
	public function register_reply_moderation_handler(): void {
		add_action( 'template_redirect', [ $this, 'handle_reply_moderation' ] );
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
	 * @param array<string, string> $types Comment type labels keyed by slug.
	 * @return array<string, string>
	 */
	public function add_reply_type_filters( array $types ): array {
		$types[ self::REPLY_COMMENT_TYPE ]      = __( 'Fediverse replies', 'agnosis' );
		$types[ self::LOCAL_REPLY_COMMENT_TYPE ] = __( 'Site replies', 'agnosis' );
		return $types;
	}

	/**
	 * Whether the reply-gateway page's federate checkbox should exist at all
	 * for a reply against $post_id — shared by render_reply_gateway() (GET,
	 * decides whether to render the checkbox) and
	 * handle_reply_gateway_submission() (POST, decides whether to honor a
	 * submitted 'federate' value rather than trusting the client rendered
	 * what the server would have) so there is exactly one gate, not two that
	 * could drift.
	 *
	 * Gated on the site-wide `agnosis_activitypub_enabled` toggle (same
	 * option/fallback every other federation code path in this class already
	 * checks) and `FederationSettlement::is_federated()`.
	 *
	 * Known, deliberate narrowing (WP7): this calls `is_federated( $post_id,
	 * $post_id )` — correct for the common case where the reply's own post
	 * IS the primary, but under-counts a reply attached to a translated
	 * SIBLING of an actually-federated primary (no primary-from-sibling
	 * resolver exists anywhere in this codebase yet — Digest.php's own use of
	 * is_federated() already has both ids in hand from its own data
	 * structure, it doesn't derive one from the other). The gap is a false
	 * negative (checkbox hidden when it arguably shouldn't be), never a false
	 * positive. Still accepted as-is now that WP6 (federating artist replies
	 * outward) is built: WP6's own delivery logic (federate_artist_reply(),
	 * reply_in_reply_to()) never needed a primary-from-sibling resolver
	 * either — worth building one only if a real use for it shows up, not
	 * worth inventing untested plumbing for here.
	 */
	private function reply_gateway_federate_offered( int $post_id ): bool {
		return (bool) get_option( 'agnosis_activitypub_enabled', true )
			&& FederationSettlement::is_federated( $post_id, $post_id );
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
		$token      = sanitize_text_field( wp_unslash( $source['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		if ( ! $comment_id ) {
			wp_die(
				esc_html__( 'This link is invalid or has already expired.', 'agnosis' ),
				esc_html__( 'Link error', 'agnosis' ),
				[ 'response' => 400 ]
			);
		}

		$token_error = self::verify_reply_gateway_token( $comment_id, $token );
		if ( null !== $token_error ) {
			wp_die( esc_html( $token_error ), esc_html__( 'Link error', 'agnosis' ), [ 'response' => 400 ] );
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment || ! in_array( $comment->comment_type, self::REPLY_COMMENT_TYPES, true ) ) {
			wp_die(
				esc_html__( 'This reply no longer exists.', 'agnosis' ),
				esc_html__( 'Link error', 'agnosis' ),
				[ 'response' => 404 ]
			);
		}

		// GET only renders the gateway page — see method docblock. The token
		// travels back to the POST in the page's hidden field, never in the
		// form's action URL (same reasoning as ReviewConfirm's own review/
		// removal links).
		if ( ! $is_post ) {
			$this->render_reply_gateway( $comment, $token );
			return; // render_reply_gateway() always exits via wp_die().
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- token itself is the auth mechanism, see method docblock.
		$this->handle_reply_gateway_submission( $comment, $source );
	}

	/**
	 * Render the reply gateway page (WP7) — shows the reply's original
	 * content AND its artist-language translation (REPLY_TRANSLATED_CONTENT_META
	 * — "(translation pending)" when the async translation queue hasn't
	 * resolved it yet), an Approve and a Reject button in the SAME form
	 * (§4 Phase 3A: "One page does everything ... one POST, one decision"),
	 * an optional textarea for the artist to write their own reply, and —
	 * only when reply_gateway_federate_offered() says so — a checkbox
	 * ("also post my reply to the Fediverse"), default checked (§7 Q3).
	 *
	 * Deliberately NOT built as a ReviewConfirm::render_approve_confirm()-
	 * style prefill/retry loop: unlike that form's title/excerpt/body, there
	 * is no required field here that can be submitted blank in a way that
	 * needs a safeguard — the artist reply textarea is optional, so there is
	 * nothing to validate and re-render on failure for.
	 */
	private function render_reply_gateway( \WP_Comment $comment, string $token ): void {
		$comment_id = (int) $comment->comment_ID;
		$post_id    = (int) $comment->comment_post_ID;

		$original   = $comment->comment_content;
		$translated = (string) get_comment_meta( $comment_id, self::REPLY_TRANSLATED_CONTENT_META, true );

		$original_html = '<div style="margin:0 0 16px;">'
			. '<p style="font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#999;margin:0 0 6px;">' . esc_html__( 'Original', 'agnosis' ) . '</p>'
			. '<p style="font-size:17px;line-height:1.6;margin:0;">' . nl2br( esc_html( $original ) ) . '</p>'
			. '</div>';

		$translated_html = '' !== $translated
			? '<div style="margin:0 0 24px;padding:16px;background:#f7f7fb;border-radius:6px;">'
				. '<p style="font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#999;margin:0 0 6px;">' . esc_html__( 'Translated', 'agnosis' ) . '</p>'
				. '<p style="font-size:17px;line-height:1.6;margin:0;">' . nl2br( esc_html( $translated ) ) . '</p>'
				. '</div>'
			: '<p style="font-size:14px;color:#999;margin:0 0 24px;font-style:italic;">' . esc_html__( 'Translation pending…', 'agnosis' ) . '</p>';

		$federate_html = $this->reply_gateway_federate_offered( $post_id )
			? '<label style="display:block;margin:12px 0 0;font-size:15px;line-height:1.5;color:#555;">'
				. '<input type="checkbox" name="federate" value="1" checked style="margin-right:8px;">'
				. esc_html__( 'Also post my reply to the Fediverse.', 'agnosis' )
				. '</label>'
			: '';

		$html = sprintf(
			'<div style="max-width:560px;margin:60px auto;font-family:Georgia,serif;color:#222;">'
			. '<p style="font-size:34px;color:#7c6af7;margin:0 0 16px;text-align:center;">✦</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 20px;text-align:center;">%1$s</h1>'
			. '%2$s%3$s'
			. '<form method="post" action="%4$s">'
			. '<input type="hidden" name="agnosis_reply" value="%5$s">'
			. '<input type="hidden" name="token" value="%6$s">'
			. '<label style="display:block;font-size:14px;color:#888;margin:0 0 4px;">%7$s</label>'
			. '<textarea name="artist_reply" rows="4" style="width:100%%;box-sizing:border-box;padding:10px;font-size:16px;font-family:inherit;border:1px solid #ddd;border-radius:6px;margin:0 0 8px;"></textarea>'
			. '%8$s'
			. '<div style="text-align:center;margin-top:24px;">'
			. '<button type="submit" name="reply_action" value="approve" style="background:#7c6af7;color:#fff;border:0;border-radius:6px;padding:12px 28px;font-size:17px;font-family:inherit;cursor:pointer;margin-right:12px;">%9$s</button>'
			. '<button type="submit" name="reply_action" value="reject" style="background:transparent;color:#c0392b;border:1px solid #c0392b;border-radius:6px;padding:12px 28px;font-size:17px;font-family:inherit;cursor:pointer;">%10$s</button>'
			. '</div>'
			. '</form>'
			. '</div>',
			esc_html__( 'Review this reply', 'agnosis' ),
			$original_html, // Built entirely from esc_html()/nl2br() pieces above.
			$translated_html, // Ditto.
			esc_url( home_url( '/' ) ),
			esc_attr( (string) $comment_id ),
			esc_attr( $token ),
			esc_html__( 'Write your own reply (optional)', 'agnosis' ),
			$federate_html, // Built entirely from esc_html() pieces above.
			esc_html__( 'Approve', 'agnosis' ),
			esc_html__( 'Reject', 'agnosis' )
		);

		wp_die( $html, esc_html__( 'Review this reply', 'agnosis' ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped above.
	}

	/**
	 * Handle the gateway page's POST — one decision, three parts (§4 Phase
	 * 3A): approve or reject the ORIGINAL held reply, optionally store the
	 * artist's own reply alongside it, and optionally flag that reply for
	 * future federation (WP6). Always exits via wp_die().
	 *
	 * @param array<string, mixed> $source Sanitized $_POST superglobal.
	 */
	private function handle_reply_gateway_submission( \WP_Comment $comment, array $source ): void {
		$comment_id = (int) $comment->comment_ID;
		$post_id    = (int) $comment->comment_post_ID;

		$action = sanitize_key( wp_unslash( $source['reply_action'] ?? '' ) );
		if ( ! in_array( $action, [ 'approve', 'reject' ], true ) ) {
			wp_die(
				esc_html__( 'This link is invalid or has already expired.', 'agnosis' ),
				esc_html__( 'Link error', 'agnosis' ),
				[ 'response' => 400 ]
			);
		}

		if ( 'approve' === $action ) {
			wp_set_comment_status( $comment_id, 'approve' );
			$message = __( 'Reply approved — it now appears on your artwork.', 'agnosis' );
		} else {
			wp_trash_comment( $comment_id );
			$message = __( 'Reply rejected — it will not be shown.', 'agnosis' );
		}

		$artist_reply = sanitize_textarea_field( wp_unslash( $source['artist_reply'] ?? '' ) );
		if ( '' !== trim( $artist_reply ) ) {
			// reply_gateway_federate_offered() re-checked here (not just
			// trusted from whatever the submitted form happened to carry) —
			// see that method's own docblock for why the gate is shared
			// rather than duplicated.
			$federate_requested = $this->reply_gateway_federate_offered( $post_id ) && ! empty( $source['federate'] );
			$this->store_artist_gateway_reply( $post_id, $comment_id, $artist_reply, $federate_requested );
			$message .= ' ' . __( 'Your reply has been posted.', 'agnosis' );
		}

		wp_die( esc_html( $message ), esc_html__( 'Reply moderated', 'agnosis' ), [ 'response' => 200 ] );
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
	 */
	public function serve_reply_activity_json( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$comment_id = (int) $request->get_param( 'id' );

		$tombstone = $this->reply_tombstone_json( $comment_id );
		if ( null !== $tombstone ) {
			return new WP_REST_Response( $tombstone, 410 );
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment
			|| self::LOCAL_REPLY_COMMENT_TYPE !== $comment->comment_type
			|| '1' !== (string) get_comment_meta( $comment_id, self::REPLY_FEDERATED_META, true )
		) {
			return new WP_Error( 'agnosis_reply_not_found', __( 'No such reply.', 'agnosis' ), [ 'status' => 404 ] );
		}

		return new WP_REST_Response( $this->reply_to_note( $comment ), 200 );
	}

	/**
	 * Insert the artist's own optional reply from the gateway page. An
	 * ORDINARY WP4 local reply (WP6's own decision: "an artist reply
	 * submitted here is an ordinary WP4 reply authored by an artist"), not a
	 * new comment type — LOCAL_REPLY_COMMENT_TYPE, same as a site visitor's.
	 *
	 * Unlike Artist\ContentEditor's public submit_reply() — a site visitor's
	 * anonymous submission — this skips rate limiting, Turnstile, AI
	 * moderation, and the held/comment_approved=0 default entirely: the
	 * artist reached this exact form only via a token-verified emailed link
	 * tied to their OWN artwork, the same trust level every other artist-
	 * authenticated write in this plugin already gets (ReviewConfirm's own
	 * approve-form title/excerpt/body edits are equally unmoderated and
	 * auto-applied). There is also no realistic abuse vector: only the
	 * artist who received this specific email can reach this form at all.
	 *
	 * $federate_requested writes REPLY_FEDERATE_REQUESTED_META, a REQUEST
	 * flag only — federate_artist_reply() itself no longer fires from here
	 * (WP13 §13.4). It used to run inline, immediately, on the reasoning that
	 * "comment_approved => 1 already, so deliver-on-approve and deliver-at-
	 * insert are the same moment" — still true, but insufficient once WP13
	 * gave this reply its own outbound translation step: federating
	 * immediately would build the Note's contentMap before any translation
	 * could exist. Both this flag and REPLY_PENDING_TRANSLATION_META (set
	 * unconditionally below, whether or not federation was requested — the
	 * on-site three-version display needs translation regardless) are read
	 * by drain_outbound_reply_translation(), which calls
	 * federate_artist_reply() itself once translation resolves. There is
	 * still no other code path that can ever produce an artist-authored
	 * reply in the first place — the no-login rule means this gateway page
	 * is the only way one is ever created.
	 */
	private function store_artist_gateway_reply( int $post_id, int $parent_comment_id, string $message, bool $federate_requested ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$artist = get_userdata( (int) $post->post_author );
		if ( ! $artist ) {
			return;
		}

		$comment_id = wp_insert_comment( [
			'comment_post_ID'      => $post_id,
			'comment_parent'       => $parent_comment_id,
			'comment_content'      => $message,
			'comment_author'       => $artist->display_name,
			'comment_author_email' => $artist->user_email,
			'user_id'              => $artist->ID,
			'comment_type'         => self::LOCAL_REPLY_COMMENT_TYPE,
			'comment_approved'     => 1,
			'comment_agent'        => 'AgnosisReplyGateway',
		] );

		if ( ! $comment_id ) {
			return;
		}

		// RLM1/RLM5 (REPLY-LANGUAGE-MIRRORING-ROADMAP.md, roadmap §4 Q3 —
		// "for sure, that's vital"): an artist's own reply is now mirrored
		// too, same as a visitor's — tagged with its own group id here so
		// mirror_reply_across_languages() (called once translations resolve,
		// from drain_outbound_reply_translation() below, never here — this
		// reply is already comment_approved => 1 at insert time, so no
		// transition_comment_status ever fires for it) recognizes it as a
		// fresh canonical reply.
		update_comment_meta( $comment_id, self::REPLY_GROUP_ID_META, (string) $comment_id );

		if ( $federate_requested ) {
			update_comment_meta( $comment_id, self::REPLY_FEDERATE_REQUESTED_META, '1' );
		}

		// WP13 §13.1/§13.6: queue for the outbound translation step
		// regardless of whether federation was requested — the on-site
		// three-version display (source / primary / original commenter's
		// language) matters for every artist reply, not just a federated one.
		update_comment_meta( $comment_id, self::REPLY_PENDING_TRANSLATION_META, '1' );
	}

	/**
	 * Federate Create{Note} for a just-inserted, federate-requested artist
	 * reply (WP6). Delivers to two destinations, both best-effort — the
	 * existing deliver()/deliver_to_followers() already log and retry-queue
	 * on failure, nothing new needed here:
	 *
	 * - The artist's OWN followers (a broadcast — this is real content
	 *   published under their actor, same as any other Create).
	 * - DIRECTLY to the remote actor being replied to, when the parent is
	 *   itself a federated-inbound reply (reply_parent_actor()) — a
	 *   follower-list broadcast alone would never reach someone who replied
	 *   but doesn't happen to follow the artist back.
	 */
	private function federate_artist_reply( \WP_Comment $comment ): void {
		$comment_id = (int) $comment->comment_ID;
		$artist_id  = (int) $comment->user_id;

		update_comment_meta( $comment_id, self::REPLY_FEDERATED_META, '1' );

		$note = $this->reply_to_note( $comment );

		$activity = [
			'@context'  => self::CONTEXT,
			'type'      => 'Create',
			'id'        => $note['id'] . '#create',
			'actor'     => $note['attributedTo'],
			'published' => $note['published'],
			'to'        => $note['to'],
			'object'    => $note,
		];
		if ( isset( $note['cc'] ) ) {
			$activity['cc'] = $note['cc'];
		}

		$this->deliver_to_followers( $activity, 'artist', $artist_id );

		$parent_actor = $this->reply_parent_actor( $comment );
		if ( '' !== $parent_actor ) {
			$inbox = $this->resolve_inbox( $parent_actor );
			if ( null !== $inbox ) {
				$this->deliver( $inbox, $activity, 'artist', $artist_id );
			}
		}
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
	 */
	public function handle_reply_status_transition( string $new_status, string $old_status, \WP_Comment $comment ): void {
		if ( 'approved' === $new_status ) {
			$this->mirror_reply_across_languages( $comment );
			return;
		}

		if ( 'trash' !== $new_status ) {
			return;
		}

		$this->cascade_delete_reply_group( $comment );
		$this->maybe_federate_reply_removal( (int) $comment->comment_ID );
	}

	/**
	 * RLM2/RLM5 (REPLY-LANGUAGE-MIRRORING-ROADMAP.md §3, §4 Q2/Q3): once a
	 * reply is approved, mirror it as a REAL, already-approved comment row
	 * onto whichever of its three target languages (§2's deliberate
	 * cost-conscious scope, NOT every LF sibling) actually has a real
	 * sibling post today. A language that already IS the post the reply
	 * lives on needs no mirror; a language with no real sibling post is
	 * skipped silently — never force-creates a translated artwork post just
	 * to host a reply (§3.2).
	 *
	 * Ulises's own answers widened this from the original visitor-only,
	 * top-level-only build: "for sure, that's vital" (Q3, nested replies)
	 * and "we allow replies in every context for every user" (Q2) — so
	 * BOTH artist-authored replies and replies-to-a-reply are now in scope.
	 * create_reply_mirrors_for() branches the target-language computation on
	 * authorship (reply_mirror_target_langs()) and, for a nested reply, maps
	 * its own mirror's comment_parent to whichever row represents its
	 * parent's OWN reply-group on that same target sibling
	 * (find_mirror_on_sibling()) — skipping a sibling entirely if the parent
	 * has no representative row there yet, rather than orphaning a reply
	 * with no visible context.
	 *
	 * Only ever runs once per canonical reply: REPLY_GROUP_ID_META is set to
	 * the row's OWN id only at insertion time (RLM1); a row whose value
	 * differs from its own id is itself already a mirror, not a fresh
	 * canonical reply, and is skipped.
	 *
	 * After attempting this reply's own mirrors, recurses into any already-
	 * APPROVED direct child of it (roadmap §4 Q1/Q3's "cascade forward"):
	 * a child approved before its own parent could have been skipped on
	 * every sibling for lacking a mapped parent row there — now that this
	 * reply's mirrors may only just have been (re)created (by a fresh
	 * approval OR by backfill_reply_mirrors_for_new_sibling()'s sweep), its
	 * children get a fresh attempt too. Recursive, so an arbitrarily deep
	 * reply chain resolves in one pass regardless of the order replies
	 * happened to be approved in.
	 */
	private function mirror_reply_across_languages( \WP_Comment $comment ): void {
		if ( ! in_array( $comment->comment_type, self::REPLY_COMMENT_TYPES, true ) ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		$group_id   = (string) get_comment_meta( $comment_id, self::REPLY_GROUP_ID_META, true );
		if ( '' === $group_id || (string) $comment_id !== $group_id ) {
			return; // Not a fresh canonical reply (already a mirror, or never tagged).
		}

		$this->create_reply_mirrors_for( $comment, $group_id );

		$children = get_comments( [
			'parent' => $comment_id,
			'type'   => self::REPLY_COMMENT_TYPES,
			'status' => 'approve',
		] );
		if ( is_array( $children ) ) {
			foreach ( $children as $child ) {
				if ( $child instanceof \WP_Comment ) {
					$this->mirror_reply_across_languages( $child );
				}
			}
		}
	}

	/** The actual mirror-creation attempt for one canonical reply — split out of mirror_reply_across_languages() so its own recursive cascade-to-children step stays readable. */
	private function create_reply_mirrors_for( \WP_Comment $comment, string $group_id ): void {
		if ( ! function_exists( 'linguaforge_get_translations' ) ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		$post_id    = (int) $comment->comment_post_ID;
		$post       = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
		$translations = linguaforge_get_translations( $post_id );
		if ( empty( $translations ) ) {
			return; // No real LF siblings at all — nothing to mirror onto.
		}

		[ $source_lang, $primary_lang, $third_lang ] = $this->reply_mirror_target_langs( $comment, $post );

		$canonical_lang = $this->resolve_post_lf_lang( $post_id );
		if ( '' === $canonical_lang ) {
			$canonical_lang = $primary_lang;
		}

		$parent_id = (int) $comment->comment_parent;

		// The three target language identities, deduplicated — sometimes
		// two (or all three) coincide, per Ulises's own "sometimes it's
		// obviously only two languages... but that does not matter" (§2).
		foreach ( array_unique( [ $source_lang, $primary_lang, $third_lang ] ) as $target_lang ) {
			if ( $target_lang === $canonical_lang ) {
				continue; // Already visible right here — no mirror needed.
			}
			if ( ! isset( $translations[ $target_lang ] ) ) {
				continue; // No real sibling in this language — skip silently.
			}
			$sibling_id = (int) $translations[ $target_lang ];
			if ( 0 === $sibling_id || $sibling_id === $post_id ) {
				continue;
			}

			$mirror_parent_id = 0;
			if ( $parent_id > 0 ) {
				$mirror_parent_id = $this->find_mirror_on_sibling( $parent_id, $sibling_id );
				if ( 0 === $mirror_parent_id ) {
					continue; // This reply's own parent has no representative row on this sibling yet.
				}
			}

			$this->insert_reply_mirror(
				$comment,
				$sibling_id,
				$this->reply_mirror_content_for( $comment, $target_lang, $primary_lang, $third_lang, $source_lang ),
				$group_id,
				$mirror_parent_id
			);
		}
	}

	/**
	 * The [source_lang, primary_lang, third_lang] target-language triple for
	 * one reply — roadmap §4 Q3's widened scope means this now branches on
	 * authorship, since WP13's own outbound translation model already
	 * assigns a DIFFERENT meaning to REPLY_TRANSLATED_CONTENT_META depending
	 * on direction:
	 *   - INBOUND (visitor/federated, no `user_id`): source = the reply's
	 *     own known language; third slot = the ARTIST's native language
	 *     (resolve_artist_lang()) — REPLY_TRANSLATED_CONTENT_META holds
	 *     that, per drain_reply_translation_queue()'s inbound branch.
	 *   - OUTBOUND (artist-authored, real `user_id`): source = the artist's
	 *     OWN declared language (drain_outbound_reply_translation() always
	 *     sets REPLY_SOURCE_LANG_META to this) — "artist-native" would be
	 *     redundant as a separate slot here, since it already IS the
	 *     source, so the third slot is instead the ORIGINAL COMMENTER's
	 *     language (resolve_original_commenter_lang()), matching exactly
	 *     what REPLY_TRANSLATED_CONTENT_META holds for an outbound reply.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	private function reply_mirror_target_langs( \WP_Comment $comment, \WP_Post $post ): array {
		$primary_lang = SubmissionTranslator::resolve_target_language();

		// REPLY_SOURCE_LANG_META uses Agnosis's own "'' means the site's
		// primary language" convention (resolve_post_lf_lang()'s own
		// docblock) — never a LinguaForge lang code of '', which
		// linguaforge_get_translations() never returns as a key either.
		$source_lang = (string) get_comment_meta( (int) $comment->comment_ID, self::REPLY_SOURCE_LANG_META, true );
		if ( '' === $source_lang ) {
			$source_lang = $primary_lang;
		}

		if ( (int) $comment->user_id > 0 ) {
			$third_lang = $this->resolve_original_commenter_lang( (int) $comment->comment_parent, $primary_lang );
			return [ $source_lang, $primary_lang, $third_lang ];
		}

		$third_lang = SubmissionTranslator::resolve_artist_lang( (int) $post->post_author );
		if ( '' === $third_lang ) {
			$third_lang = $primary_lang;
		}
		return [ $source_lang, $primary_lang, $third_lang ];
	}

	/**
	 * The existing row representing $parent_id's OWN reply-group on
	 * $sibling_post_id — either a mirror of it, or $parent_id's own
	 * canonical row, should it happen to already live there — or 0 if
	 * neither exists (yet). Roadmap §4 Q2/Q3: a nested reply is only ever
	 * mirrored onto a sibling where its own parent already has a visible
	 * counterpart; see mirror_reply_across_languages()'s own cascade-to-
	 * children step for how a temporarily-skipped nested reply catches up
	 * once its parent's mirrors do get created.
	 */
	private function find_mirror_on_sibling( int $parent_id, int $sibling_post_id ): int {
		$parent_group_id = (string) get_comment_meta( $parent_id, self::REPLY_GROUP_ID_META, true );
		if ( '' === $parent_group_id ) {
			return 0; // Parent predates RLM1, or isn't a tracked reply at all.
		}

		$found = get_comments( [
			'post_id'    => $sibling_post_id,
			'meta_key'   => self::REPLY_GROUP_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded lookup for one specific reply group, not a listing query.
			'meta_value' => $parent_group_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value_field
			'status'     => 'any',
			'number'     => 1,
		] );
		if ( ! is_array( $found ) || ! isset( $found[0] ) || ! $found[0] instanceof \WP_Comment ) {
			return 0;
		}
		return (int) $found[0]->comment_ID;
	}

	/**
	 * Which stored version of the canonical reply's text to mirror for
	 * $target_lang — the same fallback discipline as display_reply_content()
	 * (never worse than the untouched original), keyed on real language
	 * codes rather than a viewed post, since a mirror has no "page being
	 * viewed" of its own. $third_lang is whichever of artist-native/
	 * original-commenter's-language reply_mirror_target_langs() resolved
	 * for this specific reply (see that method's own docblock) — the stored
	 * meta key is the same (REPLY_TRANSLATED_CONTENT_META) either way.
	 */
	private function reply_mirror_content_for( \WP_Comment $canonical, string $target_lang, string $primary_lang, string $third_lang, string $source_lang ): string {
		if ( $target_lang === $source_lang ) {
			return $canonical->comment_content;
		}

		$comment_id = (int) $canonical->comment_ID;

		if ( $target_lang === $primary_lang ) {
			$primary = (string) get_comment_meta( $comment_id, self::REPLY_TRANSLATED_PRIMARY_META, true );
			if ( '' !== $primary ) {
				return $primary;
			}
		}

		if ( $target_lang === $third_lang ) {
			$third_translation = (string) get_comment_meta( $comment_id, self::REPLY_TRANSLATED_CONTENT_META, true );
			if ( '' !== $third_translation ) {
				return $third_translation;
			}
		}

		return $canonical->comment_content;
	}

	/**
	 * Insert one real, already-approved mirror comment row on $sibling_post_id
	 * — indistinguishable from an ordinary approved reply to every existing
	 * read path (get_replies(), reply_count(), display_reply_content() all
	 * stay completely unchanged, roadmap §3.4), except for carrying
	 * REPLY_GROUP_ID_META so cascade_delete_reply_group() can find it later.
	 * $parent_id (roadmap §4 Q2/Q3) is 0 for a top-level reply, or the
	 * mapped parent row on THIS sibling for a nested one — see
	 * find_mirror_on_sibling().
	 *
	 * Idempotent: a duplicate hook fire must never insert a second mirror
	 * for the same (group, sibling) pair — checked directly rather than
	 * assumed, same "replay must upsert, not duplicate" discipline as
	 * handle_create_reply()'s own idempotency check above.
	 */
	private function insert_reply_mirror( \WP_Comment $canonical, int $sibling_post_id, string $content, string $group_id, int $parent_id = 0 ): void {
		$already = get_comments( [
			'post_id'    => $sibling_post_id,
			'meta_key'   => self::REPLY_GROUP_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off idempotency check per mirror insert, not a listing query.
			'meta_value' => $group_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value_field
			'status'     => 'any',
			'number'     => 1,
			'count'      => true,
		] );
		if ( $already > 0 ) {
			return;
		}

		$mirror_id = wp_insert_comment( [
			'comment_post_ID'      => $sibling_post_id,
			'comment_author'       => $canonical->comment_author,
			'comment_author_email' => $canonical->comment_author_email,
			'comment_author_url'   => $canonical->comment_author_url,
			'comment_content'      => $content,
			'comment_type'         => $canonical->comment_type,
			'comment_approved'     => 1,
			'comment_parent'       => $parent_id,
			'comment_agent'        => 'AgnosisReplyMirror',
			'comment_date_gmt'     => $canonical->comment_date_gmt,
			'comment_date'         => $canonical->comment_date,
		] );

		if ( ! $mirror_id ) {
			return;
		}

		update_comment_meta( $mirror_id, self::REPLY_GROUP_ID_META, $group_id );
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
	 */
	public function backfill_reply_mirrors_for_new_sibling( int $translated_id, int $source_id, string $target_lang ): void {
		if ( ! function_exists( 'linguaforge_get_translations' ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
		$group = linguaforge_get_translations( $source_id );
		$post_ids = array_unique( array_map( 'intval', array_merge( array_values( $group ), [ $source_id, $translated_id ] ) ) );

		$canonical_replies = [];
		foreach ( $post_ids as $post_id ) {
			if ( 0 === $post_id || 'agnosis_artwork' !== get_post_type( $post_id ) ) {
				continue;
			}
			$comments = get_comments( [
				'post_id' => $post_id,
				'type'    => self::REPLY_COMMENT_TYPES,
				'status'  => 'approve',
			] );
			if ( ! is_array( $comments ) ) {
				continue;
			}
			foreach ( $comments as $comment ) {
				if ( ! $comment instanceof \WP_Comment ) {
					continue;
				}
				$comment_id = (int) $comment->comment_ID;
				$group_id   = (string) get_comment_meta( $comment_id, self::REPLY_GROUP_ID_META, true );
				if ( '' !== $group_id && (string) $comment_id === $group_id ) {
					$canonical_replies[] = $comment;
				}
			}
		}

		usort( $canonical_replies, static fn( \WP_Comment $a, \WP_Comment $b ) => strcmp( $a->comment_date_gmt, $b->comment_date_gmt ) );

		foreach ( $canonical_replies as $comment ) {
			$this->mirror_reply_across_languages( $comment );
		}
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
	 */
	public function handle_reply_content_edit( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment || ! in_array( $comment->comment_type, self::REPLY_COMMENT_TYPES, true ) ) {
			return;
		}

		$group_id = (string) get_comment_meta( $comment_id, self::REPLY_GROUP_ID_META, true );
		if ( '' === $group_id || $group_id !== (string) $comment_id ) {
			return; // Only the canonical row's own edit cascades.
		}

		$mirrors = get_comments( [
			'meta_key'   => self::REPLY_GROUP_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded to one small reply group (at most a few rows), not a listing query.
			'meta_value' => $group_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value_field
			'status'     => 'any',
		] );
		if ( ! is_array( $mirrors ) || empty( $mirrors ) ) {
			return; // No mirrors exist yet — nothing to keep in sync.
		}

		$post_id = (int) $comment->comment_post_ID;
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		[ $source_lang, $primary_lang, $third_lang ] = $this->reply_mirror_target_langs( $comment, $post );

		$translator = SubmissionTranslator::from_settings();
		if ( null !== $translator ) {
			update_comment_meta( $comment_id, self::REPLY_TRANSLATED_PRIMARY_META, $this->reply_translation_for( $translator, $comment->comment_content, $primary_lang, $source_lang ) );
			update_comment_meta( $comment_id, self::REPLY_TRANSLATED_CONTENT_META, $this->reply_translation_for( $translator, $comment->comment_content, $third_lang, $source_lang ) );
			$comment = get_comment( $comment_id ); // Re-fetch so reply_mirror_content_for() below reads the just-refreshed meta.
		}

		foreach ( $mirrors as $mirror ) {
			if ( ! $mirror instanceof \WP_Comment ) {
				continue;
			}
			$mirror_id = (int) $mirror->comment_ID;
			if ( $mirror_id === $comment_id ) {
				continue;
			}

			$mirror_lang = $this->resolve_post_lf_lang( (int) $mirror->comment_post_ID );
			if ( '' === $mirror_lang ) {
				$mirror_lang = $primary_lang;
			}

			$new_content = $this->reply_mirror_content_for( $comment, $mirror_lang, $primary_lang, $third_lang, $source_lang );
			if ( $new_content === $mirror->comment_content ) {
				continue; // Idempotent — avoid a pointless write + recursive edit_comment firing.
			}
			wp_update_comment( [ 'comment_ID' => $mirror_id, 'comment_content' => $new_content ] );
		}
	}

	/**
	 * RLM3 (REPLY-LANGUAGE-MIRRORING-ROADMAP.md §4 Q5): rejecting/trashing
	 * the CANONICAL row of a mirrored reply removes every row sharing its
	 * group id outright (hard delete, not trash) — a mirror has no
	 * independent existence once the reply it reflects is gone (§4 Q2 is
	 * still open on independent INTERACTION with a mirror, but removal is
	 * unambiguous either way: nothing approved should keep showing a reply
	 * the artist just rejected).
	 *
	 * Deliberately one-directional: trashing an individual MIRROR (e.g. an
	 * admin trashes just the sibling-language copy from that sibling's own
	 * wp-admin comments list) does NOT cascade back to the canonical or the
	 * other mirrors — left as an ordinary, uncascaded moderation action
	 * until §4 Q2 is answered. Guarded by checking this row's own
	 * REPLY_GROUP_ID_META equals its own id, the same "am I canonical"
	 * check mirror_reply_across_languages() uses.
	 */
	private function cascade_delete_reply_group( \WP_Comment $comment ): void {
		$comment_id = (int) $comment->comment_ID;
		$group_id   = (string) get_comment_meta( $comment_id, self::REPLY_GROUP_ID_META, true );
		if ( '' === $group_id || $group_id !== (string) $comment_id ) {
			return;
		}

		$members = get_comments( [
			'meta_key'   => self::REPLY_GROUP_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded to one small reply group (at most 3 rows), not a listing query.
			'meta_value' => $group_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value_field
			'status'     => 'any',
		] );
		if ( ! is_array( $members ) ) {
			return;
		}

		foreach ( $members as $member ) {
			if ( ! $member instanceof \WP_Comment ) {
				continue;
			}
			$member_id = (int) $member->comment_ID;
			if ( $member_id === $comment_id ) {
				continue; // This one is already being trashed by WP itself.
			}
			wp_delete_comment( $member_id, true );
		}
	}

	/**
	 * Hook callback: 'delete_comment' fires for a hard/force delete that
	 * bypasses trash entirely (e.g. wp_delete_comment( $id, true )) — a case
	 * 'transition_comment_status' above never sees, since that path never
	 * calls wp_set_comment_status() at all (WP6).
	 */
	public function handle_reply_hard_delete( int $comment_id ): void {
		$this->maybe_federate_reply_removal( $comment_id );
	}

	/**
	 * Federate `Delete{Tombstone}` for a federated artist reply that's being
	 * removed, whichever path removed it (WP6). A no-op for anything that
	 * was never federated in the first place (an ordinary visitor reply, or
	 * an artist reply nobody ever asked to federate) — checked via
	 * REPLY_FEDERATED_META rather than assuming every LOCAL_REPLY_COMMENT_TYPE
	 * removal is meaningful.
	 */
	private function maybe_federate_reply_removal( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment || self::LOCAL_REPLY_COMMENT_TYPE !== $comment->comment_type ) {
			return;
		}
		if ( '1' !== (string) get_comment_meta( $comment_id, self::REPLY_FEDERATED_META, true ) ) {
			return;
		}

		$object_id = $this->reply_object_id_for( $comment_id );
		$deleted   = gmdate( 'c' );

		$this->record_reply_tombstone( $comment_id, $object_id, $deleted );
		delete_comment_meta( $comment_id, self::REPLY_FEDERATED_META );

		$artist_id = (int) $comment->user_id;

		$this->deliver_to_followers( [
			'@context' => self::CONTEXT,
			'type'     => 'Delete',
			'id'       => $object_id . '#delete',
			'actor'    => $this->actor_url_for( 'artist', $artist_id ),
			'to'       => [ 'https://www.w3.org/ns/activitystreams#Public' ],
			'object'   => [
				'type'       => 'Tombstone',
				'id'         => $object_id,
				'formerType' => 'Note',
				'deleted'    => $deleted,
			],
		], 'artist', $artist_id );
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

	// -------------------------------------------------------------------------
	// Relay support (interaction-surface roadmap, Phase 3, WP8, 2026-07-27)
	// -------------------------------------------------------------------------

	/**
	 * Build the `Follow` activity a relay subscription is expressed as —
	 * shared by follow_relay() (sent as-is) and unfollow_relay() (wrapped
	 * in `Undo`, so the two can never disagree about which follow relationship
	 * is being ended).
	 *
	 * Signed as the NODE's own actor, never an artist's — relays are
	 * node-level and admin-only (§7 Q8), the one genuinely operator-facing
	 * decision in this roadmap. The activity id is deterministic (derived
	 * from the relay's own actor URL, not time-based), so it never needs to
	 * be stored anywhere separately — same reasoning as WP5's
	 * boost_announce_activity()'s own id scheme, and for the same result: at
	 * most one active Follow can exist per relay, so the same id always
	 * identifies "our current subscription to this relay".
	 *
	 * @return array<string, mixed>
	 */
	private function relay_follow_activity( string $relay_actor_url ): array {
		$node_actor = $this->actor_url_for( 'node', 0 );

		return [
			'@context' => self::CONTEXT,
			'type'     => 'Follow',
			'id'       => $node_actor . '#follow-relay-' . md5( $relay_actor_url ),
			'actor'    => $node_actor,
			'object'   => $relay_actor_url,
		];
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
	 */
	public function follow_relay( string $relay_actor_url ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$inbox = $this->resolve_inbox( $relay_actor_url );
		if ( null === $inbox ) {
			return;
		}

		$this->deliver( $inbox, $this->relay_follow_activity( $relay_actor_url ), 'node', 0 );
	}

	/**
	 * Unsubscribe from a relay (WP8) — sent when an admin disables or
	 * removes a previously-added relay, so leaving is clean rather than
	 * just going quiet on our own end while the relay keeps us subscribed
	 * indefinitely.
	 */
	public function unfollow_relay( string $relay_actor_url ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$inbox = $this->resolve_inbox( $relay_actor_url );
		if ( null === $inbox ) {
			return;
		}

		$follow = $this->relay_follow_activity( $relay_actor_url );

		$this->deliver( $inbox, [
			'@context' => self::CONTEXT,
			'type'     => 'Undo',
			'id'       => $follow['id'] . '-undo',
			'actor'    => $follow['actor'],
			'object'   => $follow,
		], 'node', 0 );
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
