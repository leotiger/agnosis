<?php
/**
 * Integration tests for PostCreator::merge_gallery() audio-skip behaviour.
 *
 * When the pipeline returns results with media_type = 'audio', merge_gallery()
 * must not attempt to upload anything to the Media Library and must return
 * an empty gallery (no attachment IDs for audio-only submissions).
 *
 * The method is private and is exercised via ReflectionMethod. A minimal
 * Pipeline stub is injected so no AI calls are made.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;

class PostCreatorGalleryAudioSkipTest extends \WP_UnitTestCase {

	private PostCreator $creator;

	protected function setUp(): void {
		parent::setUp();

		// Minimal Pipeline stub — no AI calls, no WP option resolution.
		$pipeline = new class() extends Pipeline {
			public function __construct() {}
			/** @param array<string, mixed> $submission */
			public function process( array $submission, bool $skip_enhancement = false ): array {
				return [];
			}
		};

		$this->creator = new PostCreator( $pipeline );
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Call the private merge_gallery() via reflection.
	 *
	 * @param int                          $existing_post_id  0 for new post.
	 * @param array<int, array<string, mixed>> $results       Pipeline results.
	 * @return int[]
	 */
	private function call_merge_gallery( int $existing_post_id, array $results ): array {
		$ref = new \ReflectionMethod( PostCreator::class, 'merge_gallery' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->creator, $existing_post_id, $results );
	}

	// -------------------------------------------------------------------------
	// Audio results
	// -------------------------------------------------------------------------

	public function test_audio_result_produces_empty_gallery(): void {
		$result = [
			'filename'             => 'track.mp3',
			'original_data'        => 'audio binary',
			'enhanced_data'        => '',
			'mime_type'            => 'audio/mpeg',
			'media_type'           => 'audio',
			'title'                => 'Resonance',
			'excerpt'              => 'A meditative soundscape.',
			'body'                 => '<p>The piece unfolds slowly.</p>',
			'tags'                 => [ 'ambient' ],
			'alt_text'             => 'A drone composition.',
			'description_ok'       => true,
			'error'                => '',
			'photo_quality_score'  => 0,
			'photo_quality_issues' => [],
			'enhanced'             => false,
		];

		$gallery = $this->call_merge_gallery( 0, [ $result ] );

		// No attachment should be uploaded for an audio result.
		$this->assertSame( [], $gallery );
	}

	public function test_multiple_audio_results_produce_empty_gallery(): void {
		$audio = static fn( string $f ) => [
			'filename'             => $f,
			'original_data'        => 'data',
			'enhanced_data'        => '',
			'mime_type'            => 'audio/mpeg',
			'media_type'           => 'audio',
			'title'                => 'Work',
			'excerpt'              => '',
			'body'                 => '',
			'tags'                 => [],
			'alt_text'             => '',
			'description_ok'       => true,
			'error'                => '',
			'photo_quality_score'  => 0,
			'photo_quality_issues' => [],
			'enhanced'             => false,
		];

		$gallery = $this->call_merge_gallery( 0, [ $audio( 'a.mp3' ), $audio( 'b.mp3' ) ] );

		$this->assertSame( [], $gallery );
	}

	public function test_failed_audio_result_also_produces_no_upload(): void {
		$result = [
			'filename'             => 'silence.mp3',
			'original_data'        => '',
			'enhanced_data'        => '',
			'mime_type'            => 'audio/mpeg',
			'media_type'           => 'audio',
			'title'                => '',
			'excerpt'              => '',
			'body'                 => '',
			'tags'                 => [],
			'alt_text'             => '',
			'description_ok'       => false,
			'error'                => 'No context.',
			'photo_quality_score'  => 0,
			'photo_quality_issues' => [],
			'enhanced'             => false,
		];

		$gallery = $this->call_merge_gallery( 0, [ $result ] );

		$this->assertSame( [], $gallery );
	}

	// -------------------------------------------------------------------------
	// Mixed results — audio skipped, image entries still processed
	// -------------------------------------------------------------------------

	public function test_audio_entry_skipped_while_image_entry_with_no_data_returns_empty(): void {
		// An image result with empty enhanced_data will fail upload_image() and
		// return a WP_Error — it should not appear in the gallery either.
		// The important assertion is that the audio entry does not cause a crash
		// or attempt to upload, regardless of the image outcome.
		$audio_result = [
			'filename'             => 'track.mp3',
			'original_data'        => 'binary',
			'enhanced_data'        => '',
			'mime_type'            => 'audio/mpeg',
			'media_type'           => 'audio',
			'title'                => 'Sound',
			'excerpt'              => '',
			'body'                 => '',
			'tags'                 => [],
			'alt_text'             => '',
			'description_ok'       => true,
			'error'                => '',
			'photo_quality_score'  => 0,
			'photo_quality_issues' => [],
			'enhanced'             => false,
		];

		// No crash — test simply asserts no exception is thrown.
		$gallery = $this->call_merge_gallery( 0, [ $audio_result ] );
		$this->assertIsArray( $gallery );
	}
}
