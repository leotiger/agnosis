<?php
/**
 * Integration tests for the automatic term-translation queue —
 * TAG-REDESIGN.md T3(b): the moment a primary term enters the vocabulary
 * (a TagProposals/MediumProposals approval creating a NEW term, or an admin
 * creating one directly on the Tags/Mediums screens), its trid-linked
 * translations for every active language are queued
 * (`_agnosis_term_pending_translation` term meta, a JSON array of remaining
 * language codes — the `PENDING_FANOUT_META` pattern applied to term
 * translation) and drained by a time-budgeted WP-Cron tick
 * (`drain_translation_queue()`, `agnosis_drain_translation_queue` /
 * `every_five_minutes`).
 *
 * Reuses the same LF global-stub pattern LinguaForgeTermSyncTest already
 * establishes (Compat/Stubs/lf_global_stubs.php, guarded function_exists()
 * definitions, LinguaForgeCompatTest::$lf_languages as the shared control
 * point for linguaforge_languages()).
 *
 * Coverage:
 *   queue_translation_for_term()
 *     - sets the pending-translation meta to every target language
 *     - a no-op (no meta written) when there are no target languages
 *   queue_translation_on_term_created() (the `created_term` listener)
 *     - queues translation for an admin-created primary post_tag/agnosis_medium term
 *     - ignores every other taxonomy
 *     - never queues translation for its OWN machine-slug insert
 *       (insert_or_reuse_translated_term()'s wp_insert_term() calls fire the
 *       same hook — the $suppress_translation_queue_on_created_term guard)
 *   drain_translation_queue()
 *     - creates and links every pending language, then clears the marker
 *     - skips (without re-creating) a language already linked via
 *       find_term_by_trid() — e.g. produced by an on-demand "Sync" click
 *       between queueing and the cron tick
 *     - a genuine DB-level insert failure leaves that language still queued
 *       for the next tick, rather than dropping it silently
 *
 * Deliberately NOT covered: TRANSLATION_QUEUE_TIME_BUDGET_SECONDS's own
 * timeout branch — same reasoning LinguaForgeTermSyncTest's own docblock
 * gives for sync_all_terms_across_languages()'s equivalent: a real wall-clock
 * deadline with no injectable clock, so reliably triggering it would mean a
 * genuinely slow test.
 *
 * @package Agnosis\Tests\Integration\Compat
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Compat;

use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;

require_once __DIR__ . '/Stubs/lf_global_stubs.php';

class LinguaForgeTranslationQueueTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

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

	private function insert_term( string $name, string $taxonomy = 'post_tag' ): int {
		$term = wp_insert_term( $name, $taxonomy );
		$this->assertIsArray( $term, "Fixture setup failed to insert term '$name'." );
		return (int) $term['term_id'];
	}

	/** @return string[] */
	private function pending_languages( int $term_id ): array {
		$json = (string) get_term_meta( $term_id, LinguaForge::TERM_PENDING_TRANSLATION_META, true );
		if ( '' === $json ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	private function seed_translation( string $taxonomy, string $name, string $lang, string $translated ): void {
		$cache = get_option( 'agnosis_term_translations', [] );
		$cache[ $taxonomy ][ $name ][ $lang ] = $translated;
		update_option( 'agnosis_term_translations', $cache, false );
	}

	private function insert_or_reuse_translated_term( string $name, string $taxonomy, string $trid, string $lang, bool $is_fallback = false ): ?int {
		$method = new \ReflectionMethod( LinguaForge::class, 'insert_or_reuse_translated_term' );
		$method->setAccessible( true );
		/** @var int|null $result */
		$result = $method->invoke( new LinguaForge(), $name, $taxonomy, $trid, $lang, $is_fallback );
		return $result;
	}

	// -------------------------------------------------------------------------
	// queue_translation_for_term()
	// -------------------------------------------------------------------------

	public function test_queue_translation_for_term_sets_pending_meta_for_every_target_language(): void {
		$term_id = $this->insert_term( 'Landscape' );

		LinguaForge::queue_translation_for_term( $term_id, 'post_tag' );

		$pending = $this->pending_languages( $term_id );
		sort( $pending );
		$this->assertSame( [ 'de', 'nl' ], $pending );
	}

	public function test_queue_translation_for_term_is_a_no_op_with_no_target_languages(): void {
		LinguaForgeCompatTest::$lf_languages = [ 'en' ]; // Only the primary — nothing to translate into.
		$term_id = $this->insert_term( 'Landscape' );

		LinguaForge::queue_translation_for_term( $term_id, 'post_tag' );

		$this->assertSame( '', get_term_meta( $term_id, LinguaForge::TERM_PENDING_TRANSLATION_META, true ) );
	}

	// -------------------------------------------------------------------------
	// queue_translation_on_term_created() — the `created_term` listener
	// -------------------------------------------------------------------------

	public function test_created_term_listener_queues_translation_for_an_admin_created_tag(): void {
		new LinguaForge(); // Registers the created_term listener.

		$term_id = $this->insert_term( 'Ceramics', 'post_tag' );

		$pending = $this->pending_languages( $term_id );
		sort( $pending );
		$this->assertSame( [ 'de', 'nl' ], $pending );
	}

	public function test_created_term_listener_queues_translation_for_an_admin_created_medium(): void {
		new LinguaForge();

		$term_id = $this->insert_term( 'Fresco', 'agnosis_medium' );

		$pending = $this->pending_languages( $term_id );
		sort( $pending );
		$this->assertSame( [ 'de', 'nl' ], $pending );
	}

	public function test_created_term_listener_ignores_other_taxonomies(): void {
		new LinguaForge();

		$term_id = $this->insert_term( 'Uncategorized Test Category', 'category' );

		$this->assertSame( '', get_term_meta( $term_id, LinguaForge::TERM_PENDING_TRANSLATION_META, true ) );
	}

	public function test_created_term_listener_never_queues_translation_for_its_own_machine_slug_insert(): void {
		new LinguaForge();

		$created_id = $this->insert_or_reuse_translated_term( 'Landschaft', 'post_tag', wp_generate_uuid4(), 'de' );

		$this->assertIsInt( $created_id );
		$this->assertSame(
			'',
			get_term_meta( $created_id, LinguaForge::TERM_PENDING_TRANSLATION_META, true ),
			'A translated term created by insert_or_reuse_translated_term() must never queue translation for ITSELF — the $suppress_translation_queue_on_created_term guard.'
		);
	}

	// -------------------------------------------------------------------------
	// drain_translation_queue()
	// -------------------------------------------------------------------------

	public function test_drain_creates_and_links_every_pending_language_then_clears_the_marker(): void {
		$term_id = $this->insert_term( 'Landscape' );
		$this->seed_translation( 'post_tag', 'Landscape', 'de', 'Landschaft' );
		// 'nl' deliberately left unseeded — no AI provider in the test
		// environment, so it resolves as a flagged placeholder, exactly like
		// sync_term_across_languages()'s own AI-failure path.
		update_term_meta( $term_id, LinguaForge::TERM_PENDING_TRANSLATION_META, wp_json_encode( [ 'de', 'nl' ] ) );

		( new LinguaForge() )->drain_translation_queue();

		$this->assertSame( '', get_term_meta( $term_id, LinguaForge::TERM_PENDING_TRANSLATION_META, true ), 'The marker must be cleared once every language resolves.' );

		$trid = get_term_meta( $term_id, LinguaForge::TERM_TRID_META, true );
		$this->assertNotSame( '', $trid );

		$de = get_term_by( 'name', 'Landschaft', 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $de );
		$this->assertSame( 'de', get_term_meta( $de->term_id, LinguaForge::TRANSLATED_TERM_META, true ) );
		$this->assertSame( $trid, get_term_meta( $de->term_id, LinguaForge::TERM_TRID_META, true ) );
		$this->assertSame( '', get_term_meta( $de->term_id, LinguaForge::TERM_NEEDS_TRANSLATION_META, true ) );

		$matches = get_terms( [
			'taxonomy'   => 'post_tag',
			'hide_empty' => false,
			'name'       => 'Landscape',
			'exclude'    => [ $term_id ],
		] );
		$this->assertCount( 1, $matches, 'The nl placeholder must exist, distinct from the primary term.' );
		$nl = $matches[0];
		$this->assertSame( 'nl', get_term_meta( $nl->term_id, LinguaForge::TRANSLATED_TERM_META, true ) );
		$this->assertSame( '1', get_term_meta( $nl->term_id, LinguaForge::TERM_NEEDS_TRANSLATION_META, true ) );
	}

	public function test_drain_skips_a_language_already_linked_without_recreating_it(): void {
		$term_id = $this->insert_term( 'Landscape' );
		$trid    = get_term_meta( $term_id, LinguaForge::TERM_TRID_META, true );
		if ( '' === $trid ) {
			$trid = wp_generate_uuid4();
			add_term_meta( $term_id, LinguaForge::TERM_TRID_META, $trid, true );
		}

		// Simulate a translation already produced by an on-demand "Sync"
		// click between this term's queueing and the cron tick reaching it.
		$already_linked = $this->insert_term( 'Landschaft' );
		add_term_meta( $already_linked, LinguaForge::TERM_TRID_META, $trid, true );
		add_term_meta( $already_linked, LinguaForge::TRANSLATED_TERM_META, 'de', true );

		update_term_meta( $term_id, LinguaForge::TERM_PENDING_TRANSLATION_META, wp_json_encode( [ 'de' ] ) );

		( new LinguaForge() )->drain_translation_queue();

		$this->assertSame( '', get_term_meta( $term_id, LinguaForge::TERM_PENDING_TRANSLATION_META, true ) );
		$this->assertCount(
			1,
			get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false, 'name' => 'Landschaft' ] ),
			'Draining a language that already resolved must not create a duplicate term.'
		);
	}

	public function test_drain_leaves_a_genuinely_failed_language_still_queued(): void {
		$term_id = $this->insert_term( 'Landscape' );
		$this->seed_translation( 'post_tag', 'Landscape', 'de', 'Landschaft' );
		update_term_meta( $term_id, LinguaForge::TERM_PENDING_TRANSLATION_META, wp_json_encode( [ 'de' ] ) );

		$force_failure = static fn () => new \WP_Error( 'db_insert_error', 'Simulated failure for this test.' );
		add_filter( 'pre_insert_term', $force_failure );

		( new LinguaForge() )->drain_translation_queue();

		remove_filter( 'pre_insert_term', $force_failure );

		$this->assertSame(
			[ 'de' ],
			$this->pending_languages( $term_id ),
			'A genuine DB-level insert failure must leave the language queued for the next tick, not drop it silently.'
		);
		$this->assertNull( get_term_by( 'name', 'Landschaft', 'post_tag' ) ?: null, 'Nothing should have been created for the failed language.' );
	}
}
