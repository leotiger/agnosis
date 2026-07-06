<?php
/**
 * Integration tests for PostCreator::find_post_by_subject() as used by the
 * event@ intake path.
 *
 * 2026-07-06: an artist can have several events, so a new [Event] email no
 * longer merges into "the" event. Instead — mirroring the replace@ mechanism
 * already used for artwork — it updates an existing event in place only when
 * the email subject exactly matches that event's title; any other subject
 * creates a new event post. These tests exercise find_post_by_subject()
 * directly (via reflection) with post_type = 'agnosis_event', the same way
 * PostCreatorPromotionIntegrationTest.php exercises other private resolution
 * helpers without needing to run the full queue-row handle() pipeline.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;

class PostCreatorEventTitleMatchTest extends \WP_UnitTestCase {

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

	private function find_post_by_subject( string $subject, int $artist_id, string $post_type = 'agnosis_artwork' ): int {
		$ref = new \ReflectionMethod( PostCreator::class, 'find_post_by_subject' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->creator, $subject, $artist_id, $post_type );
	}

	public function test_matching_subject_finds_existing_event_by_title(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Solo show — Gallery X',
		] );

		$found = $this->find_post_by_subject( 'Solo show — Gallery X', $this->artist_id, 'agnosis_event' );

		$this->assertSame( $post_id, $found );
	}

	public function test_non_matching_subject_returns_zero_so_a_new_event_is_created(): void {
		wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Solo show — Gallery X',
		] );

		$found = $this->find_post_by_subject( 'Group show — Gallery Y', $this->artist_id, 'agnosis_event' );

		$this->assertSame( 0, $found );
	}

	public function test_title_match_does_not_cross_artists(): void {
		$other_artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $other_artist_id,
			'post_title'  => 'Shared Title',
		] );

		// Same title, different (requesting) artist — must not match.
		$found = $this->find_post_by_subject( 'Shared Title', $this->artist_id, 'agnosis_event' );

		$this->assertSame( 0, $found );
	}

	public function test_title_match_finds_draft_events_too(): void {
		// Mirrors replace@'s behaviour for artwork: a not-yet-published event
		// (e.g. still pending review) is still a valid update target.
		$draft_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'draft',
			'post_author' => $this->artist_id,
			'post_title'  => 'Upcoming show',
		] );

		$found = $this->find_post_by_subject( 'Upcoming show', $this->artist_id, 'agnosis_event' );

		$this->assertSame( $draft_id, $found );
	}

	public function test_title_match_stays_scoped_to_event_post_type(): void {
		// An artwork with the same title as an event must not be matched when
		// searching within 'agnosis_event' — the post_type param must actually
		// constrain the query, not just default correctly.
		wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Ambiguous Title',
		] );

		$found = $this->find_post_by_subject( 'Ambiguous Title', $this->artist_id, 'agnosis_event' );

		$this->assertSame( 0, $found );
	}
}
