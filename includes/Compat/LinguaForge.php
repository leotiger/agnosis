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
 * When Lingua Forge is NOT active, this class does nothing — all hooks are
 * registered conditionally. No hard dependency.
 *
 * @package Agnosis\Compat
 */

declare(strict_types=1);

namespace Agnosis\Compat;

class LinguaForge {

	// LF does not expose active languages via a WP option — the canonical API
	// is the linguaforge_languages() global function (language-router module).

	/** All Agnosis CPT slugs — used to scope LF integrations to our content. */
	private const AGNOSIS_POST_TYPES = [
		'agnosis_artwork',
		'agnosis_biography',
		'agnosis_event',
	];

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

		// Language meta.
		add_action( 'agnosis_post_published',        [ $this, 'set_language_meta'     ], 10, 1 );

		// Translation pipeline.
		add_action( 'agnosis_post_published',        [ $this, 'request_translations'  ], 20, 1 );

		// SEO: Open Graph image override for artwork posts.
		add_filter( 'linguaforge_seo_og_image',      [ $this, 'filter_og_image'       ], 10, 1 );

		// Schema.org type override.
		add_filter( 'linguaforge_seo_schema_data',   [ $this, 'filter_schema_type'    ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Language meta
	// -------------------------------------------------------------------------

	/**
	 * Tag the artwork post with the source language.
	 *
	 * LF reads `_lf_lang` on every post to determine which language it belongs to
	 * (used by the router, hreflang output, and Translation::run() for the source
	 * language context). We detect the language from the email submission metadata;
	 * if unknown, we fall back to the site's default locale.
	 */
	public function set_language_meta( int $post_id ): void {
		if ( ! in_array( get_post_type( $post_id ), self::AGNOSIS_POST_TYPES, true ) ) {
			return;
		}

		// Prefer a language tag already stored during processing.
		$lang = get_post_meta( $post_id, '_agnosis_detected_lang', true );

		if ( empty( $lang ) ) {
			// Fall back to site locale (e.g. "en", "de", "es").
			$lang = $this->locale_to_lang( get_locale() );
		}

		update_post_meta( $post_id, '_lf_lang', sanitize_text_field( $lang ) );
	}

	// -------------------------------------------------------------------------
	// Translation pipeline
	// -------------------------------------------------------------------------

	/**
	 * Ask Lingua Forge to translate the artwork into all configured languages.
	 *
	 * Calls `linguaforge_trigger_translation()` once per target language — LF's
	 * public procedural API (defined in ai/ai.php). Each call enqueues an async
	 * translation job; the artwork post stays published in the source language
	 * while LF creates translated versions in the background.
	 *
	 * Returns silently when LF is not loaded or there are no target languages.
	 */
	public function request_translations( int $post_id ): void {
		if ( ! in_array( get_post_type( $post_id ), self::AGNOSIS_POST_TYPES, true ) ) {
			return;
		}

		if ( ! function_exists( 'linguaforge_trigger_translation' ) ) {
			return;
		}

		$source_lang = get_post_meta( $post_id, '_lf_lang', true ) ?: 'en';
		$languages   = $this->get_target_languages( $source_lang );

		if ( empty( $languages ) ) {
			return;
		}

		$params = [
			'domain'   => 'art',    // Use the art/creative translation preset.
			'priority' => 'normal',
			'source'   => 'agnosis',
		];

		foreach ( $languages as $target_lang ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling Lingua Forge's public API; prefix belongs to that plugin.
			linguaforge_trigger_translation( $post_id, $target_lang, $params );
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
	 */
	private function locale_to_lang( string $locale ): string {
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
