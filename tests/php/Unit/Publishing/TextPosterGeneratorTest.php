<?php
/**
 * Unit tests for TextPosterGenerator — the pure@ "edge-to-edge overflow
 * poster" generator used when a text-only submission has no attached photo.
 *
 * Two layers of coverage:
 *   - extract_lines() (private, tested via reflection) — the actual creative
 *     logic (line-break preservation, subject fallback, blank-line
 *     filtering, the MAX_LINES processing cap) needs no Imagick at all, so
 *     it's tested directly and precisely.
 *   - generate() end-to-end — Imagick-availability branches (unavailable /
 *     available) are forced deterministically via a namespace-scoped
 *     extension_loaded() override (Stubs/publishing_namespace_stubs.php),
 *     same technique as Unit/AI/MediaAdapterTest — "available" resolves to
 *     the real \Imagick when genuinely installed, or the conditional fake in
 *     dev/bootstrap.php when not, so no test here depends on what's actually
 *     installed on the machine running the suite.
 *
 * @package Agnosis\Tests\Unit\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Publishing;

use Agnosis\Publishing\TextPosterGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/publishing_namespace_stubs.php';

class TextPosterGeneratorTest extends TestCase {

	/**
	 * Forces the namespace-scoped extension_loaded() override (see
	 * Stubs/publishing_namespace_stubs.php) for TextPosterGenerator's own
	 * extension_loaded('imagick') guard. null = pass through to the real
	 * check.
	 */
	public static ?bool $imagick_available_override = null;

	protected function tearDown(): void {
		self::$imagick_available_override = null;
	}

	// -------------------------------------------------------------------------
	// generate() — Imagick availability
	// -------------------------------------------------------------------------

	public function test_generate_returns_null_when_imagick_unavailable(): void {
		self::$imagick_available_override = false;

		$result = TextPosterGenerator::generate( 'A Poem', "Line one\nLine two" );

		$this->assertNull( $result, 'No Imagick means no poster — the caller must fall back to publishing text-only.' );
	}

	public function test_generate_returns_null_when_there_is_no_usable_text(): void {
		self::$imagick_available_override = true; // Pass the Imagick guard so the emptiness check is what's actually exercised.

		$result = TextPosterGenerator::generate( '', '' );

		$this->assertNull( $result );
	}

	public function test_generate_returns_a_non_empty_blob_for_real_text(): void {
		self::$imagick_available_override = true;

		$result = TextPosterGenerator::generate( 'Untitled', "Roses are red\nViolets are blue" );

		$this->assertIsString( $result );
		$this->assertNotSame( '', $result );
		$this->assert_is_a_1200_square_poster( (string) $result );
	}

	public function test_generate_falls_back_to_subject_when_body_is_empty(): void {
		self::$imagick_available_override = true;

		$result = TextPosterGenerator::generate( 'Title Only Submission', '' );

		$this->assertIsString( $result );
		$this->assertNotSame( '', $result );
	}

	/**
	 * Verifies the returned blob really is a 1200x1200 canvas — decoded for
	 * real when Imagick is genuinely installed, or matched against the fake's
	 * predictable 'FAKEPNG:WxH' label otherwise (see dev/bootstrap.php).
	 * Uses the real global extension_loaded() (unqualified inside this
	 * Agnosis\Tests\Unit\Publishing-namespaced file resolves straight to the
	 * global function — the namespace-scoped override in
	 * Stubs/publishing_namespace_stubs.php only intercepts calls made from
	 * inside Agnosis\Publishing), so this reflects what's genuinely on this
	 * machine regardless of what generate() itself was just forced to see.
	 */
	private function assert_is_a_1200_square_poster( string $blob ): void {
		if ( extension_loaded( 'imagick' ) ) {
			$img = new \Imagick();
			$img->readImageBlob( $blob );
			$this->assertSame( 1200, $img->getImageWidth() );
			$this->assertSame( 1200, $img->getImageHeight() );
			$img->destroy();
		} else {
			$this->assertSame( 'FAKEPNG:1200x1200', $blob );
		}
	}

	// -------------------------------------------------------------------------
	// extract_lines() (private — tested via reflection)
	// -------------------------------------------------------------------------

	/** @return string[] */
	private function extract_lines( string $subject, string $text ): array {
		$ref = new \ReflectionMethod( TextPosterGenerator::class, 'extract_lines' );
		$ref->setAccessible( true );
		return $ref->invoke( null, $subject, $text );
	}

	public function test_extract_lines_preserves_the_artists_own_line_breaks(): void {
		$lines = $this->extract_lines( 'A Poem', "Roses are red\nViolets are blue" );

		$this->assertSame( [ 'Roses are red', 'Violets are blue' ], $lines, 'A poem\'s own line structure is part of the work, not just filler to be word-wrapped away.' );
	}

	public function test_extract_lines_trims_each_line(): void {
		$lines = $this->extract_lines( '', "  Roses are red  \n  Violets are blue  " );

		$this->assertSame( [ 'Roses are red', 'Violets are blue' ], $lines );
	}

	public function test_extract_lines_drops_blank_lines(): void {
		$lines = $this->extract_lines( '', "First stanza\n\n\nSecond stanza" );

		$this->assertSame( [ 'First stanza', 'Second stanza' ], $lines, 'Blank lines (stanza breaks) contribute no drawable text and must not become empty poster lines.' );
	}

	public function test_extract_lines_falls_back_to_subject_when_body_is_empty(): void {
		$lines = $this->extract_lines( 'My Title', '   ' );

		$this->assertSame( [ 'My Title' ], $lines );
	}

	public function test_extract_lines_prefers_body_over_subject_when_both_present(): void {
		$lines = $this->extract_lines( 'A Poem', 'The body is what matters.' );

		$this->assertSame( [ 'The body is what matters.' ], $lines, 'Subject is only a title-only-submission fallback — the poem\'s own words take priority.' );
	}

	public function test_extract_lines_returns_empty_when_subject_and_text_are_both_blank(): void {
		$this->assertSame( [], $this->extract_lines( '', '' ) );
		$this->assertSame( [], $this->extract_lines( '   ', "  \n  " ) );
	}

	public function test_extract_lines_caps_at_the_max_lines_processing_limit(): void {
		$source_lines = array_map( static fn( int $n ): string => "Line {$n}", range( 1, 45 ) );
		$lines        = $this->extract_lines( '', implode( "\n", $source_lines ) );

		$this->assertCount( 40, $lines, 'MAX_LINES is a sane processing cap, not an artistic choice — but it must still bound worst-case render cost for an unusually long submission.' );
		$this->assertSame( 'Line 1', $lines[0] );
		$this->assertSame( 'Line 40', $lines[39], 'The cap takes the first N lines, not a scattered sample.' );
	}

	public function test_extract_lines_handles_carriage_return_line_endings(): void {
		$lines = $this->extract_lines( '', "First\r\nSecond\rThird" );

		$this->assertSame( [ 'First', 'Second', 'Third' ], $lines, 'Real mail can arrive with CRLF or bare CR line endings — all three must split cleanly.' );
	}

	// -------------------------------------------------------------------------
	// fit_font_size() (private — tested via reflection)
	// -------------------------------------------------------------------------

	/** @return int */
	private function fit_font_size( \Imagick $canvas, string $line, int $usable_width, string $font ) {
		$ref = new \ReflectionMethod( TextPosterGenerator::class, 'fit_font_size' );
		$ref->setAccessible( true );
		return $ref->invoke( null, $canvas, $line, $usable_width, $font );
	}

	private function font_path(): string {
		$ref = new \ReflectionMethod( TextPosterGenerator::class, 'font_path' );
		$ref->setAccessible( true );
		return $ref->invoke( null );
	}

	public function test_fit_font_size_clamps_a_single_short_word_to_the_max(): void {
		self::$imagick_available_override = true;
		$canvas = new \Imagick();
		$canvas->newImage( 1200, 1200, new \ImagickPixel( '#0d0d12' ) );

		$size = $this->fit_font_size( $canvas, 'I', 1080, $this->font_path() );

		$this->assertSame( 260, $size, 'A single tiny word must not balloon to fill the whole canvas width — clamped to MAX_FONT_SIZE.' );
	}

	public function test_fit_font_size_clamps_a_very_long_line_to_the_min(): void {
		self::$imagick_available_override = true;
		$canvas = new \Imagick();
		$canvas->newImage( 1200, 1200, new \ImagickPixel( '#0d0d12' ) );

		$long_line = str_repeat( 'a very long line of many words ', 10 );
		$size      = $this->fit_font_size( $canvas, $long_line, 1080, $this->font_path() );

		$this->assertSame( 40, $size, 'A line with many words must not shrink into illegibility — clamped to MIN_FONT_SIZE.' );
	}

	public function test_fit_font_size_is_larger_for_shorter_lines(): void {
		self::$imagick_available_override = true;
		$canvas = new \Imagick();
		$canvas->newImage( 1200, 1200, new \ImagickPixel( '#0d0d12' ) );
		$font = $this->font_path();

		$short = $this->fit_font_size( $canvas, 'Short', 1080, $font );
		$long  = $this->fit_font_size( $canvas, 'A considerably longer line of text', 1080, $font );

		$this->assertGreaterThan( $long, $short, 'A shorter line scaled to the same edge-to-edge width must land on a larger font size.' );
	}
}
