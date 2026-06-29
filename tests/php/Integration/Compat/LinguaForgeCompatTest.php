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
 *   set_language_meta()       — writes _lf_lang from _agnosis_detected_lang or locale
 *   request_translations()    — calls linguaforge_trigger_translation once per target
 *   get_target_languages()    — excludes source lang; returns [] when LF unavailable
 *   is_active()               — true when both constants defined
 *   filter_meta_description() — returns excerpt for artwork, passes through otherwise
 *   filter_og_image()         — passes through for non-artwork; falls back when no thumb
 *   filter_schema_type()      — VisualArtwork for artwork, passes through otherwise
 *
 * @package Agnosis\Tests\Integration\Compat
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Compat;

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

	/** Return value for linguaforge_trigger_translation (int post ID or WP_Error). */
	public static int|\WP_Error|null $trigger_return = null;

	// ── Test post IDs ─────────────────────────────────────────────────────────

	private int $artwork_id;
	private int $page_id;

	// ── Lifecycle ─────────────────────────────────────────────────────────────

	protected function setUp(): void {
		parent::setUp();

		self::$lf_languages  = null;
		self::$trigger_calls = [];
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
		self::$trigger_return = null;
		parent::tearDown();
	}

	// ── is_active() ───────────────────────────────────────────────────────────

	public function test_is_active_returns_true_when_both_constants_defined(): void {
		$this->assertTrue( LinguaForge::is_active() );
	}

	// ── set_language_meta() ───────────────────────────────────────────────────

	public function test_set_language_meta_writes_lf_lang_from_detected_lang(): void {
		update_post_meta( $this->artwork_id, '_agnosis_detected_lang', 'fr' );

		( new LinguaForge() )->set_language_meta( $this->artwork_id );

		$this->assertSame( 'fr', get_post_meta( $this->artwork_id, '_lf_lang', true ) );
	}

	public function test_set_language_meta_falls_back_to_site_locale(): void {
		// No _agnosis_detected_lang set — should derive from get_locale().
		// WP test env defaults to en_US → 'en'.
		( new LinguaForge() )->set_language_meta( $this->artwork_id );

		$this->assertSame( 'en', get_post_meta( $this->artwork_id, '_lf_lang', true ) );
	}

	public function test_set_language_meta_skips_non_agnosis_post_types(): void {
		update_post_meta( $this->page_id, '_agnosis_detected_lang', 'de' );

		( new LinguaForge() )->set_language_meta( $this->page_id );

		$this->assertSame( '', get_post_meta( $this->page_id, '_lf_lang', true ) );
	}

	public function test_set_language_meta_works_for_biography_post_type(): void {
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'publish',
		] );
		update_post_meta( $bio_id, '_agnosis_detected_lang', 'de' );

		( new LinguaForge() )->set_language_meta( $bio_id );

		$this->assertSame( 'de', get_post_meta( $bio_id, '_lf_lang', true ) );
	}

	public function test_set_language_meta_works_for_event_post_type(): void {
		$event_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
		] );
		update_post_meta( $event_id, '_agnosis_detected_lang', 'es' );

		( new LinguaForge() )->set_language_meta( $event_id );

		$this->assertSame( 'es', get_post_meta( $event_id, '_lf_lang', true ) );
	}

	// ── request_translations() ────────────────────────────────────────────────

	public function test_request_translations_calls_trigger_once_per_target_language(): void {
		self::$lf_languages = [ 'en', 'es', 'fr' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$this->assertCount( 2, self::$trigger_calls );
		$langs = array_column( self::$trigger_calls, 'target_lang' );
		$this->assertContains( 'es', $langs );
		$this->assertContains( 'fr', $langs );
		$this->assertNotContains( 'en', $langs );
	}

	public function test_request_translations_passes_correct_post_id(): void {
		self::$lf_languages = [ 'en', 'de' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$this->assertSame( $this->artwork_id, self::$trigger_calls[0]['post_id'] );
	}

	public function test_request_translations_passes_domain_and_source_params(): void {
		self::$lf_languages = [ 'en', 'it' ];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$params = self::$trigger_calls[0]['params'];
		$this->assertSame( 'art',    $params['domain'] );
		$this->assertSame( 'normal', $params['priority'] );
		$this->assertSame( 'agnosis', $params['source'] );
	}

	public function test_request_translations_skips_when_no_target_languages(): void {
		self::$lf_languages = [ 'en' ]; // source is 'en', nothing left
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$this->assertCount( 0, self::$trigger_calls );
	}

	public function test_request_translations_skips_when_lf_languages_empty(): void {
		self::$lf_languages = [];
		update_post_meta( $this->artwork_id, '_lf_lang', 'en' );

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$this->assertCount( 0, self::$trigger_calls );
	}

	public function test_request_translations_skips_non_agnosis_post_types(): void {
		self::$lf_languages = [ 'en', 'fr' ];

		( new LinguaForge() )->request_translations( $this->page_id );

		$this->assertCount( 0, self::$trigger_calls );
	}

	public function test_request_translations_falls_back_to_en_when_lf_lang_absent(): void {
		// _lf_lang not set — should default to 'en' and exclude it from targets.
		self::$lf_languages = [ 'en', 'pt' ];

		( new LinguaForge() )->request_translations( $this->artwork_id );

		$this->assertCount( 1, self::$trigger_calls );
		$this->assertSame( 'pt', self::$trigger_calls[0]['target_lang'] );
	}

	// ── filter_meta_description() ─────────────────────────────────────────────

	public function test_filter_meta_description_returns_excerpt_for_artwork(): void {
		$post = get_post( $this->artwork_id );

		$result = ( new LinguaForge() )->filter_meta_description( 'default description', $post );

		$this->assertSame( 'A vivid oil painting of coastal cliffs.', $result );
	}

	public function test_filter_meta_description_passes_through_for_non_artwork(): void {
		$post = get_post( $this->page_id );

		$result = ( new LinguaForge() )->filter_meta_description( 'default description', $post );

		$this->assertSame( 'default description', $result );
	}

	public function test_filter_meta_description_falls_back_when_excerpt_empty(): void {
		$empty_id = self::factory()->post->create( [
			'post_type'    => 'agnosis_artwork',
			'post_status'  => 'publish',
			'post_excerpt' => '',
		] );
		$post = get_post( $empty_id );

		$result = ( new LinguaForge() )->filter_meta_description( 'default description', $post );

		$this->assertSame( 'default description', $result );
	}

	// ── filter_og_image() ────────────────────────────────────────────────────

	public function test_filter_og_image_passes_through_for_non_artwork(): void {
		$post = get_post( $this->page_id );

		$result = ( new LinguaForge() )->filter_og_image( 'https://example.com/default.jpg', $post );

		$this->assertSame( 'https://example.com/default.jpg', $result );
	}

	public function test_filter_og_image_falls_back_when_artwork_has_no_thumbnail(): void {
		// No featured image set on artwork post.
		$post = get_post( $this->artwork_id );

		$result = ( new LinguaForge() )->filter_og_image( 'https://example.com/default.jpg', $post );

		// get_the_post_thumbnail_url() returns false → falls back to original.
		$this->assertSame( 'https://example.com/default.jpg', $result );
	}

	// ── filter_schema_type() ─────────────────────────────────────────────────

	public function test_filter_schema_type_returns_visual_artwork_for_artwork(): void {
		$post = get_post( $this->artwork_id );

		$result = ( new LinguaForge() )->filter_schema_type( 'Article', $post );

		$this->assertSame( 'VisualArtwork', $result );
	}

	public function test_filter_schema_type_passes_through_for_non_artwork(): void {
		$post = get_post( $this->page_id );

		$result = ( new LinguaForge() )->filter_schema_type( 'Article', $post );

		$this->assertSame( 'Article', $result );
	}

	public function test_filter_schema_type_passes_through_for_biography(): void {
		$bio_id = self::factory()->post->create( [ 'post_type' => 'agnosis_biography' ] );
		$post   = get_post( $bio_id );

		$result = ( new LinguaForge() )->filter_schema_type( 'Person', $post );

		$this->assertSame( 'Person', $result );
	}

	// ── register_textdomain() ─────────────────────────────────────────────────

	public function test_register_textdomain_adds_agnosis_to_domains(): void {
		$result = ( new LinguaForge() )->register_textdomain( [ 'other-plugin' ] );

		$this->assertContains( 'agnosis', $result );
		$this->assertContains( 'other-plugin', $result );
	}

	public function test_register_textdomain_does_not_duplicate_on_empty_input(): void {
		$result = ( new LinguaForge() )->register_textdomain( [] );

		$this->assertSame( [ 'agnosis' ], $result );
	}
}
