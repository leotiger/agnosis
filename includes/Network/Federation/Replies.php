<?php
/**
 * Replies — the conversation an artwork carries, in every language it speaks.
 *
 * Fifth and largest unit extracted from Network\ActivityPub (sixteenth audit,
 * Q-2, WP5 — agnosis-audit/ACTIVITYPUB-SPLIT-ROADMAP.md). It owns four things
 * that arrived as separate roadmap phases and are one subsystem in practice:
 *
 * - **Local (visitor) replies** — the on-artwork reply form, its three-tier
 *   throttle, AI moderation, and storage as `agnosis_reply` comments.
 * - **Federated replies** — an inbound `Create{Note}` from the Fediverse,
 *   stored as `agnosis_ap_reply`, and an artist's outbound reply back to it.
 * - **The reply gateway** — the no-login, HMAC-token moderation page an artist
 *   reaches from an email link, and everything it can do from there.
 * - **The three-version translation model and cross-language mirroring** — a
 *   reply stored in its source language, the site's primary language, and the
 *   original commenter's language, mirrored across every translated sibling of
 *   the artwork it belongs to.
 *
 * **Why this is one class and not three.** §3 asked the question and answered
 * it: the four areas above share the three-version meta model, the
 * `_agnosis_reply_group_id` convention, the mirroring logic and the artist
 * notification path — nine cross-calls and a large shared private vocabulary
 * between them. Splitting would mean either widening a dozen privates to public
 * or inventing a seventh class to hold what they share. It is large; the
 * alternative is worse.
 *
 * **`reply_to_note()` lives here, not in Serialization — a WP5 correction to
 * §2a.** §2a filed both Note builders as serializers that must sit above this
 * class. That is true of `post_to_note()`, which reads `interaction_counts()`
 * *and* `reply_count()`. It is not true of `reply_to_note()`: the call graph
 * shows it depends on four reply helpers and nothing else, so it is the AS2
 * serialization *of a reply*, built from reply data, consumed by two methods in
 * this class. Leaving it above would have created the one upward edge WP5 had.
 *
 * Depends on Identity, Delivery and Interactions — injected, never constructed
 * here, so the layering stays acyclic by construction:
 *
 *     Identity -> Delivery -> Interactions / Rhizome -> **Replies** -> Serialization
 *
 * The `pipeline()` factory is injected as a closure rather than defined here,
 * because the orchestrator's own `protected pipeline()` is the seam the test
 * suite overrides to stub AI classification without a provider. Moving the seam
 * would have silently disarmed that stub; passing it in keeps the override
 * effective and keeps this class from knowing where its Pipeline comes from.
 *
 * Behaviour is unchanged. Every method body is the one that stood in
 * ActivityPub.php; three visibilities widened (`handle_create_reply()`,
 * `maybe_delete_reply()`, `validate_reply_field_length()`) because the
 * orchestrator's `inbox()`, `handle_delete()` and `register_routes()` now reach
 * them from a sibling class.
 *
 * @package Agnosis\Network\Federation
 */

declare(strict_types=1);

namespace Agnosis\Network\Federation;

use Agnosis\AI\Pipeline;
use Agnosis\AI\SubmissionTranslator;
use Agnosis\Artist\NotificationPreferences;
use Agnosis\Compat\LinguaForge;
use Agnosis\Core\CommunityMailer;
use Agnosis\Core\EmailFooter;
use Agnosis\Core\EmailTemplate;
use Agnosis\Core\Logger;
use Agnosis\Core\Privacy;
use Agnosis\Core\RateLimiter;
use Agnosis\Core\Turnstile;
use Agnosis\Network\FederationSettlement;
use Agnosis\Publishing\EmbedPolicy;
use Agnosis\Publishing\ReviewConfirm;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Replies {

	/**
	 * @param \Closure():Pipeline|null $pipeline_factory Supplies the AI Pipeline; see the class docblock for why it is injected.
	 */
	public function __construct(
		private Identity $identity,
		private Language $language,
		private Delivery $delivery,
		private Interactions $interactions,
		private ?\Closure $pipeline_factory = null
	) {}

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
	/** Widened from private for Core\Privacy's own reply exporter/eraser (sixteenth audit, G-5) — same reuse-not-duplicate reasoning as actor_url_for()/like_identity() in 0.9.58. */
	public const REPLY_COMMENT_TYPES = [ self::REPLY_COMMENT_TYPE, self::LOCAL_REPLY_COMMENT_TYPE ];

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
	/** Widened from private for Core\Privacy's reply exporter/eraser (sixteenth audit, G-5). */
	public const REPLY_TRANSLATED_CONTENT_META = '_agnosis_reply_translated_content';

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
	/** Widened from private for Core\Privacy's reply exporter/eraser (sixteenth audit, G-5). */
	public const REPLY_TRANSLATED_PRIMARY_META = '_agnosis_reply_translated_primary';

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
	/** Widened from private for Core\Privacy's reply exporter/eraser (sixteenth audit, G-5). */
	public const REPLY_SOURCE_LANG_META = '_agnosis_reply_source_lang';

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
	public const TOMBSTONE_CAP = 500;


	/**
	 * Local (visitor) reply — interaction-surface roadmap, Phase 3, WP4,
	 * §4 Phase 3A step 2. Every constant/tier here is modeled line-for-line on
	 * Artist\ContactForm's own submit()/register_routes() — same field-length
	 * caps, same per-IP/per-sender/per-(artist,sender) three-tier throttle
	 * shape, reused rather than duplicated in spirit even though each class
	 * keeps its own copy of the actual constants (no shared base class exists
	 * for this pattern anywhere else in the codebase either).
	 */
	public const REPLY_MAX_NAME_LENGTH    = 150;
	public const REPLY_MAX_MESSAGE_LENGTH = 4000;

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

	/**
	 * The dereferenceable id a federated artist reply lives at — a REST
	 * route rather than a permalink (WP6), since a comment has no permalink
	 * of its own the way an artwork does via object_id_for().
	 */
	public function reply_object_id_for( int $comment_id ): string {
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
			'@context'   => Identity::CONTEXT,
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
		return $post instanceof \WP_Post ? $this->identity->object_id_for( $post ) : '';
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
	public function reply_to_note( \WP_Comment $comment ): array {
		$comment_id = (int) $comment->comment_ID;
		$post_id    = (int) $comment->comment_post_ID;
		$note_id    = $this->reply_object_id_for( $comment_id );

		$source_lang = (string) get_comment_meta( $comment_id, self::REPLY_SOURCE_LANG_META, true );
		if ( '' === $source_lang ) {
			$source_lang = $this->language->resolve_note_language( $post_id ); // Defensive only — see docblock.
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
			'@context'     => Identity::CONTEXT,
			'type'         => 'Note',
			'id'           => $note_id,
			'url'          => $note_id,
			'attributedTo' => $this->identity->actor_url_for( 'artist', (int) $comment->user_id ),
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
		$page_lang = $this->language->resolve_post_lf_lang( $post_id );

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
	 * The reply form itself — name (optional)/email/message, Turnstile widget
	 * when configured (§4 Phase 3A step 2's rate/verification stack), no
	 * federate checkbox here: §4 Phase 3B's "artist replies federate, visitor
	 * replies don't" split is entirely automatic (an artist is identified by
	 * whether they're logged in, not by a form choice) and WP5/3B isn't built
	 * yet regardless — see submit_reply()'s own docblock.
	 */
	private function render_reply_form( int $post_id ): string {
		// Sixteenth audit, A-1 (2026-07-31): every field carries a real, visually
		// hidden <label for>, not just a placeholder. This was the only
		// front-end form in the plugin without them — join, contact, newsletter
		// signup and notification preferences all associate labels, and the
		// fifteenth audit's A-2 established this exact pattern (a real
		// screen-reader-text <label> rather than aria-label, so the label text
		// IS the accessible name: voice control works, and translation tooling
		// that walks element text rather than ARIA attributes sees it).
		// Placeholders alone fail WCAG 3.3.2 and 4.1.2, and vanish the moment
		// the user types — on a three-field form that is precisely when the
		// labelling is needed.
		//
		// Ids are scoped by $post_id for the same reason render_reply_overlay()
		// scopes its own $panel_id: an archive or feed can legitimately render
		// this block for several artworks in one document, and duplicate ids
		// would silently point every label at the first form on the page.
		$field_id = 'agnosis-reply-' . $post_id;

		ob_start();
		?>
		<form class="agnosis-reply-overlay__form" data-agnosis-reply-form data-agnosis-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
			<label class="screen-reader-text" for="<?php echo esc_attr( $field_id ); ?>-message"><?php esc_html_e( 'Your reply', 'agnosis' ); ?></label>
			<textarea id="<?php echo esc_attr( $field_id ); ?>-message" name="message" class="agnosis-reply-overlay__form-message" rows="4" placeholder="<?php esc_attr_e( 'Write a reply…', 'agnosis' ); ?>" required></textarea>
			<div class="agnosis-reply-overlay__form-row">
				<label class="screen-reader-text" for="<?php echo esc_attr( $field_id ); ?>-name"><?php esc_html_e( 'Your name (optional)', 'agnosis' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $field_id ); ?>-name" name="name" placeholder="<?php esc_attr_e( 'Name (optional)', 'agnosis' ); ?>">
				<label class="screen-reader-text" for="<?php echo esc_attr( $field_id ); ?>-email"><?php esc_html_e( 'Your email address', 'agnosis' ); ?></label>
				<input type="email" id="<?php echo esc_attr( $field_id ); ?>-email" name="email" placeholder="<?php esc_attr_e( 'Your email', 'agnosis' ); ?>" required>
			</div>
			<?php echo Turnstile::render_widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Turnstile::render_widget() escapes its own output and returns '' when not configured. ?>
			<?php // Sixteenth audit, G-3: this form does strictly MORE with a visitor's data than the join or contact forms, both of which have carried a notice since the seventh audit's §4d, and it had none. Same element, class suffix and position (after Turnstile, before submit) as JoinPage and ContactFormBlock, so the three read identically. ?>
			<p class="agnosis-reply-overlay__privacy-notice">
				<?php echo Privacy::consent_notice_html( 'reply' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- consent_notice_html() escapes internally. ?>
			</p>
			<button type="submit" class="agnosis-reply-overlay__form-submit"><?php esc_html_e( 'Send reply', 'agnosis' ); ?></button>
			<p class="agnosis-reply-overlay__form-status" aria-live="polite"></p>
		</form>
		<?php
		return (string) ob_get_clean();
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
	public static function validate_reply_field_length( string $value, int $max, string $field_label ): bool|WP_Error {
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
		$source_lang = $this->language->resolve_post_lf_lang( $post_id );
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
		$post = $this->interactions->likeable_artwork( $post_id );
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
	 * The AI Pipeline this class classifies reply text with.
	 *
	 * Resolved through the injected factory rather than constructed here (WP5).
	 * The seam tests override is `Network\ActivityPub::pipeline()` — a protected
	 * factory method stubbed by an anonymous subclass, the same convention
	 * Artist\ContactForm/EmbedPolicyTest use for that class. Defining the seam
	 * here instead would have left those overrides silently ineffective and sent
	 * moderation at a real provider. The `new Pipeline()` fallback covers direct
	 * construction of this class without an orchestrator.
	 */
	private function pipeline(): Pipeline {
		return null !== $this->pipeline_factory ? ( $this->pipeline_factory )() : new Pipeline();
	}

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
	public function handle_create_reply( array $body ): WP_REST_Response {
		$ignored = new WP_REST_Response( [ 'status' => 'ignored', 'type' => 'Create' ], 200 );

		$object = $body['object'] ?? [];
		if ( ! is_array( $object ) || 'Note' !== ( $object['type'] ?? '' ) ) {
			return $ignored;
		}

		$in_reply_to = is_string( $object['inReplyTo'] ?? null ) ? $object['inReplyTo'] : '';
		$post_id     = $this->identity->resolve_local_post_id( $in_reply_to );
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

	public static function reply_gateway_token( int $comment_id ): string {
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
	public function store_artist_gateway_reply( int $post_id, int $parent_comment_id, string $message, bool $federate_requested ): void {
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
			'@context'  => Identity::CONTEXT,
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

		$this->delivery->deliver_to_followers( $activity, 'artist', $artist_id );

		$parent_actor = $this->reply_parent_actor( $comment );
		if ( '' !== $parent_actor ) {
			$inbox = $this->delivery->resolve_inbox( $parent_actor );
			if ( null !== $inbox ) {
				$this->delivery->deliver( $inbox, $activity, 'artist', $artist_id );
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

		$canonical_lang = $this->language->resolve_post_lf_lang( $post_id );
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

			$mirror_lang = $this->language->resolve_post_lf_lang( (int) $mirror->comment_post_ID );
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

		$this->delivery->deliver_to_followers( [
			'@context' => Identity::CONTEXT,
			'type'     => 'Delete',
			'id'       => $object_id . '#delete',
			'actor'    => $this->identity->actor_url_for( 'artist', $artist_id ),
			'to'       => [ 'https://www.w3.org/ns/activitystreams#Public' ],
			'object'   => [
				'type'       => 'Tombstone',
				'id'         => $object_id,
				'formerType' => 'Note',
				'deleted'    => $deleted,
			],
		], 'artist', $artist_id );
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
	public function maybe_delete_reply( array $body ): void {
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
}
