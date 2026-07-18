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

	// -------------------------------------------------------------------------
	// agnosis_medium term meta — "sensitive by default" (audit §3f)
	// -------------------------------------------------------------------------

	/**
	 * Operator lever, one half of audit §3f's `sensitive` decision: flag a
	 * whole medium (e.g. one an operator uses for explicit work) so every
	 * artwork under it federates with AS2 `sensitive: true` + a content
	 * warning, without the artist needing to flag each piece individually
	 * (see ContentEditor::save_sensitive() for the artist-facing per-artwork
	 * half; ActivityPub::is_post_sensitive() reads either). Classic Edit Tags
	 * screen only — the block editor's own inline taxonomy REST calls don't
	 * post classic form fields, so this checkbox is reachable from
	 * wp-admin → Artworks → Mediums, not from a post's own sidebar.
	 *
	 * WordPress renders the Add and Edit term forms with different wrapper
	 * markup ({$taxonomy}_add_form_fields is a bare div, {$taxonomy}_edit_form_fields
	 * is a table row), so this is two thin wrappers around one shared field
	 * renderer, keeping the actual field markup identical on both screens.
	 */
	public function render_sensitive_add_field(): void {
		?>
		<div class="form-field term-agnosis-sensitive-wrap">
			<?php $this->render_sensitive_field_markup( false ); ?>
		</div>
		<?php
	}

	/**
	 * @param \WP_Term $term The term currently being edited.
	 */
	public function render_sensitive_edit_field( \WP_Term $term ): void {
		$checked = (bool) get_term_meta( $term->term_id, '_agnosis_medium_sensitive', true );
		?>
		<tr class="form-field term-agnosis-sensitive-wrap">
			<th scope="row"><?php esc_html_e( 'Sensitive by default', 'agnosis' ); ?></th>
			<td><?php $this->render_sensitive_field_markup( $checked ); ?></td>
		</tr>
		<?php
	}

	private function render_sensitive_field_markup( bool $checked ): void {
		?>
		<label for="agnosis-medium-sensitive">
			<input type="checkbox" name="agnosis_medium_sensitive" id="agnosis-medium-sensitive" value="1" <?php checked( $checked ); ?> />
			<?php esc_html_e( 'Mark artworks under this medium as sensitive content by default', 'agnosis' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Federated posts under this medium will carry a content warning on the Fediverse (e.g. Mastodon) unless the artist turns it off for a specific piece.', 'agnosis' ); ?></p>
		<?php
	}

	/**
	 * Save the sensitive-by-default flag from either the Add or Edit Medium
	 * form. Hooked to both created_agnosis_medium and edited_agnosis_medium —
	 * WordPress core's own edit-tags.php ("editedtag") and add-tag admin
	 * actions already verify their own nonce (update-tag_$id /
	 * add-tag) and the manage_categories-family capability before either
	 * hook fires, so this handler only needs to read the checkbox value.
	 *
	 * @param int $term_id Term being saved.
	 */
	public function save_sensitive_term_meta( int $term_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified upstream by WP core's own add-tag/editedtag admin actions before created_/edited_{taxonomy} fires; see docblock.
		$sensitive = ! empty( $_POST['agnosis_medium_sensitive'] );

		if ( $sensitive ) {
			update_term_meta( $term_id, '_agnosis_medium_sensitive', '1' );
		} else {
			delete_term_meta( $term_id, '_agnosis_medium_sensitive' );
		}
	}

	/**
	 * Order the agnosis_event archive chronologically by the event's own date
	 * meta (soonest first) rather than post publish date — a visitor browsing
	 * an artist's Events page wants to see what's coming up next, not what was
	 * emailed in most recently. Events with no recorded date sort last rather
	 * than being dropped from the list.
	 *
	 * Merges into whatever meta_query already exists rather than replacing it
	 * (0.9.24 fix) — this used to `$q->set('meta_query', …)` outright, which
	 * silently discarded Lingua Forge's own `_lf_lang` clause
	 * (QueryFilter::handle_pre_get_posts(), also hooked on `pre_get_posts` for
	 * the exact same is_archive() case) whenever that filter happened to run
	 * first. Agnosis's own `pre_get_posts` registration (Core\Plugin.php) is
	 * wired up after Lingua Forge's — LF is a hard dependency and loads first
	 * — so on every request this handler ran second and clobbered the
	 * language scoping outright, and every language's events piled up
	 * together on one archive page instead of just the current one's. Nesting
	 * the pre-existing meta_query as its own AND-ed group alongside the
	 * OR-relation date clause below preserves it (and anything else a future
	 * filter adds) regardless of hook registration order, rather than
	 * depending on this callback always running first.
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
		$date_clause = [
			'relation' => 'OR',
			[ 'key' => '_agnosis_event_date', 'compare' => 'EXISTS' ],
			[ 'key' => '_agnosis_event_date', 'compare' => 'NOT EXISTS' ],
		];

		$existing = (array) $q->get( 'meta_query' );
		$q->set( 'meta_query', empty( $existing ) ? $date_clause : [ 'relation' => 'AND', $existing, $date_clause ] );
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

		// agnosis/event-title (0.9.24) — same bilingual title treatment as
		// agnosis/artwork-title above, ported to events: an event's own name
		// (e.g. an exhibition or show title an artist gave in their own
		// language) is kept verbatim on every language version of the page —
		// never machine-translated — with the AI-generated site-language
		// translation shown as a styled subtitle underneath. See
		// Compat\LinguaForge::hold_artist_title()'s docblock for why events
		// moved off LF's normal per-sibling title translation onto this same
		// dual-title path artwork has always used.
		register_block_type(
			'agnosis/event-title',
			[
				'render_callback' => [ $this, 'render_event_title' ],
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

		// agnosis/artwork-copyright (0.9.37) — "© {year} {artist name}" credit
		// line for single artwork pages. Unlike the bare-registered blocks
		// above, this one ships a real blocks/artwork-copyright/block.json (with
		// editorScript + Color/Typography/Spacing supports) so an admin can
		// configure font size, color, and family per-instance from the block's
		// own Inspector panel, same directory-registration pattern
		// Network\SubdomainNavigation uses for artist-name-link/breadcrumb-icon-
		// link. `usesContext` still resolves the post the same way its
		// bare-registered siblings here do, since it belongs inside the artwork
		// template's Post Template, not a subdomain header.
		register_block_type(
			\AGNOSIS_DIR . 'blocks/artwork-copyright',
			[ 'render_callback' => [ $this, 'render_artwork_copyright' ] ]
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

		// Color: NOT --wp--preset--color--secondary — that token is a ~6%-opacity
		// background wash (theme.json), not a text color, and rendered this
		// translated title all but invisible against the dark artwork-page
		// header (reported 2026-07-07). The theme's own established pattern for
		// legible-but-muted text over that same dark header (post-date block,
		// breadcrumb, pagination — see agnosis-theme's single-agnosis_artwork.html
		// and style.css) is full-strength --foreground at reduced opacity, not a
		// separate low-alpha color token. Using a higher opacity (0.75) than
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
	 * Render callback for the agnosis/event-title block (0.9.24).
	 *
	 * Mirrors render_artwork_title() exactly, for the same dual-title reason:
	 * an event's own name is the artist's own words (post_title, never
	 * machine-translated on any language version — see
	 * Compat\LinguaForge::hold_artist_title()) shown as an <h1>, with the
	 * AI-generated site-language translation (_agnosis_translated_title meta)
	 * rendered below as a smaller subtitle when it differs from the
	 * original. Before 0.9.24, an event's post_title was instead translated
	 * outright per language sibling (Lingua Forge's normal behavior) — this
	 * block is the front-end half of moving events onto the same dual-title
	 * path artwork has always used.
	 *
	 * Example output for a Portuguese event on an English site:
	 *
	 *   <hgroup class="agnosis-event-title">
	 *     <h1 class="agnosis-event-title__original">Cruzando o Limiar</h1>
	 *     <p  class="agnosis-event-title__translation">Crossing the Threshold</p>
	 *   </hgroup>
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 * @return string HTML output.
	 */
	public function render_event_title( array $attrs, string $content, \WP_Block $block ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return '';
		}

		$original    = trim( $post->post_title );
		$translation = trim( (string) get_post_meta( $post_id, '_agnosis_translated_title', true ) );

		// Dual-title editing: post_title is the artist's own words — the only
		// part of this block that's ever directly editable. _agnosis_translated_title
		// is a separate AI-generated value the artist doesn't type into here;
		// it's regenerated automatically (see ContentEditor::propagate_title())
		// whenever the original title changes.
		$h1 = ContentEditor::is_editable_by_current_user( $post_id )
			? sprintf(
				'<h1 class="agnosis-event-title__original agnosis-editable" data-agnosis-edit-field="title" data-agnosis-post-id="%d" style="font-style:italic;font-weight:300;%s">%s</h1>',
				$post_id,
				'' === $translation || $translation === $original ? '' : 'margin:0 0 0.25em;',
				esc_html( $original )
			)
			: sprintf(
				'<h1 class="agnosis-event-title__original" style="font-style:italic;font-weight:300;%s">%s</h1>',
				'' === $translation || $translation === $original ? '' : 'margin:0 0 0.25em;',
				esc_html( $original )
			);

		// When both strings are identical (same language, no translation stored, or
		// translation not yet run), render a plain heading — no extra markup.
		if ( '' === $translation || $translation === $original ) {
			return $h1;
		}

		// Same color/opacity/font-size choice as render_artwork_title() — see
		// that method's own comment for why (legible-but-muted foreground at
		// 0.75 opacity, not the low-alpha --secondary background wash token).
		return sprintf(
			'<hgroup class="agnosis-event-title" style="margin:0;">%s'
			. '<p class="agnosis-event-title__translation" style="margin:0.15em 0 0;font-size:var(--wp--preset--font-size--medium);color:var(--wp--preset--color--foreground);opacity:0.75;font-style:normal;">%s</p>'
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

	/**
	 * Render callback for the agnosis/artwork-copyright block (0.9.37).
	 *
	 * Outputs "© {year} {artist name}" — the correct, gallery-standard way to
	 * credit an artwork's copyright holder. `{year}` is the artwork's own
	 * publish date (get_the_date()), not the current year: a copyright notice
	 * dates the work itself, and re-rendering "© 2027" on a piece actually
	 * published in 2026 would misstate when the work first entered publication.
	 * `{artist name}` is resolved from post_author the same way
	 * Network\SubdomainNavigation::artist_name() resolves it elsewhere in the
	 * plugin (display_name, falling back to user_nicename) — kept as a small
	 * private helper here rather than reusing that method, since this class
	 * has no dependency on Network\SubdomainNavigation and the two blocks
	 * resolve an artist from different starting points (subdomain vs.
	 * post_author).
	 *
	 * Renders nothing off an agnosis_artwork post, and nothing when the
	 * artwork's author account no longer resolves to a user (e.g. deleted),
	 * same "empty string takes no space" convention as this class's other
	 * bare-registered blocks above.
	 *
	 * Color/Typography/Spacing are ordinary block *supports* (block.json), so
	 * get_block_wrapper_attributes() alone carries whatever the Inspector's
	 * standard panels picked — no custom attributes to read.
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 * @return string HTML output or empty string when not applicable.
	 */
	public function render_artwork_copyright( array $attrs, string $content, \WP_Block $block ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );

		if ( ! $post || 'agnosis_artwork' !== $post->post_type ) {
			return '';
		}

		$name = $this->artist_display_name( (int) $post->post_author );

		if ( '' === $name ) {
			return '';
		}

		$year = get_the_date( 'Y', $post_id );

		$wrapper_attributes = get_block_wrapper_attributes();

		return sprintf(
			'<p %s>%s</p>',
			$wrapper_attributes,
			esc_html(
				sprintf(
					/* translators: 1: publish year, 2: artist name */
					__( '© %1$s %2$s', 'agnosis' ),
					$year,
					$name
				)
			)
		);
	}

	/**
	 * Resolves an artist's display name from a user ID.
	 *
	 * Same display_name-with-user_nicename-fallback convention as
	 * Network\SubdomainNavigation::artist_name() — kept local to this class
	 * (see render_artwork_copyright()'s docblock for why) rather than shared.
	 *
	 * @param int $author_id WP_User ID (post_author).
	 * @return string Display name, or '' if the user no longer resolves.
	 */
	private function artist_display_name( int $author_id ): string {
		if ( $author_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $author_id );

		return $user ? (string) ( $user->display_name ?: $user->user_nicename ) : '';
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
