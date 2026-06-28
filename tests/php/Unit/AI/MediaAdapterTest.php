<?php
/**
 * Unit tests for MediaAdapter::adapt().
 *
 * MediaAdapter is a pure static class with no WP dependencies, so these are
 * plain PHPUnit tests. PDF/video paths require Imagick/ffmpeg respectively —
 * tests for those branches assert graceful degradation (empty return) rather
 * than actual conversion, since those tools are not available in CI.
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
	// Video — graceful skip when ffmpeg unavailable
	// -------------------------------------------------------------------------

	public function test_video_returns_empty_when_ffmpeg_unavailable(): void {
		$ffmpeg = trim( (string) shell_exec( 'which ffmpeg 2>/dev/null' ) );
		if ( ! empty( $ffmpeg ) ) {
			$this->markTestSkipped( 'ffmpeg is available — this test guards the fallback path only.' );
		}

		$att    = [ 'data' => 'video_binary', 'mime' => 'video/mp4', 'filename' => 'clip.mp4' ];
		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertSame( [], $result );
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
