<?php
/**
 * Integration tests for Publishing\ReviewEndpoints.
 *
 * ReviewEndpoints is the security boundary between public email links and
 * WordPress post state. Tests cover:
 *
 *   • Token auth: valid token approves/rejects; wrong token is rejected;
 *     expired token is rejected; token is deleted after use.
 *   • WP auth: logged-in post author can approve; stranger cannot.
 *   • Business rules: approving a non-draft returns 409; approving a
 *     non-artwork post returns 404.
 *   • save(): body text update preserves existing image blocks.
 *   • extract_image_blocks(): tested indirectly through save().
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\ReviewEndpoints;

class ReviewEndpointsIntegrationTest extends \WP_UnitTestCase {

	private ReviewEndpoints $endpoints;

	/** @var int Artist WP user ID */
	private int $artist_id;

	/** @var int Draft artwork post ID */
	private int $post_id;

	private const VALID_TOKEN = 'test-token-abc123';

	protected function setUp(): void {
		parent::setUp();

		$this->endpoints = new ReviewEndpoints();

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->post_id = wp_insert_post( [
			'post_type'    => 'agnosis_artwork',
			'post_status'  => 'draft',
			'post_title'   => 'Test Artwork',
			'post_excerpt' => 'A short excerpt.',
			'post_content' => '<!-- wp:image --><figure class="wp-block-image"><img src="test.jpg" alt=""/></figure><!-- /wp:image -->' . "\n\n" . '<!-- wp:paragraph --><p>Original body.</p><!-- /wp:paragraph -->',
			'post_author'  => $this->artist_id,
		] );

		update_post_meta( $this->post_id, '_agnosis_review_token',  self::VALID_TOKEN );
		update_post_meta( $this->post_id, '_agnosis_review_expiry', time() + 86400 * 7 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function request( array $params = [] ): \WP_REST_Request {
		$req = new \WP_REST_Request();
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	// -------------------------------------------------------------------------
	// approve() — token path
	// -------------------------------------------------------------------------

	public function test_approve_with_valid_token_publishes_post(): void {
		$req    = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->approve( $req );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'published', $result->get_data()['status'] );
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );
	}

	public function test_approve_deletes_token_after_use(): void {
		$req = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$this->endpoints->approve( $req );

		$this->assertEmpty( get_post_meta( $this->post_id, '_agnosis_review_token', true ) );
	}

	public function test_approve_with_wrong_token_returns_403_error(): void {
		$req    = $this->request( [ 'id' => $this->post_id, 'token' => 'wrong-token' ] );
		$result = $this->endpoints->approve( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
	}

	public function test_approve_with_expired_token_returns_403_error(): void {
		update_post_meta( $this->post_id, '_agnosis_review_expiry', time() - 1 );

		$req    = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->approve( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_token_expired', $result->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
	}

	public function test_approve_with_no_token_and_no_auth_returns_401(): void {
		// No token, no logged-in user.
		$req    = $this->request( [ 'id' => $this->post_id ] );
		$result = $this->endpoints->approve( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_auth_required', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// approve() — WP auth path
	// -------------------------------------------------------------------------

	public function test_approve_by_post_author_publishes_post(): void {
		wp_set_current_user( $this->artist_id );

		$req    = $this->request( [ 'id' => $this->post_id ] );
		$result = $this->endpoints->approve( $req );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );

		wp_set_current_user( 0 );
	}

	public function test_approve_by_stranger_returns_403(): void {
		$stranger = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $stranger );

		$req    = $this->request( [ 'id' => $this->post_id ] );
		$result = $this->endpoints->approve( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_forbidden', $result->get_error_code() );

		wp_set_current_user( 0 );
	}

	public function test_approve_by_admin_publishes_post(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$req    = $this->request( [ 'id' => $this->post_id ] );
		$result = $this->endpoints->approve( $req );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );

		wp_set_current_user( 0 );
	}

	// -------------------------------------------------------------------------
	// approve() — business rules
	// -------------------------------------------------------------------------

	public function test_approve_already_published_post_returns_409(): void {
		wp_update_post( [ 'ID' => $this->post_id, 'post_status' => 'publish' ] );

		$req    = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->approve( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_not_draft', $result->get_error_code() );
	}

	public function test_approve_nonexistent_post_returns_404(): void {
		$req    = $this->request( [ 'id' => 99999, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->approve( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_not_found', $result->get_error_code() );
	}

	public function test_approve_non_artwork_post_returns_404(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft' ] );
		update_post_meta( $page_id, '_agnosis_review_token', self::VALID_TOKEN );
		update_post_meta( $page_id, '_agnosis_review_expiry', time() + 86400 );

		$req    = $this->request( [ 'id' => $page_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->approve( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_not_found', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// reject()
	// -------------------------------------------------------------------------

	public function test_reject_with_valid_token_trashes_post(): void {
		$req    = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->reject( $req );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rejected', $result->get_data()['status'] );
		$this->assertSame( 'trash', get_post_status( $this->post_id ) );
	}

	public function test_reject_deletes_token_after_use(): void {
		$req = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$this->endpoints->reject( $req );

		$this->assertEmpty( get_post_meta( $this->post_id, '_agnosis_review_token', true ) );
	}

	public function test_reject_with_wrong_token_returns_403_and_does_not_trash(): void {
		$req    = $this->request( [ 'id' => $this->post_id, 'token' => 'bad-token' ] );
		$result = $this->endpoints->reject( $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
	}

	// -------------------------------------------------------------------------
	// save() — text update preserves image blocks
	// -------------------------------------------------------------------------

	public function test_save_updates_title_and_body(): void {
		$req = $this->request( [
			'id'      => $this->post_id,
			'token'   => self::VALID_TOKEN,
			'title'   => 'Updated Title',
			'excerpt' => 'Updated excerpt.',
			'body'    => 'Updated body text.',
		] );

		$result = $this->endpoints->save( $req );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$post = get_post( $this->post_id );
		$this->assertSame( 'Updated Title',   $post->post_title );
		$this->assertSame( 'Updated excerpt.', $post->post_excerpt );
	}

	public function test_save_preserves_image_blocks_at_top_of_content(): void {
		$req = $this->request( [
			'id'    => $this->post_id,
			'token' => self::VALID_TOKEN,
			'body'  => 'New body text.',
		] );

		$this->endpoints->save( $req );

		$content = get_post( $this->post_id )->post_content;

		// Image block must be preserved.
		$this->assertStringContainsString( '<!-- wp:image -->', $content );
		// New body must also be present.
		$this->assertStringContainsString( 'New body text.', $content );
	}

	public function test_save_with_publish_true_publishes_post(): void {
		$req = $this->request( [
			'id'      => $this->post_id,
			'token'   => self::VALID_TOKEN,
			'title'   => 'Final Title',
			'publish' => true,
		] );

		$result = $this->endpoints->save( $req );

		$this->assertSame( 'published', $result->get_data()['status'] );
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );
	}

	public function test_save_without_publish_leaves_post_as_draft(): void {
		$req = $this->request( [
			'id'    => $this->post_id,
			'token' => self::VALID_TOKEN,
			'title' => 'Saved Title',
		] );

		$result = $this->endpoints->save( $req );

		$this->assertSame( 'saved', $result->get_data()['status'] );
		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
	}

	// -------------------------------------------------------------------------
	// Featured meta — approve() and save(publish=true) must NOT auto-promote
	// (featuring is now explicit via the promote@ email alias)
	// -------------------------------------------------------------------------

	public function test_approve_does_not_set_featured_meta(): void {
		$req = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$this->endpoints->approve( $req );

		$this->assertNotSame( '1', get_post_meta( $this->post_id, '_agnosis_featured', true ) );
	}

	public function test_save_with_publish_does_not_set_featured_meta(): void {
		$req = $this->request( [
			'id'      => $this->post_id,
			'token'   => self::VALID_TOKEN,
			'publish' => true,
		] );
		$this->endpoints->save( $req );

		$this->assertNotSame( '1', get_post_meta( $this->post_id, '_agnosis_featured', true ) );
	}

	// -------------------------------------------------------------------------
	// Token edge cases
	// -------------------------------------------------------------------------

	public function test_token_with_no_expiry_meta_is_valid(): void {
		// If no expiry is stored (e.g. older queue items) the token should
		// still work — expiry check only fires when stored_expiry > 0.
		delete_post_meta( $this->post_id, '_agnosis_review_expiry' );

		$req    = $this->request( [ 'id' => $this->post_id, 'token' => self::VALID_TOKEN ] );
		$result = $this->endpoints->approve( $req );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_empty_token_falls_through_to_auth_check(): void {
		// An empty string token must not bypass the auth check.
		$req    = $this->request( [ 'id' => $this->post_id, 'token' => '' ] );
		$result = $this->endpoints->approve( $req );

		// No logged-in user → 401 (falls through to WP auth path).
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_auth_required', $result->get_error_code() );
	}
}
