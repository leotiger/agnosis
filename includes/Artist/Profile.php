<?php
/**
 * Artist profile — custom post type + taxonomy registration.
 *
 * agnosis_artwork   — artwork submissions (default, one post per artwork).
 * agnosis_biography — artist biography (singleton per artist, always updated).
 * agnosis_event     — artist events page (singleton per artist, always updated).
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
			'supports'            => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields' ],
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
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields' ],
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
			'has_archive'        => false, // one per artist — no archive needed
			'hierarchical'       => false,
			'menu_position'      => 7,
			'menu_icon'          => 'dashicons-calendar-alt',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields' ],
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

		if ( ! $location ) {
			return '';
		}

		return sprintf(
			'<p class="agnosis-event-location" style="font-size:var(--wp--preset--font-size--small);font-weight:600;text-transform:uppercase;letter-spacing:0.06em;margin:0;">%s</p>',
			esc_html( $location )
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

		// When both strings are identical (same language, no translation stored, or
		// translation not yet run), render a plain heading — no extra markup.
		if ( '' === $translation || $translation === $original ) {
			return sprintf(
				'<h1 class="agnosis-artwork-title__original" style="font-style:italic;font-weight:300;">%s</h1>',
				esc_html( $original )
			);
		}

		return sprintf(
			'<hgroup class="agnosis-artwork-title" style="margin:0;">'
			. '<h1 class="agnosis-artwork-title__original" style="font-style:italic;font-weight:300;margin:0 0 0.25em;">%s</h1>'
			. '<p class="agnosis-artwork-title__translation" style="margin:0;font-size:var(--wp--preset--font-size--small);color:var(--wp--preset--color--secondary);font-style:normal;">%s</p>'
			. '</hgroup>',
			esc_html( $original ),
			esc_html( $translation )
		);
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
