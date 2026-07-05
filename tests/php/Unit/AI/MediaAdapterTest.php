<?php
/**
 * Unit tests for MediaAdapter::adapt().
 *
 * MediaAdapter is a pure static class with no WP dependencies, so these are
 * plain PHPUnit tests. The PDF path requires Imagick — its test asserts
 * graceful degradation (empty return, PDF dropped entirely) since Imagick is
 * not available in CI. The video path requires ffmpeg only for the optional
 * poster-frame extraction; the original video attachment itself is never
 * dropped even when ffmpeg is unavailable — its test asserts that fallback
 * (video survives, poster_data is simply empty) rather than an empty return.
 * When ffmpeg IS available, a second test has it synthesise its own minimal
 * one-frame clip via the `lavfi` test source and asserts real extraction —
 * no checked-in binary fixture needed.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\MediaAdapter;
use PHPUnit\Framework\TestCase;

class MediaAdapterTest extends TestCase {

	// -------------------------------------------------------------------------
	// Empty input
	// -------------------------------------------------------------------------

	public function test_empty_attachments_returns_empty_array(): void {
		$this->assertSame( [], MediaAdapter::adapt( [] ) );
	}

	// -------------------------------------------------------------------------
	// image/* — passthrough
	// -------------------------------------------------------------------------

	public function test_jpeg_passthrough_sets_media_type_image(): void {
		$att = [ 'data' => 'abc', 'mime' => 'image/jpeg', 'filename' => 'photo.jpg' ];

		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'image', $result[0]['media_type'] );
		$this->assertSame( 'image/jpeg', $result[0]['mime'] );
		$this->assertSame( 'abc', $result[0]['data'] );
	}

	public function test_png_passthrough_preserves_all_keys(): void {
		$att = [ 'data' => 'raw', 'mime' => 'image/png', 'filename' => 'art.png', 'extra' => 'meta' ];

		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertSame( 'meta', $result[0]['extra'] );
		$this->assertSame( 'image', $result[0]['media_type'] );
	}

	public function test_multiple_images_all_get_media_type_image(): void {
		$atts = [
			[ 'data' => '1', 'mime' => 'image/jpeg', 'filename' => 'a.jpg' ],
			[ 'data' => '2', 'mime' => 'image/webp', 'filename' => 'b.webp' ],
		];

		$result = MediaAdapter::adapt( $atts );

		$this->assertCount( 2, $result );
		foreach ( $result as $r ) {
			$this->assertSame( 'image', $r['media_type'] );
		}
	}

	// -------------------------------------------------------------------------
	// audio/* — passthrough
	// -------------------------------------------------------------------------

	public function test_audio_mpeg_passthrough_sets_media_type_audio(): void {
		$att = [ 'data' => 'sound', 'mime' => 'audio/mpeg', 'filename' => 'track.mp3' ];

		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'audio', $result[0]['media_type'] );
		$this->assertSame( 'audio/mpeg', $result[0]['mime'] );
		$this->assertSame( 'track.mp3', $result[0]['filename'] );
	}

	public function test_audio_wav_passthrough(): void {
		$att = [ 'data' => 'wav', 'mime' => 'audio/wav', 'filename' => 'recording.wav' ];

		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertSame( 'audio', $result[0]['media_type'] );
	}

	public function test_audio_ogg_passthrough(): void {
		$att = [ 'data' => 'ogg', 'mime' => 'audio/ogg', 'filename' => 'piece.ogg' ];

		$this->assertSame( 'audio', MediaAdapter::adapt( [ $att ] )[0]['media_type'] );
	}

	// -------------------------------------------------------------------------
	// Unknown MIME — dropped
	// -------------------------------------------------------------------------

	public function test_unknown_mime_is_dropped(): void {
		$att = [ 'data' => 'data', 'mime' => 'application/zip', 'filename' => 'archive.zip' ];

		$this->assertSame( [], MediaAdapter::adapt( [ $att ] ) );
	}

	public function test_empty_mime_is_dropped(): void {
		$att = [ 'data' => 'data', 'mime' => '', 'filename' => 'file' ];

		$this->assertSame( [], MediaAdapter::adapt( [ $att ] ) );
	}

	public function test_text_mime_is_dropped(): void {
		$att = [ 'data' => 'text', 'mime' => 'text/plain', 'filename' => 'notes.txt' ];

		$this->assertSame( [], MediaAdapter::adapt( [ $att ] ) );
	}

	// -------------------------------------------------------------------------
	// PDF — graceful skip when Imagick unavailable
	// -------------------------------------------------------------------------

	public function test_pdf_returns_empty_when_imagick_unavailable(): void {
		if ( extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Imagick is available — this test guards the fallback path only.' );
		}

		$att    = [ 'data' => '%PDF-1.4 fake', 'mime' => 'application/pdf', 'filename' => 'portfolio.pdf' ];
		$result = MediaAdapter::adapt( [ $att ] );

		// Without Imagick, PDF entries are dropped entirely rather than crashing.
		$this->assertSame( [], $result );
	}

	// -------------------------------------------------------------------------
	// Video — original file is always published; poster frame is best-effort
	// -------------------------------------------------------------------------

	public function test_video_without_ffmpeg_still_publishes_original_with_no_poster(): void {
		$ffmpeg = trim( (string) shell_exec( 'which ffmpeg 2>/dev/null' ) );
		if ( ! empty( $ffmpeg ) ) {
			$this->markTestSkipped( 'ffmpeg is available — this test guards the no-ffmpeg fallback path only.' );
		}

		$att    = [ 'data' => 'video_binary', 'mime' => 'video/mp4', 'filename' => 'clip.mp4' ];
		$result = MediaAdapter::adapt( [ $att ] );

		// The original video is never dropped, even without ffmpeg — only the
		// poster-frame extraction is skipped. Pipeline falls back to a
		// text-only description when poster_data is empty.
		$this->assertCount( 1, $result );
		$this->assertSame( 'video', $result[0]['media_type'] );
		$this->assertSame( 'video_binary', $result[0]['data'] );
		$this->assertSame( 'video/mp4', $result[0]['mime'] );
		$this->assertSame( '', $result[0]['poster_data'] );
		$this->assertSame( '', $result[0]['poster_mime'] );
	}

	public function test_video_with_working_ffmpeg_extracts_poster_frame(): void {
		$ffmpeg = trim( (string) shell_exec( 'which ffmpeg 2>/dev/null' ) );
		if ( empty( $ffmpeg ) ) {
			$this->markTestSkipped( 'ffmpeg not available in this environment.' );
		}

		// A hand-built byte fixture (the way tiny_wav() works for audio in the
		// PostCreator integration test) isn't possible for a real video codec
		// bitstream — unlike WAV, there is no fixed, trivial header format.
		// Instead, ask this same ffmpeg binary to synthesise a minimal,
		// genuinely decodable one-frame clip from its own `lavfi` test source
		// (no external fixture file needed at all), then feed that back into
		// MediaAdapter and assert real extraction happened. Because the same
		// ffmpeg both encodes and decodes, this is self-consistent regardless
		// of which codec this particular ffmpeg build defaults to.
		$video_path = sys_get_temp_dir() . '/agnosis_test_video_' . uniqid( '', true ) . '.mp4';

		exec(
			sprintf(
				'%s -y -f lavfi -i color=c=red:size=32x32:rate=1 -frames:v 1 -pix_fmt yuv420p %s 2>/dev/null',
				escapeshellcmd( $ffmpeg ),
				escapeshellarg( $video_path )
			),
			$output,
			$return
		);

		if ( 0 !== $return || ! is_file( $video_path ) ) {
			$this->markTestSkipped( 'This ffmpeg build could not synthesise a test video (lavfi source unavailable?).' );
		}

		$video_data = (string) file_get_contents( $video_path );
		unlink( $video_path );

		$att    = [ 'data' => $video_data, 'mime' => 'video/mp4', 'filename' => 'clip.mp4' ];
		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'video', $result[0]['media_type'] );
		$this->assertNotSame( '', $result[0]['poster_data'], 'A real, decodable video should yield an extracted poster frame.' );
		$this->assertSame( 'image/jpeg', $result[0]['poster_mime'] );
		// JPEG files start with the SOI marker 0xFFD8.
		$this->assertSame( "\xFF\xD8", substr( $result[0]['poster_data'], 0, 2 ) );
	}

	// -------------------------------------------------------------------------
	// Mixed batch
	// -------------------------------------------------------------------------

	public function test_mixed_batch_routes_correctly(): void {
		$atts = [
			[ 'data' => 'img', 'mime' => 'image/jpeg', 'filename' => 'photo.jpg' ],
			[ 'data' => 'audio', 'mime' => 'audio/mpeg', 'filename' => 'track.mp3' ],
			[ 'data' => 'zip', 'mime' => 'application/zip', 'filename' => 'archive.zip' ],
		];

		$result = MediaAdapter::adapt( $atts );

		// zip is dropped; image and audio survive
		$this->assertCount( 2, $result );
		$this->assertSame( 'image', $result[0]['media_type'] );
		$this->assertSame( 'audio', $result[1]['media_type'] );
	}
}
