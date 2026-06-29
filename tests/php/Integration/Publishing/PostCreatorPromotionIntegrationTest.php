<?php
/**
 * Integration tests for PostCreator promotion methods.
 *
 * Tests PostCreator::set_featured() and PostCreator::handle_promotion_request()
 * via reflection. The promote@ alias workflow is the only way to set
 * _agnosis_featured programmatically — approval no longer auto-promotes.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;

class PostCreatorPromotionIntegrationTest extends \WP_UnitTestCase {

	private PostCreator $creator;
	private int $artist_id;

	protected function setUp(): void {
		parent::setUp();

		// Minimal Pipeline stub — no AI calls, no WP option resolution.
		$pipeline = new class() extends Pipeline {
			public function __construct() {}
			/** @param array<string, mixed> $submission */
			public function process( array $submission, bool $skip_enhancement = false ): array {
				return []; }
		};

		$this->creator   = new PostCreator( $pipeline );
		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function call_set_featured( int $post_id, int $artist_id ): void {
		$ref = new \ReflectionMethod( PostCreator::class, 'set_featured' );
		$ref->setAccessible( true );
		$ref->invoke( $this->creator, $post_id, $artist_id );
	}

	private function call_handle_promotion_request( array $submission, int $artist_id ): void {
		$ref = new \ReflectionMethod( PostCreator::class, 'handle_promotion_request' );
		$ref->setAccessible( true );
		$ref->invoke( $this->creator, $submission, $artist_id, 0 );
	}

	// -------------------------------------------------------------------------
	// set_featured()
	// -------------------------------------------------------------------------

	public function test_set_featured_marks_post_as_featured(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'New Artwork',
		] );

		$this->call_set_featured( $post_id, $this->artist_id );

		$this->assertSame( '1', get_post_meta( $post_id, '_agnosis_featured', true ) );
	}

	public function test_set_featured_demotes_previously_featured_artwork_by_same_artist(): void {
		$old_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Old Featured',
		] );
		update_post_meta( $old_id, '_agnosis_featured', '1' );

		$new_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'New Artwork',
		] );

		$this->call_set_featured( $new_id, $this->artist_id );

		$this->assertSame( '0', get_post_meta( $old_id, '_agnosis_featured', true ) );
		$this->assertSame( '1', get_post_meta( $new_id, '_agnosis_featured', true ) );
	}

	public function test_set_featured_does_not_demote_artwork_by_other_artist(): void {
		$other_artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$other_post_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $other_artist_id,
			'post_title'  => 'Other Artist Artwork',
		] );
		update_post_meta( $other_post_id, '_agnosis_featured', '1' );

		$own_post_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'My Artwork',
		] );

		$this->call_set_featured( $own_post_id, $this->artist_id );

		// Other artist's featured flag must be untouched.
		$this->assertSame( '1', get_post_meta( $other_post_id, '_agnosis_featured', true ) );
	}

	public function test_set_featured_does_not_demote_draft_artworks(): void {
		// set_featured queries published artworks only — drafts must be untouched.
		$draft_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'draft',
			'post_author' => $this->artist_id,
			'post_title'  => 'Draft Artwork',
		] );
		update_post_meta( $draft_id, '_agnosis_featured', '1' );

		$published_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Published Artwork',
		] );

		$this->call_set_featured( $published_id, $this->artist_id );

		$this->assertSame( '1', get_post_meta( $draft_id, '_agnosis_featured', true ) );
	}

	// -------------------------------------------------------------------------
	// handle_promotion_request()
	// -------------------------------------------------------------------------

	public function test_promote_request_features_artwork_by_title(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$this->call_handle_promotion_request( [ 'subject' => 'Golden Hour' ], $this->artist_id );

		$this->assertSame( '1', get_post_meta( $post_id, '_agnosis_featured', true ) );
	}

	public function test_promote_request_with_no_matching_title_leaves_meta_unchanged(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$this->call_handle_promotion_request( [ 'subject' => 'Nonexistent Title' ], $this->artist_id );

		$this->assertNotSame( '1', get_post_meta( $post_id, '_agnosis_featured', true ) );
	}

	public function test_promote_request_with_empty_subject_leaves_meta_unchanged(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$this->call_handle_promotion_request( [ 'subject' => '' ], $this->artist_id );

		$this->assertNotSame( '1', get_post_meta( $post_id, '_agnosis_featured', true ) );
	}

	public function test_promote_request_does_not_match_draft_artworks(): void {
		// Only published artworks are eligible; a draft with a matching title must
		// not be promoted.
		$draft_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'draft',
			'post_author' => $this->artist_id,
			'post_title'  => 'Secret Work',
		] );

		$this->call_handle_promotion_request( [ 'subject' => 'Secret Work' ], $this->artist_id );

		$this->assertNotSame( '1', get_post_meta( $draft_id, '_agnosis_featured', true ) );
	}

	public function test_promote_request_does_not_match_artwork_by_other_artist(): void {
		$other_artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$other_post_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $other_artist_id,
			'post_title'  => 'Shared Title',
		] );

		// Promotion request from $this->artist_id — must not affect the other artist's post.
		$this->call_handle_promotion_request( [ 'subject' => 'Shared Title' ], $this->artist_id );

		$this->assertNotSame( '1', get_post_meta( $other_post_id, '_agnosis_featured', true ) );
	}
}
