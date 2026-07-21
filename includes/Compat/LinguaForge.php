<?php
/**
 * Lingua Forge compatibility layer.
 *
 * Integrates Agnosis with Lingua Forge (the official translation plugin for
 * the Agnosis network) when both plugins are active on the same site.
 *
 * What this does when Lingua Forge is present — eight concerns, registered in
 * the constructor in this order:
 *
 *  1. LANGUAGE META — tags every new artwork post with `_lf_lang` (the source
 *     language of the artist's submission) so LF's router, hreflang system,
 *     and translation engine can all read the canonical language meta key.
 *
 *  2. TRANSLATION TRIGGER — after an artwork is published, calls LF's
 *     `linguaforge_trigger_translation()` function once per target language
 *     so the title, excerpt and body are translated into all configured site
 *     languages without the artist doing anything.
 *
 *  3. DUAL-TITLE HANDLING — keeps the artist's own `post_title` untranslated
 *     on every language version (via `linguaforge_translation_content`,
 *     hold_artist_title()); the per-language display title AI-translates
 *     separately into `_agnosis_translated_title`. Artwork and event
 *     (DUAL_TITLE_POST_TYPES) — biography's own title still uses LF's
 *     normal per-sibling translation instead (see hold_artist_title()'s
 *     docblock for why).
 *
 *  4. TRANSLATED-POST META PROPAGATION — a translated post is otherwise
 *     created (and, critically, re-translated) with none of the source's
 *     images/event/gallery meta; copy_translated_meta() / supply_translated_meta()
 *     copy a language-neutral allowlist across, refreshing it again on every
 *     re-translation (fourth audit, §4b).
 *
 *  5. TAG / MEDIUM TRANSLATION — LF's own translation pipeline never touches
 *     taxonomy terms at all, so a translated post is otherwise created with no
 *     tags and no medium term whatsoever. sync_translated_terms() translates
 *     and assigns both onto every translated sibling, cached per (taxonomy,
 *     term name, language) so a recurring agnosis_vendor_value (a common tag, or one of
 *     `agnosis_medium`'s built-in options) gets the same translated label
 *     every time rather than a fresh AI phrasing per post. Newly-created
 *     translated terms are flagged with TRANSLATED_TERM_META so the AI's own
 *     controlled vocabulary (`PromptConfig::medium_terms()`) doesn't end up
 *     polluted with machine-translated variants (fourth audit, §4c). The
 *     cache itself can be cleared from Settings → General (a manual "Clear
 *     Term Translation Cache" action) and is auto-invalidated per entry when
 *     a source term is renamed, since the cache key is the term's name
 *     (fourth audit, §4d).
 *
 *  6. SEO METADATA — overrides the Open Graph image with the artwork's
 *     featured thumbnail via the `linguaforge_seo_og_image` filter.
 *
 *  7. ARTWORK SCHEMA — hooks into LF's `linguaforge_seo_schema_data` filter
 *     to annotate artwork posts as `VisualArtwork` rather than generic `Article`.
 *
 *  8. TEMPLATE SAFEGUARD — LF >= 2.6.1 only. LF 2.6.1 fixed
 *     `TranslationTrigger::create_translated_post()` (the function Agnosis's
 *     own translation trigger above always goes through) so a newly created
 *     translation is correctly assigned its language-specific FSE template
 *     (`single-{post_type}-{lang}`) when one already exists in the DB. This
 *     is a defense-in-depth call on top of that fix, not a workaround for its
 *     absence: `sync_translated_template()` calls LF's new
 *     `linguaforge_sync_templates()` (also 2.6.1) after every translation
 *     completes, which re-resolves and re-writes `_wp_page_template` for
 *     every sibling in the post's translation group. It makes no AI call and
 *     touches no content — LF's own changelog documents it as "free to run
 *     and safe to run repeatedly" — so keeping it as a standing safeguard
 *     costs nothing even now that the underlying bug is fixed, and continues
 *     to protect against template drift from a future theme change, template
 *     rename, or an LF regression, without Agnosis needing to know any of
 *     LF's own template-resolution logic itself. No-ops entirely on any LF
 *     version before 2.6.1 (function_exists() guard) — the fix above still
 *     applies on 2.6.1+ either way, this is additive only.
 *
 *  9. PERMALINK FLUSH ON FAN-OUT COMPLETION — `mark_fanout_progress()` tracks
 *     (via `PENDING_FANOUT_META`) which of `request_translations()`'s target
 *     languages are still outstanding for a post, and once the last one
 *     finishes translating, schedules a debounced `flush_rewrite_rules()`
 *     call (`Core\RewriteFlush`) so every newly created translated sibling
 *     actually resolves instead of 404ing — see `RewriteFlush`'s own
 *     docblock for why WordPress needs that explicit nudge.
 *     `Publishing\ReviewEndpoints::finalize_publish()` schedules the same
 *     flush the moment the primary-language post (and native-language
 *     sibling, when one applies) is approved — the pair together cover both
 *     "content that never gets AI-translated" and "content that does."
 *
 * Since 0.9.22, agnosis.php declares `Requires Plugins: lingua-forge` —
 * WordPress itself now refuses to install or activate Agnosis at all until
 * Lingua Forge is installed and active (and, symmetrically, refuses to let an
 * operator deactivate or delete Lingua Forge while Agnosis is active). This
 * class's own function_exists()/class_exists() gating on every hook
 * registration stays regardless, as defense-in-depth: WordPress core's own
 * guidance on Plugin Dependencies is explicit that this mechanism only
 * enforces presence at install/activation time, has no minimum-version
 * support, and doesn't account for plugin load order — it does not replace
 * runtime feature-detection. Concretely, that means this class still no-ops
 * safely rather than fatal-erroring if Lingua Forge is ever removed via FTP,
 * or during the brief window before it's loaded relative to Agnosis on a
 * given request.
 *
 * @package Agnosis\Compat
 */

declare(strict_types=1);

namespace Agnosis\Compat;

use Agnosis\AI\CallCounter;
use Agnosis\AI\SubmissionTranslator;
use Agnosis\Core\Logger;
use Agnosis\Core\RewriteFlush;
use Agnosis\Publishing\PostCreator;

class LinguaForge {

	// LF does not expose active languages via a WP option — the canonical API
	// is the linguaforge_languages() global function (language-router module).

	/** All Agnosis CPT slugs — used to scope LF integrations to our content. */
	private const AGNOSIS_POST_TYPES = [
		'agnosis_artwork',
		'agnosis_biography',
		'agnosis_event',
	];

	/**
	 * Language-neutral post meta copied from a source post to its translations.
	 *
	 * Deliberately an allowlist, NOT "all _agnosis_* meta". The excluded keys
	 * must never reach a translated sibling:
	 *   • per-post security tokens — _agnosis_review_token / _agnosis_removal_token
	 *     (and their *_expiry) would be shared across posts;
	 *   • duplicate-detection identity — _agnosis_queue_id, _agnosis_image_hash
	 *     would make a translation collide with its source in the dedup matcher;
	 *   • language-specific values — _agnosis_translated_title (source-language AI
	 *     title) and _agnosis_detected_lang (source language) are wrong on a
	 *     translated post by definition.
	 *
	 * Image attachments are referenced by ID, so alt text (_wp_attachment_image_alt
	 * lives on the attachment) travels with them automatically — no copy needed.
	 *
	 * Event-only keys are harmless on artwork/biography posts (simply absent).
	 *
	 * _agnosis_intake_endpoint (0.9.5) is language-neutral by construction — it
	 * records which address (artwork/photo/pure) created the source submission,
	 * which has nothing to do with the target language. Included so a replace@
	 * resend that happens to land on a translated post's TRID group still finds
	 * the original intake strategy no matter which language member replace@'s
	 * own subject-match lookup (PostCreator::find_post_by_subject()) turns up.
	 *
	 * @var string[]
	 */
	private const NEUTRAL_META_KEYS = [
		'_thumbnail_id',                     // featured image (first gallery image)
		'_agnosis_gallery_ids',              // gallery attachment IDs
		'_agnosis_original_title',           // artist's own-words title (language-neutral)
		'_agnosis_event_location',           // events only
		'_agnosis_event_date',               // events only
		'_agnosis_intake_endpoint',          // which address created the artwork (artwork/photo/pure)
		'_agnosis_biography_portfolio_url',  // biography only — a URL is a URL regardless of page language
		'_agnosis_biography_portfolio_embedded', // biography only — the EmbedPolicy
												  // approval flag ('1'/'0') gating whether
												  // Artist\Profile::render_biography_social_links()
												  // shows the portfolio link at all. Without this,
												  // _agnosis_biography_portfolio_url above still
												  // copies correctly onto every sibling, but the
												  // render-side gate check defaults to '' (not '1')
												  // on a post that's never had this flag written to
												  // it directly — silently suppressing an
												  // already-approved portfolio link on every single
												  // sibling (native or Lingua Forge-translated),
												  // regardless of how correct the URL itself is.
		'_agnosis_biography_social_url_1',   // biography only — same
		'_agnosis_biography_social_url_2',   // biography only — same
		'_agnosis_biography_social_url_3',   // biography only — same
	];

	/**
	 * Per-language display-title map stored on a source post: BCP-47 code => title.
	 * Built (DUAL_TITLE_POST_TYPES only) from the primary-language title and
	 * consumed at translation time to set each translated post's
	 * `_agnosis_translated_title`.
	 */
	private const TITLE_I18N_META = '_agnosis_title_i18n';

	/**
	 * Post types whose own post_title is kept verbatim (never machine-
	 * translated) on every language version, with a separate AI-translated
	 * display title carried in `_agnosis_translated_title`/`_agnosis_title_i18n`
	 * instead — see hold_artist_title() and build_title_translations().
	 *
	 * Artwork always has; event joined in 0.9.24 (an event's own name, like an
	 * artwork's, is the artist's own words — a machine-translated version of
	 * it on every sibling was surfacing as an inconsistent/awkward literal
	 * translation of what's often effectively a proper noun, e.g. an
	 * exhibition's own title). Biography deliberately stays off this list:
	 * its title is ordinary translatable content with no equivalent
	 * "official own-language name" concept, so it keeps LF's normal
	 * per-sibling title translation (Settings → General → "Preset biography
	 * title" is a separate, unrelated mechanism — see Artist\BiographyTitle).
	 *
	 * Mirrors Artist\ContentEditor::DUAL_TITLE_POST_TYPES — kept as a
	 * separate constant (not shared across classes) since the two classes
	 * have no other coupling; if the list here ever changes, update that one
	 * too.
	 *
	 * @var string[]
	 */
	private const DUAL_TITLE_POST_TYPES = [ 'agnosis_artwork', 'agnosis_event' ];

	/** WP-Cron hook that runs the deferred translation kickoff (off the intake request). */
	private const DISPATCH_HOOK = 'agnosis_dispatch_lf_translations';

	/**
	 * Cache of translated taxonomy term names: taxonomy => source name => lang
	 * => translated name. See translated_term_name()'s docblock for why this
	 * exists (cost + cross-post consistency for a repeated tag/medium value).
	 */
	private const TERM_TRANSLATIONS_OPTION = 'agnosis_term_translations';

	/**
	 * Extra framing passed to SubmissionTranslator::translate_text()'s $context
	 * param for term-label translation — added 2026-07-19 after a live,
	 * reproducible report: "Mixed Media" was missing from German (and other
	 * languages') medium sync on every single retry, not a transient AI
	 * hiccup. A term label is exactly the "short, bare phrase with no
	 * surrounding sentence" shape Artist\BiographyTitle::
	 * HEADING_TRANSLATION_CONTEXT was already added for on 2026-07-18 (the
	 * "Meet the Artist" mis-gendering bug) — translate_text() silently falls
	 * back to the SOURCE name whenever the model's JSON response has a
	 * non-string value for the requested field (SubmissionTranslator::
	 * call_translate()'s own docblock: seen in practice on exactly this kind
	 * of short, context-free phrase), and sync_term_across_languages() then
	 * reports that as a `failed` language — reproducibly, since nothing
	 * about the input changes between retries. translated_term_name() was
	 * calling translate_text() with the bare two-argument form, never
	 * passing this context at all, unlike BiographyTitle's own call site.
	 */
	private const TERM_TRANSLATION_CONTEXT =
		'This text is a single controlled-vocabulary category label (like a tag '
		. 'or a dropdown option), not a sentence or phrase within running prose. '
		. 'Translate it as a short, natural label in the target language — a '
		. 'single word or short noun phrase, matching the source\'s length and '
		. 'form — rather than a full sentence or explanation.';

	/**
	 * Term meta key flagging a taxonomy term (post_tag or agnosis_medium) as
	 * one sync_taxonomy() itself created via AI translation, rather than one
	 * an admin created directly (fourth audit §4c). Value is the target
	 * language code the term was translated into.
	 *
	 * Public: `AI\PromptConfig::medium_terms()` reads this constant to exclude
	 * flagged terms from the AI's controlled vocabulary — see that method's
	 * docblock for why the pollution this prevents was a real bug, not
	 * theoretical (after a few translation passes on a multi-language site,
	 * the "controlled vocabulary" would otherwise contain every term times
	 * every language, and nothing would stop a translated label from being
	 * assigned to a brand-new, differently-languaged artwork).
	 */
	public const TRANSLATED_TERM_META = '_agnosis_translated_term';

	/**
	 * Term meta key holding a stable "translation group ID" (a v4 UUID)
	 * shared by a primary-language term and every one of its per-language
	 * translated copies — the term-level equivalent of the `$trid` Lingua
	 * Forge itself already uses to group a POST's translations together
	 * (see `create_native_sibling_post()`'s own `$trid` parameter).
	 *
	 * Added 2026-07-19 after a live incident made the previous design's
	 * flaw impossible to ignore: `sync_term_across_languages()` used to
	 * decide "does this primary term already have a Catalan translation?"
	 * by re-asking the AI to translate the name and checking whether a
	 * term with that EXACT resulting string already existed
	 * (`get_term_by( 'name', $translated_name, $taxonomy )`). That only
	 * works if the AI returns byte-identical output on every call for the
	 * same input, which it doesn't reliably — running "Sync all
	 * translations" twice created a fresh near-duplicate term instead of
	 * recognizing the one already there, and on a live site this had
	 * already drifted every language bucket in `agnosis_medium` out of the
	 * 1:1 parity with the 10 primary terms it's supposed to have (some
	 * languages short by several, others with 3 extra near-duplicate
	 * spellings of the same concept — reported live with a full term dump).
	 *
	 * With TERM_TRID_META, "does a translation already exist" is answered
	 * by an explicit, persistent term_id-to-term_id link, never by
	 * re-deriving and string-matching a name — making the sync genuinely
	 * idempotent: running it any number of times converges on exactly one
	 * term per (primary term, language) pair and then does nothing further.
	 */
	public const TERM_TRID_META = '_agnosis_term_trid';

	/**
	 * Term meta key flagging a translated term that was created as a
	 * FALLBACK placeholder — the source term's own name, reused verbatim —
	 * because AI translation wasn't available or failed, rather than a
	 * genuine AI-produced translation. Added 2026-07-19 (a follow-up to the
	 * term-translation-cache fix above): that fix stopped a failure from
	 * being permanently cached, but a live report made clear it wasn't
	 * enough on its own — a real AI provider still fails a real percentage
	 * of calls, and "Sync all translations" was consistently leaving a
	 * meaningful fraction of (term, language) pairs with NO term at all
	 * (German 9/10, Italian 8/10, Portuguese 6/10 reported on one run).
	 * `insert_fallback_translated_term()` now always creates SOMETHING —
	 * trid-linked like a genuine translation — so a taxonomy's per-language
	 * count is never short, and an admin can find and hand-correct exactly
	 * these terms (this meta is what the placeholder's readable `description`
	 * note, set at creation, explains) instead of the slot staying invisible
	 * and unfixable. Never set on a genuine AI-produced translation.
	 */
	public const TERM_NEEDS_TRANSLATION_META = '_agnosis_term_needs_translation';

	/**
	 * Post meta key tracking which target languages a source post's current
	 * translation fan-out (request_translations()) is still waiting on — a
	 * JSON-encoded array of BCP-47 language codes, written when the fan-out is
	 * dispatched and shrunk by one entry per `linguaforge_translation_complete`
	 * firing (mark_fanout_progress()) until empty, at which point every
	 * language Lingua Forge was asked to translate this post into actually
	 * exists and a permalink flush is scheduled — see RewriteFlush's own
	 * docblock for why that flush is needed at all, and this class's own
	 * docblock (concern list, point 2) for the fan-out this is tracking.
	 */
	private const PENDING_FANOUT_META = '_agnosis_lf_pending_fanout';

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	public function __construct() {
		// Compat notice runs regardless of whether LF is fully active — it needs
		// to warn admins even when LF is installed but misconfigured.
		if ( is_admin() ) {
			add_action( 'admin_notices', [ $this, 'compatibility_notices' ] );
		}

		if ( ! $this->is_active() ) {
			return;
		}

		// Language meta (synchronous, no AI — just a meta write).
		add_action( 'agnosis_post_published',        [ $this, 'set_language_meta'     ], 10, 1 );

		// Translation kickoff is deferred off the intake request: publishing only
		// schedules a single WP-Cron event. The actual AI work — building per-language
		// artwork titles and queueing the body translations — runs later in
		// dispatch_translations(), so a slow webhook/IMAP intake never blocks on N
		// (or 2N) AI calls. See schedule_translations() / dispatch_translations().
		//
		// accepted_args bumped 1 -> 2 (native-language pipeline, Phase 4, 2026-07-12
		// — agnosis-audit/NATIVE-LANGUAGE-PIPELINE.md §4d): a first-time publish of
		// a native-first draft (ReviewEndpoints::finalize_publish()'s direct branch)
		// now fires 'agnosis_post_published' with an optional second arg — the
		// artist's own native language, to exclude it from LF's AI-driven fan-out
		// now that a native-language sibling is created directly instead (see
		// sync_native_sibling()). The action's only other call site (the same
		// finalize_publish() method) is the one being updated to pass it; every
		// OTHER existing `add_action( 'agnosis_post_published', ..., 1 )` registration
		// on this same hook (ActivityPub::broadcast, this class's own
		// set_language_meta() just above) is unaffected — accepted_args is set
		// per-callback, not per-hook, so they simply continue to ignore the extra arg.
		add_action( 'agnosis_post_published',        [ $this, 'schedule_translations' ], 20, 2 );
		// accepted_args bumped 1 -> 2: dispatch_translations() now also accepts an
		// optional $exclude_langs list (see schedule_fanout(), used by
		// Artist\ContentEditor for front-end corrections — audit §7c, reassessed
		// 2026-07-06). Cron events scheduled before this change carry only one
		// stored arg; WP calls the callback with whatever args were stored, so
		// $exclude_langs simply falls back to its default there — no migration needed.
		add_action( self::DISPATCH_HOOK,             [ $this, 'dispatch_translations' ], 10, 2 );

		// Dual-title (DUAL_TITLE_POST_TYPES — artwork and event): keep the artist's
		// original title on translated posts. LF would otherwise translate
		// post_title — deliberately kept in the artist's own language, not the
		// primary — from the wrong source. The correct per-language title is
		// carried in _agnosis_translated_title instead. Biography still uses
		// LF's normal title translation.
		add_filter( 'linguaforge_translation_content', [ $this, 'hold_artist_title' ], 10, 3 );

		// Translated-post meta propagation. Without this, a translated artwork /
		// biography / event post is created with translated text but none of the
		// source's images, so the page renders empty. We copy a language-neutral
		// allowlist (see NEUTRAL_META_KEYS) from source to translation.
		//
		// LF 2.4.0 added linguaforge_translated_post_meta, which writes the meta as
		// the translated post is *born* (no empty-meta window) — prefer it when
		// available. But that filter only ever fires on LF's create path
		// (TranslationTrigger::create_translated_post()); update_translated_post()
		// — the re-translation path — applies no meta filter at all. So
		// copy_translated_meta() is ALSO registered on linguaforge_translation_complete
		// unconditionally (not just as a pre-2.4.0 fallback): that action fires on
		// both creation and re-translation, and copy_translated_meta() is a pure,
		// idempotent update_post_meta() re-copy — on first creation it merely
		// rewrites the values the born-with filter already supplied; on
		// re-translation it's the only thing that refreshes stale images/meta on
		// an existing translated sibling (fourth audit, §4b).
		if (
			defined( 'LINGUAFORGE_VERSION' )
			&& version_compare( (string) LINGUAFORGE_VERSION, '2.4.0', '>=' )
		) {
			add_filter( 'linguaforge_translated_post_meta', [ $this, 'supply_translated_meta' ], 10, 4 );
		}
		add_action( 'linguaforge_translation_complete', [ $this, 'copy_translated_meta' ], 10, 3 );

		// Preserve embedded other-language text through LF's own fan-out
		// translation pass (2026-07-21) — the same problem SubmissionTranslator's
		// PRESERVE_EMBEDDED_OTHER_LANGUAGE_INSTRUCTION already fixes for Agnosis's
		// own pre-publish translation, but LF's separate pass (fanning a published
		// artwork/biography/event out to the site's other configured languages)
		// hits it too: a Latin original quoted alongside the artist's own Catalan
		// translation of it was getting the Latin itself translated, collapsing a
		// deliberate two-language juxtaposition. LF 2.6.6 added the
		// linguaforge_translation_extra_instruction filter specifically for this
		// slot — version-gated the same way supply_translated_meta() is above,
		// since the filter doesn't exist at all on an older LF install.
		if (
			defined( 'LINGUAFORGE_VERSION' )
			&& version_compare( (string) LINGUAFORGE_VERSION, '2.6.6', '>=' )
		) {
			add_filter( 'linguaforge_translation_extra_instruction', [ $this, 'preserve_embedded_other_language_text' ], 10, 2 );
		}

		// Tag / medium translation (2026-07-08). Unlike the meta-propagation
		// above, this is NOT forked by LF version: LF 2.4.0's born-with filter
		// (linguaforge_translated_post_meta) fires before the translated post is
		// inserted and has no ID yet, so there is nothing to attach taxonomy
		// relationships to at that point regardless of version — term
		// assignment can only ever happen after insert. Always hooked on
		// linguaforge_translation_complete, which — per copy_translated_meta()'s
		// own docblock above — fires on both creation and re-translation either way.
		add_action( 'linguaforge_translation_complete', [ $this, 'sync_translated_terms' ], 10, 3 );

		// Re-propagate an artwork's medium term to its already-published
		// translated siblings whenever it's CHANGED after initial publish —
		// sync_translated_terms() just above only ever fires at translation-
		// creation time, so without this, editing a medium later would leave
		// every existing sibling stuck on the old translated term forever.
		// See on_medium_terms_changed()'s own docblock for the self-limiting
		// primary-language-only guard that keeps this from looping on the
		// wp_set_object_terms() call it itself makes on each sibling.
		add_action( 'set_object_terms', [ $this, 'on_medium_terms_changed' ], 10, 6 );

		// Language attribution at term-creation time (2026-07-19, prompted by
		// a live incident: 127 `post_tag` terms accumulated in the "primary"
		// bucket during Catalan testing, because nothing had ever recorded
		// what language a freshly auto-created term was actually written in
		// — see flag_newly_created_terms_by_post_language()'s own docblock.
		// Priority 5 so this runs BEFORE on_medium_terms_changed() (10) on
		// the same 'set_object_terms' firing: that method re-propagates a
		// medium to already-translated siblings and should see a just-
		// created term's language flag already settled, not stale.
		add_action( 'created_term', [ $this, 'track_newly_created_term' ], 10, 3 );
		add_action( 'set_object_terms', [ $this, 'flag_newly_created_terms_by_post_language' ], 5, 4 );

		// AI-call instrumentation (seventh audit G-2) — counts one real
		// translation call per genuine LF fan-out completion; skips the
		// synthetic firing sync_native_sibling() does for its own AI-free
		// sibling, same guard sync_translated_terms() above already uses.
		add_action( 'linguaforge_translation_complete', [ $this, 'count_fanout_translation_call' ], 10, 3 );

		// Permalink flush once EVERY target language this post's current
		// fan-out was dispatched to has finished translating — see
		// PENDING_FANOUT_META's own docblock and RewriteFlush. Deliberately
		// NOT skipped for the native-sibling's own synthetic firing the way
		// sync_translated_terms()/count_fanout_translation_call() are: that
		// language was excluded from request_translations()'s $languages list
		// in the first place (see schedule_fanout()'s $exclude_langs), so it
		// was never added to PENDING_FANOUT_META to begin with — no special
		// suppression guard is needed here for mark_fanout_progress() to stay
		// correct.
		add_action( 'linguaforge_translation_complete', [ $this, 'mark_fanout_progress' ], 10, 3 );

		// Template safeguard (2026-07-09) — LF >= 2.6.1 only. See concern #8
		// above for why this is additive defense-in-depth, not a workaround.
		if (
			defined( 'LINGUAFORGE_VERSION' )
			&& version_compare( (string) LINGUAFORGE_VERSION, '2.6.1', '>=' )
		) {
			add_action( 'linguaforge_translation_complete', [ $this, 'sync_translated_template' ], 10, 3 );
		}

		// Term-translation cache maintenance (fourth audit §4d): the cache is
		// keyed by the term's NAME, so renaming a source term orphans its old
		// cache entry (harmless — just an unbounded, unreachable row — but
		// cheap to avoid). edit_terms fires just before WP writes the new
		// name; edited_term fires just after, once the change is committed —
		// capturing the pre-update name in the first and comparing it to the
		// post-update name in the second is the standard WP pattern for
		// detecting a rename (there is no single "term renamed" hook).
		add_action( 'edit_terms',   [ $this, 'capture_pre_rename_term_name' ], 10, 2 );
		add_action( 'edited_term',  [ $this, 'invalidate_renamed_term_cache' ], 10, 3 );

		// SEO: Open Graph image override for artwork posts.
		add_filter( 'linguaforge_seo_og_image',      [ $this, 'filter_og_image'       ], 10, 1 );

		// Schema.org type override.
		add_filter( 'linguaforge_seo_schema_data',   [ $this, 'filter_schema_type'    ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Language meta
	// -------------------------------------------------------------------------

	/**
	 * Tag the post with its source language for Lingua Forge.
	 *
	 * LF reads `_lf_lang` on every post to decide which language it belongs to
	 * (router, hreflang, and Translation::run() source-language context). Agnosis
	 * normalises every submission's body and excerpt to the site PRIMARY language at
	 * intake (AI\SubmissionTranslator targets the `linguaforge_primary_language`
	 * option), so the post's language IS that primary language. We read the same
	 * option, falling back to the WP site locale only when LF's primary is not
	 * configured. (There is no source-language *detection*: the content is always
	 * primary-language at rest — see audit §3d.)
	 */
	public function set_language_meta( int $post_id ): void {
		if ( ! in_array( get_post_type( $post_id ), self::AGNOSIS_POST_TYPES, true ) ) {
			return;
		}

		$lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );

		if ( empty( $lang ) ) {
			// LF primary not configured yet — fall back to the WP site locale.
			$lang = self::locale_to_lang( get_locale() );
		}

		update_post_meta( $post_id, '_lf_lang', sanitize_text_field( $lang ) );
	}

	// -------------------------------------------------------------------------
	// Translation pipeline (deferred off the intake request)
	// -------------------------------------------------------------------------

	/**
	 * Schedule the deferred translation kickoff for a freshly published post.
	 *
	 * Runs on `agnosis_post_published`; does no AI work itself — it only queues a
	 * single WP-Cron event (debounced) so the actual title-building and translation
	 * requests run in a later cron request, never inside the intake (IMAP/webhook)
	 * request that published the post.
	 *
	 * @param int      $post_id       Source post ID.
	 * @param string[] $exclude_langs Target languages to skip in the fan-out (see
	 *                                schedule_fanout()). Always empty on the normal
	 *                                publish path — nothing to exclude at intake.
	 */
	public function schedule_translations( int $post_id, array $exclude_langs = [] ): void {
		self::schedule_fanout( $post_id, $exclude_langs );
	}

	/**
	 * Schedule the deferred translation fan-out for a post, optionally excluding
	 * one or more target languages.
	 *
	 * Static and self-contained (touches no instance state) so callers outside the
	 * single `new LinguaForge()` instance Plugin.php constructs — e.g.
	 * Artist\ContentEditor after a front-end correction — can trigger a fan-out
	 * without instantiating a second LinguaForge object, which would re-register
	 * every constructor hook a second time (double SEO filters, double
	 * set_language_meta, etc.).
	 *
	 * Used two ways:
	 *   - schedule_translations() calls this with an empty exclusion list on every
	 *     normal publish.
	 *   - Artist\ContentEditor calls this directly after translating a front-end
	 *     correction into the primary language, excluding the artist's own source
	 *     language — that post already holds the artist's verbatim edit, and
	 *     re-deriving it via a second AI round-trip (primary -> artist's language
	 *     again) could drift from what the artist actually wrote (audit §7c,
	 *     reassessed 2026-07-06).
	 *
	 * @param int      $post_id       Source (primary-language) post ID.
	 * @param string[] $exclude_langs Target language codes to skip.
	 */
	public static function schedule_fanout( int $post_id, array $exclude_langs = [] ): void {
		if ( ! in_array( get_post_type( $post_id ), self::AGNOSIS_POST_TYPES, true ) ) {
			return;
		}

		// Preserve the exact single-arg scheduling shape used since before Phase 1
		// front-end correction existed when there is nothing to exclude — the args
		// array is part of wp_next_scheduled()'s identity match, so appending an
		// always-present (even if empty) second element would silently stop
		// matching every pre-existing 1-arg wp_next_scheduled( DISPATCH_HOOK,
		// [ $post_id ] ) lookup, including this class's own dedup check on the
		// normal publish path and existing tests pinned to that signature.
		$args = empty( $exclude_langs ) ? [ $post_id ] : [ $post_id, $exclude_langs ];

		if ( ! wp_next_scheduled( self::DISPATCH_HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::DISPATCH_HOOK, $args );
		}
	}

	/**
	 * Cron callback: do the deferred translation work for a post.
	 *
	 * Order matters and is race-free: the per-language title map is built first
	 * (synchronously within this cron tick), then the body translations are queued.
	 * The queued translation jobs run in still-later ticks, by which point the map
	 * already exists for supply_translated_meta() to read.
	 *
	 * @param int      $post_id       Source post ID.
	 * @param string[] $exclude_langs Target languages to skip (see schedule_fanout()).
	 */
	public function dispatch_translations( int $post_id, array $exclude_langs = [] ): void {
		$this->build_title_translations( $post_id );
		$this->request_translations( $post_id, $exclude_langs );
	}

	/**
	 * Ask Lingua Forge to translate the post into all configured languages.
	 *
	 * Prefers `linguaforge_queue_translation()` (LF 2.4.0+) so each translation runs
	 * off-request via Action Scheduler / WP-Cron; falls back to the synchronous
	 * `linguaforge_trigger_translation()` on older LF. LF translates the title,
	 * content and excerpt and creates a TRID-linked post per language; images, the
	 * per-language display title, and `_lf_lang` are handled by the hooks above.
	 *
	 * No `$params` are passed: LF's defaults are exactly what we want. (The plugin
	 * previously sent `domain` / `priority` / `source` — none of which LF reads, so
	 * they were silently dropped. `with_meta_description` is intentionally not set:
	 * the translated excerpt LF already produces is the artwork's description.)
	 *
	 * Returns silently when LF is not loaded or there are no target languages.
	 *
	 * @param int      $post_id       Source post ID.
	 * @param string[] $exclude_langs Target language codes to skip in addition to
	 *                                the source language (already excluded by
	 *                                get_target_languages()). See schedule_fanout().
	 */
	public function request_translations( int $post_id, array $exclude_langs = [] ): void {
		if ( ! in_array( get_post_type( $post_id ), self::AGNOSIS_POST_TYPES, true ) ) {
			return;
		}

		if ( ! function_exists( 'linguaforge_trigger_translation' ) ) {
			return;
		}

		$source_lang = get_post_meta( $post_id, '_lf_lang', true ) ?: 'en';
		$languages   = $this->get_target_languages( $source_lang );

		if ( ! empty( $exclude_langs ) ) {
			$languages = array_values( array_diff( $languages, $exclude_langs ) );
		}

		if ( empty( $languages ) ) {
			return;
		}

		// Record what this fan-out is waiting on BEFORE dispatching a single
		// translation request — mark_fanout_progress() needs the full target
		// list in place before the first `linguaforge_translation_complete`
		// firing can possibly arrive (the async queue path can complete a
		// translation on a later cron tick well before this foreach below
		// even finishes queuing the rest). A resubmitted/edited post
		// (schedule_fanout() called again after a previous fan-out already
		// completed) simply overwrites this with a fresh target list, so the
		// flush at completion fires again for the new batch too.
		update_post_meta( $post_id, self::PENDING_FANOUT_META, wp_json_encode( $languages ) );

		foreach ( $languages as $target_lang ) {
			// Prefer the async queue (LF 2.4.0+); fall back to the synchronous trigger.
			// function_exists() is checked inline so static analysis narrows correctly.
			if ( function_exists( 'linguaforge_queue_translation' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
				linguaforge_queue_translation( $post_id, $target_lang );
			} else {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
				linguaforge_trigger_translation( $post_id, $target_lang );
			}
		}
	}

	/**
	 * Shrink the pending-fan-out target list (PENDING_FANOUT_META) by one
	 * language per `linguaforge_translation_complete` firing, and schedule a
	 * debounced permalink flush (RewriteFlush) the moment it empties out —
	 * i.e. once every language this post's fan-out was dispatched to actually
	 * exists as a real, dereferenceable post.
	 *
	 * No-ops for a language this post's CURRENT fan-out was never waiting on
	 * — a re-translation triggered some other way (an admin manually
	 * re-running LF's own translation tool, for instance) fires this same
	 * action but isn't one of request_translations()' own tracked targets, so
	 * there is nothing here to decrement.
	 *
	 * @param int    $translated_id Newly created/updated translated post ID (unused).
	 * @param int    $source_id     Source (primary-language) post ID.
	 * @param string $target_lang   Target language code that just finished.
	 */
	public function mark_fanout_progress( int $translated_id, int $source_id, string $target_lang ): void {
		unset( $translated_id );

		if ( ! in_array( get_post_type( $source_id ), self::AGNOSIS_POST_TYPES, true ) ) {
			return;
		}

		$pending_json = (string) get_post_meta( $source_id, self::PENDING_FANOUT_META, true );
		if ( '' === $pending_json ) {
			return; // Nothing tracked for this post — see this method's own docblock.
		}

		/** @var string[] $pending */
		$pending = (array) json_decode( $pending_json, true );
		if ( ! in_array( $target_lang, $pending, true ) ) {
			return; // Not one of the languages THIS fan-out is waiting on.
		}

		$pending = array_values( array_diff( $pending, [ $target_lang ] ) );

		if ( empty( $pending ) ) {
			delete_post_meta( $source_id, self::PENDING_FANOUT_META );
			RewriteFlush::schedule();
		} else {
			update_post_meta( $source_id, self::PENDING_FANOUT_META, wp_json_encode( $pending ) );
		}
	}

	// -------------------------------------------------------------------------
	// Native-language sibling (native-language pipeline, Phase 4)
	// -------------------------------------------------------------------------

	/**
	 * Suppression flag for sync_translated_terms() while sync_native_sibling()
	 * below fires 'linguaforge_translation_complete' for a sibling it just
	 * created/updated directly — see sync_native_sibling()'s own docblock for
	 * why. Static (not instance state) so it works regardless of which
	 * LinguaForge instance's hooks happen to be registered: sync_native_sibling()
	 * is deliberately callable without an instance (same reasoning as
	 * schedule_fanout()), so it has no `$this` to unhook a specific `[$this,
	 * 'sync_translated_terms']` registration the way an instance method could.
	 *
	 * @var boolean
	 */
	private static bool $suppress_native_sibling_term_sync = false;

	/**
	 * Create or update the artist's own native-language sibling post directly
	 * — no AI call — from the native-language content
	 * ReviewEndpoints::finalize_publish() preserves at approval (Phase 2, §4b:
	 * `_agnosis_native_lang`/`_agnosis_native_excerpt`/`_agnosis_native_body`/
	 * `_agnosis_native_medium`/`_agnosis_native_tags`). This is Phase 4 (§4d)
	 * of the native-language pipeline redesign — agnosis-audit/
	 * NATIVE-LANGUAGE-PIPELINE.md.
	 *
	 * Replicates the exact recipe confirmed against Lingua Forge's own source
	 * during Phase 0 (§4d) — `TranslationTrigger::create_translated_post()` /
	 * `update_translated_post()` — using only LF's public API, since those two
	 * methods are themselves private to LF and always spend an AI call
	 * regardless (`linguaforge_trigger_translation()`/`linguaforge_queue_translation()`
	 * have no way to bypass that): get-or-create the TRID
	 * (`linguaforge_get_trid()`/`linguaforge_set_trid()`), create the post via
	 * a plain `wp_insert_post()` — or update an existing one via
	 * `wp_update_post()` if this artist already has a sibling for this
	 * language, e.g. a resubmission or a second staged update — link it into
	 * the TRID group (`_lf_trid`/`_lf_lang`), clear LF's translation-lookup
	 * cache (`linguaforge_clear_translation_cache()`), assign the
	 * language-specific FSE template the same way LF's own creation path does
	 * (`Router::get_instance()->sync->assign_template_if_needed()` — confirmed
	 * public during Phase 0), and fire `linguaforge_translation_complete` so
	 * every OTHER integration hooked on it (this class's own
	 * `copy_translated_meta()`/`sync_translated_template()`, and any
	 * third-party listener) treats this sibling exactly like an
	 * LF-AI-translated one in every respect except its own tags/medium — see
	 * the suppression flag above for why those are excluded from that action's
	 * normal handling and set directly instead, from data that's already
	 * correct in the native language rather than needing translation at all.
	 *
	 * No-ops (nothing created, updated, or changed) when: Lingua Forge isn't
	 * active, $primary_post_id isn't an Agnosis CPT, no native language was
	 * ever recorded for it (`_agnosis_native_lang`, only set by the
	 * native-first pipeline), that language isn't one Lingua Forge is actually
	 * configured to route to, the artist's native language already matches
	 * the site's primary language (nothing to create — the primary post
	 * already serves that role), or Phase 3 never actually preserved any
	 * native content for this post (both `_agnosis_native_excerpt` and
	 * `_agnosis_native_body` empty — e.g. called on a post that was never
	 * translated to begin with).
	 *
	 * Public and static — self-contained, deliberately callable without
	 * instantiating a `LinguaForge` object, same reasoning as
	 * `schedule_fanout()`: a second `new LinguaForge()` would re-register
	 * every constructor hook a second time.
	 */
	public static function sync_native_sibling( int $primary_post_id ): void {
		if ( ! self::is_active() || ! in_array( get_post_type( $primary_post_id ), self::AGNOSIS_POST_TYPES, true ) ) {
			return;
		}

		$native_lang = (string) get_post_meta( $primary_post_id, '_agnosis_native_lang', true );
		if ( '' === $native_lang || ! function_exists( 'linguaforge_languages' ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
		if ( ! in_array( $native_lang, linguaforge_languages(), true ) ) {
			return; // Site isn't configured to route to this language at all.
		}

		$primary_lang = (string) get_post_meta( $primary_post_id, '_lf_lang', true );
		if ( $native_lang === $primary_lang ) {
			return; // Artist already writes in the site's primary language — the primary post IS the native one.
		}

		$native_excerpt = (string) get_post_meta( $primary_post_id, '_agnosis_native_excerpt', true );
		$native_body    = (string) get_post_meta( $primary_post_id, '_agnosis_native_body', true );
		if ( '' === $native_excerpt && '' === $native_body ) {
			return; // Nothing preserved to build a sibling from.
		}

		$source = get_post( $primary_post_id );
		if ( ! $source instanceof \WP_Post ) {
			return;
		}

		if ( ! function_exists( 'linguaforge_get_trid' ) || ! function_exists( 'linguaforge_set_trid' )
			|| ! function_exists( 'linguaforge_get_translations' ) || ! function_exists( 'linguaforge_clear_translation_cache' )
		) {
			return; // Defensive — these are all core language-router functions; absence means an LF version this integration can't drive.
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
		$trid = linguaforge_get_trid( $primary_post_id );
		if ( '' === $trid ) {
			$trid = wp_generate_uuid4();
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
			linguaforge_set_trid( $primary_post_id, $trid );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
		$translations = linguaforge_get_translations( $primary_post_id );
		$sibling_id   = (int) ( $translations[ $native_lang ] ?? 0 );

		// Native excerpt/body were already stripped of markup when preserved
		// (ReviewEndpoints::translate_native_content_to_primary()) — rebuild
		// the sibling's content the same way the primary post's own content is
		// shaped: leading image/gallery block(s), then one paragraph block.
		// Image blocks are language-neutral (the same photos), so they're
		// copied verbatim from whatever the primary post's CURRENT content
		// already has at its top, rather than re-derived from
		// `_agnosis_gallery_ids` — simpler, and guaranteed to match exactly
		// what the primary post is actually showing.
		$image_blocks = '';
		if ( preg_match( '/^((?:<!-- wp:(?:image|gallery)[^>]*-->.*?<!-- \/wp:(?:image|gallery) -->[\s]*)+)/s', $source->post_content, $matches ) ) {
			$image_blocks = trim( $matches[1] );
		}
		// Same wpautop() + paragraphs_to_blocks() fix as ReviewEndpoints::save()/
		// translate_native_content_to_primary() (2026-07-21) — $native_body is
		// plain text (stripped of markup when preserved, per the comment above)
		// and previously got the identical single-<p>-no-wpautop() treatment,
		// losing the artist's own line breaks on the native-language sibling post.
		$body_block = '' !== $native_body
			? PostCreator::paragraphs_to_blocks( wpautop( wp_kses_post( $native_body ) ) )
			: '';
		$content = $image_blocks ? $image_blocks . "\n\n" . $body_block : $body_block;

		if ( $sibling_id > 0 ) {
			$sibling_id = self::update_native_sibling_post( $sibling_id, $source, $native_excerpt, $content );
		} else {
			$sibling_id = self::create_native_sibling_post( $source, $native_lang, $trid, $native_excerpt, $content );
		}

		if ( 0 === $sibling_id ) {
			return; // Insert/update failed — already logged by the helper.
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
		linguaforge_clear_translation_cache( $sibling_id );

		if ( function_exists( 'linguaforge_mark_translation_synced' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
			linguaforge_mark_translation_synced( $sibling_id );
		}

		if ( class_exists( '\LinguaForge\Router\Router' ) ) {
			$router       = \LinguaForge\Router\Router::get_instance();
			$sibling_post = get_post( $sibling_id );
			if ( $sibling_post instanceof \WP_Post ) {
				$router->sync->assign_template_if_needed( $sibling_id, $sibling_post, $native_lang );
			}
		}

		// Suppressed for the exact duration of this action: our own
		// sync_translated_terms() (hooked on this same action for every OTHER
		// LF-AI-translated sibling) would otherwise spend an AI call
		// re-translating tags/medium this sibling already has correctly, in
		// its own native language (see Phase 0, §4d). Every OTHER listener on
		// this action — copy_translated_meta(), sync_translated_template(),
		// and any third-party integration — fires completely normally.
		//
		// try/finally (seventh audit §2c): without it, a throwing listener on
		// 'linguaforge_translation_complete' (this class's own two, or any
		// third-party integration) would leave the flag stuck true for the
		// rest of the request/cron tick — silently suppressing tag/medium
		// translation for every OTHER genuinely-AI-translated sibling synced
		// afterward in that same tick, with no indication anything was wrong.
		self::$suppress_native_sibling_term_sync = true;
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
			do_action( 'linguaforge_translation_complete', $sibling_id, $primary_post_id, $native_lang );
		} finally {
			self::$suppress_native_sibling_term_sync = false;
		}

		// Assign the already-native tags/medium directly — no AI call, and no
		// re-derivation from the (primary-language) $translated['tags']/
		// ['medium'] this whole redesign exists precisely to avoid spending a
		// second translation pass on.
		$native_tags_json = (string) get_post_meta( $primary_post_id, '_agnosis_native_tags', true );
		$native_tags      = $native_tags_json ? (array) json_decode( $native_tags_json, true ) : [];
		if ( ! empty( $native_tags ) ) {
			wp_set_post_tags( $sibling_id, $native_tags );
		}

		if ( 'agnosis_artwork' === $source->post_type ) {
			$native_medium = (string) get_post_meta( $primary_post_id, '_agnosis_native_medium', true );
			if ( '' !== $native_medium ) {
				wp_set_object_terms( $sibling_id, $native_medium, 'agnosis_medium' );
			}
		}
	}

	/**
	 * Trash the native-language sibling built for $old_native_lang, when a
	 * staged update changes (or clears) the artist's declared native
	 * language — seventh audit §2b, `NATIVE-LANGUAGE-PIPELINE.md` Phase 2's
	 * own documented follow-up. sync_native_sibling() above only ever syncs
	 * whichever language currently sits on the post it's given — once the
	 * artist's declared language changes, the sibling built for the
	 * PREVIOUS language has no future writer at all: nothing would ever
	 * touch it again, and it would stay published, silently diverging from
	 * the primary post forever. Trashed (recoverable via wp-admin, not
	 * force-deleted) rather than left as stale content with no indication
	 * anything changed — this is a real, deliberate change the artist made,
	 * not spam/abuse cleanup.
	 *
	 * Called from `ReviewEndpoints::finalize_publish()`'s staged-update
	 * branch — $old_native_lang must be $primary_post_id's own PRE-update
	 * `_agnosis_native_lang` value, read before that method's copy loop
	 * overwrites it with the current submission's value.
	 */
	public static function trash_orphaned_native_sibling( int $primary_post_id, string $old_native_lang ): void {
		if ( ! self::is_active() || '' === $old_native_lang || ! function_exists( 'linguaforge_get_translations' ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
		$translations = linguaforge_get_translations( $primary_post_id );
		$sibling_id   = (int) ( $translations[ $old_native_lang ] ?? 0 );

		// No sibling ever existed for that language (e.g. the artist's prior
		// language wasn't LF-configured, or sync_native_sibling() had
		// already no-op'd for some other reason) — nothing to trash. The
		// $sibling_id === $primary_post_id guard covers the degenerate case
		// where the "sibling" the translation group returns is the primary
		// post itself (its own source-language entry).
		if ( $sibling_id <= 0 || $sibling_id === $primary_post_id ) {
			return;
		}

		$sibling = get_post( $sibling_id );
		if ( ! $sibling instanceof \WP_Post || in_array( $sibling->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return;
		}

		wp_trash_post( $sibling_id );

		Logger::info(
			sprintf(
				'LinguaForge::trash_orphaned_native_sibling(): trashed sibling #%d (language "%s") for primary post #%d — the artist\'s declared native language changed and this sibling would otherwise never be updated again.',
				$sibling_id,
				$old_native_lang,
				$primary_post_id
			),
			'lingua-forge'
		);
	}

	/**
	 * Insert the native-language sibling post — the create half of
	 * sync_native_sibling(). Mirrors `TranslationTrigger::create_translated_post()`'s
	 * own recipe: bypass `wp_after_insert_post` handlers during the insert
	 * (same reasoning LF's own code documents — those handlers assume a
	 * translation event that hasn't happened yet at this point,
	 * `_lf_trid`/`_lf_lang` aren't written until after this insert returns),
	 * apply the `linguaforge_translated_post_meta` filter for born-with meta
	 * (this class's own supply_translated_meta() is already registered on it
	 * — see the constructor — so the sibling gets its gallery/thumbnail/
	 * original title the moment it exists, same as any LF-AI-translated
	 * sibling), then write `_lf_trid`/`_lf_lang` once the post has an ID.
	 *
	 * post_title is the primary post's own post_title, verbatim — never a
	 * synthetic "Title [XX]" fallback the way LF's own `build_create_args()`
	 * falls back to for an AI translation with no result: this class's
	 * dual-title design means post_title is ALREADY the artist's own native
	 * words, identical for every language sibling by design (see
	 * hold_artist_title()) — there's nothing to translate or fall back on here.
	 *
	 * @return int New post ID, or 0 on failure (logged).
	 */
	private static function create_native_sibling_post( \WP_Post $source, string $target_lang, string $trid, string $excerpt, string $content ): int {
		$insert = [
			'post_title'   => $source->post_title,
			'post_excerpt' => $excerpt,
			'post_content' => $content,
			'post_status'  => $source->post_status,
			'post_type'    => $source->post_type,
			'post_author'  => (int) $source->post_author,
		];

		// See TranslationTrigger::create_translated_post()'s own docblock for
		// this filter's contract — same filter, same shape.
		$meta = (array) apply_filters(
			'linguaforge_translated_post_meta', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
			[],
			$source->ID,
			$target_lang,
			$source->post_type
		);
		unset( $meta['_lf_trid'], $meta['_lf_lang'] ); // LF-authoritative — written explicitly below, never via the filter.

		if ( ! isset( $meta['_thumbnail_id'] ) && post_type_supports( $source->post_type, 'thumbnail' ) ) {
			$source_thumbnail_id = (int) get_post_thumbnail_id( $source->ID );
			if ( $source_thumbnail_id ) {
				$meta['_thumbnail_id'] = $source_thumbnail_id;
			}
		}
		if ( [] !== $meta ) {
			$insert['meta_input'] = $meta;
		}

		$hooks  = self::unhook_lf_save_handlers();
		$new_id = wp_insert_post( $insert, true );
		self::rehook_lf_save_handlers( $hooks );

		if ( is_wp_error( $new_id ) ) {
			Logger::error(
				sprintf( 'LinguaForge::create_native_sibling_post(#%d → %s): wp_insert_post() failed — %s', $source->ID, $target_lang, $new_id->get_error_message() ),
				'lingua-forge'
			);
			return 0;
		}

		update_post_meta( $new_id, '_lf_trid', $trid );
		update_post_meta( $new_id, '_lf_lang', $target_lang );

		return (int) $new_id;
	}

	/**
	 * Update an existing native-language sibling with fresh content — the
	 * update half of sync_native_sibling(), reached on a resubmission or a
	 * second staged update once the sibling already exists. Mirrors
	 * `TranslationTrigger::update_translated_post()`'s own recipe, including
	 * its `page_template` reset (that method's own docblock explains why: WP
	 * 6.7+ can otherwise throw an `invalid_page_template` error updating a
	 * post whose `_wp_page_template` already holds an FSE slug like
	 * `single-agnosis_artwork-es` that isn't in `get_page_templates()`;
	 * `assign_template_if_needed()`, called by sync_native_sibling() right
	 * after this returns, re-assigns the correct template once the update has
	 * completed).
	 *
	 * post_title IS re-synced on every update (see the inline comment on that
	 * field below) — unlike a normal LF-AI translation, there's no separate
	 * translated-title concept here to preserve: it's always meant to be an
	 * exact mirror of the primary post's own post_title (see
	 * create_native_sibling_post()'s docblock).
	 *
	 * @return int $existing_id on success, 0 on failure (logged).
	 */
	private static function update_native_sibling_post( int $existing_id, \WP_Post $source, string $excerpt, string $content ): int {
		$updated = wp_update_post(
			[
				'ID'            => $existing_id,
				// Re-synced on every update, not just written once at creation
				// — a resubmission (replace@, or any subsequent staged update)
				// can carry a different subject/title from the artist, and the
				// sibling's post_title must keep mirroring the primary post's
				// own post_title exactly, forever, per the dual-title invariant
				// (see create_native_sibling_post()'s docblock).
				'post_title'    => $source->post_title,
				'post_excerpt'  => $excerpt,
				'post_content'  => $content,
				'page_template' => 'default',
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			Logger::error(
				sprintf( 'LinguaForge::update_native_sibling_post(#%d, source #%d): wp_update_post() failed — %s', $existing_id, $source->ID, $updated->get_error_message() ),
				'lingua-forge'
			);
			return 0;
		}

		return $existing_id;
	}

	/**
	 * Unhook Lingua Forge's own `wp_after_insert_post` save handlers for the
	 * duration of a programmatic `wp_insert_post()` call — same pattern
	 * `TranslationTrigger::create_translated_post()` itself uses, and for the
	 * identical reason: those handlers (`TranslationSync::handle_save_post()`,
	 * `TridGroup::handle_cache_clear()`) assume a normal editor save or a
	 * completed translation event, neither of which this is yet.
	 *
	 * Safe to call regardless of who else has hooked `wp_after_insert_post`:
	 * `remove_action()`/`add_action()` here target only these two specific
	 * callables, obtained from LF's own `Router::get_instance()` singleton —
	 * the exact same object reference LF's own bootstrap registered them
	 * with, so this correctly finds and restores LF's hooks specifically,
	 * without touching anyone else's.
	 *
	 * @return array<int, array{0: object, 1: string}> The exact hook
	 *         registrations removed, for rehook_lf_save_handlers() to
	 *         restore. Empty when the Router class isn't available
	 *         (defensive — should never happen given is_active() already
	 *         gated the caller).
	 */
	private static function unhook_lf_save_handlers(): array {
		if ( ! class_exists( '\LinguaForge\Router\Router' ) ) {
			return [];
		}

		$router = \LinguaForge\Router\Router::get_instance();
		$hooks  = [
			[ $router->sync, 'handle_save_post' ],
			[ $router->trid_group, 'handle_cache_clear' ],
		];

		remove_action( 'wp_after_insert_post', $hooks[0], 10 );
		remove_action( 'wp_after_insert_post', $hooks[1], 20 );

		return $hooks;
	}

	/**
	 * Restore whatever unhook_lf_save_handlers() removed. Called immediately
	 * after the wp_insert_post() call it wraps — see create_native_sibling_post().
	 *
	 * @param array<int, array{0: object, 1: string}> $hooks Return value of unhook_lf_save_handlers().
	 */
	private static function rehook_lf_save_handlers( array $hooks ): void {
		if ( empty( $hooks ) ) {
			return;
		}

		// @phpstan-ignore-next-line — dynamic [object, method] callbacks are valid callables at runtime; $router->sync/$router->trid_group are typed `object` because LF isn't autoloaded for static analysis.
		add_action( 'wp_after_insert_post', $hooks[0], 10, 2 );
		// @phpstan-ignore-next-line — same reasoning as above.
		add_action( 'wp_after_insert_post', $hooks[1], 20 );
	}

	// -------------------------------------------------------------------------
	// Translated-post meta propagation
	// -------------------------------------------------------------------------

	/**
	 * Collect the language-neutral meta to carry from a source post to a
	 * translation. Only keys in NEUTRAL_META_KEYS that are actually set (non-empty)
	 * on the source are returned.
	 *
	 * @param int $source_id Source post ID.
	 * @return array<string,mixed> Meta key => value pairs.
	 */
	private function collect_neutral_meta( int $source_id ): array {
		$out = [];

		foreach ( self::NEUTRAL_META_KEYS as $key ) {
			$value = get_post_meta( $source_id, $key, true );

			if ( '' !== $value && [] !== $value && null !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * LF 2.4.0+ path: declare the meta a translated post is born with.
	 *
	 * Hooked on `linguaforge_translated_post_meta`. LF writes the returned pairs via
	 * wp_insert_post()'s meta_input, so the translated post has its images from the
	 * moment it exists. Scoped to Agnosis CPTs via the source post type LF passes.
	 *
	 * @param array<string,mixed> $meta             Meta LF will write (from other integrations).
	 * @param int                 $source_id        Source post ID.
	 * @param string              $target_lang      Target language code.
	 * @param string              $source_post_type Source post type.
	 * @return array<string,mixed>
	 */
	public function supply_translated_meta( array $meta, int $source_id, string $target_lang, string $source_post_type ): array {
		if ( ! in_array( $source_post_type, self::AGNOSIS_POST_TYPES, true ) ) {
			return $meta;
		}

		return array_merge(
			$meta,
			$this->collect_neutral_meta( $source_id ),
			$this->collect_translated_title( $source_id, $target_lang )
		);
	}

	/**
	 * Unconditional meta refresh: copy the meta after the translated post is saved.
	 *
	 * Hooked on `linguaforge_translation_complete` regardless of LF version. Runs
	 * on both creation and re-translation, so it also refreshes images / event
	 * meta / the translated display title if the source changes. On LF < 2.4.0
	 * this is the only meta propagation path. On LF >= 2.4.0 it runs ALONGSIDE
	 * supply_translated_meta() (the born-with filter, preferred for its no-empty-
	 * window benefit on first creation) because that filter never fires on LF's
	 * update/re-translation path — this method is what refreshes an already-
	 * existing translated sibling. Idempotent: on first creation it merely
	 * rewrites the values the born-with filter already supplied (fourth audit, §4b).
	 *
	 * @param int    $translated_id Newly created/updated translated post ID.
	 * @param int    $source_id     Source post ID.
	 * @param string $target_lang   Target language code.
	 * @return void
	 */
	public function copy_translated_meta( int $translated_id, int $source_id, string $target_lang ): void {
		if ( ! in_array( get_post_type( $source_id ), self::AGNOSIS_POST_TYPES, true ) ) {
			return;
		}

		$all = array_merge(
			$this->collect_neutral_meta( $source_id ),
			$this->collect_translated_title( $source_id, $target_lang )
		);

		foreach ( $all as $key => $value ) {
			update_post_meta( $translated_id, $key, $value );
		}
	}

	// -------------------------------------------------------------------------
	// Taxonomy term translation (tags + medium)
	// -------------------------------------------------------------------------

	/**
	 * Translate and assign the source post's `post_tag` (all Agnosis CPTs) and
	 * `agnosis_medium` (artwork only) terms onto its newly created or
	 * re-translated sibling.
	 *
	 * Without this, a translated post has NO tags and NO medium term at all —
	 * not wrong-language, simply absent (2026-07-08 fix). Neither LF core nor
	 * this class's own meta-propagation methods above ever touch taxonomy:
	 * LF's `TranslationTrigger::create_translated_post()`/`update_translated_post()`
	 * only ever set `post_title`/`post_content`/`post_excerpt` plus whatever
	 * `linguaforge_translated_post_meta` supplies, and postmeta (`meta_input`)
	 * cannot carry a taxonomy relationship — assigning one requires the post to
	 * already have an ID, i.e. it can only happen after insert, which is
	 * exactly why this is NOT forked by LF version the way supply_translated_meta()
	 * is (see the constructor) — unlike that filter, taxonomy assignment has no
	 * "born-with" option at any LF version.
	 *
	 * `agnosis_medium` is a controlled vocabulary at AI-generation time — as of
	 * 2026-07-08, `PromptConfig::medium_terms()` (live taxonomy terms, not the
	 * fixed `CANONICAL_MEDIUMS` seed list) is what both the AI prompt and
	 * PostCreator's hallucination guard actually validate against, so admins can
	 * freely rename or add terms and have them be AI-assignable immediately. This
	 * method treats a medium term exactly like a tag either way: translate the
	 * term name via AI and assign it, with no re-validation against any English
	 * source list on the translated side (which would never match once translated).
	 *
	 * @param int    $translated_id Newly created/updated translated post ID.
	 * @param int    $source_id     Source post ID.
	 * @param string $target_lang   Target language code.
	 */
	public function sync_translated_terms( int $translated_id, int $source_id, string $target_lang ): void {
		// Native-language pipeline (Phase 4, §4d) — sync_native_sibling() sets
		// this flag around its own 'linguaforge_translation_complete' firing:
		// the sibling it just created/updated already carries the correct
		// native-language tags/medium (set directly from already-native data,
		// no AI needed), so re-translating them from the PRIMARY post here
		// would be redundant AI spend working against the exact cost saving
		// this whole redesign exists for.
		if ( self::$suppress_native_sibling_term_sync ) {
			return;
		}

		$post_type = get_post_type( $source_id );
		if ( ! in_array( $post_type, self::AGNOSIS_POST_TYPES, true ) ) {
			return;
		}

		$this->sync_taxonomy( $source_id, $translated_id, 'post_tag', $target_lang );

		if ( 'agnosis_artwork' === $post_type ) {
			$this->sync_taxonomy( $source_id, $translated_id, 'agnosis_medium', $target_lang );
		}
	}

	/**
	 * Resolve (and lazily assign) the "translation group ID" — TERM_TRID_META,
	 * a v4 UUID — that links a term to every one of its per-language
	 * translated copies. Every term that ever participates in the trid-based
	 * medium sync gets one on first use, whether it's the primary term or one
	 * of its translations, so the same lookup works uniformly from either
	 * side of the relationship.
	 *
	 * @param int $term_id Term ID to resolve/assign a trid for.
	 * @return string The term's trid (existing or newly assigned).
	 */
	private function get_or_create_term_trid( int $term_id ): string {
		$trid = get_term_meta( $term_id, self::TERM_TRID_META, true );
		if ( is_string( $trid ) && '' !== $trid ) {
			return $trid;
		}

		$trid = wp_generate_uuid4();
		add_term_meta( $term_id, self::TERM_TRID_META, $trid, true );

		return $trid;
	}

	/**
	 * Find the term in $taxonomy that carries a given trid AND is flagged as
	 * that trid group's $lang translation — the sole "does a translation
	 * already exist" check used anywhere in the trid-based sync paths. Never
	 * a name comparison: this is the explicit term_id-to-term_id link that
	 * replaces the old get_term_by( 'name', $translated_name, $taxonomy )
	 * approach, which broke down under AI translation non-determinism (see
	 * TERM_TRID_META's own docblock for the live incident that forced this).
	 *
	 * @param string $trid     Translation group ID to look up.
	 * @param string $taxonomy Taxonomy to search.
	 * @param string $lang     Language code the match must be flagged as.
	 * @return \WP_Term|null The matching term, or null if this trid has no
	 *                        $lang translation yet.
	 */
	private function find_term_by_trid( string $trid, string $taxonomy, string $lang ): ?\WP_Term {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded lookup within one taxonomy's term set, not a hot path.
					'relation' => 'AND',
					[
						'key'   => self::TERM_TRID_META,
						'value' => $trid,
					],
					[
						'key'   => self::TRANSLATED_TERM_META,
						'value' => $lang,
					],
				],
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) || ! $terms[0] instanceof \WP_Term ) {
			return null;
		}

		return $terms[0];
	}

	/**
	 * Find the UNTRANSLATED (primary/admin-curated) member of a trid group —
	 * the term this class's own convention always leaves without
	 * TRANSLATED_TERM_META, since that meta exists specifically to mark
	 * everything OTHER than the primary term. Symmetric counterpart to
	 * find_term_by_trid() (which finds a specific $lang's translated
	 * member): this one finds the one member no $lang value applies to.
	 *
	 * @param string $trid     Translation group ID to look up.
	 * @param string $taxonomy Taxonomy to search.
	 * @return \WP_Term|null The primary-language term carrying this trid, or
	 *                        null if none does (yet, or ever).
	 */
	private function find_primary_term_by_trid( string $trid, string $taxonomy ): ?\WP_Term {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded lookup within one taxonomy's term set, not a hot path.
					'relation' => 'AND',
					[
						'key'   => self::TERM_TRID_META,
						'value' => $trid,
					],
					[
						'key'     => self::TRANSLATED_TERM_META,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) || ! $terms[0] instanceof \WP_Term ) {
			return null;
		}

		return $terms[0];
	}

	/**
	 * Resolve a submission's native-language tags to the `post_tag` term IDs
	 * to assign onto the primary-language post — the single choke point
	 * where every tag, regardless of what language it was originally
	 * written in, gets reconciled against ONE canonical vocabulary
	 * (primary-language tags) before becoming "real," rather than each
	 * translation moment inventing near-duplicates independently.
	 *
	 * Why primary, and not the artist's own native language, is that one
	 * vocabulary: there is exactly one primary language per site, but
	 * potentially dozens of native languages — one per artist. Every
	 * submission passes through primary exactly once (this method's own
	 * call site), so it's the only point a single, sitewide vocabulary can
	 * actually mean "single." Anchoring on native language instead would
	 * just relocate the fragmentation this whole rework exists to remove:
	 * a Catalan artist's tags would dedupe against past Catalan tags, a
	 * French artist's against past French tags, and two artists describing
	 * the same thing in different languages would never be compared at all.
	 *
	 * Deliberately makes NO AI call of its own. An earlier version of this
	 * method ran a separate reconciliation call here for any tag without an
	 * established trid — correct in isolation, but it broke a harder
	 * constraint: NATIVE-LANGUAGE-PIPELINE.md §7's exactly-one-AI-call-per-
	 * cross-language-approval invariant, asserted directly by
	 * ReviewEndpointsNativeLanguagePipelineTest::test_approve_of_native_language_draft_makes_exactly_one_ai_call().
	 * The reconciliation decision now happens INSIDE that one call instead:
	 * Publishing\ReviewEndpoints::translate_native_content_to_primary()
	 * passes the existing primary vocabulary to SubmissionTranslator::
	 * translate_fields() as a per-field instruction on 'tags', telling the
	 * AI to copy an existing tag's exact text when a proposed one means the
	 * same thing. This method's own exact-name lookup below is trusted as a
	 * result — not the "guess by re-deriving a translation and hoping it
	 * matches" pattern that caused the medium/tag parity incident this whole
	 * trid rework exists to fix, but reading back a choice the AI was
	 * explicitly told to make from a list it was actually shown, the same
	 * closed-set trust model the `medium` field already uses elsewhere
	 * ("pick exactly one from: …").
	 *
	 * For each native term ID, in order:
	 *   1. Already has a trid, AND a primary term still carries that same
	 *      trid → reuse it. Free.
	 *   2. Otherwise → exact-name match against the current primary
	 *      vocabulary (trusted per above) → link and reuse. No match →
	 *      create a new primary term. Either way, the native term's trid is
	 *      established/linked here, so every future submission reusing that
	 *      exact native term (WordPress's own exact-name dedup already
	 *      collapses repeated identical native-language tag text to the
	 *      same term ID) resolves via step 1 from then on.
	 *
	 * $native_term_ids and $translated_names can only be paired by array
	 * position — translate_fields() collapses the whole tag list into one
	 * pipe-delimited text field with no structured per-tag correspondence in
	 * its response, so there's no stronger signal available. When the two
	 * arrays come back a different length (the AI merged, split, or dropped
	 * an entry), positional pairing is unsafe: every translated name in that
	 * batch is resolved without a native link instead — degrades to "no free
	 * reuse next time" rather than risking a wrong pairing.
	 *
	 * @param int[]    $native_term_ids  Native-language `post_tag` term IDs,
	 *                                   in the same order their names were
	 *                                   joined for translation.
	 * @param string[] $translated_names Translated tag names, already split
	 *                                   back out of the AI's pipe-delimited
	 *                                   response.
	 * @return int[] `post_tag` term IDs to assign to the primary-language post.
	 */
	public function resolve_primary_tags( array $native_term_ids, array $translated_names ): array {
		$taxonomy = 'post_tag';
		$paired   = count( $native_term_ids ) === count( $translated_names );

		$existing_primary = $this->existing_primary_tag_map( $taxonomy );
		$assign            = [];

		foreach ( $translated_names as $i => $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}

			$native_id = $paired ? (int) ( $native_term_ids[ $i ] ?? 0 ) : 0;

			if ( $native_id > 0 ) {
				$trid = get_term_meta( $native_id, self::TERM_TRID_META, true );
				if ( is_string( $trid ) && '' !== $trid ) {
					$primary = $this->find_primary_term_by_trid( $trid, $taxonomy );
					if ( $primary instanceof \WP_Term ) {
						$assign[] = $primary->term_id;
						continue; // Free — already resolved by a previous approval.
					}
					// Trid recorded but no primary term carries it any more
					// (e.g. that term was deleted since) — fall through and
					// re-resolve as if this were the first time.
				}
			}

			if ( isset( $existing_primary[ $name ] ) ) {
				$term_id = $existing_primary[ $name ];
			} else {
				$created = wp_insert_term( $name, $taxonomy );
				if ( is_wp_error( $created ) ) {
					// Most likely a genuine race — another request created
					// the same tag between the query above and this call.
					$existing = get_term_by( 'name', $name, $taxonomy );
					$term_id  = $existing instanceof \WP_Term ? $existing->term_id : 0;
				} else {
					$term_id = (int) $created['term_id'];
				}
			}

			if ( 0 === $term_id ) {
				continue; // Creation failed and no fallback match — drop rather than assign nothing usable.
			}

			if ( $native_id > 0 ) {
				$trid = $this->get_or_create_term_trid( $term_id );
				add_term_meta( $native_id, self::TERM_TRID_META, $trid, true );
			}

			$assign[] = $term_id;
		}

		return array_values( array_unique( $assign ) );
	}

	/**
	 * Name => term_id map of the current primary `post_tag` vocabulary — the
	 * exact-match lookup resolve_primary_tags() trusts (see that method's
	 * own docblock for why trusting a name match is safe specifically here).
	 *
	 * @param string $taxonomy Taxonomy — always 'post_tag' from the one call site today.
	 * @return array<string, int> Term name => term_id.
	 */
	private function existing_primary_tag_map( string $taxonomy ): array {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one bounded lookup per approval, not a hot path.
					[
						'key'     => self::TRANSLATED_TERM_META,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);
		$terms = is_wp_error( $terms ) ? [] : $terms;

		$by_name = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$by_name[ $term->name ] = $term->term_id;
			}
		}

		return $by_name;
	}

	/**
	 * On-demand sync: ensure every configured target language has a
	 * translated term for a given PRIMARY-language term on a target taxonomy
	 * (`agnosis_medium` or `post_tag` — see TaxonomyLanguageFilter::TARGET_TAXONOMIES),
	 * creating any missing one via AI translation.
	 *
	 * Trid-based (TERM_TRID_META), same as sync_taxonomy(): "does a
	 * translation already exist for this language" is answered by an
	 * explicit term_id-to-term_id link, never by re-deriving the translated
	 * name and string-matching it against existing terms. That name-matching
	 * approach — get_term_by( 'name', $translated_name, $taxonomy ) — is what
	 * this method used to do, and it's what produced the live incident this
	 * rework exists to fix (originally on `agnosis_medium`, before this
	 * method's Tags-screen expansion): running "Sync all translations" more
	 * than once created a fresh near-duplicate term instead of recognizing
	 * the one already there (AI translation isn't guaranteed byte-identical
	 * across calls), leaving every language bucket out of the 1:1 parity
	 * with the primary vocabulary it's supposed to have. With a
	 * trid link, this method is genuinely idempotent: running it any number
	 * of times converges on exactly one term per (primary term, language)
	 * pair and then does nothing further.
	 *
	 * Unlike `sync_taxonomy()`, this isn't tied to a specific source/
	 * translated POST pair — it operates on the TERM itself,
	 * independent of any artwork, for the "Sync translations" row action and
	 * the "Sync all translations" button on the Tags/Mediums admin screens
	 * (Admin\TaxonomyLanguageFilter). Requested directly for mediums:
	 * translated terms otherwise only ever get created as a side effect of
	 * an artwork/post being translated — a term with nothing published yet
	 * in some language had no translated term for that language at all,
	 * even though an admin might want the full vocabulary to exist ahead of
	 * time (e.g. for a future artist submission form in that language).
	 * Later extended to `post_tag` alongside `agnosis_medium`, since nothing
	 * about the underlying trid mechanism was medium-specific to begin with.
	 *
	 * Deliberately a no-op — returns empty results, does nothing — when
	 * `$term_id` is itself already a translated term (has
	 * TRANSLATED_TERM_META): syncing only ever makes sense starting from the
	 * primary/admin-curated term, not from one of its own translations.
	 *
	 * @param int    $term_id  Term ID to sync — a primary-language term on
	 *                         one of TaxonomyLanguageFilter::TARGET_TAXONOMIES.
	 * @param string $taxonomy Taxonomy the term belongs to — `agnosis_medium`
	 *                         or `post_tag` today; the underlying trid
	 *                         mechanism itself is fully taxonomy-generic (see
	 *                         sync_taxonomy()), so this parameter is simply
	 *                         passed through, not special-cased.
	 * @return array{created: string[], needs_translation: string[], skipped: string[], failed: string[]}
	 *         Language codes a translated term was newly created for via a
	 *         genuine AI translation, created as an untranslated fallback
	 *         placeholder needing a hand edit (see
	 *         insert_fallback_translated_term()), already present, or could
	 *         not be produced AT ALL — either a hard DB-level insert failure,
	 *         or (2026-07-19) a term-meta linking conflict caught by the
	 *         read-back check after insert (see that check's own inline
	 *         comment) — never just "the AI failed", which now always still
	 *         produces a placeholder — for the admin notice after redirect.
	 */
	public function sync_term_across_languages( int $term_id, string $taxonomy ): array {
		$result = [
			'created'           => [],
			'needs_translation' => [],
			'skipped'           => [],
			'failed'            => [],
		];

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return $result;
		}

		if ( get_term_meta( $term_id, self::TRANSLATED_TERM_META, true ) ) {
			return $result;
		}

		if ( ! function_exists( 'linguaforge_languages' ) ) {
			return $result;
		}

		$primary_lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		$trid         = $this->get_or_create_term_trid( $term_id );

		foreach ( $this->get_target_languages( $primary_lang ) as $lang ) {
			$existing = $this->find_term_by_trid( $trid, $taxonomy, $lang );
			if ( $existing instanceof \WP_Term ) {
				$result['skipped'][] = $lang;
				continue;
			}

			// No AI provider configured, or the translation call failed —
			// translated_term_name() already falls back to the original name
			// rather than blocking. Added 2026-07-19: this no longer means
			// "create nothing" (a live report found that left a real
			// percentage of every language's vocabulary permanently missing
			// — German 9/10, Italian 8/10, Portuguese 6/10 on one run,
			// "unacceptable" per the report). A trid-linked placeholder is
			// created either way now, using the untranslated source name and
			// a visible "needs translation" note (insert_fallback_translated_term()'s
			// own docblock) — so an operator can find and hand-correct it
			// directly in the Tags/Mediums screen instead of the slot simply
			// not existing.
			$translated_name  = $this->translated_term_name( $term->name, $taxonomy, $lang );
			$ai_translated_ok = $translated_name !== $term->name;

			$created_id = $ai_translated_ok
				? $this->insert_translated_term( $translated_name, $taxonomy, $trid, $lang )
				: $this->insert_fallback_translated_term( $term->name, $taxonomy, $trid, $lang );

			if ( null === $created_id ) {
				// Only reached now for a genuine DB-level insert failure —
				// both insert paths above already resolve every collision
				// case they can. Still worth a visible signal.
				$result['failed'][] = $lang;
				continue;
			}

			// add_term_meta( …, $unique = true ) silently returns false and
			// adds nothing whenever the term already carries a value for
			// that key — harmless when $created_id is the genuine-lost-race
			// term from insert_translated_term() (it already holds the
			// CORRECT value), but a real, previously-silent failure if it
			// holds something ELSE. That exact silent failure — reusing a
			// different language's term ID, then having this call no-op —
			// is how Portuguese/Spanish ended up missing real terms while
			// "Sync all translations" reported a clean 0-failed run (live
			// report, 2026-07-19). Reading the meta back after writing and
			// comparing against what was actually intended catches that
			// case (and any other future cause of the same silent no-op)
			// instead of trusting the write succeeded.
			add_term_meta( $created_id, self::TRANSLATED_TERM_META, $lang, true );
			add_term_meta( $created_id, self::TERM_TRID_META, $trid, true );

			$linked_lang = (string) get_term_meta( $created_id, self::TRANSLATED_TERM_META, true );
			$linked_trid = (string) get_term_meta( $created_id, self::TERM_TRID_META, true );

			if ( $lang !== $linked_lang || $trid !== $linked_trid ) {
				Logger::error(
					sprintf(
						'LinguaForge::sync_term_across_languages(#%d, %s → %s): term #%d already carried a conflicting TRANSLATED_TERM_META/TERM_TRID_META value and could not be linked as this translation (found lang=%s, trid=%s).',
						$term_id,
						$taxonomy,
						$lang,
						$created_id,
						$linked_lang,
						$linked_trid
					),
					'lingua-forge'
				);
				$result['failed'][] = $lang;
				continue;
			}

			if ( $ai_translated_ok ) {
				$result['created'][] = $lang;
			} else {
				add_term_meta( $created_id, self::TERM_NEEDS_TRANSLATION_META, '1', true );
				$result['needs_translation'][] = $lang;
			}
		}

		return $result;
	}

	/**
	 * Inserts a translated term, resolving the cross-language homograph
	 * collision `wp_insert_term()` hits when two languages independently
	 * translate to the same (or accent-insensitively equal) word — e.g.
	 * "Fotografie" is both German and Dutch, and "Fotografía"/"Fotografia"
	 * differ only by an accent AI output isn't guaranteed to keep across
	 * es/it/pt/ca, which the taxonomy's collation treats as equal.
	 *
	 * Added 2026-07-19 (audit §2b, AUDIT-0.9.38.md): the caller used to hand
	 * `wp_insert_term()`'s `WP_Error` a bare `continue`, which for a
	 * `term_exists` collision failed that language FOREVER — no trid link
	 * was ever created, so every future sync re-attempted the identical
	 * insert and re-failed identically, with nothing counting the loss.
	 *
	 * Corrected again 2026-07-19, same day (live report: Portuguese missing
	 * "Arte Digital"/"Escultura" — byte-identical to their own Spanish
	 * translations — plus "Fotografia"/"Poesia", accent variants of the
	 * Spanish forms; Italian, Dutch, and Catalan share enough Romance/German
	 * vocabulary with their neighbors to hit this constantly). The ORIGINAL
	 * §2b fix's "already carries this trid → safe lost race, reuse" rule was
	 * too broad: it's only actually safe when the colliding term is tagged
	 * for the SAME language already. Two different real cases were both
	 * wrongly folded into "lost race" before this fix:
	 *   - A different language's translation in the SAME trid group happens
	 *     to render identically (or accent-equivalently) — e.g. Spanish's
	 *     "Fotografía" was inserted first, then Portuguese's "Fotografia"
	 *     collided with IT, not with any primary term. Reusing Spanish's
	 *     term ID for Portuguese, then tagging it TRANSLATED_TERM_META=pt,
	 *     is impossible: `add_term_meta( …, $unique = true )` silently
	 *     refuses to add a second value for a key the term already carries
	 *     (already 'es'), so the call was a no-op — Portuguese ended up with
	 *     NO term at all, while the caller still counted the language as
	 *     `created` because it never checked that return value (see the
	 *     defensive check added in `sync_term_across_languages()` the same
	 *     day, which now catches this class of failure regardless of cause).
	 *   - The AI's "translation" happens to equal the PRIMARY term's own
	 *     name verbatim (not unusual between closely related languages) —
	 *     the collision is with the primary term itself, which never carries
	 *     TRANSLATED_TERM_META. Reusing ITS id and tagging it as a
	 *     translation would corrupt the primary vocabulary exactly the way
	 *     `insert_fallback_translated_term()`'s own docblock warns about,
	 *     just reached from this method instead.
	 *
	 * Both are now correctly treated as "this name is already taken by
	 * something that ISN'T this exact (trid, lang) pair" and fall through to
	 * the same disambiguated-slug retry a different-trid collision already
	 * used — the only genuinely safe reuse left is a collision with a term
	 * that already carries BOTH this trid AND this exact language, which can
	 * only mean a real concurrent duplicate request beat this one to it.
	 *
	 * @param string $name     Translated term name to insert.
	 * @param string $taxonomy Taxonomy to insert into.
	 * @param string $trid     Translation-group ID the new/resolved term must
	 *                         end up carrying — used only to detect the
	 *                         lost-race case; the caller is still
	 *                         responsible for actually writing TERM_TRID_META.
	 * @param string $lang     Target language code — used both to detect a
	 *                         genuine lost race and to build a disambiguating
	 *                         slug on any other collision.
	 * @return int|null The term ID to link, or null if insertion could not
	 *                   be resolved at all (a non-`term_exists` WP_Error, or
	 *                   the suffixed-slug retry itself also failed).
	 */
	private function insert_translated_term( string $name, string $taxonomy, string $trid, string $lang ): ?int {
		$created = wp_insert_term( $name, $taxonomy );

		if ( ! is_wp_error( $created ) ) {
			return (int) $created['term_id'];
		}

		if ( 'term_exists' !== $created->get_error_code() ) {
			return null;
		}

		$existing_id = (int) $created->get_error_data( 'term_exists' );
		if ( $existing_id > 0 && $trid === get_term_meta( $existing_id, self::TERM_TRID_META, true ) ) {
			$existing_lang = (string) get_term_meta( $existing_id, self::TRANSLATED_TERM_META, true );
			if ( $lang === $existing_lang ) {
				return $existing_id; // Genuine lost race — same trid, same language already landed here.
			}
			// Same trid, but this name is already claimed by something that
			// ISN'T this (trid, lang) pair: either the primary term itself
			// ($existing_lang === '', since primary terms never carry this
			// meta) or a DIFFERENT language's translation in this same trid
			// group. Neither is safe to reuse — falls through to the
			// disambiguated retry below, same as any other collision.
		}

		// A different trid group, a trid-less primary term, or (see above) a
		// same-trid term belonging to the primary term or another language,
		// is sitting on this exact name — never claim it. Retry once with a
		// disambiguating slug rather than the auto-generated one, which is
		// what triggers wp_insert_term()'s duplicate-name block in the first
		// place.
		$retried = wp_insert_term(
			$name,
			$taxonomy,
			[ 'slug' => sanitize_title( $name . '-' . $lang ) ]
		);

		return is_wp_error( $retried ) ? null : (int) $retried['term_id'];
	}

	/**
	 * Creates a FALLBACK translated term when AI translation isn't available
	 * or failed — the source term's own name, reused verbatim as a readable
	 * placeholder, rather than leaving the (term, language) pair with no
	 * term at all. Added 2026-07-19 (see TERM_NEEDS_TRANSLATION_META's own
	 * docblock for the live report this responds to).
	 *
	 * The source name will ALWAYS collide with the primary term itself in
	 * `wp_insert_term()` — same taxonomy, byte-identical name — which is why
	 * this can't reuse `insert_translated_term()`'s own collision handling:
	 * that method treats a same-trid collision as a harmless lost race and
	 * returns the EXISTING term's ID to link, which here would be the
	 * primary term's own ID (it carries this exact trid too, via
	 * `get_or_create_term_trid()`). Linking the primary term to itself as
	 * its own "German translation" would tag it with TRANSLATED_TERM_META
	 * and silently corrupt the primary vocabulary — `PromptConfig::
	 * medium_terms()` excludes anything carrying that meta from the AI's
	 * controlled vocabulary, so the primary term would vanish from it.
	 * This method sidesteps the ambiguity entirely by never attempting the
	 * plain (colliding) insert in the first place — it goes straight to a
	 * disambiguated slug, the same `{name}-{lang}` shape
	 * `insert_translated_term()` falls back to for a genuine cross-language
	 * homograph.
	 *
	 * The new term's `description` is set to a plain-language note so the
	 * placeholder is visibly distinguishable in the Tags/Mediums list table
	 * (WP_Terms_List_Table's own Description column renders it automatically
	 * — no admin-UI code needed here) — an operator can rename the term and
	 * clear the description in one edit to correct it by hand.
	 *
	 * @param string $name     Primary term's own name — used verbatim as the
	 *                         placeholder's display name.
	 * @param string $taxonomy Taxonomy to insert into.
	 * @param string $trid     Translation-group ID this placeholder belongs to.
	 * @param string $lang     Target language code — used to build the
	 *                         disambiguating slug.
	 * @return int|null The new term ID, or null if even the forced,
	 *                   disambiguated insert failed (a genuine DB-level
	 *                   failure, or a slug collision from some unrelated
	 *                   term already sitting on it — not expected in
	 *                   practice, but never silently claimed either way).
	 */
	private function insert_fallback_translated_term( string $name, string $taxonomy, string $trid, string $lang ): ?int {
		$created = wp_insert_term(
			$name,
			$taxonomy,
			[
				'slug'        => sanitize_title( $name . '-' . $lang ),
				'description' => __( 'Placeholder — AI translation was unavailable or failed when this term was created. Edit the name to provide the correct translation, then clear this note.', 'agnosis' ),
			]
		);

		return is_wp_error( $created ) ? null : (int) $created['term_id'];
	}

	/**
	 * Wall-clock budget for one `sync_all_terms_across_languages()` request,
	 * in seconds.
	 *
	 * Added 2026-07-19 (audit §2a, AUDIT-0.9.38.md): each missing (term,
	 * language) pair the loop below finds is one live AI call
	 * (`translated_term_name()` → `SubmissionTranslator::translate_text()`).
	 * On the vocabulary size the live site has already demonstrated (746
	 * tags observed pre-cleanup) × 17 target languages, a first run from a
	 * cold cache is potentially thousands of sequential AI calls — PHP's
	 * `max_execution_time`, the gateway timeout, and the AI provider's rate
	 * limit would all be hit long before it finished, and the operator got a
	 * browser error page with no notice either way.
	 *
	 * 20s is comfortably inside every default PHP/webserver timeout
	 * (`max_execution_time` defaults to 30s, most gateway timeouts are
	 * 30-60s) while still making real progress per click on a typical AI
	 * provider's per-call latency. The loop already persists each completed
	 * term before starting the next (term created + trid-linked, cache entry
	 * written — see `sync_term_across_languages()`), so stopping cleanly at
	 * this boundary turns that existing resumability into an actual UX
	 * rather than an accident an operator has to discover by re-clicking a
	 * button that appeared to fail.
	 */
	private const SYNC_ALL_TIME_BUDGET_SECONDS = 20;

	/**
	 * Runs `sync_term_across_languages()` across EVERY primary-language term
	 * on the given taxonomy in one pass — the "Sync all translations" button
	 * on the Tags/Mediums admin screens (Admin\TaxonomyLanguageFilter),
	 * requested alongside the per-term row action for clearing a whole
	 * backlog of missing translations at once rather than one term at a
	 * time.
	 *
	 * Deliberately queries with `hide_empty => false`: an admin filling in
	 * missing translations ahead of time (the exact scenario
	 * sync_term_across_languages() itself documents) is very likely
	 * targeting terms with zero posts using them yet — those would be
	 * silently skipped entirely under the default hide_empty behavior.
	 *
	 * Time-bounded to SYNC_ALL_TIME_BUDGET_SECONDS (see that constant's own
	 * docblock): the deadline is only ever checked BETWEEN terms, never
	 * mid-term, so a term already in progress always finishes its full
	 * per-language loop before the method returns — nothing here can leave a
	 * term half-synced across languages. Reaching the deadline sets
	 * `timed_out` and stops there; the caller's redirect notice is
	 * responsible for telling the operator to click again.
	 *
	 * @param string $taxonomy Taxonomy to sync — 'agnosis_medium' or 'post_tag'.
	 * @return array{terms: int, total: int, created: int, needs_translation: int, skipped: int, failed: int, timed_out: bool}
	 *         Count of primary-language terms processed this request vs. the
	 *         total eligible, the totals (summed across processed terms) of
	 *         genuinely AI-translated / created-as-untranslated-placeholder
	 *         (needs a hand edit) / already-present / hard-failed-to-insert
	 *         translated terms, and whether the time budget was hit before
	 *         every term was reached — for the admin notice after redirect.
	 */
	public function sync_all_terms_across_languages( string $taxonomy ): array {
		$result = [
			'terms'              => 0,
			'total'              => 0,
			'created'            => 0,
			'needs_translation'  => 0,
			'skipped'            => 0,
			'failed'             => 0,
			'timed_out'          => false,
		];

		$primary_terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-triggered one-off action, not a hot path; same pattern as scope_by_language()'s default view.
					[
						'key'     => self::TRANSLATED_TERM_META,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		if ( is_wp_error( $primary_terms ) || empty( $primary_terms ) ) {
			return $result;
		}

		$result['total'] = count( $primary_terms );
		$deadline        = microtime( true ) + self::SYNC_ALL_TIME_BUDGET_SECONDS;

		foreach ( $primary_terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			if ( microtime( true ) >= $deadline ) {
				$result['timed_out'] = true;
				break;
			}

			$term_result = $this->sync_term_across_languages( $term->term_id, $taxonomy );

			++$result['terms'];
			$result['created']           += count( $term_result['created'] );
			$result['needs_translation'] += count( $term_result['needs_translation'] );
			$result['skipped']           += count( $term_result['skipped'] );
			$result['failed']            += count( $term_result['failed'] );
		}

		return $result;
	}

	/**
	 * Automatically re-propagate an artwork's medium term to every already-
	 * published translated sibling whenever it changes AFTER initial publish
	 * (e.g. an admin correction, or the front-end self-correction flow) —
	 * `sync_translated_terms()` above only ever fires at translation-creation
	 * time (`linguaforge_translation_complete`), so a medium changed later
	 * would otherwise leave every existing sibling silently pointing at the
	 * old, now-wrong translated term forever.
	 *
	 * Hooked on WordPress core's own `set_object_terms` action, which fires
	 * for every `wp_set_object_terms()` call, not just ours — heavily gated
	 * as a result. Self-limiting rather than needing an explicit re-entrancy
	 * guard: this only acts on a PRIMARY-language artwork post, and
	 * `sync_taxonomy()` below only ever calls `wp_set_object_terms()` on the
	 * TRANSLATED sibling — which is never itself primary-language — so the
	 * `set_object_terms` firing that call produces is excluded by the same
	 * primary-language check on its own next pass through this method,
	 * without any additional suppression flag.
	 *
	 * @param int      $object_id  Post (or other object) the terms were set on.
	 * @param int[]    $terms      Term IDs/slugs as passed to wp_set_object_terms().
	 * @param int[]    $tt_ids     Resulting term_taxonomy_ids.
	 * @param string   $taxonomy   Taxonomy the terms were set on.
	 * @param bool     $append     Whether terms were appended or replaced.
	 * @param int[]    $old_tt_ids term_taxonomy_ids the object had before this call.
	 */
	/**
	 * Term IDs auto-created via `wp_insert_term()` during the CURRENT
	 * request, not yet correlated to whichever post triggered their
	 * creation. Populated by track_newly_created_term() (hooked to WP
	 * core's `created_term`), consumed by
	 * flag_newly_created_terms_by_post_language() (hooked to
	 * `set_object_terms`) a few lines later in the SAME
	 * `wp_set_object_terms()` call: that function resolves any not-yet-
	 * existing term name via `wp_insert_term()` — firing `created_term` —
	 * synchronously and strictly BEFORE it fires its own `set_object_terms`
	 * action at the end of that same call (verified against WP core
	 * source), so by the time the consumer below runs, every term it might
	 * need to correlate against for THIS call is already in this array.
	 *
	 * A `created_term` firing with no matching `set_object_terms` in the
	 * same request is possible and expected (e.g. a taxonomy's default term
	 * created by `register_taxonomy()`, or an admin adding a term via the
	 * Tags screen's own "Add new tag" box with no post involved at all) —
	 * left unconsumed for the rest of the request, harmless: it's an
	 * in-memory array, never persisted, and simply means that term's
	 * language is left undetermined (the existing safe default: untouched,
	 * i.e. still counted as "primary" until something actually flags it).
	 *
	 * @var int[]
	 */
	private static array $newly_created_term_ids = [];

	/**
	 * @param int    $term_id  Newly created term's ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy the term was created in.
	 */
	public function track_newly_created_term( int $term_id, int $tt_id, string $taxonomy ): void {
		unset( $tt_id );

		if ( in_array( $taxonomy, [ 'agnosis_medium', 'post_tag' ], true ) ) {
			self::$newly_created_term_ids[] = $term_id;
		}
	}

	/**
	 * Closes the gap that caused a live data-integrity incident: 127
	 * `post_tag` terms accumulated in the "primary language" bucket during
	 * Catalan testing, because TRANSLATED_TERM_META has only ever been set
	 * by the dedicated translation fan-out (sync_taxonomy() / the "Sync
	 * translations" admin action) — a term auto-created while tagging a
	 * post directly (the normal AI-tagging path on ANY newly submitted
	 * artwork, e.g. PostCreator/ReviewEndpoints calling wp_set_post_tags())
	 * never had its language recorded anywhere at all. A term created from
	 * content submitted/tested in Catalan looked byte-for-byte identical to
	 * a genuine primary-language term: both simply lacked the meta. Silent,
	 * compounding corruption on a multi-language site — caught only once
	 * Admin\TaxonomyLanguageFilter's language dropdown made the "primary"
	 * bucket's real contents visible for the first time.
	 *
	 * Whenever a post's terms are set, cross-references this request's
	 * newly-created term IDs (track_newly_created_term()) against the terms
	 * actually assigned to THIS post: any match was, by definition, just
	 * created as a side effect of tagging it. If the post isn't in the
	 * primary language, that term is a translated term the moment it's
	 * born — stamped immediately, rather than left to silently join the
	 * "primary" bucket the way the 127 did.
	 *
	 * @param int      $object_id  Post (or other object) the terms were set on.
	 * @param int[]    $terms      Term IDs/slugs as passed to wp_set_object_terms() (unused).
	 * @param int[]    $tt_ids     Resulting term_taxonomy_ids (unused — re-fetched as term IDs below).
	 * @param string   $taxonomy   Taxonomy the terms were set on.
	 */
	public function flag_newly_created_terms_by_post_language( int $object_id, array $terms, array $tt_ids, string $taxonomy ): void {
		unset( $terms, $tt_ids );

		if ( empty( self::$newly_created_term_ids ) || ! in_array( $taxonomy, [ 'agnosis_medium', 'post_tag' ], true ) ) {
			return;
		}

		$assigned_ids = wp_get_object_terms( $object_id, $taxonomy, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $assigned_ids ) ) {
			return;
		}

		/** @var int[] $matched */
		$matched = array_intersect( self::$newly_created_term_ids, $assigned_ids );
		if ( empty( $matched ) ) {
			return;
		}

		// Consumed either way (flagged below or not) — don't let them leak
		// into a later, unrelated wp_set_object_terms() call in the same
		// request (e.g. a bulk-import script processing many posts).
		self::$newly_created_term_ids = array_values( array_diff( self::$newly_created_term_ids, $matched ) );

		// sanitize_key() on both sides — not just defensiveness: this is the
		// exact comparison a casing/whitespace mismatch between the two
		// would silently defeat, which is precisely the class of bug this
		// method exists to close (see class docblock for the incident).
		//
		// `_agnosis_native_lang` (checked first) over `_lf_lang`: the native-
		// language pipeline (NATIVE-LANGUAGE-PIPELINE.md) creates a post's
		// tags at INTAKE, on the draft, via PostCreator::write_post_meta()'s
		// wp_set_post_tags() call — before the post is ever published.
		// `_lf_lang` is only ever written by set_language_meta(), hooked to
		// `agnosis_post_published`, so it doesn't exist yet at intake time —
		// this method would read `$post_lang = ''` and silently skip
		// flagging, exactly reproducing the 127-Catalan-tags bug through a
		// different, newer door: every non-primary-language artist's intake
		// tags landing unflagged in the "primary" bucket, ongoing, since the
		// native pipeline shipped (2026-07-12). `_agnosis_native_lang` IS
		// already set at intake (PostCreator::create_post(), unconditionally,
		// every submission) and is the artist's actual declared language —
		// exactly the signal this method needs and didn't have. `_lf_lang`
		// stays as the fallback for anything with no native-language meta at
		// all (biography/event posts predating this pipeline, or any future
		// post type this hook fires for that never goes through it).
		$native_lang  = sanitize_key( (string) get_post_meta( $object_id, '_agnosis_native_lang', true ) );
		$post_lang    = '' !== $native_lang ? $native_lang : sanitize_key( (string) get_post_meta( $object_id, '_lf_lang', true ) );
		$primary_lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );

		if ( '' === $post_lang || '' === $primary_lang || $post_lang === $primary_lang ) {
			return; // Primary-language post (or language unknown) — these newly-created terms genuinely ARE the primary vocabulary.
		}

		foreach ( $matched as $term_id ) {
			if ( ! get_term_meta( (int) $term_id, self::TRANSLATED_TERM_META, true ) ) {
				add_term_meta( (int) $term_id, self::TRANSLATED_TERM_META, $post_lang, true );
			}
		}
	}

	/**
	 * @param int      $object_id  Post (or other object) the terms were set on.
	 * @param int[]    $terms      Term IDs/slugs as passed to wp_set_object_terms() (unused).
	 * @param int[]    $tt_ids     Resulting term_taxonomy_ids.
	 * @param string   $taxonomy   Taxonomy the terms were set on.
	 * @param bool     $append     Whether terms were appended or replaced (unused).
	 * @param int[]    $old_tt_ids term_taxonomy_ids the object had before this call.
	 */
	public function on_medium_terms_changed( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		unset( $terms, $append );

		if ( 'agnosis_medium' !== $taxonomy ) {
			return;
		}

		// No actual change (a re-save that happens to re-set the same terms) —
		// nothing to propagate. Order-independent comparison since
		// wp_set_object_terms() doesn't guarantee tt_id ordering matches.
		sort( $tt_ids );
		sort( $old_tt_ids );
		if ( $tt_ids === $old_tt_ids ) {
			return;
		}

		// Everything else this method used to do inline — post-type check,
		// primary-language direction-of-truth guard, siblings lookup, the
		// actual propagation loop — now lives in
		// sync_medium_assignment_to_siblings(), a public, directly-callable
		// version of the exact same logic: Admin\ArtworkMediumSync calls it
		// on demand (one artwork's edit-screen button, or a bulk sweep of
		// every primary-language artwork), for artwork/sibling pairs that
		// drifted out of sync BEFORE this automatic hook existed — this
		// reactive firing only ever catches a change from here forward.
		$this->sync_medium_assignment_to_siblings( $object_id );
	}

	/**
	 * On-demand version of the propagation on_medium_terms_changed() above
	 * runs automatically on save — pushes a primary-language artwork's
	 * CURRENT medium assignment onto every already-translated sibling right
	 * now, regardless of whether anything actually just changed.
	 *
	 * Exists because the automatic hook only ever fires reactively (on a
	 * save that changes the medium): it does nothing for an artwork/sibling
	 * pair that was ALREADY out of sync before that feature shipped, or
	 * drifted for any other reason — requested directly as "a real sync
	 * button" once that gap became clear. Admin\ArtworkMediumSync's
	 * per-artwork meta box button and bulk "Sync all medium assignments"
	 * action both call this (the bulk one in a loop, via
	 * sync_all_medium_assignments() below); on_medium_terms_changed() now
	 * delegates to it too, so there is exactly one implementation of "push
	 * this artwork's medium to its siblings," triggered three ways.
	 *
	 * No-ops (returns 0) on anything that isn't a primary-language
	 * `agnosis_artwork` post — same direction-of-truth rule the automatic
	 * hook always enforced: a translated post's medium is a translation of
	 * the primary's, never an independent source to propagate FROM.
	 *
	 * @param int $post_id Primary-language `agnosis_artwork` post ID.
	 * @return int Number of translated siblings the medium was pushed to.
	 */
	public function sync_medium_assignment_to_siblings( int $post_id ): int {
		if ( 'agnosis_artwork' !== get_post_type( $post_id ) ) {
			return 0;
		}

		// Only propagate FROM the primary-language post TO its translated
		// siblings — a translated post's own medium is expected to be a
		// translation of the primary's, not an independent source of truth.
		$primary_lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		$post_lang    = sanitize_key( (string) get_post_meta( $post_id, '_lf_lang', true ) );
		if ( '' === $primary_lang || $post_lang !== $primary_lang ) {
			return 0;
		}

		if ( ! function_exists( 'linguaforge_get_translations' ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
		$translations = linguaforge_get_translations( $post_id );

		$synced = 0;
		foreach ( $translations as $lang => $sibling_id ) {
			$sibling_id = (int) $sibling_id;
			if ( $sibling_id === $post_id || 0 === $sibling_id ) {
				continue;
			}
			$this->sync_taxonomy( $post_id, $sibling_id, 'agnosis_medium', (string) $lang );
			++$synced;
		}

		return $synced;
	}

	/**
	 * Bulk version of sync_medium_assignment_to_siblings() — sweeps every
	 * published primary-language `agnosis_artwork` post and re-propagates
	 * its medium assignment to all its translated siblings in one pass.
	 * Built alongside the per-artwork button for the same reason: clearing
	 * today's backlog of already-out-of-sync artwork/sibling pairs (the
	 * Catalan-testing data-integrity incident this whole area exists to
	 * help clean up), not just future edits.
	 *
	 * @return array{artworks: int, synced: int} Count of primary-language
	 *         artworks examined, and the total number of sibling posts a
	 *         medium was actually pushed to across all of them — for the
	 *         admin notice after redirect.
	 */
	public function sync_all_medium_assignments(): array {
		$result = [
			'artworks' => 0,
			'synced'   => 0,
		];

		$primary_lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		if ( '' === $primary_lang ) {
			return $result;
		}

		$artwork_ids = get_posts(
			[
				'post_type'      => 'agnosis_artwork',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-triggered one-off bulk action, not a hot path.
					[
						'key'   => '_lf_lang',
						'value' => $primary_lang,
					],
				],
			]
		);

		foreach ( $artwork_ids as $post_id ) {
			++$result['artworks'];
			$result['synced'] += $this->sync_medium_assignment_to_siblings( (int) $post_id );
		}

		return $result;
	}

	/**
	 * Record one AI translation call for each language Lingua Forge's own
	 * fan-out genuinely translates (seventh audit G-2 —
	 * `AI\CallCounter`/`agnosis-audit/NATIVE-LANGUAGE-PIPELINE.md` §7).
	 *
	 * Hooked on the same `linguaforge_translation_complete` action as
	 * `sync_translated_terms()` above, and skipped under the exact same
	 * condition: `sync_native_sibling()` fires this action synthetically for
	 * the native sibling it just built directly (no AI call at all — that's
	 * the entire point of Phase 4), guarded by
	 * `self::$suppress_native_sibling_term_sync` for the duration of that one
	 * call. Every OTHER firing of this action is a real LF-driven
	 * translation, so it's counted.
	 *
	 * $source_id is used as the counter key (not $translated_id) so every
	 * language's fan-out call accumulates onto the one Agnosis post the
	 * submission actually belongs to — matching
	 * `ReviewEndpoints::translate_native_content_to_primary()`'s own choice
	 * of key for the native→primary call.
	 *
	 * @param int    $translated_id Newly created/updated translated post ID (unused).
	 * @param int    $source_id     Source (primary-language) post ID.
	 * @param string $target_lang   Target language code (unused).
	 */
	public function count_fanout_translation_call( int $translated_id, int $source_id, string $target_lang ): void {
		unset( $translated_id, $target_lang );

		if ( self::$suppress_native_sibling_term_sync ) {
			return; // Our own AI-free native-sibling sync — not a real AI call.
		}

		if ( ! in_array( get_post_type( $source_id ), self::AGNOSIS_POST_TYPES, true ) ) {
			return;
		}

		CallCounter::record( $source_id, 'lf_fanout' );
	}

	// -------------------------------------------------------------------------
	// Template safeguard (LF >= 2.6.1 only)
	// -------------------------------------------------------------------------

	/**
	 * Re-resolve and re-write `_wp_page_template` across an entire translation
	 * group after a translation completes — a free, no-AI, no-content-touching
	 * safeguard on top of LF 2.6.1's own fix for this (see concern #8 in this
	 * class's docblock for the full history).
	 *
	 * `linguaforge_sync_templates()` must be called with the PRIMARY/source-
	 * language post ID — it returns an error (silently ignored here; there is
	 * nothing actionable to do with it) when given a secondary-language ID —
	 * and internally walks every OTHER language in the post's translation
	 * group, so a single call after any one language's translation completes
	 * re-verifies the template assignment for every sibling, not just the one
	 * that just finished. Deliberately not scoped to $target_lang for that
	 * reason: passing $translated_id or checking $target_lang would miss the
	 * "fix every sibling" behavior this function is designed to provide.
	 *
	 * Hooked only when LF >= 2.6.1 (see constructor) — this function does not
	 * exist on older LF, and LF 2.6.1's own TranslationTrigger fix already
	 * covers this class's normal translation-creation path regardless, so
	 * there is nothing to work around on an older version; this is purely
	 * additive insurance against future drift (a theme change, a template
	 * rename, or an LF regression), not a fix for a known gap.
	 *
	 * @param int    $translated_id Newly created/updated translated post ID (unused — see above).
	 * @param int    $source_id     Source (primary-language) post ID.
	 * @param string $target_lang   Target language code (unused — see above).
	 */
	public function sync_translated_template( int $translated_id, int $source_id, string $target_lang ): void {
		unset( $translated_id, $target_lang );

		if ( ! in_array( get_post_type( $source_id ), self::AGNOSIS_POST_TYPES, true ) ) {
			return;
		}

		if ( ! function_exists( 'linguaforge_sync_templates' ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
		linguaforge_sync_templates( $source_id, false );
	}

	/**
	 * Sync every term a source post holds in $taxonomy onto the translated
	 * post, replacing whatever it had before (wp_set_object_terms()'s
	 * default $append = false) — the same "full, blunt overwrite on
	 * re-translation" behavior LF's own update_translated_post() already
	 * applies to post_content/post_title, so this isn't introducing a new
	 * class of surprise on re-translation.
	 *
	 * A source post with no terms in $taxonomy clears the translated post's
	 * own terms too, rather than leaving a stale set behind from a previous
	 * translation pass.
	 *
	 * Trid-based (TERM_TRID_META): for each source term, resolves (or
	 * lazily assigns) its trid, then finds-or-creates the $target_lang
	 * member of that SAME trid group — never a name comparison on the
	 * translated side. This taxonomy-generic version replaced an older,
	 * purely name-matching implementation (translate the name via AI, then
	 * term_exists()/get_term_by('name', ...) to see if a term with that
	 * exact string already existed) that broke down under AI translation
	 * non-determinism: re-running a sync could create a fresh
	 * near-duplicate term instead of recognizing the one already there.
	 * That was first fixed for `agnosis_medium` only — a live incident left
	 * every language bucket there out of the 1:1 parity it's supposed to
	 * have with the 10-term primary vocabulary — then extended here to
	 * `post_tag` once it was clear the same non-determinism risk existed
	 * there too, just without the fixed-count symptom that made the medium
	 * version obvious. Works uniformly whether a source term is itself
	 * primary or already one of another language's translations (both
	 * carry a trid once touched by this method), so no special-casing is
	 * needed for which side of the relationship a term happens to be on.
	 *
	 * Numeric-looking translated names ("2026", or an AI translation that
	 * happens to come back as a bare number) still go through
	 * resolve_numeric_term_name() rather than a plain wp_insert_term() call
	 * — see that method's own docblock for why WordPress's term lookup is
	 * ambiguous for those specifically (sixth audit §6, carried from the
	 * fifth; this predates and is independent of the trid rework).
	 *
	 * @param int    $source_id     Source post ID (primary-language, or
	 *                               itself already translated).
	 * @param int    $translated_id Translated sibling post ID to assign onto.
	 * @param string $taxonomy      Taxonomy to sync — 'post_tag' or 'agnosis_medium'.
	 * @param string $target_lang   Language code being synced to.
	 */
	private function sync_taxonomy( int $source_id, int $translated_id, string $taxonomy, string $target_lang ): void {
		$terms = wp_get_post_terms( $source_id, $taxonomy, [ 'fields' => 'all' ] );

		if ( is_wp_error( $terms ) ) {
			return;
		}

		if ( empty( $terms ) ) {
			wp_set_object_terms( $translated_id, [], $taxonomy );
			return;
		}

		$assign = [];

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$trid     = $this->get_or_create_term_trid( $term->term_id );
			$existing = $this->find_term_by_trid( $trid, $taxonomy, $target_lang );

			if ( $existing instanceof \WP_Term ) {
				$assign[] = $existing->term_id;
				continue;
			}

			$translated_name = $this->translated_term_name( $term->name, $taxonomy, $target_lang );
			if ( $translated_name === $term->name ) {
				// No AI provider configured, or the translation call failed —
				// fall back to assigning the SOURCE term itself rather than
				// creating nothing.
				$assign[] = $term->term_id;
				continue;
			}

			if ( is_numeric( $translated_name ) ) {
				[ $created_id, $was_new ] = $this->resolve_numeric_term_name( $translated_name, $taxonomy );
				if ( 0 === $created_id ) {
					continue; // Nothing resolvable — drop rather than pass the ambiguous numeric string through.
				}
			} else {
				$created = wp_insert_term( $translated_name, $taxonomy );
				if ( is_wp_error( $created ) ) {
					// Most likely a genuine race — another request created
					// the same translation between our trid lookup and this
					// call. One more trid lookup catches that; otherwise
					// fall back to the source term rather than dropping the
					// assignment entirely.
					$existing = $this->find_term_by_trid( $trid, $taxonomy, $target_lang );
					$assign[] = $existing instanceof \WP_Term ? $existing->term_id : $term->term_id;
					continue;
				}
				$created_id = (int) $created['term_id'];
				$was_new    = true;
			}

			if ( $was_new ) {
				add_term_meta( $created_id, self::TRANSLATED_TERM_META, $target_lang, true );
				add_term_meta( $created_id, self::TERM_TRID_META, $trid, true );
			}

			$assign[] = $created_id;
		}

		wp_set_object_terms( $translated_id, $assign, $taxonomy );
	}

	/**
	 * Resolve a numeric-looking translated term name ("2026", or an AI
	 * translation that happens to come back as a bare number) to a real term
	 * ID, sidestepping a documented WordPress ambiguity for numeric-looking
	 * term names (sixth audit §6, carried from the fifth — pre-existing
	 * since 0.9.9, not introduced by sync_taxonomy() itself).
	 *
	 * WordPress's own `term_exists()` adds a `t.term_id = %d` OR-clause to
	 * its lookup SQL whenever `is_numeric( $term )` is true, even though
	 * `$term` is a plain string — so a tag or medium literally named "2026"
	 * can silently match whatever UNRELATED term happens to have term_id
	 * 2026, instead of a term actually named "2026" (creating one, or
	 * reusing an existing one, as intended). `wp_set_object_terms()` calls
	 * `term_exists()` internally and inherits the exact same ambiguity, so
	 * passing a numeric-looking name straight through — the pre-fix
	 * behavior — silently mis-assigns or drops such terms. Passing a
	 * genuine PHP int (not a numeric string) sidesteps this entirely:
	 * `term_exists()` performs ONLY the exact `t.term_id = %d` match when
	 * given an int, with no name/slug fallback at all — so resolving to a
	 * real int ID ourselves, before the name ever reaches
	 * `term_exists()`/`wp_set_object_terms()`, removes the ambiguity.
	 *
	 * @param string $name     The (already-translated) term name to resolve. Caller
	 *                         guarantees `is_numeric( $name )` is true.
	 * @param string $taxonomy Taxonomy to resolve/create the term in.
	 * @return array{0: int, 1: bool} [term_id (0 if genuinely unresolvable), whether
	 *                                 this call just created the term].
	 */
	private function resolve_numeric_term_name( string $name, string $taxonomy ): array {
		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing instanceof \WP_Term ) {
			return [ $existing->term_id, false ];
		}

		$inserted = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $inserted ) ) {
			// Most likely a genuine race — another request created the same
			// term between the lookup above and this call (wp_insert_term()
			// itself returns a "term_exists" WP_Error in that case). One more
			// lookup catches that; if there is still nothing, the term is
			// dropped rather than passed through to wp_set_object_terms() as
			// an ambiguous numeric string anyway.
			$retry = get_term_by( 'name', $name, $taxonomy );
			return $retry instanceof \WP_Term ? [ $retry->term_id, false ] : [ 0, false ];
		}

		return [ (int) $inserted['term_id'], true ];
	}

	/**
	 * Resolve (and cache) the $target_lang name for a taxonomy term.
	 *
	 * Cached in `agnosis_term_translations` (taxonomy → source name → lang →
	 * translated name) rather than translated fresh every time the same term
	 * recurs — both for cost (an AI call per unique (term, language) pair
	 * instead of per post) and for consistency: a controlled vocabulary like
	 * medium needs the SAME translated label every time "Oil Painting"
	 * appears, not a slightly different AI phrasing per artwork, or tag-based
	 * browsing/filtering would silently fragment across near-duplicate terms.
	 *
	 * Falls back to the untranslated name — never blocks the sync — when no AI
	 * provider is configured or a translation call returns empty.
	 *
	 * Cache-write race (fourth audit §4d, noted rather than fixed — see that
	 * finding's rationale): this is a plain read-modify-write of a single WP
	 * option, not an atomic increment. If two `linguaforge_translation_complete`
	 * events land in the same cron window and both reach this method for
	 * different terms before either has written back, the second `update_option()`
	 * overwrites the first — one entry is silently lost. The only consequence is
	 * that term getting re-translated (one extra AI call) the next time it's
	 * encountered, not a corrupted cache or a wrong translation ever being
	 * served — acceptable for a low-traffic admin-side cache, so left
	 * unguarded rather than adding real locking for a cosmetic race.
	 *
	 * A FAILED translation attempt is never cached (fixed 2026-07-19, same
	 * live report as TERM_TRANSLATION_CONTEXT above). `translate_text()`
	 * falls back to returning the SOURCE name unchanged on any failure — no
	 * AI provider, an empty response, or the model returning a non-scalar
	 * JSON value SubmissionTranslator::call_translate() had to drop. The
	 * pre-fix code only skipped the cache write when the result was an empty
	 * string, which a same-as-source fallback never is, so it got written to
	 * the cache exactly like a genuine translation would. Every later sync
	 * attempt then hit that cache entry first and returned the same fallback
	 * immediately — the actual AI call was never retried again, ever, for
	 * that (term, language) pair, which is why re-running "Sync all
	 * translations" could not fix a term that had failed once (the specific
	 * symptom reported: "Mixed Media" permanently missing from German sync,
	 * unchanged across repeated retries). Only clearing the whole term
	 * translation cache (Settings → General → "Clear Term Translation
	 * Cache") ever un-stuck it. Now: a result identical to the source name is
	 * treated as a failure and never cached, so the next sync attempt calls
	 * the AI translator again instead of replaying a stale failure. Trade-off
	 * accepted deliberately: a term whose translation is GENUINELY identical
	 * to its source (a loanword kept as-is in the target language) will incur
	 * one extra AI call on every future "Sync all" run instead of being
	 * cached — indistinguishable from a failure with the information
	 * available here, and the caller (`sync_term_across_languages()`) already
	 * treats the two identically for its own `failed` count, so this doesn't
	 * introduce a new ambiguity, only extends the existing one to the cache.
	 */
	private function translated_term_name( string $name, string $taxonomy, string $target_lang ): string {
		$cache = get_option( self::TERM_TRANSLATIONS_OPTION, [] );

		$cached = $cache[ $taxonomy ][ $name ][ $target_lang ] ?? '';
		if ( '' !== $cached ) {
			return $cached;
		}

		$translator = SubmissionTranslator::from_settings();
		if ( null === $translator ) {
			return $name;
		}

		$translated = trim( $translator->translate_text( $name, $target_lang, self::TERM_TRANSLATION_CONTEXT ) );
		if ( '' === $translated || $translated === $name ) {
			return $name;
		}

		$cache[ $taxonomy ][ $name ][ $target_lang ] = $translated;
		// autoload=false: this can grow into a genuinely large map on a busy,
		// many-language site — no reason to load it on every request when only
		// the (rare) translation dispatch cron tick ever reads it.
		update_option( self::TERM_TRANSLATIONS_OPTION, $cache, false );

		return $translated;
	}

	// -------------------------------------------------------------------------
	// Term-translation cache maintenance (fourth audit §4d)
	// -------------------------------------------------------------------------

	/**
	 * Delete the entire term-translation cache. A bad AI translation of a
	 * term label was otherwise permanent — nothing expired it and
	 * re-translating the same source term always hit the cache by design.
	 * The next sync for every (taxonomy, term, language) combination simply
	 * re-translates from scratch. Exposed for the Settings → General "Clear
	 * Term Translation Cache" action (see Admin\Settings::handle_clear_term_translations_cache()).
	 */
	public static function clear_term_translations_cache(): void {
		delete_option( self::TERM_TRANSLATIONS_OPTION );
	}

	/**
	 * One-time repair (0.9.39) for cache entries poisoned by the term-
	 * translation-cache bug fixed in translated_term_name() above: before
	 * that fix, a FAILED translation attempt (the AI model returning a
	 * non-scalar JSON value for a short, context-free label — see
	 * TERM_TRANSLATION_CONTEXT's own docblock — or simply no AI provider
	 * configured) got cached as if `translated name === source name` were a
	 * genuine translation, permanently short-circuiting every future retry
	 * for that (taxonomy, term, language) triple. Live symptom this was
	 * found from: "Mixed Media" missing from German medium sync, unchanged
	 * across repeated "Sync all translations" runs — translated_term_name()
	 * was returning the poisoned cache hit instantly, never calling the AI
	 * translator again.
	 *
	 * Purges any cache entry whose stored value is identical to its own
	 * source name — the exact self-referential shape only that bug could
	 * produce; a genuine translation into a different language essentially
	 * never matches the source string verbatim for a controlled vocabulary
	 * label. Purging (rather than re-translating here) is deliberate: this
	 * runs from Activator::maybe_upgrade(), which fires on every page load
	 * until the version check passes, so it must stay a plain, fast option
	 * read/write. The next "Sync translations" / "Sync all translations"
	 * click — an existing, on-demand admin action — is what actually
	 * re-attempts the AI call, now benefiting from both this cleared cache
	 * entry and TERM_TRANSLATION_CONTEXT's improved prompt framing.
	 *
	 * Called once from Activator::maybe_upgrade() on the 0.9.39 upgrade.
	 * Safe to re-run: once the cache holds no self-referential entries, this
	 * is a single get_option() call and nothing else.
	 *
	 * @return int Number of poisoned cache entries removed.
	 */
	public static function purge_self_referential_term_translations(): int {
		$cache = get_option( self::TERM_TRANSLATIONS_OPTION, [] );
		if ( ! is_array( $cache ) ) {
			return 0;
		}

		$removed = 0;

		foreach ( $cache as $taxonomy => $by_name ) {
			foreach ( (array) $by_name as $name => $by_lang ) {
				foreach ( (array) $by_lang as $lang => $value ) {
					if ( $value === $name ) {
						unset( $cache[ $taxonomy ][ $name ][ $lang ] );
						++$removed;
					}
				}
				if ( empty( $cache[ $taxonomy ][ $name ] ) ) {
					unset( $cache[ $taxonomy ][ $name ] );
				}
			}
			if ( empty( $cache[ $taxonomy ] ) ) {
				unset( $cache[ $taxonomy ] );
			}
		}

		if ( $removed > 0 ) {
			update_option( self::TERM_TRANSLATIONS_OPTION, $cache, false );
		}

		return $removed;
	}

	/** Total number of cached (taxonomy, term name, language) translations, for the Settings panel. */
	public static function term_translation_cache_count(): int {
		$cache = get_option( self::TERM_TRANSLATIONS_OPTION, [] );
		if ( ! is_array( $cache ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $cache as $names ) {
			foreach ( (array) $names as $langs ) {
				$count += count( (array) $langs );
			}
		}

		return $count;
	}

	/**
	 * Static holder for capture_pre_rename_term_name()'s "before" snapshot,
	 * read by invalidate_renamed_term_cache() on the very next hook firing
	 * for the same $term_id — WP fires edit_terms then edited_term as two
	 * separate actions for the same save, with no built-in way to pass data
	 * between them.
	 *
	 * @var array<int, string>
	 */
	private static array $pre_rename_names = [];

	/**
	 * `edit_terms` callback — snapshot the term's current name just before
	 * WP overwrites it, so invalidate_renamed_term_cache() (hooked on the
	 * `edited_term` action that follows) can tell whether this save actually
	 * changed the name.
	 *
	 * Scoped to post_tag/agnosis_medium only — the two taxonomies this class
	 * ever caches a translation for; no reason to do this lookup for every
	 * term edit sitewide (categories, other custom taxonomies, etc.).
	 */
	public function capture_pre_rename_term_name( int $term_id, string $taxonomy ): void {
		if ( ! in_array( $taxonomy, [ 'post_tag', 'agnosis_medium' ], true ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( $term instanceof \WP_Term ) {
			self::$pre_rename_names[ $term_id ] = $term->name;
		}
	}

	/**
	 * `edited_term` callback — if this save actually renamed the term (name
	 * differs from the snapshot captured above), drop that taxonomy's cache
	 * entries for the OLD name. The cache is keyed by name, not term ID, so a
	 * rename otherwise leaves the old entry orphaned forever — harmless (it's
	 * simply never read again) but unbounded, and a rename is also exactly
	 * the moment an admin is most likely fixing a bad AI translation, which
	 * is the scenario this cache existing at all is meant to help with.
	 *
	 * Every cached language for the old name is dropped, not just one — a
	 * renamed term needs fresh translations into every language, same as a
	 * brand new term would.
	 */
	public function invalidate_renamed_term_cache( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! in_array( $taxonomy, [ 'post_tag', 'agnosis_medium' ], true ) ) {
			return;
		}

		$old_name = self::$pre_rename_names[ $term_id ] ?? null;
		unset( self::$pre_rename_names[ $term_id ] );

		if ( null === $old_name ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! ( $term instanceof \WP_Term ) || $term->name === $old_name ) {
			return; // Not actually a rename (or term vanished mid-request).
		}

		$cache = get_option( self::TERM_TRANSLATIONS_OPTION, [] );
		if ( ! isset( $cache[ $taxonomy ][ $old_name ] ) ) {
			return;
		}

		unset( $cache[ $taxonomy ][ $old_name ] );
		update_option( self::TERM_TRANSLATIONS_OPTION, $cache, false );
	}

	/**
	 * The per-language display title for a translation, if one was built at publish.
	 *
	 * Reads the `_agnosis_title_i18n` map written by build_title_translations() and
	 * returns it as a `_agnosis_translated_title` pair for the target language.
	 * Returns an empty array when no title was built for that language (e.g. no AI
	 * provider configured) — the translation then simply has no display-title
	 * override, and the source's primary title is never copied verbatim.
	 *
	 * @param int    $source_id   Source post ID.
	 * @param string $target_lang Target language code.
	 * @return array<string,mixed>
	 */
	private function collect_translated_title( int $source_id, string $target_lang ): array {
		$map = get_post_meta( $source_id, self::TITLE_I18N_META, true );

		if ( is_array( $map ) && isset( $map[ $target_lang ] ) && '' !== $map[ $target_lang ] ) {
			return [ '_agnosis_translated_title' => (string) $map[ $target_lang ] ];
		}

		return [];
	}

	// -------------------------------------------------------------------------
	// Title handling (dual-title architecture)
	// -------------------------------------------------------------------------

	/**
	 * Keep the artist's original title on a translated post.
	 *
	 * Hooked on `linguaforge_translation_content` (fires after LF's AI call, before
	 * the translated post is written). Agnosis keeps `post_title` in the artist's
	 * own language on every language version — the primary/translated title lives in
	 * `_agnosis_translated_title` and is surfaced by the `agnosis/artwork-title`/
	 * `agnosis/event-title` block. Without this, LF would translate the artist's
	 * (non-primary) title from the wrong source language. We overwrite the AI's
	 * `translated_title` with the source post's original title so LF writes that
	 * verbatim.
	 *
	 * DUAL_TITLE_POST_TYPES only (artwork, event) — the dual-title design doesn't
	 * apply to biography (an artist's name is set at application, not derived
	 * from the bio title, and a biography's title is ordinary translatable
	 * content with no "official own-language name" to preserve — see that
	 * constant's own docblock).
	 *
	 * @param array<string,mixed> $payload     AI translation payload.
	 * @param int                 $post_id     Source post ID being translated.
	 * @param string              $target_lang Target language code.
	 * @return array<string,mixed>
	 */
	public function hold_artist_title( array $payload, int $post_id, string $target_lang ): array {
		unset( $target_lang ); // Same original title regardless of target language.

		if ( ! in_array( get_post_type( $post_id ), self::DUAL_TITLE_POST_TYPES, true ) ) {
			return $payload;
		}

		$source = get_post( $post_id );
		if ( $source instanceof \WP_Post && '' !== $source->post_title ) {
			$payload['translated_title'] = $source->post_title;
		}

		return $payload;
	}

	/**
	 * Appends SubmissionTranslator's own "leave embedded other-language text
	 * untranslated" instruction to LF's `linguaforge_translation_extra_instruction`
	 * filter (LF 2.6.6+), so a quotation or epigraph an artist deliberately left
	 * in a different language (e.g. a Latin original alongside their own
	 * translation of it) survives LF's fan-out translation pass exactly as it
	 * already survives Agnosis's own pre-publish translation — see the shared
	 * constant's own docblock for the full incident this fixes.
	 *
	 * Applies unconditionally to every Agnosis post LF translates (not scoped by
	 * post type or post ID) — the underlying problem (a source text legitimately
	 * mixing languages on purpose) isn't specific to artwork vs. biography vs.
	 * event, and appending a fixed sentence to whatever instruction (if any)
	 * another plugin already supplied via this same filter is always additive,
	 * never destructive of it.
	 *
	 * @param string $instruction Existing extra instruction (usually '').
	 * @param int    $post_id     Source post being translated (unused — instruction is post-type-agnostic).
	 * @return string
	 */
	public function preserve_embedded_other_language_text( string $instruction, int $post_id ): string {
		unset( $post_id );

		return trim( $instruction . ' ' . SubmissionTranslator::PRESERVE_EMBEDDED_OTHER_LANGUAGE_INSTRUCTION );
	}

	/**
	 * Build the per-language display-title map for a freshly published post.
	 *
	 * Translates the primary-language title (`_agnosis_translated_title`) into each
	 * enabled Lingua Forge language and stores the result in `_agnosis_title_i18n`,
	 * which collect_translated_title() reads when each translation is created.
	 *
	 * Runs in the deferred dispatch (off the intake request), before translations
	 * are queued. DUAL_TITLE_POST_TYPES only (artwork, event) — biography uses
	 * LF's normal title translation. No-ops gracefully when the post's type isn't
	 * in that list, has no primary title, has no target languages, or no AI
	 * provider is configured.
	 *
	 * @param int $post_id Source post ID.
	 * @return void
	 */
	public function build_title_translations( int $post_id ): void {
		if ( ! in_array( get_post_type( $post_id ), self::DUAL_TITLE_POST_TYPES, true ) ) {
			return;
		}

		$primary_title = (string) get_post_meta( $post_id, '_agnosis_translated_title', true );
		if ( '' === $primary_title ) {
			return;
		}

		$source_lang = get_post_meta( $post_id, '_lf_lang', true ) ?: 'en';
		$targets     = $this->get_target_languages( (string) $source_lang );
		if ( empty( $targets ) ) {
			return;
		}

		$translator = SubmissionTranslator::from_settings();
		if ( null === $translator ) {
			return; // No provider configured — translations keep no display-title override.
		}

		// Fifth audit §4d: one envelope call translating the title into every
		// target language at once, instead of one translate_text() call per
		// language — the same title fan-out ran on every artwork publish on a
		// multilingual site, each call re-shipping a full translate prompt for
		// a ~10-word string. translate_to_languages() applies the identical
		// "only keep an actual change" filter the old per-language loop did.
		$map = $translator->translate_to_languages( $primary_title, $targets );

		if ( ! empty( $map ) ) {
			update_post_meta( $post_id, self::TITLE_I18N_META, $map );
		}
	}

	// -------------------------------------------------------------------------
	// SEO filters
	// -------------------------------------------------------------------------

	/**
	 * Supply the featured artwork image as the Open Graph image.
	 *
	 * LF 2.3.3 fires `linguaforge_seo_og_image` with a single string arg (the
	 * current candidate URL). We use get_post() to identify the current post
	 * rather than receiving it as a parameter.
	 *
	 * @param string $image_url  Current OG image URL.
	 * @return string
	 */
	public function filter_og_image( string $image_url ): string {
		$post = get_post();
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'agnosis_artwork' ) {
			return $image_url;
		}

		$thumbnail = get_the_post_thumbnail_url( $post->ID, 'agnosis-artwork' );
		return $thumbnail ?: $image_url;
	}

	/**
	 * Override Schema.org `@type` to `VisualArtwork` for artwork singular pages.
	 *
	 * LF 2.3.3 fires `linguaforge_seo_schema_data` with the full schema array
	 * and a type string. We modify `$data['@type']` in-place and return the array.
	 *
	 * @param array<string, mixed> $data  Current schema.org data array.
	 * @param string               $type  Schema type hint (e.g. 'Article').
	 * @return array<string, mixed>
	 */
	public function filter_schema_type( array $data, string $type ): array {
		if ( is_singular( 'agnosis_artwork' ) ) {
			$data['@type'] = 'VisualArtwork';
		}

		return $data;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Returns true when Lingua Forge is loaded and functional. */
	public static function is_active(): bool {
		return defined( 'LINGUAFORGE_FILE' ) && defined( 'LINGUAFORGE_VERSION' );
	}

	/**
	 * Resolve the current request's two-letter language code — the
	 * authoritative "what language is this page actually being viewed in"
	 * signal, as opposed to get_locale() (the site's/admin's own configured
	 * language, which doesn't change when LF serves a translated post/page).
	 *
	 * Prefers Lingua Forge's own `LF_LANG` constant, set by its language
	 * router for the current request (see current_lang_path_prefix()'s own
	 * docblock for how LF derives it — URL path prefix / cookie /
	 * Accept-Language header, independent of any specific post). Falls back
	 * to locale_to_lang( get_locale() ) when LF isn't active/bootstrapped for
	 * this request, so callers get a sensible answer either way.
	 *
	 * Public + static (promoted from a private copy that used to live only in
	 * Core\DateFormatter — see that class's own current_lang(), which now
	 * just delegates here) so any caller needing "what language is the
	 * visitor actually reading/writing in right now" — e.g. an explicit
	 * `lang` attribute on a visitor-facing textarea, so the browser's spell
	 * checker matches what's being typed — has one shared, correct answer
	 * rather than each re-deriving it.
	 */
	public static function current_lang(): string {
		if ( defined( 'LF_LANG' ) && '' !== LF_LANG ) {
			return (string) LF_LANG;
		}

		return self::locale_to_lang( get_locale() );
	}

	/**
	 * The current request's language, as a joinable URL path segment.
	 *
	 * Returns '' when Lingua Forge isn't active, `LF_LANG` isn't defined or
	 * empty (LF not yet bootstrapped for this request, or a non-routable
	 * request type), or the current language IS the site's configured source
	 * language — a source-language URL must never get a redundant prefix.
	 * Otherwise returns '/xx' (no trailing slash), ready to prepend to a path.
	 *
	 * `LF_LANG` is derived purely from the URL path prefix / `lf_lang` cookie /
	 * `Accept-Language` header (see LF's `Context::detect_lang_safe()`) —
	 * independent of any post or page — so this is equally correct on a
	 * singular post and on a "Your latest posts" homepage.
	 *
	 * Used to keep artist-subdomain links (breadcrumb, portal back-link,
	 * gallery-overview artist links) on the visitor's current language instead
	 * of always dropping back to the subdomain's source-language root. Only
	 * meaningful in LF's path/subfolder routing mode — the call sites that
	 * matter here only run when Agnosis's own artist-subdomain routing is
	 * active, and `SubdomainRouter::boot()` refuses to run at all when LF is
	 * configured for subdomain routing mode (both would claim the same
	 * subdomain namespace) — so by the time this runs, LF is guaranteed to be
	 * either inactive or in path mode.
	 *
	 * @return string '' or '/xx'.
	 */
	public static function current_lang_path_prefix(): string {
		if ( ! self::is_active() || ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return '';
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
		$source = function_exists( 'linguaforge_source_language' ) ? linguaforge_source_language() : '';

		return ( LF_LANG === $source ) ? '' : '/' . LF_LANG;
	}

	/**
	 * Return the target language list: all LF-configured languages minus the source.
	 *
	 * Uses the linguaforge_languages() global function exposed by LF's language-
	 * router module — the canonical public API for the active routing language list.
	 * Returns an empty array when LF is not loaded or the function is not defined.
	 *
	 * @param string $source_lang  BCP-47 tag to exclude.
	 * @return string[]
	 */
	private function get_target_languages( string $source_lang ): array {
		if ( ! function_exists( 'linguaforge_languages' ) ) {
			return [];
		}

		/** @var string[] $all */
		$all = linguaforge_languages();

		if ( empty( $all ) ) {
			return [];
		}

		return array_values( array_filter(
			$all,
			fn( string $lang ) => $lang !== $source_lang
		) );
	}

	/**
	 * Convert a WordPress locale string (e.g. "de_DE") to a BCP-47 language
	 * tag (e.g. "de") for use with Lingua Forge's language system.
	 *
	 * Public + static so callers outside this class can compare a WP user's own
	 * `locale` field (e.g. an artist's admission-time language choice — see
	 * `Artist\Admission::apply()`) against a post's `_lf_lang` meta using the same
	 * conversion `set_language_meta()` itself relies on. Used by
	 * `Artist\ContentEditor` to restrict front-end correction to the post version
	 * matching the artist's own declared language (audit §7c, reassessed 2026-07-06).
	 */
	public static function locale_to_lang( string $locale ): string {
		// LF typically uses two-letter primary subtags ("en", "de", "es", "fr"…).
		return strtolower( explode( '_', $locale )[0] );
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	/**
	 * Show a blocking admin notice when LinguaForge is active in subdomain
	 * routing mode.
	 *
	 * In that configuration both plugins compete for the same subdomain
	 * namespace: LF expects language subdomains (en.agnosis.art) while Agnosis
	 * expects artist subdomains (artistx.agnosis.art). Artist subdomain routing
	 * is completely disabled until the conflict is resolved.
	 *
	 * Only shown when a base domain has been configured — if the admin hasn't
	 * set one yet there is nothing to conflict with.
	 */
	public function compatibility_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only relevant when artist subdomains are intended (base domain set).
		if ( ! get_option( 'agnosis_base_domain' ) ) {
			return;
		}

		// Check LinguaForge routing mode.
		if (
			! defined( 'LINGUAFORGE_VERSION' ) ||
			'subdomain' !== (string) get_option( 'linguaforge_routing_mode', 'path' )
		) {
			return;
		}

		$lf_settings_url = admin_url( 'admin.php?page=lingua-forge' );

		// Build the body as separate escaped fragments — i18n requires single
		// string literals; HTML-heavy technical notices are split by sentence.
		$body =
			'<strong>LinguaForge</strong> '
			. esc_html__( 'is active and configured for', 'agnosis' )
			. ' <strong>' . esc_html__( 'Subdomain', 'agnosis' ) . '</strong> '
			. esc_html__( 'routing mode', 'agnosis' )
			. ' (<code>linguaforge_routing_mode = subdomain</code>). '
			. esc_html__( 'This conflicts with Agnosis artist subdomains — both plugins would claim the same subdomain namespace.', 'agnosis' )
			. ' ' . esc_html__( 'Artist subdomain routing is', 'agnosis' )
			. ' <strong>' . esc_html__( 'completely inactive', 'agnosis' ) . '</strong> '
			. esc_html__( 'until this is resolved.', 'agnosis' )
			. '<br><br>'
			. esc_html__( 'Fix: open', 'agnosis' )
			. ' <strong>LinguaForge &rarr; ' . esc_html__( 'Settings', 'agnosis' ) . ' &rarr; ' . esc_html__( 'Language Router', 'agnosis' ) . '</strong> '
			. esc_html__( 'and switch the URL strategy to', 'agnosis' )
			. ' <strong>' . esc_html__( 'Path prefix (subfolder)', 'agnosis' ) . '</strong>. '
			. esc_html__( 'This is the LinguaForge default and allows artist subdomains to coexist with language subfolders', 'agnosis' )
			. ' (e.g. <code>artistx.' . esc_html( (string) get_option( 'agnosis_base_domain' ) ) . '/en/</code>).';

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p><p><a href="%s" class="button button-primary">%s</a></p></div>',
			esc_html__( 'Agnosis — Artist Subdomain Routing is disabled', 'agnosis' ),
			wp_kses(
				$body,
				[
					'strong' => [],
					'code'   => [],
					'br'     => [],
				]
			),
			esc_url( $lf_settings_url ),
			esc_html__( 'Open LinguaForge Settings', 'agnosis' )
		);
	}
}
