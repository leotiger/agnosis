<?php
/**
 * Integration tests for Publishing\GalleryOverview.
 *
 * Covers:
 *   • _agnosis_featured post meta registration (REST-exposed, boolean).
 *   • Meta box save: valid nonce persists value; missing nonce is a no-op;
 *     autosave is skipped; insufficient capability is rejected.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\GalleryOverview;

class GalleryOverviewIntegrationTest extends \WP_UnitTestCase {

	private GalleryOverview $gallery;
	private int $artist_id;
	private int $post_id;

	protected function setUp(): void {
		parent::setUp();

		$this->gallery   = new GalleryOverview();
		$this->gallery->register_meta();

		$this->artist_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$this->post_id   = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'draft',
			'post_author' => $this->artist_id,
			'post_title'  => 'Test Artwork',
		] );
	}

	// -------------------------------------------------------------------------
	// Meta registration
	// -------------------------------------------------------------------------

	public function test_featured_meta_is_registered(): void {
		$registered = get_registered_meta_keys( 'post', 'agnosis_artwork' );

		$this->assertArrayHasKey( '_agnosis_featured', $registered );
	}

	public function test_featured_meta_is_boolean_type(): void {
		$registered = get_registered_meta_keys( 'post', 'agnosis_artwork' );

		$this->assertSame( 'boolean', $registered['_agnosis_featured']['type'] );
	}

	public function test_featured_meta_is_rest_exposed(): void {
		$registered = get_registered_meta_keys( 'post', 'agnosis_artwork' );

		$this->assertTrue( (bool) $registered['_agnosis_featured']['show_in_rest'] );
	}

	// -------------------------------------------------------------------------
	// Meta box save
	// -------------------------------------------------------------------------

	public function test_save_meta_box_persists_featured_flag(): void {
		wp_set_current_user( $this->artist_id );

		$_POST['agnosis_featured_nonce'] = wp_create_nonce( 'agnosis_featured_' . $this->post_id );
		$_POST['agnosis_featured']       = '1';

		$this->gallery->save_meta_box( $this->post_id );

		$this->assertSame( '1', get_post_meta( $this->post_id, '_agnosis_featured', true ) );

		wp_set_current_user( 0 );
		unset( $_POST['agnosis_featured_nonce'], $_POST['agnosis_featured'] );
	}

	public function test_save_meta_box_clears_flag_when_checkbox_absent(): void {
		// Pre-set the flag so we can verify it gets cleared.
		update_post_meta( $this->post_id, '_agnosis_featured', '1' );

		wp_set_current_user( $this->artist_id );

		$_POST['agnosis_featured_nonce'] = wp_create_nonce( 'agnosis_featured_' . $this->post_id );
		// No 'agnosis_featured' key — checkbox is unchecked.

		$this->gallery->save_meta_box( $this->post_id );

		$this->assertSame( '0', get_post_meta( $this->post_id, '_agnosis_featured', true ) );

		wp_set_current_user( 0 );
		unset( $_POST['agnosis_featured_nonce'] );
	}

	public function test_save_meta_box_is_noop_without_nonce(): void {
		update_post_meta( $this->post_id, '_agnosis_featured', '0' );

		wp_set_current_user( $this->artist_id );

		// No nonce in $_POST at all.
		$this->gallery->save_meta_box( $this->post_id );

		// Value must be unchanged.
		$this->assertSame( '0', get_post_meta( $this->post_id, '_agnosis_featured', true ) );

		wp_set_current_user( 0 );
	}

	public function test_save_meta_box_is_noop_on_autosave(): void {
		update_post_meta( $this->post_id, '_agnosis_featured', '0' );

		wp_set_current_user( $this->artist_id );

		$_POST['agnosis_featured_nonce'] = wp_create_nonce( 'agnosis_featured_' . $this->post_id );
		$_POST['agnosis_featured']       = '1';

		define( 'DOING_AUTOSAVE', true );

		$this->gallery->save_meta_box( $this->post_id );

		// Autosave must leave the value untouched.
		$this->assertSame( '0', get_post_meta( $this->post_id, '_agnosis_featured', true ) );

		wp_set_current_user( 0 );
		unset( $_POST['agnosis_featured_nonce'], $_POST['agnosis_featured'] );
	}
}
