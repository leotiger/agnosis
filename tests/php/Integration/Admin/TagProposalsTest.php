<?php
/**
 * Integration tests for Admin\TagProposals — TAG-REDESIGN.md T2's
 * proposals queue for tag names that didn't match the live post_tag
 * vocabulary at association time (Publishing\TagGate::associate()'s own
 * "new" branch). Mirrors MediumProposalsTest's structure (reflection into
 * the private approve_proposal()/reject_proposal()/get_proposals() methods
 * to avoid handle_approve()/handle_reject()'s terminal `exit;` — see that
 * file's own docblock for why), adapted for the three ways tags differ from
 * medium: multi-value meta per post, cross-CPT scope, normalized-match term
 * reuse, and additive (not replacing) assignment — see TagProposals' own
 * class docblock.
 *
 * TTL sweep coverage lives in its own section below — the boundary
 * TAG-REDESIGN.md T2's acceptance criteria names explicitly ("a proposal
 * older than the TTL is swept, logged, and behaves exactly like a
 * rejection").
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\TagProposals;
use Agnosis\Compat\LinguaForge;
use Agnosis\Publishing\TagGate;
use Agnosis\Tests\Integration\Compat\LinguaForgeCompatTest;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;
use Agnosis\Tests\Integration\Support\NarrowsWpReturns;

require_once __DIR__ . '/../Compat/Stubs/lf_global_stubs.php';

class TagProposalsTest extends \WP_UnitTestCase {

	use NarrowsWpReturns;

	private int $artist_id;
	private TagProposals $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->artist_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->controller = new TagProposals();
	}

	protected function tearDown(): void {
		foreach ( get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false ] ) as $term ) {
			wp_delete_term( $term->term_id, 'post_tag' );
		}
		delete_option( 'agnosis_proposal_ttl' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_post_with_proposal( string $proposal, string $post_type = 'agnosis_artwork', string $title = 'Test Post' ): int {
		$post_id = (int) wp_insert_post( [
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => $title,
		] );
		TagGate::replace_proposals( $post_id, [ $proposal ] );
		return $post_id;
	}

	/** @return array<int, array{proposal: string, post_count: int, posts: array<int, array{id: int, title: string}>}> */
	private function call_get_proposals(): array {
		$ref = new \ReflectionMethod( TagProposals::class, 'get_proposals' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->controller );
	}

	/** @return array{0: int, 1: string|null} */
	private function call_approve( string $proposal ): array {
		$ref = new \ReflectionMethod( TagProposals::class, 'approve_proposal' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->controller, $proposal );
	}

	private function call_reject( string $proposal ): int {
		$ref = new \ReflectionMethod( TagProposals::class, 'reject_proposal' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->controller, $proposal );
	}

	// -------------------------------------------------------------------------
	// get_proposals() — cross-CPT scope
	// -------------------------------------------------------------------------

	public function test_get_proposals_groups_by_distinct_value_across_all_three_cpts(): void {
		$this->make_post_with_proposal( 'Sunset', 'agnosis_artwork', 'Piece One' );
		$this->make_post_with_proposal( 'Sunset', 'agnosis_biography', 'A Bio' );
		$this->make_post_with_proposal( 'Sunset', 'agnosis_event', 'An Event' );

		$by_value = [];
		foreach ( $this->call_get_proposals() as $row ) {
			$by_value[ $row['proposal'] ] = (int) $row['post_count'];
		}

		$this->assertSame( 3, $by_value['Sunset'] ?? null, 'post_tag spans all three Agnosis CPTs — the listing must too.' );
	}

	public function test_get_proposals_is_empty_when_none_pending(): void {
		$this->assertSame( [], $this->call_get_proposals() );
	}

	// -------------------------------------------------------------------------
	// approve_proposal()
	// -------------------------------------------------------------------------

	public function test_approve_creates_new_term_once_and_tags_every_carrying_post(): void {
		$post_1 = $this->make_post_with_proposal( 'Ceramics', 'agnosis_artwork', 'Piece A' );
		$post_2 = $this->make_post_with_proposal( 'Ceramics', 'agnosis_biography', 'Piece B' );

		[ $approved, $error ] = $this->call_approve( 'Ceramics' );

		$this->assertNull( $error );
		$this->assertSame( 2, $approved );
		$this->assertSame( [ 'Ceramics' ], wp_get_post_terms( $post_1, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertSame( [ 'Ceramics' ], wp_get_post_terms( $post_2, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertCount( 1, get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false, 'name' => 'Ceramics' ] ) );
		$this->assertSame( [], get_post_meta( $post_1, TagGate::PROPOSAL_META, false ) );
	}

	/**
	 * Double-approval survival: a SECOND submission proposing the same
	 * concept in different case/whitespace than the first approval already
	 * settled must reuse the existing term via normalized match (TW-9), not
	 * error or create a duplicate — same double-approval tolerance
	 * MediumProposals has. Each approve() call below stores and looks up
	 * its OWN proposal string consistently (get_posts_with_proposal()'s
	 * cross-post grouping is deliberately an exact-value match — see
	 * TagGate's own docblock on why a per-row JSON blob can't be used
	 * instead); it's the VOCABULARY match inside approve_proposal() that's
	 * normalized, not this lookup.
	 */
	public function test_approve_survives_double_approval_via_normalized_reuse(): void {
		$first_post = $this->make_post_with_proposal( 'Ceramics' );
		$this->call_approve( 'Ceramics' );

		// A different post proposed the same concept under a differently-CASED
		// name (no surrounding whitespace — that dimension is already covered
		// by normalize_for_match()'s own unit tests in TagGateTest; keeping
		// this one exact-match lookup clean of whitespace isolates what this
		// test is actually about: the VOCABULARY reuse match, not the
		// proposal-row lookup).
		$second_post = $this->make_post_with_proposal( 'ceramics' );
		[ $approved, $error ] = $this->call_approve( 'ceramics' );

		$this->assertNull( $error );
		$this->assertSame( 1, $approved );
		$this->assertSame( [ 'Ceramics' ], wp_get_post_terms( $first_post, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertSame( [ 'Ceramics' ], wp_get_post_terms( $second_post, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertCount( 1, get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false, 'name' => 'Ceramics' ] ), 'The second, differently-cased approval must reuse the term the first approval already created, not create a duplicate.' );
	}

	/**
	 * TAG-REDESIGN.md T3(b): approving a proposal that creates a brand-new
	 * primary term must queue background translation into every active
	 * language — LinguaForge::queue_translation_for_term(), called from the
	 * new-term branch of approve_proposal() only.
	 */
	public function test_approve_of_a_new_name_queues_background_translation(): void {
		LinguaForgeCompatTest::$lf_languages = [ 'en', 'de' ];
		update_option( 'linguaforge_primary_language', 'en' );

		$this->make_post_with_proposal( 'Ceramics' );
		$this->call_approve( 'Ceramics' );

		$term = get_term_by( 'name', 'Ceramics', 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$pending = json_decode( (string) get_term_meta( $term->term_id, LinguaForge::TERM_PENDING_TRANSLATION_META, true ), true );
		$this->assertSame( [ 'de' ], $pending );

		LinguaForgeCompatTest::$lf_languages = null;
		delete_option( 'linguaforge_primary_language' );
	}

	/**
	 * End-to-end acceptance check (TAG-REDESIGN.md T3's own acceptance
	 * paragraph): "approving a proposal yields, after the queue drains, a
	 * trid-complete group across every active language with zero clicks."
	 * Chains a real approve_proposal() call into a real
	 * drain_translation_queue() tick — no direct queue-meta manipulation —
	 * to prove the two T3(b) entry points this test suite otherwise checks
	 * in isolation actually compose correctly.
	 */
	public function test_approve_then_drain_yields_a_trid_complete_group_with_zero_manual_clicks(): void {
		LinguaForgeCompatTest::$lf_languages = [ 'en', 'de', 'nl' ];
		update_option( 'linguaforge_primary_language', 'en' );

		$this->make_post_with_proposal( 'Ceramics' );
		$this->call_approve( 'Ceramics' );

		( new LinguaForge() )->drain_translation_queue();

		$primary = get_term_by( 'name', 'Ceramics', 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $primary );
		$this->assertSame(
			'',
			get_term_meta( $primary->term_id, LinguaForge::TERM_PENDING_TRANSLATION_META, true ),
			'The queue marker must be fully drained.'
		);

		$trid = get_term_meta( $primary->term_id, LinguaForge::TERM_TRID_META, true );
		$this->assertNotSame( '', $trid );

		foreach ( [ 'de', 'nl' ] as $lang ) {
			$matches = get_terms( [
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- test assertion, not production code.
					[ 'key' => LinguaForge::TERM_TRID_META, 'value' => $trid ],
					[ 'key' => LinguaForge::TRANSLATED_TERM_META, 'value' => $lang ],
				],
			] );
			$this->assertCount( 1, $matches, "The trid group must have exactly one '$lang' member." );
		}

		LinguaForgeCompatTest::$lf_languages = null;
		delete_option( 'linguaforge_primary_language' );
	}

	/**
	 * The reuse branch (normalized double-approval, TW-9) must never
	 * re-queue translation — the term already has its own queue marker
	 * from its original creation (or predates the queue entirely, in which
	 * case it's the "Sync all translations" backfill button's job, not this
	 * approval's).
	 */
	public function test_approve_reuse_of_an_existing_term_does_not_re_queue_translation(): void {
		LinguaForgeCompatTest::$lf_languages = [ 'en', 'de' ];
		update_option( 'linguaforge_primary_language', 'en' );

		$this->make_post_with_proposal( 'Ceramics' );
		$this->call_approve( 'Ceramics' ); // Creates the term and queues it.

		$term = get_term_by( 'name', 'Ceramics', 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		// Simulate the queue having already drained (or an admin having
		// synced manually) by the time the second approval below happens.
		delete_term_meta( $term->term_id, LinguaForge::TERM_PENDING_TRANSLATION_META );

		$this->make_post_with_proposal( 'ceramics' );
		$this->call_approve( 'ceramics' ); // Reuse via normalized match.

		$this->assertSame(
			'',
			get_term_meta( $term->term_id, LinguaForge::TERM_PENDING_TRANSLATION_META, true ),
			'Reusing an already-existing term via normalized match must never re-queue translation.'
		);

		LinguaForgeCompatTest::$lf_languages = null;
		delete_option( 'linguaforge_primary_language' );
	}

	/**
	 * TAG-REDESIGN.md T3(c): approving a proposal must propagate the new
	 * association to the approved post's translated siblings — delivered
	 * automatically via the generalized on_term_assignment_changed()
	 * listener (T3(c)) firing on this method's own wp_set_object_terms()
	 * call, not a separate explicit propagation call here.
	 */
	public function test_approve_propagates_the_new_tag_to_a_translated_sibling(): void {
		new LinguaForge(); // Registers the set_object_terms propagation listener.
		update_option( 'linguaforge_primary_language', 'en' );

		$post_id = $this->make_post_with_proposal( 'Ceramics' );
		update_post_meta( $post_id, '_lf_lang', 'en' );
		$sibling_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		FakeLinguaForge::link( $post_id, 'de', $sibling_id );

		$this->call_approve( 'Ceramics' );

		$this->assertSame(
			[ 'Ceramics' ],
			wp_get_post_terms( $sibling_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] )
		);

		delete_option( 'linguaforge_primary_language' );
		FakeLinguaForge::reset();
	}

	public function test_approve_adds_alongside_already_matched_tags_rather_than_replacing(): void {
		$existing = wp_insert_term( 'Oil Painting', 'post_tag' );
		$post_id  = $this->make_post_with_proposal( 'Ceramics' );
		wp_set_object_terms( $post_id, [ (int) $existing['term_id'] ], 'post_tag' );

		$this->call_approve( 'Ceramics' );

		$names = self::term_names( wp_get_post_terms( $post_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ]  ) );
		sort( $names );
		$this->assertSame( [ 'Ceramics', 'Oil Painting' ], $names, 'Approving a proposal must ADD to whatever finalize_tags() already assigned, never replace it.' );
	}

	public function test_approve_does_not_affect_a_sibling_pending_proposal_on_the_same_post(): void {
		$post_id = $this->make_post_with_proposal( 'Ceramics' );
		TagGate::replace_proposals( $post_id, [ 'Ceramics', 'Textiles' ] );

		$this->call_approve( 'Ceramics' );

		$this->assertSame( [ 'Textiles' ], get_post_meta( $post_id, TagGate::PROPOSAL_META, false ), 'The 3-arg clear must leave a sibling pending proposal on the same post untouched.' );
	}

	public function test_approve_does_not_affect_posts_with_a_different_proposal(): void {
		$this->make_post_with_proposal( 'Ceramics', 'agnosis_artwork', 'Piece A' );
		$other = $this->make_post_with_proposal( 'Textiles', 'agnosis_artwork', 'Piece B' );

		$this->call_approve( 'Ceramics' );

		$this->assertSame( [], wp_get_post_terms( $other, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertSame( [ 'Textiles' ], get_post_meta( $other, TagGate::PROPOSAL_META, false ) );
	}

	public function test_approve_of_empty_proposal_is_a_no_op(): void {
		[ $approved, $error ] = $this->call_approve( '' );

		$this->assertNull( $error );
		$this->assertSame( 0, $approved );
	}

	// -------------------------------------------------------------------------
	// reject_proposal()
	// -------------------------------------------------------------------------

	public function test_reject_clears_rows_and_nothing_else(): void {
		$existing = wp_insert_term( 'Oil Painting', 'post_tag' );
		$post_id  = $this->make_post_with_proposal( 'Interpretive Dance' );
		wp_set_object_terms( $post_id, [ (int) $existing['term_id'] ], 'post_tag' );

		$rejected = $this->call_reject( 'Interpretive Dance' );

		$this->assertSame( 1, $rejected );
		$this->assertSame( [], get_post_meta( $post_id, TagGate::PROPOSAL_META, false ) );
		$this->assertFalse( get_term_by( 'name', 'Interpretive Dance', 'post_tag' ), 'Rejecting a proposal must never create the term.' );
		$this->assertSame(
			[ 'Oil Painting' ],
			wp_get_post_terms( $post_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ),
			'Rejecting a proposal must not touch tags the post already has assigned.'
		);
	}

	public function test_reject_of_empty_proposal_is_a_no_op(): void {
		$this->assertSame( 0, $this->call_reject( '' ) );
	}

	// -------------------------------------------------------------------------
	// sweep_expired() — TTL boundary
	// -------------------------------------------------------------------------

	public function test_sweep_expires_a_proposal_older_than_the_ttl_exactly_like_a_reject(): void {
		update_option( 'agnosis_proposal_ttl', 7 );
		$existing = wp_insert_term( 'Oil Painting', 'post_tag' );
		$post_id  = $this->make_post_with_proposal( 'Stale Idea' );
		wp_set_object_terms( $post_id, [ (int) $existing['term_id'] ], 'post_tag' );

		// Backdate the proposal's own creation timestamp past the 7-day TTL.
		// Map key matches PROPOSAL_META's own stored value exactly, same as
		// production's TagGate::replace_proposals() always derives both from
		// the same $names array.
		update_post_meta(
			$post_id,
			TagGate::PROPOSAL_CREATED_META,
			wp_json_encode( [ 'Stale Idea' => time() - ( 8 * DAY_IN_SECONDS ) ] )
		);

		$this->controller->sweep_expired();

		$this->assertSame( [], get_post_meta( $post_id, TagGate::PROPOSAL_META, false ), 'An expired proposal must be cleared exactly like a rejection.' );
		$this->assertFalse( get_term_by( 'name', 'Stale Idea', 'post_tag' ), 'The TTL sweep must never create a term — same as a real rejection.' );
		$this->assertSame(
			[ 'Oil Painting' ],
			wp_get_post_terms( $post_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ),
			'Sweeping an expired proposal must not touch tags the post already has assigned.'
		);
	}

	public function test_sweep_leaves_a_proposal_younger_than_the_ttl_untouched(): void {
		update_option( 'agnosis_proposal_ttl', 7 );
		$post_id = $this->make_post_with_proposal( 'Fresh Idea' );

		// The timestamp map's keys must match PROPOSAL_META's own stored
		// value exactly (production's TagGate::replace_proposals() derives
		// both from the same $names array) — 'Fresh Idea', not a lowercased
		// variant.
		update_post_meta(
			$post_id,
			TagGate::PROPOSAL_CREATED_META,
			wp_json_encode( [ 'Fresh Idea' => time() - ( 6 * DAY_IN_SECONDS ) ] )
		);

		$this->controller->sweep_expired();

		$this->assertSame( [ 'Fresh Idea' ], get_post_meta( $post_id, TagGate::PROPOSAL_META, false ), 'A proposal younger than the TTL must survive the sweep.' );
	}

	public function test_sweep_respects_a_custom_ttl_setting(): void {
		update_option( 'agnosis_proposal_ttl', 1 );
		$post_id = $this->make_post_with_proposal( 'Two Day Old Idea' );

		update_post_meta(
			$post_id,
			TagGate::PROPOSAL_CREATED_META,
			wp_json_encode( [ 'Two Day Old Idea' => time() - ( 2 * DAY_IN_SECONDS ) ] )
		);

		$this->controller->sweep_expired();

		$this->assertSame( [], get_post_meta( $post_id, TagGate::PROPOSAL_META, false ) );
	}
}
