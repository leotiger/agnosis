<?php
/**
 * Integration tests for Publishing\TagGate::associate() — the shared
 * gate → match → assign-by-ID → proposal-rows implementation
 * ReviewEndpoints::finalize_tags() v2 and Publishing\Retag::run() both call
 * (invariant 8: no parallel reimplementation). Exercises TAG-REDESIGN.md
 * T2's own acceptance criteria directly: "approval of a submission whose
 * proposals include an existing tag (matched, associated by ID), a junk
 * candidate (gated, gone), and a new name (proposal row, post publishes
 * without it)."
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\TagGate;

class TagGateAssociateTest extends \WP_UnitTestCase {

	private int $artist_id;
	private int $post_id;

	protected function setUp(): void {
		parent::setUp();

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->post_id   = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Test Artwork',
		] );
	}

	protected function tearDown(): void {
		foreach ( get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false ] ) as $term ) {
			wp_delete_term( $term->term_id, 'post_tag' );
		}
		parent::tearDown();
	}

	public function test_matched_junk_and_new_candidates_are_sorted_correctly(): void {
		$existing = wp_insert_term( 'Sunset', 'post_tag' );

		$result = TagGate::associate( $this->post_id, [ 'Sunset', '42', 'Golden Hour' ] );

		$this->assertSame( 1, $result['matched'] );
		$this->assertSame( 1, $result['proposed'] );
		$this->assertSame( 1, $result['gated'] ); // '42' — a bare number outside the plausible-year range.

		$this->assertSame(
			[ $existing['term_id'] ],
			wp_get_post_terms( $this->post_id, 'post_tag', [ 'fields' => 'ids', 'hide_empty' => false ] )
		);
		$this->assertFalse( get_term_by( 'name', 'Golden Hour', 'post_tag' ), 'A new candidate must never create a term directly — only a proposal row (invariant 1).' );
		// Proposal rows keep the candidate's own trimmed display casing
		// (TagGate::associate()'s $new bucket stores trim($candidate), not
		// normalize_for_match($candidate)) — an admin reviewing the Tags
		// screen should see "Golden Hour", not a lowercased mangling of it.
		$this->assertSame( [ 'Golden Hour' ], get_post_meta( $this->post_id, TagGate::PROPOSAL_META, false ) );
	}

	public function test_matched_candidate_is_associated_by_id_never_by_name(): void {
		$existing = wp_insert_term( 'Photography', 'post_tag' );

		// Deliberately different casing/whitespace than the stored term —
		// TW-9: association must still succeed via normalize_for_match(),
		// and the assignment itself must be wp_set_object_terms() with the
		// term ID, never the raw candidate string.
		TagGate::associate( $this->post_id, [ '  photography  ' ] );

		$this->assertSame(
			[ $existing['term_id'] ],
			wp_get_post_terms( $this->post_id, 'post_tag', [ 'fields' => 'ids', 'hide_empty' => false ] )
		);
	}

	public function test_replaces_rather_than_appends_matched_tags(): void {
		$first  = wp_insert_term( 'Oil Painting', 'post_tag' );
		$second = wp_insert_term( 'Watercolor', 'post_tag' );

		TagGate::associate( $this->post_id, [ 'Oil Painting' ] );
		TagGate::associate( $this->post_id, [ 'Watercolor' ] );

		$this->assertSame(
			[ $second['term_id'] ],
			wp_get_post_terms( $this->post_id, 'post_tag', [ 'fields' => 'ids', 'hide_empty' => false ] ),
			'A second associate() call must REPLACE the assignment, not append to it — required for both an ordinary re-approval and Retag ("assign by ID, replace not append").'
		);
	}

	public function test_a_second_identical_run_converges_to_the_same_state(): void {
		$existing = wp_insert_term( 'Sunset', 'post_tag' );

		TagGate::associate( $this->post_id, [ 'Sunset', 'Harbor' ] );
		$second = TagGate::associate( $this->post_id, [ 'Sunset', 'Harbor' ] );

		// Idempotence (invariant 6) — the second run's own counts, and the
		// resulting state, must be identical to the first: no duplicate
		// proposal rows, matched set unchanged.
		$this->assertSame( 1, $second['matched'] );
		$this->assertSame( 1, $second['proposed'] );
		$this->assertSame(
			[ $existing['term_id'] ],
			wp_get_post_terms( $this->post_id, 'post_tag', [ 'fields' => 'ids', 'hide_empty' => false ] )
		);
		$this->assertSame( [ 'Harbor' ], get_post_meta( $this->post_id, TagGate::PROPOSAL_META, false ), 'A re-run must not leave duplicate proposal rows for the same still-unmatched name.' );
	}

	public function test_new_proposal_replaces_this_posts_earlier_pending_proposals(): void {
		TagGate::associate( $this->post_id, [ 'Old Idea' ] );
		TagGate::associate( $this->post_id, [ 'New Idea' ] );

		$this->assertSame( [ 'New Idea' ], get_post_meta( $this->post_id, TagGate::PROPOSAL_META, false ), 'A re-tag/re-approval supersedes its own earlier candidates.' );
	}

	public function test_caps_total_accepted_candidates_at_tag_count_with_matched_taking_precedence(): void {
		update_option( 'agnosis_prompt_tag_count', 2 );

		$a = wp_insert_term( 'Alpha', 'post_tag' );
		$b = wp_insert_term( 'Beta', 'post_tag' );

		$result = TagGate::associate( $this->post_id, [ 'Alpha', 'Beta', 'Gamma' ] );

		$this->assertSame( 2, $result['matched'] );
		$this->assertSame( 0, $result['proposed'], 'The cap leaves no budget for new proposals once matched names fill it.' );
		$this->assertSame( 1, $result['gated'] );

		$assigned = wp_get_post_terms( $this->post_id, 'post_tag', [ 'fields' => 'ids', 'hide_empty' => false ] );
		sort( $assigned );
		$expected = [ $a['term_id'], $b['term_id'] ];
		sort( $expected );
		$this->assertSame( $expected, $assigned );

		delete_option( 'agnosis_prompt_tag_count' );
	}

	public function test_empty_matched_set_is_a_valid_clearing_call_not_skipped(): void {
		wp_insert_term( 'Something', 'post_tag' );
		TagGate::associate( $this->post_id, [ 'Something' ] );

		// Every candidate this pass is junk — a genuinely different result
		// than "no candidates at all" (which callers must not even reach
		// this method for) — must clear the post's existing tags.
		$result = TagGate::associate( $this->post_id, [ '42', 'x' ] );

		$this->assertSame( 0, $result['matched'] );
		$this->assertSame(
			[],
			wp_get_post_terms( $this->post_id, 'post_tag', [ 'fields' => 'ids', 'hide_empty' => false ] )
		);
	}
}
