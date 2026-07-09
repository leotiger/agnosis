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

	private function call_handle_removal_request( array $submission, int $artist_id ): bool {
		$ref = new \ReflectionMethod( PostCreator::class, 'handle_removal_request' );
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

	// -------------------------------------------------------------------------
	// handle_removal_request() — bool return value (2b) — used by handle() to
	// mark the queue row 'published' (true) vs 'skipped' (false). Previously
	// untested: every test above only ever checked post meta as a side effect.
	// -------------------------------------------------------------------------

	public function test_removal_request_returns_true_on_a_match(): void {
		wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Solo show — Gallery X',
		] );

		$result = $this->call_handle_removal_request( [ 'subject' => 'Solo show — Gallery X' ], $this->artist_id );

		$this->assertTrue( $result );
	}

	public function test_removal_request_returns_false_on_no_match(): void {
		$result = $this->call_handle_removal_request( [ 'subject' => 'Nothing matches this' ], $this->artist_id );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// handle_removal_request() — do_action() firing (2b/2c) — the admin-
	// visibility/feedback-email hooks this method fires. Never exercised by
	// any existing test (no test in this file ever registered an action spy).
	// -------------------------------------------------------------------------

	public function test_removal_request_fires_agnosis_removal_requested_on_match(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Solo show — Gallery X',
		] );

		$calls = $this->capture_action( 'agnosis_removal_requested', function () {
			$this->call_handle_removal_request( [ 'subject' => 'Solo show — Gallery X' ], $this->artist_id );
		} );

		$this->assertCount( 1, $calls );
		$this->assertSame( [ $post_id, $this->artist_id ], $calls[0] );
	}

	public function test_removal_request_fires_agnosis_removal_target_not_found_on_miss_with_current_titles(): void {
		wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Existing Event',
		] );

		$calls = $this->capture_action( 'agnosis_removal_target_not_found', function () {
			$this->call_handle_removal_request( [ 'subject' => 'A title that does not exist' ], $this->artist_id );
		} );

		$this->assertCount( 1, $calls );
		[ $artist_id, $subject, $titles ] = $calls[0];
		$this->assertSame( $this->artist_id, $artist_id );
		$this->assertSame( 'A title that does not exist', $subject );
		$this->assertContains( 'Existing Event', $titles, 'The current-titles list fed to the feedback email must include the artist\'s existing post.' );
	}

	public function test_removal_request_target_not_found_action_omits_fuzzy_suggestion_when_pipeline_chat_is_unavailable(): void {
		// The Pipeline stub used throughout this file never sets up a real
		// description_provider — gather_title_context()'s try/catch around
		// pipeline->chat() must catch that failure gracefully and report no
		// suggestion, rather than ever throwing out of handle_removal_request().
		wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Existing Event',
		] );

		$calls = $this->capture_action( 'agnosis_removal_target_not_found', function () {
			$this->call_handle_removal_request( [ 'subject' => 'Totally different title' ], $this->artist_id );
		} );

		[ , , , $suggestion_id, $suggestion_title ] = $calls[0];
		$this->assertSame( 0, $suggestion_id );
		$this->assertSame( '', $suggestion_title );
	}

	public function test_removal_request_target_not_found_action_includes_fuzzy_suggestion_when_pipeline_chat_succeeds(): void {
		$post_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Solo show at Gallery X',
		] );

		// A Pipeline stub whose chat() plausibly answers the fuzzy-match prompt
		// with this post's own ID — proves gather_title_context() actually
		// wires a successful AI response through to the fired action, not just
		// the graceful-failure path every other test in this file exercises.
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

		$ref = new \ReflectionMethod( PostCreator::class, 'handle_removal_request' );
		$ref->setAccessible( true );

		$calls = $this->capture_action( 'agnosis_removal_target_not_found', function () use ( $ref, $creator ) {
			$ref->invoke( $creator, [ 'subject' => 'Solo show at Gallry X (typo)' ], $this->artist_id, 0 );
		} );

		[ , , , $suggestion_id, $suggestion_title ] = $calls[0];
		$this->assertSame( $post_id, $suggestion_id );
		$this->assertSame( 'Solo show at Gallery X', $suggestion_title );
	}
}
