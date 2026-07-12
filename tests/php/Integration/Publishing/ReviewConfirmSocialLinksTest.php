<?php
/**
 * Integration tests for the biography approve form's three social-link
 * fields (Publishing\ReviewConfirm) — render, prefill-on-retry, and sync,
 * added 2026-07-13 alongside the pre-existing portfolio_url field.
 *
 * Exercises the private render_social_link_fields()/extra_prefill_from_source()/
 * sync_social_links() methods directly via reflection, same convention
 * Unit\Email\ParserTest already uses for private-method coverage — simpler
 * and more targeted than simulating the full token-gated confirm HTTP flow
 * ReviewConfirmIntegrationTest covers for the request/redirect boundary
 * itself, which these fields don't touch.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\ReviewConfirm;
use WP_UnitTestCase;

class ReviewConfirmSocialLinksTest extends WP_UnitTestCase {

	private ReviewConfirm $confirm;
	private int $bio_id;

	protected function setUp(): void {
		parent::setUp();
		$this->confirm = new ReviewConfirm();

		$artist_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->bio_id = (int) self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $artist_id,
			'post_status' => 'draft',
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers — reflection into ReviewConfirm's private methods
	// -------------------------------------------------------------------------

	/** @param array<string, mixed> $args */
	private function call_private( string $method, array $args ): mixed {
		$ref = new \ReflectionMethod( ReviewConfirm::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->confirm, $args );
	}

	// -------------------------------------------------------------------------
	// render_social_link_fields() / render_extra_fields_html()
	// -------------------------------------------------------------------------

	public function test_render_extra_fields_includes_three_social_inputs_for_biography(): void {
		$html = $this->call_private( 'render_extra_fields_html', [ 'agnosis_biography', $this->bio_id, [] ] );

		$this->assertSame( 3, substr_count( $html, 'type="url" name="social_url_' ), 'Expected exactly three social-url inputs.' );
		$this->assertStringContainsString( 'name="social_url_1"', $html );
		$this->assertStringContainsString( 'name="social_url_2"', $html );
		$this->assertStringContainsString( 'name="social_url_3"', $html );
	}

	public function test_render_extra_fields_still_includes_the_pre_existing_portfolio_field(): void {
		$html = $this->call_private( 'render_extra_fields_html', [ 'agnosis_biography', $this->bio_id, [] ] );

		$this->assertStringContainsString( 'name="portfolio_url"', $html, 'Adding the social fields must not have displaced the existing portfolio field.' );
	}

	public function test_render_social_link_fields_prefills_from_existing_meta(): void {
		update_post_meta( $this->bio_id, '_agnosis_biography_social_url_2', 'https://instagram.com/artist' );

		$html = $this->call_private( 'render_social_link_fields', [ $this->bio_id, [] ] );

		$this->assertStringContainsString( 'value="https://instagram.com/artist"', $html );
	}

	public function test_render_social_link_fields_prefers_prefill_over_baseline_meta(): void {
		update_post_meta( $this->bio_id, '_agnosis_biography_social_url_1', 'https://old-value.example' );

		$html = $this->call_private( 'render_social_link_fields', [
			$this->bio_id,
			[ 'social_url_1' => 'https://edited-during-retry.example' ],
		] );

		$this->assertStringContainsString( 'value="https://edited-during-retry.example"', $html );
		$this->assertStringNotContainsString( 'old-value.example', $html );
	}

	// -------------------------------------------------------------------------
	// extra_prefill_from_source()
	// -------------------------------------------------------------------------

	public function test_extra_prefill_from_source_extracts_all_three_social_urls(): void {
		$prefill = $this->call_private( 'extra_prefill_from_source', [
			'agnosis_biography',
			[
				'portfolio_url' => 'https://portfolio.example',
				'social_url_1'  => 'https://instagram.com/artist',
				'social_url_2'  => 'https://bandcamp.com/artist',
				'social_url_3'  => 'https://wa.me/1234567890',
			],
		] );

		$this->assertSame( 'https://instagram.com/artist', $prefill['social_url_1'] );
		$this->assertSame( 'https://bandcamp.com/artist', $prefill['social_url_2'] );
		$this->assertSame( 'https://wa.me/1234567890', $prefill['social_url_3'] );
	}

	// -------------------------------------------------------------------------
	// sync_social_links() / sync_extra_fields()
	// -------------------------------------------------------------------------

	public function test_sync_social_links_persists_sanitized_urls(): void {
		$this->call_private( 'sync_social_links', [
			$this->bio_id,
			[
				'social_url_1' => 'https://instagram.com/artist',
				'social_url_2' => 'https://bandcamp.com/artist',
				'social_url_3' => 'https://wa.me/1234567890',
			],
		] );

		$this->assertSame( 'https://instagram.com/artist', get_post_meta( $this->bio_id, '_agnosis_biography_social_url_1', true ) );
		$this->assertSame( 'https://bandcamp.com/artist', get_post_meta( $this->bio_id, '_agnosis_biography_social_url_2', true ) );
		$this->assertSame( 'https://wa.me/1234567890', get_post_meta( $this->bio_id, '_agnosis_biography_social_url_3', true ) );
	}

	public function test_sync_social_links_clears_a_field_omitted_from_the_form(): void {
		update_post_meta( $this->bio_id, '_agnosis_biography_social_url_1', 'https://old-value.example' );

		// Always re-applies straight from the submitted form (see this
		// method's own docblock) — an artist clearing a field on a
		// resubmitted approve form is as valid an edit as changing it, so an
		// absent key must clear the meta, not leave the old value in place.
		$this->call_private( 'sync_social_links', [ $this->bio_id, [] ] );

		$this->assertSame( '', get_post_meta( $this->bio_id, '_agnosis_biography_social_url_1', true ) );
	}

	public function test_sync_extra_fields_for_biography_persists_both_portfolio_and_social_links(): void {
		$this->call_private( 'sync_extra_fields', [
			$this->bio_id,
			'agnosis_biography',
			[
				'portfolio_url' => 'https://youtube.com/trusted-host-no-fetch-needed',
				'social_url_1'  => 'https://instagram.com/artist',
			],
		] );

		$this->assertSame( 'https://instagram.com/artist', get_post_meta( $this->bio_id, '_agnosis_biography_social_url_1', true ) );
		// Portfolio sync is exercised in full elsewhere (EmbedPolicy-gated meta
		// write, no longer a post_content rebuild — see sync_portfolio_embed()'s
		// docblock) — here we only need proof both syncs ran, via the meta
		// value they each independently write.
		$this->assertSame( 'https://youtube.com/trusted-host-no-fetch-needed', get_post_meta( $this->bio_id, '_agnosis_biography_portfolio_url', true ) );
	}
}
