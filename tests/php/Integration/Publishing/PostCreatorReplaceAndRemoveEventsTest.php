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

	// -------------------------------------------------------------------------
	// handle_removal_request() — an exact title matching MORE than one post
	// (2026-07-14): an artwork and an event are free to share a title, so a
	// remove@ subject can legitimately match both. This must offer a choice
	// rather than silently acting on (or arbitrarily picking) just one.
	// -------------------------------------------------------------------------

	public function test_removal_request_with_shared_title_across_artwork_and_event_stores_a_token_on_both(): void {
		$artwork_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );
		$event_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$this->call_handle_removal_request( [ 'subject' => 'Golden Hour' ], $this->artist_id );

		$this->assertNotEmpty( get_post_meta( $artwork_id, '_agnosis_removal_token', true ) );
		$this->assertNotEmpty( get_post_meta( $event_id, '_agnosis_removal_token', true ) );
		// The two tokens must be independent, not the same value copied twice.
		$this->assertNotSame(
			get_post_meta( $artwork_id, '_agnosis_removal_token', true ),
			get_post_meta( $event_id, '_agnosis_removal_token', true )
		);
	}

	public function test_removal_request_with_shared_title_returns_true(): void {
		wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );
		wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$result = $this->call_handle_removal_request( [ 'subject' => 'Golden Hour' ], $this->artist_id );

		$this->assertTrue( $result );
	}

	public function test_removal_request_with_shared_title_fires_multiple_action_not_the_single_one(): void {
		$artwork_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );
		$event_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$single_calls   = [];
		$multiple_calls = [];
		add_action( 'agnosis_removal_requested', function ( ...$args ) use ( &$single_calls ): void {
			$single_calls[] = $args;
		}, 10, 10 );
		add_action( 'agnosis_removal_requested_multiple', function ( ...$args ) use ( &$multiple_calls ): void {
			$multiple_calls[] = $args;
		}, 10, 10 );

		$this->call_handle_removal_request( [ 'subject' => 'Golden Hour' ], $this->artist_id );

		$this->assertCount( 0, $single_calls, 'The single-post action must not fire when more than one post matched.' );
		$this->assertCount( 1, $multiple_calls );

		[ $matches, $artist_id, $subject ] = $multiple_calls[0];
		$this->assertSame( $this->artist_id, $artist_id );
		$this->assertSame( 'Golden Hour', $subject );
		$this->assertCount( 2, $matches );

		$matched_ids = array_column( $matches, 'id' );
		$this->assertContains( $artwork_id, $matched_ids );
		$this->assertContains( $event_id, $matched_ids );

		foreach ( $matches as $match ) {
			$this->assertNotEmpty( $match['token'] );
			$this->assertSame(
				get_post_meta( $match['id'], '_agnosis_removal_token', true ),
				$match['token'],
				'The token passed to the action must be the exact one persisted to post meta.'
			);
			$this->assertContains( $match['type'], [ 'agnosis_artwork', 'agnosis_event' ] );
		}
	}

	public function test_removal_request_with_shared_title_does_not_match_a_third_artist_own_post(): void {
		// Sanity check: the multi-match branch is still scoped per-artist —
		// a same-titled post belonging to someone else must never appear
		// alongside this artist's own matches.
		$other_artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $other_artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$artwork_id = wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );
		$event_id = wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'Golden Hour',
		] );

		$calls = $this->capture_action( 'agnosis_removal_requested_multiple', function () {
			$this->call_handle_removal_request( [ 'subject' => 'Golden Hour' ], $this->artist_id );
		} );

		$this->assertCount( 1, $calls );
		$matched_ids = array_column( $calls[0][0], 'id' );
		$this->assertCount( 2, $matched_ids );
		$this->assertContains( $artwork_id, $matched_ids );
		$this->assertContains( $event_id, $matched_ids );
	}

	// -------------------------------------------------------------------------
	// resolve_endpoint_label() — Inbox admin table's Endpoint column (2026-07-14).
	// A remove@/promote@ message that still ends up in a mark_no_artwork()
	// skip row (e.g. Email\Parser's management-address exemption missing an
	// edge case) must be labelled by its real recipient, not fall back to the
	// "Artwork" default — Inbox::mark_no_artwork() was just fixed to stash
	// 'subject'/'to_addresses' in that scenario; this confirms the label
	// resolution on the reading end actually uses them correctly.
	// -------------------------------------------------------------------------

	public function test_resolve_endpoint_label_recognises_remove_address(): void {
		update_option( 'agnosis_email_remove', 'remove@example.com' );

		$label = PostCreator::resolve_endpoint_label( [
			'subject'      => 'Golden Hour',
			'to_addresses' => [ 'remove@example.com' ],
		] );

		$this->assertSame( 'Remove', $label );

		delete_option( 'agnosis_email_remove' );
	}

	public function test_resolve_endpoint_label_recognises_promote_address(): void {
		update_option( 'agnosis_email_promote', 'promote@example.com' );

		$label = PostCreator::resolve_endpoint_label( [
			'subject'      => 'Golden Hour',
			'to_addresses' => [ 'promote@example.com' ],
		] );

		$this->assertSame( 'Promote', $label );

		delete_option( 'agnosis_email_promote' );
	}

	public function test_resolve_endpoint_label_reports_unknown_without_any_recipient_or_subject_context(): void {
		update_option( 'agnosis_email_remove', 'remove@example.com' );

		// The exact stale-row shape the original bug produced: only 'from'
		// was ever stashed, so the label resolver has nothing to classify by
		// at all. This must say so plainly — "Artwork" would be asserting a
		// specific, likely wrong, classification for a message this code
		// never actually looked at.
		$label = PostCreator::resolve_endpoint_label( [ 'from' => 'artist@example.com' ] );

		$this->assertSame( 'Unknown', $label );

		delete_option( 'agnosis_email_remove' );
	}

	public function test_resolve_endpoint_label_returns_artwork_only_when_context_was_captured_and_matched_nothing_special(): void {
		// Unlike the "no context at all" case above, a row that DID capture a
		// subject/recipient — and that context simply didn't match any
		// special route or subject indicator — really is the same "Artwork"
		// default resolve_post_type() itself would land on, so this remains
		// a genuine classification rather than a guess.
		$label = PostCreator::resolve_endpoint_label( [
			'subject'      => 'Golden Hour',
			'to_addresses' => [ 'submit@example.com' ],
		] );

		$this->assertSame( 'Artwork', $label );
	}

	public function test_resolve_endpoint_label_recognises_goodbye_address(): void {
		update_option( 'agnosis_email_goodbye', 'goodbye@example.com' );

		$label = PostCreator::resolve_endpoint_label( [
			'subject'      => 'Goodbye',
			'to_addresses' => [ 'goodbye@example.com' ],
		] );

		$this->assertSame( 'Goodbye', $label );

		delete_option( 'agnosis_email_goodbye' );
	}

	public function test_resolve_endpoint_label_recognises_community_address(): void {
		update_option( 'agnosis_email_community', 'community@example.com' );

		$label = PostCreator::resolve_endpoint_label( [
			'subject'      => 'Announcement',
			'to_addresses' => [ 'community@example.com' ],
		] );

		$this->assertSame( 'Community', $label );

		delete_option( 'agnosis_email_community' );
	}
}
