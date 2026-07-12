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
 *     separately into `_agnosis_translated_title`. Artwork only.
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
 *     term name, language) so a recurring value (a common tag, or one of
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
 * When Lingua Forge is NOT active, this class does nothing — all hooks are
 * registered conditionally. No hard dependency.
 *
 * @package Agnosis\Compat
 */

declare(strict_types=1);

namespace Agnosis\Compat;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Core\Logger;

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
		'_thumbnail_id',             // featured image (first gallery image)
		'_agnosis_gallery_ids',      // gallery attachment IDs
		'_agnosis_original_title',   // artist's own-words title (language-neutral)
		'_agnosis_event_location',   // events only
		'_agnosis_event_date',       // events only
		'_agnosis_intake_endpoint',  // which address created the artwork (artwork/photo/pure)
	];

	/**
	 * Per-language display-title map stored on a source post: BCP-47 code => title.
	 * Built (artwork only) from the primary-language title and consumed at
	 * translation time to set each translated post's `_agnosis_translated_title`.
	 */
	private const TITLE_I18N_META = '_agnosis_title_i18n';

	/** WP-Cron hook that runs the deferred translation kickoff (off the intake request). */
	private const DISPATCH_HOOK = 'agnosis_dispatch_lf_translations';

	/**
	 * Cache of translated taxonomy term names: taxonomy => source name => lang
	 * => translated name. See translated_term_name()'s docblock for why this
	 * exists (cost + cross-post consistency for a repeated tag/medium value).
	 */
	private const TERM_TRANSLATIONS_OPTION = 'agnosis_term_translations';

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

		// Dual-title (artwork only): keep the artist's original title on translated
		// posts. LF would otherwise translate post_title — deliberately kept in the
		// artist's own language, not the primary — from the wrong source. The correct
		// per-language title is carried in _agnosis_translated_title instead. Events
		// and biographies use LF's normal title translation.
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

		// Tag / medium translation (2026-07-08). Unlike the meta-propagation
		// above, this is NOT forked by LF version: LF 2.4.0's born-with filter
		// (linguaforge_translated_post_meta) fires before the translated post is
		// inserted and has no ID yet, so there is nothing to attach taxonomy
		// relationships to at that point regardless of version — term
		// assignment can only ever happen after insert. Always hooked on
		// linguaforge_translation_complete, which — per copy_translated_meta()'s
		// own docblock above — fires on both creation and re-translation either way.
		add_action( 'linguaforge_translation_complete', [ $this, 'sync_translated_terms' ], 10, 3 );

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
		$body_block = '' !== $native_body
			? '<!-- wp:paragraph --><p>' . wp_kses_post( $native_body ) . '</p><!-- /wp:paragraph -->'
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
		self::$suppress_native_sibling_term_sync = true;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
		do_action( 'linguaforge_translation_complete', $sibling_id, $primary_post_id, $native_lang );
		self::$suppress_native_sibling_term_sync = false;

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
	 * "fix every sibling" behaviour this function is designed to provide.
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
	 * Translate every term name a source post holds in $taxonomy and assign the
	 * translated set to the translated post, replacing whatever it had before
	 * (wp_set_object_terms()'s default $append = false) — the same "full,
	 * blunt overwrite on re-translation" behaviour LF's own
	 * update_translated_post() already applies to post_content/post_title, so
	 * this isn't introducing a new class of surprise on re-translation.
	 *
	 * A source post with no terms in $taxonomy clears the translated post's own
	 * terms too, rather than leaving a stale set behind from a previous
	 * translation pass.
	 *
	 * Any translated name that doesn't already exist in $taxonomy is, by
	 * definition, a term this method itself is about to create via
	 * wp_set_object_terms()'s auto-create behaviour — not one an admin
	 * deliberately added. Such terms are stamped with TRANSLATED_TERM_META so
	 * `PromptConfig::medium_terms()` can exclude them from the AI's controlled
	 * vocabulary (fourth audit §4c: without this, AI-translated term names
	 * accumulate in `agnosis_medium`/`post_tag` indistinguishable from
	 * admin-curated ones, polluting both the AI prompt's term list and the
	 * admin taxonomy screens).
	 *
	 * Numeric-looking names ("2026", or an AI translation that happens to
	 * come back as a bare number) are handled separately from the plain
	 * term_exists()/auto-create flow above — see resolve_numeric_term_name()
	 * (sixth audit §6, carried from the fifth) for why WordPress's own term
	 * lookup is ambiguous for those specifically, and how this method avoids
	 * it.
	 */
	private function sync_taxonomy( int $source_id, int $translated_id, string $taxonomy, string $target_lang ): void {
		$names = wp_get_post_terms( $source_id, $taxonomy, [ 'fields' => 'names' ] );

		if ( is_wp_error( $names ) ) {
			return;
		}

		if ( empty( $names ) ) {
			wp_set_object_terms( $translated_id, [], $taxonomy );
			return;
		}

		$translated_names = array_map(
			fn( string $name ) => $this->translated_term_name( $name, $taxonomy, $target_lang ),
			$names
		);

		// Numeric-looking names (a literal year like "2026", or an AI
		// translation that happens to come back as a bare number) are
		// resolved to a real term ID here, BEFORE anything below ever hands
		// the raw numeric string to term_exists()/wp_set_object_terms() —
		// see resolve_numeric_term_name()'s docblock for why WordPress's own
		// lookup is ambiguous for those, and not for an actual int (sixth
		// audit §6, carried from the fifth — pre-existing since 0.9.9, not
		// introduced by this method). Non-numeric names are completely
		// unaffected and keep the original behaviour: term_exists() is
		// checked BEFORE the assignment call so a genuinely new name can
		// still be identified afterward (wp_set_object_terms() auto-creates
		// it, and its ID is only known once that call returns).
		$assign     = []; // int|string values, in order, for wp_set_object_terms().
		$new_names  = []; // non-numeric names not yet existing — resolved to IDs after assignment.
		$newly_made = []; // term IDs already known to be freshly created — numeric names resolve immediately.

		foreach ( $translated_names as $name ) {
			if ( is_numeric( $name ) ) {
				[ $term_id, $was_new ] = $this->resolve_numeric_term_name( $name, $taxonomy );
				if ( 0 === $term_id ) {
					continue; // Nothing resolvable — drop rather than pass the ambiguous numeric string through.
				}
				$assign[] = $term_id;
				if ( $was_new ) {
					$newly_made[] = $term_id;
				}
				continue;
			}

			if ( ! term_exists( $name, $taxonomy ) ) {
				$new_names[] = $name;
			}
			$assign[] = $name;
		}

		wp_set_object_terms( $translated_id, $assign, $taxonomy );

		foreach ( $newly_made as $term_id ) {
			add_term_meta( $term_id, self::TRANSLATED_TERM_META, $target_lang, true );
		}

		foreach ( $new_names as $name ) {
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				add_term_meta( $term->term_id, self::TRANSLATED_TERM_META, $target_lang, true );
			}
		}
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
	 * behaviour — silently mis-assigns or drops such terms. Passing a
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

		$translated = trim( $translator->translate_text( $name, $target_lang ) );
		if ( '' === $translated ) {
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
	 * `_agnosis_translated_title` and is surfaced by the `agnosis/artwork-title`
	 * block. Without this, LF would translate the artist's (non-primary) title from
	 * the wrong source language. We overwrite the AI's `translated_title` with the
	 * source post's original title so LF writes that verbatim.
	 *
	 * Artwork only — the dual-title design is artwork-specific. Events and
	 * biographies use LF's normal title translation (an event title is translatable
	 * content; an artist's name is set at application, not derived from the bio title).
	 *
	 * @param array<string,mixed> $payload     AI translation payload.
	 * @param int                 $post_id     Source post ID being translated.
	 * @param string              $target_lang Target language code.
	 * @return array<string,mixed>
	 */
	public function hold_artist_title( array $payload, int $post_id, string $target_lang ): array {
		unset( $target_lang ); // Same original title regardless of target language.

		if ( 'agnosis_artwork' !== get_post_type( $post_id ) ) {
			return $payload;
		}

		$source = get_post( $post_id );
		if ( $source instanceof \WP_Post && '' !== $source->post_title ) {
			$payload['translated_title'] = $source->post_title;
		}

		return $payload;
	}

	/**
	 * Build the per-language display-title map for a freshly published post.
	 *
	 * Translates the primary-language title (`_agnosis_translated_title`) into each
	 * enabled Lingua Forge language and stores the result in `_agnosis_title_i18n`,
	 * which collect_translated_title() reads when each translation is created.
	 *
	 * Runs in the deferred dispatch (off the intake request), before translations
	 * are queued. Artwork only — events/biographies use LF's normal title
	 * translation. No-ops gracefully when the post is not an artwork, has no primary
	 * title, has no target languages, or no AI provider is configured.
	 *
	 * @param int $post_id Source post ID.
	 * @return void
	 */
	public function build_title_translations( int $post_id ): void {
		if ( 'agnosis_artwork' !== get_post_type( $post_id ) ) {
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
