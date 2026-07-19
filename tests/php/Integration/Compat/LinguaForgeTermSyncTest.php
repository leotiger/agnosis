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
 * Since 2026-07-19 that fallback no longer means "create nothing" — it means
 * a real, trid-linked PLACEHOLDER term is created instead (the source name,
 * verbatim, tagged TERM_NEEDS_TRANSLATION_META and given a visible
 * "needs translation" description) — see insert_fallback_translated_term()'s
 * own docblock for the live report (German 9/10, Italian 8/10, Portuguese
 * 6/10 permanently missing) this change responds to. `failed` is now
 * reserved for a genuine DB-level insert failure, not "the AI couldn't
 * translate this."
 *
 * Coverage:
 *   sync_term_across_languages()
 *     - no-op on an already-translated term
 *     - creates a translated term per configured target language
 *     - skips a language whose translation is already trid-linked
 *     - creates a trid-linked, flagged PLACEHOLDER term (not a bare
 *       `failed` count) when no provider/cache translation is available
 *     - §2b: a same-name collision against a term in a different trid group
 *       (or a trid-less primary term) resolves via a language-suffixed slug
 *       instead of failing that language forever
 *     - a same-name collision WITHIN the same trid group — two different
 *       target languages translating to the identical word — resolves to
 *       two distinct terms instead of silently losing the second language
 *       (2026-07-19, the actual live bug: Portuguese/Spanish permanently
 *       missing several terms — "Arte Digital", "Escultura" — while the
 *       sync notice claimed zero failures)
 *   sync_all_terms_across_languages()
 *     - aggregates terms/total/created/needs_translation/skipped/failed
 *       across every primary term
 *     - excludes already-translated terms from the primary set
 *     - all-zero result when the taxonomy has no primary terms
 *   insert_translated_term() (private — the §2b homograph fix, corrected
 *   again 2026-07-19 for the same-trid/cross-language case above; exercised
 *   via Reflection, same pattern SettingsTermTranslationCacheTest already
 *   uses for a private method) — the "same trid, lost a race" branch
 *   specifically can't be reached through the public method without genuine
 *   concurrency (find_term_by_trid()'s own earlier lookup would short-circuit
 *   to "skipped" first in any single-threaded call), so it's verified
 *   directly:
 *     - clean insert
 *     - a non-`term_exists` WP_Error passes through as failure (null)
 *     - a `term_exists` collision where the existing term already carries
 *       THIS trid AND this exact language resolves to that existing term (a
 *       lost race, not a failure)
 *     - a `term_exists` collision where the existing term carries THIS trid
 *       but a DIFFERENT language already linked to it retries with a
 *       language-suffixed slug rather than reusing (and corrupting) the
 *       other language's term — the 2026-07-19 fix
 *     - a `term_exists` collision where the existing term IS the primary
 *       term itself (same trid, no TRANSLATED_TERM_META at all) also retries
 *       with a suffixed slug, rather than tagging the primary term as its
 *       own translation
 *     - a `term_exists` collision where the existing term carries a
 *       different trid (or none) retries with a language-suffixed slug
 *     - a collision where even the suffixed-slug retry fails resolves to null
 *   insert_fallback_translated_term() (private — the 2026-07-19 fix;
 *   exercised via Reflection, same pattern as above):
 *     - always creates a distinctly-slugged placeholder, even though the
 *       source name collides with the primary term itself (which carries
 *       THIS SAME trid) — the one case insert_translated_term()'s own
 *       "lost race" resolution would wrongly short-circuit to reusing the
 *       primary term's own ID, corrupting the primary vocabulary
 *     - sets the visible "needs translation" description
 *     - a non-`term_exists`/genuine insert failure resolves to null
 *
 * Deliberately NOT covered: sync_all_terms_across_languages()'s time-bounded
 * `timed_out` branch (SYNC_ALL_TIME_BUDGET_SECONDS, audit §2a) — it's a real
 * wall-clock deadline with no injectable clock or filter seam, so reliably
 * triggering it would mean either a genuinely slow (20s+) test or asserting
 * on a `>=` comparison no test can safely force. The aggregation logic
 * around it (total/terms/created/needs_translation/skipped/failed summing)
 * IS covered.
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

		$this->assertSame(
			[ 'created' => [], 'needs_translation' => [], 'skipped' => [], 'failed' => [] ],
			$result
		);
	}

	public function test_sync_term_creates_a_translated_term_per_target_language(): void {
		$term_id = $this->insert_term( 'Landscape' );
		$this->seed_translation( 'post_tag', 'Landscape', 'de', 'Landschaft' );
		$this->seed_translation( 'post_tag', 'Landscape', 'nl', 'Landschap' );

		$result = ( new LinguaForge() )->sync_term_across_languages( $term_id, 'post_tag' );

		$this->assertSame( [ 'de', 'nl' ], $result['created'] );
		$this->assertSame( [], $result['needs_translation'] );
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
		$first  = $lf->sync_term_across_languages( $term_id, 'post_tag' ); // First run creates both.
		$result = $lf->sync_term_across_languages( $term_id, 'post_tag' ); // Second run must reuse both.

		// 'nl' has no cache entry seeded at all, so the first run falls back
		// to a trid-linked PLACEHOLDER term for it rather than skipping
		// creation entirely (2026-07-19 fix) — so by the second run, both
		// 'de' (a genuine translation) and 'nl' (a placeholder) are already
		// linked and converge to skipped, exactly like a genuine translation
		// would. This is the point of the test: a placeholder must not be
		// wastefully regenerated on every sync any more than a real one is.
		$this->assertSame( [ 'de' ], $first['created'] );
		$this->assertSame( [ 'nl' ], $first['needs_translation'] );

		$this->assertSame( [], $result['created'] );
		$this->assertSame( [], $result['needs_translation'] );
		$this->assertSame( [ 'de', 'nl' ], $result['skipped'] );
		$this->assertSame( [], $result['failed'] );

		$this->assertCount(
			1,
			get_terms( [ 'taxonomy' => 'post_tag', 'name' => 'Landschaft', 'hide_empty' => false ] ),
			'A second sync must not create a duplicate translated term.'
		);
		$this->assertCount(
			2, // The primary 'Landscape' term itself, plus the one 'nl' placeholder — same name, disambiguated slug.
			get_terms( [ 'taxonomy' => 'post_tag', 'name' => 'Landscape', 'hide_empty' => false ] ),
			'A second sync must not create a duplicate placeholder term either.'
		);
	}

	public function test_sync_term_creates_fallback_placeholders_with_no_provider_and_no_cache(): void {
		$term_id = $this->insert_term( 'Landscape' );
		// No agnosis_term_translations cache entry and no AI provider
		// configured in the test environment — translated_term_name() falls
		// back to the original name for every target language, which now
		// (2026-07-19) means a trid-linked PLACEHOLDER term is created for
		// each one instead of the language being left with no term at all.

		$result = ( new LinguaForge() )->sync_term_across_languages( $term_id, 'post_tag' );

		$this->assertSame( [], $result['created'] );
		$this->assertSame( [ 'de', 'nl' ], $result['needs_translation'] );
		$this->assertSame( [], $result['skipped'] );
		$this->assertSame( [], $result['failed'] );

		$trid = get_term_meta( $term_id, LinguaForge::TERM_TRID_META, true );
		$this->assertNotSame( '', $trid );

		foreach ( [ 'de', 'nl' ] as $lang ) {
			$placeholder = $this->find_trid_linked_term( 'post_tag', $trid, $lang, exclude: $term_id );
			$this->assertInstanceOf( \WP_Term::class, $placeholder, "A placeholder term must exist for '$lang'." );
			$this->assertSame( 'Landscape', $placeholder->name, 'The placeholder must reuse the source name verbatim.' );
			$this->assertNotSame( 'landscape', $placeholder->slug, 'The placeholder must use a disambiguated slug, not collide with the primary term.' );
			$this->assertSame( $lang, get_term_meta( $placeholder->term_id, LinguaForge::TRANSLATED_TERM_META, true ) );
			$this->assertSame( '1', get_term_meta( $placeholder->term_id, LinguaForge::TERM_NEEDS_TRANSLATION_META, true ) );
			$this->assertNotSame( '', $placeholder->description, 'The placeholder must carry a visible note explaining it needs a hand translation.' );
		}
	}

	/** Find the term (if any) carrying $trid + TRANSLATED_TERM_META=$lang in $taxonomy, other than $exclude. */
	private function find_trid_linked_term( string $taxonomy, string $trid, string $lang, int $exclude ): ?\WP_Term {
		$matches = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'exclude'    => [ $exclude ],
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- test helper, not production code.
				[ 'key' => LinguaForge::TERM_TRID_META, 'value' => $trid ],
				[ 'key' => LinguaForge::TRANSLATED_TERM_META, 'value' => $lang ],
			],
		] );

		return ( ! is_wp_error( $matches ) && isset( $matches[0] ) && $matches[0] instanceof \WP_Term ) ? $matches[0] : null;
	}

	public function test_sync_term_resolves_a_homograph_collision_with_a_different_trid_group(): void {
		// A term already exists with the exact name the translation will
		// produce, but it belongs to no trid group at all — an admin-curated
		// primary term that happens to share the word, e.g. "Fotografie"
		// being both German and Dutch (audit §2b).
		$colliding_id = $this->insert_term( 'Fotografie' );

		$term_id = $this->insert_term( 'Foto' );
		$this->seed_translation( 'post_tag', 'Foto', 'de', 'Fotografie' );
		// 'nl' is deliberately left unseeded — falls back to a placeholder
		// term (needs_translation), keeping this test's only genuine
		// homograph collision unambiguous (two languages colliding on the
		// exact same string in the same call is a separate, narrower edge
		// case not exercised here).

		$result = ( new LinguaForge() )->sync_term_across_languages( $term_id, 'post_tag' );

		$this->assertSame( [ 'de' ], $result['created'], 'The homograph collision must resolve, not fail.' );
		$this->assertSame( [ 'nl' ], $result['needs_translation'] );
		$this->assertSame( [], $result['failed'] );

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

	public function test_sync_term_resolves_a_homograph_collision_within_the_same_trid_group(): void {
		// The actual live bug, end to end through the public method: TWO
		// target languages of the SAME primary term translate to the exact
		// same word ("Arte Digital" is identical in Spanish and Portuguese;
		// modeled here with the same de/nl "Fotografie" pair the different-
		// trid-group test above uses, but this time both are genuinely
		// translating the SAME primary term — a same-trid collision, not a
		// collision with some unrelated pre-existing term). Before the fix,
		// whichever language processed second silently lost its term (reused
		// the first language's ID, then add_term_meta's unique=true no-op'd)
		// while still being counted as `created` — reported live as
		// Portuguese/Spanish permanently missing several terms with the sync
		// notice claiming zero failures.
		$term_id = $this->insert_term( 'Photo' );
		$this->seed_translation( 'post_tag', 'Photo', 'de', 'Fotografie' );
		$this->seed_translation( 'post_tag', 'Photo', 'nl', 'Fotografie' );

		$result = ( new LinguaForge() )->sync_term_across_languages( $term_id, 'post_tag' );

		$this->assertSame( [ 'de', 'nl' ], $result['created'], 'Both languages must succeed — neither may be silently dropped.' );
		$this->assertSame( [], $result['failed'] );
		$this->assertSame( [], $result['needs_translation'] );

		$matches = get_terms( [ 'taxonomy' => 'post_tag', 'name' => 'Fotografie', 'hide_empty' => false ] );
		$this->assertCount( 2, $matches, 'Each language must get its own distinct term.' );

		$trid = get_term_meta( $term_id, LinguaForge::TERM_TRID_META, true );
		$linked_langs = [];
		foreach ( $matches as $term ) {
			$this->assertSame( $trid, get_term_meta( $term->term_id, LinguaForge::TERM_TRID_META, true ) );
			$linked_langs[] = get_term_meta( $term->term_id, LinguaForge::TRANSLATED_TERM_META, true );
		}
		sort( $linked_langs );
		$this->assertSame( [ 'de', 'nl' ], $linked_langs, 'Each term must be linked to its own correct, distinct language.' );
	}

	// -------------------------------------------------------------------------
	// sync_all_terms_across_languages()
	// -------------------------------------------------------------------------

	public function test_sync_all_aggregates_across_every_primary_term(): void {
		$this->insert_term( 'Landscape' );
		$this->insert_term( 'Portrait' );
		$this->seed_translation( 'post_tag', 'Landscape', 'de', 'Landschaft' );
		// 'Landscape' nl and both of 'Portrait's languages have no cache
		// entry and no provider — all three fall back to placeholder terms
		// (needs_translation), not `failed` (2026-07-19: a bare AI failure no
		// longer means "create nothing").

		$result = ( new LinguaForge() )->sync_all_terms_across_languages( 'post_tag' );

		$this->assertSame( 2, $result['terms'] );
		$this->assertSame( 2, $result['total'] );
		$this->assertFalse( $result['timed_out'] );
		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 3, $result['needs_translation'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 0, $result['failed'] );
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
			[
				'terms'             => 0,
				'total'             => 0,
				'created'           => 0,
				'needs_translation' => 0,
				'skipped'           => 0,
				'failed'            => 0,
				'timed_out'         => false,
			],
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

	public function test_insert_translated_term_suffixes_the_slug_on_a_same_trid_collision_with_a_different_language(): void {
		// The actual live bug (2026-07-19, reported the same day as the fix):
		// two DIFFERENT languages in the SAME trid group translate to the
		// same (or accent-equivalent) word — e.g. "Arte Digital" is literally
		// identical in Spanish and Portuguese, "Fotografía"/"Fotografia"
		// differ only by an accent. Before this fix, ANY same-trid collision
		// was treated as a safe "lost race" and resolved to the FIRST
		// language's own term — reused for the second language too, which
		// then failed to actually link (add_term_meta's $unique=true silently
		// no-ops when the term already carries a DIFFERENT value for that
		// key), leaving the second language with no term at all despite the
		// caller believing it succeeded.
		$trid    = wp_generate_uuid4();
		$de_id   = $this->insert_translated_term( 'Fotografie', 'post_tag', $trid, 'de' );
		$this->assertIsInt( $de_id );
		add_term_meta( $de_id, LinguaForge::TRANSLATED_TERM_META, 'de', true );
		add_term_meta( $de_id, LinguaForge::TERM_TRID_META, $trid, true );

		$nl_id = $this->insert_translated_term( 'Fotografie', 'post_tag', $trid, 'nl' );

		$this->assertIsInt( $nl_id );
		$this->assertNotSame( $de_id, $nl_id, 'Two different languages in the same trid group must never share one term.' );
		$this->assertSame( 'Fotografie', get_term( $nl_id, 'post_tag' )->name );
		$this->assertNotSame(
			get_term( $de_id, 'post_tag' )->slug,
			get_term( $nl_id, 'post_tag' )->slug,
			'The second language must get its own disambiguated slug, not collide again.'
		);
		$this->assertCount(
			2,
			get_terms( [ 'taxonomy' => 'post_tag', 'name' => 'Fotografie', 'hide_empty' => false ] ),
			'Both languages must end up with their own real term.'
		);
	}

	public function test_insert_translated_term_suffixes_the_slug_on_a_collision_with_the_primary_term_itself(): void {
		// AI can legitimately "translate" a word to something byte-identical
		// to the primary term's own name (not unusual between closely
		// related languages, or for a term that's a proper noun/loanword).
		// The colliding term here is the PRIMARY term itself — which never
		// carries TRANSLATED_TERM_META — so before this fix it was
		// indistinguishable from a genuine lost race and got reused,
		// silently tagging the primary term as its own translation
		// (PromptConfig::medium_terms() would then wrongly exclude it from
		// the AI's controlled vocabulary from that point on).
		$trid       = wp_generate_uuid4();
		$primary_id = $this->insert_term( 'Escultura' );
		add_term_meta( $primary_id, LinguaForge::TERM_TRID_META, $trid, true );
		// Deliberately no TRANSLATED_TERM_META — that absence is exactly
		// what marks a term as primary throughout this class.

		$created_id = $this->insert_translated_term( 'Escultura', 'post_tag', $trid, 'de' );

		$this->assertIsInt( $created_id );
		$this->assertNotSame( $primary_id, $created_id, "Must never reuse the primary term's own ID." );
		$this->assertSame(
			'',
			get_term_meta( $primary_id, LinguaForge::TRANSLATED_TERM_META, true ),
			'The primary term must never end up tagged as its own translation.'
		);
		$this->assertCount(
			2,
			get_terms( [ 'taxonomy' => 'post_tag', 'name' => 'Escultura', 'hide_empty' => false ] ),
			'The primary term plus one newly created (suffixed-slug) translation term.'
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

	// -------------------------------------------------------------------------
	// insert_fallback_translated_term() — the 2026-07-19 fix, via Reflection.
	// -------------------------------------------------------------------------

	private function insert_fallback_translated_term( string $name, string $taxonomy, string $trid, string $lang ): ?int {
		$method = new \ReflectionMethod( LinguaForge::class, 'insert_fallback_translated_term' );
		$method->setAccessible( true );
		/** @var int|null $result */
		$result = $method->invoke( new LinguaForge(), $name, $taxonomy, $trid, $lang );
		return $result;
	}

	public function test_insert_fallback_translated_term_creates_a_distinctly_slugged_placeholder_even_though_the_source_name_collides_with_the_primary_term_itself(): void {
		// The exact case insert_translated_term()'s own collision handling
		// would resolve WRONGLY: the primary term carries this same trid
		// (get_or_create_term_trid() assigns/stores it there too), so a
		// plain wp_insert_term() attempt colliding with it would look
		// EXACTLY like insert_translated_term()'s "same trid, lost a race"
		// case and resolve to the primary term's own ID — corrupting the
		// primary vocabulary by tagging it as its own translation. This
		// method must never attempt the plain insert in the first place.
		$trid        = wp_generate_uuid4();
		$primary_id  = $this->insert_term( 'Mixed Media', 'agnosis_medium' );
		add_term_meta( $primary_id, LinguaForge::TERM_TRID_META, $trid, true );

		$id = $this->insert_fallback_translated_term( 'Mixed Media', 'agnosis_medium', $trid, 'de' );

		$this->assertIsInt( $id );
		$this->assertNotSame( $primary_id, $id, 'Must never reuse the primary term\'s own ID.' );
		$this->assertSame( 'Mixed Media', get_term( $id, 'agnosis_medium' )->name, 'The placeholder reuses the source name verbatim.' );
		$this->assertNotSame(
			get_term( $primary_id, 'agnosis_medium' )->slug,
			get_term( $id, 'agnosis_medium' )->slug,
			'The placeholder must use a disambiguated slug, not collide with the primary term.'
		);
		$this->assertSame(
			$trid,
			get_term_meta( $primary_id, LinguaForge::TERM_TRID_META, true ),
			"The primary term's own trid meta must be completely untouched by creating a placeholder for another language."
		);
	}

	public function test_insert_fallback_translated_term_sets_a_visible_needs_translation_note(): void {
		$id = $this->insert_fallback_translated_term( 'Sculpture', 'agnosis_medium', wp_generate_uuid4(), 'de' );

		$this->assertIsInt( $id );
		$this->assertNotSame( '', get_term( $id, 'agnosis_medium' )->description, 'A placeholder must carry a human-readable note explaining it needs translation.' );
	}

	public function test_insert_fallback_translated_term_returns_null_on_a_genuine_insert_failure(): void {
		$force_failure = static fn () => new \WP_Error( 'db_insert_error', 'Simulated failure for this test.' );
		add_filter( 'pre_insert_term', $force_failure );

		$id = $this->insert_fallback_translated_term( 'Whatever', 'agnosis_medium', wp_generate_uuid4(), 'de' );

		remove_filter( 'pre_insert_term', $force_failure );

		$this->assertNull( $id );
	}
}
