<?php
/**
 * Artist profile — custom post type + taxonomy registration.
 *
 * agnosis_artwork  — the artwork post type.
 * agnosis_medium   — taxonomy: painting, photography, sculpture, digital, etc.
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
