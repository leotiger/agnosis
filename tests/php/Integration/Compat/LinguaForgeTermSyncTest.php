<?php
/**
 * Integration tests — Compat\LinguaForge's on-demand term-translation sync
 * (`sync_term_across_languages()` / `sync_all_terms_across_languages()`),
 * added for the taxonomy redesign (0.9.38/0.9.39) and shipped with zero
 * PHPUnit coverage (audit §2i, AUDIT-0.9.38.md) — including the homograph
 * collision fix (audit §2b, 0.9.39).
 *
 * Reuses the same LF global-stub pattern LinguaForgeCompatTest already
 * established (Compat/Stubs/lf_global_stubs.php, guarded function_exists()
 * definitions, LinguaForgeCompatTest::$lf_languages as the shared control
 * point for linguaforge_languages()) rather than redefining it — the stub
 * functions are guarded, so requiring the same file again here is a no-op
 * everywhere except the one place that matters: this file no longer depends
 * on load order relative to LinguaForgeCompatTest.php.
 *
 * No AI provider is configured in the test environment, so
 * translated_term_name() always falls back to the original name UNLESS a
 * translation is pre-seeded into the `agnosis_term_translations` cache —
 * the same technique LinguaForgeCompatTest's own trid tests already use.
 *
 * Coverage:
 *   sync_term_across_languages()
 *     - no-op on an already-translated term
 *     - creates a translated term per configured target language
 *     - skips a language whose translation is already trid-linked
 *     - counts a language as `failed` with no provider/cache translation
 *     - §2b: a same-name collision against a term in a different trid group
 *       (or a trid-less primary term) resolves via a language-suffixed slug
 *       instead of failing that language forever
 *   sync_all_terms_across_languages()
 *     - aggregates terms/total/created/skipped/failed across every primary term
 *     - excludes already-translated terms from the primary set
 *     - all-zero result when the taxonomy has no primary terms
 *   insert_translated_term() (private — the actual §2b fix; exercised via
 *   Reflection, same pattern SettingsTermTranslationCacheTest already uses
 *   for a private method) — the "same trid, lost a race" branch specifically
 *   can't be reached through the public method without genuine concurrency
 *   (find_term_by_trid()'s own earlier lookup would short-circuit to
 *   "skipped" first in any single-threaded call), so it's verified directly:
 *     - clean insert
 *     - a non-`term_exists` WP_Error passes through as failure (null)
 *     - a `term_exists` collision where the existing term already carries
 *       THIS trid resolves to that existing term (a lost race, not a failure)
 *     - a `term_exists` collision where the existing term carries a
 *       different trid (or none) retries with a language-suffixed slug
 *     - a collision where even the suffixed-slug retry fails resolves to null
 *
 * Deliberately NOT covered: sync_all_terms_across_languages()'s time-bounded
 * `timed_out` branch (SYNC_ALL_TIME_BUDGET_SECONDS, audit §2a) — it's a real
 * wall-clock deadline with no injectable clock or filter seam, so reliably
 * triggering it would mean either a genuinely slow (20s+) test or asserting
 * on a `>=` comparison no test can safely force. The aggregation logic
 * around it (total/terms/created/skipped/failed summing) IS covered.
 *
 * @package Agnosis\Tests\Integration\Compat
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Compat;

use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;

require_once __DIR__ . '/Stubs/lf_global_stubs.php';

class LinguaForgeTermSyncTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Belt-and-suspenders, same reasoning as LinguaForgeCompatTest's own
		// setUp() — agnosis_medium is registered globally on 'init', which
		// should already have fired, but explicit here anyway.
		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			( new Profile() )->register_taxonomy();
		}

		LinguaForgeCompatTest::$lf_languages = [ 'en', 'de', 'nl' ];
		update_option( 'linguaforge_primary_language', 'en' );
	}

	protected function tearDown(): void {
		LinguaForgeCompatTest::$lf_languages = null;
		delete_option( 'linguaforge_primary_language' );
		delete_option( 'agnosis_term_translations' );
		parent::tearDown();
	}

	/** Pre-seed a cached translation so translated_term_name() returns it without an AI provider. */
	private function seed_translation( string $taxonomy, string $name, string $lang, string $translated ): void {
		$cache = get_option( 'agnosis_term_translations', [] );
		$cache[ $taxonomy ][ $name ][ $lang ] = $translated;
		update_option( 'agnosis_term_translations', $cache, false );
	}

	private function insert_term( string $name, string $taxonomy = 'post_tag' ): int {
		$term = wp_insert_term( $name, $taxonomy );
		$this->assertIsArray( $term, "Fixture setup failed to insert term '$name'." );
		return (int) $term['term_id'];
	}

	// -------------------------------------------------------------------------
	// sync_term_across_languages()
	// -------------------------------------------------------------------------

	public function test_sync_term_is_a_no_op_on_an_already_translated_term(): void {
		$translated_id = $this->insert_term( 'Paysage' );
		add_term_meta( $translated_id, LinguaForge::TRANSLATED_TERM_META, 'fr', true );

		$result = ( new LinguaForge() )->sync_term_across_languages( $translated_id, 'post_tag' );

		$this->assertSame( [ 'created' => [], 'skipped' => [], 'failed' => [] ], $result );
	}

	public function test_sync_term_creates_a_translated_term_per_target_language(): void {
		$term_id = $this->insert_term( 'Landscape' );
		$this->seed_translation( 'post_tag', 'Landscape', 'de', 'Landschaft' );
		$this->seed_translation( 'post_tag', 'Landscape', 'nl', 'Landschap' );

		$result = ( new LinguaForge() )->sync_term_across_languages( $term_id, 'post_tag' );

		$this->assertSame( [ 'de', 'nl' ], $result['created'] );
		$this->assertSame( [], $result['skipped'] );
		$this->assertSame( [], $result['failed'] );

		$de = get_term_by( 'name', 'Landschaft', 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $de );

		$trid = get_term_meta( $term_id, LinguaForge::TERM_TRID_META, true );
		$this->assertNotSame( '', $trid, 'Syncing must assign the primary term its own trid.' );
		$this->assertSame( $trid, get_term_meta( $de->term_id, LinguaForge::TERM_TRID_META, true ) );
		$this->assertSame( 'de', get_term_meta( $de->term_id, LinguaForge::TRANSLATED_TERM_META, true ) );
	}

	public function test_sync_term_skips_a_language_already_trid_linked(): void {
		$term_id = $this->insert_term( 'Landscape' );
		$this->seed_translation( 'post_tag', 'Landscape', 'de', 'Landschaft' );

		$lf = new LinguaForge();
		$lf->sync_term_across_languages( $term_id, 'post_tag' ); // First run creates it.
		$result = $lf->sync_term_across_languages( $term_id, 'post_tag' ); // Second run must reuse it.

		$this->assertSame( [], $result['created'] );
		$this->assertSame( [ 'de' ], $result['skipped'] );
		// 'nl' has no cache entry seeded at all, so it falls back to the
		// original name and is counted as failed on every run, same as the
		// first — this test is only about 'de' converging to skipped rather
		// than being recreated.
		$this->assertSame( [ 'nl' ], $result['failed'] );

		$this->assertCount(
			1,
			get_terms( [ 'taxonomy' => 'post_tag', 'name' => 'Landschaft', 'hide_empty' => false ] ),
			'A second sync must not create a duplicate translated term.'
		);
	}

	public function test_sync_term_counts_a_language_as_failed_with_no_provider_and_no_cache(): void {
		$term_id = $this->insert_term( 'Landscape' );
		// No agnosis_term_translations cache entry and no AI provider
		// configured in the test environment — translated_term_name() falls
		// back to the original name for every target language.

		$result = ( new LinguaForge() )->sync_term_across_languages( $term_id, 'post_tag' );

		$this->assertSame( [], $result['created'] );
		$this->assertSame( [], $result['skipped'] );
		$this->assertSame( [ 'de', 'nl' ], $result['failed'] );
	}

	public function test_sync_term_resolves_a_homograph_collision_with_a_different_trid_group(): void {
		// A term already exists with the exact name the translation will
		// produce, but it belongs to no trid group at all — an admin-curated
		// primary term that happens to share the word, e.g. "Fotografie"
		// being both German and Dutch (audit §2b).
		$colliding_id = $this->insert_term( 'Fotografie' );

		$term_id = $this->insert_term( 'Foto' );
		$this->seed_translation( 'post_tag', 'Foto', 'de', 'Fotografie' );
		// 'nl' is deliberately left unseeded — falls back to the original
		// name and is counted as failed, keeping this test's only collision
		// unambiguous (two languages colliding on the exact same string in
		// the same call is a separate, narrower edge case not exercised here).

		$result = ( new LinguaForge() )->sync_term_across_languages( $term_id, 'post_tag' );

		$this->assertSame( [ 'de' ], $result['created'], 'The homograph collision must resolve, not fail.' );
		$this->assertSame( [ 'nl' ], $result['failed'] );

		$trid    = get_term_meta( $term_id, LinguaForge::TERM_TRID_META, true );
		$matches = get_terms( [ 'taxonomy' => 'post_tag', 'name' => 'Fotografie', 'hide_empty' => false ] );
		$this->assertCount( 2, $matches, 'The original colliding term plus one newly created (suffixed-slug) term.' );

		$new_term = null;
		foreach ( $matches as $term ) {
			if ( (int) $term->term_id !== $colliding_id ) {
				$new_term = $term;
			}
		}
		$this->assertNotNull( $new_term, 'A new term must have been created rather than reusing the collision.' );
		$this->assertSame( 'de', get_term_meta( $new_term->term_id, LinguaForge::TRANSLATED_TERM_META, true ) );
		$this->assertSame( $trid, get_term_meta( $new_term->term_id, LinguaForge::TERM_TRID_META, true ) );
		$this->assertSame(
			'',
			get_term_meta( $colliding_id, LinguaForge::TERM_TRID_META, true ),
			"The pre-existing colliding term must never be claimed as this sync's translation."
		);
	}

	// -------------------------------------------------------------------------
	// sync_all_terms_across_languages()
	// -------------------------------------------------------------------------

	public function test_sync_all_aggregates_across_every_primary_term(): void {
		$this->insert_term( 'Landscape' );
		$this->insert_term( 'Portrait' );
		$this->seed_translation( 'post_tag', 'Landscape', 'de', 'Landschaft' );
		// 'Landscape' nl and both of 'Portrait's languages have no cache
		// entry and no provider — all three fail.

		$result = ( new LinguaForge() )->sync_all_terms_across_languages( 'post_tag' );

		$this->assertSame( 2, $result['terms'] );
		$this->assertSame( 2, $result['total'] );
		$this->assertFalse( $result['timed_out'] );
		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 3, $result['failed'] );
	}

	public function test_sync_all_excludes_already_translated_terms_from_the_primary_set(): void {
		$this->insert_term( 'Landscape' );
		$translated_id = $this->insert_term( 'Paysage' );
		add_term_meta( $translated_id, LinguaForge::TRANSLATED_TERM_META, 'fr', true );

		$result = ( new LinguaForge() )->sync_all_terms_across_languages( 'post_tag' );

		$this->assertSame( 1, $result['total'], 'An already-translated term must not be counted as a primary term to sync.' );
	}

	public function test_sync_all_returns_zeroed_result_when_taxonomy_has_no_primary_terms(): void {
		$result = ( new LinguaForge() )->sync_all_terms_across_languages( 'post_tag' );

		$this->assertSame(
			[ 'terms' => 0, 'total' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'timed_out' => false ],
			$result
		);
	}

	// -------------------------------------------------------------------------
	// insert_translated_term() — the actual §2b fix, via Reflection.
	// -------------------------------------------------------------------------

	private function insert_translated_term( string $name, string $taxonomy, string $trid, string $lang ): ?int {
		$method = new \ReflectionMethod( LinguaForge::class, 'insert_translated_term' );
		$method->setAccessible( true );
		/** @var int|null $result */
		$result = $method->invoke( new LinguaForge(), $name, $taxonomy, $trid, $lang );
		return $result;
	}

	public function test_insert_translated_term_clean_insert(): void {
		$id = $this->insert_translated_term( 'Landschaft', 'post_tag', wp_generate_uuid4(), 'de' );

		$this->assertIsInt( $id );
		$this->assertSame( 'Landschaft', get_term( $id, 'post_tag' )->name );
	}

	public function test_insert_translated_term_returns_null_on_a_non_collision_error(): void {
		$force_failure = static fn () => new \WP_Error( 'db_insert_error', 'Simulated failure for this test.' );
		add_filter( 'pre_insert_term', $force_failure );

		$id = $this->insert_translated_term( 'Whatever', 'post_tag', wp_generate_uuid4(), 'de' );

		remove_filter( 'pre_insert_term', $force_failure );

		$this->assertNull( $id );
	}

	public function test_insert_translated_term_resolves_a_same_trid_collision_as_a_lost_race(): void {
		$trid        = wp_generate_uuid4();
		$existing_id = $this->insert_term( 'Fotografie' );
		add_term_meta( $existing_id, LinguaForge::TERM_TRID_META, $trid, true );
		add_term_meta( $existing_id, LinguaForge::TRANSLATED_TERM_META, 'de', true );

		$id = $this->insert_translated_term( 'Fotografie', 'post_tag', $trid, 'de' );

		$this->assertSame(
			$existing_id,
			$id,
			'A collision against a term already carrying THIS trid must resolve to that same term, not a new insert.'
		);
		$this->assertCount(
			1,
			get_terms( [ 'taxonomy' => 'post_tag', 'name' => 'Fotografie', 'hide_empty' => false ] ),
			'No new term should have been created.'
		);
	}

	public function test_insert_translated_term_suffixes_the_slug_on_a_different_trid_collision(): void {
		$colliding_id = $this->insert_term( 'Fotografie' );
		// No trid at all on the colliding term — an admin-curated primary term.

		$id = $this->insert_translated_term( 'Fotografie', 'post_tag', wp_generate_uuid4(), 'de' );

		$this->assertIsInt( $id );
		$this->assertNotSame( $colliding_id, $id, 'Must never claim the pre-existing, differently-linked term.' );
		$this->assertSame( 'Fotografie', get_term( $id, 'post_tag' )->name );
		$this->assertNotSame(
			get_term( $colliding_id, 'post_tag' )->slug,
			get_term( $id, 'post_tag' )->slug,
			'The retry must use a disambiguating slug, not collide again.'
		);
	}

	public function test_insert_translated_term_returns_null_when_even_the_suffixed_slug_collides(): void {
		$this->insert_term( 'Fotografie' );
		// Pre-occupy the exact disambiguating slug the retry will attempt.
		wp_insert_term( 'Fotografie (de)', 'post_tag', [ 'slug' => 'fotografie-de' ] );

		$id = $this->insert_translated_term( 'Fotografie', 'post_tag', wp_generate_uuid4(), 'de' );

		$this->assertNull( $id );
	}
}
