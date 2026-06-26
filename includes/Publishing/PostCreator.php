<?php
/**
 * PostCreator — turns a processed AI pipeline result into a WordPress post.
 *
 * Flow:
 *   1. Load queue item.
 *   2. Run AI Pipeline on the submission.
 *   3. Upload enhanced images to the Media Library.
 *   4. Create an 'agnosis_artwork' post with gallery, title, body, tags.
 *   5. Mark queue item as 'published'.
 *   6. Fire 'agnosis_post_published' for ActivityPub broadcast.
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\AI\Pipeline;

/**
 * Converts a queued email submission into a published artwork post.
 */
class PostCreator {

	/** @var Pipeline AI pipeline instance. */
	private Pipeline $pipeline;

	/**
	 * Initialise the pipeline.
	 */
	public function __construct() {
		$this->pipeline = new Pipeline();
	}

	/**
	 * Cron callback — receives queue row ID and publishes the submission.
	 *
	 * @param int $queue_id Queue table row ID.
	 * @throws \RuntimeException When post creation fails; caught internally and logged.
	 */
	public function handle( int $queue_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no WP abstraction available.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_queue WHERE id = %d AND status = 'pending' LIMIT 1",
				$queue_id
			)
		);

		if ( ! $row ) {
			return;
		}

		$this->mark( $queue_id, 'processing' );

		try {
			$submission = json_decode( $row->raw_email, true );
			$results    = $this->pipeline->process( $submission );

			$post_id = $this->create_post( $submission, $results, (int) $row->artist_id );

			if ( is_wp_error( $post_id ) ) {
				throw new \RuntimeException( $post_id->get_error_message() );
			}

			$this->mark( $queue_id, 'published' );

			// Notify ActivityPub layer.
			do_action( 'agnosis_post_published', $post_id );

		} catch ( \Throwable $e ) {
			$this->mark( $queue_id, 'failed', $e->getMessage() );
			$this->log( 'PostCreator error for queue #' . $queue_id . ': ' . $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------

	/**
	 * Build and insert the artwork post.
	 *
	 * @param array<string, mixed>             $submission  Parsed email submission data.
	 * @param array<int, array<string, mixed>> $results     AI pipeline results, one per attachment.
	 * @param int                              $artist_id   WordPress user ID of the submitting artist.
	 * @return int|\WP_Error New post ID on success, WP_Error on failure.
	 */
	private function create_post( array $submission, array $results, int $artist_id ): int|\WP_Error {
		// Use the first successful result for title/body; merge all tags.
		$primary  = $this->primary_result( $results );
		$all_tags = array_unique( array_merge( ...array_column( $results, 'tags' ) ) );
		$gallery  = [];

		foreach ( $results as $result ) {
			$attachment_id = $this->upload_image(
				$result['enhanced_data'],
				$result['mime_type'],
				$result['filename'],
				$result['alt_text'],
				$result['title'],
			);
			if ( ! is_wp_error( $attachment_id ) ) {
				$gallery[] = $attachment_id;
			}
		}

		$post_content = $primary['body'] ?? '';

		// Append a Gutenberg gallery block if multiple images.
		if ( count( $gallery ) > 1 ) {
			$post_content = $this->build_gallery_block( $gallery ) . "\n\n" . $post_content;
		} elseif ( count( $gallery ) === 1 ) {
			$post_content = $this->build_image_block( $gallery[0] ) . "\n\n" . $post_content;
		}

		$post_data = [
			'post_title'   => $primary['title'] ?: ( $submission['subject'] ?: __( 'Untitled', 'agnosis' ) ),
			'post_excerpt' => $primary['excerpt'] ?? '',
			'post_content' => $post_content,
			'post_status'  => 'publish',
			'post_type'    => 'agnosis_artwork',
			'post_author'  => $artist_id ?: 1,
			'meta_input'   => [
				'_agnosis_from'           => $submission['from'],
				'_agnosis_source'         => $submission['source'],
				'_agnosis_gallery_ids'    => $gallery,
				'_agnosis_artist_prompt'  => $submission['description'] ?? '',
			],
		];

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set featured image.
		if ( ! empty( $gallery ) ) {
			set_post_thumbnail( $post_id, $gallery[0] );
		}

		// Set tags.
		if ( ! empty( $all_tags ) ) {
			wp_set_post_tags( $post_id, $all_tags );
		}

		return $post_id;
	}

	/**
	 * Write image data to the Media Library via wp_handle_sideload.
	 *
	 * @param string $data     Raw image binary data.
	 * @param string $mime     MIME type (e.g. 'image/jpeg').
	 * @param string $filename Original filename.
	 * @param string $alt      Alt text for the attachment.
	 * @param string $title    Post title for the attachment.
	 * @return int|\WP_Error Attachment post ID, or WP_Error on failure.
	 */
	private function upload_image(
		string $data,
		string $mime,
		string $filename,
		string $alt,
		string $title
	): int|\WP_Error {
		// Write to a temp file, then use wp_handle_sideload.
		$tmp = wp_tempnam( $filename );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem not available at this point in the cron pipeline; temp file is cleaned up immediately after sideload.
		file_put_contents( $tmp, $data );

		$file = [
			'name'     => $filename,
			'type'     => $mime,
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => strlen( $data ),
		];

		// Disable the upload size check for our sideload.
		add_filter( 'upload_size_limit', fn() => PHP_INT_MAX );
		$sideload = wp_handle_sideload( $file, [ 'test_form' => false ] );
		remove_all_filters( 'upload_size_limit' );

		wp_delete_file( $tmp );

		if ( isset( $sideload['error'] ) ) {
			return new \WP_Error( 'agnosis_upload', $sideload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => $sideload['type'],
				'post_title'     => $title ?: sanitize_file_name( $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$sideload['file']
		);

		if ( 0 === $attachment_id ) {
			return new \WP_Error( 'agnosis_insert_attachment', __( 'Failed to insert attachment.', 'agnosis' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attachment_id, $sideload['file'] );
		wp_update_attachment_metadata( $attachment_id, $meta );

		if ( ! empty( $alt ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		return $attachment_id;
	}

	/**
	 * Return the first result that has a successful description, or fall back to index 0.
	 *
	 * @param array<int, array<string, mixed>> $results AI pipeline results.
	 * @return array<string, mixed> Primary result data.
	 */
	private function primary_result( array $results ): array {
		foreach ( $results as $r ) {
			if ( $r['description_ok'] ) {
				return $r;
			}
		}
		return $results[0] ?? [];
	}

	/**
	 * Build a Gutenberg gallery block containing multiple images.
	 *
	 * @param array<int, int> $ids Attachment post IDs.
	 * @return string Block markup string.
	 */
	private function build_gallery_block( array $ids ): string {
		$json = wp_json_encode( [ 'ids' => $ids, 'columns' => 2, 'linkTo' => 'none' ] ) ?: '{}';
		$imgs = '';
		foreach ( $ids as $id ) {
			$url  = esc_url( wp_get_attachment_url( $id ) ?: '' );
			$imgs .= '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . $url . '" /></figure><!-- /wp:image -->';
		}
		return '<!-- wp:gallery ' . $json . ' --><figure class="wp-block-gallery">' . $imgs . '</figure><!-- /wp:gallery -->';
	}

	/**
	 * Build a Gutenberg single image block.
	 *
	 * @param int $id Attachment post ID.
	 * @return string Block markup string.
	 */
	private function build_image_block( int $id ): string {
		$url = esc_url( wp_get_attachment_url( $id ) ?: '' );
		return '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . $url . '" /></figure><!-- /wp:image -->';
	}

	/**
	 * Update the status (and optional error message) of a queue row.
	 *
	 * @param int    $id     Queue row ID.
	 * @param string $status New status value.
	 * @param string $error  Optional error message to store.
	 */
	private function mark( int $id, string $status, string $error = '' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write; caching not applicable to UPDATE.
		$wpdb->update(
			$wpdb->prefix . 'agnosis_queue',
			[ 'status' => $status, 'error' => $error ?: null ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Write a message to the debug log when WP_DEBUG_LOG is enabled.
	 *
	 * @param string $msg Log message.
	 */
	private function log( string $msg ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Agnosis Publisher] ' . $msg );
		}
	}
}
