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
 *
 * @package Agnosis\Tests\Integration\Compat
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Compat;

use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;

// LF constants and global function stubs live in a separate file to satisfy
// Universal.Files.SeparateFunctionsFromOO (no function declarations alongside OO).
require_once __DIR__ . '/Stubs/lf_global_stubs.php';

class LinguaForgeCompatTest extends \WP_UnitTestCase {

	// ── Stubs state (reset in tearDown) ───────────────────────────────────────

	/** Active routing languages returned by linguaforge_languages(). */
	public static ?array $lf_languages = null;

	/** Calls recorded by linguaforge_trigger_translation(). */
	public static array $trigger_calls = [];

	/** Calls recorded by linguaforge_queue_translation() (LF 2.4.0+). */
	public static array $queue_calls = [];

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
		self::$trigger_return = null;

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
		self::$trigger_return = null;
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

	public function test_hold_artist_title_passes_through_for_event_and_biography(): void {
		// Dual-title is artwork-only — events and biographies use LF's normal title
		// translation, so the AI's translated_title is left untouched.
		foreach ( [ 'agnosis_event', 'agnosis_biography' ] as $type ) {
			$id = self::factory()->post->create( [
				'post_type'   => $type,
				'post_status' => 'publish',
				'post_title'  => 'Original',
			] );

			$payload = [ 'output' => '<p>…</p>', 'translated_title' => 'Translated Title' ];
			$result  = ( new LinguaForge() )->hold_artist_title( $payload, $id, 'es' );

			$this->assertSame( 'Translated Title', $result['translated_title'], $type );
		}
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

	public function test_build_title_translations_skips_event_and_biography(): void {
		// Artwork-only: events and biographies never get a per-language title map.
		self::$lf_languages = [ 'en', 'es' ];

		foreach ( [ 'agnosis_event', 'agnosis_biography' ] as $type ) {
			$id = self::factory()->post->create( [
				'post_type'   => $type,
				'post_status' => 'publish',
			] );
			update_post_meta( $id, '_lf_lang', 'en' );
			update_post_meta( $id, '_agnosis_translated_title', 'A Title' );

			( new LinguaForge() )->build_title_translations( $id );

			$this->assertSame( '', (string) get_post_meta( $id, '_agnosis_title_i18n', true ), $type );
		}
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
}
