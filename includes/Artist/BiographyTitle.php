<?php
/**
 * Enforces an optional, site-wide preset title on every agnosis_biography post.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agnosis\AI\SubmissionTranslator;
use WP_User;

/**
 * Settings → General → "Preset biography title" (`agnosis_biography_preset_title`,
 * default '') lets an operator force every biography page to use one fixed
 * title instead of the artist's own — regardless of how or where that title
 * was last set: ApplicationBiography's admission-derived "About {name}"
 * draft, PostCreator's email-subject-as-title on creation or a later
 * bio@ resend, an artist's own edit on the approve-confirm form (routed
 * through ReviewEndpoints::save()), or a front-end post-publish edit via
 * Artist\ContentEditor. Left blank (the default), nothing here changes
 * anything and every one of those paths keeps working exactly as before —
 * the artist's own title, whatever it is.
 *
 * Deliberately hooked on WordPress core's own `wp_insert_post_data` filter
 * rather than any one of those individual write paths. That filter runs
 * inside every single `wp_insert_post()`/`wp_update_post()` call — insert or
 * update alike — immediately before the row is written, regardless of which
 * of the paths above (or any future one) triggered it. This is one choke
 * point instead of separately patching every title-setting call site above,
 * which would be easy to leave inconsistent the next time a new one is added.
 *
 * That includes Lingua Forge's own translated-sibling creation and Agnosis's
 * own native-language sibling creation (Compat\LinguaForge::sync_native_sibling()),
 * so every biography page — every language — gets the preset title rather
 * than the artist's own. But unlike the artwork dual-title system, a
 * biography title is normally machine-translated per language (see this
 * class's own translate_for_sibling(), and Compat\LinguaForge::hold_artist_title()'s
 * docblock for why artwork is the one exception) — so a preset title should
 * be too, not shown as the same fixed (likely English) text on every single
 * language version. apply_preset_title() below still sets the untranslated
 * preset as EVERY new post's initial title (simplest correct default, and
 * exactly right for the primary-language post, which needs no translation at
 * all) — translate_for_sibling(), hooked separately on
 * `linguaforge_translation_complete` (fires for both a genuine LF-AI-translated
 * sibling and Agnosis's own native-language one), then overwrites that initial
 * value with a translated one for every OTHER language, the same "set once,
 * correct for sibling afterward" shape sync_native_sibling() itself already
 * uses for tags/medium.
 *
 * Settings → General → "Include artist's name in preset title"
 * (`agnosis_biography_preset_title_include_name`, default off) appends the
 * post author's own WordPress display name to the preset title when it's
 * active — applied AFTER translation for a sibling, so the artist's name
 * itself (a proper noun) is never run through the AI translator. Has no
 * effect while the preset title itself is blank.
 *
 * Note for anyone editing a biography's title afterward (approve-confirm
 * form, front-end ContentEditor): while a preset title is configured, that
 * field remains visible and editable but has no visible effect — this class
 * overrides it again on the very next save, the same as it overrides
 * whatever those forms pre-filled the field with. This is intentional: the
 * setting is meant to always win, not just seed a default an artist can
 * still override.
 */
class BiographyTitle {

	/**
	 * Cache of translated preset titles: preset text => target language => translated text.
	 * Same shape/rationale as Compat\LinguaForge::TERM_TRANSLATIONS_OPTION — a
	 * biography-title translation is just as reusable across every artist's
	 * sibling pages, and just as worth not re-spending an AI call on every
	 * single sync. Cleared automatically as a side effect of nothing (an
	 * operator changing the preset text just accumulates a small number of
	 * harmless, never-read-again entries under the old text — not worth a
	 * dedicated invalidation path for a handful of short strings).
	 */
	private const PRESET_TITLE_TRANSLATIONS_OPTION = 'agnosis_biography_preset_title_translations';

	/**
	 * Suppresses apply_preset_title() for the exact duration of
	 * translate_for_sibling()'s own wp_update_post() call below.
	 *
	 * wp_update_post() re-fires `wp_insert_post_data` on its way in — without
	 * this flag, apply_preset_title() would fire a second time on that same
	 * call and immediately stomp the just-translated title back to the raw,
	 * untranslated preset (plus artist name), making translate_for_sibling()
	 * a complete no-op. Static (not instance state) for the same reason
	 * Compat\LinguaForge's own $suppress_native_sibling_term_sync is: no
	 * guarantee this exact instance is the one wp_update_post()'s filter call
	 * re-enters through.
	 *
	 * @var boolean
	 */
	private static bool $suppress_preset_override = false;

	public function register_hooks(): void {
		add_filter( 'wp_insert_post_data', [ $this, 'apply_preset_title' ], 10, 2 );

		// Runs AFTER apply_preset_title() has already set the untranslated
		// preset as $translated_id's initial title (that filter fired
		// earlier, during the wp_insert_post()/wp_update_post() call this
		// action follows) — see this class's own docblock for why a second,
		// separate pass is needed rather than translating inline.
		add_action( 'linguaforge_translation_complete', [ $this, 'translate_for_sibling' ], 10, 3 );
	}

	/**
	 * @param array<string, mixed> $data    Sanitized post fields about to be saved.
	 * @param array<string, mixed> $postarr Raw args passed to wp_insert_post()/wp_update_post() — unused, $data already carries the merged, sanitized post_author/post_type.
	 * @return array<string, mixed>
	 */
	public function apply_preset_title( array $data, array $postarr ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedFunctionParameter -- required by the wp_insert_post_data filter signature.
		if ( self::$suppress_preset_override ) {
			return $data;
		}

		if ( 'agnosis_biography' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$preset_title = trim( (string) get_option( 'agnosis_biography_preset_title', '' ) );
		if ( '' === $preset_title ) {
			return $data;
		}

		$data['post_title'] = self::maybe_append_artist_name( $preset_title, (int) ( $data['post_author'] ?? 0 ) );

		return $data;
	}

	/**
	 * Appends the artist's own WordPress display name to $title when Settings
	 * → General → "Include artist's name in preset title" is on — shared by
	 * apply_preset_title() (untranslated, primary-language post) and
	 * translate_for_sibling() (a sibling's own translated title), so the name
	 * itself is appended identically either way, always AFTER whatever
	 * translation (if any) already happened to $title — a proper noun has no
	 * business going through the AI translator.
	 */
	private static function maybe_append_artist_name( string $title, int $author_id ): string {
		if ( ! (bool) get_option( 'agnosis_biography_preset_title_include_name', false ) ) {
			return $title;
		}

		$artist = get_userdata( $author_id );
		if ( ! $artist instanceof WP_User || '' === $artist->display_name ) {
			return $title;
		}

		/* translators: 1: preset biography title (Settings → General); 2: artist's WordPress display name */
		return sprintf( __( '%1$s — %2$s', 'agnosis' ), $title, $artist->display_name );
	}

	/**
	 * Overwrite a sibling's preset title (already set, untranslated, by
	 * apply_preset_title() at insert) with a machine-translated version.
	 *
	 * Hooked on `linguaforge_translation_complete`, which fires for BOTH a
	 * genuine Lingua Forge AI-translated sibling and Agnosis's own
	 * native-language sibling (Compat\LinguaForge::sync_native_sibling()) —
	 * see that class's own docblock. No-ops (leaves the untranslated preset
	 * standing) when: the source post isn't an agnosis_biography, no preset
	 * title is configured, or no AI provider is configured to translate it
	 * (SubmissionTranslator::from_settings() returns null) — same graceful
	 * degradation every other AI-optional feature in this plugin uses.
	 *
	 * @param int    $translated_id Sibling post ID whose title to overwrite.
	 * @param int    $source_id     Source (primary-language) post ID.
	 * @param string $target_lang   ISO 639-1 target language code.
	 */
	public function translate_for_sibling( int $translated_id, int $source_id, string $target_lang ): void {
		if ( 'agnosis_biography' !== get_post_type( $source_id ) ) {
			return;
		}

		$preset_title = trim( (string) get_option( 'agnosis_biography_preset_title', '' ) );
		if ( '' === $preset_title ) {
			return;
		}

		$translated = self::translated_preset_title( $preset_title, $target_lang );
		$final      = self::maybe_append_artist_name( $translated, (int) get_post_field( 'post_author', $translated_id ) );

		// try/finally: a throwing wp_update_post() (or a callback it triggers)
		// must never leave this flag stuck true — that would silently disable
		// the preset title for every OTHER agnosis_biography write for the
		// rest of the request, same reasoning as sync_native_sibling()'s own
		// try/finally around $suppress_native_sibling_term_sync.
		self::$suppress_preset_override = true;
		try {
			wp_update_post( [ 'ID' => $translated_id, 'post_title' => $final ] );
		} finally {
			self::$suppress_preset_override = false;
		}
	}

	/**
	 * Translate the preset title into $target_lang, cached per (text, language)
	 * so re-syncing the same sibling (a resubmission, a second staged update)
	 * never re-spends an AI call translating text that never changes — same
	 * shape and rationale as Compat\LinguaForge's own term-translation cache.
	 */
	private static function translated_preset_title( string $preset_title, string $target_lang ): string {
		$cache  = get_option( self::PRESET_TITLE_TRANSLATIONS_OPTION, [] );
		$cached = $cache[ $preset_title ][ $target_lang ] ?? '';
		if ( '' !== $cached ) {
			return $cached;
		}

		$translator = SubmissionTranslator::from_settings();
		if ( null === $translator ) {
			return $preset_title;
		}

		$translated = trim( $translator->translate_text( $preset_title, $target_lang ) );
		if ( '' === $translated ) {
			return $preset_title;
		}

		$cache[ $preset_title ][ $target_lang ] = $translated;
		update_option( self::PRESET_TITLE_TRANSLATIONS_OPTION, $cache, false );

		return $translated;
	}

	/**
	 * One-time repair (0.9.23) for damage done before the 0.9.22 fix to
	 * SubmissionTranslator::call_translate() landed.
	 *
	 * That fix stopped the literal string "Array" from ever being produced
	 * by a NEW call to the AI translator, but couldn't undo two things
	 * already sitting on a live site from before it landed:
	 *
	 *   1. Any sibling post whose post_title had already been overwritten
	 *      with "Array" (or "Array — Artist Name", when the include-name
	 *      option is on) by translate_for_sibling() — the buggy cast is
	 *      long gone, but its output was already saved to the database.
	 *   2. A "Array" value already sitting in
	 *      PRESET_TITLE_TRANSLATIONS_OPTION's cache for the current preset
	 *      text/language pair — translated_preset_title() returns a cache
	 *      hit without ever calling the (now-fixed) translator again, so
	 *      that one bad cached value would otherwise keep being served back
	 *      out, unchanged, forever.
	 *
	 * Called once from Activator::maybe_upgrade() on the 0.9.23 upgrade.
	 * No-ops entirely if no preset title is currently configured (nothing to
	 * compute a correct replacement from) or Lingua Forge's language lookup
	 * isn't available. Safe to re-run: once the cache holds no "Array"
	 * entries and no post has a literal "Array" title, both passes below
	 * simply find nothing to do.
	 *
	 * @return int Number of post titles corrected.
	 */
	public static function repair_array_titles(): int {
		// Purge any cached "Array" translation first, so a post this pass
		// can't yet reach (e.g. no AI provider configured right now) at
		// least won't keep re-serving the stale bad value once one is.
		$cache   = get_option( self::PRESET_TITLE_TRANSLATIONS_OPTION, [] );
		$dirty   = false;
		foreach ( $cache as $text => $by_lang ) {
			foreach ( $by_lang as $lang => $value ) {
				if ( 'Array' === $value ) {
					unset( $cache[ $text ][ $lang ] );
					$dirty = true;
				}
			}
			if ( empty( $cache[ $text ] ) ) {
				unset( $cache[ $text ] );
			}
		}
		if ( $dirty ) {
			update_option( self::PRESET_TITLE_TRANSLATIONS_OPTION, $cache, false );
		}

		$preset_title = trim( (string) get_option( 'agnosis_biography_preset_title', '' ) );
		if ( '' === $preset_title || ! function_exists( 'linguaforge_get_lang' ) ) {
			return 0;
		}

		global $wpdb;
		// Raw title match rather than WP_Query: this is a one-time repair for
		// an exact, known-bad literal string, not a general content query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ( post_title = %s OR post_title LIKE %s )",
			'agnosis_biography',
			'Array',
			$wpdb->esc_like( 'Array — ' ) . '%'
		) );

		$fixed = 0;
		foreach ( $post_ids as $post_id ) {
			$post_id     = (int) $post_id;
			$target_lang = sanitize_key( linguaforge_get_lang( $post_id ) );
			if ( '' === $target_lang ) {
				continue;
			}

			$translated = self::translated_preset_title( $preset_title, $target_lang );
			$final      = self::maybe_append_artist_name( $translated, (int) get_post_field( 'post_author', $post_id ) );

			if ( $final === get_post_field( 'post_title', $post_id ) ) {
				continue; // Nothing actually changed (e.g. no AI provider configured) — leave it for a later run.
			}

			// Same suppression as translate_for_sibling() above — without it,
			// this wp_update_post() call would re-fire wp_insert_post_data
			// and apply_preset_title() would immediately stomp $final back
			// to the raw, untranslated preset.
			self::$suppress_preset_override = true;
			try {
				wp_update_post( [ 'ID' => $post_id, 'post_title' => $final ] );
			} finally {
				self::$suppress_preset_override = false;
			}
			++$fixed;
		}

		return $fixed;
	}
}
