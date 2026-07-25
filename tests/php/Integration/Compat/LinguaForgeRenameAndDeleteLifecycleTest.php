<?php
/**
 * Integration tests for T5(c)'s two vocabulary-maintenance lifecycles —
 * TAG-REDESIGN.md §4/§8 gap 4: rename and delete used to leave a primary
 * term's translation group in an inconsistent state (a rename orphaned the
 * old translations; a delete left them behind entirely). Both taxonomies
 * (`post_tag`, `agnosis_medium`) share the same trid-group model, so one
 * mechanism covers both.
 *
 * Rename: `invalidate_renamed_term_cache()` (the existing `edit_terms`/
 * `edited_term` rename-detection pair, T5(c) extends rather than
 * duplicates) queues `queue_rename_retranslation()` for a PRIMARY term's
 * rename only — never for a translated sibling's own rename (invariant 4:
 * nothing writes primary-ward from a translation). Only languages that
 * already have a linked sibling are queued (`_agnosis_term_pending_rename`);
 * `drain_rename_queue()` re-translates the (new) primary name and updates
 * each sibling's NAME in place via `wp_update_term()` — trid and slug are
 * never touched.
 *
 * Delete: `cascade_delete_term_group()` (`pre_delete_term`) deletes every
 * other member of a PRIMARY term's trid group when the primary itself is
 * deleted. Gated the same way — deleting a translated sibling directly
 * must never cascade back onto its primary or its other siblings, which is
 * also what stops the nested `wp_delete_term()` calls this method makes
 * from recursing into another cascade.
 *
 * Reuses LinguaForgeTranslationQueueTest's own LF global-stub / cache-seed
 * conventions (Compat/Stubs/lf_global_stubs.php,
 * LinguaForgeCompatTest::$lf_languages, the `agnosis_term_translations`
 * option as the AI-call stand-in for a cache hit).
 *
 * @package Agnosis\Tests\Integration\Compat
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Compat;

use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;

require_once __DIR__ . '/Stubs/lf_global_stubs.php';

class LinguaForgeRenameAndDeleteLifecycleTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			( new Profile() )->register_taxonomy();
		}

		LinguaForgeCompatTest::$lf_languages = [ 'en', 'de', 'nl' ];
		update_option( 'linguaforge_primary_language', 'en' );

		new LinguaForge(); // Registers edit_terms/edited_term/pre_delete_term.
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

	private function seed_translation( string $taxonomy, string $name, string $lang, string $translated ): void {
		$cache = get_option( 'agnosis_term_translations', [] );
		$cache[ $taxonomy ][ $name ][ $lang ] = $translated;
		update_option( 'agnosis_term_translations', $cache, false );
	}

	/** @return string[] */
	private function pending_rename_languages( int $term_id ): array {
		$json = (string) get_term_meta( $term_id, LinguaForge::TERM_PENDING_RENAME_META, true );
		if ( '' === $json ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Links $sibling_id into $primary_id's trid group as $lang — same shape
	 * `drain_translation_queue()` itself produces, built directly here so
	 * these tests don't depend on that method succeeding first.
	 */
	private function link_sibling( int $primary_id, int $sibling_id, string $taxonomy, string $lang ): string {
		$trid = get_term_meta( $primary_id, LinguaForge::TERM_TRID_META, true );
		if ( '' === $trid ) {
			$trid = wp_generate_uuid4();
			add_term_meta( $primary_id, LinguaForge::TERM_TRID_META, $trid, true );
		}
		add_term_meta( $sibling_id, LinguaForge::TERM_TRID_META, $trid, true );
		add_term_meta( $sibling_id, LinguaForge::TRANSLATED_TERM_META, $lang, true );
		return (string) $trid;
	}

	// -------------------------------------------------------------------------
	// Rename — queuing (invalidate_renamed_term_cache()'s T5(c) extension)
	// -------------------------------------------------------------------------

	public function test_renaming_a_primary_term_with_an_existing_sibling_queues_that_language(): void {
		$primary = $this->insert_term( 'Landscape' );
		$sibling = $this->insert_term( 'Landschaft' );
		$this->link_sibling( $primary, $sibling, 'post_tag', 'de' );

		wp_update_term( $primary, 'post_tag', [ 'name' => 'Landscapes' ] );

		$this->assertSame( [ 'de' ], $this->pending_rename_languages( $primary ) );
	}

	public function test_renaming_a_primary_term_with_no_siblings_yet_queues_nothing(): void {
		$primary = $this->insert_term( 'Seascape' );

		wp_update_term( $primary, 'post_tag', [ 'name' => 'Seascapes' ] );

		$this->assertSame( [], $this->pending_rename_languages( $primary ), 'A missing language needs no entry — it will pick up the fresh name whenever it is eventually created.' );
	}

	public function test_renaming_a_primary_term_that_was_never_synced_is_a_no_op(): void {
		$primary = $this->insert_term( 'Etching' );
		// No trid at all — never went through any sync path.

		wp_update_term( $primary, 'post_tag', [ 'name' => 'Etchings' ] );

		$this->assertSame( [], $this->pending_rename_languages( $primary ) );
	}

	public function test_a_no_op_update_that_does_not_change_the_name_queues_nothing(): void {
		$primary = $this->insert_term( 'Mixed Media' );
		$sibling = $this->insert_term( 'Mixed Media DE' );
		$this->link_sibling( $primary, $sibling, 'post_tag', 'de' );

		// Same name, different description — not a rename.
		wp_update_term( $primary, 'post_tag', [ 'name' => 'Mixed Media', 'description' => 'Updated description.' ] );

		$this->assertSame( [], $this->pending_rename_languages( $primary ) );
	}

	public function test_renaming_a_translated_sibling_directly_never_queues_group_retranslation(): void {
		$primary = $this->insert_term( 'Sculpture' );
		$sibling = $this->insert_term( 'Skulptur' );
		$this->link_sibling( $primary, $sibling, 'post_tag', 'de' );

		// An admin hand-editing the SIBLING's own name directly.
		wp_update_term( $sibling, 'post_tag', [ 'name' => 'Skulptur (korrigiert)' ] );

		$this->assertSame( [], $this->pending_rename_languages( $primary ), 'A translated sibling renaming itself must never write back onto the primary (invariant 4).' );
		$this->assertSame( [], $this->pending_rename_languages( $sibling ) );
	}

	// -------------------------------------------------------------------------
	// Rename — draining (drain_rename_queue())
	// -------------------------------------------------------------------------

	public function test_drain_rename_queue_retranslates_and_renames_the_existing_sibling_in_place(): void {
		$primary = $this->insert_term( 'Landscape', 'post_tag' );
		$sibling = $this->insert_term( 'Landschaft', 'post_tag' );
		$trid    = $this->link_sibling( $primary, $sibling, 'post_tag', 'de' );
		$old_slug = get_term( $sibling, 'post_tag' )->slug;

		$this->seed_translation( 'post_tag', 'Landscapes', 'de', 'Landschaften' );
		update_term_meta( $primary, LinguaForge::TERM_PENDING_RENAME_META, wp_json_encode( [ 'de' ] ) );
		// Simulate the rename itself having already happened (drain reads the
		// term's CURRENT name at drain time).
		global $wpdb;
		$wpdb->update( $wpdb->terms, [ 'name' => 'Landscapes' ], [ 'term_id' => $primary ] );
		clean_term_cache( $primary, 'post_tag' );

		( new LinguaForge() )->drain_rename_queue();

		$this->assertSame( '', get_term_meta( $primary, LinguaForge::TERM_PENDING_RENAME_META, true ) );
		$updated = get_term( $sibling, 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $updated );
		$this->assertSame( 'Landschaften', $updated->name, 'The sibling name must update to the fresh translation.' );
		$this->assertSame( $old_slug, $updated->slug, 'Slug must stay stable across a rename — the whole point of machine slugs.' );
		$this->assertSame( $trid, get_term_meta( $sibling, LinguaForge::TERM_TRID_META, true ), 'Trid must stay stable.' );
		$this->assertSame( '', get_term_meta( $sibling, LinguaForge::TERM_NEEDS_TRANSLATION_META, true ) );
	}

	public function test_drain_rename_queue_falls_back_to_the_new_primary_name_and_flags_it_on_ai_failure(): void {
		$primary = $this->insert_term( 'Landscape', 'post_tag' );
		$sibling = $this->insert_term( 'Landschaft', 'post_tag' );
		$this->link_sibling( $primary, $sibling, 'post_tag', 'de' );
		// Deliberately NOT seeding a 'de' translation for 'Landscapes' — no AI
		// provider in the test environment, so translated_term_name() fails.

		update_term_meta( $primary, LinguaForge::TERM_PENDING_RENAME_META, wp_json_encode( [ 'de' ] ) );
		global $wpdb;
		$wpdb->update( $wpdb->terms, [ 'name' => 'Landscapes' ], [ 'term_id' => $primary ] );
		clean_term_cache( $primary, 'post_tag' );

		( new LinguaForge() )->drain_rename_queue();

		$updated = get_term( $sibling, 'post_tag' );
		$this->assertSame( 'Landscapes', $updated->name, 'On AI failure the sibling falls back to the new primary name verbatim.' );
		$this->assertSame( '1', get_term_meta( $sibling, LinguaForge::TERM_NEEDS_TRANSLATION_META, true ) );
		$this->assertSame( '', get_term_meta( $primary, LinguaForge::TERM_PENDING_RENAME_META, true ), 'A fallback still resolves the queue entry — it is a decision, not a failure to retry.' );
	}

	public function test_drain_rename_queue_drops_a_vanished_sibling_without_error(): void {
		$primary = $this->insert_term( 'Landscape', 'post_tag' );
		$sibling = $this->insert_term( 'Landschaft', 'post_tag' );
		$this->link_sibling( $primary, $sibling, 'post_tag', 'de' );

		update_term_meta( $primary, LinguaForge::TERM_PENDING_RENAME_META, wp_json_encode( [ 'de' ] ) );

		// Delete the sibling directly (bypassing cascade) to simulate it
		// vanishing between queuing and the cron tick.
		global $wpdb;
		$wpdb->delete( $wpdb->terms, [ 'term_id' => $sibling ] );
		$wpdb->delete( $wpdb->term_taxonomy, [ 'term_id' => $sibling ] );
		clean_term_cache( $sibling, 'post_tag' );

		( new LinguaForge() )->drain_rename_queue();

		$this->assertSame( '', get_term_meta( $primary, LinguaForge::TERM_PENDING_RENAME_META, true ), 'A vanished sibling must still be dropped from the pending list, not left queued forever.' );
	}

	// -------------------------------------------------------------------------
	// Delete cascade (cascade_delete_term_group())
	// -------------------------------------------------------------------------

	public function test_deleting_a_primary_term_cascades_to_every_sibling_in_its_group(): void {
		$primary  = $this->insert_term( 'Watercolor', 'agnosis_medium' );
		$de       = $this->insert_term( 'Aquarell', 'agnosis_medium' );
		$nl       = $this->insert_term( 'Aquarel', 'agnosis_medium' );
		$this->link_sibling( $primary, $de, 'agnosis_medium', 'de' );
		$this->link_sibling( $primary, $nl, 'agnosis_medium', 'nl' );

		wp_delete_term( $primary, 'agnosis_medium' );

		$this->assertNull( get_term( $primary, 'agnosis_medium' ) ?: null );
		$this->assertNull( get_term( $de, 'agnosis_medium' ) ?: null, 'The German sibling must be cascaded away with the primary.' );
		$this->assertNull( get_term( $nl, 'agnosis_medium' ) ?: null, 'The Dutch sibling must be cascaded away with the primary.' );
	}

	public function test_deleting_a_translated_sibling_directly_never_cascades(): void {
		$primary = $this->insert_term( 'Oil Painting', 'post_tag' );
		$de      = $this->insert_term( 'Ölgemälde', 'post_tag' );
		$nl      = $this->insert_term( 'Olieverfschilderij', 'post_tag' );
		$this->link_sibling( $primary, $de, 'post_tag', 'de' );
		$this->link_sibling( $primary, $nl, 'post_tag', 'nl' );

		wp_delete_term( $de, 'post_tag' );

		$this->assertInstanceOf( \WP_Term::class, get_term( $primary, 'post_tag' ), 'Deleting one sibling must never take the primary down with it.' );
		$this->assertInstanceOf( \WP_Term::class, get_term( $nl, 'post_tag' ), 'Deleting one sibling must never take an unrelated sibling down with it.' );
	}

	public function test_deleting_a_primary_term_never_synced_deletes_cleanly_with_no_cascade(): void {
		$primary = $this->insert_term( 'Gouache', 'post_tag' );
		// No trid — never synced.

		$result = wp_delete_term( $primary, 'post_tag' );

		$this->assertTrue( $result );
	}
}
