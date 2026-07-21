<?php
/**
 * TextPosterGenerator — renders a text-only submission as a placeholder poster.
 *
 * pure@ (agnosis_artwork, zero AI, the artist's own words verbatim) is a
 * visual-art lane — an agnosis_artwork post is meant to show something. Once
 * Email\Parser stopped requiring an attachment (poetry and other text-only
 * submissions are art too — see Parser::parse_imap_message()'s relaxed
 * gate), a pure@ submission with no photo would otherwise publish with an
 * empty gallery. This class generates a stand-in image from the artist's own
 * text instead, so the gallery still shows something they actually wrote.
 *
 * Design — "edge-to-edge overflow poster" (confirmed with the site owner):
 * each line of the poster's source text is rendered at the largest font size
 * that makes THAT line span the canvas edge-to-edge, then all lines are
 * stacked with their vertical centers evenly distributed across the canvas
 * height. This means:
 *   - A short source (a few lines) reads cleanly, generously spaced — each
 *     line big, bold, on its own.
 *   - A long source (many lines) degrades gracefully into overlap — the
 *     later lines increasingly stack on top of each other, so only isolated
 *     words or short phrases stay legible near the top. The concealment is
 *     purely a side effect of scale and layout, never content omission: no
 *     text is redacted or truncated (beyond the sane MAX_LINES processing
 *     cap below), it simply becomes visually dense.
 *
 * The title leads, then part of the body fills the rest (2026-07-21 — see
 * extract_lines()'s own docblock): the title is what the artist themselves
 * chose to call the piece, and belongs at the top, but a title alone
 * under-fills an edge-to-edge poster — the body's own opening lines (as
 * many as still fit within MAX_LINES) round it out. Not necessarily the
 * whole piece for a long essay, and that's fine: the complete body is never
 * lost — it still becomes the post's own text content
 * (PostCreator::build_post_content()); this poster is only ever a cover
 * image standing in for a photo.
 *
 * Styling matches the site's own dark brand (agnosis-theme's theme.json
 * palette: background #0d0d12, foreground #ededf0) and bundles its own copy
 * of Plus Jakarta Sans (SIL OFL 1.1 — see assets/fonts/plus-jakarta-sans/
 * LICENSE.txt) rather than reading the theme repo's font files at runtime —
 * a plugin's core functionality should never depend on a specific theme
 * being active.
 *
 * Imagick is a soft dependency everywhere else in this codebase (see
 * AI\MediaAdapter) — generate() follows the same extension_loaded() guard
 * and graceful-degradation convention: on any failure it returns null and
 * logs a reason, and the caller (PostCreator::handle()) publishes text-only
 * rather than blocking the submission.
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\Core\Logger;

class TextPosterGenerator {

	private const CANVAS_SIZE   = 1200;
	private const BACKGROUND    = '#0d0d12';
	/** Decimal R,G,B for the site's dark-brand foreground (#ededf0) — used to build an rgba() ImagickPixel string per line (see the alpha comment below for why a plain hex string isn't used instead). */
	private const TEXT_COLOR_RGB = '237,237,240';
	private const MARGIN        = 60;
	private const MIN_FONT_SIZE = 40;
	private const MAX_FONT_SIZE = 260;

	/**
	 * Sane processing cap — bounds worst-case render time/memory for an
	 * unusually long submission. Not an artistic choice: lines beyond this
	 * are simply never drawn, taken from the start of the text. With the
	 * evenly-distributed vertical layout below, even a handful of lines
	 * already produces a full poster, so this cap is rarely the limiting
	 * factor for what a real poem looks like.
	 */
	private const MAX_LINES = 40;

	/**
	 * Generate a poster PNG from a text-only submission's own words.
	 *
	 * @param string $subject Email subject (title) — rendered as the poster's
	 *                        lead line when present (2026-07-21 — see
	 *                        extract_lines()'s own docblock for why).
	 * @param string $text    The submission body (the artist's own text) —
	 *                        fills the rest of the poster, up to MAX_LINES,
	 *                        after the title. Not necessarily the full text
	 *                        for a long essay — see extract_lines().
	 * @return string|null Raw PNG binary, or null if generation isn't
	 *                     possible (Imagick/font unavailable, or no usable
	 *                     text in either argument).
	 */
	public static function generate( string $subject, string $text ): ?string {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( \Imagick::class ) ) {
			Logger::warning( 'TextPosterGenerator: Imagick PHP extension not available — cannot generate a pure@ placeholder poster; publishing text-only.', 'publisher' );
			return null;
		}

		$font = self::font_path();
		if ( '' === $font ) {
			Logger::warning( 'TextPosterGenerator: bundled Plus Jakarta Sans font missing from assets/fonts/ — cannot generate a poster; publishing text-only.', 'publisher' );
			return null;
		}

		$lines = self::extract_lines( $subject, $text );
		if ( empty( $lines ) ) {
			return null; // Nothing to render — caller falls back to text-only.
		}

		try {
			$canvas = new \Imagick();
			$canvas->newImage( self::CANVAS_SIZE, self::CANVAS_SIZE, new \ImagickPixel( self::BACKGROUND ) );
			$canvas->setImageFormat( 'png' );

			$usable_width = self::CANVAS_SIZE - ( 2 * self::MARGIN );
			$count        = count( $lines );

			foreach ( $lines as $i => $line ) {
				$draw = new \ImagickDraw();
				$draw->setFont( $font );
				// Subtle depth cue: alternating lines are slightly translucent,
				// so overlapping text (long submissions) reads as layered
				// rather than as flat, illegible noise. ImagickDraw::setFillAlpha()
				// is deprecated by newer ImageMagick/Imagick builds — the alpha
				// channel is baked into the fill color itself instead, via an
				// rgba() ImagickPixel string, which has no deprecated equivalent.
				$alpha = 0 === $i % 2 ? 1.0 : 0.72;
				$draw->setFillColor( new \ImagickPixel( sprintf( 'rgba(%s,%.2F)', self::TEXT_COLOR_RGB, $alpha ) ) );
				$draw->setTextAntialias( true );

				$font_size = self::fit_font_size( $canvas, $line, $usable_width, $font );
				$draw->setFontSize( $font_size );

				$metrics  = $canvas->queryFontMetrics( $draw, $line );
				$x        = self::MARGIN;
				$y_center = $count > 1
					? self::MARGIN + ( $i / ( $count - 1 ) ) * ( self::CANVAS_SIZE - 2 * self::MARGIN )
					: self::CANVAS_SIZE / 2;
				$y        = $y_center + ( (float) $metrics['ascender'] / 2 );

				$canvas->annotateImage( $draw, $x, $y, 0, $line );
			}

			$canvas->setImageCompressionQuality( 90 );
			$blob = $canvas->getImageBlob();
			$canvas->clear();
			$canvas->destroy();

			if ( '' === $blob ) {
				Logger::warning( 'TextPosterGenerator: Imagick produced no output — publishing text-only.', 'publisher' );
				return null;
			}

			return $blob;

		} catch ( \ImagickException $e ) {
			Logger::error( sprintf( 'TextPosterGenerator: Imagick failed — %s. Publishing text-only.', $e->getMessage() ), 'publisher' );
			return null;
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the poster's line list: the subject/title leads (when present),
	 * followed by as much of the body as still fits within MAX_LINES —
	 * "part of the body" filling out the rest of the edge-to-edge poster,
	 * not the title alone and not the whole piece (2026-07-21, refining the
	 * same-day title-first change above: a title-only poster under-filled
	 * the canvas — the edge-to-edge design wants real lines to distribute
	 * across the full height, and the body's own line breaks are worth
	 * keeping for a poem's opening lines, not flattened into one excerpt
	 * string). The full body still becomes the post's own text content
	 * regardless (PostCreator::build_post_content()) — this poster is a
	 * cover image standing in for a photo, not the only place the text is
	 * shown, so truncating an essay here to whatever fits is fine.
	 *
	 * Preserves the artist's own line breaks in the body portion rather than
	 * word-wrapping — for a poem, the line structure is part of the work.
	 *
	 * @return array<int, string>
	 */
	private static function extract_lines( string $subject, string $text ): array {
		$lines = [];

		$subject = trim( $subject );
		if ( '' !== $subject ) {
			$lines[] = $subject;
		}

		$remaining = self::MAX_LINES - count( $lines );
		// @phpstan-ignore-next-line -- always true given MAX_LINES=40 and $lines holding at most one entry (the subject) above; kept as an explicit guard so this doesn't silently break if MAX_LINES is ever lowered to 0/negative or more entries are pushed to $lines before this point.
		if ( $remaining > 0 ) {
			$raw_lines  = preg_split( '/\r\n|\r|\n/', $text ) ?: [];
			$body_lines = array_values( array_filter(
				array_map( 'trim', $raw_lines ),
				static fn( string $line ): bool => '' !== $line
			) );
			$lines = array_merge( $lines, array_slice( $body_lines, 0, $remaining ) );
		}

		return $lines;
	}

	/**
	 * Find the font size that makes $line span roughly $usable_width px —
	 * measured once at a reference size and scaled linearly (text width
	 * scales ~linearly with font size for a fixed string), then clamped so
	 * a single short word doesn't balloon to an absurd size and a
	 * many-word line doesn't shrink into illegibility.
	 */
	private static function fit_font_size( \Imagick $canvas, string $line, int $usable_width, string $font ): int {
		$reference = new \ImagickDraw();
		$reference->setFont( $font );
		$reference->setFontSize( 100 );

		$metrics = $canvas->queryFontMetrics( $reference, $line );
		$measured_width = max( 1.0, (float) $metrics['textWidth'] );

		$scaled = (int) round( 100 * ( $usable_width / $measured_width ) );

		return max( self::MIN_FONT_SIZE, min( self::MAX_FONT_SIZE, $scaled ) );
	}

	/** Absolute path to the bundled Plus Jakarta Sans (SemiBold) TTF, or '' if missing. */
	private static function font_path(): string {
		$path = \AGNOSIS_DIR . 'assets/fonts/plus-jakarta-sans/plus-jakarta-sans_normal_600.ttf';
		return file_exists( $path ) ? $path : '';
	}
}
