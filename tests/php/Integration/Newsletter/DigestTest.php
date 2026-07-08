<?php
/**
 * Integration tests — newsletter auto-digest content builder.
 *
 * Covers the "since" cutoff (only content published after the last issue is
 * included), the empty-state message, and that the artist digest surfaces
 * activity counts and new members.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\Digest;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;

class DigestTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeLinguaForge::reset();
	}

	protected function tearDown(): void {
		FakeLinguaForge::reset();
		parent::tearDown();
	}

	private function make_artwork( string $title, string $post_date ): int {
		return (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_date'   => $post_date,
			'post_author' => self::factory()->user->create(),
		] );
	}

	public function test_public_digest_is_empty_message_when_nothing_new(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$html = Digest::build_public( $since );

		$this->assertStringContainsString( 'Nothing new to report', $html );
	}

	public function test_public_digest_includes_artwork_published_after_since(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->make_artwork( 'Fresh Piece', gmdate( 'Y-m-d H:i:s' ) );

		$html = Digest::build_public( $since );

		$this->assertStringContainsString( 'Fresh Piece', $html );
		$this->assertStringContainsString( 'New artwork', $html );
	}

	public function test_public_digest_excludes_artwork_published_before_since(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->make_artwork( 'Old Piece', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );

		$html = Digest::build_public( $since );

		$this->assertStringNotContainsString( 'Old Piece', $html );
		$this->assertStringContainsString( 'Nothing new to report', $html );
	}

	public function test_artist_digest_reports_artwork_count(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->make_artwork( 'Community Piece One', gmdate( 'Y-m-d H:i:s' ) );
		$this->make_artwork( 'Community Piece Two', gmdate( 'Y-m-d H:i:s' ) );

		$html = Digest::build_artist( $since );

		$this->assertStringContainsString( '2 new artworks published', $html );
	}

	// =========================================================================
	// Per-locale rendering
	// =========================================================================

	/**
	 * Link two posts as Lingua Forge translations of each other: real _lf_lang
	 * meta (recent_posts() reads this directly to scope its own WP_Query) plus
	 * a FakeLinguaForge registry entry (stands in for linguaforge_get_translations(),
	 * since the real Lingua Forge plugin isn't loaded in this test environment —
	 * see tests/php/Integration/Support/FakeLinguaForge.php for why).
	 */
	private function link_as_translations( int $original_id, string $original_lang, int $translated_id, string $translated_lang ): void {
		update_post_meta( $original_id, '_lf_lang', $original_lang );
		update_post_meta( $translated_id, '_lf_lang', $translated_lang );
		FakeLinguaForge::link( $original_id, $translated_lang, $translated_id );
	}

	public function test_recent_posts_excludes_translated_duplicate_from_the_default_render(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$original_id   = $this->make_artwork( 'Dedup Original', gmdate( 'Y-m-d H:i:s' ) );
		$translated_id = $this->make_artwork( 'Dedup Translated', gmdate( 'Y-m-d H:i:s' ) );
		$this->link_as_translations( $original_id, 'en', $translated_id, 'es' );

		$html = Digest::build_public( $since );

		$this->assertStringContainsString( 'Dedup Original', $html, 'The primary-language post must still appear.' );
		$this->assertStringNotContainsString( 'Dedup Translated', $html, 'A translated duplicate must not be listed separately.' );
		$this->assertSame( 1, substr_count( $html, '<table' ), 'The artwork must be listed exactly once, not once per language.' );
	}

	public function test_public_digest_uses_translated_title_for_matching_recipient_language(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$original_id   = $this->make_artwork( 'Original Title', gmdate( 'Y-m-d H:i:s' ) );
		$translated_id = $this->make_artwork( 'Título Traducido', gmdate( 'Y-m-d H:i:s' ) );
		$this->link_as_translations( $original_id, 'en', $translated_id, 'es' );

		$html = Digest::build_public( $since, 'es' );

		$this->assertStringContainsString( 'Título Traducido', $html );
		$this->assertStringNotContainsString( 'Original Title', $html );
	}

	public function test_public_digest_falls_back_to_original_when_no_translation_exists(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->make_artwork( 'Untranslated Piece', gmdate( 'Y-m-d H:i:s' ) );

		$html = Digest::build_public( $since, 'es' );

		$this->assertStringContainsString( 'Untranslated Piece', $html, 'With no translation available, the primary-language post must still be shown rather than nothing.' );
	}

	public function test_public_digest_with_source_lf_lang_uses_original_directly(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$original_id   = $this->make_artwork( 'Same Language Piece', gmdate( 'Y-m-d H:i:s' ) );
		$translated_id = $this->make_artwork( 'Should Not Appear', gmdate( 'Y-m-d H:i:s' ) );
		$this->link_as_translations( $original_id, 'en', $translated_id, 'es' );

		// Requesting the site's own primary language must not trigger a translation lookup.
		$html = Digest::build_public( $since, 'en' );

		$this->assertStringContainsString( 'Same Language Piece', $html );
		$this->assertStringNotContainsString( 'Should Not Appear', $html );
	}

	public function test_artist_digest_lists_newly_admitted_members(): void {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => 'newmember@example.com',
				'display_name' => 'Nova Artist',
				'status'       => 'admitted',
				'resolved_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		$html = Digest::build_artist( $since );

		$this->assertStringContainsString( 'Nova Artist', $html );
	}

	// =========================================================================
	// build_intro_context() — structured summary for the AI intro drafter
	// =========================================================================

	public function test_intro_context_includes_artwork_title_excerpt_tags_and_medium(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$id = $this->make_artwork( 'Context Piece', gmdate( 'Y-m-d H:i:s' ) );
		wp_update_post( [ 'ID' => $id, 'post_excerpt' => 'A striking new work.' ] );
		wp_set_post_tags( $id, [ 'blue', 'abstract' ] );
		wp_set_object_terms( $id, 'Oil Painting', 'agnosis_medium' );

		$context = Digest::build_intro_context( 'public', $since );

		$this->assertCount( 1, $context['artworks'] );
		$item = $context['artworks'][0];
		$this->assertSame( 'Context Piece', $item['title'] );
		$this->assertSame( 'A striking new work.', $item['excerpt'] );
		$this->assertContains( 'blue', $item['tags'] );
		$this->assertContains( 'abstract', $item['tags'] );
		$this->assertContains( 'Oil Painting', $item['medium'] );
	}

	public function test_intro_context_events_have_no_medium_key_value(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_title'  => 'Context Event',
			'post_date'   => gmdate( 'Y-m-d H:i:s' ),
			'post_author' => self::factory()->user->create(),
		] );

		$context = Digest::build_intro_context( 'public', $since );

		$this->assertCount( 1, $context['events'] );
		$this->assertSame( [], $context['events'][0]['medium'] ?? [], 'Events carry no agnosis_medium terms — the key must be absent or empty, never populated from an unrelated artwork.' );
	}

	public function test_intro_context_public_type_omits_members_and_votes(): void {
		$since   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$context = Digest::build_intro_context( 'public', $since );

		$this->assertArrayNotHasKey( 'new_members', $context );
		$this->assertArrayNotHasKey( 'open_votes', $context );
	}

	public function test_intro_context_artist_type_includes_new_members(): void {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => 'context-member@example.com',
				'display_name' => 'Context Artist',
				'status'       => 'admitted',
				'resolved_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		$context = Digest::build_intro_context( 'artist', $since );

		$this->assertContains( 'Context Artist', $context['new_members'] );
		$this->assertSame( 0, $context['open_votes'] );
	}

	public function test_intro_context_empty_when_nothing_new(): void {
		$since   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$context = Digest::build_intro_context( 'public', $since );

		$this->assertSame( [], $context['artworks'] );
		$this->assertSame( [], $context['events'] );
	}
}
