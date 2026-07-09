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

	private function call_handle_promotion_request( array $submission, int $artist_id ): bool {
		$ref = new \ReflectionMethod( PostCreator::class, 'handle_promotion_request' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->creator, $submission, $artist_id, 0 );
	}

	/** @return array<int, array<mixed>> Every call recorded for $hook during $run(). */
	private function capture_action( string $hook, callable $run ): array {
		$calls = [];
		$cb    = function ( ...$args ) use ( &$calls ): void {
			$calls[] = $args;
		};
		add_action( $hook, $cb, 10, 10 );
		$run();
		remove_action( $hook, $cb, 10 );
		return $calls;
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

	// -------------------------------------------------------------------------
	// handle_promotion_request() — bool return value (2b) — used by handle()
	// to mark the queue row 'published' (true) vs 'skipped' (false). Never
	// asserted by any existing test above.
	// -------------------------------------------------------------------------

	public function test_promote_request_returns_true_on_a_match(): void {
		wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$this->assertTrue( $this->call_handle_promotion_request( [ 'subject' => 'Golden Hour' ], $this->artist_id ) );
	}

	public function test_promote_request_returns_false_on_no_match(): void {
		$this->assertFalse( $this->call_handle_promotion_request( [ 'subject' => 'Nonexistent Title' ], $this->artist_id ) );
	}

	// -------------------------------------------------------------------------
	// handle_promotion_request() — do_action('agnosis_promotion_result', ...)
	// firing (2b/2c). Never exercised by any existing test — no action spy was
	// ever registered in this file before.
	// -------------------------------------------------------------------------

	public function test_promote_request_fires_promotion_result_with_success_true_on_match(): void {
		wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$calls = $this->capture_action( 'agnosis_promotion_result', function () {
			$this->call_handle_promotion_request( [ 'subject' => 'Golden Hour' ], $this->artist_id );
		} );

		$this->assertCount( 1, $calls );
		[ $artist_id, $subject, $success, $titles, $suggestion_title ] = $calls[0];
		$this->assertSame( $this->artist_id, $artist_id );
		$this->assertSame( 'Golden Hour', $subject );
		$this->assertTrue( $success );
		$this->assertSame( [], $titles );
		$this->assertSame( '', $suggestion_title );
	}

	public function test_promote_request_fires_promotion_result_with_success_false_and_current_titles_on_miss(): void {
		wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Existing Artwork',
		] );

		$calls = $this->capture_action( 'agnosis_promotion_result', function () {
			$this->call_handle_promotion_request( [ 'subject' => 'A title that does not exist' ], $this->artist_id );
		} );

		$this->assertCount( 1, $calls );
		[ , , $success, $titles ] = $calls[0];
		$this->assertFalse( $success );
		$this->assertContains( 'Existing Artwork', $titles );
	}

	public function test_promote_request_includes_fuzzy_suggestion_when_pipeline_chat_succeeds(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour at the Bay',
		] );

		// A Pipeline stub whose chat() plausibly answers the fuzzy-match prompt
		// with this post's own ID — proves gather_title_context() actually
		// wires a successful AI response through to the fired action.
		$pipeline = new class( $post_id ) extends Pipeline {
			public function __construct( private int $post_id ) {}
			/** @param array<string, mixed> $submission */
			public function process( array $submission, bool $skip_enhancement = false ): array {
				return [];
			}
			public function chat( string $prompt ): string {
				return (string) $this->post_id;
			}
		};
		$creator = new PostCreator( $pipeline );

		$ref = new \ReflectionMethod( PostCreator::class, 'handle_promotion_request' );
		$ref->setAccessible( true );

		$calls = $this->capture_action( 'agnosis_promotion_result', function () use ( $ref, $creator ) {
			$ref->invoke( $creator, [ 'subject' => 'Golden Hour at the Bay (typo)' ], $this->artist_id, 0 );
		} );

		[ , , $success, , $suggestion_title ] = $calls[0];
		$this->assertFalse( $success );
		$this->assertSame( 'Golden Hour at the Bay', $suggestion_title );
	}
}
