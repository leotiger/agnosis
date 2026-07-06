<?php
/**
 * Integration tests: replace@ and remove@ now cover agnosis_event as well as
 * agnosis_artwork (2026-07-06) — both used to be artwork-only.
 *
 * Exercises the private resolution helpers directly via reflection, the same
 * way PostCreatorPromotionIntegrationTest.php and
 * PostCreatorEventTitleMatchTest.php do, without needing to run the full
 * queue-row handle() pipeline.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;

class PostCreatorReplaceAndRemoveEventsTest extends \WP_UnitTestCase {

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

	/**
	 * @param string|string[] $post_type
	 */
	private function find_post_by_subject( string $subject, int $artist_id, string|array $post_type = 'agnosis_artwork' ): int {
		$ref = new \ReflectionMethod( PostCreator::class, 'find_post_by_subject' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->creator, $subject, $artist_id, $post_type );
	}

	private function call_handle_removal_request( array $submission, int $artist_id ): void {
		$ref = new \ReflectionMethod( PostCreator::class, 'handle_removal_request' );
		$ref->setAccessible( true );
		$ref->invoke( $this->creator, $submission, $artist_id, 0 );
	}

	// -------------------------------------------------------------------------
	// find_post_by_subject() — combined-type search (replace@'s new behaviour)
	// -------------------------------------------------------------------------

	public function test_combined_search_finds_an_artwork(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$found = $this->find_post_by_subject( 'Golden Hour', $this->artist_id, [ 'agnosis_artwork', 'agnosis_event' ] );

		$this->assertSame( $post_id, $found );
		$this->assertSame( 'agnosis_artwork', get_post_type( $found ) );
	}

	public function test_combined_search_finds_an_event(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Solo show — Gallery X',
		] );

		$found = $this->find_post_by_subject( 'Solo show — Gallery X', $this->artist_id, [ 'agnosis_artwork', 'agnosis_event' ] );

		$this->assertSame( $post_id, $found );
		$this->assertSame( 'agnosis_event', get_post_type( $found ) );
	}

	public function test_combined_search_returns_zero_when_neither_type_matches(): void {
		wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$found = $this->find_post_by_subject( 'Nonexistent Title', $this->artist_id, [ 'agnosis_artwork', 'agnosis_event' ] );

		$this->assertSame( 0, $found );
	}

	// -------------------------------------------------------------------------
	// handle_removal_request() — remove@ now finds events too
	// -------------------------------------------------------------------------

	public function test_removal_request_finds_matching_event_and_stores_token(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Solo show — Gallery X',
		] );

		$this->call_handle_removal_request( [ 'subject' => 'Solo show — Gallery X' ], $this->artist_id );

		$this->assertNotEmpty( get_post_meta( $post_id, '_agnosis_removal_token', true ) );
		$this->assertNotEmpty( get_post_meta( $post_id, '_agnosis_removal_expiry', true ) );
	}

	public function test_removal_request_with_no_matching_event_or_artwork_stores_no_token(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Solo show — Gallery X',
		] );

		$this->call_handle_removal_request( [ 'subject' => 'A Totally Different Title' ], $this->artist_id );

		$this->assertEmpty( get_post_meta( $post_id, '_agnosis_removal_token', true ) );
	}

	public function test_removal_request_does_not_match_event_by_other_artist(): void {
		$other_artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $other_artist_id,
			'post_title'  => 'Shared Title',
		] );

		$this->call_handle_removal_request( [ 'subject' => 'Shared Title' ], $this->artist_id );

		$this->assertEmpty( get_post_meta( $post_id, '_agnosis_removal_token', true ) );
	}
}
