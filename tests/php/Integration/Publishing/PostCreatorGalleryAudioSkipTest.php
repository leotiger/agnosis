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
 * Also covers two patch 18 fixes to the same method:
 *   - A merge target whose '_agnosis_gallery_ids' meta key was never set at
 *     all (get_post_meta() then returns '', not an array) must not crash —
 *     this is exactly what happened in production for every artist whose
 *     biography was auto-created by Artist\ApplicationBiography (which never
 *     wrote that meta key) and who then emailed a photo to bio@ for the
 *     first time.
 *   - agnosis_biography caps at exactly one image: a new photo replaces the
 *     old one instead of accumulating, a single email with several
 *     attachments keeps only the first, and a gallery that already
 *     accumulated more than one image before this fix self-heals on the
 *     next resubmission.
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
	 * @param string                       $post_type         CPT slug — only agnosis_biography caps at one image.
	 * @return int[]
	 */
	private function call_merge_gallery( int $existing_post_id, array $results, string $post_type = 'agnosis_artwork' ): array {
		$ref = new \ReflectionMethod( PostCreator::class, 'merge_gallery' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->creator, $existing_post_id, $results, $post_type );
	}

	/**
	 * A byte-valid 1x1 transparent GIF — real MIME sniffing
	 * (wp_check_filetype_and_ext() inside wp_handle_sideload()) requires
	 * actual file content, not an arbitrary placeholder string (same
	 * reasoning as tiny_wav() above).
	 */
	private static function tiny_gif(): string {
		return (string) base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7' );
	}

	/**
	 * @param string $filename Distinct filenames (or a trailing NUL byte on the
	 *                          data, same trick tiny_wav() callers use above) keep
	 *                          two fixtures from deduplicating by content hash.
	 * @return array<string, mixed> A pipeline result shaped like a real image
	 *                               attachment — description_ok, no enhancement.
	 */
	private function image_result( string $filename, string $data ): array {
		return [
			'filename'             => $filename,
			'original_data'        => $data,
			'enhanced_data'        => $data,
			'mime_type'            => 'image/gif',
			'media_type'           => 'image',
			'title'                => $filename,
			'excerpt'              => '',
			'body'                 => '',
			'tags'                 => [],
			'alt_text'             => '',
			'description_ok'       => true,
			'error'                => '',
			'photo_quality_score'  => 8,
			'photo_quality_issues' => [],
			'enhanced'             => false,
		];
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

	// -------------------------------------------------------------------------
	// Missing/malformed '_agnosis_gallery_ids' meta on the merge target (patch 18)
	// -------------------------------------------------------------------------

	/**
	 * Reproduces the exact production failure: a merge target whose
	 * '_agnosis_gallery_ids' meta key was never set at all (e.g.
	 * Artist\ApplicationBiography's auto-created biography draft, or any
	 * post built via a bare wp_insert_post()) — get_post_meta() returns ''
	 * for it, and merge_gallery() must not (array)-cast that blindly.
	 */
	public function test_merge_gallery_tolerates_missing_gallery_meta_on_existing_post(): void {
		$existing_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'draft',
			'post_title'  => 'About Someone',
		] );
		// Deliberately NOT seeding '_agnosis_gallery_ids' — this is the whole point.
		$this->assertSame( '', get_post_meta( $existing_id, '_agnosis_gallery_ids', true ), 'Precondition: meta key must be genuinely absent, not an empty array.' );

		$gallery = $this->call_merge_gallery( $existing_id, [ $this->image_result( 'portrait.gif', self::tiny_gif() ) ], 'agnosis_biography' );

		$this->assertCount( 1, $gallery, 'A missing gallery meta key must not crash, and the new image must still upload.' );
		$this->assertIsInt( $gallery[0] );
		$this->assertSame( 'image/gif', get_post_mime_type( $gallery[0] ) );
	}

	/** Same missing-meta scenario, but with no new image either — must return [], not [""]. */
	public function test_merge_gallery_tolerates_missing_gallery_meta_with_no_new_image(): void {
		$existing_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'draft',
			'post_title'  => 'About Someone Else',
		] );

		$gallery = $this->call_merge_gallery( $existing_id, [], 'agnosis_biography' );

		$this->assertSame( [], $gallery );
	}

	// -------------------------------------------------------------------------
	// Biography — one-image cap (patch 18)
	// -------------------------------------------------------------------------

	public function test_biography_new_image_replaces_existing_image(): void {
		$existing_id  = (int) wp_insert_post( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'draft',
			'post_title'  => 'About the Artist',
		] );
		$old_photo_id = self::factory()->attachment->create();
		update_post_meta( $existing_id, '_agnosis_gallery_ids', [ $old_photo_id ] );

		$gallery = $this->call_merge_gallery( $existing_id, [ $this->image_result( 'new-portrait.gif', self::tiny_gif() ) ], 'agnosis_biography' );

		$this->assertCount( 1, $gallery, 'A biography must never carry more than one image.' );
		$this->assertNotSame( $old_photo_id, $gallery[0], 'A new biography photo must replace the old one, not sit alongside it.' );
	}

	public function test_biography_keeps_existing_image_when_resubmission_has_no_new_photo(): void {
		$existing_id  = (int) wp_insert_post( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'draft',
			'post_title'  => 'About the Artist',
		] );
		$old_photo_id = self::factory()->attachment->create();
		update_post_meta( $existing_id, '_agnosis_gallery_ids', [ $old_photo_id ] );

		// A text-only bio update (no attachment) must not wipe the existing portrait.
		$gallery = $this->call_merge_gallery( $existing_id, [], 'agnosis_biography' );

		$this->assertSame( [ $old_photo_id ], $gallery );
	}

	public function test_biography_multiple_attachments_in_one_email_keep_only_the_first(): void {
		$gif_a = self::tiny_gif();
		$gif_b = self::tiny_gif() . "\x00"; // distinct content so it doesn't dedupe by hash against $gif_a.

		$gallery = $this->call_merge_gallery(
			0,
			[ $this->image_result( 'a.gif', $gif_a ), $this->image_result( 'b.gif', $gif_b ) ],
			'agnosis_biography'
		);

		$this->assertCount( 1, $gallery, 'A biography email with several attachments must still only keep one image.' );
	}

	public function test_biography_self_heals_a_gallery_that_already_accumulated_multiple_images(): void {
		// Simulates a biography that accumulated more than one image before this
		// fix existed — the very next resubmission (even text-only) must trim it
		// back down to one rather than perpetuating the pre-fix state forever.
		$existing_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'draft',
			'post_title'  => 'About the Artist',
		] );
		$first  = self::factory()->attachment->create();
		$second = self::factory()->attachment->create();
		update_post_meta( $existing_id, '_agnosis_gallery_ids', [ $first, $second ] );

		$gallery = $this->call_merge_gallery( $existing_id, [], 'agnosis_biography' );

		$this->assertCount( 1, $gallery );
		$this->assertSame( $first, $gallery[0], 'Trimming a legacy multi-image gallery keeps the first (oldest) image.' );
	}
}
