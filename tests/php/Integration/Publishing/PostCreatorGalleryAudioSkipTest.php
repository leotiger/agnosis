<?php
/**
 * Integration tests for PostCreator::merge_gallery() audio/video upload behaviour.
 *
 * File name predates a behaviour change and no longer matches what these tests
 * cover — kept as-is because the file's identity can't be renamed from this
 * session, only its contents. Originally this asserted that audio results were
 * SKIPPED entirely (no Media Library upload). That was dead-code-adjacent: the
 * intake Parser dropped every audio/video attachment before any of this code
 * could run, so "audio submissions" never actually reached merge_gallery() in
 * production. Once Parser::ALLOWED_MIME was widened to accept audio and video,
 * skipping the upload here would have meant an artist's sound or video work
 * was silently discarded and never published at all — the opposite of what
 * "supporting" those submission types should mean. merge_gallery() now uploads
 * the real audio/video binary via upload_media()/upload_video() same as it
 * always has for images.
 *
 * The method is private and is exercised via ReflectionMethod. A minimal
 * Pipeline stub is injected so no AI calls are made.
 *
 * Uses a hand-built, byte-valid, empty WAV file as the audio fixture (not an
 * arbitrary placeholder string) because upload_media() now runs through the
 * real wp_handle_sideload()/wp_check_filetype_and_ext() path, which inspects
 * actual file content — a fake string like "audio binary" would fail real
 * MIME sniffing and produce a false negative. There is no equivalent
 * lightweight fixture for video (a minimal valid MP4/MOV container is not a
 * few bytes of fixed header the way WAV is), so video's real upload path is
 * better verified by an end-to-end test (send an actual video by email) than
 * a synthetic fixture here.
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
	// Helpers
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

	/**
	 * A byte-valid, empty (zero-sample) WAV file — 44-byte canonical header,
	 * PCM, mono, 8kHz, 8-bit, no audio data. Passes real MIME sniffing as
	 * audio/wav via both file extension and content inspection.
	 */
	private static function tiny_wav(): string {
		$data_size = 0;
		$riff_size = 36 + $data_size;
		return pack( 'A4Va4', 'RIFF', $riff_size, 'WAVE' )
			. pack( 'a4VvvVVvv', 'fmt ', 16, 1, 1, 8000, 8000, 1, 8 )
			. pack( 'a4V', 'data', $data_size );
	}

	// -------------------------------------------------------------------------
	// Audio results
	// -------------------------------------------------------------------------

	public function test_audio_result_is_uploaded_to_the_media_library(): void {
		$wav = self::tiny_wav();

		$result = [
			'filename'             => 'track.wav',
			'original_data'        => $wav,
			'enhanced_data'        => $wav, // audio mirrors original_data — see Pipeline::process_audio_single().
			'mime_type'            => 'audio/wav',
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

		$this->assertCount( 1, $gallery, 'Audio result should produce exactly one Media Library attachment.' );
		$this->assertSame( 'audio/wav', get_post_mime_type( $gallery[0] ) );
		$this->assertSame( md5( $wav ), get_post_meta( $gallery[0], '_agnosis_image_hash', true ) );
	}

	public function test_multiple_audio_results_each_upload(): void {
		$wav = self::tiny_wav();

		$audio = static fn( string $f ) => [
			'filename'             => $f,
			'original_data'        => $wav,
			'enhanced_data'        => $wav,
			'mime_type'            => 'audio/wav',
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

		// Two DIFFERENT files (append a distinguishing byte to the data chunk so
		// their hashes differ) — otherwise the second would be deduplicated by
		// hash against the first, same as it would for two identical images.
		$a = $audio( 'a.wav' );
		$b = $audio( 'b.wav' );
		$b['original_data'] = $wav . "\x00";
		$b['enhanced_data'] = $wav . "\x00";

		$gallery = $this->call_merge_gallery( 0, [ $a, $b ] );

		$this->assertCount( 2, $gallery, 'Two distinct audio results should produce two attachments.' );
	}

	public function test_failed_audio_result_with_no_binary_produces_no_upload(): void {
		// audio_failure_result() (Pipeline) sets original_data to '' when there
		// was no transcript or context to work from at all — nothing to upload.
		$result = [
			'filename'             => 'silence.wav',
			'original_data'        => '',
			'enhanced_data'        => '',
			'mime_type'            => 'audio/wav',
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
	// Mixed results — audio and image entries both processed
	// -------------------------------------------------------------------------

	public function test_audio_entry_uploaded_alongside_a_failed_image_entry(): void {
		// An image result with empty enhanced_data will fail upload_media() and
		// return a WP_Error — it should simply be excluded from the gallery,
		// without affecting the audio entry's own upload.
		$wav = self::tiny_wav();

		$audio_result = [
			'filename'             => 'track.wav',
			'original_data'        => $wav,
			'enhanced_data'        => $wav,
			'mime_type'            => 'audio/wav',
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

		$broken_image_result = [
			'filename'             => 'broken.jpg',
			'original_data'        => 'not empty so it is attempted',
			'enhanced_data'        => '', // empty binary — upload_media() will fail on this.
			'mime_type'            => 'image/jpeg',
			'media_type'           => 'image',
			'title'                => 'Broken',
			'excerpt'              => '',
			'body'                 => '',
			'tags'                 => [],
			'alt_text'             => '',
			'description_ok'       => false,
			'error'                => 'x',
			'photo_quality_score'  => 0,
			'photo_quality_issues' => [],
			'enhanced'             => false,
		];

		$gallery = $this->call_merge_gallery( 0, [ $audio_result, $broken_image_result ] );

		$this->assertCount( 1, $gallery, 'Only the audio entry should have uploaded successfully.' );
		$this->assertSame( 'audio/wav', get_post_mime_type( $gallery[0] ) );
	}
}
