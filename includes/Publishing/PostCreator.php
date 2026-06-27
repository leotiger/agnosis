<?php
/**
 * PostCreator — turns a processed AI pipeline result into a WordPress post.
 *
 * Flow:
 *   1. Load queue item.
 *   2. Run AI Pipeline on the submission.
 *   3. Upload enhanced images to the Media Library.
 *   4. Create an 'agnosis_artwork' draft with gallery, title, body, tags + signed review token.
 *   5. Mark queue item as 'published' (meaning: pipeline complete).
 *   6. Fire 'agnosis_post_drafted' → Notification sends the artist a review email.
 *   7. Artist approves via email link or /my-submissions/ → ReviewEndpoints publishes the post.
 *   8. 'agnosis_post_published' fires only then, triggering ActivityPub broadcast.
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Core\Logger;

/**
 * Converts a queued email submission into a WordPress post.
 *
 * Subject-line indicators route submissions to specialised CPTs:
 *
 *   [Biography] → agnosis_biography  (singleton per artist — always updated)
 *   [Event]     → agnosis_event      (singleton per artist — always updated)
 *   (none)      → agnosis_artwork    (per artwork, with full duplicate detection)
 *
 * Indicator matching is case-insensitive; the bracket prefix is stripped from
 * the subject before it is used as the post title.
 */
class PostCreator {

	/**
	 * Maps lowercase indicator keywords to CPT slugs.
	 * Singleton types always merge into the single existing post for that artist.
	 *
	 * @var array<string, array{post_type: string, singleton: bool}>
	 */
	private const INDICATORS = [
		'biography' => [ 'post_type' => 'agnosis_biography', 'singleton' => true ],
		'event'     => [ 'post_type' => 'agnosis_event', 'singleton' => true ],
	];

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
		Logger::info( 'Processing queue #' . $queue_id . ' from <' . ( $row->artist_id ?? '?' ) . '>.', 'publisher' );

		try {
			$submission = json_decode( $row->raw_email, true );

			if ( ! is_array( $submission ) ) {
				throw new \RuntimeException( 'Queue row has no valid submission data.' );
			}

			// ---- Resolve subject-line indicator ---------------------------------
			// Parse [Keyword] prefix from the subject; strip it for use as post title.
			[ $post_type, $singleton, $clean_subject ] = $this->resolve_indicator(
				(string) ( $submission['subject'] ?? '' )
			);
			$submission['subject'] = $clean_subject;

			Logger::info(
				sprintf( 'Queue #%d: post type resolved to "%s"%s.', $queue_id, $post_type, $singleton ? ' (singleton)' : '' ),
				'publisher'
			);

			// ---- Decode attachments ---------------------------------------------
			if ( ! empty( $submission['attachments'] ) ) {
				foreach ( $submission['attachments'] as &$att ) {
					if ( ( $att['encoding'] ?? '' ) === 'base64' && isset( $att['data'] ) ) {
						$att['data'] = base64_decode( $att['data'] );
						unset( $att['encoding'] );
					}
				}
				unset( $att );
			}

			// ---- AI pipeline ----------------------------------------------------
			// Run even for singleton types — biography/event posts still benefit
			// from AI-generated body text from the email content.
			$attach_count = count( $submission['attachments'] ?? [] );
			if ( $attach_count > 0 ) {
				Logger::info( sprintf( 'Queue #%d: running AI pipeline on %d attachment(s).', $queue_id, $attach_count ), 'publisher' );
				$results = $this->pipeline->process( $submission );
				foreach ( $results as $i => $r ) {
					if ( $r['description_ok'] ) {
						Logger::info( sprintf( 'Queue #%d: attachment %d described — "%s".', $queue_id, $i + 1, $r['title'] ), 'publisher' );
					} else {
						Logger::warning( sprintf( 'Queue #%d: attachment %d description failed — %s', $queue_id, $i + 1, $r['error'] ?? 'unknown' ), 'publisher' );
					}
				}
			} else {
				$results = []; // Biography/event emails may have no attachments.
			}

			// ---- AI polish (singleton types only) -------------------------------
			// If the operator has enabled AI polishing for this post type, run the
			// email body through a cheap text pass to fix spelling / grammar.
			if ( $singleton && ! empty( $submission['description'] ) ) {
				$polish_key = match ( $post_type ) {
					'agnosis_biography' => 'agnosis_ai_polish_biography',
					'agnosis_event'     => 'agnosis_ai_polish_event',
					default             => '',
				};
				if ( $polish_key && get_option( $polish_key ) ) {
					$polished                    = $this->pipeline->polish( $submission['description'] );
					$submission['description']   = $polished;
					Logger::info( sprintf( 'Queue #%d: AI polish applied to %s body.', $queue_id, $post_type ), 'publisher' );
				}
			}

			// ---- Duplicate / singleton resolution -------------------------------
			if ( $singleton ) {
				// Singleton types always merge into the one existing post for this artist.
				$merge_into = $this->find_singleton_post( $post_type, (int) $row->artist_id, $queue_id );
			} else {
				// Standard artworks: full three-layer duplicate detection.
				$merge_into = $this->find_duplicate_post( $submission, $results, (int) $row->artist_id, $queue_id );
			}

			$post_id = $this->create_post( $submission, $results, (int) $row->artist_id, $queue_id, $merge_into, $post_type );

			if ( is_wp_error( $post_id ) ) {
				throw new \RuntimeException( $post_id->get_error_message() );
			}

			$this->mark( $queue_id, 'published', '', $post_id );
			Logger::info( sprintf( 'Queue #%d: artwork post #%d created as draft.', $queue_id, $post_id ), 'publisher' );

			// Notify review layer — email sent to artist for approval.
			do_action( 'agnosis_post_drafted', $post_id, (int) $row->artist_id );

		} catch ( \Throwable $e ) {
			$this->mark( $queue_id, 'failed', $e->getMessage() );
			Logger::error( 'Queue #' . $queue_id . ' failed: ' . $e->getMessage(), 'publisher' );
		}
	}

	// -------------------------------------------------------------------------

	/**
	 * Parse a subject-line indicator and return the resolved CPT + singleton flag.
	 *
	 * Recognises patterns like "[Biography] My text", "[EVENT] ...", "[event]...".
	 * The indicator keyword is stripped from the subject before it is used as the
	 * post title. Unknown indicators fall back to the default artwork type.
	 *
	 * @param  string $subject Raw email subject.
	 * @return array{0: string, 1: bool, 2: string} [post_type, is_singleton, clean_subject]
	 */
	private function resolve_indicator( string $subject ): array {
		if ( preg_match( '/^\[([^\]]+)\]\s*/u', $subject, $m ) ) {
			$keyword   = strtolower( trim( $m[1] ) );
			$clean     = substr( $subject, strlen( $m[0] ) );
			$indicator = self::INDICATORS[ $keyword ] ?? null;
			if ( $indicator ) {
				return [ $indicator['post_type'], $indicator['singleton'], $clean ];
			}
		}
		return [ 'agnosis_artwork', false, $subject ];
	}

	/**
	 * Find the single existing post of a singleton type for a given artist.
	 *
	 * Singleton types (biography, events) have at most one post per artist.
	 * If one exists it is always updated; if none exists a new one is created.
	 *
	 * @param string $post_type CPT slug (e.g. 'agnosis_biography').
	 * @param int    $artist_id WordPress user ID.
	 * @param int    $queue_id  Current queue row (used only for logging).
	 */
	private function find_singleton_post( string $post_type, int $artist_id, int $queue_id ): int {
		if ( ! $artist_id ) {
			return 0;
		}
		$existing = get_posts( [
			'post_type'      => $post_type,
			'author'         => $artist_id,
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		] );
		if ( ! empty( $existing ) ) {
			$post_id = (int) $existing[0];
			Logger::info(
				sprintf( 'Queue #%d: singleton %s — merging into existing post #%d.', $queue_id, $post_type, $post_id ),
				'publisher'
			);
			return $post_id;
		}
		return 0; // No existing post → create_post() will insert a new one.
	}

	/**
	 * Ask the AI whether this new submission is a resend of an existing artwork.
	 *
	 * Compares the AI-generated title + tags of the new submission against recent
	 * artwork posts from the same artist using a cheap text-only model call.
	 * Returns the existing post ID to merge into, or 0 if this is genuinely new.
	 *
	 * @param array<int, array<string, mixed>> $results   Pipeline results for the new submission.
	 * @param int                              $artist_id WordPress user ID of the artist.
	 * @param int                              $queue_id  Current queue row (excluded from comparison).
	 */
	/**
	 * Determine whether this submission is a resend of an existing artwork post.
	 *
	 * Three layers, cheapest first:
	 *   1. Exact subject → post title match  (free DB query)
	 *   2. Exact image hash match            (free DB query)
	 *   3. AI fuzzy comparison               (cheap text model — subject + AI title + tags)
	 *
	 * Returns the post ID to merge into, or 0 for a genuinely new artwork.
	 *
	 * @param array<string, mixed>             $submission Raw email submission (subject, description, …).
	 * @param array<int, array<string, mixed>> $results    AI pipeline results, one per attachment.
	 * @param int                              $artist_id  WordPress user ID of the artist.
	 * @param int                              $queue_id   Current queue row (excluded from comparison).
	 */
	private function find_duplicate_post( array $submission, array $results, int $artist_id, int $queue_id ): int {
		if ( ! $artist_id ) {
			return 0;
		}

		// ---- 1. Exact subject match -------------------------------------------
		// The email subject is the most deliberate title signal from the artist.
		// If it matches an existing post title exactly, it's the same artwork.
		$subject = trim( (string) ( $submission['subject'] ?? '' ) );
		if ( $subject ) {
			$exact = get_posts( [
				'post_type'      => 'agnosis_artwork',
				'author'         => $artist_id,
				'title'          => $subject,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			] );
			if ( ! empty( $exact ) ) {
				$match_id = (int) $exact[0];
				if ( (int) get_post_meta( $match_id, '_agnosis_queue_id', true ) !== $queue_id ) {
					Logger::info(
						sprintf( 'Queue #%d: exact subject match — merging into existing post #%d.', $queue_id, $match_id ),
						'publisher'
					);
					return $match_id;
				}
			}
		}

		// ---- 2. Exact image hash match ----------------------------------------
		// Same binary → same artwork, regardless of subject or AI output.
		foreach ( $results as $r ) {
			if ( empty( $r['original_data'] ) ) {
				continue;
			}
			$hash    = md5( $r['original_data'] );
			$matches = get_posts( [
				'post_type'      => 'agnosis_artwork',
				'author'         => $artist_id,
				'meta_key'       => '_agnosis_image_hash',
				'meta_value'     => $hash,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			] );
			if ( ! empty( $matches ) ) {
				$match_id = (int) $matches[0];
				if ( (int) get_post_meta( $match_id, '_agnosis_queue_id', true ) === $queue_id ) {
					continue;
				}
				Logger::info(
					sprintf( 'Queue #%d: identical image hash — merging into existing post #%d.', $queue_id, $match_id ),
					'publisher'
				);
				return $match_id;
			}
		}

		// ---- 3. AI fuzzy comparison -------------------------------------------
		// Catches misspelled subjects, slightly different titles, and near-duplicate
		// content where neither subject nor image hash matched exactly.
		// We pass both the raw email subject AND the AI-generated title so the model
		// has the fullest possible picture of the artist's intent.
		$primary   = $this->primary_result( $results );
		$new_title = $primary['title'] ?? '';
		$new_tags  = implode( ', ', $primary['tags'] ?? [] );

		// Need at least one meaningful text signal to compare.
		if ( empty( $subject ) && empty( $new_title ) ) {
			return 0;
		}

		// Fetch recent artwork posts from the same artist (last 30 days).
		$recent = get_posts( [
			'post_type'      => 'agnosis_artwork',
			'author'         => $artist_id,
			'posts_per_page' => 10,
			'post_status'    => 'any',
			'date_query'     => [ [ 'after' => '30 days ago' ] ],
			'fields'         => 'ids',
		] );

		if ( empty( $recent ) ) {
			return 0;
		}

		// Build candidate list, skipping the post already owned by this queue row.
		$candidates = [];
		foreach ( $recent as $pid ) {
			$pid = (int) $pid;
			if ( (int) get_post_meta( $pid, '_agnosis_queue_id', true ) === $queue_id ) {
				continue;
			}
			$post     = get_post( $pid );
			$raw_tags = wp_get_post_tags( $pid, [ 'fields' => 'names' ] );
			$ptags    = implode( ', ', is_array( $raw_tags ) ? $raw_tags : [] );
			$candidates[ $pid ] = sprintf( 'Post #%d: "%s" — tags: %s', $pid, $post ? $post->post_title : '', $ptags );
		}

		if ( empty( $candidates ) ) {
			return 0;
		}

		$list   = implode( "\n", $candidates );
		$prompt = <<<PROMPT
An artist submitted an artwork via email.
Email subject: "{$subject}"
AI-generated title: "{$new_title}"
AI-generated tags: {$new_tags}

Recent artwork posts from the same artist (last 30 days):
{$list}

Is this submission the same artwork as one of the above posts — including if the subject is misspelled, slightly reworded, or ~90% similar?
Reply with ONLY the matching post ID number (e.g. "42"), or "0" if this is a genuinely new artwork. No explanation.
PROMPT;

		$response = $this->pipeline->chat( $prompt );
		$post_id  = (int) preg_replace( '/\D/', '', $response );

		if ( $post_id > 0 && array_key_exists( $post_id, $candidates ) ) {
			Logger::info(
				sprintf( 'Queue #%d: AI fuzzy match — merging into existing post #%d.', $queue_id, $post_id ),
				'publisher'
			);
			return $post_id;
		}

		return 0;
	}

	/**
	 * Build and insert (or update) a post for the given CPT.
	 *
	 * @param array<string, mixed>             $submission       Parsed email submission data.
	 * @param array<int, array<string, mixed>> $results          AI pipeline results, one per attachment.
	 * @param int                              $artist_id        WordPress user ID of the submitting artist.
	 * @param int                              $queue_id         Queue row ID — stored in post meta for reverse lookup.
	 * @param int                              $merge_into_post  Post ID to update instead of inserting (0 = auto-detect).
	 * @param string                           $post_type        CPT slug (default: agnosis_artwork).
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	private function create_post( array $submission, array $results, int $artist_id, int $queue_id = 0, int $merge_into_post = 0, string $post_type = 'agnosis_artwork' ): int|\WP_Error {
		// ---- Idempotency guard ------------------------------------------------
		// Priority: explicit merge target (singleton/duplicate) > same queue row > new post.
		$existing_id = $merge_into_post;

		if ( ! $existing_id && $queue_id > 0 ) {
			$existing = get_posts( [
				'post_type'      => $post_type,
				'meta_key'       => '_agnosis_queue_id',
				'meta_value'     => (string) $queue_id,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			] );
			$existing_id = ! empty( $existing ) ? (int) $existing[0] : 0;
		}

		// ---- Build content ----------------------------------------------------
		$primary  = $this->primary_result( $results );
		$all_tags = array_unique( array_merge( ...array_column( $results, 'tags' ) ) );

		// When updating an existing post, build a hash → attachment ID map from the
		// current gallery so we can reuse already-uploaded images instead of
		// duplicating them in the media library. This is what makes the
		// "series amplification" workflow work: an artist can include previously
		// submitted images alongside new ones — we skip re-uploading known images
		// and append only the genuinely new ones to the existing gallery.
		$existing_hash_map = [];
		$existing_gallery  = [];
		if ( $existing_id ) {
			$existing_gallery = (array) get_post_meta( $existing_id, '_agnosis_gallery_ids', true );
			foreach ( $existing_gallery as $att_id ) {
				$h = (string) get_post_meta( (int) $att_id, '_agnosis_image_hash', true );
				if ( $h ) {
					$existing_hash_map[ $h ] = (int) $att_id;
				}
			}
		}

		// Upload new attachments, reusing existing ones where the hash matches.
		// Hash is always computed from original_data (pre-enhancement) so it is
		// stable across resubmissions regardless of whether enhancement ran.
		$new_gallery = [];
		foreach ( $results as $result ) {
			$hash = ! empty( $result['original_data'] ) ? md5( $result['original_data'] ) : '';
			if ( $hash && isset( $existing_hash_map[ $hash ] ) ) {
				// Already in media library — reuse without re-uploading.
				$new_gallery[] = $existing_hash_map[ $hash ];
				continue;
			}
			$attachment_id = $this->upload_image(
				$result['enhanced_data'],
				$result['mime_type'],
				$result['filename'],
				$result['alt_text'],
				$result['title'],
				$hash, // pre-computed from original_data — consistent with find_duplicate_post()
			);
			if ( ! is_wp_error( $attachment_id ) ) {
				$new_gallery[] = $attachment_id;
			}
		}

		// Final gallery: existing images first, then any newly uploaded ones appended.
		// array_unique preserves order and removes any overlap.
		$gallery = array_values( array_unique( array_merge( $existing_gallery, $new_gallery ) ) );

		$post_content = $primary['body'] ?? '';

		if ( count( $gallery ) > 1 ) {
			$post_content = $this->build_gallery_block( $gallery ) . "\n\n" . $post_content;
		} elseif ( count( $gallery ) === 1 ) {
			$post_content = $this->build_image_block( $gallery[0] ) . "\n\n" . $post_content;
		}

		// Keep the existing review token when updating so artist links stay valid.
		$review_token  = $existing_id
			? ( get_post_meta( $existing_id, '_agnosis_review_token', true ) ?: wp_hash( $artist_id . '|' . time() . '|' . wp_salt( 'auth' ) ) )
			: wp_hash( $artist_id . '|' . time() . '|' . wp_salt( 'auth' ) );
		$review_expiry = time() + ( 7 * DAY_IN_SECONDS );

		$post_data = [
			'post_title'   => $primary['title'] ?: ( $submission['subject'] ?: __( 'Untitled', 'agnosis' ) ),
			'post_excerpt' => $primary['excerpt'] ?? '',
			'post_content' => $post_content,
			'post_status'  => 'draft',
			'post_type'    => $post_type,
			'post_author'  => $artist_id ?: 1,
			'meta_input'   => [
				'_agnosis_from'          => $submission['from'],
				'_agnosis_source'        => $submission['source'],
				'_agnosis_gallery_ids'   => $gallery,
				'_agnosis_artist_prompt' => $submission['description'] ?? '',
				'_agnosis_review_token'  => $review_token,
				'_agnosis_review_expiry' => $review_expiry,
				'_agnosis_queue_id'      => $queue_id,
			],
		];

		// Insert or update.
		if ( $existing_id ) {
			$post_data['ID']          = $existing_id;
			$post_data['post_status'] = get_post_status( $existing_id ) ?: 'draft'; // preserve publish state
			$post_id = wp_update_post( $post_data, true );
			Logger::info( sprintf( 'Queue #%d: updated existing post #%d.', $queue_id, $existing_id ), 'publisher' );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

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

		// Mirror image hashes from attachments onto the artwork post.
		// This lets find_duplicate_post() detect resends via a simple meta query
		// (exact hash match) without touching the AI — the strongest duplicate signal.
		delete_post_meta( $post_id, '_agnosis_image_hash' ); // clear stale hashes on reprocess
		foreach ( $gallery as $att_id ) {
			$hash = get_post_meta( $att_id, '_agnosis_image_hash', true );
			if ( $hash ) {
				add_post_meta( $post_id, '_agnosis_image_hash', $hash ); // multiple values allowed
			}
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
		string $title,
		string $hash = '', // MD5 of original (pre-enhancement) binary — caller should pass this
	): int|\WP_Error {
		// wp_tempnam() and wp_handle_sideload() live in wp-admin/includes/file.php —
		// not auto-loaded outside the normal admin media-upload flow (e.g. cron, admin-post).
		require_once ABSPATH . 'wp-admin/includes/file.php';

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

		// Store a content hash so duplicate submissions (same image, different email) can be
		// detected quickly via meta query — no AI call required for exact matches.
		// Always use the original-data hash passed by the caller; fall back to hashing
		// the (possibly enhanced) $data only if no hash was provided.
		update_post_meta( $attachment_id, '_agnosis_image_hash', $hash ?: md5( $data ) );

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
	 * Update the status (and optional post_id / error message) of a queue row.
	 *
	 * @param int    $id      Queue row ID.
	 * @param string $status  New status value.
	 * @param string $error   Optional error message to store.
	 * @param int    $post_id WordPress post ID to record (0 = leave unchanged).
	 */
	private function mark( int $id, string $status, string $error = '', int $post_id = 0 ): void {
		global $wpdb;
		$data   = [ 'status' => $status, 'error' => $error ?: null ];
		$format = [ '%s', '%s' ];
		if ( $post_id > 0 ) {
			$data['post_id'] = $post_id;
			$format[]        = '%d';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write; caching not applicable to UPDATE.
		$wpdb->update( $wpdb->prefix . 'agnosis_queue', $data, [ 'id' => $id ], $format, [ '%d' ] );
	}
}
