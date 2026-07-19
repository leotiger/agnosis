<?php
/**
 * Gallery overview block — server-side render.
 *
 * Proportional, randomised artwork grid across all artists.
 *
 * Logic:
 *   1. Collect all distinct artwork authors (or, on an artist's own subdomain,
 *      just that one artist — see "Artist pool" below).
 *   2. Per-artist budget = ceil( pool_size / artist_count ), min 1.
 *   3. Fill each artist's slot: on the shared main gallery, featured post
 *      first (_agnosis_featured=1), then most-recent posts to exhaust the
 *      budget. On an artist's own subdomain, promote@ doesn't apply — see
 *      "Featured artwork first" below — so this is just most-recent-first.
 *   4. Shuffle the assembled pool using a day-based seed so pagination is
 *      stable within a calendar day but the order renews each morning.
 *   5. Apply medium filter and paginate at agnosis_gallery_per_page items/page.
 *
 * URL query vars consumed:
 *   agnosis_medium        — taxonomy slug to filter by (optional)
 *   agnosis_overview_page — current page number (optional, default 1)
 *
 * @package Agnosis\Blocks\GalleryOverview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Settings ────────────────────────────────────────────────────────────────
$agnosis_per_page    = max( 1, (int) get_option( 'agnosis_gallery_per_page', 12 ) );
$agnosis_columns     = max( 1, (int) ( $attributes['columns'] ?? 3 ) );
$agnosis_pool_target = max( $agnosis_per_page * 4, 60 );

// ── URL inputs ──────────────────────────────────────────────────────────────
// Deliberately NOT named "agnosis_medium" — that string is also the
// agnosis_medium taxonomy's own registered query_var (Profile::register_
// taxonomy(), 'query_var' => true, 'rewrite' => ['slug' => 'medium']), which
// exists for wp-admin/REST, not for this block. Reusing the same name here
// collided with it: WordPress's redirect_canonical() recognizes any
// `?agnosis_medium=X` request as a request for that taxonomy's real archive
// and silently 301-redirects it to the pretty-permalink URL /medium/x/ — a
// totally different page/template with no gallery-overview block on it at
// all. Confirmed live: clicking a medium pill (or the Interactivity Router
// fetching that same URL) landed on WordPress's own generic taxonomy archive
// instead of updating this block in place — not a data or caching bug, a
// straight query-var name collision. "agnosis_medium_filter" carries no
// taxonomy meaning to WordPress, so it can never trigger that redirect.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter/page, no state change.
$agnosis_medium_filter = isset( $_GET['agnosis_medium_filter'] )
	? sanitize_key( wp_unslash( $_GET['agnosis_medium_filter'] ) )
	: '';
$agnosis_current_page  = isset( $_GET['agnosis_overview_page'] )
	? max( 1, (int) $_GET['agnosis_overview_page'] )
	: 1;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

// ── Language filter ──────────────────────────────────────────────────────────
// Every artwork post is tagged with `_lf_lang` at publish time (see
// Compat\LinguaForge::set_language_meta() — runs unconditionally, including for
// untranslated originals, so this is never empty/missing on a real post) once
// Lingua Forge is active. Applied explicitly here, on every query this block
// runs, rather than relying on LF's own pre_get_posts filtering of secondary
// queries to catch it incidentally: that produced exactly the bug this fixes
// (pagination computed from an unfiltered pool while the final page fetch was
// silently language-filtered out from under it, so translations counted
// toward page totals but never actually rendered). LF_LANG reflects the
// CURRENT request's resolved language (e.g. 'es' on /es/), not the site's
// configured primary language.
$agnosis_lang_meta_query = ( \Agnosis\Compat\LinguaForge::is_active() && defined( 'LF_LANG' ) && LF_LANG )
	? [ [ 'key' => '_lf_lang', 'value' => LF_LANG ] ]
	: [];
// Note: the distinct-authors query just below is intentionally NOT
// language-scoped — it only discovers which artists have ANY published
// artwork, in any language, so every artist is considered for this page's
// pool. An artist with no artwork yet translated into the current language
// simply contributes zero posts once the per-artist queries below apply
// $agnosis_lang_meta_query — a thinner pool for that language rather than
// wrong-language content, which is the safe default until translations catch up.

// ── Artist pool ─────────────────────────────────────────────────────────────
// On an artist's own subdomain (artistx.agnosis.art) this block is that
// artist's personal gallery, not the cross-artist portal directory — it must
// show ONLY their own work. This block never touches the main WP_Query (it
// builds its own pool via independent get_posts()/wpdb calls below), so
// SubdomainRouter::scope_query()'s pre_get_posts filtering — which only
// touches is_main_query() — never applies here on its own; it has to be
// checked explicitly. The per-artist pool-building logic just below already
// degenerates correctly to "just this artist's own artworks" when the
// artist-ID pool contains exactly one ID, so no separate rendering path is
// needed — just skip the sitewide "every artist who has ever published"
// discovery query in that case.
$agnosis_subdomain_artist_id = ( class_exists( '\Agnosis\Network\SubdomainRouter' ) && \Agnosis\Network\SubdomainRouter::is_artist_subdomain() )
	? \Agnosis\Network\SubdomainRouter::current_artist_id()
	: null;

if ( null !== $agnosis_subdomain_artist_id ) {
	$agnosis_artist_ids = [ $agnosis_subdomain_artist_id ];
} else {
	$agnosis_cache_key   = 'agnosis_gallery_artist_ids';
	$agnosis_cache_group = 'agnosis_gallery';
	$agnosis_artist_ids  = wp_cache_get( $agnosis_cache_key, $agnosis_cache_group );

	if ( false === $agnosis_artist_ids ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result cached via wp_cache_set() immediately below.
		$agnosis_artist_ids = $wpdb->get_col(
			"SELECT DISTINCT post_author FROM {$wpdb->posts}
			 WHERE post_type = 'agnosis_artwork' AND post_status = 'publish'"
		);
		wp_cache_set( $agnosis_cache_key, $agnosis_artist_ids, $agnosis_cache_group, 5 * MINUTE_IN_SECONDS );
	}
}

if ( empty( $agnosis_artist_ids ) ) {
	return;
}

$agnosis_artist_count = count( $agnosis_artist_ids );
$agnosis_per_artist   = max( 1, (int) ceil( $agnosis_pool_target / $agnosis_artist_count ) );

// ── Medium filter tax_query ──────────────────────────────────────────────────
$agnosis_tax_query = $agnosis_medium_filter ? [
	[
		'taxonomy' => 'agnosis_medium',
		'field'    => 'slug',
		'terms'    => $agnosis_medium_filter,
	],
] : [];

// ── Build pool ───────────────────────────────────────────────────────────────
$agnosis_pool = [];

foreach ( $agnosis_artist_ids as $agnosis_raw_id ) {
	$agnosis_artist_id = (int) $agnosis_raw_id;

	// Featured artwork first — only meaningful on the shared main gallery. There,
	// $agnosis_per_artist can be smaller than an artist's total published output,
	// so pulling the featured piece first guarantees it survives the per-artist
	// budget cut below rather than possibly getting crowded out by more-recent
	// work. On an artist's own subdomain $agnosis_per_artist already equals
	// $agnosis_pool_target (one "artist" in the whole pool — see the ID-collection
	// branch above), so every one of that artist's own artworks is included
	// regardless of this query; and the day-seeded shuffle a few lines down
	// reorders the entire pool by post ID anyway, so "first" here wouldn't even
	// survive as a visible position. Skipping it on subdomain avoids a wasted
	// query and — more importantly — an artist's own single-artist gallery is
	// not a curatorial context promote@ was designed for; see the ✦ badge
	// suppression further down for the other half of this.
	$agnosis_featured = ( null === $agnosis_subdomain_artist_id ) ? get_posts( [
		'post_type'      => 'agnosis_artwork',
		'post_status'    => 'publish',
		'author'         => $agnosis_artist_id,
		'posts_per_page' => 1,
		'meta_query'     => array_merge(
			[ [ 'key' => '_agnosis_featured', 'value' => '1' ] ],
			$agnosis_lang_meta_query
		),
		'tax_query'      => $agnosis_tax_query,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	] ) : [];

	$agnosis_exclude   = $agnosis_featured;
	$agnosis_remaining = $agnosis_per_artist - count( $agnosis_featured );

	// Fill remainder with most-recent non-featured artworks.
	if ( $agnosis_remaining > 0 ) {
		$agnosis_extra = get_posts( [
			'post_type'      => 'agnosis_artwork',
			'post_status'    => 'publish',
			'author'         => $agnosis_artist_id,
			'posts_per_page' => $agnosis_remaining,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'exclude'        => $agnosis_exclude,
			'meta_query'     => $agnosis_lang_meta_query,
			'tax_query'      => $agnosis_tax_query,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		$agnosis_featured = array_merge( $agnosis_featured, $agnosis_extra );
	}

	$agnosis_pool = array_merge( $agnosis_pool, array_map( 'intval', $agnosis_featured ) );
}

// ── Day-seeded deterministic shuffle ─────────────────────────────────────────
// usort with a crc32-based key gives a stable per-day order without touching
// PHP's PRNG state — avoids srand() which is discouraged by WPCS.
// Incorporates medium filter so filter pages don't mirror unfiltered order.
$agnosis_day_seed = (int) gmdate( 'Ymd' ) + (int) crc32( $agnosis_medium_filter );
usort( $agnosis_pool, static function ( int $a, int $b ) use ( $agnosis_day_seed ): int {
	return crc32( $a . $agnosis_day_seed ) <=> crc32( $b . $agnosis_day_seed );
} );

// ── Pagination ────────────────────────────────────────────────────────────────
$agnosis_total        = count( $agnosis_pool );
$agnosis_max_pages    = max( 1, (int) ceil( $agnosis_total / $agnosis_per_page ) );
$agnosis_current_page = min( $agnosis_current_page, $agnosis_max_pages );
$agnosis_offset       = ( $agnosis_current_page - 1 ) * $agnosis_per_page;
$agnosis_page_ids     = array_slice( $agnosis_pool, $agnosis_offset, $agnosis_per_page );

// Deliberately NOT an early `return;` on empty $agnosis_page_ids (a real
// case — e.g. a medium filter with zero matching artwork in this context/
// language). A bare `return;` here means this whole file — including the
// filter nav and the router-region wrapper div itself — never renders at
// all, so render_block() returns "" as the block's entire output. That was
// already a latent bug on a normal full-page load (the block silently
// vanished, no explanation), but became much more visible once medium-pill
// clicks started swapping only the router region: the Interactivity Router
// can't find a `[data-wp-router-region="agnosis/gallery-overview"]` element
// in a completely empty response to swap in, so the swap blanks the entire
// gallery area instead of just failing to update it (confirmed live —
// filtering to a medium with zero results blanked everything below the
// header). $agnosis_posts is just left empty instead; the render section
// below shows the filter nav plus a "nothing here" message rather than
// nothing at all.
$agnosis_posts = empty( $agnosis_page_ids ) ? [] : get_posts( [
	'post_type'      => 'agnosis_artwork',
	'post__in'       => $agnosis_page_ids,
	'orderby'        => 'post__in',
	'posts_per_page' => count( $agnosis_page_ids ),
	'meta_query'     => $agnosis_lang_meta_query,
] );

// ── Medium filter terms ───────────────────────────────────────────────────────
// Scoped two ways: (a) the current request's language, and (b) mediums actually
// used somewhere in THIS context's own candidate pool — an artist's own
// subdomain gallery only ever offers mediums that artist has actually
// published in, and the sitewide overview only offers mediums some published
// artwork actually uses, rather than every medium term that has ever existed
// sitewide. Without (b), an artist subdomain with only photography/digital-art
// work still showed a "Sculpture" pill with nothing behind it — clicking it
// landed on a correctly-empty (but confusing) results page (reported
// 2026-07-18, screenshot of a 3-artwork subdomain still showing all 5 sitewide
// medium pills). Without (a): agnosis_medium is one flat, language-agnostic
// taxonomy — Compat\LinguaForge::sync_taxonomy() auto-creates a real term per
// (medium, target language) AI translation and flags it with
// TRANSLATED_TERM_META, the same way PromptConfig::medium_terms() already
// excludes those from the AI's controlled vocabulary; every language's variant
// of every medium showed up as its own pill regardless of which language site
// was being viewed, and clicking a pill whose term ID belonged to a different
// language than the one being browsed silently returned zero artworks — that
// term's ID matches no artwork tagged in THIS language (also reported
// 2026-07-18). $agnosis_lang_meta_query above already resolves LF_LANG for the
// post query; mirrored here for the term list.
// Resolved into a plain string variable (rather than referencing the LF_LANG
// constant directly wherever it's needed below) so PHPStan can see it's
// always defined at every later use-site — the constant itself is only ever
// guarded here, in this one inline defined()/truthiness check.
$agnosis_current_lang            = ( defined( 'LF_LANG' ) && LF_LANG ) ? (string) LF_LANG : '';
$agnosis_primary_lang            = \Agnosis\Compat\LinguaForge::is_active()
	? sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) )
	: '';
$agnosis_is_primary_lang_request = '' === $agnosis_primary_lang
	|| '' === $agnosis_current_lang
	|| $agnosis_current_lang === $agnosis_primary_lang;

// Every artwork this context could possibly show, in any medium — deliberately
// NOT passing $agnosis_tax_query here, so selecting one medium doesn't collapse
// the pill row down to just that one medium; $agnosis_artist_ids/
// $agnosis_lang_meta_query are the same artist-scope/language restriction the
// pool-building loop above already applies. IDs only, no pagination limit —
// this is purely for "which terms exist here at all", not for display.
$agnosis_candidate_ids = get_posts( [
	'post_type'      => 'agnosis_artwork',
	'post_status'    => 'publish',
	'author__in'     => $agnosis_artist_ids,
	'meta_query'     => $agnosis_lang_meta_query,
	'fields'         => 'ids',
	'posts_per_page' => -1,
	'no_found_rows'  => true,
] );

if ( empty( $agnosis_candidate_ids ) ) {
	$agnosis_medium_terms = [];
} else {
	$agnosis_medium_terms = $agnosis_is_primary_lang_request
		// Primary/original language (or LF inactive): only the admin-curated
		// vocabulary — exclude every AI-created translated variant.
		? get_terms( [
			'taxonomy'   => 'agnosis_medium',
			'hide_empty' => true,
			'object_ids' => $agnosis_candidate_ids,
			'meta_query' => [
				[
					'key'     => \Agnosis\Compat\LinguaForge::TRANSLATED_TERM_META,
					'compare' => 'NOT EXISTS',
				],
			],
		] )
		// Secondary language: only the terms translated into this exact language.
		: get_terms( [
			'taxonomy'   => 'agnosis_medium',
			'hide_empty' => true,
			'object_ids' => $agnosis_candidate_ids,
			'meta_query' => [
				[
					'key'   => \Agnosis\Compat\LinguaForge::TRANSLATED_TERM_META,
					'value' => $agnosis_current_lang,
				],
			],
		] );
}

if ( is_wp_error( $agnosis_medium_terms ) ) {
	$agnosis_medium_terms = [];
}

// ── URL helpers (closures to avoid global function redeclaration) ─────────────
// Built off the CURRENT request's own URL (host + path + query), not
// home_url( '/' ) — this block is placed on more than one template (home.html
// AND archive-agnosis_artwork.html), and home_url( '/' ) always resolves to
// the site root regardless of which one actually rendered it. A filter or
// pagination click on the archive page was silently redirecting the visitor
// to the home page instead of updating the archive in place. (Artist
// subdomains were never the problem here — SubdomainRouter::rewrite_home()
// already filters WordPress's own `option_home` per-request, so home_url()
// itself correctly reflected the subdomain; this was purely a
// which-template-is-this-block-actually-on gap.)
// add_query_arg()/remove_query_arg() are flagged by WordPress's Plugin Check
// tool when called without an explicit URL — the implicit fallback to
// $_SERVER['REQUEST_URI'] is unescaped input — so the current URL is built
// and escaped by hand here instead of relying on that fallback.
$agnosis_current_url = esc_url_raw(
	( is_ssl() ? 'https://' : 'http://' )
	. wp_unslash( $_SERVER['HTTP_HOST'] ?? '' )
	. wp_unslash( $_SERVER['REQUEST_URI'] ?? '' )
);

// Strip our own two params from the base before anything else. $args below
// deliberately OMITS a key entirely for its "default" state (no filter, page
// 1) via array_filter() — but add_query_arg() only ever adds/replaces keys
// actually present in $args, it never clears an absent one. Without this,
// clicking "All" while on page 3 of a medium filter would keep the current
// URL's own stale agnosis_overview_page=3 (and clicking a fresh medium while
// paginated would do the same), since neither value would be in $args to
// overwrite it.
$agnosis_current_url = remove_query_arg( [ 'agnosis_medium_filter', 'agnosis_overview_page' ], $agnosis_current_url );

$agnosis_filter_url = static function ( string $medium, int $page = 1 ) use ( $agnosis_current_url ): string {
	$args = array_filter( [
		'agnosis_medium_filter' => $medium ?: null,
		'agnosis_overview_page' => $page > 1 ? $page : null,
	] );
	return add_query_arg( $args, $agnosis_current_url );
};

// ── Subdomain URL resolution ──────────────────────────────────────────────────
$agnosis_has_subdomains = ! empty( get_option( 'agnosis_base_domain', '' ) );

$agnosis_post_url_for = static function ( \WP_Post $p ) use ( $agnosis_has_subdomains ): string {
	if ( $agnosis_has_subdomains && class_exists( '\Agnosis\Network\SubdomainRouter' ) ) {
		$home = \Agnosis\Network\SubdomainRouter::url_for_artist( (int) $p->post_author );
		if ( $home ) {
			return rtrim( $home, '/' ) . '/art/' . $p->post_name . '/';
		}
	}
	return (string) get_permalink( $p->ID );
};

// On an artist subdomain  → link to their biography page on that subdomain.
// On the main domain      → link to the artist's subdomain home.
// Memoised per request to avoid redundant queries for repeated artists.
$agnosis_on_subdomain   = $agnosis_has_subdomains
	&& class_exists( '\Agnosis\Network\SubdomainRouter' )
	&& \Agnosis\Network\SubdomainRouter::is_artist_subdomain();
$agnosis_bio_url_cache  = [];
$agnosis_artist_url_for = static function ( int $artist_id ) use ( $agnosis_has_subdomains, $agnosis_on_subdomain, &$agnosis_bio_url_cache ): string {
	if ( isset( $agnosis_bio_url_cache[ $artist_id ] ) ) {
		return $agnosis_bio_url_cache[ $artist_id ];
	}

	if ( $agnosis_on_subdomain ) {
		// We're on an artist subdomain — link to the biography page.
		$bios = get_posts( [
			'post_type'      => 'agnosis_biography',
			'post_status'    => 'publish',
			'author'         => $artist_id,
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		] );
		if ( $bios ) {
			$url = (string) get_permalink( $bios[0]->ID );
			$agnosis_bio_url_cache[ $artist_id ] = $url;
			return $url;
		}
		// No biography yet — stay on subdomain home.
		$url = \Agnosis\Network\SubdomainRouter::url_for_artist( $artist_id );
		$agnosis_bio_url_cache[ $artist_id ] = $url;
		return $url;
	}

	// We're on the main domain — link to the artist's subdomain.
	if ( $agnosis_has_subdomains && class_exists( '\Agnosis\Network\SubdomainRouter' ) ) {
		$home = \Agnosis\Network\SubdomainRouter::url_for_artist( $artist_id );
		if ( $home ) {
			$agnosis_bio_url_cache[ $artist_id ] = $home;
			return $home;
		}
	}
	$agnosis_author_url                  = (string) get_author_posts_url( $artist_id );
	$agnosis_bio_url_cache[ $artist_id ] = $agnosis_author_url;
	return $agnosis_author_url;
};

// ── Render ────────────────────────────────────────────────────────────────────
// Output directly — the PHP render_callback in GalleryOverview::render_block()
// wraps this include in its own ob_start()/ob_get_clean() pair.
//
// The filter nav + grid + pagination below are wrapped in one Interactivity
// API router region (data-wp-router-region, paired with view.js's
// 'agnosis/gallery-overview' store) so a medium-pill or pagination click
// fetches this SAME render.php's output for the clicked link's real href and
// swaps only this region — a genuinely correct, complete, server-filtered
// result (including pagination) rather than a client-side guess about what's
// already on screen. See view.js's own docblock for why this replaced an
// earlier pure-client-side attempt.
?>

<div
	data-wp-interactive="agnosis/gallery-overview"
	data-wp-router-region="agnosis/gallery-overview"
>

<?php if ( $agnosis_medium_terms ) : ?>
<nav class="agnosis-medium-filter" aria-label="<?php esc_attr_e( 'Filter by medium', 'agnosis' ); ?>">
	<a href="<?php echo esc_url( $agnosis_filter_url( '', 1 ) ); ?>"
	   data-wp-on--mouseenter="actions.prefetch"
	   data-wp-on--click="actions.navigate"
	   class="agnosis-medium-filter__term<?php echo ! $agnosis_medium_filter ? ' is-active' : ''; ?>">
		<?php esc_html_e( 'All', 'agnosis' ); ?>
	</a>
	<?php foreach ( $agnosis_medium_terms as $agnosis_term ) : ?>
	<a href="<?php echo esc_url( $agnosis_filter_url( $agnosis_term->slug, 1 ) ); ?>"
	   data-wp-on--mouseenter="actions.prefetch"
	   data-wp-on--click="actions.navigate"
	   class="agnosis-medium-filter__term<?php echo ( $agnosis_medium_filter === $agnosis_term->slug ) ? ' is-active' : ''; ?>">
		<?php echo esc_html( $agnosis_term->name ); ?>
	</a>
	<?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ( ! $agnosis_posts ) : ?>
<p class="agnosis-gallery-overview__empty">
	<?php if ( $agnosis_medium_filter ) : ?>
		<?php esc_html_e( 'No artwork matches this filter yet.', 'agnosis' ); ?>
	<?php else : ?>
		<?php esc_html_e( 'No artwork to show yet.', 'agnosis' ); ?>
	<?php endif; ?>
</p>
<?php else : ?>
<div class="agnosis-gallery-overview agnosis-gallery-overview--cols-<?php echo (int) $agnosis_columns; ?>">
	<?php
	foreach ( $agnosis_posts as $agnosis_post ) :
		$agnosis_artist_id   = (int) $agnosis_post->post_author;
		$agnosis_thumb_id    = (int) get_post_thumbnail_id( $agnosis_post->ID );
		$agnosis_thumb       = wp_get_attachment_image_src( $agnosis_thumb_id, 'agnosis-thumb' );
		$agnosis_full_src    = (string) get_the_post_thumbnail_url( $agnosis_post->ID, 'full' );
		$agnosis_meta        = wp_get_attachment_metadata( $agnosis_thumb_id );
		// Promoting only applies to the shared main gallery (see the pool-
		// building loop's own comment above) — never show the ✦ badge on an
		// artist's own subdomain gallery, even for a piece that IS flagged
		// featured from having been promoted while also appearing on the main
		// gallery elsewhere.
		$agnosis_is_featured = null === $agnosis_subdomain_artist_id
			&& '1' === get_post_meta( $agnosis_post->ID, '_agnosis_featured', true );
		$agnosis_artist      = get_userdata( $agnosis_artist_id );
		$agnosis_post_url    = $agnosis_post_url_for( $agnosis_post );
		$agnosis_art_url     = $agnosis_artist_url_for( $agnosis_artist_id );

		// Per-image unique ID and WP Interactivity state for the core/image lightbox store.
		$agnosis_uid = uniqid( 'ag', true );
		wp_interactivity_state(
			'core/image',
			[
				'metadata' => [
					$agnosis_uid => [
						// Full-resolution source shown in the lightbox overlay.
						'uploadedSrc'      => $agnosis_full_src ?: ( $agnosis_thumb ? $agnosis_thumb[0] : '' ),
						// Classes applied to the <figure> inside the overlay (no frame padding there).
						'figureClassNames' => 'wp-block-image',
						'figureStyles'     => null,
						// Class applied to the <img> inside the overlay.
						'imgClassNames'    => $agnosis_thumb_id ? 'wp-image-' . $agnosis_thumb_id : '',
						'imgStyles'        => null,
						// Original upload dimensions — used by view.js to compute the zoom animation.
						'targetWidth'      => $agnosis_meta['width']  ?? 'none',
						'targetHeight'     => $agnosis_meta['height'] ?? 'none',
						'scaleAttr'        => false,
						'ariaLabel'        => __( 'Enlarged image', 'agnosis' ),
						'alt'              => $agnosis_post->post_title,
					],
				],
			]
		);
		?>
	<article class="agnosis-gallery-overview__item<?php echo $agnosis_is_featured ? ' is-featured' : ''; ?>">
		<?php if ( $agnosis_thumb ) : ?>
		<figure
			class="wp-block-image agnosis-gallery-overview__image-wrap wp-lightbox-container"
			data-wp-interactive="core/image"
			data-wp-context="<?php echo esc_attr( (string) wp_json_encode( [ 'imageId' => $agnosis_uid ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ) ); ?>"
			data-wp-key="<?php echo esc_attr( $agnosis_uid ); ?>"
		>
			<img
				src="<?php echo esc_url( $agnosis_thumb[0] ); ?>"
				width="<?php echo (int) $agnosis_thumb[1]; ?>"
				height="<?php echo (int) $agnosis_thumb[2]; ?>"
				alt="<?php echo esc_attr( $agnosis_post->post_title ); ?>"
				class="wp-image-<?php echo absint( $agnosis_thumb_id ); ?>"
				loading="lazy"
				decoding="async"
				data-wp-init="callbacks.setButtonStyles"
				data-wp-on--load="callbacks.setButtonStyles"
				data-wp-on-window--resize="callbacks.setButtonStyles"
				data-wp-on--click="actions.showLightbox"
				data-wp-class--hide="state.isContentHidden"
				data-wp-class--show="state.isContentVisible"
			>
			<?php if ( $agnosis_is_featured ) : ?>
			<span class="agnosis-gallery-overview__badge" aria-label="<?php esc_attr_e( 'Featured', 'agnosis' ); ?>">✦</span>
			<?php endif; ?>
			<button
				class="lightbox-trigger"
				type="button"
				aria-haspopup="dialog"
				aria-label="<?php esc_attr_e( 'Enlarge', 'agnosis' ); ?>"
				data-wp-init="callbacks.initTriggerButton"
				data-wp-on--click="actions.showLightbox"
				data-wp-style--right="state.imageButtonRight"
				data-wp-style--top="state.imageButtonTop"
			>
				<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 12 12">
					<path fill="#fff" d="M2 0a2 2 0 0 0-2 2v2h1.5V2a.5.5 0 0 1 .5-.5h2V0H2Zm2 10.5H2a.5.5 0 0 1-.5-.5V8H0v2a2 2 0 0 0 2 2h2v-1.5ZM8 12v-1.5h2a.5.5 0 0 0 .5-.5V8H12v2a2 2 0 0 1-2 2H8Zm2-12a2 2 0 0 1 2 2v2h-1.5V2a.5.5 0 0 0-.5-.5H8V0h2Z" />
				</svg>
			</button>
		</figure>
		<?php endif; ?>
		<div class="agnosis-gallery-overview__caption">
			<a href="<?php echo esc_url( $agnosis_post_url ); ?>" class="agnosis-gallery-overview__title"><?php echo esc_html( $agnosis_post->post_title ); ?></a>
			<?php if ( $agnosis_artist ) : ?>
			<a href="<?php echo esc_url( $agnosis_art_url ); ?>" class="agnosis-gallery-overview__artist">
				<?php echo esc_html( $agnosis_artist->display_name ); ?>
			</a>
			<?php endif; ?>
		</div>
	</article>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ( $agnosis_max_pages > 1 ) : ?>
<nav class="agnosis-gallery-overview__pagination" aria-label="<?php esc_attr_e( 'Gallery pages', 'agnosis' ); ?>">
	<?php if ( $agnosis_current_page > 1 ) : ?>
	<a href="<?php echo esc_url( $agnosis_filter_url( $agnosis_medium_filter, $agnosis_current_page - 1 ) ); ?>"
	   data-wp-on--mouseenter="actions.prefetch"
	   data-wp-on--click="actions.navigate"
	   class="agnosis-gallery-overview__page-link">← <?php esc_html_e( 'Prev', 'agnosis' ); ?></a>
	<?php endif; ?>

	<?php for ( $agnosis_p = 1; $agnosis_p <= $agnosis_max_pages; $agnosis_p++ ) : ?>
	<a href="<?php echo esc_url( $agnosis_filter_url( $agnosis_medium_filter, $agnosis_p ) ); ?>"
	   data-wp-on--mouseenter="actions.prefetch"
	   data-wp-on--click="actions.navigate"
	   class="agnosis-gallery-overview__page-link<?php echo ( $agnosis_p === $agnosis_current_page ) ? ' is-current' : ''; ?>"
		<?php echo ( $agnosis_p === $agnosis_current_page ) ? 'aria-current="page"' : ''; ?>>
		<?php echo (int) $agnosis_p; ?>
	</a>
	<?php endfor; ?>

	<?php if ( $agnosis_current_page < $agnosis_max_pages ) : ?>
	<a href="<?php echo esc_url( $agnosis_filter_url( $agnosis_medium_filter, $agnosis_current_page + 1 ) ); ?>"
	   data-wp-on--mouseenter="actions.prefetch"
	   data-wp-on--click="actions.navigate"
	   class="agnosis-gallery-overview__page-link"><?php esc_html_e( 'Next', 'agnosis' ); ?> →</a>
	<?php endif; ?>
</nav>
<?php endif; ?>

</div><!-- /[data-wp-router-region="agnosis/gallery-overview"] -->
