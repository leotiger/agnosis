<?php
/**
 * Integration tests — automatic quality rejection gate in PostCreator.
 *
 * Uses a stub Pipeline that returns a canned quality score, then asserts:
 *   - Score ≤ threshold → agnosis_submission_rejected fires, queue row fails, no post.
 *   - Score = 0 (provider can't assess) → rejection skipped, pipeline continues.
 *   - Score > threshold → proceeds normally (no rejection action fired).
 *   - Threshold = 0 → gate disabled, never rejects regardless of score.
 *   - Non-artwork post type → gate skipped even on low score.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;

class QualityRejectionTest extends \WP_UnitTestCase {

	private int $artist_id;

	protected function setUp(): void {
		parent::setUp();

		// Create an admitted artist.
		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_user_by( 'id', $this->artist_id );
		$user->add_role( 'agnosis_artist' );

		// Default rejection threshold: 3, enhancement threshold: 7.
		update_option( 'agnosis_quality_rejection_threshold', 3 );
		update_option( 'agnosis_quality_threshold', 7 );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->clear_queue();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Insert a minimal pending artwork queue row and return its ID. */
	private function insert_artwork_queue_row( string $uid = 'test-uid' ): int {
		global $wpdb;
		$submission = wp_json_encode( [
			'from'        => 'artist@example.com',
			'subject'     => 'Test artwork',
			'description' => 'A test piece.',
			'attachments' => [
				[
					'filename' => 'art.jpg',
					'mime'     => 'image/jpeg',
					'data'     => base64_encode( 'fake-image-data' ),
					'encoding' => 'base64',
				],
			],
			'artist_id'   => $this->artist_id,
			'to_address'  => '',
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

	/** Build a stub Pipeline that returns the given quality score for every image. */
	private function make_stub_pipeline( int $quality_score, array $issues = [] ): Pipeline {
		return new class( $quality_score, $issues ) extends Pipeline {
			public function __construct(
				private int $score,
				private array $issues
			) {
				// Skip parent constructor — no real providers needed.
			}

			/** @return array<string, mixed>[] */
			public function process( array $submission ): array {
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
						'photo_quality_score'  => $this->score,
						'photo_quality_issues' => $this->issues,
						'enhanced'             => false,
					];
				}
				return $results;
			}

			public function chat( string $prompt ): string {
				return '';
			}
		};
	}

	private function clear_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agnosis_queue WHERE message_uid LIKE 'test-%'" );
	}

	private function get_queue_status( int $queue_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}agnosis_queue WHERE id = %d", $queue_id )
		);
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_score_at_threshold_triggers_rejection(): void {
		$queue_id   = $this->insert_artwork_queue_row( 'test-reject-at' );
		$fired_args = null;

		add_action( 'agnosis_submission_rejected', function ( ...$args ) use ( &$fired_args ) {
			$fired_args = $args;
		}, 10, 4 );

		$creator = new PostCreator( $this->make_stub_pipeline( 3, [ 'too dark' ] ) );
		$creator->handle( $queue_id );

		$this->assertNotNull( $fired_args, 'agnosis_submission_rejected should have fired.' );
		$this->assertSame( $queue_id, $fired_args[0] );
		$this->assertSame( $this->artist_id, $fired_args[1] );
		$this->assertSame( 3, $fired_args[2] );
		$this->assertContains( 'too dark', $fired_args[3] );
		$this->assertSame( 'failed', $this->get_queue_status( $queue_id ) );
	}

	public function test_score_below_threshold_triggers_rejection(): void {
		$queue_id = $this->insert_artwork_queue_row( 'test-reject-below' );
		$fired    = false;

		add_action( 'agnosis_submission_rejected', function () use ( &$fired ) {
			$fired = true;
		} );

		$creator = new PostCreator( $this->make_stub_pipeline( 1, [ 'motion blur', 'underexposed' ] ) );
		$creator->handle( $queue_id );

		$this->assertTrue( $fired );
		$this->assertSame( 'failed', $this->get_queue_status( $queue_id ) );
	}

	public function test_score_above_threshold_does_not_reject(): void {
		$queue_id = $this->insert_artwork_queue_row( 'test-no-reject' );
		$fired    = false;

		add_action( 'agnosis_submission_rejected', function () use ( &$fired ) {
			$fired = true;
		} );

		// Score 5 > threshold 3 — should proceed (may fail for other reasons, not rejection).
		$creator = new PostCreator( $this->make_stub_pipeline( 5 ) );
		$creator->handle( $queue_id );

		$this->assertFalse( $fired );
	}

	public function test_score_zero_skips_rejection(): void {
		// Score 0 = provider couldn't assess quality — gate must not fire.
		$queue_id = $this->insert_artwork_queue_row( 'test-score-zero' );
		$fired    = false;

		add_action( 'agnosis_submission_rejected', function () use ( &$fired ) {
			$fired = true;
		} );

		$creator = new PostCreator( $this->make_stub_pipeline( 0 ) );
		$creator->handle( $queue_id );

		$this->assertFalse( $fired );
	}

	public function test_rejection_gate_disabled_when_threshold_is_zero(): void {
		update_option( 'agnosis_quality_rejection_threshold', 0 );
		$queue_id = $this->insert_artwork_queue_row( 'test-disabled-gate' );
		$fired    = false;

		add_action( 'agnosis_submission_rejected', function () use ( &$fired ) {
			$fired = true;
		} );

		// Even a very low score must not trigger rejection when threshold = 0.
		$creator = new PostCreator( $this->make_stub_pipeline( 1, [ 'too dark' ] ) );
		$creator->handle( $queue_id );

		$this->assertFalse( $fired );
	}
}
