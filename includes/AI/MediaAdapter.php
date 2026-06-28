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
 *   video/*          → extract the first frame with ffmpeg, emit one 'image' entry.
 *   audio/*          → passthrough with media_type = 'audio' (pipeline routes to audio branch).
 *
 * Attachments that cannot be converted (Imagick unavailable for PDFs, ffmpeg absent for
 * video) are logged and dropped — the caller receives fewer entries than it passed in.
 *
 * Each attachment array is expected to have at minimum:
 *   'data'       string  Raw binary content.
 *   'mime'       string  MIME type (matches Parser output).
 *   'filename'   string  Original filename.
 *
 * Each returned entry carries those same keys plus:
 *   'media_type' string  'image' | 'audio'  — tells Pipeline which branch to use.
 *   'mime'       string  Possibly updated MIME (e.g. 'image/jpeg' after PDF rasterisation).
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
				$frame = self::adapt_video( $attachment );
				if ( null !== $frame ) {
					$out[] = $frame;
				}
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
	 * Extract the first frame of a video file using ffmpeg.
	 *
	 * Uses a temp-file round-trip because ffmpeg reads and writes files, not
	 * stdin/stdout, for reliable cross-format frame extraction.
	 *
	 * Returns null when ffmpeg is not found or the extraction fails.
	 *
	 * @param array<string, mixed> $attachment  Raw attachment with 'data', 'mime', 'filename'.
	 * @return array<string, mixed>|null  Single image attachment, or null on failure.
	 */
	private static function adapt_video( array $attachment ): ?array {
		// Verify ffmpeg is available before touching temp files.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- `which ffmpeg` is a read-only binary probe with no user input; the command is a fixed string literal.
		$ffmpeg = trim( (string) shell_exec( 'which ffmpeg 2>/dev/null' ) );
		if ( empty( $ffmpeg ) ) {
			Logger::warning( sprintf(
				'MediaAdapter: video "%s" skipped — ffmpeg not found in PATH.',
				$attachment['filename'] ?? 'unknown'
			), 'pipeline' );
			return null;
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

		// Clean up temp files regardless of outcome.
		wp_delete_file( $in_path );
		wp_delete_file( $out_path );

		if ( false === $frame_data || '' === $frame_data ) {
			Logger::error( sprintf(
				'MediaAdapter: ffmpeg frame extraction failed for "%s" (exit %d).',
				$attachment['filename'] ?? 'unknown',
				(int) $return
			), 'pipeline' );
			return null;
		}

		$base_name = pathinfo( (string) ( $attachment['filename'] ?? 'video' ), PATHINFO_FILENAME );

		return [
			'data'       => $frame_data,
			'mime'       => 'image/jpeg',
			'filename'   => $base_name . '-frame.jpg',
			'media_type' => 'image',
		];
	}
}
