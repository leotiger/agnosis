<?php
/**
 * MediaAdapter — normalise non-image attachments into pipeline-compatible shapes.
 *
 * The Pipeline was originally designed for image/jpeg and image/png attachments.
 * This adapter sits in front of the pipeline loop and converts other media types
 * into a form the pipeline can process:
 *
 *   image/*          → passthrough with media_type = 'image' (no conversion needed).
 *   application/pdf  → rasterise each page with Imagick, emit one 'image' entry per page.
 *   video/*          → extract the first frame with ffmpeg as a poster/description
 *                       image, but keep the original video file — emit one 'video'
 *                       entry carrying both. The video itself is what gets published;
 *                       the frame is only used for AI description and the <video poster>.
 *   audio/*          → passthrough with media_type = 'audio' (pipeline routes to audio branch).
 *
 * Attachments that cannot be converted (Imagick unavailable for PDFs) are logged and
 * dropped — the caller receives fewer entries than it passed in. Video is more
 * forgiving: if ffmpeg is unavailable or frame extraction fails, the video itself is
 * still forwarded (with no poster frame) rather than being dropped entirely — Pipeline
 * falls back to a text-only description in that case, same as it does for audio with
 * no transcript available.
 *
 * Each attachment array is expected to have at minimum:
 *   'data'       string  Raw binary content.
 *   'mime'       string  MIME type (matches Parser output).
 *   'filename'   string  Original filename.
 *
 * Each returned entry carries those same keys plus:
 *   'media_type'  string  'image' | 'audio' | 'video' — tells Pipeline which branch to use.
 *   'mime'        string  Possibly updated MIME (e.g. 'image/jpeg' after PDF rasterisation).
 *   'poster_data' string  (video only) Extracted first-frame JPEG binary, or '' if unavailable.
 *   'poster_mime' string  (video only) Always 'image/jpeg' when poster_data is non-empty.
 *
 * Also home to maybe_downscale_for_vision() — a per-request image-downscale
 * helper for vision API calls (cost optimisation), deliberately NOT wired
 * into adapt()/adapt_pdf()/adapt_video() themselves. See that method's own
 * doc for why: the attachment 'data'/'poster_data' this class returns is
 * reused downstream for enhancement and for the actual published file, so
 * mutating it here would silently shrink published artwork.
 *
 * @package Agnosis\AI
 */

declare(strict_types=1);

namespace Agnosis\AI;

use Agnosis\Core\Logger;

class MediaAdapter {

	/**
	 * Expand and adapt a list of raw attachments for the AI pipeline.
	 *
	 * @param array<int, array<string, mixed>> $attachments  Raw attachment records.
	 * @return array<int, array<string, mixed>>              Adapted records, flat list.
	 */
	public static function adapt( array $attachments ): array {
		$out = [];

		foreach ( $attachments as $attachment ) {
			$mime = (string) ( $attachment['mime'] ?? '' );

			if ( str_starts_with( $mime, 'image/' ) ) {
				$out[] = array_merge( $attachment, [ 'media_type' => 'image' ] );

			} elseif ( $mime === 'application/pdf' ) {
				array_push( $out, ...self::adapt_pdf( $attachment ) );

			} elseif ( str_starts_with( $mime, 'video/' ) ) {
				$out[] = self::adapt_video( $attachment );

			} elseif ( str_starts_with( $mime, 'audio/' ) ) {
				$out[] = array_merge( $attachment, [ 'media_type' => 'audio' ] );

			} else {
				// Unknown / unsupported MIME type — log and skip.
				Logger::warning( sprintf(
					'MediaAdapter: skipping unsupported MIME type "%s" for file "%s".',
					$mime,
					$attachment['filename'] ?? 'unknown'
				), 'pipeline' );
			}
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Vision-input downscaling
	// -------------------------------------------------------------------------

	/**
	 * Downscale an image to the admin-configured max width (agnosis_ai_vision_max_width_px,
	 * default 800px) for a single vision-API request — a pure cost/latency
	 * optimisation, since vision-token cost scales with resolution and a
	 * description task needs nowhere near a photo's original resolution.
	 *
	 * IMPORTANT — call this from inside each ProviderInterface::describe()
	 * implementation, immediately before base64-encoding the request body,
	 * and use only the return value for that one request. Never call it here
	 * in MediaAdapter (i.e. never let its output replace an attachment's
	 * 'data'/'poster_data') and never let its output flow back into
	 * Pipeline::process_single()'s $image_data / process_video_single()'s
	 * $poster_data. Those same variables are also used, unmodified, for:
	 * the image enhancement API call (which needs real resolution to do a
	 * good job), Pipeline's 'original_data' result (the actual file
	 * PostCreator publishes when enhancement doesn't run), and — for video —
	 * the poster frame's own 'poster_data' result (the real `<video poster>`
	 * attachment). Downscaling any of those in place would silently publish
	 * artwork/poster images at only agnosis_ai_vision_max_width_px wide,
	 * which is not what this setting is for.
	 *
	 * Deliberately conservative: never upscales (a source already narrower
	 * than the configured max is returned untouched), and any failure —
	 * disabled via the '0' setting, Imagick unavailable, or a corrupt/
	 * unreadable blob — falls back to returning the original image
	 * unmodified rather than ever blocking a describe() call. Mirrors
	 * adapt_pdf()'s existing Imagick-availability guard and logging
	 * convention, since Imagick is already a soft dependency in this class.
	 *
	 * @param string $data Raw image binary.
	 * @param string $mime Image MIME type (informational only — Imagick
	 *                     preserves the source format on resize).
	 * @return string Resized image binary, or the original $data unchanged
	 *                if downscaling is disabled, unnecessary, or unavailable.
	 */
	public static function maybe_downscale_for_vision( string $data, string $mime ): string {
		$max_width = (int) get_option( 'agnosis_ai_vision_max_width_px', 800 );

		if ( $max_width <= 0 || '' === $data ) {
			return $data; // Disabled via the admin setting, or nothing to resize.
		}

		if ( ! extension_loaded( 'imagick' ) || ! class_exists( \Imagick::class ) ) {
			// No warning logged here (unlike adapt_pdf()'s hard failure) —
			// sending the original, undownscaled image is a silent, harmless
			// fallback, not a broken feature; PDF rasterisation without
			// Imagick has no such fallback, which is why that path warns.
			return $data;
		}

		try {
			$imagick = new \Imagick();
			$imagick->readImageBlob( $data );

			$width = $imagick->getImageWidth();
			if ( $width <= $max_width ) {
				$imagick->destroy();
				return $data; // Already small enough — never upscale.
			}

			$height     = $imagick->getImageHeight();
			$new_height = (int) round( $height * ( $max_width / $width ) );

			$imagick->resizeImage( $max_width, max( 1, $new_height ), \Imagick::FILTER_LANCZOS, 1 );
			$resized = $imagick->getImageBlob();
			$imagick->destroy();

			return ( false !== $resized && '' !== $resized ) ? $resized : $data;

		} catch ( \Throwable $e ) {
			Logger::warning( sprintf(
				'MediaAdapter: vision-downscale failed (%s) — sending the original %s image instead.',
				$e->getMessage(),
				$mime
			), 'pipeline' );
			return $data;
		}
	}

	// -------------------------------------------------------------------------
	// PDF → image pages
	// -------------------------------------------------------------------------

	/**
	 * Rasterise each page of a PDF with Imagick.
	 *
	 * Each returned entry is an independent image attachment (JPEG, 150 dpi),
	 * suitable for the normal describe → optionally-enhance pipeline branch.
	 *
	 * Falls back gracefully when Imagick is not installed — returns an empty
	 * array and logs a notice so the admin knows why the PDF was skipped.
	 *
	 * @param array<string, mixed> $attachment  Raw attachment with 'data', 'mime', 'filename'.
	 * @return array<int, array<string, mixed>>  One entry per PDF page; empty on failure.
	 */
	private static function adapt_pdf( array $attachment ): array {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( \Imagick::class ) ) {
			Logger::warning( sprintf(
				'MediaAdapter: PDF "%s" skipped — Imagick PHP extension not available.',
				$attachment['filename'] ?? 'unknown'
			), 'pipeline' );
			return [];
		}

		try {
			$imagick = new \Imagick();
			$imagick->setResolution( 150, 150 );
			$imagick->readImageBlob( $attachment['data'] );
			$imagick->setImageFormat( 'jpeg' );

			$pages = [];
			$count = $imagick->getNumberImages();

			for ( $i = 0; $i < $count; $i++ ) {
				$imagick->setIteratorIndex( $i );
				$imagick->setImageFormat( 'jpeg' );
				$imagick->setImageCompressionQuality( 90 );

				$page_data = $imagick->getImageBlob();
				if ( ! $page_data ) {
					continue;
				}

				$base_name = pathinfo( (string) ( $attachment['filename'] ?? 'document' ), PATHINFO_FILENAME );
				$suffix    = $count > 1 ? '-p' . ( $i + 1 ) : '';

				$pages[] = [
					'data'       => $page_data,
					'mime'       => 'image/jpeg',
					'filename'   => $base_name . $suffix . '.jpg',
					'media_type' => 'image',
				];
			}

			$imagick->destroy();
			return $pages;

		} catch ( \ImagickException $e ) {
			Logger::error( sprintf(
				'MediaAdapter: Imagick failed for PDF "%s": %s',
				$attachment['filename'] ?? 'unknown',
				$e->getMessage()
			), 'pipeline' );
			return [];
		}
	}

	// -------------------------------------------------------------------------
	// Video → first frame
	// -------------------------------------------------------------------------

	/**
	 * Adapt a video attachment: keep the original file for publishing, and
	 * try to extract its first frame with ffmpeg as a poster/description image.
	 *
	 * Unlike adapt_pdf(), this never drops the attachment. The original video
	 * binary is always returned under 'data'/'mime' unchanged; the extracted
	 * frame (when available) rides alongside as 'poster_data'/'poster_mime'
	 * for Pipeline to use as the vision-description image and as the
	 * eventual `<video poster>` attachment. If ffmpeg is missing or
	 * extraction fails, 'poster_data' is '' and Pipeline falls back to a
	 * text-only description — the video is still published either way.
	 *
	 * Uses a temp-file round-trip because ffmpeg reads and writes files, not
	 * stdin/stdout, for reliable cross-format frame extraction.
	 *
	 * @param array<string, mixed> $attachment  Raw attachment with 'data', 'mime', 'filename'.
	 * @return array<string, mixed>  Single video entry — always returned, never null.
	 */
	private static function adapt_video( array $attachment ): array {
		$base_entry = array_merge( $attachment, [
			'media_type'  => 'video',
			'poster_data' => '',
			'poster_mime' => '',
		] );

		// Verify ffmpeg is available before touching temp files.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- `which ffmpeg` is a read-only binary probe with no user input; the command is a fixed string literal.
		$ffmpeg = trim( (string) shell_exec( 'which ffmpeg 2>/dev/null' ) );
		if ( empty( $ffmpeg ) ) {
			Logger::warning( sprintf(
				'MediaAdapter: video "%s" — ffmpeg not found in PATH, publishing without a poster frame (description will be text-only).',
				$attachment['filename'] ?? 'unknown'
			), 'pipeline' );
			return $base_entry;
		}

		$tmp_dir = get_temp_dir();
		$token   = wp_generate_password( 12, false );

		// Derive extension from MIME.
		$ext_map = [
			'video/mp4'       => 'mp4',
			'video/quicktime' => 'mov',
			'video/x-msvideo' => 'avi',
			'video/webm'      => 'webm',
			'video/ogg'       => 'ogv',
			'video/mpeg'      => 'mpeg',
		];
		$mime    = (string) ( $attachment['mime'] ?? 'video/mp4' );
		$ext     = $ext_map[ $mime ] ?? 'mp4';

		$in_path  = $tmp_dir . 'agnosis_vid_' . $token . '.' . $ext;
		$out_path = $tmp_dir . 'agnosis_vid_' . $token . '_frame.jpg';

		// Write the video binary to a temp file.
		file_put_contents( $in_path, $attachment['data'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Extract first frame: -vframes 1 takes only the very first video frame.
		$cmd    = sprintf(
			'%s -y -i %s -vframes 1 -f image2 %s 2>/dev/null',
			escapeshellcmd( $ffmpeg ),
			escapeshellarg( $in_path ),
			escapeshellarg( $out_path )
		);
		$return = null;
		exec( $cmd, $output, $return ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		// Read extracted frame.
		$frame_data = is_file( $out_path ) ? file_get_contents( $out_path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// Clean up temp files regardless of outcome. The original video binary
		// itself was only ever held in memory ($attachment['data']) — never
		// written to $in_path's contents beyond this ffmpeg round-trip.
		wp_delete_file( $in_path );
		wp_delete_file( $out_path );

		if ( false === $frame_data || '' === $frame_data ) {
			Logger::warning( sprintf(
				'MediaAdapter: ffmpeg frame extraction failed for "%s" (exit %d) — publishing without a poster frame (description will be text-only).',
				$attachment['filename'] ?? 'unknown',
				(int) $return
			), 'pipeline' );
			return $base_entry;
		}

		return array_merge( $base_entry, [
			'poster_data' => $frame_data,
			'poster_mime' => 'image/jpeg',
		] );
	}
}
