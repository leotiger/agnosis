<?php
/**
 * Integration tests for PromptConfig::medium_terms() (2026-07-08).
 *
 * medium_terms() calls the live WordPress term API (taxonomy_exists()/get_terms()),
 * so — unlike the rest of PromptConfig, a pure value object exercised under plain
 * PHPUnit in PromptConfigTest.php — it needs a real WordPress environment and lives
 * here instead.
 *
 * Coverage:
 *   - Falls back to CANONICAL_MEDIUMS when the taxonomy has no terms at all
 *     (a fresh install, before any submission or manual term creation).
 *   - Falls back to CANONICAL_MEDIUMS when the taxonomy isn't registered.
 *   - Returns the LIVE term list — including an admin-added, non-canonical
 *     term — once terms exist, which is the actual point of this method:
 *     an admin-added Medium term becomes usable by the AI pipeline with no
 *     code change.
 *   - resolved_system_prompt( PromptConfig::medium_terms() ) — the way every
 *     real provider (OpenAI/Anthropic/WordPressAI) calls it — reflects that
 *     live list, while resolved_system_prompt() with no argument still falls
 *     back to CANONICAL_MEDIUMS (keeps the pure-value-object Unit tests valid).
 *
 * @package Agnosis\Tests\Integration\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\AI;

use Agnosis\AI\PromptConfig;
use Agnosis\Artist\Profile;

class PromptConfigMediumTermsTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Belt-and-suspenders: agnosis_medium is registered globally by
		// Profile::register_taxonomy() on 'init', which should already have
		// fired long before any test class runs. Explicit here anyway,
		// matching ActivatorTest's own defensive registration for the same
		// taxonomy — cheap insurance against test-order-dependent state.
		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			( new Profile() )->register_taxonomy();
		}
	}

	protected function tearDown(): void {
		// Remove any terms this test class added so counts in other tests
		// (e.g. ActivatorTest's seed-idempotency assertion) aren't affected.
		if ( taxonomy_exists( 'agnosis_medium' ) ) {
			foreach ( get_terms( [ 'taxonomy' => 'agnosis_medium', 'hide_empty' => false ] ) as $term ) {
				wp_delete_term( $term->term_id, 'agnosis_medium' );
			}
		}
		parent::tearDown();
	}

	private function make_config( string $system_prompt = 'Mediums: {medium_list}.' ): PromptConfig {
		return new PromptConfig(
			system_prompt:            $system_prompt,
			user_template:            '{artist_prompt}',
			enhancement_instructions: 'Enhance',
			tag_count:                5,
			excerpt_words:            30,
		);
	}

	public function test_medium_terms_falls_back_to_canonical_when_taxonomy_empty(): void {
		// agnosis_medium is registered globally by Profile::register_taxonomy()
		// on init, but this test class starts with zero terms in it.
		$this->assertTrue( taxonomy_exists( 'agnosis_medium' ) );

		$this->assertSame( PromptConfig::CANONICAL_MEDIUMS, PromptConfig::medium_terms() );
	}

	public function test_medium_terms_falls_back_to_canonical_when_taxonomy_unregistered(): void {
		// _unregister_taxonomy() is the WP core test suite's own sanctioned
		// helper for this — unlike a raw `global $wp_taxonomies; unset(...)`,
		// it's the mechanism WP's own test framework expects, so it can't
		// leave 'agnosis_medium' permanently unregistered for every later test
		// class in the same PHPUnit process if something goes wrong here.
		// Restoring via Profile::register_taxonomy() in the finally block
		// (rather than snapshotting/restoring the raw WP_Taxonomy object)
		// guarantees the REAL production registration comes back, not just
		// whatever this test happened to capture.
		_unregister_taxonomy( 'agnosis_medium' );

		try {
			$this->assertFalse( taxonomy_exists( 'agnosis_medium' ) );
			$this->assertSame( PromptConfig::CANONICAL_MEDIUMS, PromptConfig::medium_terms() );
		} finally {
			( new Profile() )->register_taxonomy();
		}
	}

	public function test_medium_terms_returns_live_terms_including_admin_added_one(): void {
		wp_insert_term( 'Oil Painting', 'agnosis_medium' );
		wp_insert_term( 'Ceramics', 'agnosis_medium' ); // Not in CANONICAL_MEDIUMS.

		$terms = PromptConfig::medium_terms();

		$this->assertContains( 'Oil Painting', $terms );
		$this->assertContains( 'Ceramics', $terms );
	}

	public function test_medium_terms_reflects_a_renamed_term(): void {
		$inserted = wp_insert_term( 'Oil Painting', 'agnosis_medium' );
		wp_update_term( $inserted['term_id'], 'agnosis_medium', [ 'name' => 'Oils' ] );

		$terms = PromptConfig::medium_terms();

		$this->assertContains( 'Oils', $terms );
		$this->assertNotContains( 'Oil Painting', $terms );
	}

	public function test_resolved_system_prompt_with_no_argument_uses_canonical_default(): void {
		// Confirms real terms in the DB don't leak into the no-argument call —
		// callers that don't explicitly pass medium_terms() (i.e. every existing
		// PromptConfigTest Unit test) keep seeing the fixed seed list.
		wp_insert_term( 'Ceramics', 'agnosis_medium' );

		$result = $this->make_config()->resolved_system_prompt();

		$this->assertStringContainsString( 'Oil Painting', $result );
		$this->assertStringNotContainsString( 'Ceramics', $result );
	}

	public function test_resolved_system_prompt_with_live_medium_terms_includes_admin_added_term(): void {
		wp_insert_term( 'Oil Painting', 'agnosis_medium' );
		wp_insert_term( 'Ceramics', 'agnosis_medium' );

		$result = $this->make_config()->resolved_system_prompt( PromptConfig::medium_terms() );

		$this->assertStringContainsString( 'Oil Painting', $result );
		$this->assertStringContainsString( 'Ceramics', $result );
	}
}
