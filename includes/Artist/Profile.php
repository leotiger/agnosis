<?php
/**
 * Artist profile — custom post type + taxonomy registration.
 *
 * agnosis_artwork   — artwork submissions (default, one post per artwork).
 * agnosis_biography — artist biography (singleton per artist, always updated).
 * agnosis_event     — artist events (has an archive; email-intake still merges
 *                      into one post per artist — see PostCreator::INDICATORS).
 * agnosis_medium    — taxonomy: painting, photography, sculpture, digital, etc.
 *
 * New CPTs are triggered by subject-line indicators in incoming emails:
 *   [Biography] → agnosis_biography
 *   [Event]     → agnosis_event
 *   (none)      → agnosis_artwork
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

class Profile {

	public function register_post_type(): void {
		register_post_type( 'agnosis_artwork', [
			'label'               => __( 'Artworks', 'agnosis' ),
			'labels'              => $this->artwork_labels(),
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'query_var'           => true,
			'rewrite'             => [ 'slug' => 'art', 'with_front' => false ],
			'capability_type'     => 'post',
			'has_archive'         => 'gallery',
			'hierarchical'        => false,
			'menu_position'       => 5,
			'menu_icon'           => 'dashicons-art',
			// 'revisions' added for front-end correction (audit §7c "safety rails"):
			// every artist edit through ContentEditor becomes a WP revision, giving
			// admins free undo/accountability with no extra code.
			'supports'            => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields', 'revisions' ],
			'taxonomies'          => [ 'post_tag', 'agnosis_medium' ],
			'template'            => [
				[ 'core/image' ],
				[ 'core/paragraph', [ 'placeholder' => __( 'Write about this artwork…', 'agnosis' ) ] ],
			],
		] );
	}

	public function register_biography_post_type(): void {
		register_post_type( 'agnosis_biography', [
			'label'              => __( 'Biographies', 'agnosis' ),
			'labels'             => [
				'name'               => __( 'Biographies',          'agnosis' ),
				'singular_name'      => __( 'Biography',            'agnosis' ),
				'add_new_item'       => __( 'Add New Biography',    'agnosis' ),
				'edit_item'          => __( 'Edit Biography',       'agnosis' ),
				'new_item'           => __( 'New Biography',        'agnosis' ),
				'view_item'          => __( 'View Biography',       'agnosis' ),
				'search_items'       => __( 'Search Biographies',   'agnosis' ),
				'not_found'          => __( 'No biographies found.', 'agnosis' ),
				'not_found_in_trash' => __( 'No biographies in trash.', 'agnosis' ),
				'all_items'          => __( 'All Biographies',      'agnosis' ),
				'menu_name'          => __( 'Biographies',          'agnosis' ),
				'name_admin_bar'     => __( 'Biography',            'agnosis' ),
			],
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => [ 'slug' => 'biography', 'with_front' => false ],
			'capability_type'    => 'post',
			'has_archive'        => false, // one per artist — no archive needed
			'hierarchical'       => false,
			'menu_position'      => 6,
			'menu_icon'          => 'dashicons-id',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields', 'revisions' ],
			'taxonomies'         => [ 'post_tag' ],
		] );
	}

	public function register_event_post_type(): void {
		register_post_type( 'agnosis_event', [
			'label'              => __( 'Events', 'agnosis' ),
			'labels'             => [
				'name'               => __( 'Events',               'agnosis' ),
				'singular_name'      => __( 'Event',                'agnosis' ),
				'add_new_item'       => __( 'Add New Event',        'agnosis' ),
				'edit_item'          => __( 'Edit Event',           'agnosis' ),
				'new_item'           => __( 'New Event',            'agnosis' ),
				'view_item'          => __( 'View Event',           'agnosis' ),
				'search_items'       => __( 'Search Events',        'agnosis' ),
				'not_found'          => __( 'No events found.',     'agnosis' ),
				'not_found_in_trash' => __( 'No events in trash.',  'agnosis' ),
				'all_items'          => __( 'All Events',           'agnosis' ),
				'menu_name'          => __( 'Events',               'agnosis' ),
				'name_admin_bar'     => __( 'Event',                'agnosis' ),
			],
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => [ 'slug' => 'events', 'with_front' => false ],
			'capability_type'    => 'post',
			// An artist can have several events (upcoming shows, past exhibitions,
			// etc.) — an archive is needed so "Events" in the artist breadcrumb has
			// somewhere to link to. NOTE: PostCreator's email-intake pipeline still
			// treats [Event] submissions as a singleton (each new email overwrites
			// the artist's one existing event post) — that's a separate, still-open
			// question about whether repeated event emails should now create
			// additional posts instead. See archive-agnosis_event.html (agnosis-theme)
			// for the chronological listing this archive slug serves.
			'has_archive'        => 'events',
			'hierarchical'       => false,
			'menu_position'      => 7,
			'menu_icon'          => 'dashicons-calendar-alt',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields', 'revisions' ],
			'taxonomies'         => [ 'post_tag' ],
		] );
	}

	public function register_taxonomy(): void {
		register_taxonomy( 'agnosis_medium', [ 'agnosis_artwork' ], [
			'label'             => __( 'Medium', 'agnosis' ),
			'labels'            => [
				'name'          => __( 'Mediums', 'agnosis' ),
				'singular_name' => __( 'Medium', 'agnosis' ),
				'search_items'  => __( 'Search Mediums', 'agnosis' ),
				'all_items'     => __( 'All Mediums', 'agnosis' ),
				'edit_item'     => __( 'Edit Medium', 'agnosis' ),
				'update_item'   => __( 'Update Medium', 'agnosis' ),
				'add_new_item'  => __( 'Add New Medium', 'agnosis' ),
				'new_item_name' => __( 'New Medium Name', 'agnosis' ),
				'menu_name'     => __( 'Mediums', 'agnosis' ),
			],
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => [ 'slug' => 'medium' ],
		] );
	}

	/**
	 * Order the agnosis_event archive chronologically by the event's own date
	 * meta (soonest first) rather than post publish date — a visitor browsing
	 * an artist's Events page wants to see what's coming up next, not what was
	 * emailed in most recently. Events with no recorded date sort last rather
	 * than being dropped from the list.
	 */
	public function order_events_archive( \WP_Query $q ): void {
		if ( ! $q->is_main_query() || is_admin() || ! $q->is_post_type_archive( 'agnosis_event' ) ) {
			return;
		}

		$q->set( 'meta_key', '_agnosis_event_date' );
		$q->set( 'orderby', [ 'meta_value' => 'ASC', 'date' => 'DESC' ] );
		// meta_key + orderby=>meta_value alone would silently exclude any event
		// with no _agnosis_event_date at all — the OR below keeps them in the
		// results (they just sort after every dated event, per 'orderby' above).
		$q->set( 'meta_query', [
			'relation' => 'OR',
			[ 'key' => '_agnosis_event_date', 'compare' => 'EXISTS' ],
			[ 'key' => '_agnosis_event_date', 'compare' => 'NOT EXISTS' ],
		] );
	}

	/**
	 * Scope an unscoped "agnosis_artwork" Query Loop block to the current
	 * artwork's own author, when rendered on a single artwork page.
	 *
	 * agnosis-theme's single-agnosis_artwork.html template has a "More works"
	 * Query Loop block (postType: agnosis_artwork, inherit: false, author: "")
	 * meant to show more of the SAME artist's own work. A non-inheriting Query
	 * Loop with no author configured queries every artwork from every artist
	 * site-wide instead — the exact "shows everyone's work instead of just
	 * this artist's" bug SubdomainRouter::scope_query() already fixes for the
	 * main query. That fix only touches is_main_query() though, and this
	 * Query Loop is deliberately a secondary query (inherit: false), so it
	 * needs its own. Also excludes the current artwork from its own "more
	 * works" list.
	 *
	 * Deliberately leaves any block alone that already has an explicit author
	 * set — this only corrects the specific "unscoped agnosis_artwork loop on
	 * an agnosis_artwork singular page" shape, not every Query Loop on the site.
	 *
	 * Hooked to: query_loop_block_query_vars (core filter, WP 6.1+)
	 *
	 * @param array<string, mixed> $query Query args the Query Loop block is about to run with.
	 * @return array<string, mixed>
	 */
	public function scope_more_works_query( array $query ): array {
		if ( ! is_singular( 'agnosis_artwork' ) ) {
			return $query;
		}

		$post_type       = $query['post_type'] ?? '';
		$is_artwork_loop = 'agnosis_artwork' === $post_type
			|| ( is_array( $post_type ) && in_array( 'agnosis_artwork', $post_type, true ) );

		if ( ! $is_artwork_loop || ! empty( $query['author'] ) ) {
			return $query;
		}

		$current_id = get_queried_object_id();
		$author_id  = (int) get_post_field( 'post_author', $current_id );

		if ( $author_id > 0 ) {
			$query['author'] = $author_id;
		}
		$query['post__not_in'] = array_merge( (array) ( $query['post__not_in'] ?? [] ), [ $current_id ] );

		return $query;
	}

	/**
	 * Register server-side-rendered blocks that surface CPT meta in FSE templates.
	 *
	 * agnosis/event-location — renders the _agnosis_event_location meta value for
	 * agnosis_event posts. Returns an empty string when the meta is unset so the
	 * block takes no space on events that have no location recorded yet.
	 */
	public function register_blocks(): void {
		register_block_type(
			'agnosis/event-location',
			[
				'render_callback' => [ $this, 'render_event_location' ],
				'uses_context'    => [ 'postId' ],
			]
		);

		// agnosis/event-date — renders the _agnosis_event_date meta value for
		// agnosis_event posts. The ISO 8601 value is formatted with WordPress's
		// configured date (and optionally time) format via date_i18n(). Returns an
		// empty string when the meta is unset so the block takes no space on events
		// that have no date recorded yet.
		register_block_type(
			'agnosis/event-date',
			[
				'render_callback' => [ $this, 'render_event_date' ],
				'uses_context'    => [ 'postId' ],
			]
		);

		// agnosis/event-address — renders the _agnosis_event_address meta value
		// (street address, distinct from the venue/city name event-location
		// renders — added 2026-07-10 alongside the event-timezone meta) for
		// agnosis_event posts. Same empty-string-takes-no-space convention as
		// event-location above.
		register_block_type(
			'agnosis/event-address',
			[
				'render_callback' => [ $this, 'render_event_address' ],
				'uses_context'    => [ 'postId' ],
			]
		);

		// agnosis/artwork-title — bilingual title block for single artwork pages.
		// Renders the artist's original title (post_title) as the primary <h1> and
		// the AI-generated site-language translation (_agnosis_translated_title meta)
		// as a styled subtitle.  Falls back to a plain <h1> when both strings are
		// identical (English artist on English site) or the meta is absent.
		register_block_type(
			'agnosis/artwork-title',
			[
				'render_callback' => [ $this, 'render_artwork_title' ],
				'uses_context'    => [ 'postId' ],
			]
		);

		// agnosis/biography-social-links — an icon row of the biography approve
		// form's portfolio link + up to three social links (_agnosis_biography_
		// portfolio_url, _agnosis_biography_social_url_1/2/3 — see
		// Publishing\ReviewConfirm). The platform for each URL is auto-detected
		// at render time (Publishing\SocialLinks::detect_service()) — nothing is
		// stored beyond the raw URLs, so there's no separate "service" value that
		// could ever drift out of sync with the link itself. Returns '' (no
		// space taken) when the biography has no links at all yet.
		register_block_type(
			'agnosis/biography-social-links',
			[
				'render_callback' => [ $this, 'render_biography_social_links' ],
				'uses_context'    => [ 'postId' ],
			]
		);
	}

	/**
	 * Render callback for the agnosis/event-location block.
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 * @return string HTML output or empty string when no location is set.
	 */
	public function render_event_location( array $attrs, string $content, \WP_Block $block ): string {
		$post_id  = (int) ( $block->context['postId'] ?? get_the_ID() );
		$location = trim( (string) get_post_meta( $post_id, '_agnosis_event_location', true ) );

		if ( ! $location && ! ContentEditor::is_editable_by_current_user( $post_id ) ) {
			return '';
		}

		// When the current viewer may correct this event (audit §7c/§7d Phase 1),
		// wrap the output in the same editable-region marker ContentEditor's
		// the_content/the_excerpt filters use, so one frontend.js module can find
		// every editable field on the page uniformly. An empty location still
		// renders (as an empty, click-to-fill region) for an eligible artist.
		if ( ContentEditor::is_editable_by_current_user( $post_id ) ) {
			return sprintf(
				'<p class="agnosis-event-location agnosis-editable" data-agnosis-edit-field="event_location" data-agnosis-post-id="%d" style="font-size:var(--wp--preset--font-size--small);font-weight:600;text-transform:uppercase;letter-spacing:0.06em;margin:0;">%s</p>',
				$post_id,
				esc_html( $location )
			);
		}

		return sprintf(
			'<p class="agnosis-event-location" style="font-size:var(--wp--preset--font-size--small);font-weight:600;text-transform:uppercase;letter-spacing:0.06em;margin:0;">%s</p>',
			esc_html( $location )
		);
	}

	/**
	 * Render callback for the agnosis/event-date block.
	 *
	 * Reads the ISO 8601 _agnosis_event_date meta and formats it using the site's
	 * configured date format (and time format when a time component is present).
	 * Returns an empty string when the meta is absent so the block takes no space.
	 *
	 * 2026-07-10: when `_agnosis_event_timezone` is also set (an IANA identifier —
	 * see Pipeline::extract_event_fields()), it's appended after the formatted
	 * date/time (e.g. "August 15, 2026 7:00 pm (Europe/Madrid)") so a visitor
	 * reading the event on a site whose own configured timezone differs from the
	 * event's isn't misled into thinking the displayed time is in their local/site
	 * timezone. Deliberately NOT converted into the site's own timezone — the
	 * stored date/time is exactly what the artist's email said, in the place the
	 * event actually happens; converting it would require assuming the artist
	 * wrote the time already in that IANA zone, which extract_event_fields() only
	 * infers, not guarantees.
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 * @return string HTML output or empty string when no date is set.
	 */
	public function render_event_date( array $attrs, string $content, \WP_Block $block ): string {
		$post_id    = (int) ( $block->context['postId'] ?? get_the_ID() );
		$event_date = trim( (string) get_post_meta( $post_id, '_agnosis_event_date', true ) );

		if ( ! $event_date ) {
			return '';
		}

		$timestamp = strtotime( $event_date );
		if ( false === $timestamp ) {
			return '';
		}

		// Include time when the stored value has a time component (i.e. contains 'T').
		$has_time  = str_contains( $event_date, 'T' );
		$formatted = $has_time
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp )
			: date_i18n( (string) get_option( 'date_format' ), $timestamp );

		$timezone = trim( (string) get_post_meta( $post_id, '_agnosis_event_timezone', true ) );
		if ( $has_time && '' !== $timezone ) {
			$formatted .= ' (' . $timezone . ')';
		}

		// Editable-region marker for the front-end correction overlay (audit §7c/§7d
		// Phase 1) — same convention as render_event_location() above.
		if ( ContentEditor::is_editable_by_current_user( $post_id ) ) {
			return sprintf(
				'<p class="agnosis-event-date agnosis-editable" data-agnosis-edit-field="event_date" data-agnosis-post-id="%d" style="font-size:var(--wp--preset--font-size--small);font-weight:600;text-transform:uppercase;letter-spacing:0.06em;margin:0;"><time datetime="%s">%s</time></p>',
				$post_id,
				esc_attr( $event_date ),
				esc_html( $formatted )
			);
		}

		return sprintf(
			'<p class="agnosis-event-date" style="font-size:var(--wp--preset--font-size--small);font-weight:600;text-transform:uppercase;letter-spacing:0.06em;margin:0;"><time datetime="%s">%s</time></p>',
			esc_attr( $event_date ),
			esc_html( $formatted )
		);
	}

	/**
	 * Render callback for the agnosis/event-address block.
	 *
	 * Mirrors render_event_location() exactly, reading `_agnosis_event_address`
	 * (the street address, distinct from the venue/city name `_agnosis_event_location`
	 * holds) instead. Added 2026-07-10 alongside the timezone meta.
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 * @return string HTML output or empty string when no address is set.
	 */
	public function render_event_address( array $attrs, string $content, \WP_Block $block ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$address = trim( (string) get_post_meta( $post_id, '_agnosis_event_address', true ) );

		if ( ! $address && ! ContentEditor::is_editable_by_current_user( $post_id ) ) {
			return '';
		}

		if ( ContentEditor::is_editable_by_current_user( $post_id ) ) {
			return sprintf(
				'<p class="agnosis-event-address agnosis-editable" data-agnosis-edit-field="event_address" data-agnosis-post-id="%d" style="font-size:var(--wp--preset--font-size--small);font-weight:400;margin:0;opacity:0.7;">%s</p>',
				$post_id,
				esc_html( $address )
			);
		}

		return sprintf(
			'<p class="agnosis-event-address" style="font-size:var(--wp--preset--font-size--small);font-weight:400;margin:0;opacity:0.7;">%s</p>',
			esc_html( $address )
		);
	}

	/**
	 * Render callback for the agnosis/artwork-title block.
	 *
	 * Outputs the artwork's canonical title (post_title, in the artist's language)
	 * as an <h1>.  When a site-language AI translation exists and differs from the
	 * original, it is rendered below as a smaller subtitle so visitors who do not
	 * speak the artist's language can still understand the work's name.
	 *
	 * Example output for a Chinese artwork on an English site:
	 *
	 *   <hgroup class="agnosis-artwork-title">
	 *     <h1 class="agnosis-artwork-title__original">秘密花園</h1>
	 *     <p  class="agnosis-artwork-title__translation">The Secret Garden</p>
	 *   </hgroup>
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 * @return string HTML output.
	 */
	public function render_artwork_title( array $attrs, string $content, \WP_Block $block ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return '';
		}

		$original    = trim( $post->post_title );
		$translation = trim( (string) get_post_meta( $post_id, '_agnosis_translated_title', true ) );

		// Dual-title editing (Phase 3, audit §7c/§7d): post_title is the artist's
		// own words — the only part of this block that's ever directly editable.
		// _agnosis_translated_title is a separate AI-generated value the artist
		// doesn't type into here; it's regenerated automatically (see
		// ContentEditor::propagate_title()) whenever the original title changes.
		$h1 = ContentEditor::is_editable_by_current_user( $post_id )
			? sprintf(
				'<h1 class="agnosis-artwork-title__original agnosis-editable" data-agnosis-edit-field="title" data-agnosis-post-id="%d" style="font-style:italic;font-weight:300;%s">%s</h1>',
				$post_id,
				'' === $translation || $translation === $original ? '' : 'margin:0 0 0.25em;',
				esc_html( $original )
			)
			: sprintf(
				'<h1 class="agnosis-artwork-title__original" style="font-style:italic;font-weight:300;%s">%s</h1>',
				'' === $translation || $translation === $original ? '' : 'margin:0 0 0.25em;',
				esc_html( $original )
			);

		// When both strings are identical (same language, no translation stored, or
		// translation not yet run), render a plain heading — no extra markup.
		if ( '' === $translation || $translation === $original ) {
			return $h1;
		}

		// Colour: NOT --wp--preset--color--secondary — that token is a ~6%-opacity
		// background wash (theme.json), not a text colour, and rendered this
		// translated title all but invisible against the dark artwork-page
		// header (reported 2026-07-07). The theme's own established pattern for
		// legible-but-muted text over that same dark header (post-date block,
		// breadcrumb, pagination — see agnosis-theme's single-agnosis_artwork.html
		// and style.css) is full-strength --foreground at reduced opacity, not a
		// separate low-alpha colour token. Using a higher opacity (0.75) than
		// those nav-ish elements (0.4-0.5) since this is actual translated
		// content — the work's official site-language title — not incidental
		// chrome, and should be comfortably readable, not merely present.
		// Font-size bumped one step (small -> medium) per the same report.
		return sprintf(
			'<hgroup class="agnosis-artwork-title" style="margin:0;">%s'
			. '<p class="agnosis-artwork-title__translation" style="margin:0.15em 0 0;font-size:var(--wp--preset--font-size--medium);color:var(--wp--preset--color--foreground);opacity:0.75;font-style:normal;">%s</p>'
			. '</hgroup>',
			$h1,
			esc_html( $translation )
		);
	}

	/**
	 * Render callback for the agnosis/biography-social-links block.
	 *
	 * Reads the portfolio link plus the three optional social links off the
	 * biography's own postmeta (all set once, at approval, by
	 * Publishing\ReviewConfirm — see that class's render_social_link_fields()/
	 * sync_social_links()), auto-detects each one's platform from its host,
	 * and renders the whole row through WordPress core's own Social Icons
	 * block (Publishing\SocialLinks::render_icon_row()) — real core icons and
	 * default styling, not a bespoke icon set. Portfolio link is included in
	 * the same row deliberately: it's just as much an outbound "find me
	 * elsewhere" link as the three social ones, only pre-existing.
	 *
	 * The portfolio link alone is gated on `_agnosis_biography_portfolio_embedded`
	 * ('1' only once Publishing\EmbedPolicy has approved it — see
	 * ReviewConfirm::sync_portfolio_embed()) — this is the one link an artist
	 * doesn't type directly into a trusted field of their own choosing, it's
	 * carried over from their admission application/email, so it stays
	 * subject to the same moderation gate that used to control whether it
	 * became an in-content embed before this row existed. The three
	 * `_social_url_*` fields are plain artist-entered outbound links with no
	 * such gate (see ReviewConfirm::sync_social_links()'s own docblock) and
	 * always render when set.
	 *
	 * No ContentEditor editable-region wrapper here (unlike
	 * render_event_location()/render_event_date() above) — the three social
	 * fields are reachable post-publish via ContentEditor's generic REST
	 * field-edit endpoint (Artist\ContentEditor::EDITABLE_FIELDS), but have no
	 * on-page click-to-edit affordance yet, same "backend-capable, no visual
	 * affordance yet" state event_timezone has had since 2026-07-10.
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 * @return string HTML output or empty string when no links are set.
	 */
	public function render_biography_social_links( array $attrs, string $content, \WP_Block $block ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );

		$portfolio_approved = '1' === (string) get_post_meta( $post_id, '_agnosis_biography_portfolio_embedded', true );

		$urls = [
			$portfolio_approved ? get_post_meta( $post_id, '_agnosis_biography_portfolio_url', true ) : '',
			get_post_meta( $post_id, '_agnosis_biography_social_url_1', true ),
			get_post_meta( $post_id, '_agnosis_biography_social_url_2', true ),
			get_post_meta( $post_id, '_agnosis_biography_social_url_3', true ),
		];

		return \Agnosis\Publishing\SocialLinks::render_icon_row( array_map( 'strval', $urls ) );
	}

	// -------------------------------------------------------------------------

	/** @return array<string, string> */
	private function artwork_labels(): array {
		return [
			'name'                  => __( 'Artworks',           'agnosis' ),
			'singular_name'         => __( 'Artwork',            'agnosis' ),
			'add_new'               => __( 'Add New',            'agnosis' ),
			'add_new_item'          => __( 'Add New Artwork',    'agnosis' ),
			'edit_item'             => __( 'Edit Artwork',       'agnosis' ),
			'new_item'              => __( 'New Artwork',        'agnosis' ),
			'view_item'             => __( 'View Artwork',       'agnosis' ),
			'search_items'          => __( 'Search Artworks',    'agnosis' ),
			'not_found'             => __( 'No artworks found.', 'agnosis' ),
			'not_found_in_trash'    => __( 'No artworks in trash.', 'agnosis' ),
			'all_items'             => __( 'All Artworks',       'agnosis' ),
			'archives'              => __( 'Artwork Archives',   'agnosis' ),
			'attributes'            => __( 'Artwork Attributes', 'agnosis' ),
			'menu_name'             => __( 'Artworks',           'agnosis' ),
			'name_admin_bar'        => __( 'Artwork',            'agnosis' ),
		];
	}
}
