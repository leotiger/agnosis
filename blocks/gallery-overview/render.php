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

// ── Artist pool ─────────────────────────────────────────────────────────────
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

if ( empty( $agnosis_artist_ids ) ) {
	return;
}

$agnosis_artist_count = count( $agnosis_artist_ids );
$agnosis_per_artist   = max( 1, (int) ceil( $agnosis_pool_target / $agnosis_artist_count ) );

// ── Medium filter tax_query ──────────────────────────────────────────────────
$agnosis_tax_query = $agnosis_medium_filter ? [ [
	'taxonomy' => 'agnosis_medium',
	'field'    => 'slug',
	'terms'    => $agnosis_medium_filter,
] ] : [];

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
		'meta_query'     => [ [ 'key' => '_agnosis_featured', 'value' => '1' ] ],
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

$agnosis_artist_url_for = static function ( int $artist_id ) use ( $agnosis_has_subdomains ): string {
	if ( $agnosis_has_subdomains && class_exists( '\Agnosis\Network\SubdomainRouter' ) ) {
		$home = \Agnosis\Network\SubdomainRouter::url_for_artist( $artist_id );
		if ( $home ) {
			return $home;
		}
	}
	return (string) get_author_posts_url( $artist_id );
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
	<?php foreach ( $agnosis_posts as $agnosis_post ) :
		$agnosis_artist_id   = (int) $agnosis_post->post_author;
		$agnosis_thumb       = wp_get_attachment_image_src( get_post_thumbnail_id( $agnosis_post->ID ), 'agnosis-thumb' );
		$agnosis_is_featured = '1' === get_post_meta( $agnosis_post->ID, '_agnosis_featured', true );
		$agnosis_artist      = get_userdata( $agnosis_artist_id );
		$agnosis_post_url    = $agnosis_post_url_for( $agnosis_post );
		$agnosis_art_url     = $agnosis_artist_url_for( $agnosis_artist_id );
	?>
	<article class="agnosis-gallery-overview__item<?php echo $agnosis_is_featured ? ' is-featured' : ''; ?>">
		<a href="<?php echo esc_url( $agnosis_post_url ); ?>" class="agnosis-gallery-overview__link">
			<?php if ( $agnosis_thumb ) : ?>
			<div class="agnosis-gallery-overview__image-wrap">
				<img
					src="<?php echo esc_url( $agnosis_thumb[0] ); ?>"
					width="<?php echo (int) $agnosis_thumb[1]; ?>"
					height="<?php echo (int) $agnosis_thumb[2]; ?>"
					alt="<?php echo esc_attr( $agnosis_post->post_title ); ?>"
					loading="lazy"
					decoding="async"
				>
				<?php if ( $agnosis_is_featured ) : ?>
				<span class="agnosis-gallery-overview__badge" aria-label="<?php esc_attr_e( 'Featured', 'agnosis' ); ?>">✦</span>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			<div class="agnosis-gallery-overview__caption">
				<span class="agnosis-gallery-overview__title"><?php echo esc_html( $agnosis_post->post_title ); ?></span>
				<?php if ( $agnosis_artist ) : ?>
				<a href="<?php echo esc_url( $agnosis_art_url ); ?>"
				   class="agnosis-gallery-overview__artist"
				   tabindex="-1"
				   onclick="event.stopPropagation();">
					<?php echo esc_html( $agnosis_artist->display_name ); ?>
				</a>
				<?php endif; ?>
			</div>
		</a>
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

<?php
