<?php
/**
 * Integration tests — agnosis/join block rendering and its localized config.
 *
 * Focused on the "After applying, send artists to" setting
 * (agnosis_join_success_url): it should reach the frontend via
 * wp_localize_script() as `redirectUrl`, empty by default, resolved to a
 * page's permalink when a page is selected, so blocks/join/frontend.js can
 * redirect a successful applicant there (see JoinPage::success_redirect_url()).
 *
 * 2026-07-08: this setting became a WP page selector — the option now
 * normally holds a page ID rather than a raw URL string. A back-compat test
 * below covers sites that configured a raw URL before this change.
 *
 * Also covers resolve_success_url( $lang ) directly — the TRID-aware lookup
 * added the same day so an artist can be sent to a translated version of the
 * configured page, matching the language they select in the form (see
 * Admission::apply(), which is the only real caller of the $lang argument;
 * success_redirect_url() above always calls it with no argument and gets the
 * page's own untranslated permalink as a static render-time fallback).
 *
 * LINGUAFORGE_FILE / LINGUAFORGE_VERSION are defined below (guarded) so
 * LinguaForge::is_active() reads true for the TRID-resolution tests — same
 * one-directional-for-the-whole-process constant trick LinguaForgeCompatTest
 * already relies on (PHP constants can't be undefined once set). This file
 * sits alphabetically before Compat/, so its own pre-existing tests above
 * still exercise real code paths regardless — none of them pass a $lang, and
 * resolve_success_url() only ever consults Lingua Forge when $lang is
 * non-empty, so defining these constants here doesn't change their behaviour.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\JoinPage;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;

if ( ! defined( 'LINGUAFORGE_FILE' ) ) {
	define( 'LINGUAFORGE_FILE', __FILE__ );
}
if ( ! defined( 'LINGUAFORGE_VERSION' ) ) {
	define( 'LINGUAFORGE_VERSION', '1.0.0-test' );
}

class JoinPageTest extends \WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( 'agnosis_join_success_url' );
		wp_deregister_script( 'agnosis-join' );
		FakeLinguaForge::reset();
		parent::tearDown();
	}

	/** Pull the JS-encoded localized data object registered for a script handle. */
	private function localized_data( string $handle ): string {
		$scripts = wp_scripts();
		return (string) ( $scripts->get_data( $handle, 'data' ) ?: '' );
	}

	public function test_redirect_url_is_empty_by_default(): void {
		wp_set_current_user( 0 );

		( new JoinPage() )->render_block();

		$data = $this->localized_data( 'agnosis-join' );
		$this->assertStringContainsString( '"redirectUrl":""', $data );
	}

	public function test_redirect_url_reflects_selected_page(): void {
		$page_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'What happens next',
		] );
		update_option( 'agnosis_join_success_url', $page_id );
		wp_set_current_user( 0 );

		( new JoinPage() )->render_block();

		$data      = $this->localized_data( 'agnosis-join' );
		$permalink = str_replace( '/', '\/', get_permalink( $page_id ) );
		$this->assertStringContainsString( $permalink, $data );
	}

	public function test_redirect_url_ignores_unselected_page_option(): void {
		update_option( 'agnosis_join_success_url', 0 );
		wp_set_current_user( 0 );

		( new JoinPage() )->render_block();

		$data = $this->localized_data( 'agnosis-join' );
		$this->assertStringContainsString( '"redirectUrl":""', $data );
	}

	/**
	 * Back-compat: sites configured before this became a page selector stored
	 * a raw URL string directly (including URLs on other domains) — still
	 * honoured until the setting is next saved through the new dropdown.
	 */
	public function test_redirect_url_falls_back_to_legacy_raw_url_string(): void {
		update_option( 'agnosis_join_success_url', 'https://example.com/thanks/' );
		wp_set_current_user( 0 );

		( new JoinPage() )->render_block();

		$data = $this->localized_data( 'agnosis-join' );
		$this->assertStringContainsString( 'https:\/\/example.com\/thanks\/', $data );
	}

	// -------------------------------------------------------------------------
	// resolve_success_url( $lang ) — TRID-aware lookup
	// -------------------------------------------------------------------------

	public function test_resolve_success_url_returns_translated_permalink_when_available(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		$es_page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'agnosis_join_success_url', $page_id );
		FakeLinguaForge::link( $page_id, 'es', $es_page );

		$this->assertSame( get_permalink( $es_page ), JoinPage::resolve_success_url( 'es' ) );
	}

	public function test_resolve_success_url_falls_back_to_source_page_when_no_translation_exists(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'agnosis_join_success_url', $page_id );
		// FakeLinguaForge has no 'de' entry for this page at all.

		$this->assertSame( get_permalink( $page_id ), JoinPage::resolve_success_url( 'de' ) );
	}

	public function test_resolve_success_url_with_no_lang_argument_returns_source_permalink(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		$es_page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'agnosis_join_success_url', $page_id );
		FakeLinguaForge::link( $page_id, 'es', $es_page );

		// No $lang given (the render-time default, via success_redirect_url())
		// — never consults Lingua Forge, always the page's own permalink.
		$this->assertSame( get_permalink( $page_id ), JoinPage::resolve_success_url() );
	}

	public function test_resolve_success_url_legacy_raw_url_ignores_lang(): void {
		update_option( 'agnosis_join_success_url', 'https://example.com/thanks/' );

		$this->assertSame( 'https://example.com/thanks/', JoinPage::resolve_success_url( 'es' ) );
	}

	public function test_resolve_success_url_returns_empty_when_unconfigured(): void {
		$this->assertSame( '', JoinPage::resolve_success_url( 'es' ) );
	}
}
