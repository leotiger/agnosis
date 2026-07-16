<?php
/**
 * Gallery overview block — server-side render.
 *
 * Proportional, randomised artwork grid across all artists.
 *
 * Logic:
 *   1. Collect all distinct artwork authors.
 *   2. Per-artist budget = ceil( pool_size / artist_count ), min 1.
 *   3. Fill each artist's slot: featured post first (_agnosis_featured=1),
 *      then most-recent posts to exhaust the budget.
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
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter/page, no state change.
$agnosis_medium_filter = isset( $_GET['agnosis_medium'] )
	? sanitize_key( wp_unslash( $_GET['agnosis_medium'] ) )
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

	// Featured artwork first.
	$agnosis_featured = get_posts( [
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
	] );

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

if ( empty( $agnosis_page_ids ) ) {
	return;
}

$agnosis_posts = get_posts( [
	'post_type'      => 'agnosis_artwork',
	'post__in'       => $agnosis_page_ids,
	'orderby'        => 'post__in',
	'posts_per_page' => count( $agnosis_page_ids ),
	'meta_query'     => $agnosis_lang_meta_query,
] );

// ── Medium filter terms ───────────────────────────────────────────────────────
$agnosis_medium_terms = get_terms( [ 'taxonomy' => 'agnosis_medium', 'hide_empty' => true ] );
if ( is_wp_error( $agnosis_medium_terms ) ) {
	$agnosis_medium_terms = [];
}

// ── URL helpers (closures to avoid global function redeclaration) ─────────────
$agnosis_filter_url = static function ( string $medium, int $page = 1 ): string {
	$args = array_filter( [
		'agnosis_medium'        => $medium ?: null,
		'agnosis_overview_page' => $page > 1 ? $page : null,
	] );
	return add_query_arg( $args, home_url( '/' ) );
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
?>

<?php if ( $agnosis_medium_terms ) : ?>
<nav class="agnosis-medium-filter" aria-label="<?php esc_attr_e( 'Filter by medium', 'agnosis' ); ?>">
	<a href="<?php echo esc_url( $agnosis_filter_url( '', 1 ) ); ?>"
	   class="agnosis-medium-filter__term<?php echo ! $agnosis_medium_filter ? ' is-active' : ''; ?>">
		<?php esc_html_e( 'All', 'agnosis' ); ?>
	</a>
	<?php foreach ( $agnosis_medium_terms as $agnosis_term ) : ?>
	<a href="<?php echo esc_url( $agnosis_filter_url( $agnosis_term->slug, 1 ) ); ?>"
	   class="agnosis-medium-filter__term<?php echo ( $agnosis_medium_filter === $agnosis_term->slug ) ? ' is-active' : ''; ?>">
		<?php echo esc_html( $agnosis_term->name ); ?>
	</a>
	<?php endforeach; ?>
</nav>
<?php endif; ?>

<div class="agnosis-gallery-overview agnosis-gallery-overview--cols-<?php echo (int) $agnosis_columns; ?>">
	<?php
	foreach ( $agnosis_posts as $agnosis_post ) :
		$agnosis_artist_id   = (int) $agnosis_post->post_author;
		$agnosis_thumb_id    = (int) get_post_thumbnail_id( $agnosis_post->ID );
		$agnosis_thumb       = wp_get_attachment_image_src( $agnosis_thumb_id, 'agnosis-thumb' );
		$agnosis_full_src    = (string) get_the_post_thumbnail_url( $agnosis_post->ID, 'full' );
		$agnosis_meta        = wp_get_attachment_metadata( $agnosis_thumb_id );
		$agnosis_is_featured = '1' === get_post_meta( $agnosis_post->ID, '_agnosis_featured', true );
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

<?php if ( $agnosis_max_pages > 1 ) : ?>
<nav class="agnosis-gallery-overview__pagination" aria-label="<?php esc_attr_e( 'Gallery pages', 'agnosis' ); ?>">
	<?php if ( $agnosis_current_page > 1 ) : ?>
	<a href="<?php echo esc_url( $agnosis_filter_url( $agnosis_medium_filter, $agnosis_current_page - 1 ) ); ?>"
	   class="agnosis-gallery-overview__page-link">← <?php esc_html_e( 'Prev', 'agnosis' ); ?></a>
	<?php endif; ?>

	<?php for ( $agnosis_p = 1; $agnosis_p <= $agnosis_max_pages; $agnosis_p++ ) : ?>
	<a href="<?php echo esc_url( $agnosis_filter_url( $agnosis_medium_filter, $agnosis_p ) ); ?>"
	   class="agnosis-gallery-overview__page-link<?php echo ( $agnosis_p === $agnosis_current_page ) ? ' is-current' : ''; ?>"
		<?php echo ( $agnosis_p === $agnosis_current_page ) ? 'aria-current="page"' : ''; ?>>
		<?php echo (int) $agnosis_p; ?>
	</a>
	<?php endfor; ?>

	<?php if ( $agnosis_current_page < $agnosis_max_pages ) : ?>
	<a href="<?php echo esc_url( $agnosis_filter_url( $agnosis_medium_filter, $agnosis_current_page + 1 ) ); ?>"
	   class="agnosis-gallery-overview__page-link"><?php esc_html_e( 'Next', 'agnosis' ); ?> →</a>
	<?php endif; ?>
</nav>
<?php endif; ?>
