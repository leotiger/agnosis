<?php
/**
 * Subdomain navigation aids — artist-breadcrumb block, and the theme-facing
 * link fix that lets a visitor get back to the main Agnosis site from an
 * artist subdomain.
 *
 * Both concerns exist because SubdomainRouter rewrites `option_home` to the
 * artist's own subdomain for the whole request: `wp:site-logo` and
 * `wp:site-title` (or anything else built from `home_url()`) end up linking
 * to the artist's own page, not the portal, and nothing else on the page
 * otherwise identifies which artist a visitor is looking at (see
 * SubdomainRouter's `option_blogname` note).
 *
 * This lives in the plugin — not in a specific theme's functions.php — so
 * that any theme built against Agnosis gets both behaviours automatically
 * just by using core's Site Logo / Site Title blocks and, optionally,
 * inserting the `agnosis/artist-breadcrumb` block wherever it wants the
 * artist identified.
 *
 * @package Agnosis\Network
 */

declare(strict_types=1);

namespace Agnosis\Network;

use Agnosis\Compat\LinguaForge;

class SubdomainNavigation {

	// -------------------------------------------------------------------------
	// Block: agnosis/artist-breadcrumb
	// -------------------------------------------------------------------------

	/**
	 * Register the agnosis/artist-breadcrumb dynamic block.
	 *
	 * block.json lives in blocks/artist-breadcrumb/ relative to the plugin root.
	 */
	public function register_block(): void {
		register_block_type(
			\AGNOSIS_DIR . 'blocks/artist-breadcrumb',
			[ 'render_callback' => [ $this, 'render_block' ] ]
		);
	}

	/**
	 * PHP render_callback for the agnosis/artist-breadcrumb block.
	 *
	 * Renders nothing at all — not an empty element — off an artist subdomain,
	 * so themes can drop it into a shared template part without an extra
	 * conditional and never show a stray blank line on the main site.
	 *
	 * Text/background color and font size are editable per-instance via the
	 * block's own Color/Typography inspector panels (block.json "supports"):
	 * get_block_wrapper_attributes() turns whatever the editor picked into the
	 * matching class(es)/inline style on the wrapper. Nothing picked in the
	 * editor falls back to the theme's own `.agnosis-artist-breadcrumb` CSS.
	 *
	 * The artist's name links to their own subdomain home
	 * (`SubdomainRouter::url_for_artist()`) — the breadcrumb then doubles as a
	 * "back to the artist's home" link from any other page on that subdomain
	 * (an artwork, biography, or event single), not just an identifying label.
	 *
	 * The name and the "Biography"/"Events" links are two separate groups —
	 * the name is never pipe-separated from anything else. The theme lays
	 * the two groups out on opposite sides (name on the reading-start side,
	 * links on the reading-end side — i.e. left/right, or the reverse on
	 * RTL) via `.agnosis-artist-breadcrumb`'s flex layout. Within the links
	 * group, "Biography" and "Events" are pipe-separated from *each other*,
	 * but only when both are actually present — no dangling link to an empty
	 * page for an artist who's never submitted a bio or event, and no lone
	 * leading/trailing pipe when only one of the two exists.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( array $attributes = [] ): string {
		$artist_id = SubdomainRouter::current_artist_id();

		if ( ! $artist_id ) {
			return '';
		}

		$name = $this->artist_name( $artist_id );

		if ( '' === $name ) {
			return '';
		}

		$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'agnosis-artist-breadcrumb' ] );
		$url                = SubdomainRouter::url_for_artist( $artist_id );

		$name_link = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $name ) );
		$markup    = sprintf( '<span class="agnosis-artist-breadcrumb__name">%s</span>', $name_link );

		$secondary_links = [];

		$bio_url = $this->biography_permalink( $artist_id );
		if ( '' !== $bio_url ) {
			$secondary_links[] = sprintf( '<a href="%s">%s</a>', esc_url( $bio_url ), esc_html__( 'Biography', 'agnosis' ) );
		}

		if ( $this->has_published_post( 'agnosis_event', $artist_id ) ) {
			$events_url = (string) get_post_type_archive_link( 'agnosis_event' );
			if ( '' !== $events_url ) {
				$secondary_links[] = sprintf( '<a href="%s">%s</a>', esc_url( $events_url ), esc_html__( 'Events', 'agnosis' ) );
			}
		}

		if ( $secondary_links ) {
			$markup .= sprintf(
				'<span class="agnosis-artist-breadcrumb__links">%s</span>',
				implode( ' | ', $secondary_links )
			);
		}

		return sprintf( '<div %s>%s</div>', $wrapper_attributes, $markup );
	}

	// -------------------------------------------------------------------------
	// Site Logo / Site Title → back to the main site
	// -------------------------------------------------------------------------

	/**
	 * On an artist subdomain, point the Site Logo and Site Title links at the
	 * main Agnosis site instead of the artist's own home.
	 *
	 * Both blocks link to home_url() by default, which
	 * SubdomainRouter::rewrite_home() rewrites to the current artist subdomain
	 * for every request — so without this, there is no way back to the portal
	 * from an artist's page. Each block renders exactly one link, so replacing
	 * the first `href` found is enough.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @return string
	 */
	public function link_to_portal( string $block_content ): string {
		if ( ! SubdomainRouter::is_artist_subdomain() ) {
			return $block_content;
		}

		return (string) preg_replace(
			'/href="[^"]*"/',
			'href="' . esc_url( $this->portal_home_url() ) . '"',
			$block_content,
			1
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Display name for a given artist user ID, or '' if the user can't be found. */
	private function artist_name( int $artist_id ): string {
		$user = get_user_by( 'id', $artist_id );

		return $user ? (string) ( $user->display_name ?: $user->user_nicename ) : '';
	}

	/**
	 * Permalink of the artist's biography post, localized to the CURRENT
	 * request's language, or '' if they don't have one (yet).
	 *
	 * Biography is "singleton" only in the sense that intake never creates a
	 * second SOURCE-language post — but Lingua Forge translation fan-out
	 * still creates one additional `agnosis_biography` post per translated
	 * language once publish happens, so an artist can have several published
	 * biography posts. Without scoping to the source language first,
	 * `get_posts()` could return ANY of those siblings (ordered by date, not
	 * language) — which is exactly why this link used to always point at
	 * whichever translation happened to be created last, regardless of which
	 * language the visitor was actually reading. `localized_post()` then
	 * swaps in the visitor's own language's sibling when one exists,
	 * mirroring `Newsletter\Digest::localized_post()`'s exact fallback chain.
	 */
	private function biography_permalink( int $artist_id ): string {
		$query_args = [
			'post_type'      => 'agnosis_biography',
			'author'         => $artist_id,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Lingua Forge's public API; prefix belongs to that plugin.
		if ( function_exists( 'linguaforge_source_language' ) ) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single, cheap lookup, once per artist-breadcrumb render.
				'relation' => 'OR',
				[ 'key' => '_lf_lang', 'value' => linguaforge_source_language() ],
				[ 'key' => '_lf_lang', 'compare' => 'NOT EXISTS' ],
			];
		}

		$ids = get_posts( $query_args );
		if ( ! $ids ) {
			return '';
		}

		$post = get_post( $ids[0] );

		return $post instanceof \WP_Post ? (string) get_permalink( $this->localized_post( $post ) ) : '';
	}

	/**
	 * Resolve a post to its published translated counterpart in the CURRENT
	 * request's language (`LF_LANG`), or the post itself when Lingua Forge
	 * isn't active, the current language IS the source language, or no
	 * published translation exists yet.
	 */
	private function localized_post( \WP_Post $post ): \WP_Post {
		if ( ! defined( 'LF_LANG' ) || ! LF_LANG || ! function_exists( 'linguaforge_get_translations' ) ) {
			return $post;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Lingua Forge's public API; prefix belongs to that plugin.
		$source = function_exists( 'linguaforge_source_language' ) ? linguaforge_source_language() : '';
		if ( LF_LANG === $source ) {
			return $post; // Already the source-language post — nothing to look up.
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Lingua Forge's public API; prefix belongs to that plugin.
		$translations  = linguaforge_get_translations( $post->ID );
		$translated_id = (int) ( $translations[ LF_LANG ] ?? 0 );
		if ( $translated_id <= 0 ) {
			return $post; // No translation into the visitor's language yet.
		}

		$translated = get_post( $translated_id );

		return ( $translated instanceof \WP_Post && 'publish' === $translated->post_status ) ? $translated : $post;
	}

	/** True when the artist has at least one published post of the given type. */
	private function has_published_post( string $post_type, int $artist_id ): bool {
		$ids = get_posts( [
			'post_type'      => $post_type,
			'author'         => $artist_id,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		return ! empty( $ids );
	}

	/**
	 * The main Agnosis site's URL, on the visitor's current language.
	 *
	 * Same reasoning as `SubdomainRouter::url_for_artist()`: a visitor reading
	 * `ourartist.agnosis.art/fr/...` who clicks back to the portal should land
	 * on the portal's `/fr/` home, not always its source-language root.
	 */
	private function portal_home_url(): string {
		$base = (string) get_option( 'agnosis_base_domain', '' );

		if ( ! $base ) {
			return home_url();
		}

		$scheme = is_ssl() ? 'https' : 'http';
		$prefix = LinguaForge::current_lang_path_prefix();

		// Trailing slash only when a language prefix is actually appended — see
		// the matching comment in SubdomainRouter::url_for_artist().
		return $scheme . '://' . $base . $prefix . ( '' !== $prefix ? '/' : '' );
	}
}
