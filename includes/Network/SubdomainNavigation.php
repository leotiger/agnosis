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

		return sprintf(
			'<div %s><a href="%s">%s</a></div>',
			$wrapper_attributes,
			esc_url( $url ),
			esc_html( $name )
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
