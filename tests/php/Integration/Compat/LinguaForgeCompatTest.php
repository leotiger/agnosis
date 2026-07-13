<?php
/**
 * Integration tests for the Lingua Forge compat layer (LinguaForge.php).
 *
 * LF is not installed in the test environment, so this file defines:
 *
 *  • LINGUAFORGE_FILE / LINGUAFORGE_VERSION constants — make is_active() return
 *    true (once defined, PHP constants cannot be undefined, so the inverse branch
 *    is exercised via LinguaForgeCompatNoticesTest which runs without LINGUAFORGE_FILE).
 *
 *  • Global function stubs for linguaforge_languages() and
 *    linguaforge_trigger_translation() — return values are controlled via static
 *    properties on LinguaForgeCompatTest so each test can configure the LF "side"
 *    independently without interference.
 *
 * Coverage targets:
 *   set_language_meta()       — writes _lf_lang from linguaforge_primary_language or locale
 *   request_translations()    — calls linguaforge_trigger_translation once per target
 *   get_target_languages()    — excludes source lang; returns [] when LF unavailable
 *   is_active()               — true when both constants defined
 *   filter_og_image()         — passes through off-artwork; falls back when no thumb
 *   filter_schema_type()      — VisualArtwork on singular artwork, passes through otherwise
 *   supply_translated_meta()  — allowlisted meta only; excludes tokens/identity/lang keys
 *   copy_translated_meta()    — writes allowlist; skips non-Agnosis source; overwrites
 *                               stale values on re-translation (fourth audit, §4b);
 *                               hooked on linguaforge_translation_complete unconditionally
 *                               (not just as a pre-2.4.0 fallback)
 *   sync_taxonomy()           — (via sync_translated_terms()) flags a newly-created
 *                               translated term with TRANSLATED_TERM_META; leaves a
 *                               pre-existing term unflagged (fourth audit, §4c — see
 *                               PromptConfigMediumTermsTest for the filtering side)
 *   clear_term_translations_cache() / term_translation_cache_count() — manual
 *                               cache-clear + count for the Settings panel
 *   invalidate_renamed_term_cache() — drops a term's OLD cached translations when
 *                               it's renamed (not on any other kind of term edit),
 *                               scoped to post_tag/agnosis_medium only (fourth audit, §4d)
 *   sync_translated_template()  — calls linguaforge_sync_templates() with the
 *                               SOURCE post ID and check_caps=false; skips
 *                               non-Agnosis source posts; hooked on
 *                               linguaforge_translation_complete only when
 *                               LINGUAFORGE_VERSION >= 2.6.1 (concern #8)
 *
 * @package Agnosis\Tests\Integration\Compat
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Compat;

use Agnosis\AI\CallCounter;
use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;
use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;

// LF constants and global function stubs live in a separate file to satisfy
// Universal.Files.SeparateFunctionsFromOO (no function declarations alongside OO).
require_once __DIR__ . '/Stubs/lf_global_stubs.php';

// Shared fake WordPress AI Client stub (Providers\WordPressAI needs no API
// key) — used only by the build_title_translations() "with a configured
// provider" tests below; every other test in this file relies on
// SubmissionTranslator::from_settings() being null, unaffected by this.
require_once __DIR__ . '/../AI/Stubs/WpAiClientTestRegistry.php';
require_once __DIR__ . '/../AI/Stubs/wp_ai_provider_namespace_stubs.php';

class LinguaForgeCompatTest extends \WP_UnitTestCase {

	// ── Stubs state (reset in tearDown) ───────────────────────────────────────

	/** Active routing languages returned by linguaforge_languages(). */
	public static ?array $lf_languages = null;

	/** Calls recorded by linguaforge_trigger_translation(). */
	public static array $trigger_calls = [];

	/** Calls recorded by linguaforge_queue_translation() (LF 2.4.0+). */
	public static array $queue_calls = [];

	/** Calls recorded by linguaforge_sync_templates() (LF 2.6.1+). */
	public static array $sync_templates_calls = [];

	/** Return value for linguaforge_trigger_translation (int post ID or WP_Error). */
	public static int|\WP_Error|null $trigger_return = null;

	// ── Test post IDs ─────────────────────────────────────────────────────────

	private int $artwork_id;
	private int $page_id;

	// ── Lifecycle ─────────────────────────────────────────────────────────────

	protected function setUp(): void {
		parent::setUp();

		// Belt-and-suspenders: agnosis_medium is registered globally by
		// Profile::register_taxonomy() on 'init', which should already have
		// fired long before any test class runs — explicit here anyway (2026-07-08,
		// same reasoning as ActivatorTest's own defensive registration) since the
		// sync_translated_terms() medium tests below need real term assignment.
		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			( new Profile() )->register_taxonomy();
		}

		self::$lf_languages  = null;
		self::$trigger_calls = [];
		self::$queue_calls   = [];
		self::$sync_templates_calls = [];
		self::$trigger_return = null;
		FakeLinguaForge::reset();

		$this->artwork_id = self::factory()->post->create( [
			'post_type'    => 'agnosis_artwork',
			'post_status'  => 'publish',
			'post_excerpt' => 'A vivid oil painting of coastal cliffs.',
		] );

		$this->page_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
		] );
	}

	protected function tearDown(): void {
		self::$lf_languages   = null;
		self::$trigger_calls  = [];
		self::$queue_calls    = [];
		self::$sync_templates_calls = [];
		self::$trigger_return = null;
		FakeLinguaForge::reset();
		delete_option( 'agnosis_term_translations' );
		parent::tearDown();
	}

	// ── is_active() ───────────────────────────────────────────────────────────

	public function test_is_active_returns_true_when_both_constants_defined(): void {
		$this->assertTrue( LinguaForge::is_active() );
	}

	// ── set_language_meta() ───────────────────────────────────────────────────
	// _lf_lang = the language content is normalised to at intake = the
	// `linguaforge_primary_language` option (falling back to the WP locale).
	// There is no source-language detection (audit §3d / C-4).

	public function test_set_language_meta_falls_back_to_site_locale(): void {
		// No linguaforge_primary_language set — derive from get_locale().
		// WP test env defaults to en_US → 'en'.
		( new LinguaForge() )->set_language_meta( $this->artwork_id );

		$this->assertSame( 'en', get_post_meta( $this->artwork_id, '_lf_lang', true ) );
	}

	public function test_set_language_meta_skips_non_agnosis_post_types(): void {
		update_option( 'linguaforge_primary_language', 'de' );

		( new LinguaForge() )->set_language_meta( $this->page_id );

		$this->assertSame( '', get_post_meta( $this->page_id, '_lf_lang', true ) );

		delete_option( 'linguaforge_primary_language' );
	}

	public function test_set_language_meta_works_for_biography_post_type(): void {
		update_option( 'linguaforge_primary_language', 'de' );
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'publish',
		] );

		( new LinguaForge() )->set_language_meta( $bio_id );

		$this->assertSame( 'de', get_post_meta( $bio_id, '_lf_lang', true ) );

		delete_option( 'linguaforge_primary_language' );
	}

	public function test_set_language_meta_works_for_event_post_type(): void {
		update_option( 'linguaforge_primary_language', 'es' );
		$event_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
		] );

		( new LinguaForge() )->set_language_meta( $event_id );

		$this->assertSame( 'es', get_post_meta( $event_id, '_lf_lang', true ) );

		delete_option( 'linguaforge_primary_language' );
	}

	// ── request_translations() ────────────────────────────────────────────────
	// linguaforge_queue_translation() is stubbed, so request_translations() prefers
	// the async queue → assertions read self::$queue_calls.

	public function test_request_translations_queues_once_per_target_language(): void {
		self::$lf_languages = [ 'en', 'es', 'fr' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$this->assertCount( 2, self::$queue_calls );
		$langs = array_column( self::$queue_calls, 'target_lang' );
		$this->assertContains( 'es', $langs );
		$this->assertContains( 'fr', $langs );
		$this->assertNotContains( 'en', $langs );
		// Async path preferred — the synchronous trigger is not used.
		$this->assertCount( 0, self::$trigger_calls );
	}

	public function test_request_translations_passes_correct_post_id(): void {
		self::$lf_languages = [ 'en', 'de' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$this->assertSame( $this->artwork_id, self::$queue_calls[0]['post_id'] );
	}

	public function test_request_translations_passes_no_params(): void {
		// LF reads only force_refresh / force_draft / with_meta_description; the old
		// domain/priority/source params did nothing, so we now pass none and let LF
		// default. The translated excerpt LF produces is the artwork's description,
		// so with_meta_description is intentionally not set.
		self::$lf_languages = [ 'en', 'it' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$this->assertSame( [], self::$queue_calls[0]['params'] );
	}

	public function test_request_translations_skips_when_no_target_languages(): void {
		self::$lf_languages = [ 'en' ]; // source is 'en', nothing left
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$this->assertCount( 0, self::$queue_calls );
	}

	public function test_request_translations_skips_when_lf_languages_empty(): void {
		self::$lf_languages = [];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$this->assertCount( 0, self::$queue_calls );
	}

	public function test_request_translations_skips_non_agnosis_post_types(): void {
		self::$lf_languages = [ 'en', 'fr' ];

		( new LinguaForge() )->request_translations( $this->page_id );

		$this->assertCount( 0, self::$queue_calls );
	}

	public function test_request_translations_falls_back_to_en_when_lf_lang_absent(): void {
		// _lf_lang not set — should default to 'en' and exclude it from targets.
		self::$lf_languages = [ 'en', 'pt' ];

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$this->assertCount( 1, self::$queue_calls );
		$this->assertSame( 'pt', self::$queue_calls[0]['target_lang'] );
	}

	// ── schedule_translations() / dispatch_translations() ─────────────────────

	public function test_schedule_translations_schedules_cron_for_agnosis_post(): void {
		( new LinguaForge() )->schedule_translations( $this->artwork_id );

		$this->assertNotFalse(
			wp_next_scheduled( 'agnosis_dispatch_lf_translations', [ $this->artwork_id ] )
		);
		// Scheduling does no AI work inline.
		$this->assertCount( 0, self::$queue_calls );
	}

	public function test_schedule_translations_skips_non_agnosis_post_types(): void {
		( new LinguaForge() )->schedule_translations( $this->page_id );

		$this->assertFalse(
			wp_next_scheduled( 'agnosis_dispatch_lf_translations', [ $this->page_id ] )
		);
	}

	public function test_dispatch_translations_queues_the_body_translations(): void {
		self::$lf_languages = [ 'en', 'es' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->dispatch_translations( $this->artwork_id );

		$this->assertCount( 1, self::$queue_calls );
		$this->assertSame( 'es', self::$queue_calls[0]['target_lang'] );
	}

	// ── $exclude_langs plumbing (native-language pipeline, Phase 4/6 —
	// agnosis-audit/NATIVE-LANGUAGE-PIPELINE.md §4d/§6) ───────────────────────
	//
	// finalize_publish() computes $exclude_langs from a post's own
	// _agnosis_native_lang and passes it through do_action('agnosis_post_published',
	// $post_id, $exclude_langs) / LinguaForge::schedule_fanout() directly — these
	// tests confirm that value actually reaches the queued cron event and, once
	// dispatched, is honored by request_translations() rather than being lost or
	// ignored anywhere along the chain.

	public function test_schedule_fanout_with_exclude_langs_schedules_cron_carrying_both_args(): void {
		LinguaForge::schedule_fanout( $this->artwork_id, [ 'es' ] );

		$this->assertNotFalse(
			wp_next_scheduled( 'agnosis_dispatch_lf_translations', [ $this->artwork_id, [ 'es' ] ] ),
			'A non-empty exclude list must be scheduled as the cron event\'s second arg — this is exactly what dispatch_translations()/request_translations() receive at dispatch time.'
		);
	}

	public function test_schedule_fanout_without_exclude_langs_keeps_the_original_one_arg_signature(): void {
		LinguaForge::schedule_fanout( $this->artwork_id );

		$this->assertNotFalse( wp_next_scheduled( 'agnosis_dispatch_lf_translations', [ $this->artwork_id ] ) );
		$this->assertFalse(
			wp_next_scheduled( 'agnosis_dispatch_lf_translations', [ $this->artwork_id, [] ] ),
			'Must not schedule under the two-arg shape with an empty array — wp_next_scheduled()\'s dedup match is keyed on the exact args array, so this would silently stop matching the pre-existing one-arg lookups every normal (non-excluding) publish path already relies on.'
		);
	}

	public function test_request_translations_with_exclude_langs_skips_source_and_excluded_languages(): void {
		self::$lf_languages = [ 'en', 'es', 'fr', 'de' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->request_translations( $this->artwork_id, [ 'es' ] );

		$this->assertCount( 2, self::$queue_calls );
		$langs = array_column( self::$queue_calls, 'target_lang' );
		$this->assertContains( 'fr', $langs );
		$this->assertContains( 'de', $langs );
		$this->assertNotContains( 'es', $langs, 'The excluded (native) language must never be queued for LF\'s own AI translation — a native-language sibling is synced directly instead (sync_native_sibling()).' );
		$this->assertNotContains( 'en', $langs, 'The source language is always excluded regardless of $exclude_langs.' );
	}

	public function test_dispatch_translations_forwards_exclude_langs_to_request_translations(): void {
		self::$lf_languages = [ 'en', 'es', 'fr' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->dispatch_translations( $this->artwork_id, [ 'es' ] );

		$langs = array_column( self::$queue_calls, 'target_lang' );
		$this->assertContains( 'fr', $langs );
		$this->assertNotContains( 'es', $langs, 'dispatch_translations() must forward $exclude_langs through to request_translations(), not just accept and drop it.' );
	}

	// ── filter_og_image() ────────────────────────────────────────────────────
	// Current signature is filter_og_image( string $image_url ): string and it
	// reads the queried post via get_post(), so tests drive it through go_to().

	public function test_filter_og_image_passes_through_off_artwork(): void {
		$this->go_to( get_permalink( $this->page_id ) );

		$result = ( new LinguaForge() )->filter_og_image( 'https://example.com/default.jpg' );

		$this->assertSame( 'https://example.com/default.jpg', $result );
	}

	public function test_filter_og_image_falls_back_when_artwork_has_no_thumbnail(): void {
		$this->go_to( get_permalink( $this->artwork_id ) );

		$result = ( new LinguaForge() )->filter_og_image( 'https://example.com/default.jpg' );

		// No featured image → get_the_post_thumbnail_url() returns false → original kept.
		$this->assertSame( 'https://example.com/default.jpg', $result );
	}

	// ── filter_schema_type() ─────────────────────────────────────────────────
	// Current signature is filter_schema_type( array $data, string $type ): array
	// and it keys off is_singular( 'agnosis_artwork' ), so tests use go_to().

	public function test_filter_schema_type_sets_visual_artwork_on_singular_artwork(): void {
		$this->go_to( get_permalink( $this->artwork_id ) );

		$result = ( new LinguaForge() )->filter_schema_type( [ '@type' => 'Article' ], 'Article' );

		$this->assertSame( 'VisualArtwork', $result['@type'] );
	}

	public function test_filter_schema_type_passes_through_on_non_artwork_singular(): void {
		$this->go_to( get_permalink( $this->page_id ) );

		$result = ( new LinguaForge() )->filter_schema_type( [ '@type' => 'WebPage' ], 'WebPage' );

		$this->assertSame( 'WebPage', $result['@type'] );
	}

	public function test_filter_schema_type_passes_through_off_singular(): void {
		// Not on any singular view → untouched.
		$result = ( new LinguaForge() )->filter_schema_type( [ '@type' => 'Article' ], 'Article' );

		$this->assertSame( 'Article', $result['@type'] );
	}

	// ── Translated-post meta propagation (§2a) ────────────────────────────────

	public function test_supply_translated_meta_copies_allowlisted_keys_for_artwork(): void {
		update_post_meta( $this->artwork_id, '_thumbnail_id', 4242 );
		update_post_meta( $this->artwork_id, '_agnosis_gallery_ids', [ 11, 22 ] );

		$meta = ( new LinguaForge() )->supply_translated_meta( [], $this->artwork_id, 'es', 'agnosis_artwork' );

		$this->assertEquals( 4242, $meta['_thumbnail_id'] );
		$this->assertEquals( [ 11, 22 ], $meta['_agnosis_gallery_ids'] );
	}

	public function test_supply_translated_meta_excludes_security_and_identity_keys(): void {
		update_post_meta( $this->artwork_id, '_thumbnail_id', 4242 );
		update_post_meta( $this->artwork_id, '_agnosis_review_token', 'secret-token' );
		update_post_meta( $this->artwork_id, '_agnosis_removal_token', 'removal-token' );
		update_post_meta( $this->artwork_id, '_agnosis_queue_id', '777' );
		update_post_meta( $this->artwork_id, '_agnosis_image_hash', 'deadbeef' );
		update_post_meta( $this->artwork_id, '_agnosis_translated_title', 'AI title (source lang)' );
		update_post_meta( $this->artwork_id, '_agnosis_detected_lang', 'en' );

		$meta = ( new LinguaForge() )->supply_translated_meta( [], $this->artwork_id, 'es', 'agnosis_artwork' );

		$this->assertArrayHasKey( '_thumbnail_id', $meta );
		$this->assertArrayNotHasKey( '_agnosis_review_token', $meta );
		$this->assertArrayNotHasKey( '_agnosis_removal_token', $meta );
		$this->assertArrayNotHasKey( '_agnosis_queue_id', $meta );
		$this->assertArrayNotHasKey( '_agnosis_image_hash', $meta );
		$this->assertArrayNotHasKey( '_agnosis_translated_title', $meta );
		$this->assertArrayNotHasKey( '_agnosis_detected_lang', $meta );
	}

	public function test_supply_translated_meta_passes_through_for_non_agnosis_type(): void {
		update_post_meta( $this->page_id, '_thumbnail_id', 4242 );

		$meta = ( new LinguaForge() )->supply_translated_meta( [ 'x' => 1 ], $this->page_id, 'es', 'page' );

		$this->assertSame( [ 'x' => 1 ], $meta );
	}

	public function test_supply_translated_meta_includes_event_keys_for_event(): void {
		$event_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
		] );
		update_post_meta( $event_id, '_agnosis_event_location', 'Barcelona' );
		update_post_meta( $event_id, '_agnosis_event_date', '2026-07-15' );

		$meta = ( new LinguaForge() )->supply_translated_meta( [], $event_id, 'fr', 'agnosis_event' );

		$this->assertSame( 'Barcelona', $meta['_agnosis_event_location'] );
		$this->assertSame( '2026-07-15', $meta['_agnosis_event_date'] );
	}

	public function test_copy_translated_meta_writes_allowlist_to_translated_post(): void {
		update_post_meta( $this->artwork_id, '_thumbnail_id', 4242 );
		update_post_meta( $this->artwork_id, '_agnosis_review_token', 'secret-token' );

		$translated_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
		] );

		( new LinguaForge() )->copy_translated_meta( $translated_id, $this->artwork_id, 'es' );

		$this->assertEquals( 4242, get_post_meta( $translated_id, '_thumbnail_id', true ) );
		$this->assertSame( '', (string) get_post_meta( $translated_id, '_agnosis_review_token', true ) );
	}

	public function test_copy_translated_meta_skips_non_agnosis_source(): void {
		update_post_meta( $this->page_id, '_thumbnail_id', 4242 );

		$translated_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
		] );

		( new LinguaForge() )->copy_translated_meta( $translated_id, $this->page_id, 'es' );

		$this->assertSame( '', (string) get_post_meta( $translated_id, '_thumbnail_id', true ) );
	}

	/**
	 * Fourth audit §4b: the concrete production symptom was a `replace@` photo
	 * swap leaving OLD images live on every translated page — the translated
	 * sibling already has meta from its first translation pass, and the fix's
	 * whole point is that a second pass must overwrite it, not just fill it in
	 * when absent (which the pre-fix "write allowlist" tests above don't
	 * distinguish from a genuine refresh).
	 */
	public function test_copy_translated_meta_overwrites_stale_values_on_retranslation(): void {
		update_post_meta( $this->artwork_id, '_thumbnail_id', 9999 ); // new photo post-replace@

		$translated_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
		] );
		update_post_meta( $translated_id, '_thumbnail_id', 1111 ); // stale, pre-replace@ photo

		( new LinguaForge() )->copy_translated_meta( $translated_id, $this->artwork_id, 'es' );

		$this->assertEquals( 9999, get_post_meta( $translated_id, '_thumbnail_id', true ) );
	}

	/**
	 * Fourth audit §4b: on LF >= 2.4.0, copy_translated_meta() must be hooked
	 * on `linguaforge_translation_complete` UNCONDITIONALLY, not only as a
	 * pre-2.4.0 fallback — LF's update_translated_post() (the re-translation
	 * path) never fires `linguaforge_translated_post_meta` at any LF version,
	 * so the born-with filter alone misses every re-translation.
	 *
	 * Caveat: LINGUAFORGE_VERSION is a PHP constant defined once for this test
	 * process (here, and by several other Integration test files) at
	 * '1.0.0-test' — always below 2.4.0 — so this test cannot itself cross the
	 * 2.4.0 boundary. What it guards against is regressing back to the old
	 * `if (>= 2.4.0) { filter } else { action }` fork, which — combined with
	 * test_copy_translated_meta_overwrites_stale_values_on_retranslation()
	 * above proving the method's refresh behavior is correct once called —
	 * closes the gap: were the constructor to ever gate this action behind an
	 * LF-version check again, a real LF >= 2.4.0 site would silently lose
	 * re-translation refresh exactly as the audit found, even though every
	 * test in this file would still pass under the fixed '1.0.0-test' stub.
	 */
	public function test_copy_translated_meta_is_hooked_on_translation_complete_unconditionally(): void {
		$lf = new LinguaForge();

		$this->assertNotFalse(
			has_action( 'linguaforge_translation_complete', [ $lf, 'copy_translated_meta' ] ),
			'copy_translated_meta() must be registered on linguaforge_translation_complete regardless of LINGUAFORGE_VERSION.'
		);
	}

	// ── sync_translated_template(): template safeguard (LF >= 2.6.1 only) ──────

	/**
	 * sync_translated_template() must call LF's linguaforge_sync_templates()
	 * with the SOURCE (primary-language) post ID, not the translated post ID —
	 * that function walks every OTHER language in the source's own
	 * translation group internally and errors on a secondary-language ID (see
	 * this method's own docblock), so passing $translated_id here would be a
	 * silent no-op against LF's real implementation.
	 */
	public function test_sync_translated_template_calls_linguaforge_sync_templates_with_source_id(): void {
		( new LinguaForge() )->sync_translated_template( 4242, $this->artwork_id, 'es' );

		$this->assertCount( 1, self::$sync_templates_calls );
		$this->assertSame( $this->artwork_id, self::$sync_templates_calls[0]['post_id'] );
		$this->assertFalse(
			self::$sync_templates_calls[0]['check_caps'],
			'Programmatic callers must not enforce current_user_can(), matching linguaforge_sync_templates()\'s own $check_caps convention for non-request contexts.'
		);
	}

	public function test_sync_translated_template_skips_non_agnosis_source(): void {
		( new LinguaForge() )->sync_translated_template( 4242, $this->page_id, 'es' );

		$this->assertSame( [], self::$sync_templates_calls );
	}

	/**
	 * This class's docblock (concern #8) documents sync_translated_template()
	 * as hooked on linguaforge_translation_complete ONLY when LINGUAFORGE_VERSION
	 * >= 2.6.1 — the version linguaforge_sync_templates() was introduced in.
	 *
	 * Caveat mirrors test_copy_translated_meta_is_hooked_on_translation_complete_unconditionally()'s
	 * own: LINGUAFORGE_VERSION is a PHP constant fixed at '1.0.0-test' for this
	 * entire test process (here and in several other Integration test files),
	 * always below 2.6.1 — so this test cannot cross the boundary in the other
	 * direction (it can't prove the hook IS added at >= 2.6.1). What it DOES
	 * prove is the gate's negative case: below 2.6.1, the hook must NOT be
	 * registered, guarding against ever hooking this unconditionally (which
	 * would call an undefined LF function on any pre-2.6.1 site running
	 * alongside Agnosis, before function_exists() inside the method itself
	 * even gets a chance to no-op it — the method's own guard is a second,
	 * independent safety net, not a substitute for gating the hook itself).
	 */
	public function test_sync_translated_template_is_not_hooked_below_2_6_1(): void {
		$lf = new LinguaForge();

		$this->assertFalse(
			has_action( 'linguaforge_translation_complete', [ $lf, 'sync_translated_template' ] ),
			'sync_translated_template() must not be registered while LINGUAFORGE_VERSION is below 2.6.1.'
		);
	}

	// ── set_language_meta(): primary-language alignment (§2d residual) ─────────

	public function test_set_language_meta_uses_primary_language_option(): void {
		update_option( 'linguaforge_primary_language', 'de' );

		( new LinguaForge() )->set_language_meta( $this->artwork_id );

		$this->assertSame( 'de', get_post_meta( $this->artwork_id, '_lf_lang', true ) );

		delete_option( 'linguaforge_primary_language' );
	}

	public function test_set_language_meta_prefers_primary_language_over_locale(): void {
		// Primary language set to something other than the site locale (en_US → en).
		update_option( 'linguaforge_primary_language', 'ca' );

		( new LinguaForge() )->set_language_meta( $this->artwork_id );

		$this->assertSame( 'ca', get_post_meta( $this->artwork_id, '_lf_lang', true ) );

		delete_option( 'linguaforge_primary_language' );
	}

	// ── Dual-title: hold_artist_title() ───────────────────────────────────────

	public function test_hold_artist_title_keeps_artist_original_for_artwork(): void {
		$art_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_title'  => 'Mar i Cel',
		] );

		$payload = [ 'output' => '<p>…</p>', 'translated_title' => 'Sea and Sky (bad source)' ];
		$result  = ( new LinguaForge() )->hold_artist_title( $payload, $art_id, 'es' );

		$this->assertSame( 'Mar i Cel', $result['translated_title'] );
	}

	public function test_hold_artist_title_passes_through_non_agnosis(): void {
		$payload = [ 'output' => '<p>…</p>', 'translated_title' => 'Translated' ];
		$result  = ( new LinguaForge() )->hold_artist_title( $payload, $this->page_id, 'es' );

		$this->assertSame( 'Translated', $result['translated_title'] );
	}

	public function test_hold_artist_title_keeps_artist_original_for_event(): void {
		// 0.9.24: event joined DUAL_TITLE_POST_TYPES alongside artwork — an
		// event's own name is the artist's own words and must survive
		// translation untouched, exactly like test_hold_artist_title_keeps_
		// artist_original_for_artwork() above.
		$event_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_title'  => 'Cruzant el Llindar',
		] );

		$payload = [ 'output' => '<p>…</p>', 'translated_title' => 'Crossing the Threshold (bad source)' ];
		$result  = ( new LinguaForge() )->hold_artist_title( $payload, $event_id, 'es' );

		$this->assertSame( 'Cruzant el Llindar', $result['translated_title'] );
	}

	public function test_hold_artist_title_passes_through_for_biography(): void {
		// Dual-title doesn't apply to biography — it uses LF's normal title
		// translation, so the AI's translated_title is left untouched.
		$id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'publish',
			'post_title'  => 'Original',
		] );

		$payload = [ 'output' => '<p>…</p>', 'translated_title' => 'Translated Title' ];
		$result  = ( new LinguaForge() )->hold_artist_title( $payload, $id, 'es' );

		$this->assertSame( 'Translated Title', $result['translated_title'] );
	}

	// ── Per-language display title from the title map ─────────────────────────

	public function test_supply_translated_meta_adds_per_language_title_from_map(): void {
		update_post_meta( $this->artwork_id, '_agnosis_title_i18n', [ 'es' => 'Título ES', 'fr' => 'Titre FR' ] );

		$meta = ( new LinguaForge() )->supply_translated_meta( [], $this->artwork_id, 'es', 'agnosis_artwork' );

		$this->assertSame( 'Título ES', $meta['_agnosis_translated_title'] );
	}

	public function test_supply_translated_meta_omits_title_when_map_lacks_target(): void {
		update_post_meta( $this->artwork_id, '_agnosis_title_i18n', [ 'fr' => 'Titre FR' ] );

		$meta = ( new LinguaForge() )->supply_translated_meta( [], $this->artwork_id, 'es', 'agnosis_artwork' );

		$this->assertArrayNotHasKey( '_agnosis_translated_title', $meta );
	}

	public function test_copy_translated_meta_writes_per_language_title(): void {
		update_post_meta( $this->artwork_id, '_agnosis_title_i18n', [ 'es' => 'Título ES' ] );

		$translated_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
		] );

		( new LinguaForge() )->copy_translated_meta( $translated_id, $this->artwork_id, 'es' );

		$this->assertSame( 'Título ES', get_post_meta( $translated_id, '_agnosis_translated_title', true ) );
	}

	// ── build_title_translations(): graceful guards (no provider in test env) ──

	public function test_build_title_translations_skips_without_provider(): void {
		self::$lf_languages = [ 'en', 'es' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );
		update_post_meta( $this->artwork_id, '_agnosis_translated_title', 'A Title' );

		// No agnosis_*_api_key configured → SubmissionTranslator::from_settings() is null.
		( new LinguaForge() )->build_title_translations( $this->artwork_id );

		$this->assertSame( '', (string) get_post_meta( $this->artwork_id, '_agnosis_title_i18n', true ) );
	}

	public function test_build_title_translations_skips_non_agnosis(): void {
		self::$lf_languages = [ 'en', 'es' ];
		update_post_meta( $this->page_id, '_agnosis_translated_title', 'A Title' );

		( new LinguaForge() )->build_title_translations( $this->page_id );

		$this->assertSame( '', (string) get_post_meta( $this->page_id, '_agnosis_title_i18n', true ) );
	}

	public function test_build_title_translations_skips_biography(): void {
		// Biography never gets a per-language title map — it's outside
		// DUAL_TITLE_POST_TYPES (event joined artwork there in 0.9.24; see
		// test_build_title_translations_invokes_translate_to_languages_and_stores_the_map_for_event()
		// below for proof event now DOES get one with a provider configured).
		self::$lf_languages = [ 'en', 'es' ];

		$id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'publish',
		] );
		update_post_meta( $id, '_lf_lang', 'en' );
		update_post_meta( $id, '_agnosis_translated_title', 'A Title' );

		( new LinguaForge() )->build_title_translations( $id );

		$this->assertSame( '', (string) get_post_meta( $id, '_agnosis_title_i18n', true ) );
	}

	// ── Tag / medium term translation: sync_translated_terms() (§5) ───────────
	// No agnosis_*_api_key is configured in the test env, so
	// SubmissionTranslator::from_settings() is null and translated_term_name()
	// falls back to the original term name — this exercises the "never block
	// the sync on a missing provider" path. The cache-hit test below exercises
	// the translated-name path without needing a real provider, since a cache
	// hit short-circuits before from_settings() is ever consulted.
	//
	// Every wp_get_post_terms() call below passes 'hide_empty' => false: WP's
	// default (true) filters by term_taxonomy.count, which a term freshly
	// assigned via wp_set_object_terms() within the same request can still
	// read back as 0 — a stale-count false negative, not a real "no
	// relationship" case (found the hard way: the assign-a-real-term tests
	// failed with an empty array without this; the expect-empty tests were
	// unaffected either way).

	public function test_sync_translated_terms_skips_non_agnosis_post_types(): void {
		wp_set_object_terms( $this->page_id, [ 'Landscape' ], 'post_tag' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->page_id, 'es' );

		$this->assertSame( [], wp_get_post_terms( $translated_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ) );
	}

	public function test_sync_translated_terms_copies_tags_for_artwork(): void {
		wp_set_object_terms( $this->artwork_id, [ 'Landscape', 'Coastal' ], 'post_tag' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'es' );

		// No AI provider configured — falls back to the original names.
		$names = wp_get_post_terms( $translated_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] );
		$this->assertCount( 2, $names );
		$this->assertContains( 'Landscape', $names );
		$this->assertContains( 'Coastal', $names );
	}

	public function test_sync_translated_terms_copies_tags_for_biography_and_event(): void {
		foreach ( [ 'agnosis_biography', 'agnosis_event' ] as $type ) {
			$source_id = self::factory()->post->create( [ 'post_type' => $type, 'post_status' => 'publish' ] );
			wp_set_object_terms( $source_id, [ 'Interview' ], 'post_tag' );
			$translated_id = self::factory()->post->create( [ 'post_type' => $type, 'post_status' => 'publish' ] );

			( new LinguaForge() )->sync_translated_terms( $translated_id, $source_id, 'fr' );

			$this->assertSame(
				[ 'Interview' ],
				wp_get_post_terms( $translated_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ),
				$type
			);
		}
	}

	public function test_sync_translated_terms_copies_medium_for_artwork(): void {
		wp_set_object_terms( $this->artwork_id, [ 'Oil Painting' ], 'agnosis_medium' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'de' );

		$this->assertSame(
			[ 'Oil Painting' ],
			wp_get_post_terms( $translated_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] )
		);
	}

	public function test_sync_translated_terms_skips_medium_for_biography_and_event(): void {
		// agnosis_medium isn't even registered against these post types, but
		// this confirms sync_translated_terms() only ever attempts it for
		// agnosis_artwork (see the post-type guard in the method itself).
		foreach ( [ 'agnosis_biography', 'agnosis_event' ] as $type ) {
			$source_id     = self::factory()->post->create( [ 'post_type' => $type, 'post_status' => 'publish' ] );
			$translated_id = self::factory()->post->create( [ 'post_type' => $type, 'post_status' => 'publish' ] );

			( new LinguaForge() )->sync_translated_terms( $translated_id, $source_id, 'fr' );

			$this->assertSame(
				[],
				wp_get_post_terms( $translated_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ),
				$type
			);
		}
	}

	public function test_sync_translated_terms_clears_translated_terms_when_source_has_none(): void {
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		wp_set_object_terms( $translated_id, [ 'Stale Tag' ], 'post_tag' );

		// Source artwork has no tags at all.
		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'es' );

		$this->assertSame( [], wp_get_post_terms( $translated_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ) );
	}

	public function test_sync_translated_terms_overwrites_stale_terms_on_retranslation(): void {
		wp_set_object_terms( $this->artwork_id, [ 'Coastal' ], 'post_tag' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		wp_set_object_terms( $translated_id, [ 'Old Stale Tag' ], 'post_tag' );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'es' );

		$names = wp_get_post_terms( $translated_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] );
		$this->assertSame( [ 'Coastal' ], $names );
		$this->assertNotContains( 'Old Stale Tag', $names );
	}

	public function test_sync_translated_terms_uses_cached_translation(): void {
		// Pre-seed the cache so translated_term_name() short-circuits before
		// ever consulting SubmissionTranslator::from_settings() — this is how
		// the translated (non-fallback) path is exercised without a real AI
		// provider configured in the test env.
		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => 'Paisaje' ] ],
		] );
		wp_set_object_terms( $this->artwork_id, [ 'Landscape' ], 'post_tag' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'es' );

		$this->assertSame(
			[ 'Paisaje' ],
			wp_get_post_terms( $translated_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] )
		);

		delete_option( 'agnosis_term_translations' );
	}

	public function test_sync_translated_terms_cache_is_scoped_per_language(): void {
		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => 'Paisaje' ] ],
		] );
		wp_set_object_terms( $this->artwork_id, [ 'Landscape' ], 'post_tag' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		// Requesting 'fr' — no cache entry for that language — falls back to
		// the original name (no provider configured), NOT the Spanish cache hit.
		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'fr' );

		$this->assertSame(
			[ 'Landscape' ],
			wp_get_post_terms( $translated_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] )
		);

		delete_option( 'agnosis_term_translations' );
	}

	// ── §4c: newly-created translated terms are flagged for exclusion ────────
	// SubmissionTranslator::from_settings() is null in this test env (no AI
	// provider configured), so translated_term_name() only ever produces a
	// name DIFFERENT from the source when a cache entry supplies one — same
	// mechanism the cache tests above already use. That's exploited here to
	// get a genuinely new term name out of sync_taxonomy() without a real
	// provider call.

	public function test_sync_taxonomy_flags_newly_created_term_as_translated(): void {
		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => 'Paisaje' ] ],
		] );
		wp_set_object_terms( $this->artwork_id, [ 'Landscape' ], 'post_tag' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'es' );

		$term = get_term_by( 'name', 'Paisaje', 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( 'es', get_term_meta( $term->term_id, LinguaForge::TRANSLATED_TERM_META, true ) );

		delete_option( 'agnosis_term_translations' );
	}

	public function test_sync_taxonomy_flags_newly_created_medium_term_as_translated(): void {
		update_option( 'agnosis_term_translations', [
			'agnosis_medium' => [ 'Oil Painting' => [ 'de' => 'Ölgemälde' ] ],
		] );
		wp_set_object_terms( $this->artwork_id, [ 'Oil Painting' ], 'agnosis_medium' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'de' );

		$term = get_term_by( 'name', 'Ölgemälde', 'agnosis_medium' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( 'de', get_term_meta( $term->term_id, LinguaForge::TRANSLATED_TERM_META, true ) );

		delete_option( 'agnosis_term_translations' );
	}

	public function test_sync_taxonomy_does_not_flag_a_pre_existing_term(): void {
		// No AI provider configured — 'Landscape' and 'Coastal' are assigned
		// to the translated post unchanged (translated_term_name() falls back
		// to the source name), so both terms already existed BEFORE
		// sync_taxonomy() ran (created when assigned to the source post
		// above). Neither should come out flagged as machine-translated.
		wp_set_object_terms( $this->artwork_id, [ 'Landscape', 'Coastal' ], 'post_tag' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'es' );

		foreach ( [ 'Landscape', 'Coastal' ] as $name ) {
			$term = get_term_by( 'name', $name, 'post_tag' );
			$this->assertInstanceOf( \WP_Term::class, $term, $name );
			$this->assertSame( '', get_term_meta( $term->term_id, LinguaForge::TRANSLATED_TERM_META, true ), $name );
		}
	}

	// ── G-2: AI-call instrumentation ──────────────────────────────────────────

	public function test_count_fanout_translation_call_records_a_genuine_translation(): void {
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new LinguaForge() )->count_fanout_translation_call( $translated_id, $this->artwork_id, 'es' );

		$this->assertSame( 1, CallCounter::get_total( $this->artwork_id ), 'A genuine LF-driven fan-out completion (suppression flag not set) must increment the source post\'s AI-call counter.' );
	}

	public function test_count_fanout_translation_call_accumulates_across_languages(): void {
		$translated_id_es = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		$translated_id_fr = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		$lf = new LinguaForge();
		$lf->count_fanout_translation_call( $translated_id_es, $this->artwork_id, 'es' );
		$lf->count_fanout_translation_call( $translated_id_fr, $this->artwork_id, 'fr' );

		$this->assertSame( 2, CallCounter::get_total( $this->artwork_id ), 'Each language the fan-out actually translates is a separate AI call and must accumulate onto the same source post.' );
	}

	public function test_count_fanout_translation_call_skips_non_agnosis_post_types(): void {
		$translated_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );

		( new LinguaForge() )->count_fanout_translation_call( $translated_id, $this->page_id, 'es' );

		$this->assertSame( 0, CallCounter::get_total( $this->page_id ) );
	}

	/**
	 * sync_native_sibling() fires 'linguaforge_translation_complete' synthetically
	 * for the sibling it just built directly — no AI call at all, the entire
	 * point of Phase 4. count_fanout_translation_call() must not count that
	 * firing, using the same $suppress_native_sibling_term_sync guard
	 * sync_translated_terms() already relies on for the same reason.
	 */
	public function test_sync_native_sibling_does_not_count_its_own_synthetic_translation_complete_firing(): void {
		self::$lf_languages = [ 'en', 'es' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );
		update_post_meta( $this->artwork_id, '_agnosis_native_lang', 'es' );
		update_post_meta( $this->artwork_id, '_agnosis_native_excerpt', 'Resumen nativo.' );
		update_post_meta( $this->artwork_id, '_agnosis_native_body', 'Cuerpo nativo.' );

		LinguaForge::sync_native_sibling( $this->artwork_id );

		$this->assertSame( 0, CallCounter::get_total( $this->artwork_id ), 'Building a native sibling is explicitly zero-AI-cost — it must not inflate the calls-per-submission counter the same way a real translation would.' );
	}

	// ── §4d: term-translation cache maintenance ───────────────────────────────

	public function test_clear_term_translations_cache_empties_the_option(): void {
		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => 'Paisaje' ] ],
		] );

		LinguaForge::clear_term_translations_cache();

		$this->assertSame( [], get_option( 'agnosis_term_translations', [] ) );
	}

	public function test_term_translation_cache_count_sums_every_cached_language(): void {
		update_option( 'agnosis_term_translations', [
			'post_tag'       => [
				'Landscape' => [ 'es' => 'Paisaje', 'fr' => 'Paysage' ],
				'Coastal'   => [ 'es' => 'Costero' ],
			],
			'agnosis_medium' => [
				'Oil Painting' => [ 'de' => 'Ölgemälde' ],
			],
		] );

		$this->assertSame( 4, LinguaForge::term_translation_cache_count() );

		delete_option( 'agnosis_term_translations' );
	}

	public function test_term_translation_cache_count_is_zero_when_option_is_unset(): void {
		delete_option( 'agnosis_term_translations' );

		$this->assertSame( 0, LinguaForge::term_translation_cache_count() );
	}

	/**
	 * The cache is keyed by the term's NAME, so a rename otherwise orphans
	 * the old entry forever (fourth audit §4d). Renaming a term must drop
	 * every cached language under the OLD name for that taxonomy, while
	 * leaving unrelated cache entries (a different term, or the same name in
	 * a different taxonomy) untouched.
	 */
	public function test_renaming_a_term_invalidates_its_old_cached_translations(): void {
		$term = wp_insert_term( 'Landscape', 'post_tag' );
		update_option( 'agnosis_term_translations', [
			'post_tag'       => [
				'Landscape' => [ 'es' => 'Paisaje', 'fr' => 'Paysage' ],
				'Coastal'   => [ 'es' => 'Costero' ], // Unrelated term — must survive.
			],
			'agnosis_medium' => [
				'Landscape' => [ 'es' => 'Not really a medium, just proving taxonomy scoping' ],
			],
		] );

		// Unlike every other test in this file, this one goes through the real
		// `edit_terms`/`edited_term` WP actions (via wp_update_term() below)
		// rather than calling a method directly — so the constructor's
		// add_action() calls actually need to have run first. The production
		// singleton (Plugin.php's `new LinguaForge()`) was already constructed
		// before this test file's LINGUAFORGE_FILE/VERSION stubs were defined,
		// so is_active() was false at that point and it never registered these
		// hooks; a fresh instance here (constants now defined) registers them
		// for real, scoped to this test only (WP_UnitTestCase backs up/restores
		// $wp_filter around every test).
		new LinguaForge();

		wp_update_term( $term['term_id'], 'post_tag', [ 'name' => 'Landscapes' ] );

		$cache = get_option( 'agnosis_term_translations', [] );
		$this->assertArrayNotHasKey( 'Landscape', $cache['post_tag'] );
		$this->assertSame( [ 'es' => 'Costero' ], $cache['post_tag']['Coastal'] ?? null );
		// Same old name under a DIFFERENT taxonomy is untouched — invalidation is scoped per-taxonomy.
		$this->assertArrayHasKey( 'Landscape', $cache['agnosis_medium'] );

		delete_option( 'agnosis_term_translations' );
	}

	public function test_updating_a_term_without_changing_its_name_does_not_invalidate_the_cache(): void {
		// edited_term fires on ANY term save (description, slug, parent, …),
		// not only a rename — must not treat every save as a cache-busting event.
		$term = wp_insert_term( 'Landscape', 'post_tag' );
		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => 'Paisaje' ] ],
		] );

		// Register the real hooks (see the rename test above for why this is
		// needed) — without it, this "no-op" assertion would pass trivially
		// even if the guard clause were broken, since nothing would run at all.
		new LinguaForge();

		wp_update_term( $term['term_id'], 'post_tag', [ 'description' => 'A scenic view.' ] );

		$cache = get_option( 'agnosis_term_translations', [] );
		$this->assertSame( [ 'es' => 'Paisaje' ], $cache['post_tag']['Landscape'] ?? null );

		delete_option( 'agnosis_term_translations' );
	}

	public function test_renaming_a_term_in_an_unrelated_taxonomy_does_not_touch_the_cache(): void {
		// Category isn't one of the two taxonomies this class ever caches a
		// translation for — capture_pre_rename_term_name()/invalidate_renamed_term_cache()
		// must no-op for it entirely (also proves the taxonomy scoping guard
		// doesn't throw when there's nothing cached for that taxonomy at all).
		$term = wp_insert_term( 'Uncategorized Sub', 'category' );
		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => 'Paisaje' ] ],
		] );

		// Register the real hooks (see the rename test above for why this is
		// needed) — without it, this taxonomy-scoping assertion would pass
		// trivially even if the scoping guard were broken, since nothing would
		// run at all.
		new LinguaForge();

		wp_update_term( $term['term_id'], 'category', [ 'name' => 'Renamed Category' ] );

		$cache = get_option( 'agnosis_term_translations', [] );
		$this->assertSame( [ 'es' => 'Paisaje' ], $cache['post_tag']['Landscape'] ?? null );

		delete_option( 'agnosis_term_translations' );
	}

	// ── sync_taxonomy(): numeric-looking term names (sixth audit §6) ──────────
	//
	// WordPress's own term_exists() adds a `t.term_id = %d` OR-clause whenever
	// is_numeric($term) is true, so a translated term name that happens to be
	// a bare number ("2026") could silently match an UNRELATED term whose
	// term_id happens to equal that number, instead of a term actually named
	// that. resolve_numeric_term_name() resolves numeric-looking names to a
	// real term ID itself, before term_exists()/wp_set_object_terms() ever see
	// the ambiguous string. As with the tests above, the term-translation
	// cache (agnosis_term_translations) is used to force a specific
	// "translated" name without a real AI provider.

	public function test_sync_taxonomy_creates_and_assigns_a_new_numeric_term_name(): void {
		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => '2026' ] ],
		] );
		wp_set_object_terms( $this->artwork_id, [ 'Landscape' ], 'post_tag' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'es' );

		$this->assertSame(
			[ '2026' ],
			wp_get_post_terms( $translated_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ),
			'A brand-new numeric-looking term name must actually be created and assigned, not silently dropped.'
		);

		$term = get_term_by( 'name', '2026', 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame(
			'es',
			get_term_meta( $term->term_id, LinguaForge::TRANSLATED_TERM_META, true ),
			'A newly-created numeric term must be flagged as translated, exactly like a newly-created non-numeric one.'
		);

		delete_option( 'agnosis_term_translations' );
	}

	public function test_sync_taxonomy_reuses_an_existing_numeric_term_without_reflagging_it(): void {
		// '2026' already exists as a genuine, admin-curated term (e.g. a real
		// "year" tag used elsewhere) — sync_taxonomy() must reuse that exact
		// term, not create a duplicate, and must not retroactively flag a term
		// that already existed before this translation pass.
		$existing = wp_insert_term( '2026', 'post_tag' );
		$this->assertIsArray( $existing );

		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => '2026' ] ],
		] );
		wp_set_object_terms( $this->artwork_id, [ 'Landscape' ], 'post_tag' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'es' );

		$assigned = wp_get_post_terms( $translated_id, 'post_tag', [ 'fields' => 'ids' ] );
		$this->assertSame( [ (int) $existing['term_id'] ], $assigned, 'Must reuse the pre-existing "2026" term by ID, not create a second one.' );
		$this->assertSame( '', get_term_meta( (int) $existing['term_id'], LinguaForge::TRANSLATED_TERM_META, true ), 'A pre-existing numeric term must not be flagged as translated.' );

		delete_option( 'agnosis_term_translations' );
	}

	/**
	 * The exact scenario resolve_numeric_term_name() exists to prevent: a
	 * translated name that happens to be numeric ("Filler"'s own term_id,
	 * stringified) must never be silently matched against that UNRELATED
	 * term merely because the ID happens to coincide with the digits —
	 * WordPress's term_exists() ambiguity this method sidesteps. A distinct
	 * term, actually NAMED that digit string, must be created instead, and
	 * the collided-with term must be completely untouched.
	 */
	public function test_sync_taxonomy_numeric_term_never_matches_an_unrelated_term_by_id(): void {
		$filler = wp_insert_term( 'Filler Tag', 'post_tag' );
		$this->assertIsArray( $filler );
		$collision_id = (int) $filler['term_id'];

		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => (string) $collision_id ] ],
		] );
		wp_set_object_terms( $this->artwork_id, [ 'Landscape' ], 'post_tag' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'es' );

		$assigned_names = wp_get_post_terms( $translated_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] );
		$this->assertSame( [ (string) $collision_id ], $assigned_names, 'A new term literally named after the colliding ID must be created and assigned.' );

		$new_term = get_term_by( 'name', (string) $collision_id, 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $new_term );
		$this->assertNotSame( $collision_id, $new_term->term_id, 'The new term must NOT reuse "Filler Tag"\'s term_id merely because the digits match — that is exactly the WordPress term_exists() ambiguity this method exists to avoid.' );

		$untouched = get_term( $collision_id, 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $untouched );
		$this->assertSame( 'Filler Tag', $untouched->name, '"Filler Tag" itself must be completely unaffected by an unrelated numeric-named term sharing its ID.' );

		delete_option( 'agnosis_term_translations' );
	}

	public function test_sync_taxonomy_drops_a_genuinely_unresolvable_numeric_term(): void {
		// Force wp_insert_term() to fail for reasons other than "already
		// exists" (e.g. a genuine DB error) and ensure no pre-existing term
		// named '404' exists either — resolve_numeric_term_name() must drop
		// the term entirely rather than ever passing the ambiguous numeric
		// string through to wp_set_object_terms().
		$force_failure = static function () {
			return new \WP_Error( 'db_insert_error', 'Simulated failure for this test.' );
		};
		add_filter( 'pre_insert_term', $force_failure, 10, 0 );

		update_option( 'agnosis_term_translations', [
			'post_tag' => [ 'Landscape' => [ 'es' => '404' ] ],
		] );
		wp_set_object_terms( $this->artwork_id, [ 'Landscape' ], 'post_tag' );
		$translated_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new LinguaForge() )->sync_translated_terms( $translated_id, $this->artwork_id, 'es' );

		$this->assertSame(
			[],
			wp_get_post_terms( $translated_id, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ),
			'An unresolvable numeric term name must be dropped, never passed through unassigned/ambiguous.'
		);

		remove_filter( 'pre_insert_term', $force_failure, 10 );
		delete_option( 'agnosis_term_translations' );
	}

	// ── build_title_translations(): with a configured provider (fifth audit §4d) ──
	//
	// The existing guard tests above (test_build_title_translations_skips_*)
	// only ever exercise the "no provider configured" no-op path. These tests
	// configure Providers\WordPressAI (no API key required) backed by the
	// shared fake wp_ai_client_prompt() stub so translate_to_languages() is
	// actually invoked and its result actually written to _agnosis_title_i18n.

	public function test_build_title_translations_invokes_translate_to_languages_and_stores_the_map(): void {
		self::$lf_languages = [ 'en', 'es', 'fr' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );
		update_post_meta( $this->artwork_id, '_agnosis_translated_title', 'Sunrise' );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'es' => 'Amanecer', 'fr' => 'Lever du soleil' ] );

		( new LinguaForge() )->build_title_translations( $this->artwork_id );

		$this->assertSame(
			[ 'es' => 'Amanecer', 'fr' => 'Lever du soleil' ],
			get_post_meta( $this->artwork_id, '_agnosis_title_i18n', true )
		);
		// Fifth audit §4d: one envelope call for every target language, not one
		// translate_text() call per language.
		$this->assertCount( 1, WpAiClientTestRegistry::$prompts );
		$this->assertStringContainsString( 'Sunrise', WpAiClientTestRegistry::$prompts[0] );

		delete_option( 'agnosis_ai_provider' );
		WpAiClientTestRegistry::reset();
	}

	public function test_build_title_translations_invokes_translate_to_languages_and_stores_the_map_for_event(): void {
		// 0.9.24: event joined DUAL_TITLE_POST_TYPES — proves it now actually
		// builds a real per-language title map (not just that it isn't
		// skipped — test_build_title_translations_skips_biography() above
		// covers the type that still IS skipped).
		$event_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
		] );
		self::$lf_languages = [ 'en', 'es', 'fr' ];
		update_post_meta( $event_id, '_lf_lang', 'en' );
		update_post_meta( $event_id, '_agnosis_translated_title', 'Crossing the Threshold' );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'es' => 'Cruzando el Umbral', 'fr' => 'Traverser le Seuil' ] );

		( new LinguaForge() )->build_title_translations( $event_id );

		$this->assertSame(
			[ 'es' => 'Cruzando el Umbral', 'fr' => 'Traverser le Seuil' ],
			get_post_meta( $event_id, '_agnosis_title_i18n', true )
		);

		delete_option( 'agnosis_ai_provider' );
		WpAiClientTestRegistry::reset();
	}

	public function test_build_title_translations_does_not_store_a_map_when_every_translation_is_unchanged(): void {
		// translate_to_languages() drops any language whose "translation" comes
		// back identical to the source text — if that empties the whole map,
		// build_title_translations() must not write an empty array to meta.
		self::$lf_languages = [ 'en', 'es' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );
		update_post_meta( $this->artwork_id, '_agnosis_translated_title', 'Sunrise' );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'es' => 'Sunrise' ] ); // Echoed back unchanged.

		( new LinguaForge() )->build_title_translations( $this->artwork_id );

		$this->assertSame( '', (string) get_post_meta( $this->artwork_id, '_agnosis_title_i18n', true ) );

		delete_option( 'agnosis_ai_provider' );
		WpAiClientTestRegistry::reset();
	}

	// ── sync_native_sibling(): native-language pipeline Phase 4/6 fidelity ────
	// (agnosis-audit/NATIVE-LANGUAGE-PIPELINE.md §4d/§6 Phase 6b) ─────────────
	//
	// Uses the linguaforge_get_trid()/set_trid()/get_translations()/
	// clear_translation_cache()/mark_translation_synced() stubs added to
	// Support/linguaforge-function-stubs.php + FakeLinguaForge specifically for
	// this coverage. No AI provider is configured in any test below — proving
	// the core claim these tests exist for: the native sibling's content comes
	// straight from the preserved _agnosis_native_excerpt/_agnosis_native_body
	// meta, byte for byte, with zero AI involvement (WpAiClientTestRegistry::$prompts
	// stays empty throughout).

	/** Find the sibling post carrying $trid as its own real _lf_trid postmeta (never the primary post itself — see below). */
	private function find_sibling_by_trid( string $trid, int $exclude_id ): ?\WP_Post {
		// sync_native_sibling() only ever writes _lf_trid/_lf_lang as REAL
		// postmeta onto the newly-created SIBLING (create_native_sibling_post()) —
		// the primary post's own trid lives solely in FakeLinguaForge::$trids
		// (the linguaforge_get_trid()/set_trid() stub backing), never as real
		// postmeta on the primary post in this test double. Scoping the query to
		// $trid and excluding the primary's own ID is therefore sufficient to
		// find exactly the sibling, with no ambiguity.
		$ids = get_posts( [
			'post_type'      => 'agnosis_artwork',
			'meta_key'       => '_lf_trid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test-only, tiny fixture set.
			'meta_value'     => $trid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- test-only, tiny fixture set.
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		] );
		$ids = array_values( array_diff( array_map( 'intval', $ids ), [ $exclude_id ] ) );
		return isset( $ids[0] ) ? get_post( $ids[0] ) : null;
	}

	public function test_sync_native_sibling_creates_a_new_post_with_exact_native_content(): void {
		self::$lf_languages = [ 'en', 'es' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );
		update_post_meta( $this->artwork_id, '_agnosis_native_lang', 'es' );
		update_post_meta( $this->artwork_id, '_agnosis_native_excerpt', 'Un resumen final, tal como lo escribió el artista.' );
		update_post_meta( $this->artwork_id, '_agnosis_native_body', 'Cuerpo final, editado por el artista.' );
		update_post_meta( $this->artwork_id, '_agnosis_native_tags', wp_json_encode( [ 'Paisaje', 'Costero' ] ) );
		update_post_meta( $this->artwork_id, '_agnosis_native_medium', 'Óleo' );

		LinguaForge::sync_native_sibling( $this->artwork_id );

		$trid = FakeLinguaForge::$trids[ $this->artwork_id ] ?? '';
		$this->assertNotSame( '', $trid, 'sync_native_sibling() must generate and set a TRID for the primary post when none existed yet.' );

		$sibling = $this->find_sibling_by_trid( $trid, $this->artwork_id );
		$this->assertInstanceOf( \WP_Post::class, $sibling, 'A native-language sibling post must be created.' );

		$this->assertSame( get_post( $this->artwork_id )->post_title, $sibling->post_title, 'post_title must mirror the primary post verbatim — never translated, per the dual-title invariant.' );
		$this->assertSame( 'Un resumen final, tal como lo escribió el artista.', $sibling->post_excerpt, 'The sibling excerpt must match the preserved native excerpt exactly, byte for byte.' );
		$this->assertStringContainsString( 'Cuerpo final, editado por el artista.', $sibling->post_content, 'The sibling body must contain the preserved native body exactly, byte for byte.' );
		$this->assertSame( 'es', get_post_meta( $sibling->ID, '_lf_lang', true ) );
		$this->assertSame( $trid, get_post_meta( $sibling->ID, '_lf_trid', true ) );

		$this->assertSame(
			[],
			WpAiClientTestRegistry::$prompts,
			'sync_native_sibling() must never call the AI translator — the whole point is a zero-cost sibling built from already-native, already-approved content.'
		);

		$this->assertContains( $sibling->ID, FakeLinguaForge::$cache_cleared_for, 'linguaforge_clear_translation_cache() must be called for the new sibling, or it stays invisible to LF\'s own translation-lookup UI/queries for up to an hour.' );
		$this->assertContains( $sibling->ID, FakeLinguaForge::$marked_synced );

		// wp_get_post_terms() doesn't guarantee insertion order (WP's own
		// default term ordering), so this compares as a set rather than
		// assertSame() — order was never the claim, exact membership was.
		$this->assertEqualsCanonicalizing(
			[ 'Paisaje', 'Costero' ],
			wp_get_post_terms( $sibling->ID, 'post_tag', [ 'fields' => 'names', 'hide_empty' => false ] ),
			'Native tags must be assigned directly from _agnosis_native_tags, not re-derived from any translated set.'
		);
		$this->assertSame(
			[ 'Óleo' ],
			wp_get_post_terms( $sibling->ID, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ),
			'Native medium must be assigned directly from _agnosis_native_medium.'
		);
	}

	public function test_sync_native_sibling_updates_an_existing_sibling_in_place_and_resyncs_the_title(): void {
		self::$lf_languages = [ 'en', 'es' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );
		update_post_meta( $this->artwork_id, '_agnosis_native_lang', 'es' );
		update_post_meta( $this->artwork_id, '_agnosis_native_excerpt', 'Versión anterior.' );
		update_post_meta( $this->artwork_id, '_agnosis_native_body', 'Cuerpo anterior.' );

		$existing_sibling_id = self::factory()->post->create( [
			'post_type'    => 'agnosis_artwork',
			'post_status'  => 'publish',
			'post_title'   => 'Stale Title',
			'post_excerpt' => 'Stale excerpt.',
			'post_content' => 'Stale content.',
		] );
		FakeLinguaForge::link( $this->artwork_id, 'es', $existing_sibling_id );

		// Resubmission (e.g. replace@): the artist's title and native text have
		// since changed — post_title must be RE-SYNCED on every update, not just
		// written once at creation (see update_native_sibling_post()'s docblock).
		wp_update_post( [ 'ID' => $this->artwork_id, 'post_title' => 'Updated Title' ] );
		update_post_meta( $this->artwork_id, '_agnosis_native_excerpt', 'Versión final, editada.' );
		update_post_meta( $this->artwork_id, '_agnosis_native_body', 'Cuerpo final, editado.' );

		LinguaForge::sync_native_sibling( $this->artwork_id );

		$sibling = get_post( $existing_sibling_id );
		$this->assertSame( 'Updated Title', $sibling->post_title, 'post_title must be re-synced on every update to keep mirroring the primary post exactly, forever.' );
		$this->assertSame( 'Versión final, editada.', $sibling->post_excerpt );
		$this->assertStringContainsString( 'Cuerpo final, editado.', $sibling->post_content );
		$this->assertStringNotContainsString( 'Cuerpo anterior.', $sibling->post_content, 'Stale native content from before the resubmission must not linger.' );

		$all_artwork_ids = get_posts( [
			'post_type'      => 'agnosis_artwork',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		] );
		$this->assertCount( 2, $all_artwork_ids, 'Updating an existing sibling must never create a second post.' );

		$this->assertSame( [], WpAiClientTestRegistry::$prompts );
	}

	public function test_sync_native_sibling_no_ops_when_native_lang_matches_primary(): void {
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );
		update_post_meta( $this->artwork_id, '_agnosis_native_lang', 'en' ); // Artist already writes in the primary language.
		update_post_meta( $this->artwork_id, '_agnosis_native_excerpt', 'Should never be used.' );
		update_post_meta( $this->artwork_id, '_agnosis_native_body', 'Should never be used.' );

		LinguaForge::sync_native_sibling( $this->artwork_id );

		$this->assertSame( '', FakeLinguaForge::$trids[ $this->artwork_id ] ?? '', 'No sibling work of any kind should happen — the primary post already serves as the "native" one.' );
	}

	public function test_sync_native_sibling_no_ops_without_preserved_native_content(): void {
		self::$lf_languages = [ 'en', 'es' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );
		update_post_meta( $this->artwork_id, '_agnosis_native_lang', 'es' );
		// _agnosis_native_excerpt/_agnosis_native_body deliberately left unset —
		// e.g. a post that was never translated to begin with.

		LinguaForge::sync_native_sibling( $this->artwork_id );

		$this->assertSame( '', FakeLinguaForge::$trids[ $this->artwork_id ] ?? '', 'Nothing to build a sibling from — must no-op cleanly rather than create an empty post.' );
	}

	/**
	 * Seventh audit §2c — a throwing listener on 'linguaforge_translation_complete'
	 * used to leave $suppress_native_sibling_term_sync stuck true for the rest
	 * of the request/cron tick (no try/finally around the do_action() call),
	 * silently disabling tag/medium translation for every OTHER
	 * genuinely-AI-translated sibling synced afterward in that same tick.
	 */
	public function test_sync_native_sibling_resets_suppression_flag_even_when_a_listener_throws(): void {
		self::$lf_languages = [ 'en', 'es' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );
		update_post_meta( $this->artwork_id, '_agnosis_native_lang', 'es' );
		update_post_meta( $this->artwork_id, '_agnosis_native_excerpt', 'Resumen.' );
		update_post_meta( $this->artwork_id, '_agnosis_native_body', 'Cuerpo.' );

		$listener = function (): void {
			throw new \RuntimeException( 'boom — a third-party listener misbehaving' );
		};
		add_action( 'linguaforge_translation_complete', $listener );

		try {
			LinguaForge::sync_native_sibling( $this->artwork_id );
			$this->fail( 'Expected the listener\'s exception to propagate out of sync_native_sibling() — do_action() never swallows exceptions, and neither should this method.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom — a third-party listener misbehaving', $e->getMessage() );
		} finally {
			remove_action( 'linguaforge_translation_complete', $listener );
		}

		$this->assertFalse(
			$this->suppress_native_sibling_term_sync_flag(),
			'The suppression flag must be reset by the finally block even though the listener threw — without it, the flag would stay stuck true for the rest of the request, silently suppressing tag/medium sync for every OTHER sibling.'
		);
	}

	/** Reads the private static Compat\LinguaForge::$suppress_native_sibling_term_sync flag directly — the most precise way to prove §2c's try/finally actually resets it, rather than inferring it from a second sync_native_sibling() call's side effects. */
	private function suppress_native_sibling_term_sync_flag(): bool {
		$ref = new \ReflectionProperty( LinguaForge::class, 'suppress_native_sibling_term_sync' );
		$ref->setAccessible( true );
		return (bool) $ref->getValue();
	}

	// ── trash_orphaned_native_sibling(): seventh audit §2b ────────────────────
	// (NATIVE-LANGUAGE-PIPELINE.md Phase 2's own documented "known follow-up") ─

	public function test_trash_orphaned_native_sibling_trashes_the_sibling_for_the_old_language(): void {
		self::$lf_languages = [ 'en', 'es' ];

		$old_sibling_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_title'  => 'Título anterior.',
		] );
		FakeLinguaForge::link( $this->artwork_id, 'es', $old_sibling_id );

		LinguaForge::trash_orphaned_native_sibling( $this->artwork_id, 'es' );

		$sibling = get_post( $old_sibling_id );
		$this->assertSame(
			'trash',
			$sibling->post_status,
			'The sibling built for the language the artist has since switched away from must be trashed — nothing would ever sync it again.'
		);
	}

	public function test_trash_orphaned_native_sibling_does_not_force_delete(): void {
		self::$lf_languages = [ 'en', 'es' ];

		$old_sibling_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
		] );
		FakeLinguaForge::link( $this->artwork_id, 'es', $old_sibling_id );

		LinguaForge::trash_orphaned_native_sibling( $this->artwork_id, 'es' );

		$this->assertNotNull(
			get_post( $old_sibling_id ),
			'Trashing must be recoverable — this is a real, deliberate language change the artist made, not spam/abuse cleanup, so the post must still exist (in the trash), not be gone outright.'
		);
	}

	public function test_trash_orphaned_native_sibling_no_ops_when_no_sibling_exists_for_that_language(): void {
		self::$lf_languages = [ 'en', 'es' ];
		// FakeLinguaForge::link() deliberately never called — no translation
		// group entry exists for 'es' at all (e.g. sync_native_sibling() never
		// ran for the old language, or it wasn't LF-configured).

		LinguaForge::trash_orphaned_native_sibling( $this->artwork_id, 'es' );

		$this->assertSame(
			[],
			get_posts( [
				'post_type'      => 'agnosis_artwork',
				'post_status'    => 'trash',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			] ),
			'With nothing to trash, no post anywhere should end up in the trash.'
		);
	}

	public function test_trash_orphaned_native_sibling_no_ops_with_an_empty_old_language(): void {
		self::$lf_languages = [ 'en', 'es' ];

		$old_sibling_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
		] );
		FakeLinguaForge::link( $this->artwork_id, 'es', $old_sibling_id );

		// '' means the target never actually had a prior native language (the
		// guard ReviewEndpoints::finalize_publish() itself applies before ever
		// calling this method) — exercised directly here too, defensively.
		LinguaForge::trash_orphaned_native_sibling( $this->artwork_id, '' );

		$this->assertSame( 'publish', get_post( $old_sibling_id )->post_status, 'An empty old language means nothing to trash — the unrelated "es" sibling above must be left untouched.' );
	}

	public function test_trash_orphaned_native_sibling_does_not_retrash_an_already_trashed_sibling(): void {
		self::$lf_languages = [ 'en', 'es' ];

		$old_sibling_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'trash',
		] );
		FakeLinguaForge::link( $this->artwork_id, 'es', $old_sibling_id );

		// Must not throw/warn on a target that's already trashed — just a
		// defensive no-op, exercised directly rather than only inferred.
		LinguaForge::trash_orphaned_native_sibling( $this->artwork_id, 'es' );

		$this->assertSame( 'trash', get_post( $old_sibling_id )->post_status );
	}
}
