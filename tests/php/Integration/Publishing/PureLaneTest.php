<?php
/**
 * Integration tests for the pure@ (zero-AI) intake lane.
 *
 * Covers:
 *   - pure@ address routing resolves to agnosis_artwork with photo_only = true
 *     AND pure = true.
 *   - [Pure] subject indicator resolves the same way.
 *   - Pipeline::process_raw() is called instead of Pipeline::process() — no
 *     describe()/enhance()/translate() call ever happens.
 *   - Quality rejection gate is skipped, same as photo@.
 *   - The published post's title/body/excerpt/alt text/translated-title are
 *     taken verbatim from the artist's own subject/description — never an
 *     AI-authored rewrite.
 *   - _agnosis_intake_endpoint is recorded as 'pure' once, and never changes
 *     on a later update.
 *   - replace@ reuses the 'pure' strategy for a resend to an artwork
 *     originally created via pure@, even though replace@ itself never sets
 *     agnosis_email_pure.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;

class PureLaneTest extends \WP_UnitTestCase {

	private int $artist_id;

	protected function setUp(): void {
		parent::setUp();

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user            = get_user_by( 'id', $this->artist_id );
		$user->add_role( 'agnosis_artist' );

		update_option( 'agnosis_quality_rejection_threshold', 3 );
		update_option( 'agnosis_quality_threshold', 7 );
		update_option( 'agnosis_email_pure', 'pure@agnosis.art' );
		update_option( 'agnosis_email_replace', 'replace@agnosis.art' );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->clear_queue();
		delete_option( 'agnosis_email_pure' );
		delete_option( 'agnosis_email_replace' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function insert_queue_row( string $uid, string $to_address = '', string $subject = 'Test', string $description = 'My own words, exactly as I wrote them.' ): int {
		global $wpdb;
		$submission = wp_json_encode( [
			'from'        => 'artist@example.com',
			'subject'     => $subject,
			'description' => $description,
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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agnosis_queue WHERE message_uid LIKE 'test-pure-%'" );
	}

	private function get_queue_status( int $queue_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}agnosis_queue WHERE id = %d", $queue_id )
		);
	}

	private function get_published_post_id( int $queue_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}agnosis_queue WHERE id = %d", $queue_id )
		);
	}

	/**
	 * A Pipeline stub whose process() would prove the AI path ran (it must
	 * NOT be called for pure@) and whose process_raw() delegates to the real
	 * implementation so the actual zero-AI result shape is exercised.
	 */
	private function make_spy_pipeline(): object {
		return new class() extends Pipeline {
			public bool $process_called     = false;
			public bool $process_raw_called = false;

			public function __construct() {}

			public function process( array $submission, bool $skip_enhancement = false ): array {
				$this->process_called = true;
				return []; // Would never publish anything usable — proves the wrong path ran.
			}

			public function process_raw( array $submission ): array {
				$this->process_raw_called = true;
				return parent::process_raw( $submission );
			}

			public function chat( string $prompt ): string {
				return '';
			}
		};
	}

	// -------------------------------------------------------------------------
	// pure@ address routing
	// -------------------------------------------------------------------------

	public function test_pure_address_calls_process_raw_not_process(): void {
		$queue_id = $this->insert_queue_row( 'test-pure-addr', 'pure@agnosis.art' );
		$pipeline = $this->make_spy_pipeline();

		$creator = new PostCreator( $pipeline );
		$creator->handle( $queue_id );

		$this->assertTrue( $pipeline->process_raw_called, 'pure@ must route through Pipeline::process_raw().' );
		$this->assertFalse( $pipeline->process_called, 'pure@ must never call Pipeline::process() — no AI at all.' );
	}

	public function test_pure_address_skips_quality_rejection(): void {
		$queue_id = $this->insert_queue_row( 'test-pure-quality', 'pure@agnosis.art' );
		$fired    = false;

		add_action( 'agnosis_submission_rejected', function () use ( &$fired ) {
			$fired = true;
		} );

		$creator = new PostCreator( $this->make_spy_pipeline() );
		$creator->handle( $queue_id );

		$this->assertFalse( $fired, 'Quality rejection gate must not fire for pure@ submissions.' );
		$this->assertNotSame( 'failed', $this->get_queue_status( $queue_id ) );
	}

	public function test_pure_address_publishes_artist_text_verbatim(): void {
		$queue_id = $this->insert_queue_row(
			'test-pure-verbatim',
			'pure@agnosis.art',
			'My Own Title',
			'These are my own words about my own work.'
		);

		$creator = new PostCreator( $this->make_spy_pipeline() );
		$creator->handle( $queue_id );

		$post_id = $this->get_published_post_id( $queue_id );
		$post    = get_post( $post_id );

		$this->assertSame( 'My Own Title', $post->post_title );
		$this->assertStringContainsString( 'These are my own words about my own work.', $post->post_content );
		$this->assertSame( 'My Own Title', get_post_meta( $post_id, '_agnosis_translated_title', true ), 'The "translated" title must equal the artist\'s own subject — never an AI rewrite — for pure@.' );
	}

	/**
	 * A real-world concern (2026-07-21): mail clients on Windows/Microsoft
	 * platforms (Outlook, Exchange) commonly send plain-text bodies with
	 * CRLF (\r\n) line endings rather than the bare \n a Unix-originated
	 * email typically has. This confirms the full intake-to-post-content
	 * chain — Parser's sanitize_textarea_field() (which deliberately
	 * preserves \r\n untouched, keep_newlines=true) → Pipeline::process_raw()'s
	 * wpautop() (which normalizes \r\n/\r to \n internally before inserting
	 * <br /> — verified directly against WordPress core's own
	 * wp-includes/formatting.php source) → PostCreator::paragraphs_to_blocks() —
	 * handles CRLF-separated lines exactly the same as bare-\n ones, end to
	 * end through the real pipeline, not just in isolation.
	 */
	public function test_pure_address_preserves_crlf_line_breaks_in_body(): void {
		$queue_id = $this->insert_queue_row(
			'test-pure-crlf',
			'pure@agnosis.art',
			'A Poem',
			"Roses are red\r\nViolets are blue\r\nThis poem arrived\r\nFrom a Windows PC too"
		);

		$creator = new PostCreator( $this->make_spy_pipeline() );
		$creator->handle( $queue_id );

		$post_id = $this->get_published_post_id( $queue_id );
		$content = get_post( $post_id )->post_content;

		$this->assertSame( 3, substr_count( $content, '<br' ), 'All three CRLF-separated line breaks must survive as <br /> tags, same as bare-\n input.' );
	}

	public function test_pure_address_records_intake_endpoint_meta(): void {
		$queue_id = $this->insert_queue_row( 'test-pure-meta', 'pure@agnosis.art' );

		$creator = new PostCreator( $this->make_spy_pipeline() );
		$creator->handle( $queue_id );

		$post_id = $this->get_published_post_id( $queue_id );

		$this->assertSame( 'pure', get_post_meta( $post_id, '_agnosis_intake_endpoint', true ) );
	}

	// -------------------------------------------------------------------------
	// [Pure] subject indicator
	// -------------------------------------------------------------------------

	public function test_pure_indicator_calls_process_raw(): void {
		delete_option( 'agnosis_email_pure' ); // Force fallback to the subject indicator.
		$queue_id = $this->insert_queue_row( 'test-pure-ind', '', '[Pure] Grain Study' );
		$pipeline = $this->make_spy_pipeline();

		$creator = new PostCreator( $pipeline );
		$creator->handle( $queue_id );

		$this->assertTrue( $pipeline->process_raw_called );
		$this->assertFalse( $pipeline->process_called );
	}

	// -------------------------------------------------------------------------
	// replace@ reuses the original intake strategy
	// -------------------------------------------------------------------------

	public function test_replace_reuses_pure_strategy_from_original_submission(): void {
		// First submission: via pure@.
		$queue_id_1 = $this->insert_queue_row( 'test-pure-replace-1', 'pure@agnosis.art', 'Reworked Piece' );
		$creator    = new PostCreator( $this->make_spy_pipeline() );
		$creator->handle( $queue_id_1 );

		$post_id = $this->get_published_post_id( $queue_id_1 );
		$this->assertSame( 'pure', get_post_meta( $post_id, '_agnosis_intake_endpoint', true ) );

		// Second submission: a resend via replace@, same subject (exact title match).
		// replace@ never sets agnosis_email_pure — the pure strategy must come from
		// the matched post's own _agnosis_intake_endpoint meta, read-only.
		$queue_id_2 = $this->insert_queue_row( 'test-pure-replace-2', 'replace@agnosis.art', 'Reworked Piece' );
		$pipeline_2 = $this->make_spy_pipeline();
		$creator_2  = new PostCreator( $pipeline_2 );
		$creator_2->handle( $queue_id_2 );

		$this->assertTrue( $pipeline_2->process_raw_called, 'replace@ must reuse process_raw() when the original submission was pure@.' );
		$this->assertFalse( $pipeline_2->process_called );

		// The endpoint meta itself must be untouched by replace@ (read-only).
		$this->assertSame( 'pure', get_post_meta( $post_id, '_agnosis_intake_endpoint', true ) );
	}
}
