<?php
/**
 * Integration tests — Biography and Event CPT registration.
 *
 * Verifies that the two singleton CPTs added in 0.1.4 are registered with the
 * correct labels, rewrite slugs, and archive settings.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

class BiographyEventIntegrationTest extends \WP_UnitTestCase {

	// =========================================================================
	// agnosis_biography
	// =========================================================================

	public function test_biography_cpt_is_registered(): void {
		$this->assertTrue(
			post_type_exists( 'agnosis_biography' ),
			'agnosis_biography CPT must be registered.'
		);
	}

	public function test_biography_cpt_has_no_archive(): void {
		$obj = get_post_type_object( 'agnosis_biography' );

		$this->assertNotNull( $obj );
		$this->assertFalse(
			(bool) $obj->has_archive,
			'agnosis_biography must not expose a list archive — it is a singleton per artist.'
		);
	}

	public function test_biography_cpt_is_public(): void {
		$obj = get_post_type_object( 'agnosis_biography' );

		$this->assertNotNull( $obj );
		$this->assertTrue( $obj->public );
	}

	public function test_biography_cpt_is_rest_enabled(): void {
		$obj = get_post_type_object( 'agnosis_biography' );

		$this->assertNotNull( $obj );
		$this->assertTrue( $obj->show_in_rest );
	}

	public function test_biography_cpt_rewrite_slug_is_biography(): void {
		$obj = get_post_type_object( 'agnosis_biography' );

		$this->assertNotNull( $obj );
		$this->assertIsArray( $obj->rewrite );
		$this->assertSame( 'biography', $obj->rewrite['slug'] );
	}

	public function test_biography_cpt_supports_title_and_editor(): void {
		foreach ( [ 'title', 'editor' ] as $feature ) {
			$this->assertTrue(
				post_type_supports( 'agnosis_biography', $feature ),
				"agnosis_biography should support '$feature'."
			);
		}
	}

	public function test_can_insert_biography_post(): void {
		$artist  = self::factory()->user->create();
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'publish',
			'post_title'  => 'My artistic journey',
			'post_author' => $artist,
		] );

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 'agnosis_biography', get_post_type( $post_id ) );
	}

	// =========================================================================
	// agnosis_event
	// =========================================================================

	public function test_event_cpt_is_registered(): void {
		$this->assertTrue(
			post_type_exists( 'agnosis_event' ),
			'agnosis_event CPT must be registered.'
		);
	}

	public function test_event_cpt_has_no_archive(): void {
		$obj = get_post_type_object( 'agnosis_event' );

		$this->assertNotNull( $obj );
		$this->assertFalse(
			(bool) $obj->has_archive,
			'agnosis_event must not expose a list archive — it is a singleton per artist.'
		);
	}

	public function test_event_cpt_is_public(): void {
		$obj = get_post_type_object( 'agnosis_event' );

		$this->assertNotNull( $obj );
		$this->assertTrue( $obj->public );
	}

	public function test_event_cpt_is_rest_enabled(): void {
		$obj = get_post_type_object( 'agnosis_event' );

		$this->assertNotNull( $obj );
		$this->assertTrue( $obj->show_in_rest );
	}

	public function test_event_cpt_rewrite_slug_is_events(): void {
		$obj = get_post_type_object( 'agnosis_event' );

		$this->assertNotNull( $obj );
		$this->assertIsArray( $obj->rewrite );
		$this->assertSame( 'events', $obj->rewrite['slug'] );
	}

	public function test_event_cpt_supports_title_and_editor(): void {
		foreach ( [ 'title', 'editor' ] as $feature ) {
			$this->assertTrue(
				post_type_supports( 'agnosis_event', $feature ),
				"agnosis_event should support '$feature'."
			);
		}
	}

	public function test_can_insert_event_post(): void {
		$artist  = self::factory()->user->create();
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_title'  => 'Solo exhibition — Gallery X',
			'post_author' => $artist,
		] );

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 'agnosis_event', get_post_type( $post_id ) );
	}

	// =========================================================================
	// Singleton intent — one post per artist per type
	// =========================================================================

	public function test_two_biography_posts_for_same_artist_are_both_insertable(): void {
		// The DB itself does not enforce singleton — PostCreator does at application
		// level via find_singleton_post(). This test documents that the CPT does not
		// add a DB-level uniqueness constraint (i.e. the constraint is intentionally
		// enforced in code, not the schema).
		$artist = self::factory()->user->create();

		$id1 = wp_insert_post( [ 'post_type' => 'agnosis_biography', 'post_status' => 'publish', 'post_title' => 'Bio v1', 'post_author' => $artist ] );
		$id2 = wp_insert_post( [ 'post_type' => 'agnosis_biography', 'post_status' => 'publish', 'post_title' => 'Bio v2', 'post_author' => $artist ] );

		$this->assertGreaterThan( 0, $id1 );
		$this->assertGreaterThan( 0, $id2 );
		$this->assertNotSame( $id1, $id2 );
		// Document: application code (PostCreator::find_singleton_post) is
		// responsible for merging, not the CPT registration.
	}
}
