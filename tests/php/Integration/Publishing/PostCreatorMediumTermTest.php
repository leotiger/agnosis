<?php
/**
 * Integration tests for PostCreator::write_post_meta()'s medium-term guard (2026-07-08).
 *
 * The AI-hallucination guard used to validate against the hardcoded
 * PromptConfig::CANONICAL_MEDIUMS constant, which meant a Medium term an admin
 * added under Artwork → Mediums (beyond the built-in eight) could never be
 * AI-assigned — the AI could return that exact string and it would still be
 * silently dropped. It now validates against PromptConfig::medium_terms()
 * (the live taxonomy), which is the whole point of making the vocabulary
 * admin-extensible in the first place. Exercises the private method directly
 * via reflection, the same pattern PostCreatorEventTitleMatchTest already uses
 * for another private PostCreator helper, rather than driving the full
 * queue-row handle() pipeline.
 *
 * Every wp_get_post_terms() assertion below passes 'hide_empty' => false —
 * without it, WP's default (hide_empty => true) filters the query by each
 * term's term_taxonomy.count column, which only gets recalculated on the next
 * relevant cron/save cycle rather than synchronously the instant
 * wp_set_object_terms() runs. A brand-new term freshly assigned within the
 * same request can legitimately still read back as count = 0 at query time,
 * which silently empties the result — not a real "no relationship" case, just
 * a stale count read too early. (Found the hard way: the two tests asserting
 * a term IS present failed with an empty array before this was added; the
 * tests asserting no term is present were unaffected either way.)
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Artist\Profile;
use Agnosis\Publishing\PostCreator;

class PostCreatorMediumTermTest extends \WP_UnitTestCase {

	private PostCreator $creator;
	private int $artist_id;

	protected function setUp(): void {
		parent::setUp();

		// Belt-and-suspenders: agnosis_medium is registered globally by
		// Profile::register_taxonomy() on 'init', which should already have
		// fired long before any test class runs — explicit here anyway,
		// matching ActivatorTest's own defensive registration for the same
		// taxonomy, since wp_insert_term()/wp_set_object_terms() both fail
		// silently against an unregistered taxonomy and every test below
		// relies on real term assignment actually happening.
		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			( new Profile() )->register_taxonomy();
		}

		// Minimal Pipeline stub — no AI calls, no WP option resolution.
		$pipeline = new class() extends Pipeline {
			public function __construct() {}
			/** @param array<string, mixed> $submission */
			public function process( array $submission, bool $skip_enhancement = false ): array {
				return []; }
		};

		$this->creator   = new PostCreator( $pipeline );
		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	protected function tearDown(): void {
		if ( taxonomy_exists( 'agnosis_medium' ) ) {
			foreach ( get_terms( [ 'taxonomy' => 'agnosis_medium', 'hide_empty' => false ] ) as $term ) {
				wp_delete_term( $term->term_id, 'agnosis_medium' );
			}
		}
		parent::tearDown();
	}

	/** @param array<string, mixed> $primary */
	private function write_post_meta( int $post_id, array $primary, string $post_type = 'agnosis_artwork' ): void {
		$ref = new \ReflectionMethod( PostCreator::class, 'write_post_meta' );
		$ref->setAccessible( true );
		$ref->invoke( $this->creator, $post_id, $primary, [], [], $post_type );
	}

	/**
	 * 2026-07-08: this MUST set post_title (or content/excerpt) — WordPress's
	 * own wp_insert_post_empty_content check makes wp_insert_post() silently
	 * return 0 (not a WP_Error; 'wp_error' defaults to false) when title,
	 * content, AND excerpt are all empty. Every test in this file was actually
	 * operating on post ID 0 until this was added: the two "term gets
	 * assigned" tests failed outright, and the "term is skipped" tests were
	 * quietly passing for the wrong reason (nothing to assign a term to in the
	 * first place, not a real exercise of the guard).
	 */
	private function make_artwork(): int {
		return (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Test Artwork',
		] );
	}

	public function test_assigns_canonical_medium_term(): void {
		wp_insert_term( 'Oil Painting', 'agnosis_medium' );
		$post_id = $this->make_artwork();

		$this->write_post_meta( $post_id, [ 'medium' => 'Oil Painting' ] );

		$this->assertSame(
			[ 'Oil Painting' ],
			wp_get_post_terms( $post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] )
		);
	}

	public function test_assigns_admin_added_non_canonical_medium_term(): void {
		// 'Ceramics' is not in PromptConfig::CANONICAL_MEDIUMS — an admin added
		// it themselves under Artwork → Mediums. Before this fix, the AI
		// returning this exact string would still have been silently dropped.
		wp_insert_term( 'Ceramics', 'agnosis_medium' );
		$post_id = $this->make_artwork();

		$this->write_post_meta( $post_id, [ 'medium' => 'Ceramics' ] );

		$this->assertSame(
			[ 'Ceramics' ],
			wp_get_post_terms( $post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] )
		);
	}

	public function test_skips_medium_not_in_live_vocabulary(): void {
		// Live vocabulary is whatever's actually in the taxonomy — with none
		// seeded, medium_terms() falls back to CANONICAL_MEDIUMS, so an AI
		// value outside even that list doesn't get an automatic term
		// assignment. 2026-07-21: it's no longer silently discarded either —
		// see test_records_proposal_for_medium_not_in_live_vocabulary() below.
		$post_id = $this->make_artwork();

		$this->write_post_meta( $post_id, [ 'medium' => 'Interpretive Dance' ] );

		$this->assertSame( [], wp_get_post_terms( $post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ) );
	}

	public function test_skips_medium_for_non_artwork_post_type(): void {
		wp_insert_term( 'Oil Painting', 'agnosis_medium' );
		// post_title required — see make_artwork()'s docblock (wp_insert_post_empty_content).
		$post_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Test Biography',
		] );

		$this->write_post_meta( $post_id, [ 'medium' => 'Oil Painting' ], 'agnosis_biography' );

		$this->assertSame( [], wp_get_post_terms( $post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ) );
	}

	public function test_empty_medium_value_is_skipped(): void {
		wp_insert_term( 'Oil Painting', 'agnosis_medium' );
		$post_id = $this->make_artwork();

		$this->write_post_meta( $post_id, [ 'medium' => '' ] );

		$this->assertSame( [], wp_get_post_terms( $post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ) );
	}

	// -------------------------------------------------------------------------
	// Medium-proposal recording (2026-07-21)
	// -------------------------------------------------------------------------

	/**
	 * Regression test for the 2026-07-21 fix: a non-matching medium used to
	 * be silently discarded here — the AI's answer simply vanished with no
	 * record it ever happened. It's now recorded as a reviewable proposal
	 * (Admin\MediumProposals surfaces these on the Artwork → Mediums screen).
	 */
	public function test_records_proposal_for_medium_not_in_live_vocabulary(): void {
		$post_id = $this->make_artwork();

		$this->write_post_meta( $post_id, [ 'medium' => 'Interpretive Dance' ] );

		$this->assertSame( 'Interpretive Dance', get_post_meta( $post_id, '_agnosis_medium_proposal', true ) );
	}

	public function test_no_proposal_recorded_when_medium_matches_live_vocabulary(): void {
		wp_insert_term( 'Oil Painting', 'agnosis_medium' );
		$post_id = $this->make_artwork();

		$this->write_post_meta( $post_id, [ 'medium' => 'Oil Painting' ] );

		$this->assertSame( '', get_post_meta( $post_id, '_agnosis_medium_proposal', true ) );
	}

	public function test_no_proposal_recorded_for_empty_medium(): void {
		$post_id = $this->make_artwork();

		$this->write_post_meta( $post_id, [ 'medium' => '' ] );

		$this->assertSame( '', get_post_meta( $post_id, '_agnosis_medium_proposal', true ) );
	}

	public function test_no_proposal_recorded_for_non_artwork_post_type(): void {
		$post_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Test Biography',
		] );

		$this->write_post_meta( $post_id, [ 'medium' => 'Interpretive Dance' ], 'agnosis_biography' );

		$this->assertSame( '', get_post_meta( $post_id, '_agnosis_medium_proposal', true ) );
	}

	/**
	 * A stale proposal from an earlier pass (e.g. a reprocess) must not
	 * linger once a later pass over the same post successfully matches the
	 * live vocabulary instead.
	 */
	public function test_stale_proposal_cleared_when_a_later_medium_matches(): void {
		wp_insert_term( 'Oil Painting', 'agnosis_medium' );
		$post_id = $this->make_artwork();

		$this->write_post_meta( $post_id, [ 'medium' => 'Interpretive Dance' ] );
		$this->assertSame( 'Interpretive Dance', get_post_meta( $post_id, '_agnosis_medium_proposal', true ) );

		$this->write_post_meta( $post_id, [ 'medium' => 'Oil Painting' ] );
		$this->assertSame( '', get_post_meta( $post_id, '_agnosis_medium_proposal', true ) );
		$this->assertSame(
			[ 'Oil Painting' ],
			wp_get_post_terms( $post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] )
		);
	}
}
