<?php
/**
 * Integration tests for Publishing\TagGate::vocabulary_map() — the one
 * method on that class that touches the real WordPress term API
 * (taxonomy_exists()/get_terms()), so it lives here rather than under
 * Unit/ alongside normalize_for_match()/is_junk() (see TagGateTest).
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Compat\LinguaForge;
use Agnosis\Publishing\TagGate;

class TagGateVocabularyTest extends \WP_UnitTestCase {

	protected function tearDown(): void {
		foreach ( get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false ] ) as $term ) {
			wp_delete_term( $term->term_id, 'post_tag' );
		}
		parent::tearDown();
	}

	public function test_vocabulary_map_is_empty_on_a_fresh_taxonomy(): void {
		$this->assertSame( [], TagGate::vocabulary_map() );
	}

	public function test_vocabulary_map_keys_by_normalized_name(): void {
		$inserted = wp_insert_term( 'Oil Painting', 'post_tag' );

		$map = TagGate::vocabulary_map();

		$this->assertSame( $inserted['term_id'], $map['oil painting'] ?? null );
	}

	public function test_vocabulary_map_excludes_translated_sibling_terms(): void {
		wp_insert_term( 'Sunset', 'post_tag' );
		$translated = wp_insert_term( 'Coucher de soleil', 'post_tag' );
		add_term_meta( $translated['term_id'], LinguaForge::TRANSLATED_TERM_META, 'fr', true );

		$map = TagGate::vocabulary_map();

		$this->assertArrayHasKey( 'sunset', $map );
		$this->assertArrayNotHasKey( 'coucher de soleil', $map );
	}

	public function test_vocabulary_map_matches_regardless_of_stored_display_casing(): void {
		$inserted = wp_insert_term( 'Photography', 'post_tag' );

		$map = TagGate::vocabulary_map();

		// A caller normalizing an AI-proposed "photography" (lowercase) must
		// still find the stored "Photography" (TW-9's own reported failure).
		$this->assertSame( $inserted['term_id'], $map[ TagGate::normalize_for_match( 'photography' ) ] ?? null );
	}
}
