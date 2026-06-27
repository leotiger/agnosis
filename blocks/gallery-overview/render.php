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

declare(strict_types=1);

// ── Settings ────────────────────────────────────────────────────────────────
$per_page    = max(1, (int) get_option('agnosis_gallery_per_page', 12));
$columns     = max(1, (int) ($attributes['columns'] ?? 3));
$pool_target = max($per_page * 4, 60); // total posts to build the pool from

// ── URL inputs ──────────────────────────────────────────────────────────────
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$medium_filter = isset($_GET['agnosis_medium'])
    ? sanitize_key($_GET['agnosis_medium'])
    : '';
$current_page  = max(1, (int) ($_GET['agnosis_overview_page'] ?? 1));
// phpcs:enable

// ── Artist pool ─────────────────────────────────────────────────────────────
global $wpdb;
$artist_ids = $wpdb->get_col(
    "SELECT DISTINCT post_author FROM {$wpdb->posts}
     WHERE post_type = 'agnosis_artwork' AND post_status = 'publish'"
);

if ( empty( $artist_ids ) ) {
	return '';
}

$artist_count = count( $artist_ids );
$per_artist   = max( 1, (int) ceil( $pool_target / $artist_count ) );

// ── Medium filter tax_query ──────────────────────────────────────────────────
$tax_query = $medium_filter ? [ [
	'taxonomy' => 'agnosis_medium',
	'field'    => 'slug',
	'terms'    => $medium_filter,
] ] : [];

// ── Build pool ───────────────────────────────────────────────────────────────
$pool = [];

foreach ( $artist_ids as $raw_id ) {
	$artist_id = (int) $raw_id;

	// Featured artwork first.
	$featured = get_posts( [
		'post_type'      => 'agnosis_artwork',
		'post_status'    => 'publish',
		'author'         => $artist_id,
		'posts_per_page' => 1,
		'meta_query'     => [ [ 'key' => '_agnosis_featured', 'value' => '1' ] ],
		'tax_query'      => $tax_query,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	] );

	$exclude   = $featured;
	$remaining = $per_artist - count( $featured );

	// Fill remainder with most-recent non-featured artworks.
	if ( $remaining > 0 ) {
		$extra = get_posts( [
			'post_type'      => 'agnosis_artwork',
			'post_status'    => 'publish',
			'author'         => $artist_id,
			'posts_per_page' => $remaining,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'exclude'        => $exclude,
			'tax_query'      => $tax_query,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		$featured = array_merge( $featured, $extra );
	}

	$pool = array_merge( $pool, array_map( 'intval', $featured ) );
}

// ── Day-seeded shuffle ────────────────────────────────────────────────────────
// Same seed within a calendar day → stable page 2, 3 … pagination.
// Incorporates medium filter slug so filter pages don't mirror unfiltered order.
srand( (int) date( 'Ymd' ) + (int) crc32( $medium_filter ) );
shuffle( $pool );
srand(); // restore PHP's default PRNG state

// ── Pagination ────────────────────────────────────────────────────────────────
$total     = count( $pool );
$max_pages = max( 1, (int) ceil( $total / $per_page ) );
$current_page = min( $current_page, $max_pages );
$offset    = ( $current_page - 1 ) * $per_page;
$page_ids  = array_slice( $pool, $offset, $per_page );

if ( empty( $page_ids ) ) {
	return '';
}

$posts = get_posts( [
	'post_type'      => 'agnosis_artwork',
	'post__in'       => $page_ids,
	'orderby'        => 'post__in',
	'posts_per_page' => count( $page_ids ),
] );

// ── Medium filter terms ───────────────────────────────────────────────────────
$medium_terms = get_terms( [ 'taxonomy' => 'agnosis_medium', 'hide_empty' => true ] );
if ( is_wp_error( $medium_terms ) ) {
	$medium_terms = [];
}

// ── URL helpers (closures to avoid global function redeclaration) ─────────────
$filter_url = static function ( string $medium, int $page = 1 ) use ( $medium_filter ): string {
	$args = array_filter( [
		'agnosis_medium'        => $medium ?: null,
		'agnosis_overview_page' => $page > 1 ? $page : null,
	] );
	return add_query_arg( $args, home_url( '/' ) );
};

// ── Subdomain URL resolution ──────────────────────────────────────────────────
$has_subdomains = ! empty( get_option( 'agnosis_base_domain', '' ) );

$post_url_for = static function ( \WP_Post $p ) use ( $has_subdomains ): string {
	if ( $has_subdomains && class_exists( '\Agnosis\Network\SubdomainRouter' ) ) {
		$home = \Agnosis\Network\SubdomainRouter::url_for_artist( (int) $p->post_author );
		if ( $home ) {
			return rtrim( $home, '/' ) . '/art/' . $p->post_name . '/';
		}
	}
	return (string) get_permalink( $p->ID );
};

$artist_url_for = static function ( int $artist_id ) use ( $has_subdomains ): string {
	if ( $has_subdomains && class_exists( '\Agnosis\Network\SubdomainRouter' ) ) {
		$home = \Agnosis\Network\SubdomainRouter::url_for_artist( $artist_id );
		if ( $home ) {
			return $home;
		}
	}
	return (string) get_author_posts_url( $artist_id );
};

// ── Render ────────────────────────────────────────────────────────────────────
ob_start();
?>

<?php if ( $medium_terms ) : ?>
<nav class="agnosis-medium-filter" aria-label="<?php esc_attr_e( 'Filter by medium', 'agnosis' ); ?>">
	<a href="<?php echo esc_url( $filter_url( '', 1 ) ); ?>"
	   class="agnosis-medium-filter__term<?php echo ! $medium_filter ? ' is-active' : ''; ?>">
		<?php esc_html_e( 'All', 'agnosis' ); ?>
	</a>
	<?php foreach ( $medium_terms as $term ) : ?>
	<a href="<?php echo esc_url( $filter_url( $term->slug, 1 ) ); ?>"
	   class="agnosis-medium-filter__term<?php echo ( $medium_filter === $term->slug ) ? ' is-active' : ''; ?>">
		<?php echo esc_html( $term->name ); ?>
	</a>
	<?php endforeach; ?>
</nav>
<?php endif; ?>

<div class="agnosis-gallery-overview agnosis-gallery-overview--cols-<?php echo $columns; ?>">
	<?php foreach ( $posts as $post ) :
		$artist_id   = (int) $post->post_author;
		$thumb       = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'agnosis-thumb' );
		$is_featured = '1' === get_post_meta( $post->ID, '_agnosis_featured', true );
		$artist      = get_userdata( $artist_id );
		$post_url    = $post_url_for( $post );
		$art_url     = $artist_url_for( $artist_id );
	?>
	<article class="agnosis-gallery-overview__item<?php echo $is_featured ? ' is-featured' : ''; ?>">
		<a href="<?php echo esc_url( $post_url ); ?>" class="agnosis-gallery-overview__link">
			<?php if ( $thumb ) : ?>
			<div class="agnosis-gallery-overview__image-wrap">
				<img
					src="<?php echo esc_url( $thumb[0] ); ?>"
					width="<?php echo (int) $thumb[1]; ?>"
					height="<?php echo (int) $thumb[2]; ?>"
					alt="<?php echo esc_attr( $post->post_title ); ?>"
					loading="lazy"
					decoding="async"
				>
				<?php if ( $is_featured ) : ?>
				<span class="agnosis-gallery-overview__badge" aria-label="<?php esc_attr_e( 'Featured', 'agnosis' ); ?>">✦</span>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			<div class="agnosis-gallery-overview__caption">
				<span class="agnosis-gallery-overview__title"><?php echo esc_html( $post->post_title ); ?></span>
				<?php if ( $artist ) : ?>
				<a href="<?php echo esc_url( $art_url ); ?>"
				   class="agnosis-gallery-overview__artist"
				   tabindex="-1"
				   onclick="event.stopPropagation();">
					<?php echo esc_html( $artist->display_name ); ?>
				</a>
				<?php endif; ?>
			</div>
		</a>
	</article>
	<?php endforeach; ?>
</div>

<?php if ( $max_pages > 1 ) : ?>
<nav class="agnosis-gallery-overview__pagination" aria-label="<?php esc_attr_e( 'Gallery pages', 'agnosis' ); ?>">
	<?php if ( $current_page > 1 ) : ?>
	<a href="<?php echo esc_url( $filter_url( $medium_filter, $current_page - 1 ) ); ?>"
	   class="agnosis-gallery-overview__page-link">← <?php esc_html_e( 'Prev', 'agnosis' ); ?></a>
	<?php endif; ?>

	<?php for ( $p = 1; $p <= $max_pages; $p++ ) : ?>
	<a href="<?php echo esc_url( $filter_url( $medium_filter, $p ) ); ?>"
	   class="agnosis-gallery-overview__page-link<?php echo ( $p === $current_page ) ? ' is-current' : ''; ?>"
	   <?php echo ( $p === $current_page ) ? 'aria-current="page"' : ''; ?>>
		<?php echo (int) $p; ?>
	</a>
	<?php endfor; ?>

	<?php if ( $current_page < $max_pages ) : ?>
	<a href="<?php echo esc_url( $filter_url( $medium_filter, $current_page + 1 ) ); ?>"
	   class="agnosis-gallery-overview__page-link"><?php esc_html_e( 'Next', 'agnosis' ); ?> →</a>
	<?php endif; ?>
</nav>
<?php endif; ?>

<?php
return ob_get_clean();
