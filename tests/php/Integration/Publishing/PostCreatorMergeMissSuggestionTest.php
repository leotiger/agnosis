<?php
/**
 * Integration tests — replace@/[Event] fuzzy "did you mean" suggestion on a
 * title-match miss (audit §2a).
 *
 * A replace@ or [Event]-update subject that doesn't exactly match one of the
 * artist's existing titles still creates a brand-new post — same as always,
 * and correctly so, since replace is destructive and is never auto-merged on
 * a fuzzy guess. What's new: PostCreator::gather_title_context() (the same
 * "did you mean" AI-comparison machinery §2c already built for
 * remove@/promote@) is now also consulted on a miss, and — when it finds a
 * plausible candidate among the artist's other posts — the new post is
 * tagged with `_agnosis_merge_miss_suggestion` (JSON {type, title}) so
 * Notification::build_email() can warn the artist this may be an unintended
 * duplicate instead of the review email reading like an ordinary new-artwork
 * draft.
 *
 * Runs the full handle() pipeline (queue row → PostCreator::handle()) rather
 * than reflecting into private helpers directly, since the behaviour under
 * test spans two separate branches (replace@ before the AI pipeline runs,
 * event@ after) plus create_post()'s meta write — reflection on any one of
 * those wouldn't exercise the property hand-off between them. Pipeline stub
 * mirrors QualityRejectionTest.php's shape (a minimal valid image result so
 * create_post() actually succeeds) with an added configurable chat()
 * response for gather_title_context()'s fuzzy-match prompt, and an
 * extract_event_fields() override (event@ tests only reach it, but harmless
 * to always provide) — Pipeline::extract_event_fields() calls
 * $this->description_provider->chat(), a *different* internal object our
 * chat() override never touches and which is never initialized when the
 * stub skips the real constructor.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;

class PostCreatorMergeMissSuggestionTest extends \WP_UnitTestCase {

	private int $artist_id;

	protected function setUp(): void {
		parent::setUp();

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_user_by( 'id', $this->artist_id )->add_role( 'agnosis_artist' );

		update_option( 'agnosis_quality_rejection_threshold', 0 ); // disable gate noise for this suite.
		update_option( 'agnosis_email_replace', 'replace@agnosis.art' );
		update_option( 'agnosis_email_event', 'event@agnosis.art' );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->clear_queue();
		delete_option( 'agnosis_email_replace' );
		delete_option( 'agnosis_email_event' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function insert_queue_row( string $uid, string $to_address, string $subject ): int {
		global $wpdb;
		$submission = wp_json_encode( [
			'from'        => 'artist@example.com',
			'subject'     => $subject,
			'description' => 'A test piece.',
			'attachments' => [
				[
					'filename' => 'art.jpg',
					'mime'     => 'image/jpeg',
					'data'     => base64_encode( 'fake-image-data' ),
					'encoding' => 'base64',
				],
			],
			'artist_id'  => $this->artist_id,
			'to_address' => $to_address,
			'source'     => 'test',
		] );
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_queue',
			[
				'message_uid' => $uid,
				'artist_id'   => $this->artist_id,
				'raw_email'   => $submission,
				'status'      => 'pending',
			],
			[ '%s', '%d', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	private function clear_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agnosis_queue WHERE message_uid LIKE 'test-mms-%'" );
	}

	/**
	 * Reads back the queue row's post_id, but first asserts the row actually
	 * reached 'published' — surfacing PostCreator::handle()'s caught
	 * Throwable message (the 'error' column) directly in the failure output
	 * instead of leaving a bare "0 is identical to N" to puzzle out blind.
	 */
	private function get_published_post_id( int $queue_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT post_id, status, error FROM {$wpdb->prefix}agnosis_queue WHERE id = %d", $queue_id )
		);
		$this->assertNotNull( $row, "Queue row #{$queue_id} vanished." );
		$this->assertSame(
			'published',
			$row->status,
			sprintf( 'Queue #%d ended in status "%s" instead of "published" — error column: %s', $queue_id, $row->status, $row->error ?? '(none)' )
		);
		return (int) $row->post_id;
	}

	private function merge_miss_meta( int $post_id ): array {
		$raw = (string) get_post_meta( $post_id, '_agnosis_merge_miss_suggestion', true );
		return $raw ? (array) json_decode( $raw, true ) : [];
	}

	/**
	 * @param string $chat_response What gather_title_context()'s fuzzy-match
	 *                              prompt should appear to answer — a post ID
	 *                              string to simulate a hit, or '0'/'' to
	 *                              simulate no plausible candidate.
	 */
	private function make_pipeline( string $chat_response = '0' ): Pipeline {
		return new class( $chat_response ) extends Pipeline {
			public function __construct( private string $chat_response ) {
				// Skip parent constructor — no real provider needed.
			}

			/** @return array<string, mixed>[] */
			public function process( array $submission, bool $skip_enhancement = false ): array {
				$results = [];
				foreach ( $submission['attachments'] ?? [] as $att ) {
					$results[] = [
						'filename'             => $att['filename'] ?? 'art.jpg',
						'original_data'        => $att['data'] ?? '',
						'enhanced_data'        => $att['data'] ?? '',
						'mime_type'            => $att['mime'] ?? 'image/jpeg',
						'title'                => 'Test Artwork',
						'excerpt'              => 'A test piece.',
						'body'                 => '<p>A test piece.</p>',
						'tags'                 => [ 'test' ],
						'alt_text'             => 'A test artwork.',
						'description_ok'       => true,
						'error'                => null,
						'photo_quality_score'  => 8,
						'photo_quality_issues' => [],
						'enhanced'             => false,
					];
				}
				return $results;
			}

			public function chat( string $prompt, int $min_tokens = 0 ): string {
				return $this->chat_response;
			}

			/** @return array{location: string, address: string, event_date: string, timezone: string} */
			public function extract_event_fields( array $submission ): array {
				// See class docblock: the real implementation reaches an
				// uninitialized $description_provider when the constructor is
				// skipped — short-circuited here rather than exercised.
				return [ 'location' => '', 'address' => '', 'event_date' => '', 'timezone' => '' ];
			}
		};
	}

	/**
	 * Every real Agnosis post created via PostCreator::create_post() writes
	 * '_agnosis_gallery_ids' meta_input — even an empty array serializes to a
	 * real array on readback. A post built via a bare wp_insert_post() (as
	 * these fixtures are) never gets that meta at all, so
	 * get_post_meta( $id, '_agnosis_gallery_ids', true ) returns ''.
	 *
	 * Patch 18: merge_gallery() used to blindly (array)-cast that return
	 * value — (array) '' is [''] (one empty-string element), not [] — and the
	 * stray '' survived into build_image_block()'s int $id parameter. That's
	 * fixed now (merge_gallery() validates is_array() before trusting the
	 * meta), so this explicit empty-array seed is no longer load-bearing for
	 * THIS bug specifically. Left in place anyway because it's still what
	 * every real pre-existing post has once created via create_post() (and
	 * matches what Artist\ApplicationBiography's auto-created biography
	 * drafts do NOT have, which is what originally surfaced this bug in
	 * production) — these fixtures should keep looking like a real post.
	 */
	private function create_artwork( string $title ): int {
		$id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => $title,
		] );
		update_post_meta( $id, '_agnosis_gallery_ids', [] );
		return $id;
	}

	private function create_event( string $title ): int {
		$id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => $title,
		] );
		update_post_meta( $id, '_agnosis_gallery_ids', [] );
		return $id;
	}

	// =========================================================================
	// replace@
	// =========================================================================

	public function test_replace_miss_with_fuzzy_candidate_sets_suggestion_meta(): void {
		$existing_id = $this->create_artwork( 'Sunset Over the Harbor' );

		$queue_id = $this->insert_queue_row( 'test-mms-replace-hit', 'replace@agnosis.art', 'Sunset Over teh Harbor' );
		$creator  = new PostCreator( $this->make_pipeline( (string) $existing_id ) );
		$creator->handle( $queue_id );

		$new_post_id = $this->get_published_post_id( $queue_id );
		$this->assertNotSame( $existing_id, $new_post_id, 'A miss must create a NEW post, never auto-merge into the fuzzy guess.' );

		$meta = $this->merge_miss_meta( $new_post_id );
		$this->assertSame( 'replace', $meta['type'] ?? null );
		$this->assertSame( 'Sunset Over the Harbor', $meta['title'] ?? null );
	}

	public function test_replace_miss_without_fuzzy_candidate_sets_no_suggestion(): void {
		$this->create_artwork( 'Completely Unrelated Title' );

		$queue_id = $this->insert_queue_row( 'test-mms-replace-nohit', 'replace@agnosis.art', 'Nothing Like It' );
		$creator  = new PostCreator( $this->make_pipeline( '0' ) );
		$creator->handle( $queue_id );

		$new_post_id = $this->get_published_post_id( $queue_id );
		$this->assertSame( [], $this->merge_miss_meta( $new_post_id ) );
	}

	public function test_replace_exact_match_sets_no_suggestion_meta(): void {
		$existing_id = $this->create_artwork( 'Exact Title Match' );

		$queue_id = $this->insert_queue_row( 'test-mms-replace-exact', 'replace@agnosis.art', 'Exact Title Match' );
		// chat() would return the existing post's ID if ever called for a
		// fuzzy check — proves the exact-match branch never reaches that path.
		$creator = new PostCreator( $this->make_pipeline( (string) $existing_id ) );
		$creator->handle( $queue_id );

		// Patch 18 "true staging": the fixture target is publish()'d, so an
		// exact match no longer updates it in place — that would take
		// already-live content offline the moment a fresh review token is
		// minted (see PostCreatorMergeRedraftsPublishedTargetTest). Instead a
		// separate staging draft is created, tagged back to the live post.
		$staged_post_id = $this->get_published_post_id( $queue_id );
		$this->assertNotSame( $existing_id, $staged_post_id, 'A replace@ exact match onto an already-published post must stage the update as a separate draft, not write to the live post directly.' );
		$this->assertSame(
			$existing_id,
			(int) get_post_meta( $staged_post_id, '_agnosis_pending_update_for', true ),
			'The staging draft must be tagged back to the live post it is an update for.'
		);
		$this->assertSame( 'publish', get_post_status( $existing_id ), 'The live post must remain untouched (still published) while the update is only staged.' );
		$this->assertSame( [], $this->merge_miss_meta( $staged_post_id ) );
	}

	// =========================================================================
	// event@
	// =========================================================================

	public function test_event_update_miss_with_fuzzy_candidate_sets_suggestion_meta(): void {
		$existing_id = $this->create_event( 'Solo Show at Gallery X' );

		$queue_id = $this->insert_queue_row( 'test-mms-event-hit', 'event@agnosis.art', 'Solo Show at Gallry X (typo)' );
		$creator  = new PostCreator( $this->make_pipeline( (string) $existing_id ) );
		$creator->handle( $queue_id );

		$new_post_id = $this->get_published_post_id( $queue_id );
		$this->assertNotSame( $existing_id, $new_post_id );

		$meta = $this->merge_miss_meta( $new_post_id );
		$this->assertSame( 'event_update', $meta['type'] ?? null );
		$this->assertSame( 'Solo Show at Gallery X', $meta['title'] ?? null );
	}

	public function test_event_update_miss_without_fuzzy_candidate_sets_no_suggestion(): void {
		$this->create_event( 'Completely Different Event' );

		$queue_id = $this->insert_queue_row( 'test-mms-event-nohit', 'event@agnosis.art', 'Nothing Like It' );
		$creator  = new PostCreator( $this->make_pipeline( '0' ) );
		$creator->handle( $queue_id );

		$new_post_id = $this->get_published_post_id( $queue_id );
		$this->assertSame( [], $this->merge_miss_meta( $new_post_id ) );
	}

	public function test_event_update_exact_match_sets_no_suggestion_meta(): void {
		$existing_id = $this->create_event( 'Exact Event Title' );

		$queue_id = $this->insert_queue_row( 'test-mms-event-exact', 'event@agnosis.art', 'Exact Event Title' );
		$creator  = new PostCreator( $this->make_pipeline( (string) $existing_id ) );
		$creator->handle( $queue_id );

		// Patch 18 "true staging": the fixture target is publish()'d, so an
		// exact title match no longer updates it in place — see the matching
		// comment on test_replace_exact_match_sets_no_suggestion_meta above.
		$staged_post_id = $this->get_published_post_id( $queue_id );
		$this->assertNotSame( $existing_id, $staged_post_id, 'An event-title exact match onto an already-published event must stage the update as a separate draft, not write to the live post directly.' );
		$this->assertSame(
			$existing_id,
			(int) get_post_meta( $staged_post_id, '_agnosis_pending_update_for', true ),
			'The staging draft must be tagged back to the live event it is an update for.'
		);
		$this->assertSame( 'publish', get_post_status( $existing_id ), 'The live event must remain untouched (still published) while the update is only staged.' );
		$this->assertSame( [], $this->merge_miss_meta( $staged_post_id ) );
	}

	public function test_event_fuzzy_suggestion_never_matches_an_artwork_title(): void {
		// An artwork titled identically to what the AI would "confirm" must
		// never surface as an event-update suggestion — gather_title_context()
		// is scoped to 'agnosis_event' only for this branch (see PostCreator's
		// own comment at that call site).
		$artwork_id = $this->create_artwork( 'Ambiguous Title' );

		$queue_id = $this->insert_queue_row( 'test-mms-event-scope', 'event@agnosis.art', 'Ambiguous Titel' );
		// chat() would happily "confirm" the artwork's ID if the candidate
		// pool were ever allowed to include it — it must not be presented
		// with that option at all, since $titles_map is built strictly from
		// the (correctly scoped) get_posts() candidates.
		$creator = new PostCreator( $this->make_pipeline( (string) $artwork_id ) );
		$creator->handle( $queue_id );

		$new_post_id = $this->get_published_post_id( $queue_id );
		$this->assertSame( [], $this->merge_miss_meta( $new_post_id ), 'chat() answering with an out-of-scope ID must not produce a suggestion — gather_title_context() only trusts an ID present in its own candidate map.' );
	}

	// =========================================================================
	// Non-replace/event submissions and cross-row leakage
	// =========================================================================

	public function test_ordinary_artwork_submission_never_sets_suggestion_meta(): void {
		$queue_id = $this->insert_queue_row( 'test-mms-plain', '', 'A Brand New Piece' );
		$creator  = new PostCreator( $this->make_pipeline( '0' ) );
		$creator->handle( $queue_id );

		$new_post_id = $this->get_published_post_id( $queue_id );
		$this->assertSame( [], $this->merge_miss_meta( $new_post_id ) );
	}

	public function test_suggestion_does_not_leak_across_queue_rows_on_the_same_creator_instance(): void {
		$existing_id = $this->create_artwork( 'Sunset Over the Harbor' );
		$creator     = new PostCreator( $this->make_pipeline( (string) $existing_id ) );

		// Row 1: a replace@ miss that DOES get a fuzzy suggestion.
		$queue_id_1 = $this->insert_queue_row( 'test-mms-leak-1', 'replace@agnosis.art', 'Sunset Over teh Harbor' );
		$creator->handle( $queue_id_1 );
		$post_id_1 = $this->get_published_post_id( $queue_id_1 );
		$this->assertNotSame( [], $this->merge_miss_meta( $post_id_1 ), 'Sanity check: row 1 must actually get a suggestion.' );

		// Row 2: an ordinary artwork submission on the SAME PostCreator
		// instance — must not inherit row 1's stale suggestion.
		$queue_id_2 = $this->insert_queue_row( 'test-mms-leak-2', '', 'An Unrelated New Piece' );
		$creator->handle( $queue_id_2 );
		$post_id_2 = $this->get_published_post_id( $queue_id_2 );
		$this->assertSame( [], $this->merge_miss_meta( $post_id_2 ), 'A later, unrelated queue row must never inherit a prior row\'s merge-miss suggestion.' );
	}
}
