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
	 * the name is never mixed in with anything else. The theme lays the two
	 * groups out on opposite sides (name on the reading-start side, links on
	 * the reading-end side — i.e. left/right, or the reverse on RTL) via
	 * `.agnosis-artist-breadcrumb`'s flex layout.
	 *
	 * 2026-07-10: Biography/Events used to render as translated text links
	 * ("Biography" / "Events", pipe-separated from each other when both were
	 * present). On mobile, some translations of those two words together
	 * were wide enough to overflow or wrap awkwardly next to the artist's
	 * name. Both are now icon-only links — a fixed-width glyph regardless of
	 * locale — with the translated word moved to `aria-label`/`title`
	 * (screen readers and hover tooltips still get it; sighted mobile
	 * visitors get a compact, self-explanatory icon). Same
	 * stroke="currentColor" 24×24-viewBox convention `PopoverBlock::ICONS`
	 * already established for this plugin's icon buttons. No separator
	 * needed between the two any more — `.agnosis-artist-breadcrumb__links`'s
	 * flex `gap` spaces them evenly whether one or both are present.
	 *
	 * Icon choice (`biographyIcon`/`eventsIcon`), size (`iconSize`), and color
	 * (`iconColor`) are editable per-instance from the block's own Inspector
	 * panel (block.json "attributes" — a plain per-instance attribute here,
	 * not a block *support*, since there's no built-in support for "recolor
	 * just this inner element" the way there is for the whole block's text
	 * color). `iconColor` defaults to '' (unset), in which case the icons
	 * keep inheriting `currentColor` from the block's own text-color support
	 * exactly as before — setting it only overrides that for the icons
	 * specifically, leaving the artist name's color untouched.
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

		$icon_size    = max( 12, (int) ( $attributes['iconSize'] ?? 18 ) );
		$icon_color   = sanitize_hex_color( (string) ( $attributes['iconColor'] ?? '' ) ) ?: '';
		$bio_icon     = (string) ( $attributes['biographyIcon'] ?? 'book' );
		$events_icon  = (string) ( $attributes['eventsIcon'] ?? 'calendar' );

		$secondary_links = [];

		$bio_url = $this->biography_permalink( $artist_id );
		if ( '' !== $bio_url ) {
			$secondary_links[] = $this->icon_link( $bio_url, 'biography', $bio_icon, __( 'Biography', 'agnosis' ), $icon_size );
		}

		if ( $this->has_published_post( 'agnosis_event', $artist_id ) ) {
			$events_url = (string) get_post_type_archive_link( 'agnosis_event' );
			if ( '' !== $events_url ) {
				$secondary_links[] = $this->icon_link( $events_url, 'events', $events_icon, __( 'Events', 'agnosis' ), $icon_size );
			}
		}

		if ( $secondary_links ) {
			$links_style = '' !== $icon_color ? sprintf( ' style="color:%s"', esc_attr( $icon_color ) ) : '';
			$markup     .= sprintf(
				'<span class="agnosis-artist-breadcrumb__links"%s>%s</span>',
				$links_style,
				implode( '', $secondary_links )
			);
		}

		return sprintf( '<div %s>%s</div>', $wrapper_attributes, $markup );
	}

	/**
	 * Icon-only stroke SVGs for the breadcrumb's Biography/Events links — a
	 * couple of variants per link, selectable from the block's Inspector
	 * panel (`biographyIcon`/`eventsIcon` attributes). Same 24×24-viewBox,
	 * stroke="currentColor" Feather-style convention as
	 * `Newsletter\PopoverBlock::ICONS`. Raw, hand-authored markup — never
	 * user input. Kept in sync by hand with editor.js's `ICON_MARKUP` (same
	 * "vanilla JS, no build step" tradeoff `PopoverBlock`'s own icon picker
	 * already made).
	 *
	 * @var array<string, array<string, string>>
	 */
	private const LINK_ICON_SETS = [
		'biography' => [
			'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>',
			'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
		],
		'events' => [
			'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
			'pin'      => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle>',
		],
	];

	/**
	 * Build one icon-only breadcrumb link. The translated word never appears
	 * as visible text — it's the link's `aria-label` (for screen readers)
	 * and `title` (for a mouse-hover tooltip on desktop) — see render_block()'s
	 * docblock for why.
	 */
	private function icon_link( string $url, string $link_type, string $icon_key, string $label, int $size ): string {
		$set  = self::LINK_ICON_SETS[ $link_type ] ?? [];
		$path = $set[ $icon_key ] ?? reset( $set ) ?: '';

		return sprintf(
			'<a href="%1$s" aria-label="%2$s" title="%2$s"><svg width="%3$d" height="%3$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">%4$s</svg></a>',
			esc_url( $url ),
			esc_attr( $label ),
			$size,
			$path
		);
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
