<?php
/**
 * Integration tests for Publishing\Retag — TAG-REDESIGN.md T2/§2's service
 * layer only (no UI this phase). Covers the structural eligibility gate
 * (not_found/unsupported_post_type/not_published/not_primary_language —
 * all deliberately non-throwing, since T4's future backfill loop sweeps a
 * mixed batch and must not abort on the first ineligible post) and the
 * one-AI-call → candidates → TagGate::associate() happy path, faked via the
 * same WpAiClientTestRegistry stub ReviewEndpointsNativeLanguagePipelineTest
 * uses for Pipeline's own chat()-based calls.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\CallCounter;
use Agnosis\Publishing\Retag;
use Agnosis\Publishing\TagGate;
use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;

require_once __DIR__ . '/../AI/Stubs/WpAiClientTestRegistry.php';
require_once __DIR__ . '/../AI/Stubs/wp_ai_provider_namespace_stubs.php';

class RetagTest extends \WP_UnitTestCase {

	private int $artist_id;
	private Retag $service;

	protected function setUp(): void {
		parent::setUp();

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->service    = new Retag();

		update_option( 'agnosis_description_provider', 'wp_ai' );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_description_provider' );
		WpAiClientTestRegistry::reset();

		foreach ( get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false ] ) as $term ) {
			wp_delete_term( $term->term_id, 'post_tag' );
		}
		parent::tearDown();
	}

	private function make_post( array $overrides = [] ): int {
		return (int) wp_insert_post( array_merge( [
			'post_type'    => 'agnosis_artwork',
			'post_status'  => 'publish',
			'post_author'  => $this->artist_id,
			'post_title'   => 'Sunset Over the Bay',
			'post_excerpt' => 'A vivid oil painting of a harbor at dusk.',
			'post_content' => '<!-- wp:paragraph --><p>Full body text describing the piece.</p><!-- /wp:paragraph -->',
		], $overrides ) );
	}

	// -------------------------------------------------------------------------
	// Eligibility gate
	// -------------------------------------------------------------------------

	public function test_run_fails_for_a_nonexistent_post(): void {
		$result = $this->service->run( 999999 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'not_found', $result['reason'] );
	}

	public function test_run_fails_for_an_unsupported_post_type(): void {
		$post_id = (int) wp_insert_post( [
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'A blog post',
		] );

		$result = $this->service->run( $post_id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'unsupported_post_type', $result['reason'] );
	}

	public function test_run_fails_for_a_draft(): void {
		$post_id = $this->make_post( [ 'post_status' => 'draft' ] );

		$result = $this->service->run( $post_id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'not_published', $result['reason'] );
	}

	public function test_run_fails_for_a_native_language_post(): void {
		$post_id = $this->make_post();
		update_post_meta( $post_id, '_agnosis_native_lang', 'es' );

		$result = $this->service->run( $post_id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'not_primary_language', $result['reason'] );
	}

	public function test_run_succeeds_for_a_post_whose_native_lang_equals_the_primary_code(): void {
		$post_id = $this->make_post();
		// '_agnosis_native_lang' already equal to the site's own resolved
		// primary code is the same "nothing to translate" case T1's own
		// intake gate treats as primary-language — not a native submission.
		update_post_meta( $post_id, '_agnosis_native_lang', \Agnosis\AI\SubmissionTranslator::resolve_target_language() );
		WpAiClientTestRegistry::$response = wp_json_encode( [ 'sunset', 'harbor' ] );

		$result = $this->service->run( $post_id );

		$this->assertTrue( $result['success'] );
	}

	// -------------------------------------------------------------------------
	// Happy path — one AI call, candidates written, TagGate::associate() run
	// -------------------------------------------------------------------------

	public function test_run_writes_candidates_and_associates_via_the_shared_gate(): void {
		$existing = wp_insert_term( 'Harbor', 'post_tag' );
		$post_id  = $this->make_post();
		WpAiClientTestRegistry::$response = wp_json_encode( [ 'Harbor', 'Sunset' ] );

		$result = $this->service->run( $post_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['matched'] );
		$this->assertSame( 1, $result['proposed'] );

		$this->assertSame(
			[ $existing['term_id'] ],
			wp_get_post_terms( $post_id, 'post_tag', [ 'fields' => 'ids', 'hide_empty' => false ] )
		);
		// Proposal rows keep the candidate's own trimmed display casing
		// (TagGate::associate()'s $new bucket stores trim($candidate), not
		// its lowercased normalize_for_match() form).
		$this->assertSame( [ 'Sunset' ], get_post_meta( $post_id, TagGate::PROPOSAL_META, false ) );
		$this->assertNotSame( '', get_post_meta( $post_id, '_agnosis_tag_candidates', true ) );
	}

	public function test_run_records_a_call_counter_entry_labeled_retag(): void {
		$post_id = $this->make_post();
		WpAiClientTestRegistry::$response = wp_json_encode( [ 'sunset' ] );

		$before = CallCounter::get_total( $post_id );
		$this->service->run( $post_id );

		$this->assertSame( $before + 1, CallCounter::get_total( $post_id ) );
	}

	public function test_run_clears_this_posts_stale_pending_proposals_first(): void {
		$post_id = $this->make_post();
		TagGate::replace_proposals( $post_id, [ 'Old Idea' ] );
		WpAiClientTestRegistry::$response = wp_json_encode( [ 'New Idea' ] );

		$this->service->run( $post_id );

		$this->assertSame( [ 'New Idea' ], get_post_meta( $post_id, TagGate::PROPOSAL_META, false ), 'A re-tag supersedes its own earlier candidates.' );
	}

	public function test_run_fails_when_the_ai_call_returns_nothing_usable(): void {
		$post_id = $this->make_post();
		WpAiClientTestRegistry::$response = 'not valid json';

		$result = $this->service->run( $post_id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'ai_call_failed', $result['reason'] );
	}

	public function test_a_second_immediate_run_converges(): void {
		$post_id = $this->make_post();
		WpAiClientTestRegistry::$response = wp_json_encode( [ 'sunset', 'harbor' ] );

		$first  = $this->service->run( $post_id );
		$second = $this->service->run( $post_id );

		$this->assertSame( $first['matched'], $second['matched'] );
		$this->assertSame( $first['proposed'], $second['proposed'] );

		$proposals = get_post_meta( $post_id, TagGate::PROPOSAL_META, false );
		sort( $proposals );
		$this->assertSame( [ 'harbor', 'sunset' ], $proposals, 'A second immediate Re-tag must not leave duplicate proposal rows behind.' );
		$this->assertSame( [], get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false, 'fields' => 'ids' ] ), 'Neither run creates a real term — both names stayed unmatched proposals.' );
	}
}
