<?php
/**
 * Integration tests for Profile::render_event_location() block callback.
 *
 * Verifies that the agnosis/event-location block render callback:
 *   • Returns formatted HTML when _agnosis_event_location meta is set.
 *   • Returns an empty string when meta is absent or blank (no visual gap).
 *   • HTML-escapes the location value.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Profile;

class ProfileEventLocationBlockTest extends \WP_UnitTestCase {

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
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Create a WP_Block mock whose context carries the given postId.
	 */
	private function make_block( int $post_id ): \WP_Block {
		$mock          = $this->createMock( \WP_Block::class );
		$mock->context = [ 'postId' => $post_id ];
		return $mock;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_renders_location_when_meta_is_set(): void {
		update_post_meta( $this->post_id, '_agnosis_event_location', 'Gallery Mitte, Berlin' );

		$html = $this->profile->render_event_location( [], '', $this->make_block( $this->post_id ) );

		$this->assertStringContainsString( 'Gallery Mitte, Berlin', $html );
		$this->assertStringContainsString( 'agnosis-event-location', $html );
	}

	public function test_returns_empty_string_when_meta_is_not_set(): void {
		$html = $this->profile->render_event_location( [], '', $this->make_block( $this->post_id ) );

		$this->assertSame( '', $html );
	}

	public function test_returns_empty_string_when_meta_is_blank(): void {
		update_post_meta( $this->post_id, '_agnosis_event_location', '   ' );

		$html = $this->profile->render_event_location( [], '', $this->make_block( $this->post_id ) );

		$this->assertSame( '', $html );
	}

	public function test_output_is_html_escaped(): void {
		update_post_meta( $this->post_id, '_agnosis_event_location', '<script>alert(1)</script>' );

		$html = $this->profile->render_event_location( [], '', $this->make_block( $this->post_id ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}
}
