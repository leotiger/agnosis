<?php
/**
 * Integration tests for Admin\MediumProposals (2026-07-21) — the review queue
 * for AI-proposed medium categories that didn't match the live agnosis_medium
 * vocabulary at classification time (see PostCreatorMediumTermTest and
 * ReviewEndpoints' finalize_publish() coverage for the write-side of this same
 * fix — every classification path now records `_agnosis_medium_proposal`
 * instead of silently discarding a non-matching value).
 *
 * Exercises approve_proposal()/reject_proposal()/get_proposals() directly via
 * reflection rather than the public handle_approve()/handle_reject()
 * admin_post handlers — both of those end with wp_safe_redirect() + exit;,
 * the same terminal pattern every other admin_post handler in this codebase
 * uses (QueueController, TaxonomyLanguageFilter), and none of those have
 * existing test coverage either for the same reason: calling a method that
 * ends in a real `exit;` would kill the entire PHPUnit process. The three
 * private methods were pulled out specifically so the actual business logic
 * (term creation/reuse, per-post assignment, meta clearing) could be tested
 * without that problem — see MediumProposals::approve_proposal()'s own
 * docblock.
 *
 * maybe_render_notice()'s get_current_screen() gate is intentionally NOT
 * covered here — no existing test in this codebase exercises set_current_screen()
 * either, and the display logic itself is a thin wrapper around
 * get_proposals() (covered below) plus static markup.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\MediumProposals;
use Agnosis\Artist\Profile;

class MediumProposalsTest extends \WP_UnitTestCase {

	private int $artist_id;
	private MediumProposals $controller;

	protected function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			( new Profile() )->register_taxonomy();
		}

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->controller = new MediumProposals();
	}

	protected function tearDown(): void {
		if ( taxonomy_exists( 'agnosis_medium' ) ) {
			foreach ( get_terms( [ 'taxonomy' => 'agnosis_medium', 'hide_empty' => false ] ) as $term ) {
				wp_delete_term( $term->term_id, 'agnosis_medium' );
			}
		}
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_artwork_with_proposal( string $proposal, string $title = 'Test Artwork' ): int {
		$post_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => $title,
		] );
		update_post_meta( $post_id, '_agnosis_medium_proposal', $proposal );
		return $post_id;
	}

	/** @return array<int, array{proposal: string, post_count: int, posts: array<int, array{id: int, title: string}>}> */
	private function call_get_proposals(): array {
		$ref = new \ReflectionMethod( MediumProposals::class, 'get_proposals' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->controller );
	}

	/** @return array{0: int, 1: string|null} */
	private function call_approve( string $proposal ): array {
		$ref = new \ReflectionMethod( MediumProposals::class, 'approve_proposal' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->controller, $proposal );
	}

	private function call_reject( string $proposal ): int {
		$ref = new \ReflectionMethod( MediumProposals::class, 'reject_proposal' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->controller, $proposal );
	}

	// -------------------------------------------------------------------------
	// get_proposals() — the aggregate query behind the admin notice/table
	// -------------------------------------------------------------------------

	public function test_get_proposals_groups_by_distinct_value_with_counts(): void {
		$this->make_artwork_with_proposal( 'Short Story', 'Piece One' );
		$this->make_artwork_with_proposal( 'Short Story', 'Piece Two' );
		$this->make_artwork_with_proposal( 'Collage', 'Piece Three' );

		$by_value = [];
		foreach ( $this->call_get_proposals() as $row ) {
			$by_value[ $row['proposal'] ] = (int) $row['post_count'];
		}

		$this->assertSame( 2, $by_value['Short Story'] ?? null );
		$this->assertSame( 1, $by_value['Collage'] ?? null );
	}

	/**
	 * Regression test for the 2026-07-21 follow-up: the admin notice used to
	 * show only a bare count ("3 submissions") with no way to see WHICH
	 * submissions a proposal actually covers before approving/rejecting it
	 * for all of them at once. get_proposals() must return each matching
	 * post's own id/title alongside the count.
	 */
	public function test_get_proposals_includes_the_specific_posts_for_each_value(): void {
		$post_1 = $this->make_artwork_with_proposal( 'Short Story', 'Piece One' );
		$post_2 = $this->make_artwork_with_proposal( 'Short Story', 'Piece Two' );

		$row = null;
		foreach ( $this->call_get_proposals() as $candidate ) {
			if ( 'Short Story' === $candidate['proposal'] ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row, 'Expected a "Short Story" proposal row.' );
		$post_ids = array_column( $row['posts'], 'id' );
		$this->assertContains( $post_1, $post_ids );
		$this->assertContains( $post_2, $post_ids );

		$titles = array_column( $row['posts'], 'title' );
		$this->assertContains( 'Piece One', $titles );
		$this->assertContains( 'Piece Two', $titles );
	}

	public function test_get_proposals_excludes_non_artwork_post_types(): void {
		$post_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'A Biography',
		] );
		update_post_meta( $post_id, '_agnosis_medium_proposal', 'Should Not Count' );

		$values = array_column( $this->call_get_proposals(), 'proposal' );

		$this->assertNotContains( 'Should Not Count', $values );
	}

	public function test_get_proposals_is_empty_when_none_pending(): void {
		$this->assertSame( [], $this->call_get_proposals() );
	}

	// -------------------------------------------------------------------------
	// approve_proposal()
	// -------------------------------------------------------------------------

	public function test_approve_creates_new_term_and_assigns_to_all_matching_posts(): void {
		$post_1 = $this->make_artwork_with_proposal( 'Ceramics', 'Piece A' );
		$post_2 = $this->make_artwork_with_proposal( 'Ceramics', 'Piece B' );

		[ $approved, $error ] = $this->call_approve( 'Ceramics' );

		$this->assertNull( $error );
		$this->assertSame( 2, $approved );
		$this->assertSame( [ 'Ceramics' ], wp_get_post_terms( $post_1, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertSame( [ 'Ceramics' ], wp_get_post_terms( $post_2, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertSame( '', get_post_meta( $post_1, '_agnosis_medium_proposal', true ) );
		$this->assertSame( '', get_post_meta( $post_2, '_agnosis_medium_proposal', true ) );
	}

	/**
	 * An admin approving the same proposed name twice (two separate batches)
	 * must reuse the existing term, not error or create a duplicate.
	 */
	public function test_approve_reuses_existing_term_rather_than_erroring(): void {
		wp_insert_term( 'Ceramics', 'agnosis_medium' );
		$post_id = $this->make_artwork_with_proposal( 'Ceramics' );

		[ $approved, $error ] = $this->call_approve( 'Ceramics' );

		$this->assertNull( $error );
		$this->assertSame( 1, $approved );
		$this->assertSame( [ 'Ceramics' ], wp_get_post_terms( $post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertCount( 1, get_terms( [ 'taxonomy' => 'agnosis_medium', 'hide_empty' => false, 'name' => 'Ceramics' ] ), 'Approving an already-existing term name must not create a duplicate.' );
	}

	public function test_approve_does_not_affect_posts_with_a_different_proposal(): void {
		$this->make_artwork_with_proposal( 'Ceramics', 'Piece A' );
		$other = $this->make_artwork_with_proposal( 'Textiles', 'Piece B' );

		$this->call_approve( 'Ceramics' );

		$this->assertSame( [], wp_get_post_terms( $other, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertSame( 'Textiles', get_post_meta( $other, '_agnosis_medium_proposal', true ) );
	}

	public function test_approve_of_empty_proposal_is_a_no_op(): void {
		[ $approved, $error ] = $this->call_approve( '' );

		$this->assertNull( $error );
		$this->assertSame( 0, $approved );
	}

	// -------------------------------------------------------------------------
	// reject_proposal()
	// -------------------------------------------------------------------------

	public function test_reject_clears_meta_without_creating_any_term(): void {
		$post_id = $this->make_artwork_with_proposal( 'Interpretive Dance' );

		$rejected = $this->call_reject( 'Interpretive Dance' );

		$this->assertSame( 1, $rejected );
		$this->assertSame( '', get_post_meta( $post_id, '_agnosis_medium_proposal', true ) );
		$this->assertFalse( get_term_by( 'name', 'Interpretive Dance', 'agnosis_medium' ), 'Rejecting a proposal must never create the term.' );
	}

	public function test_reject_preserves_an_existing_medium_assignment(): void {
		wp_insert_term( 'Oil Painting', 'agnosis_medium' );
		$post_id = $this->make_artwork_with_proposal( 'Something Else' );
		wp_set_object_terms( $post_id, 'Oil Painting', 'agnosis_medium' );

		$this->call_reject( 'Something Else' );

		$this->assertSame(
			[ 'Oil Painting' ],
			wp_get_post_terms( $post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ),
			'Rejecting a proposal must not touch a medium the post already has assigned.'
		);
	}

	public function test_reject_of_empty_proposal_is_a_no_op(): void {
		$this->assertSame( 0, $this->call_reject( '' ) );
	}
}
