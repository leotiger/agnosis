<?php
/**
 * Integration tests — custom post type and taxonomy registration.
 *
 * Verifies that the Agnosis plugin registers its CPT and taxonomy correctly
 * when active inside a real WordPress environment.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

class ProfileIntegrationTest extends \WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// CPT — agnosis_artwork
	// -------------------------------------------------------------------------

	public function test_artwork_cpt_is_registered(): void {
		$this->assertTrue(
			post_type_exists( 'agnosis_artwork' ),
			'agnosis_artwork CPT should be registered.'
		);
	}

	public function test_artwork_cpt_is_public(): void {
		$obj = get_post_type_object( 'agnosis_artwork' );

		$this->assertNotNull( $obj );
		$this->assertTrue( $obj->public );
	}

	public function test_artwork_cpt_is_rest_enabled(): void {
		$obj = get_post_type_object( 'agnosis_artwork' );

		$this->assertNotNull( $obj );
		$this->assertTrue( $obj->show_in_rest );
	}

	public function test_artwork_cpt_has_gallery_archive(): void {
		$obj = get_post_type_object( 'agnosis_artwork' );

		$this->assertNotNull( $obj );
		$this->assertSame( 'gallery', $obj->has_archive );
	}

	public function test_artwork_cpt_rewrite_slug_is_art(): void {
		$obj = get_post_type_object( 'agnosis_artwork' );

		$this->assertNotNull( $obj );
		$this->assertIsArray( $obj->rewrite );
		$this->assertSame( 'art', $obj->rewrite['slug'] );
	}

	public function test_artwork_cpt_supports_required_features(): void {
		$obj = get_post_type_object( 'agnosis_artwork' );

		$this->assertNotNull( $obj );
		foreach ( [ 'title', 'editor', 'thumbnail', 'excerpt', 'author' ] as $feature ) {
			$this->assertTrue(
				post_type_supports( 'agnosis_artwork', $feature ),
				"agnosis_artwork should support '$feature'."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Taxonomy — agnosis_medium
	// -------------------------------------------------------------------------

	public function test_medium_taxonomy_is_registered(): void {
		$this->assertTrue(
			taxonomy_exists( 'agnosis_medium' ),
			'agnosis_medium taxonomy should be registered.'
		);
	}

	public function test_medium_taxonomy_is_hierarchical(): void {
		$obj = get_taxonomy( 'agnosis_medium' );

		$this->assertNotFalse( $obj );
		$this->assertTrue( $obj->hierarchical );
	}

	public function test_medium_taxonomy_is_rest_enabled(): void {
		$obj = get_taxonomy( 'agnosis_medium' );

		$this->assertNotFalse( $obj );
		$this->assertTrue( $obj->show_in_rest );
	}

	public function test_medium_taxonomy_is_linked_to_artwork_cpt(): void {
		$obj = get_taxonomy( 'agnosis_medium' );

		$this->assertNotFalse( $obj );
		$this->assertContains( 'agnosis_artwork', (array) $obj->object_type );
	}

	// -------------------------------------------------------------------------
	// Smoke test — create an artwork post
	// -------------------------------------------------------------------------

	public function test_can_insert_artwork_post(): void {
		$artist = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$post_id = wp_insert_post( [
			'post_type'    => 'agnosis_artwork',
			'post_status'  => 'publish',
			'post_title'   => 'Still Life at Dusk',
			'post_excerpt' => 'A quiet meditation on transience.',
			'post_author'  => $artist,
		] );

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 'agnosis_artwork', get_post_type( $post_id ) );
	}
}
