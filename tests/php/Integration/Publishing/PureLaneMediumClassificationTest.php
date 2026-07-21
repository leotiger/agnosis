<?php
/**
 * Integration tests for the pure@ medium-classification wiring added
 * 2026-07-21 (see PureLaneTest's own docblock for the wider context).
 *
 * process_raw() itself still makes zero AI calls (unchanged), but
 * PostCreator::handle() now makes exactly one classification-only call
 * afterward for a pure@ submission:
 *   - Pipeline::classify_medium_from_image() when the artist attached a REAL
 *     photo (not the TextPosterGenerator synthetic-poster fallback).
 *   - Pipeline::classify_medium_from_text() otherwise — no attachment at
 *     all, or a real attachment that isn't an image (a PDF/audio/video file
 *     sent to a pure@ address).
 *
 * Before this fix, pure@'s result array never even had a 'medium' key, so
 * medium-term auto-assignment silently never worked for this lane at all —
 * these tests exercise the real end-to-end PostCreator::handle() →
 * write_post_meta() chain, not just Pipeline in isolation, since that's
 * exactly the kind of disconnect PostCreatorMediumTermTest's own docblock
 * warns never gets caught by unit-level tests alone.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Artist\Profile;
use Agnosis\Publishing\PostCreator;
use Agnosis\Tests\Integration\Publishing\Stubs\ImagickAvailabilityOverride;

// The text-only (no-attachment) tests below need TextPosterGenerator::generate()
// to actually succeed — a namespace-scoped extension_loaded() override, forced
// "available" here so this test doesn't depend on whether the machine running
// the suite genuinely has the Imagick PHP extension.
//
// 2026-07-21: this used to require Unit/Publishing/Stubs/
// publishing_namespace_stubs.php directly — that file also declares a
// namespace-scoped get_option() (needed by the Unit suite's
// ResolvePostTypeTest) which, once pulled into an Integration run, silently
// shadowed the real get_option() for every Agnosis\Publishing call site
// (PostCreator, EmbedPolicy, …) for the rest of the process. Use the
// dedicated Integration-only stub instead (its own static flag, no
// dependency on the Unit test tree — see its docblock for the full
// incident writeup, including why reusing TextPosterGeneratorTest's static
// property wasn't safe either).
require_once __DIR__ . '/Stubs/extension_loaded_namespace_stub.php';

class PureLaneMediumClassificationTest extends \WP_UnitTestCase {

	private int $artist_id;

	protected function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			( new Profile() )->register_taxonomy();
		}

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user            = get_user_by( 'id', $this->artist_id );
		$user->add_role( 'agnosis_artist' );

		update_option( 'agnosis_quality_rejection_threshold', 3 );
		update_option( 'agnosis_quality_threshold', 7 );
		update_option( 'agnosis_email_pure', 'pure@agnosis.art' );

		ImagickAvailabilityOverride::$value = true;
	}

	protected function tearDown(): void {
		ImagickAvailabilityOverride::$value = null;

		if ( taxonomy_exists( 'agnosis_medium' ) ) {
			foreach ( get_terms( [ 'taxonomy' => 'agnosis_medium', 'hide_empty' => false ] ) as $term ) {
				wp_delete_term( $term->term_id, 'agnosis_medium' );
			}
		}
		delete_option( 'agnosis_email_pure' );
		$this->clear_queue();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @param array<int, array<string, mixed>> $attachments */
	private function insert_queue_row( string $uid, string $subject, string $description, array $attachments ): int {
		global $wpdb;
		$submission = wp_json_encode( [
			'from'        => 'artist@example.com',
			'subject'     => $subject,
			'description' => $description,
			'attachments' => $attachments,
			'artist_id'   => $this->artist_id,
			'to_address'  => 'pure@agnosis.art',
			'source'      => 'test',
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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agnosis_queue WHERE message_uid LIKE 'test-pure-medium-%'" );
	}

	private function get_published_post_id( int $queue_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}agnosis_queue WHERE id = %d", $queue_id )
		);
	}

	/**
	 * A Pipeline stub whose process_raw() delegates to the real
	 * implementation (so the real, no-AI result shape is exercised) but
	 * whose classify_medium_from_image()/classify_medium_from_text() are
	 * instrumented spies returning a caller-supplied fixed value, rather than
	 * making a real AI call.
	 */
	private function make_pipeline_with_medium( string $medium_to_return ): object {
		return new class( $medium_to_return ) extends Pipeline {
			public string $medium_to_return;
			public bool $image_classify_called = false;
			public bool $text_classify_called  = false;
			/** @var array<string, mixed>|null */
			public ?array $image_classify_args = null;
			public ?string $text_classify_arg = null;

			public function __construct( string $medium_to_return ) {
				$this->medium_to_return = $medium_to_return;
			}

			public function process( array $submission, bool $skip_enhancement = false ): array {
				return [];
			}

			// process_raw() is intentionally NOT overridden — the real
			// implementation (zero AI calls, per its own docblock) is exactly
			// what these tests need to exercise.

			public function classify_medium_from_image( array $submission, string $image_data, string $mime_type ): string {
				$this->image_classify_called = true;
				$this->image_classify_args   = [ 'image_data' => $image_data, 'mime_type' => $mime_type ];
				return $this->medium_to_return;
			}

			public function classify_medium_from_text( string $text ): string {
				$this->text_classify_called = true;
				$this->text_classify_arg    = $text;
				return $this->medium_to_return;
			}
		};
	}

	// -------------------------------------------------------------------------
	// Real photo attached → image-based classification
	// -------------------------------------------------------------------------

	public function test_real_photo_attachment_uses_image_classification(): void {
		wp_insert_term( 'Photography', 'agnosis_medium' );

		$queue_id = $this->insert_queue_row(
			'test-pure-medium-photo',
			'A Real Photo',
			'My own words about my own photograph.',
			[
				[
					'filename' => 'photo.jpg',
					'mime'     => 'image/jpeg',
					'data'     => base64_encode( 'fake-image-data' ),
					'encoding' => 'base64',
				],
			]
		);

		$pipeline = $this->make_pipeline_with_medium( 'Photography' );
		( new PostCreator( $pipeline ) )->handle( $queue_id );

		$this->assertTrue( $pipeline->image_classify_called, 'A real attached photo must route through classify_medium_from_image().' );
		$this->assertFalse( $pipeline->text_classify_called, 'classify_medium_from_text() must not run when a real photo was classified instead.' );

		$post_id = $this->get_published_post_id( $queue_id );
		$this->assertSame(
			[ 'Photography' ],
			wp_get_post_terms( $post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] )
		);
	}

	// -------------------------------------------------------------------------
	// No real attachment (synthetic text-poster case) → text-based classification
	// -------------------------------------------------------------------------

	public function test_text_only_submission_uses_text_classification(): void {
		wp_insert_term( 'Poetry', 'agnosis_medium' );

		$queue_id = $this->insert_queue_row(
			'test-pure-medium-text',
			'A Poem',
			"Roses are red\nViolets are blue",
			[] // No attachment — TextPosterGenerator injects a synthetic poster.
		);

		$pipeline = $this->make_pipeline_with_medium( 'Poetry' );
		( new PostCreator( $pipeline ) )->handle( $queue_id );

		$this->assertTrue( $pipeline->text_classify_called, 'A text-only submission (no real photo) must route through classify_medium_from_text().' );
		$this->assertFalse( $pipeline->image_classify_called, 'classify_medium_from_image() must not run when there is no real photo to classify.' );
		$this->assertStringContainsString( 'Roses are red', (string) $pipeline->text_classify_arg, "The artist's own submitted text must be what's sent for text-based classification." );

		$post_id = $this->get_published_post_id( $queue_id );
		$this->assertSame(
			[ 'Poetry' ],
			wp_get_post_terms( $post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] )
		);
	}

	// -------------------------------------------------------------------------
	// Guard behavior is unchanged when classification returns nothing usable
	// -------------------------------------------------------------------------

	public function test_empty_classification_result_assigns_no_medium_term(): void {
		$queue_id = $this->insert_queue_row(
			'test-pure-medium-empty',
			'Ambiguous Piece',
			'Some text that could not be classified.',
			[]
		);

		$pipeline = $this->make_pipeline_with_medium( '' );
		( new PostCreator( $pipeline ) )->handle( $queue_id );

		$post_id = $this->get_published_post_id( $queue_id );
		$this->assertSame(
			[],
			wp_get_post_terms( $post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ),
			'An empty/unclassifiable medium must not assign any term — same hallucination guard every other lane already relies on.'
		);
	}
}
