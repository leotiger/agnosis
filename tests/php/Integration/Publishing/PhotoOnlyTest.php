<?php
/**
 * Integration tests for the photo-only intake lane.
 *
 * Covers:
 *   - photo@ address routing resolves to agnosis_artwork with photo_only = true.
 *   - [Photo] subject indicator resolves the same way.
 *   - Quality rejection gate is skipped when photo_only.
 *   - Pipeline is called with skip_enhancement = true when photo_only.
 *   - Standard artwork submissions still trigger the quality gate.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;

class PhotoOnlyTest extends \WP_UnitTestCase {

	private int $artist_id;

	protected function setUp(): void {
		parent::setUp();

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user            = get_user_by( 'id', $this->artist_id );
		$user->add_role( 'agnosis_artist' );

		update_option( 'agnosis_quality_rejection_threshold', 3 );
		update_option( 'agnosis_quality_threshold', 7 );
		update_option( 'agnosis_email_photo', 'photo@agnosis.art' );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->clear_queue();
		delete_option( 'agnosis_email_photo' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function insert_queue_row( string $uid, string $to_address = '', string $subject = 'Test' ): int {
		global $wpdb;
		$submission = wp_json_encode( [
			'from'        => 'artist@example.com',
			'subject'     => $subject,
			'description' => 'My photograph.',
			'attachments' => [
				[
					'filename' => 'photo.jpg',
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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agnosis_queue WHERE message_uid LIKE 'test-photo-%'" );
	}

	private function get_queue_status( int $queue_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}agnosis_queue WHERE id = %d", $queue_id )
		);
	}

	/**
	 * Build a stub Pipeline that records whether skip_enhancement was passed,
	 * and returns a canned result with the given quality score.
	 */
	private function make_spy_pipeline( int $quality_score = 2 ): object {
		return new class( $quality_score ) extends Pipeline {
			public bool $skip_enhancement_received = false;

			public function __construct( private int $score ) {}

			public function process( array $submission, bool $skip_enhancement = false ): array {
				$this->skip_enhancement_received = $skip_enhancement;
				$results = [];
				foreach ( $submission['attachments'] ?? [] as $att ) {
					$results[] = [
						'filename'             => $att['filename'] ?? 'photo.jpg',
						'original_data'        => $att['data'] ?? '',
						'enhanced_data'        => $att['data'] ?? '',
						'mime_type'            => 'image/jpeg',
						'title'                => 'Test Photo',
						'excerpt'              => 'A test photo.',
						'body'                 => '<p>A test photo.</p>',
						'tags'                 => [ 'photo' ],
						'alt_text'             => 'A test photograph.',
						'description_ok'       => true,
						'error'                => null,
						'photo_quality_score'  => $this->score,
						'photo_quality_issues' => [ 'underexposed' ],
						'enhanced'             => false,
					];
				}
				return $results;
			}

			public function chat( string $prompt, int $min_tokens = 0 ): string {
				return ''; }
		};
	}

	// -------------------------------------------------------------------------
	// photo@ address routing
	// -------------------------------------------------------------------------

	public function test_photo_address_skips_quality_rejection(): void {
		$queue_id = $this->insert_queue_row( 'test-photo-addr', 'photo@agnosis.art' );
		$fired    = false;

		add_action( 'agnosis_submission_rejected', function () use ( &$fired ) {
			$fired = true;
		} );

		// Score 1 — would normally trigger rejection (threshold = 3).
		$creator = new PostCreator( $this->make_spy_pipeline( 1 ) );
		$creator->handle( $queue_id );

		$this->assertFalse( $fired, 'Quality rejection gate must not fire for photo@ submissions.' );
		$this->assertNotSame( 'failed', $this->get_queue_status( $queue_id ), 'Queue row must not be marked failed.' );
	}

	public function test_photo_address_passes_skip_enhancement_to_pipeline(): void {
		$queue_id = $this->insert_queue_row( 'test-photo-enh', 'photo@agnosis.art' );
		$pipeline = $this->make_spy_pipeline( 2 );

		$creator = new PostCreator( $pipeline );
		$creator->handle( $queue_id );

		$this->assertTrue( $pipeline->skip_enhancement_received, 'Pipeline must receive skip_enhancement = true for photo@ submissions.' );
	}

	// -------------------------------------------------------------------------
	// [Photo] subject indicator
	// -------------------------------------------------------------------------

	public function test_photo_indicator_skips_quality_rejection(): void {
		// No to_address — fallback to subject indicator.
		$queue_id = $this->insert_queue_row( 'test-photo-ind', '', '[Photo] Grain Study' );
		$fired    = false;

		add_action( 'agnosis_submission_rejected', function () use ( &$fired ) {
			$fired = true;
		} );

		$creator = new PostCreator( $this->make_spy_pipeline( 1 ) );
		$creator->handle( $queue_id );

		$this->assertFalse( $fired, 'Quality rejection gate must not fire for [Photo] indicator submissions.' );
	}

	public function test_photo_indicator_passes_skip_enhancement_to_pipeline(): void {
		$queue_id = $this->insert_queue_row( 'test-photo-ind-enh', '', '[Photo] Grain Study' );
		$pipeline = $this->make_spy_pipeline( 2 );

		$creator = new PostCreator( $pipeline );
		$creator->handle( $queue_id );

		$this->assertTrue( $pipeline->skip_enhancement_received );
	}

	// -------------------------------------------------------------------------
	// Standard artwork still goes through the quality gate
	// -------------------------------------------------------------------------

	public function test_standard_artwork_still_triggers_quality_rejection(): void {
		// No photo@ address, no [Photo] indicator — standard artwork path.
		$queue_id = $this->insert_queue_row( 'test-photo-std', '', 'A Regular Artwork' );
		$fired    = false;

		add_action( 'agnosis_submission_rejected', function () use ( &$fired ) {
			$fired = true;
		} );

		$creator = new PostCreator( $this->make_spy_pipeline( 1 ) );
		$creator->handle( $queue_id );

		$this->assertTrue( $fired, 'Quality rejection gate must fire for standard artwork with low score.' );
		$this->assertSame( 'failed', $this->get_queue_status( $queue_id ) );
	}

	public function test_standard_artwork_does_not_pass_skip_enhancement(): void {
		$queue_id = $this->insert_queue_row( 'test-photo-std-enh', '', 'Normal Artwork' );
		$pipeline = $this->make_spy_pipeline( 8 ); // High score — won't reject, won't enhance.

		$creator = new PostCreator( $pipeline );
		$creator->handle( $queue_id );

		$this->assertFalse( $pipeline->skip_enhancement_received, 'Standard artwork must not pass skip_enhancement = true.' );
	}

	// -------------------------------------------------------------------------
	// photo@ address not configured — must not match
	// -------------------------------------------------------------------------

	public function test_photo_routing_disabled_when_option_empty(): void {
		delete_option( 'agnosis_email_photo' );
		$queue_id = $this->insert_queue_row( 'test-photo-disabled', 'photo@agnosis.art' );
		$fired    = false;

		add_action( 'agnosis_submission_rejected', function () use ( &$fired ) {
			$fired = true;
		} );

		// Score 1 — with option empty, this address is not recognised as photo@,
		// falls through to standard artwork, so rejection fires.
		$creator = new PostCreator( $this->make_spy_pipeline( 1 ) );
		$creator->handle( $queue_id );

		$this->assertTrue( $fired, 'When photo option is empty, address must not be treated as photo-only.' );
	}
}
