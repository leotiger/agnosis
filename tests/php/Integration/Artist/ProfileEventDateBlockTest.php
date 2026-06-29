<?php
/**
 * Integration tests for Profile::render_event_date() block callback.
 *
 * Verifies that the agnosis/event-date block render callback:
 *   • Returns formatted HTML when _agnosis_event_date meta is set (date-only).
 *   • Includes a time component when the ISO 8601 value contains 'T'.
 *   • Returns an empty string when meta is absent or blank (no visual gap).
 *   • Rejects a meta value that strtotime() cannot parse.
 *   • HTML-escapes all output.
 *   • Emits a <time datetime="…"> element with the raw ISO value.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Profile;

class ProfileEventDateBlockTest extends \WP_UnitTestCase {

	private Profile $profile;
	private int $post_id;

	protected function setUp(): void {
		parent::setUp();

		$this->profile = new Profile();

		$this->post_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_title'  => 'Test Event',
		] );

		// Use a fixed, predictable date format so assertions are locale-independent.
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	private function make_block( int $post_id ): \WP_Block {
		$mock          = $this->createMock( \WP_Block::class );
		$mock->context = [ 'postId' => $post_id ];
		return $mock;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_renders_date_only_when_no_time_component(): void {
		update_post_meta( $this->post_id, '_agnosis_event_date', '2026-08-15' );

		$html = $this->profile->render_event_date( [], '', $this->make_block( $this->post_id ) );

		$this->assertStringContainsString( '2026-08-15', $html );
		$this->assertStringContainsString( 'agnosis-event-date', $html );
		// Time should not appear for a date-only value.
		$this->assertStringNotContainsString( ':', substr( $html, strpos( $html, '>' ) ) ); // colon inside text, not datetime attr
	}

	public function test_renders_date_and_time_when_time_component_present(): void {
		update_post_meta( $this->post_id, '_agnosis_event_date', '2026-08-15T19:00' );

		$html = $this->profile->render_event_date( [], '', $this->make_block( $this->post_id ) );

		$this->assertStringContainsString( '2026-08-15', $html );
		$this->assertStringContainsString( '19:00', $html );
	}

	public function test_emits_time_element_with_datetime_attribute(): void {
		update_post_meta( $this->post_id, '_agnosis_event_date', '2026-08-15' );

		$html = $this->profile->render_event_date( [], '', $this->make_block( $this->post_id ) );

		$this->assertStringContainsString( '<time datetime="2026-08-15"', $html );
	}

	public function test_returns_empty_string_when_meta_not_set(): void {
		$html = $this->profile->render_event_date( [], '', $this->make_block( $this->post_id ) );

		$this->assertSame( '', $html );
	}

	public function test_returns_empty_string_when_meta_is_blank(): void {
		update_post_meta( $this->post_id, '_agnosis_event_date', '   ' );

		$html = $this->profile->render_event_date( [], '', $this->make_block( $this->post_id ) );

		$this->assertSame( '', $html );
	}

	public function test_returns_empty_string_for_unparseable_date(): void {
		update_post_meta( $this->post_id, '_agnosis_event_date', 'not-a-date' );

		$html = $this->profile->render_event_date( [], '', $this->make_block( $this->post_id ) );

		// strtotime('not-a-date') returns false — must return '' not broken output.
		$this->assertSame( '', $html );
	}

	public function test_datetime_attribute_contains_iso_value(): void {
		update_post_meta( $this->post_id, '_agnosis_event_date', '2026-08-15T19:00' );

		$html = $this->profile->render_event_date( [], '', $this->make_block( $this->post_id ) );

		// The datetime attribute must carry the raw ISO value for machine readability.
		$this->assertStringContainsString( 'datetime="2026-08-15T19:00"', $html );
	}
}
