<?php
/**
 * Integration tests — SubdomainNavigation's `type=language` breadcrumb badge
 * linking to the artist's native-language equivalent of the current page
 * (added 2026-07-23; see native_language_url()'s own docblock).
 *
 * Before this, the badge was always a plain, unlinked `<span>` naming the
 * artist's own language — purely informational, with no way to actually go
 * look at that language's version of whatever the visitor was reading.
 * Covers:
 *
 *   - No artist resolved, or the artist has no locale set → unchanged
 *     original behavior (nothing, or a plain span).
 *   - The artist's language isn't one of the site's own configured/routed
 *     languages → plain span, nothing to link to.
 *   - The visitor is already viewing that exact language → plain span.
 *   - Non-singular (archive/gallery/home) page → links via Lingua Forge's
 *     own `linguaforge_lsflr_translate_current_url()`.
 *   - Singular page with a published sibling in the artist's language →
 *     links straight to that sibling's own permalink.
 *   - Singular page with no sibling yet, or only an unpublished one → plain
 *     span.
 *
 * Dispatches through the real WP render_block() pipeline, same reasoning as
 * SubdomainNavigationContactIconTest's own class docblock (get_block_wrapper_attributes()
 * needs a genuine WP_Block::render() call).
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\SubdomainNavigation;
use Agnosis\Network\SubdomainRouter;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;

class SubdomainNavigationLanguageBadgeTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'agnosis/breadcrumb-icon-link' ) ) {
			( new SubdomainNavigation() )->register_breadcrumb_icon_link_block();
		}

		FakeLinguaForge::reset();
	}

	protected function tearDown(): void {
		$this->set_current_artist( null );
		FakeLinguaForge::reset();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Simulate SubdomainRouter::boot() having resolved a given artist (or none). */
	private function set_current_artist( ?int $artist_id ): void {
		$ref = new \ReflectionProperty( SubdomainRouter::class, 'artist_id' );
		$ref->setAccessible( true );
		$ref->setValue( null, $artist_id );
	}

	private function create_artist( string $locale = '' ): int {
		$id = self::factory()->user->create( [
			'role'       => 'subscriber',
			'user_email' => 'lang-badge-artist-' . wp_generate_password( 8, false ) . '@example.com',
		] );
		get_userdata( $id )->add_role( 'agnosis_artist' );
		if ( '' !== $locale ) {
			update_user_meta( $id, 'locale', $locale );
		}
		return $id;
	}

	private function render_language_badge(): string {
		return render_block( [
			'blockName'    => 'agnosis/breadcrumb-icon-link',
			'attrs'        => [ 'type' => 'language' ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		] );
	}

	// -------------------------------------------------------------------------
	// Unchanged original behavior
	// -------------------------------------------------------------------------

	public function test_renders_nothing_without_a_locale(): void {
		$artist_id = $this->create_artist(); // no locale meta set
		$this->set_current_artist( $artist_id );

		$this->assertSame( '', $this->render_language_badge() );
	}

	public function test_renders_a_plain_span_when_language_is_not_site_configured(): void {
		$artist_id = $this->create_artist( 'ca_ES' );
		$this->set_current_artist( $artist_id );

		FakeLinguaForge::$source_language = 'en';
		FakeLinguaForge::$valid_langs     = [ 'en' ]; // 'ca' deliberately absent.

		$html = $this->render_language_badge();

		$this->assertStringContainsString( '<span', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringContainsString( 'CA', $html );
	}

	public function test_renders_a_plain_span_when_already_viewing_that_language(): void {
		$artist_id = $this->create_artist( 'ca_ES' );
		$this->set_current_artist( $artist_id );

		FakeLinguaForge::$source_language = 'ca'; // visitor already on the Catalan version
		FakeLinguaForge::$valid_langs     = [ 'en', 'ca' ];

		$html = $this->render_language_badge();

		$this->assertStringContainsString( '<span', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	// -------------------------------------------------------------------------
	// Non-singular (archive/gallery/home) — Lingua Forge's own URL rewriter
	// -------------------------------------------------------------------------

	public function test_links_to_lingua_forges_translated_url_on_a_non_singular_page(): void {
		$artist_id = $this->create_artist( 'ca_ES' );
		$this->set_current_artist( $artist_id );

		FakeLinguaForge::$source_language      = 'en';
		FakeLinguaForge::$valid_langs          = [ 'en', 'ca' ];
		FakeLinguaForge::$lsflr_translated_url = 'https://example.org/ca/gallery/';

		// The artist's own gallery archive — unambiguously non-singular,
		// unlike home_url('/') which could resolve to a static front PAGE
		// (singular) depending on Settings → Reading.
		$this->go_to( (string) get_post_type_archive_link( 'agnosis_artwork' ) );

		$html = $this->render_language_badge();

		$this->assertStringContainsString( '<a ', $html );
		$this->assertStringContainsString( 'href="https://example.org/ca/gallery/"', $html );
	}

	// -------------------------------------------------------------------------
	// Singular — the current post's own translation sibling
	// -------------------------------------------------------------------------

	public function test_links_directly_to_a_published_sibling_on_a_singular_page(): void {
		$artist_id = $this->create_artist( 'ca_ES' );
		$this->set_current_artist( $artist_id );

		$primary_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
		] );
		$sibling_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
		] );

		FakeLinguaForge::$source_language = 'en';
		FakeLinguaForge::$valid_langs     = [ 'en', 'ca' ];
		FakeLinguaForge::link( $primary_id, 'ca', $sibling_id );

		// (string) here is a plain, always-valid scalar cast on go_to()'s own
		// $url param (string|false → string) — unrelated to $primary_id's own
		// int|WP_Error taint, which is what get_permalink()'s own already-
		// baselined "expects int|WP_Post" complaint below is about.
		$this->go_to( (string) get_permalink( $primary_id ) );

		$html = $this->render_language_badge();

		$this->assertStringContainsString( '<a ', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $sibling_id ) ) . '"', $html );
	}

	public function test_renders_a_plain_span_when_no_sibling_exists_yet(): void {
		$artist_id = $this->create_artist( 'ca_ES' );
		$this->set_current_artist( $artist_id );

		$primary_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
		] );

		FakeLinguaForge::$source_language = 'en';
		FakeLinguaForge::$valid_langs     = [ 'en', 'ca' ];
		// No FakeLinguaForge::link() call — no sibling recorded for any language.

		$this->go_to( (string) get_permalink( $primary_id ) ); // see (string) note above

		$html = $this->render_language_badge();

		$this->assertStringContainsString( '<span', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function test_renders_a_plain_span_when_the_sibling_is_not_yet_published(): void {
		$artist_id = $this->create_artist( 'ca_ES' );
		$this->set_current_artist( $artist_id );

		$primary_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
		] );
		$sibling_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'draft',
		] );

		FakeLinguaForge::$source_language = 'en';
		FakeLinguaForge::$valid_langs     = [ 'en', 'ca' ];
		FakeLinguaForge::link( $primary_id, 'ca', $sibling_id );

		$this->go_to( (string) get_permalink( $primary_id ) ); // see (string) note above

		$html = $this->render_language_badge();

		$this->assertStringContainsString( '<span', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}
}
