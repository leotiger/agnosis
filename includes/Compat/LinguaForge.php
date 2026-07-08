<?php
/**
 * Lingua Forge compatibility layer.
 *
 * Integrates Agnosis with Lingua Forge (the official translation plugin for
 * the Agnosis network) when both plugins are active on the same site.
 *
 * What this does when Lingua Forge is present:
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
 *  3. SEO METADATA — overrides the Open Graph image with the artwork's
 *     featured thumbnail via the `linguaforge_seo_og_image` filter.
 *
 *  4. ARTWORK SCHEMA — hooks into LF's `linguaforge_seo_schema_data` filter
 *     to annotate artwork posts as `VisualArtwork` rather than generic `Article`.
 *
 *  5. TAG / MEDIUM TRANSLATION — LF's own translation pipeline never touches
 *     taxonomy terms at all, so a translated post is otherwise created with no
 *     tags and no medium term whatsoever. sync_translated_terms() translates
 *     and assigns both onto every translated sibling, cached per (taxonomy,
 *     term name, language) so a recurring value (a common tag, or one of
 *     `agnosis_medium`'s built-in options) gets the same translated label
 *     every time rather than a fresh AI phrasing per post.
 *
 * When Lingua Forge is NOT active, this class does nothing — all hooks are
 * registered conditionally. No hard dependency.
 *
 * @package Agnosis\Compat
 */

declare(strict_types=1);

namespace Agnosis\Compat;

use Agnosis\AI\SubmissionTranslator;

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
		add_action( 'agnosis_post_published',        [ $this, 'schedule_translations' ], 20, 1 );
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
		// the translated post is *born* (no empty-meta window) — prefer it. On older
		// LF, fall back to the post-save action, which also covers re-translation.
		if (
			defined( 'LINGUAFORGE_VERSION' )
			&& version_compare( (string) LINGUAFORGE_VERSION, '2.4.0', '>=' )
		) {
			add_filter( 'linguaforge_translated_post_meta', [ $this, 'supply_translated_meta' ], 10, 4 );
		} else {
			add_action( 'linguaforge_translation_complete', [ $this, 'copy_translated_meta' ], 10, 3 );
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
	 * Pre-2.4.0 fallback: copy the meta after the translated post is saved.
	 *
	 * Hooked on `linguaforge_translation_complete`. Runs on both creation and
	 * re-translation, so it also refreshes images if the source changes. There is a
	 * brief window after creation where the post exists without these keys — which
	 * is exactly why LF 2.4.0's born-complete filter (above) is preferred.
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
	 * / copy_translated_meta() are (see the constructor).
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

		wp_set_object_terms( $translated_id, $translated_names, $taxonomy );
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

		$map = [];
		foreach ( $targets as $lang ) {
			$translated = $translator->translate_text( $primary_title, $lang );
			if ( '' !== $translated && $translated !== $primary_title ) {
				$map[ $lang ] = $translated;
			}
		}

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
