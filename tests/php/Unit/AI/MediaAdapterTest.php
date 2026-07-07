<?php
/**
 * Unit tests for MediaAdapter::adapt().
 *
 * MediaAdapter is a pure static class with no WP dependencies, so these are
 * plain PHPUnit tests. The video path requires ffmpeg only for the optional
 * poster-frame extraction; the original video attachment itself is never
 * dropped even when ffmpeg is unavailable — its test asserts that fallback
 * (video survives, poster_data is simply empty) rather than an empty return.
 *
 * All three branches of MediaAdapter::adapt_video()'s ffmpeg handling — not
 * installed; installed and extraction succeeds; installed but extraction
 * fails — are forced deterministically via $ffmpeg_path_override and
 * $exec_override (read by namespace-scoped shell_exec()/exec() overrides in
 * Stubs/ai_namespace_stubs.php) rather than by asking the real machine.
 *
 * Both branches of MediaAdapter's Imagick usage (PDF rasterisation, vision-
 * input downscaling) — Imagick unavailable; Imagick available — are forced
 * the same way via $imagick_available_override (read by a namespace-scoped
 * extension_loaded() override in the same Stubs file). "Available" resolves
 * to the real \Imagick class when the extension is genuinely installed, or a
 * conditional fake defined in dev/bootstrap.php when it isn't.
 *
 * 2026-07-06: no test in this file skips for any reason. This used to be only
 * half-true in two different ways — the ffmpeg "extraction succeeds" test
 * asked a genuinely-installed ffmpeg to synthesise its own test clip via
 * `lavfi`, and every Imagick-dependent test could only run whichever one of
 * its two branches matched whatever happened to be installed on the machine
 * running the suite, self-skipping the other. Both are now fully
 * environment-independent: every branch of both dependencies runs
 * identically and deterministically on every machine, installed or not.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\MediaAdapter;
use PHPUnit\Framework\TestCase;

class MediaAdapterTest extends TestCase {

	/**
	 * Forces the namespace-scoped shell_exec() override (see
	 * Stubs/ai_namespace_stubs.php) to return this value for MediaAdapter's
	 * "which ffmpeg" detection probe, instead of asking the real machine.
	 * null = pass through to the real shell_exec() (genuinely ask the OS).
	 * '' simulates "ffmpeg not installed" deterministically on any machine.
	 */
	public static ?string $ffmpeg_path_override = null;

	/**
	 * Forces the namespace-scoped exec() override (see Stubs/ai_namespace_stubs.php)
	 * to fake the outcome of MediaAdapter's ffmpeg frame-extraction command —
	 * 'success' (writes a minimal valid JPEG to the command's own output path)
	 * or 'failure' (leaves it absent) — instead of running a real ffmpeg binary.
	 * null = pass through to the real exec().
	 */
	public static ?string $exec_override = null;

	/**
	 * Forces the namespace-scoped extension_loaded() override (see
	 * Stubs/ai_namespace_stubs.php) to fake whether Imagick is "available" for
	 * MediaAdapter's own extension_loaded('imagick') check, instead of asking
	 * the real machine. true/false force either branch regardless of what's
	 * actually installed; null passes through to the real check. See
	 * dev/bootstrap.php for the conditional fake Imagick class this pairs with.
	 */
	public static ?bool $imagick_available_override = null;

	protected function tearDown(): void {
		// Always reset — a failed assertion must not leave this leaking into
		// unrelated tests run later in the same process.
		self::$ffmpeg_path_override         = null;
		self::$exec_override                = null;
		self::$imagick_available_override   = null;
		// maybe_downscale_for_vision()'s get_option() call resolves to the same
		// namespace-scoped stub SubmissionTranslatorTest uses (both classes
		// live under Agnosis\AI, and Stubs/ai_namespace_stubs.php intercepts
		// the whole namespace) — reset it here too so a test that sets a
		// custom agnosis_ai_vision_max_width_px doesn't leak into
		// SubmissionTranslatorTest or any other test sharing this stub.
		SubmissionTranslatorTest::$options = null;
		parent::tearDown();
	}

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
		self::$imagick_available_override = false;

		$att    = [ 'data' => '%PDF-1.4 fake', 'mime' => 'application/pdf', 'filename' => 'portfolio.pdf' ];
		$result = MediaAdapter::adapt( [ $att ] );

		// Without Imagick, PDF entries are dropped entirely rather than crashing.
		$this->assertSame( [], $result );
	}

	/**
	 * Previously untested: adapt_pdf()'s actual rasterisation loop (one entry
	 * per page, correct filenames, correct mime/media_type) had no coverage at
	 * all before $imagick_available_override existed, since this branch could
	 * only run for real on a machine with Imagick genuinely installed. Uses
	 * the fake 'FAKEPDF:...' blob format dev/bootstrap.php's stand-in Imagick
	 * class understands (see its own docblock) — works identically whether
	 * this machine has the real extension or not.
	 */
	public function test_pdf_rasterizes_each_page_when_imagick_available(): void {
		self::$imagick_available_override = true;

		$att = [
			'data'     => $this->make_test_multipage_blob( [ [ 600, 800 ], [ 600, 800 ] ] ),
			'mime'     => 'application/pdf',
			'filename' => 'portfolio.pdf',
		];
		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertCount( 2, $result, 'Each page of a multi-page PDF becomes its own image entry.' );
		foreach ( $result as $page ) {
			$this->assertSame( 'image', $page['media_type'] );
			$this->assertSame( 'image/jpeg', $page['mime'] );
		}
		$this->assertSame( 'portfolio-p1.jpg', $result[0]['filename'] );
		$this->assertSame( 'portfolio-p2.jpg', $result[1]['filename'] );
	}

	/**
	 * Builds a blob that adapt_pdf()'s `new \Imagick()` → readImageBlob() →
	 * getNumberImages() round-trip decodes into exactly the given per-page
	 * dimensions, on WHICHEVER concrete \Imagick class ends up active for this
	 * process.
	 *
	 * $imagick_available_override only forces MediaAdapter's own
	 * extension_loaded('imagick') *guard check* — it can't change which
	 * concrete class a literal `new \Imagick()` resolves to (leading-backslash
	 * class references always resolve to the true global class; see this
	 * file's class docblock). So on a machine where the real `imagick`
	 * extension is genuinely installed, `new \Imagick()` inside adapt_pdf()
	 * really is the real extension — a hand-rolled 'FAKEPDF:...' string isn't
	 * decodable image data to it and readImageBlob() would throw. This builds
	 * a real multi-page TIFF instead in that case (TIFF's multi-page support
	 * is native to libtiff, unlike PDF which needs an optional Ghostscript
	 * delegate — one less environment dependency for a test fixture). When
	 * the real extension is absent, dev/bootstrap.php's fake stand-in is
	 * active instead and understands the plain 'FAKEPDF:WxH|WxH...' format
	 * directly — see that class's own docblock.
	 *
	 * @param array<int, array{0:int,1:int}> $page_sizes
	 */
	private function make_test_multipage_blob( array $page_sizes ): string {
		if ( \extension_loaded( 'imagick' ) ) {
			$container = new \Imagick();
			foreach ( $page_sizes as [ $w, $h ] ) {
				$page = new \Imagick();
				$page->newImage( $w, $h, new \ImagickPixel( 'white' ) );
				$page->setImageFormat( 'tiff' );
				$container->addImage( $page );
				$page->destroy();
			}
			$blob = $container->getImagesBlob();
			$container->destroy();
			return $blob;
		}

		return 'FAKEPDF:' . implode( '|', array_map( static fn( $p ) => $p[0] . 'x' . $p[1], $page_sizes ) );
	}

	// -------------------------------------------------------------------------
	// HEIC/HEIF — graceful skip when Imagick (or its libheif delegate) can't decode it
	// -------------------------------------------------------------------------

	public function test_heic_returns_empty_when_imagick_unavailable(): void {
		self::$imagick_available_override = false;

		$att    = [ 'data' => 'FAKEHEIC:1200x900', 'mime' => 'image/heic', 'filename' => 'photo.heic' ];
		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertSame( [], $result );
	}

	public function test_heic_converts_to_jpeg_when_imagick_available(): void {
		self::$imagick_available_override = true;

		$att    = [ 'data' => $this->make_test_heic_blob( 1200, 900 ), 'mime' => 'image/heic', 'filename' => 'photo.heic' ];
		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'image', $result[0]['media_type'] );
		$this->assertSame( 'image/jpeg', $result[0]['mime'] );
		$this->assertSame( 'photo.jpg', $result[0]['filename'], 'The .heic extension must be replaced with .jpg.' );
		$this->assertNotSame( '', $result[0]['data'] );
	}

	public function test_heif_mime_also_converts(): void {
		self::$imagick_available_override = true;

		$att    = [ 'data' => $this->make_test_heic_blob( 640, 480 ), 'mime' => 'image/heif', 'filename' => 'photo.heif' ];
		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'image/jpeg', $result[0]['mime'] );
	}

	public function test_heic_sequence_mime_also_converts(): void {
		self::$imagick_available_override = true;

		// Only the primary (first) image of a burst/Live Photo sequence is used.
		$att    = [ 'data' => $this->make_test_heic_blob( 640, 480 ), 'mime' => 'image/heic-sequence', 'filename' => 'burst.heic' ];
		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'image/jpeg', $result[0]['mime'] );
	}

	/**
	 * The single most common real-world failure: Imagick is installed, but the
	 * ImageMagick build lacks the libheif delegate, so it can't actually
	 * decode a HEIC/HEIF blob even though the extension itself is present.
	 * Indistinguishable from any other corrupt/undecodable blob at the
	 * catch( \ImagickException ) level — see dev/bootstrap.php's fake Imagick
	 * for why "unable to decode" covers both causes identically.
	 */
	public function test_heic_returns_empty_when_conversion_throws(): void {
		self::$imagick_available_override = true;

		$att    = [ 'data' => 'not-a-real-heic-file', 'mime' => 'image/heic', 'filename' => 'photo.heic' ];
		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertSame( [], $result );
	}

	public function test_heic_filename_falls_back_when_missing(): void {
		self::$imagick_available_override = true;

		$att    = [ 'data' => $this->make_test_heic_blob( 300, 200 ), 'mime' => 'image/heic' ]; // no 'filename' key
		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'photo.jpg', $result[0]['filename'] );
	}

	/**
	 * Builds a blob that adapt_heic()'s `new \Imagick()` → readImageBlob() →
	 * getImageBlob() round-trip can decode successfully, on WHICHEVER concrete
	 * \Imagick class ends up active for this process — same rationale as
	 * make_test_multipage_blob() above. On a machine where the real `imagick`
	 * extension is genuinely installed, a hand-rolled 'FAKEHEIC:...' string
	 * isn't decodable image data to it and readImageBlob() would throw, so
	 * this builds a real JPEG blob instead in that case (adapt_heic() doesn't
	 * care what format the *source* bytes are — it only asserts control flow
	 * here, not genuine HEIC decoding, which depends on the server's libheif
	 * delegate and is covered separately by
	 * test_heic_returns_empty_when_conversion_throws). When the real
	 * extension is absent, dev/bootstrap.php's fake stand-in is active
	 * instead and understands the plain 'FAKEHEIC:WxH' format directly.
	 */
	private function make_test_heic_blob( int $width, int $height ): string {
		if ( \extension_loaded( 'imagick' ) ) {
			$img = new \Imagick();
			$img->newImage( $width, $height, new \ImagickPixel( 'white' ) );
			$img->setImageFormat( 'jpeg' );
			$blob = $img->getImageBlob();
			$img->destroy();
			return $blob;
		}

		return sprintf( 'FAKEHEIC:%dx%d', $width, $height );
	}

	// -------------------------------------------------------------------------
	// Video — original file is always published; poster frame is best-effort
	// -------------------------------------------------------------------------

	public function test_video_without_ffmpeg_still_publishes_original_with_no_poster(): void {
		// Force the "ffmpeg not installed" branch deterministically — this
		// must hold true regardless of whether the machine actually running
		// this suite has ffmpeg or not (previously it could only run for
		// real on a box genuinely missing ffmpeg, and self-skipped everywhere
		// else — see Stubs/ai_namespace_stubs.php for how the override works).
		self::$ffmpeg_path_override = '';

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
		// Pretend ffmpeg is installed (any non-empty path will do — adapt_video()
		// only checks it's non-empty before proceeding) and force the
		// extraction command itself to "succeed", via the namespace-scoped
		// exec() override. No real ffmpeg binary is invoked at all, so this
		// runs identically on every machine.
		self::$ffmpeg_path_override = '/usr/bin/ffmpeg';
		self::$exec_override        = 'success';

		$att    = [ 'data' => 'video_binary', 'mime' => 'video/mp4', 'filename' => 'clip.mp4' ];
		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'video', $result[0]['media_type'] );
		$this->assertNotSame( '', $result[0]['poster_data'], 'A successful extraction should yield a non-empty poster frame.' );
		$this->assertSame( 'image/jpeg', $result[0]['poster_mime'] );
		// JPEG files start with the SOI marker 0xFFD8.
		$this->assertSame( "\xFF\xD8", substr( $result[0]['poster_data'], 0, 2 ) );
	}

	/**
	 * Previously untested: ffmpeg is present (the "which ffmpeg" probe
	 * succeeds) but the extraction command itself fails or produces no
	 * output file — a real crash, an unsupported codec, a corrupt upload,
	 * etc. Before the exec() override existed there was no way to force a
	 * real ffmpeg binary to fail on demand, so this branch shipped with no
	 * coverage at all. adapt_video() must still behave like the "ffmpeg not
	 * installed" case: publish the video, just without a poster frame.
	 */
	public function test_video_with_ffmpeg_present_but_extraction_fails_still_publishes_video(): void {
		self::$ffmpeg_path_override = '/usr/bin/ffmpeg';
		self::$exec_override        = 'failure';

		$att    = [ 'data' => 'video_binary', 'mime' => 'video/mp4', 'filename' => 'clip.mp4' ];
		$result = MediaAdapter::adapt( [ $att ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'video', $result[0]['media_type'] );
		$this->assertSame( 'video_binary', $result[0]['data'], 'The original video must still be published even when frame extraction fails.' );
		$this->assertSame( '', $result[0]['poster_data'] );
		$this->assertSame( '', $result[0]['poster_mime'] );
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

	// -------------------------------------------------------------------------
	// maybe_downscale_for_vision() — per-request vision-input downscale
	//
	// IMPORTANT: adapt()'s own passthrough is asserted (above,
	// test_jpeg_passthrough_sets_media_type_image() et al.) to leave 'data'
	// completely untouched — maybe_downscale_for_vision() is deliberately NOT
	// wired into adapt()/adapt_pdf()/adapt_video(). It exists only to be
	// called from inside a ProviderInterface::describe() implementation
	// (Anthropic, OpenAI) on a local, throwaway copy for that one request —
	// see the method's own doc for why mutating the attachment data here
	// would silently shrink published artwork.
	// -------------------------------------------------------------------------

	public function test_downscale_disabled_via_zero_setting_returns_original(): void {
		SubmissionTranslatorTest::$options = [ 'agnosis_ai_vision_max_width_px' => 0 ];

		$this->assertSame( 'unchanged-bytes', MediaAdapter::maybe_downscale_for_vision( 'unchanged-bytes', 'image/jpeg' ) );
	}

	public function test_downscale_returns_original_for_empty_data(): void {
		$this->assertSame( '', MediaAdapter::maybe_downscale_for_vision( '', 'image/jpeg' ) );
	}

	public function test_downscale_returns_original_when_imagick_unavailable(): void {
		self::$imagick_available_override = false;

		// Default width (800) applies (no option override) — the Imagick
		// guard must still short-circuit before ever trying to read this as
		// an image, so garbage input is fine here.
		$this->assertSame( 'not-a-real-image', MediaAdapter::maybe_downscale_for_vision( 'not-a-real-image', 'image/jpeg' ) );
	}

	public function test_downscale_returns_original_for_corrupt_image_data(): void {
		self::$imagick_available_override = true;

		$this->assertSame( 'not-a-real-image', MediaAdapter::maybe_downscale_for_vision( 'not-a-real-image', 'image/jpeg' ) );
	}

	/** Build a real, decodable JPEG blob of the given size using Imagick itself. */
	private function make_test_jpeg( int $width, int $height ): string {
		$img = new \Imagick();
		$img->newImage( $width, $height, new \ImagickPixel( 'red' ) );
		$img->setImageFormat( 'jpeg' );
		$data = $img->getImageBlob();
		$img->destroy();
		return $data;
	}

	public function test_downscale_shrinks_an_image_wider_than_the_default_max(): void {
		self::$imagick_available_override = true;

		$original = $this->make_test_jpeg( 2000, 1000 );
		$resized  = MediaAdapter::maybe_downscale_for_vision( $original, 'image/jpeg' );

		$this->assertNotSame( $original, $resized );

		$img = new \Imagick();
		$img->readImageBlob( $resized );
		$this->assertSame( 800, $img->getImageWidth(), 'Default max width is 800px.' );
		$this->assertSame( 400, $img->getImageHeight(), 'Height must scale proportionally (2000:1000 = 800:400).' );
		$img->destroy();
	}

	public function test_downscale_honours_a_custom_configured_width(): void {
		self::$imagick_available_override = true;

		SubmissionTranslatorTest::$options = [ 'agnosis_ai_vision_max_width_px' => 400 ];

		$original = $this->make_test_jpeg( 2000, 1000 );
		$resized  = MediaAdapter::maybe_downscale_for_vision( $original, 'image/jpeg' );

		$img = new \Imagick();
		$img->readImageBlob( $resized );
		$this->assertSame( 400, $img->getImageWidth() );
		$this->assertSame( 200, $img->getImageHeight() );
		$img->destroy();
	}

	public function test_downscale_never_upscales_an_already_small_image(): void {
		self::$imagick_available_override = true;

		$original = $this->make_test_jpeg( 400, 300 ); // narrower than the 800px default
		$result   = MediaAdapter::maybe_downscale_for_vision( $original, 'image/jpeg' );

		$this->assertSame( $original, $result, 'An image already narrower than the max width must be returned untouched.' );
	}

	public function test_downscale_disabled_setting_skips_even_a_large_image(): void {
		// No $imagick_available_override needed here: $max_width <= 0 makes
		// maybe_downscale_for_vision() short-circuit before it ever reaches the
		// extension_loaded('imagick') check, so this test runs identically on
		// any machine regardless of Imagick availability.
		SubmissionTranslatorTest::$options = [ 'agnosis_ai_vision_max_width_px' => 0 ];

		$original = $this->make_test_jpeg( 2000, 1000 );
		$result   = MediaAdapter::maybe_downscale_for_vision( $original, 'image/jpeg' );

		$this->assertSame( $original, $result );
	}
}
