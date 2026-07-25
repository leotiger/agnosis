<?php
/**
 * Integration tests for the generalized term-assignment propagation —
 * TAG-REDESIGN.md T3(c): `on_term_assignment_changed()` (renamed from
 * `on_medium_terms_changed()`) now covers both `post_tag` and
 * `agnosis_medium`, and `sync_term_assignment_to_siblings()`/
 * `sync_all_term_assignments()` (renamed from their medium-only
 * equivalents) take an explicit `$taxonomy` parameter with taxonomy-aware
 * post-type eligibility: `post_tag` spans every Agnosis CPT
 * (artwork/biography/event), `agnosis_medium` stays `agnosis_artwork`-only
 * (the only CPT the taxonomy is even registered on).
 *
 * Uses FakeLinguaForge (Support/FakeLinguaForge.php, backed by
 * Support/linguaforge-function-stubs.php's `linguaforge_get_translations()`,
 * required once in bootstrap.php for the whole suite) rather than the
 * Compat/Stubs pattern — the same fake Admin\ArtworkMediumSyncTest already
 * relies on for its own sibling-propagation coverage.
 *
 * @package Agnosis\Tests\Integration\Compat
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Compat;

use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;

class LinguaForgeTermPropagationTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			( new Profile() )->register_taxonomy();
		}

		FakeLinguaForge::reset();
		update_option( 'linguaforge_primary_language', 'en' );
	}

	protected function tearDown(): void {
		FakeLinguaForge::reset();
		delete_option( 'linguaforge_primary_language' );
		foreach ( get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false ] ) as $term ) {
			wp_delete_term( $term->term_id, 'post_tag' );
		}
		foreach ( get_terms( [ 'taxonomy' => 'agnosis_medium', 'hide_empty' => false ] ) as $term ) {
			wp_delete_term( $term->term_id, 'agnosis_medium' );
		}
		parent::tearDown();
	}

	private function make_post( string $post_type, string $lang ): int {
		$id = (int) wp_insert_post( [
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_title'  => 'Test Post',
		] );
		update_post_meta( $id, '_lf_lang', $lang );
		return $id;
	}

	// -------------------------------------------------------------------------
	// sync_term_assignment_to_siblings() — taxonomy-aware post-type scoping
	// -------------------------------------------------------------------------

	public function test_sync_term_assignment_to_siblings_pushes_tags_from_an_artwork(): void {
		$primary = $this->make_post( 'agnosis_artwork', 'en' );
		$sibling = $this->make_post( 'agnosis_artwork', 'de' );
		FakeLinguaForge::link( $primary, 'de', $sibling );
		wp_set_object_terms( $primary, [ 'Landscape' ], 'post_tag' );

		$synced = ( new LinguaForge() )->sync_term_assignment_to_siblings( $primary, 'post_tag' );

		$this->assertSame( 1, $synced );
		$sibling_terms = wp_get_post_terms( $sibling, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] );
		$this->assertSame( [ 'Landscape' ], $sibling_terms );
	}

	public function test_sync_term_assignment_to_siblings_pushes_tags_from_a_biography(): void {
		// post_tag spans every Agnosis CPT (TAG-REDESIGN.md soundness
		// review §8) — biography is not artwork-only like agnosis_medium.
		$primary = $this->make_post( 'agnosis_biography', 'en' );
		$sibling = $this->make_post( 'agnosis_biography', 'de' );
		FakeLinguaForge::link( $primary, 'de', $sibling );
		wp_set_object_terms( $primary, [ 'Retrospective' ], 'post_tag' );

		$synced = ( new LinguaForge() )->sync_term_assignment_to_siblings( $primary, 'post_tag' );

		$this->assertSame( 1, $synced );
		$this->assertSame(
			[ 'Retrospective' ],
			wp_get_post_terms( $sibling, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] )
		);
	}

	public function test_sync_term_assignment_to_siblings_is_a_no_op_for_medium_on_a_non_artwork_post_type(): void {
		// agnosis_medium is agnosis_artwork-only — the taxonomy isn't even
		// registered on biography/event, so this must no-op rather than error.
		$primary = $this->make_post( 'agnosis_biography', 'en' );

		$synced = ( new LinguaForge() )->sync_term_assignment_to_siblings( $primary, 'agnosis_medium' );

		$this->assertSame( 0, $synced );
	}

	public function test_sync_term_assignment_to_siblings_is_a_no_op_for_a_translated_source_post(): void {
		$primary = $this->make_post( 'agnosis_artwork', 'de' ); // Not the primary language.
		wp_set_object_terms( $primary, [ 'Landscape' ], 'post_tag' );

		$synced = ( new LinguaForge() )->sync_term_assignment_to_siblings( $primary, 'post_tag' );

		$this->assertSame( 0, $synced );
	}

	// -------------------------------------------------------------------------
	// sync_all_term_assignments() — taxonomy-aware bulk sweep
	// -------------------------------------------------------------------------

	public function test_sync_all_term_assignments_for_tags_sweeps_every_agnosis_cpt(): void {
		$artwork          = $this->make_post( 'agnosis_artwork', 'en' );
		$artwork_sibling  = $this->make_post( 'agnosis_artwork', 'de' );
		$biography        = $this->make_post( 'agnosis_biography', 'en' );
		$biography_sibling = $this->make_post( 'agnosis_biography', 'de' );
		FakeLinguaForge::link( $artwork, 'de', $artwork_sibling );
		FakeLinguaForge::link( $biography, 'de', $biography_sibling );
		wp_set_object_terms( $artwork, [ 'Landscape' ], 'post_tag' );
		wp_set_object_terms( $biography, [ 'Retrospective' ], 'post_tag' );

		$result = ( new LinguaForge() )->sync_all_term_assignments( 'post_tag' );

		$this->assertSame( 2, $result['posts'] );
		$this->assertSame( 2, $result['synced'] );
		$this->assertSame( [ 'Landscape' ], wp_get_post_terms( $artwork_sibling, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertSame( [ 'Retrospective' ], wp_get_post_terms( $biography_sibling, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ) );
	}

	public function test_sync_all_term_assignments_for_medium_sweeps_artwork_only(): void {
		$artwork   = $this->make_post( 'agnosis_artwork', 'en' );
		$biography = $this->make_post( 'agnosis_biography', 'en' );

		$result = ( new LinguaForge() )->sync_all_term_assignments( 'agnosis_medium' );

		$this->assertSame( 1, $result['posts'], 'Only the artwork post is eligible for agnosis_medium.' );
	}

	// -------------------------------------------------------------------------
	// on_term_assignment_changed() — the automatic set_object_terms listener
	// -------------------------------------------------------------------------

	public function test_on_term_assignment_changed_propagates_a_live_tag_edit_to_siblings(): void {
		new LinguaForge(); // Registers the set_object_terms listener.

		$primary = $this->make_post( 'agnosis_artwork', 'en' );
		$sibling = $this->make_post( 'agnosis_artwork', 'de' );
		FakeLinguaForge::link( $primary, 'de', $sibling );

		// The actual edit — triggers on_term_assignment_changed() via core's
		// set_object_terms action, which must propagate automatically.
		wp_set_object_terms( $primary, [ 'Landscape' ], 'post_tag' );

		$this->assertSame(
			[ 'Landscape' ],
			wp_get_post_terms( $sibling, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ),
			'A live tag edit on a primary-language post must automatically propagate to its translated siblings.'
		);
	}

	public function test_on_term_assignment_changed_still_propagates_medium(): void {
		// Regression coverage for the pre-existing (previously untested)
		// medium behavior on_term_assignment_changed() inherited from
		// on_medium_terms_changed() — T3(c) must not have narrowed this.
		new LinguaForge();

		$primary = $this->make_post( 'agnosis_artwork', 'en' );
		$sibling = $this->make_post( 'agnosis_artwork', 'de' );
		FakeLinguaForge::link( $primary, 'de', $sibling );

		wp_set_object_terms( $primary, [ 'Oil Painting' ], 'agnosis_medium' );

		$this->assertSame(
			[ 'Oil Painting' ],
			wp_get_post_terms( $sibling, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] )
		);
	}

	public function test_on_term_assignment_changed_ignores_unrelated_taxonomies(): void {
		new LinguaForge();

		$primary = $this->make_post( 'agnosis_artwork', 'en' );
		$sibling = $this->make_post( 'agnosis_artwork', 'de' );
		FakeLinguaForge::link( $primary, 'de', $sibling );

		wp_set_object_terms( $primary, [ 'Test Category' ], 'category' );

		$this->assertSame(
			[],
			wp_get_post_terms( $sibling, 'category', [ 'fields' => 'names', 'hide_empty' => false ] ),
			'A category change must never trigger propagation — only post_tag/agnosis_medium are trid-translation-eligible.'
		);
	}
}
