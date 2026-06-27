<?php
/**
 * Subdomain Router — artist-scoped subdomain routing.
 *
 * Maps wildcard subdomains to individual artist users so that
 * artistx.agnosis.art serves only that artist's content.
 *
 * Architecture
 * ────────────
 * • DNS: wildcard *.{base_domain} → same WP install.
 * • This class detects the subdomain on `plugins_loaded`, resolves it to a
 *   WP user (nicename preferred, login as fallback), and wires query + URL
 *   filters for the rest of the request.
 * • `option_home` is rewritten to the artist subdomain so every
 *   WP-generated URL (pagination, canonical, feeds, REST links…) is correct.
 * • `pre_get_posts` scopes the main query to the artist's posts only.
 *
 * URL structure
 * ─────────────
 * artistx.agnosis.art            → artist's content, default language
 * artistx.agnosis.art/en/        → English (LinguaForge subfolder mode)
 * artistx.agnosis.art/ca/        → Catalan
 * agnosis.art                    → portal / directory (no routing applied)
 *
 * @package Agnosis\Network
 */

declare(strict_types=1);

namespace Agnosis\Network;

class SubdomainRouter {

	/**
	 * Resolved artist user ID for the current request, or null on the main domain.
	 *
	 * @var integer|null
	 */
	private static ?int $artist_id = null;

	/**
	 * Subdomain slug used in the URL (e.g. "artistx").
	 *
	 * @var string
	 */
	private static string $slug = '';

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/**
	 * Detect the artist subdomain and register all routing hooks.
	 *
	 * Must be called on `plugins_loaded` (priority ≤ 10) so that the
	 * `option_home` filter is in place before `init` runs and WP builds
	 * its internal URL tables.
	 *
	 * Bails silently when LinguaForge is active and configured for subdomain
	 * routing ('subdomain') — both systems would claim the same subdomain
	 * namespace. LinguaForge's default is path/subfolder mode ('path'), which
	 * leaves subdomains free for artist routing.
	 */
	public function boot(): void {
		// Guard: LinguaForge subdomain mode occupies the same namespace.
		if (
			defined( 'LINGUAFORGE_VERSION' ) &&
			'subdomain' === (string) get_option( 'linguaforge_routing_mode', 'path' )
		) {
			return;
		}

		$host = strtolower( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) ) );
		// Strip port if present (e.g. localhost:8080).
		$host = (string) preg_replace( '/:\d+$/', '', $host );

		$base = strtolower( trim( (string) get_option( 'agnosis_base_domain', '' ) ) );

		if ( ! $base || $host === $base || ! str_ends_with( $host, '.' . $base ) ) {
			return; // Main domain or base domain not configured.
		}

		$subdomain = substr( $host, 0, strlen( $host ) - strlen( '.' . $base ) );

		// Reject multi-level subdomains (e.g. sub.artist.agnosis.art).
		if ( str_contains( $subdomain, '.' ) ) {
			return;
		}

		// Resolve to a WP user: nicename (URL-friendly) first, login as fallback.
		$user = get_user_by( 'slug', $subdomain )
			?: get_user_by( 'login', $subdomain );

		if ( ! $user ) {
			return;
		}

		self::$artist_id = $user->ID;
		self::$slug      = $subdomain;

		// Rewrite home URL → artist subdomain for all WP URL generation.
		add_filter( 'option_home',          [ $this, 'rewrite_home'        ] );

		// Scope main query to this artist's content.
		add_action( 'pre_get_posts',        [ $this, 'scope_query'         ] );

		// Page title: replace site name with the artist's display name.
		add_filter( 'document_title_parts', [ $this, 'filter_title_parts'  ] );
		add_filter( 'wp_title',             [ $this, 'filter_wp_title'     ], 10, 1 );
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Rewrite the `home` option to the artist's subdomain URL.
	 *
	 * This is the single source of truth that makes WP generate correct
	 * links, canonical tags, feeds, REST API base URLs, and pagination.
	 */
	public function rewrite_home( string $url ): string {
		$base   = (string) get_option( 'agnosis_base_domain', '' );
		$scheme = is_ssl() ? 'https' : 'http';
		return $scheme . '://' . self::$slug . '.' . $base;
	}

	/**
	 * Restrict the main front-end query to the current artist's posts.
	 *
	 * Only affects the main query on the front end; admin and secondary
	 * queries (nav menus, widget queries, etc.) are untouched.
	 */
	public function scope_query( \WP_Query $q ): void {
		if ( ! $q->is_main_query() || is_admin() ) {
			return;
		}

		// Pin every query to this artist.
		$q->set( 'author', self::$artist_id );

		// If no post type is already specified, show all Agnosis CPTs.
		if ( ! $q->get( 'post_type' ) ) {
			$q->set( 'post_type', [ 'agnosis_artwork', 'agnosis_biography', 'agnosis_event' ] );
		}
	}

	/**
	 * Replace the site name in the document title with the artist's display name.
	 *
	 * @param array<string, string> $parts
	 * @return array<string, string>
	 */
	public function filter_title_parts( array $parts ): array {
		$user = get_user_by( 'id', self::$artist_id );
		if ( $user ) {
			$parts['site'] = $user->display_name ?: self::$slug;
		}
		return $parts;
	}

	/** Legacy `wp_title` filter for themes that don't use `document_title_parts`. */
	public function filter_wp_title( string $title ): string {
		$user = get_user_by( 'id', self::$artist_id );
		$name = $user ? ( $user->display_name ?: self::$slug ) : self::$slug;
		return $title ? $title . ' — ' . $name : $name;
	}

	// -------------------------------------------------------------------------
	// Static accessors — used by LinguaForge compat and other classes
	// -------------------------------------------------------------------------

	/** Returns the WP user ID of the current artist, or null on the main domain. */
	public static function current_artist_id(): ?int {
		return self::$artist_id;
	}

	/** True when the current request is on an artist subdomain. */
	public static function is_artist_subdomain(): bool {
		return null !== self::$artist_id;
	}

	/**
	 * Build the subdomain URL for a given artist user.
	 *
	 * Prefers `user_nicename` (already URL-safe) over `user_login`.
	 * Falls back to `home_url()` when no base domain is configured.
	 */
	public static function url_for_artist( int $user_id ): string {
		$base = (string) get_option( 'agnosis_base_domain', '' );
		if ( ! $base ) {
			return home_url();
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return home_url();
		}

		$slug   = $user->user_nicename ?: $user->user_login;
		$scheme = is_ssl() ? 'https' : 'http';
		return $scheme . '://' . $slug . '.' . $base;
	}
}
