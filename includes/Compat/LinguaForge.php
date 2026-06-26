<?php
/**
 * Lingua Forge compatibility layer.
 *
 * Integrates Agnosis with Lingua Forge (the official translation plugin for
 * the Agnosis network) when both plugins are active on the same site.
 *
 * What this does when Lingua Forge is present:
 *
 *  1. LANGUAGE META — tags every new artwork post with `_lang` (the source
 *     language of the artist's submission) so LF's router and hreflang
 *     system can handle multilingual URL routing automatically.
 *
 *  2. TRANSLATION TRIGGER — after an artwork is published, fires LF's
 *     translation pipeline via the `linguaforge_request_translation` action
 *     so the title, excerpt and body are translated into all configured site
 *     languages without the artist doing anything.
 *
 *  3. SEO METADATA — filters `linguaforge_meta_description_post` to return
 *     the AI-generated excerpt as the SEO meta description for artwork posts,
 *     and provides Open Graph image data from the gallery attachment.
 *
 *  4. ARTWORK SCHEMA — hooks into LF's Schema.org output to annotate
 *     artwork posts as `VisualArtwork` rather than generic `Article`.
 *
 * When Lingua Forge is NOT active, this class does nothing — all hooks are
 * registered conditionally. No hard dependency.
 *
 * @package Agnosis\Compat
 */

declare(strict_types=1);

namespace Agnosis\Compat;

class LinguaForge {

	/** Option key LF uses to read the list of active site languages. */
	private const LF_LANGUAGES_OPTION = 'linguaforge_active_languages';

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	public function __construct() {
		if ( ! $this->is_active() ) {
			return;
		}

		// Language meta.
		add_action( 'agnosis_post_published',        [ $this, 'set_language_meta'     ], 10, 1 );

		// Translation pipeline.
		add_action( 'agnosis_post_published',        [ $this, 'request_translations'  ], 20, 1 );

		// SEO: meta description.
		add_filter( 'linguaforge_meta_description_post', [ $this, 'filter_meta_description' ], 10, 2 );

		// SEO: Open Graph image override for artwork posts.
		add_filter( 'linguaforge_og_image',          [ $this, 'filter_og_image'       ], 10, 2 );

		// Schema.org type override.
		add_filter( 'linguaforge_schema_type',       [ $this, 'filter_schema_type'    ], 10, 2 );

		// Register Agnosis text domain with LF's i18n override system so
		// translators can ship .mo files via LF's uploads-based overrides dir.
		add_filter( 'linguaforge_managed_textdomains', [ $this, 'register_textdomain' ] );
	}

	// -------------------------------------------------------------------------
	// Language meta
	// -------------------------------------------------------------------------

	/**
	 * Tag the artwork post with the source language.
	 *
	 * LF reads `_lang` on every post to determine which language it belongs to.
	 * We detect the language from the email submission metadata; if unknown, we
	 * fall back to the site's default locale.
	 */
	public function set_language_meta( int $post_id ): void {
		if ( get_post_type( $post_id ) !== 'agnosis_artwork' ) {
			return;
		}

		// Prefer a language tag already stored during processing.
		$lang = get_post_meta( $post_id, '_agnosis_detected_lang', true );

		if ( empty( $lang ) ) {
			// Fall back to site locale (e.g. "en", "de", "es").
			$lang = $this->locale_to_lang( get_locale() );
		}

		update_post_meta( $post_id, '_lang', sanitize_text_field( $lang ) );
	}

	// -------------------------------------------------------------------------
	// Translation pipeline
	// -------------------------------------------------------------------------

	/**
	 * Ask Lingua Forge to translate the artwork into all configured languages.
	 *
	 * LF listens on `linguaforge_request_translation` and enqueues an async
	 * translation job. The artwork post stays published in the source language
	 * while LF creates translated versions in the background.
	 */
	public function request_translations( int $post_id ): void {
		if ( get_post_type( $post_id ) !== 'agnosis_artwork' ) {
			return;
		}

		$source_lang = get_post_meta( $post_id, '_lang', true ) ?: 'en';
		$languages   = $this->get_target_languages( $source_lang );

		if ( empty( $languages ) ) {
			return;
		}

		/**
		 * Fires when Agnosis wants Lingua Forge to translate a post.
		 *
		 * @param int    $post_id     The artwork post ID.
		 * @param string $source_lang BCP-47 language tag of the source content.
		 * @param array  $languages   Target language tags to translate into.
		 * @param array  $context     Contextual hints for the translation model.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally calling a Lingua Forge hook; the prefix belongs to that plugin.
		do_action(
			'linguaforge_request_translation',
			$post_id,
			$source_lang,
			$languages,
			[
				'domain'   => 'art',       // Hint: use the art/creative translation preset.
				'priority' => 'normal',
				'source'   => 'agnosis',
			]
		);
	}

	// -------------------------------------------------------------------------
	// SEO filters
	// -------------------------------------------------------------------------

	/**
	 * Return the AI-generated excerpt as the SEO meta description for artwork posts.
	 *
	 * @param string   $description  Current meta description candidate.
	 * @param \WP_Post $post         The post being described.
	 * @return string
	 */
	public function filter_meta_description( string $description, \WP_Post $post ): string {
		if ( $post->post_type !== 'agnosis_artwork' ) {
			return $description;
		}

		$excerpt = trim( $post->post_excerpt );
		return ! empty( $excerpt ) ? $excerpt : $description;
	}

	/**
	 * Supply the featured artwork image as the Open Graph image.
	 *
	 * @param string   $image_url  Current OG image URL.
	 * @param \WP_Post $post       The post being described.
	 * @return string
	 */
	public function filter_og_image( string $image_url, \WP_Post $post ): string {
		if ( $post->post_type !== 'agnosis_artwork' ) {
			return $image_url;
		}

		$thumbnail = get_the_post_thumbnail_url( $post->ID, 'large' );
		return $thumbnail ?: $image_url;
	}

	/**
	 * Override Schema.org type to `VisualArtwork` for artwork posts.
	 *
	 * @param string   $type  Current schema type (e.g. 'Article').
	 * @param \WP_Post $post  The post.
	 * @return string
	 */
	public function filter_schema_type( string $type, \WP_Post $post ): string {
		if ( $post->post_type !== 'agnosis_artwork' ) {
			return $type;
		}

		return 'VisualArtwork';
	}

	/**
	 * Tell Lingua Forge to manage translations for the 'agnosis' text domain.
	 *
	 * @param array $domains  Current list of managed text domains.
	 * @return array
	 */
	/**
	 * @param array<string> $domains
	 * @return array<string>
	 */
	public function register_textdomain( array $domains ): array {
		$domains[] = 'agnosis';
		return $domains;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Returns true when Lingua Forge is loaded and functional. */
	public static function is_active(): bool {
		return defined( 'LINGUAFORGE_FILE' ) && defined( 'LINGUAFORGE_VERSION' );
	}

	/**
	 * Return the target language list: all LF-configured languages minus the source.
	 *
	 * @param string $source_lang  BCP-47 tag to exclude.
	 * @return string[]
	 */
	private function get_target_languages( string $source_lang ): array {
		$all = get_option( self::LF_LANGUAGES_OPTION, [] );

		if ( ! is_array( $all ) || empty( $all ) ) {
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
	 */
	private function locale_to_lang( string $locale ): string {
		// LF typically uses two-letter primary subtags ("en", "de", "es", "fr"…).
		return strtolower( explode( '_', $locale )[0] );
	}
}
