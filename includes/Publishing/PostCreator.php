<?php
/**
 * PostCreator — turns a processed AI pipeline result into a WordPress post.
 *
 * Flow:
 *   1. Load queue item.
 *   2. Run AI Pipeline on the submission.
 *   3. Upload each attachment to the Media Library — enhanced images, or the
 *      original file as-is for audio and video (see merge_gallery()).
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
use Agnosis\AI\PromptConfig;
use Agnosis\Artist\Admission;
use Agnosis\Core\Logger;
use Agnosis\Email\AttachmentStore;

/**
 * Converts a queued email submission into a WordPress post.
 *
 * Subject-line indicators route submissions to specialised CPTs:
 *
 *   [Biography] → agnosis_biography  (singleton per artist — always updated)
 *   [Event]     → agnosis_event      (an artist can have several; a new email
 *                                      updates an existing event only if the
 *                                      subject exactly matches its title,
 *                                      otherwise it creates a new one — see
 *                                      the event branch in handle())
 *   (none)      → agnosis_artwork    (per artwork, with full duplicate detection)
 *
 * Indicator matching is case-insensitive; the bracket prefix is stripped from
 * the subject before it is used as the post title.
 */
class PostCreator {

	/**
	 * Maps lowercase indicator keywords to CPT slugs.
	 *
	 * The 'singleton' flag still gates AI-polish eligibility (see handle()) for
	 * both biography and event, but as of 2026-07-06 it no longer means "merge
	 * into the one existing post" for agnosis_event specifically — an artist can
	 * have several events. A new [Event] email updates an existing one only
	 * when its subject exactly matches that event's title (find_post_by_subject()
	 * — the same title-match mechanism replace@ uses); any other subject creates
	 * a new event post. agnosis_biography is the only type still merged
	 * unconditionally into a single existing post per artist.
	 *
	 * @var array<string, array{post_type: string, singleton: bool}>
	 */
	private const INDICATORS = [
		'biography' => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false ],
		'event'     => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false ],
		'photo'     => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true  ],
	];

	/**
	 * Hosts an artist-submitted link is allowed to become a wp:embed block for
	 * (e.g. a video too large to email, hosted elsewhere instead).
	 *
	 * This is a hard allowlist, not a denylist, and that distinction is the
	 * entire safety mechanism: there is no code anywhere in this pipeline that
	 * fetches or inspects what a linked page actually contains (no browsing,
	 * no vision call on the destination), so there is no reliable way to
	 * detect "this is a commercial site" or "this is pornographic" after the
	 * fact. Trusting only a short, maintained list of known video/audio
	 * platforms achieves the same goal by construction — anything not on this
	 * list, whatever it is, never gets embedded. Filterable so an operator can
	 * add a platform (e.g. a self-hosted PeerTube instance) without a plugin
	 * update.
	 */
	private const ALLOWED_EMBED_HOSTS = [
		'youtube.com',
		'youtu.be',
		'vimeo.com',
		'dailymotion.com',
		'soundcloud.com',
		'bandcamp.com',
		'archive.org',
	];

	/** Video-type (vs. generic "rich") hosts, purely for the block's cosmetic `type` attribute. */
	private const VIDEO_EMBED_HOSTS = [ 'youtube.com', 'youtu.be', 'vimeo.com', 'dailymotion.com' ];

	/** Cap on how many external links a single submission can turn into embeds — avoids link-spam in the body. */
	private const MAX_EMBEDDED_LINKS = 3;

	/** @var Pipeline AI pipeline instance. */
	private Pipeline $pipeline;

	/**
	 * Inject or auto-create the AI pipeline.
	 *
	 * Accepts an optional Pipeline so tests can pass a lightweight stub without
	 * hitting real AI endpoints. Production code calls `new PostCreator()` and
	 * gets a fully-configured pipeline automatically.
	 *
	 * @param Pipeline|null $pipeline Pipeline instance, or null to create one.
	 */
	public function __construct( ?Pipeline $pipeline = null ) {
		$this->pipeline = $pipeline ?? new Pipeline();
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

		// Defense-in-depth: re-assert admission before any AI spend. The intake
		// path gates on this too, but re-checking here ensures nothing slips
		// through if an artist's role is removed between enqueue and processing.
		if ( ! Admission::is_admitted_artist( (int) $row->artist_id ) ) {
			Logger::warning( 'PostCreator: skipped queue #' . $queue_id . ' — artist #' . ( $row->artist_id ?? '?' ) . ' is not admitted.', 'publisher' );
			$this->mark( $queue_id, 'skipped' );
			return;
		}

		$this->mark( $queue_id, 'processing' );
		Logger::info( 'Processing queue #' . $queue_id . ' from <' . ( $row->artist_id ?? '?' ) . '>.', 'publisher' );

		try {
			$submission = json_decode( $row->raw_email, true );

			if ( ! is_array( $submission ) ) {
				throw new \RuntimeException( 'Queue row has no valid submission data.' );
			}

			// ---- Resolve post type ----------------------------------------------
			// Primary: recipient address (To: header / webhook 'recipient' field).
			// Fallback: subject-line [Indicator] prefix (backward compat).
			[ $post_type, $singleton, $clean_subject, $photo_only ] = $this->resolve_post_type( $submission );
			$submission['subject'] = $clean_subject;

			// Capture the artist's original title before SubmissionTranslator may
			// rewrite the subject into the site's primary language.  This is stored
			// in _agnosis_original_title so we can always show the artist their own
			// words and derive a meaningful slug from the submission language.
			$original_title = $clean_subject;

			Logger::info(
				sprintf( 'Queue #%d: post type resolved to "%s"%s.', $queue_id, $post_type, $singleton ? ' (singleton)' : '' ),
				'publisher'
			);

			// ---- Special handlers (no AI pipeline) ------------------------------

			if ( 'agnosis_remove' === $post_type ) {
				$this->handle_removal_request( $submission, (int) $row->artist_id, $queue_id );
				$this->mark( $queue_id, 'published' );
				return;
			}

			if ( 'agnosis_promote' === $post_type ) {
				$this->handle_promotion_request( $submission, (int) $row->artist_id, $queue_id );
				$this->mark( $queue_id, 'published' );
				return;
			}

			// ---- Load attachment binaries ---------------------------------------
			// New path: binary was written to uploads/agnosis-queue/{uid}/ at
			// ingest time; read it back now and remove the temp file reference.
			// Legacy path (rows enqueued before this change): binary is still
			// base64-encoded inline — decode it for backwards compatibility.
			if ( ! empty( $submission['attachments'] ) ) {
				foreach ( $submission['attachments'] as &$att ) {
					if ( isset( $att['file'] ) ) {
						$binary = $this->filesystem()->get_contents( $att['file'] );

						if ( false !== $binary ) {
							$att['data'] = $binary;
						}
						unset( $att['file'] );
					} elseif ( ( $att['encoding'] ?? '' ) === 'base64' && isset( $att['data'] ) ) {
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
				Logger::info(
					sprintf(
						'Queue #%d: running AI pipeline on %d attachment(s)%s.',
						$queue_id,
						$attach_count,
						$photo_only ? ' (photo-only — enhancement skipped)' : ''
					),
					'publisher'
				);
				$results = $this->pipeline->process( $submission, $photo_only );
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

			// ---- Quality rejection gate -----------------------------------------
			// Only applies to artwork submissions with actual pipeline results.
			// Score 0 means the provider could not assess quality — never reject.
			// Rejection threshold must be > 0 (setting to 0 disables the gate).
			// photo_only submissions bypass this gate entirely: a deliberately low-fi
			// or stylised photograph is an artistic choice, not a defect.
			if ( 'agnosis_artwork' === $post_type && ! empty( $results ) && ! $photo_only ) {
				$primary_score  = (int) ( $results[0]['photo_quality_score']  ?? 0 );
				$primary_issues = (array) ( $results[0]['photo_quality_issues'] ?? [] );
				$reject_below   = (int) get_option( 'agnosis_quality_rejection_threshold', 3 );

				if ( $reject_below > 0 && $primary_score > 0 && $primary_score <= $reject_below ) {
					Logger::warning(
						sprintf(
							'Queue #%d: primary image quality score %d ≤ rejection threshold %d — rejecting submission.',
							$queue_id, $primary_score, $reject_below
						),
						'publisher'
					);

					/**
					 * Fires when a submission is automatically rejected due to low image quality.
					 *
					 * @param int      $queue_id  Queue row ID.
					 * @param int      $artist_id WordPress user ID of the submitting artist.
					 * @param int      $score     Detected quality score (1–10).
					 * @param string[] $issues    Array of human-readable issue labels from the AI.
					 */
					do_action( 'agnosis_submission_rejected', $queue_id, (int) $row->artist_id, $primary_score, $primary_issues );

					$this->mark( $queue_id, 'failed', sprintf(
						'Rejected: image quality score %d is at or below the rejection threshold (%d). Artist notified.',
						$primary_score, $reject_below
					) );
					return;
				}
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

			// ---- Event field extraction -----------------------------------------
			// For event posts, ask the AI to pull the location and event date out of
			// the email so the agnosis/event-location and agnosis/event-date blocks
			// have data without admin entry.
			if ( 'agnosis_event' === $post_type ) {
				$event_fields                    = $this->pipeline->extract_event_fields( $submission );
				$submission['_event_location']   = $event_fields['location'];
				$submission['_event_date']        = $event_fields['event_date'];
				if ( $event_fields['location'] ) {
					Logger::info( sprintf( 'Queue #%d: event location extracted — "%s".', $queue_id, $event_fields['location'] ), 'publisher' );
				}
				if ( $event_fields['event_date'] ) {
					Logger::info( sprintf( 'Queue #%d: event date extracted — "%s".', $queue_id, $event_fields['event_date'] ), 'publisher' );
				}
			}

			// ---- Duplicate / singleton resolution -------------------------------
			if ( 'agnosis_replace' === $post_type ) {
				// Explicit replacement: skip AI fuzzy detection entirely.
				// Match only by exact subject — the artist named the artwork OR
				// event they want replaced (2026-07-06: searches both types;
				// $post_type adopts whichever one the match belongs to). No
				// match falls back to the pre-2026-07-06 default of creating a
				// new artwork — replace@ was artwork-only until now, so that's
				// the safest "nothing matched" behaviour to preserve.
				$singleton  = false;
				$merge_into = $this->find_post_by_subject( $submission['subject'], (int) $row->artist_id, [ 'agnosis_artwork', 'agnosis_event' ] );
				$post_type  = $merge_into ? (string) get_post_type( $merge_into ) : 'agnosis_artwork';
				if ( $merge_into ) {
					Logger::info( sprintf( 'Queue #%d: replace@ — updating existing %s #%d.', $queue_id, $post_type, $merge_into ), 'publisher' );
				} else {
					Logger::info( sprintf( 'Queue #%d: replace@ — no existing post found, creating new artwork.', $queue_id ), 'publisher' );
				}
			} elseif ( 'agnosis_event' === $post_type ) {
				// 2026-07-06: an artist can now have several events, so a new
				// [Event] email no longer blindly merges into "the" event (there
				// isn't just one). Instead it mirrors replace@: if the subject
				// exactly matches an existing event's title, that event is
				// updated in place (same address, no separate "replace" step);
				// otherwise a new event post is created. No AI fuzzy detection
				// here — that's tuned for artwork photo/description matching and
				// doesn't apply to a plain title match. $singleton stays true for
				// this type (still gates AI polish above) — only the merge
				// decision changes.
				$merge_into = $this->find_post_by_subject( $submission['subject'], (int) $row->artist_id, 'agnosis_event' );
				if ( $merge_into ) {
					Logger::info( sprintf( 'Queue #%d: event@ — subject matches existing event #%d, updating in place.', $queue_id, $merge_into ), 'publisher' );
				} else {
					Logger::info( sprintf( 'Queue #%d: event@ — no title match, creating new event.', $queue_id ), 'publisher' );
				}
			} elseif ( $singleton ) {
				// Remaining singleton types (biography) always merge into the one
				// existing post for this artist.
				$merge_into = $this->find_singleton_post( $post_type, (int) $row->artist_id, $queue_id );
			} else {
				// Standard artworks: full three-layer duplicate detection.
				$merge_into = $this->find_duplicate_post( $submission, $results, (int) $row->artist_id, $queue_id );
			}

			// ---- Biography merge (singleton update) --------------------------------
			// When an artist submits a biography update and a previous biography
			// already exists, merge the new text with the existing one rather than
			// replacing it. This lets artists send incremental updates ("I just won
			// the Premio Nacional") without having to resubmit their entire bio.
			//
			// Source for existing text: _agnosis_artist_prompt (the previous
			// submission's plain text, stored without block markup).
			//
			// Events are intentionally excluded — a new event announcement supersedes
			// the previous one wholesale; there is nothing to preserve.
			if ( 'agnosis_biography' === $post_type
				&& $merge_into > 0
				&& ! empty( $submission['description'] )
				&& get_option( 'agnosis_ai_merge_biography', '1' )
			) {
				$existing_prompt = trim( (string) get_post_meta( $merge_into, '_agnosis_artist_prompt', true ) );
				if ( ! empty( $existing_prompt ) ) {
					$merged = $this->pipeline->merge_biography( $existing_prompt, $submission['description'] );
					if ( ! empty( $merged ) ) {
						$submission['description'] = $merged;
						Logger::info( sprintf( 'Queue #%d: biography merged with existing content.', $queue_id ), 'publisher' );
					} else {
						Logger::warning( sprintf( 'Queue #%d: biography merge failed — using new submission as-is.', $queue_id ), 'publisher' );
					}
				}
			}

			$post_id = $this->create_post( $submission, $results, (int) $row->artist_id, $queue_id, $merge_into, $post_type, $original_title );

			if ( is_wp_error( $post_id ) ) {
				throw new \RuntimeException( $post_id->get_error_message() );
			}

			$this->mark( $queue_id, 'published', '', $post_id );
			Logger::info( sprintf( 'Queue #%d: artwork post #%d created as draft.', $queue_id, $post_id ), 'publisher' );

			// Remove temp attachment files — binaries are now in the media library.
			AttachmentStore::delete_dir( $row->message_uid );

			// Notify review layer — email sent to artist for approval.
			do_action( 'agnosis_post_drafted', $post_id, (int) $row->artist_id );

		} catch ( \Throwable $e ) {
			$this->mark( $queue_id, 'failed', $e->getMessage() );
			Logger::error( 'Queue #' . $queue_id . ' failed: ' . $e->getMessage(), 'publisher' );
		}
	}

	// -------------------------------------------------------------------------

	/**
	 * Resolve the target post type for an incoming submission.
	 *
	 * Routing priority:
	 *   1. Recipient address (To: header from IMAP; 'recipient'/'to' from webhook).
	 *      Matched case-insensitively against the three configured routing addresses.
	 *   2. Subject-line [Indicator] prefix — backward-compatible fallback for artists
	 *      who already use the bracket syntax or whose mail client doesn't set To:.
	 *   3. Default: agnosis_artwork.
	 *
	/**
	 * Resolve the post type, singleton flag, cleaned subject, and photo-only flag
	 * from the submission's To: address and subject line.
	 *
	 * Returns a four-element array: [post_type, singleton, clean_subject, photo_only].
	 *
	 * photo_only = true means the submission came via photo@ or [Photo] indicator:
	 *   - AI enhancement is skipped entirely (no API call, no image mutation).
	 *   - Quality rejection gate is bypassed (a deliberately low-fi image is not a defect).
	 *   - AI description (title, excerpt, tags, alt text) still runs normally.
	 *   - The original binary is published as-is.
	 *
	 * @param array<string, mixed> $submission
	 * @return array{0: string, 1: bool, 2: string, 3: bool}
	 */
	private function resolve_post_type( array $submission ): array {
		$to      = strtolower( trim( (string) ( $submission['to_address'] ?? '' ) ) );
		$subject = (string) ( $submission['subject'] ?? '' );

		if ( $to ) {
			$bio_addr     = strtolower( trim( (string) get_option( 'agnosis_email_bio',     '' ) ) );
			$event_addr   = strtolower( trim( (string) get_option( 'agnosis_email_event',   '' ) ) );
			$replace_addr = strtolower( trim( (string) get_option( 'agnosis_email_replace', '' ) ) );
			$remove_addr  = strtolower( trim( (string) get_option( 'agnosis_email_remove',  '' ) ) );
			$promote_addr = strtolower( trim( (string) get_option( 'agnosis_email_promote', '' ) ) );
			$photo_addr   = strtolower( trim( (string) get_option( 'agnosis_email_photo',   '' ) ) );

			if ( $bio_addr && $to === $bio_addr ) {
				return [ 'agnosis_biography', true, $subject, false ];
			}
			if ( $event_addr && $to === $event_addr ) {
				return [ 'agnosis_event', true, $subject, false ];
			}
			// Photo-only lane: AI description + no enhancement + no quality rejection.
			if ( $photo_addr && $to === $photo_addr ) {
				return [ 'agnosis_artwork', false, $subject, true ];
			}
			// Pseudo-types — handled specially in handle() before create_post() is called.
			if ( $replace_addr && $to === $replace_addr ) {
				return [ 'agnosis_replace', false, $subject, false ];
			}
			if ( $remove_addr && $to === $remove_addr ) {
				return [ 'agnosis_remove', false, $subject, false ];
			}
			if ( $promote_addr && $to === $promote_addr ) {
				return [ 'agnosis_promote', false, $subject, false ];
			}
		}

		// Fallback: subject-line indicator.
		return $this->resolve_indicator( $subject );
	}

	/**
	 * Parse a subject-line indicator and return the resolved CPT + singleton flag.
	 *
	 * Recognises patterns like "[Biography] My text", "[EVENT] ...", "[event]...".
	 * The indicator keyword is stripped from the subject before it is used as the
	 * post title. Unknown indicators fall back to the default artwork type.
	 *
	 * @param  string $subject Raw email subject.
	 * @return array{0: string, 1: bool, 2: string, 3: bool} [post_type, is_singleton, clean_subject, photo_only]
	 */
	private function resolve_indicator( string $subject ): array {
		if ( preg_match( '/^\[([^\]]+)\]\s*/u', $subject, $m ) ) {
			$keyword   = strtolower( trim( $m[1] ) );
			$clean     = substr( $subject, strlen( $m[0] ) );
			$indicator = self::INDICATORS[ $keyword ] ?? null;
			if ( $indicator ) {
				return [
					$indicator['post_type'],
					$indicator['singleton'],
					$clean,
					$indicator['photo_only'],
				];
			}
		}
		return [ 'agnosis_artwork', false, $subject, false ];
	}

	/**
	 * Find the single existing post of a singleton type for a given artist.
	 *
	 * As of 2026-07-06 this is only ever called for 'agnosis_biography' — an
	 * artist has at most one biography post, always updated in place.
	 * agnosis_event is no longer routed here: an artist can have several
	 * events, so instead they're matched by exact title via
	 * find_post_by_subject() (see the duplicate/singleton resolution block in
	 * handle()) — the same title-match mechanism replace@ uses.
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
			'no_found_rows'  => true,
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
				'no_found_rows'  => true,
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
				'no_found_rows'  => true,
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
			'no_found_rows'  => true,
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
		$prompt = sprintf(
			"An artist submitted an artwork via email.\n"
			. "Email subject: \"%s\"\n"
			. "AI-generated title: \"%s\"\n"
			. "AI-generated tags: %s\n"
			. "\n"
			. "Recent artwork posts from the same artist (last 30 days):\n"
			. "%s\n"
			. "\n"
			. "Is this submission the same artwork as one of the above posts — including if the subject is misspelled, slightly reworded, or ~90%% similar?\n"
			. 'Reply with ONLY the matching post ID number (e.g. "42"), or "0" if this is a genuinely new artwork. No explanation.',
			$subject,
			$new_title,
			$new_tags,
			$list
		);

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
	 * Find a published or draft post by exact title for a given artist.
	 *
	 * Used by:
	 *   - replace@ (2026-07-06: searches ['agnosis_artwork', 'agnosis_event']
	 *     together — the artist may be replacing either; whichever type the
	 *     match belongs to is what handle() adopts as $post_type).
	 *   - event@ (post_type = 'agnosis_event'): resending an email whose
	 *     subject exactly matches an existing event's title updates that
	 *     event in place instead of creating a new one.
	 *
	 * @param string          $subject   Post title to match.
	 * @param int             $artist_id WordPress user ID.
	 * @param string|string[] $post_type CPT slug, or an array of slugs to search across.
	 * @return int Post ID, or 0 if not found.
	 */
	private function find_post_by_subject( string $subject, int $artist_id, string|array $post_type = 'agnosis_artwork' ): int {
		if ( ! $subject || ! $artist_id ) {
			return 0;
		}
		$matches = get_posts( [
			'post_type'      => $post_type,
			'author'         => $artist_id,
			'title'          => $subject,
			'post_status'    => [ 'draft', 'pending', 'publish' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		return ! empty( $matches ) ? (int) $matches[0] : 0;
	}

	/**
	 * Handle a takedown request sent to remove@.
	 *
	 * Finds the artwork or event by exact subject/title match (2026-07-06:
	 * no longer artwork-only), records a removal token, and fires the
	 * 'agnosis_removal_requested' action so Notification can email the
	 * artist a confirmation link. Does NOT trash the post itself — that only
	 * happens once the artist clicks confirm (see RemovalEndpoints::confirm(),
	 * which also had to stop rejecting non-artwork post types for this to work
	 * end-to-end).
	 *
	 * @param array<string, mixed> $submission Parsed email submission.
	 * @param int                  $artist_id  WordPress user ID of the requesting artist.
	 * @param int                  $queue_id   Current queue row (for logging).
	 */
	private function handle_removal_request( array $submission, int $artist_id, int $queue_id ): void {
		$subject = trim( $submission['subject'] ?? '' );

		if ( ! $subject ) {
			Logger::warning( sprintf( 'Queue #%d: remove@ request has no subject — cannot identify post.', $queue_id ), 'publisher' );
			return;
		}

		$post_id = $this->find_post_by_subject( $subject, $artist_id, [ 'agnosis_artwork', 'agnosis_event' ] );

		if ( ! $post_id ) {
			Logger::warning(
				sprintf( 'Queue #%d: remove@ — no artwork or event titled "%s" found for this artist.', $queue_id, $subject ),
				'publisher'
			);
			return;
		}

		// Generate a cryptographically random removal token.
		// The post is NOT moved or modified yet; the artist must confirm via email link.
		$token  = $this->generate_token();
		$expiry = time() + ( 7 * DAY_IN_SECONDS );

		update_post_meta( $post_id, '_agnosis_removal_token',  $token );
		update_post_meta( $post_id, '_agnosis_removal_expiry', $expiry );
		update_post_meta( $post_id, '_agnosis_removal_reason', sanitize_textarea_field( $submission['description'] ?? '' ) );

		Logger::info(
			sprintf( 'Queue #%d: remove@ — removal confirmation email queued for post #%d.', $queue_id, $post_id ),
			'publisher'
		);

		/**
		 * Fires when an artist requests takedown of one of their artworks or events.
		 * A signed token has been stored — Notification sends the confirmation email.
		 *
		 * @param int $post_id   The artwork or event post ID (unchanged until confirmed).
		 * @param int $artist_id The requesting artist's user ID.
		 */
		do_action( 'agnosis_removal_requested', $post_id, $artist_id );
	}

	/**
	 * Handle a promote@ request: find the published artwork by subject and mark
	 * it as the artist's featured piece in the gallery overview.
	 *
	 * Subject must exactly match the artwork's title.  Only published artworks
	 * are eligible — a draft cannot be featured until it has been approved.
	 *
	 * @param array<string, mixed> $submission Parsed email submission.
	 * @param int                  $artist_id  WordPress user ID of the requesting artist.
	 * @param int                  $queue_id   Current queue row (for logging).
	 */
	private function handle_promotion_request( array $submission, int $artist_id, int $queue_id ): void {
		$subject = trim( $submission['subject'] ?? '' );

		if ( ! $subject ) {
			Logger::warning( sprintf( 'Queue #%d: promote@ request has no subject — cannot identify post.', $queue_id ), 'publisher' );
			return;
		}

		$matches = get_posts( [
			'post_type'      => 'agnosis_artwork',
			'post_status'    => 'publish',
			'author'         => $artist_id,
			'title'          => $subject,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		if ( empty( $matches ) ) {
			Logger::warning(
				sprintf( 'Queue #%d: promote@ — no published artwork titled "%s" found for this artist.', $queue_id, $subject ),
				'publisher'
			);
			return;
		}

		$post_id = (int) $matches[0];
		$this->set_featured( $post_id, $artist_id );

		Logger::info(
			sprintf( 'Queue #%d: promote@ — artwork #%d "%s" is now featured.', $queue_id, $post_id, $subject ),
			'publisher'
		);
	}

	/**
	 * Mark $post_id as the featured artwork for $artist_id and demote any other
	 * published artwork that currently holds that flag for the same artist.
	 *
	 * Kept here in the publishing layer (not in GalleryOverview, which is a
	 * display/UI class) because featuring is a workflow decision, not a rendering
	 * concern.  GalleryOverview reads the meta for display; PostCreator writes it.
	 *
	 * @param int $post_id   The artwork post to feature.
	 * @param int $artist_id The post author (artist) user ID.
	 */
	private function set_featured( int $post_id, int $artist_id ): void {
		$others = get_posts( [
			'post_type'      => 'agnosis_artwork',
			'post_status'    => 'publish',
			'author'         => $artist_id,
			'posts_per_page' => -1,
			'exclude'        => [ $post_id ],
			'meta_key'       => '_agnosis_featured',
			'meta_value'     => '1',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		foreach ( $others as $other_id ) {
			update_post_meta( (int) $other_id, '_agnosis_featured', '0' );
		}

		update_post_meta( $post_id, '_agnosis_featured', '1' );
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
	private function create_post( array $submission, array $results, int $artist_id, int $queue_id = 0, int $merge_into_post = 0, string $post_type = 'agnosis_artwork', string $original_title = '' ): int|\WP_Error {
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
				'no_found_rows'  => true,
			] );
			$existing_id = ! empty( $existing ) ? (int) $existing[0] : 0;
		}

		// ---- Build content ----------------------------------------------------
		$primary  = $this->primary_result( $results );
		$all_tags = array_unique( array_merge( ...array_column( $results, 'tags' ) ) );

		$gallery      = $this->merge_gallery( $existing_id, $results );
		$post_content = $this->build_post_content( $primary, $gallery, $post_type, $submission['description'] ?? '' );

		// Keep the existing review token when updating so artist links stay valid.
		// New tokens are 32 bytes of CSPRNG — no reconstruction possible.
		$review_token  = $existing_id
			? ( get_post_meta( $existing_id, '_agnosis_review_token', true ) ?: $this->generate_token() )
			: $this->generate_token();
		$review_expiry = time() + ( 7 * DAY_IN_SECONDS );

		// The artist's original submitted title is the canonical post title — it is
		// the name the artist gave their work, in their own language.  The AI-generated
		// translation (site language) is stored separately in _agnosis_translated_title
		// and surfaced to visitors via the agnosis/artwork-title block.
		$ai_title = $primary['title'] ?? '';
		$post_data = [
			'post_title'   => '' !== $original_title ? $original_title : ( $ai_title ?: __( 'Untitled', 'agnosis' ) ),
			'post_excerpt' => $primary['excerpt'] ?? '',
			'post_content' => $post_content,
			'post_status'  => 'draft',
			'post_type'    => $post_type,
			'post_author'  => $artist_id ?: 1,
			'meta_input'   => [
				'_agnosis_from'             => $submission['from']   ?? '',
				'_agnosis_source'           => $submission['source'] ?? '',
				'_agnosis_gallery_ids'      => $gallery,
				'_agnosis_artist_prompt'    => $submission['description'] ?? '',
				'_agnosis_review_token'     => $review_token,
				'_agnosis_review_expiry'    => $review_expiry,
				'_agnosis_queue_id'         => $queue_id,
				'_agnosis_translated_title' => $ai_title,
			],
		];

		if ( 'agnosis_event' === $post_type ) {
			$post_data['meta_input']['_agnosis_event_location'] = $submission['_event_location'] ?? '';
			$post_data['meta_input']['_agnosis_event_date']     = $submission['_event_date']     ?? '';
		}

		// For new posts, derive the URL slug from the artist's original submitted
		// title (before AI translation) so the permalink reflects their language.
		// On updates we preserve whatever slug is already published.
		if ( ! $existing_id && '' !== $original_title ) {
			$slug = $this->make_slug( $original_title );
			if ( '' !== trim( $slug, '-' ) ) {
				$post_data['post_name'] = $slug;
			}
			// If make_slug() returns empty (intl absent + non-Latin script) we leave
			// post_name unset; WordPress derives it from post_title (translated title).
		}

		// ---- Insert or update ------------------------------------------------
		if ( $existing_id ) {
			$post_data['ID']          = $existing_id;
			$post_data['post_status'] = get_post_status( $existing_id ) ?: 'draft'; // preserve publish state

			// For singleton types (biography, event): do not replace existing body
			// with empty content. This guards against a resend that arrives with no
			// text and no attachment producing a blank page over a previously good bio.
			if ( '' === trim( wp_strip_all_tags( $post_content ) )
				&& in_array( $post_type, [ 'agnosis_biography', 'agnosis_event' ], true )
			) {
				unset( $post_data['post_content'] );
			}

			$post_id = wp_update_post( $post_data, true );
			Logger::info( sprintf( 'Queue #%d: updated existing post #%d.', $queue_id, $existing_id ), 'publisher' );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Persist the artist's original submitted title (pre-translation) once.
		// Subsequent updates (resends, singleton merges) leave this value intact so
		// the artist's creative intent is never lost or overwritten by a later AI pass.
		if ( '' !== $original_title && '' === (string) get_post_meta( $post_id, '_agnosis_original_title', true ) ) {
			update_post_meta( $post_id, '_agnosis_original_title', $original_title );
		}

		$this->write_post_meta( $post_id, $primary, $gallery, $all_tags, $post_type );

		return $post_id;
	}

	/**
	 * Build the merged gallery of attachment IDs for a post.
	 *
	 * When updating an existing post, reuses already-uploaded images (matched by
	 * MD5 hash of the original binary) to avoid duplicates in the media library.
	 * Newly uploaded images are appended after existing ones.
	 *
	 * @param int                              $existing_id Post ID to merge into, or 0 for new.
	 * @param array<int, array<string, mixed>> $results     AI pipeline results, one per attachment.
	 * @return int[] Ordered, deduplicated attachment IDs.
	 */
	private function merge_gallery( int $existing_id, array $results ): array {
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

		$new_gallery = [];
		foreach ( $results as $result ) {
			// A result with no binary at all has nothing to upload — this only
			// happens for a fully failed description (e.g. audio_failure_result()
			// when there was no transcript or context to work from).
			if ( empty( $result['original_data'] ) ) {
				continue;
			}

			$hash = md5( $result['original_data'] );
			if ( isset( $existing_hash_map[ $hash ] ) ) {
				$new_gallery[] = $existing_hash_map[ $hash ];
				continue;
			}

			$media_type    = $result['media_type'] ?? 'image';
			$attachment_id = 'video' === $media_type
				? $this->upload_video( $result, $hash )
				: $this->upload_media(
					$result['enhanced_data'],
					$result['mime_type'],
					$result['filename'],
					$result['alt_text'] ?? '',
					$result['title'],
					$hash,
				);

			if ( is_wp_error( $attachment_id ) ) {
				continue;
			}

			$new_gallery[] = $attachment_id;

			// Original preservation — when enhancement actually changed the image,
			// upload the artist's original binary as a sidecar attachment so the
			// master is always recoverable. Stored as a child of the enhanced
			// attachment with _agnosis_is_original = '1'; its ID is recorded in
			// _agnosis_original_attachment_id on the enhanced attachment.
			// Audio and video are never enhanced (their pipeline results always
			// carry 'enhanced' => false), so this never fires for them.
			// original_data is guaranteed non-empty here — the loop already
			// `continue`s past any result with an empty original_data above.
			if ( ( $result['enhanced'] ?? false )
				&& $result['original_data'] !== $result['enhanced_data']
			) {
				$base          = pathinfo( $result['filename'], PATHINFO_FILENAME );
				$ext           = pathinfo( $result['filename'], PATHINFO_EXTENSION );
				$orig_filename = $base . '-original.' . $ext;
				$orig_mime     = $this->mime_for_extension( $ext ) ?: 'image/jpeg';

				$orig_id = $this->upload_media(
					$result['original_data'],
					$orig_mime,
					$orig_filename,
					$result['alt_text'],
					// translators: %s is the artwork title.
					sprintf( __( '%s (original)', 'agnosis' ), $result['title'] ),
					$hash, // same hash — same image before enhancement
				);

				if ( ! is_wp_error( $orig_id ) ) {
					update_post_meta( $orig_id,          '_agnosis_is_original',             '1' );
					update_post_meta( $attachment_id,    '_agnosis_original_attachment_id',  $orig_id );
				}
			}
		}

		return array_values( array_unique( array_merge( $existing_gallery, $new_gallery ) ) );
	}

	/**
	 * Upload a video result: the video binary itself, plus — when MediaAdapter
	 * managed to extract one — the poster frame as a linked sidecar image
	 * attachment. The poster is used for the `<video poster>` attribute
	 * (build_video_block()) and, when this is the post's featured item, as
	 * the post's thumbnail (write_post_meta() resolves this via
	 * _agnosis_video_poster_id, since a video attachment itself has no image
	 * representation wp_get_attachment_image_src() can use).
	 *
	 * @param array<string, mixed> $result Pipeline result for a single video attachment.
	 * @param string                $hash   MD5 of the original video binary.
	 * @return int|\WP_Error
	 */
	private function upload_video( array $result, string $hash ): int|\WP_Error {
		$video_id = $this->upload_media(
			$result['enhanced_data'],
			$result['mime_type'],
			$result['filename'],
			'', // alt text is an image-accessibility concept — not applicable to the video file itself
			$result['title'],
			$hash,
		);

		if ( is_wp_error( $video_id ) || empty( $result['poster_data'] ) ) {
			return $video_id;
		}

		$base      = pathinfo( $result['filename'], PATHINFO_FILENAME );
		$poster_id = $this->upload_media(
			$result['poster_data'],
			$result['poster_mime'] ?? 'image/jpeg',
			$base . '-poster.jpg',
			$result['alt_text'] ?? '',
			// translators: %s is the artwork title.
			sprintf( __( '%s (poster frame)', 'agnosis' ), $result['title'] ),
		);

		if ( ! is_wp_error( $poster_id ) ) {
			update_post_meta( $poster_id, '_agnosis_is_video_poster', '1' );
			update_post_meta( $video_id,  '_agnosis_video_poster_id', $poster_id );
		}

		return $video_id;
	}

	/**
	 * Build Gutenberg post content from the primary AI result and the media gallery.
	 *
	 * The gallery can mix images, audio, and video (an artist could, in principle,
	 * attach more than one kind to a single email, though in practice it's almost
	 * always one kind). Each attachment's real MIME type (not the pipeline result
	 * that produced it — that array has already been discarded by this point,
	 * and reading straight from the uploaded attachment is more robust than
	 * threading media_type through a second parameter) decides which block it
	 * becomes: images collapse into a single wp:image or wp:gallery block exactly
	 * as before; each audio or video attachment gets its own standalone core
	 * block (wp:audio / wp:video — both render natively, no theme support needed).
	 * Video/audio blocks are placed first, then the image block, then body text,
	 * then — last — a wp:embed block for each allowlisted external link found in
	 * the artist's raw message (see build_external_link_embeds()), for when the
	 * actual file was too large to email and the artist points to it elsewhere.
	 *
	 * @param array<string, mixed> $primary     Primary AI result.
	 * @param int[]                $gallery     Ordered attachment IDs.
	 * @param string               $artist_text Raw submitted email body (also scanned for embeddable links).
	 * @return string Serialised Gutenberg block markup.
	 */
	private function build_post_content( array $primary, array $gallery, string $post_type = 'agnosis_artwork', string $artist_text = '' ): string {
		// For biography and event posts, the body is the artist's own written
		// statement (already AI-polished if that setting is on). The AI image
		// description body is only appropriate for artwork — where the image *is*
		// the work. For bio/event, the image is supplementary (portrait, venue
		// photo, map) and describing it is not useful as page content.
		$body = in_array( $post_type, [ 'agnosis_biography', 'agnosis_event' ], true )
			? wp_kses_post( $artist_text )
			: ( $primary['body'] ?? '' );

		$embed_blocks = $this->build_external_link_embeds( $artist_text );

		if ( empty( $gallery ) ) {
			return $body . $embed_blocks;
		}

		$image_ids    = [];
		$media_blocks = '';

		foreach ( $gallery as $attachment_id ) {
			$mime = (string) get_post_mime_type( $attachment_id );

			if ( str_starts_with( $mime, 'video/' ) ) {
				$media_blocks .= $this->build_video_block( $attachment_id ) . "\n\n";
			} elseif ( str_starts_with( $mime, 'audio/' ) ) {
				$media_blocks .= $this->build_audio_block( $attachment_id ) . "\n\n";
			} else {
				$image_ids[] = $attachment_id;
			}
		}

		$image_block = '';
		if ( count( $image_ids ) > 1 ) {
			$image_block = $this->build_gallery_block( $image_ids ) . "\n\n";
		} elseif ( count( $image_ids ) === 1 ) {
			$image_block = $this->build_image_block( $image_ids[0] ) . "\n\n";
		}

		return $media_blocks . $image_block . $body . $embed_blocks;
	}

	/**
	 * Scan the artist's raw submitted text for links and turn each allowlisted
	 * one into a wp:embed block, for when the actual file (typically a video)
	 * was too large to email and the artist points to it elsewhere instead
	 * (YouTube, Vimeo, SoundCloud, Bandcamp, …). Appended at the very bottom
	 * of the post, after all attached media and body text.
	 *
	 * Deliberately allowlist-only — see ALLOWED_EMBED_HOSTS for why. A link
	 * to anything not on that list (or its filtered extension) is silently
	 * dropped and logged, never embedded, never shown as a raw link either.
	 *
	 * @param string $artist_text Raw submitted email body (pre-translation, pre-AI).
	 * @return string Zero or more wp:embed blocks, each followed by a blank line; '' if none.
	 */
	private function build_external_link_embeds( string $artist_text ): string {
		if ( '' === trim( $artist_text ) ) {
			return '';
		}

		// Bare-URL scan — good enough for an artist typing/pasting a link into
		// a plain-text email; deliberately excludes trailing quote/bracket/
		// angle-bracket characters that a mail client's own formatting might
		// wrap around the URL rather than actual URL characters.
		preg_match_all( '#\bhttps?://[^\s<>"\')\]]+#i', $artist_text, $matches );
		// preg_match_all() always populates index 0 (the full-pattern matches),
		// even when empty — no ?? fallback needed.
		$urls = array_unique( $matches[0] );

		if ( empty( $urls ) ) {
			return '';
		}

		$blocks = '';
		$count  = 0;

		foreach ( $urls as $url ) {
			if ( $count >= self::MAX_EMBEDDED_LINKS ) {
				break;
			}

			// Trailing sentence punctuation ("check it out: https://vimeo.com/1.")
			// is not part of the URL.
			$url  = rtrim( $url, '.,;:!?' );
			$host = strtolower( (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' ) );

			if ( '' === $host ) {
				continue;
			}

			if ( ! $this->is_allowed_embed_host( $host ) ) {
				Logger::info(
					sprintf( 'PostCreator: link to "%s" is not on the embeddable-platform allowlist — omitted from post content.', $host ),
					'publisher'
				);
				continue;
			}

			$blocks .= $this->build_embed_block( $url, $host ) . "\n\n";
			++$count;
		}

		return $blocks;
	}

	/**
	 * Whether $host is, or is a subdomain of, an allowlisted embed platform
	 * (e.g. "myname.bandcamp.com" matches the "bandcamp.com" entry).
	 *
	 * Filterable via 'agnosis_embed_host_allowlist' so an operator can add a
	 * platform without a plugin update. Filtering only ever ADDS trust — there
	 * is no corresponding way to shrink the list at runtime below
	 * ALLOWED_EMBED_HOSTS from inside this method, by design.
	 *
	 * @param string $host Lowercased hostname from the URL (may include "www.").
	 */
	private function is_allowed_embed_host( string $host ): bool {
		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		/**
		 * Filters the list of hostnames an artist-submitted link may point to
		 * in order to become a wp:embed block. Additive to ALLOWED_EMBED_HOSTS.
		 *
		 * @param string[] $hosts Base hostnames (subdomains match automatically).
		 */
		$allowed = (array) apply_filters( 'agnosis_embed_host_allowlist', self::ALLOWED_EMBED_HOSTS );

		foreach ( $allowed as $allowed_host ) {
			$allowed_host = strtolower( trim( (string) $allowed_host ) );
			if ( '' === $allowed_host ) {
				continue;
			}
			if ( $host === $allowed_host || str_ends_with( $host, '.' . $allowed_host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a minimal Gutenberg core/embed block for an allowlisted URL.
	 *
	 * Only the URL itself needs to be correct — core/embed is a dynamic block;
	 * WordPress performs its own oEmbed lookup (and caches the result in post
	 * meta) at render time using the URL in the wrapper div, regardless of
	 * what "type"/"providerNameSlug" this markup declares. Those two are
	 * included only as a best-effort cosmetic hint, not a functional
	 * requirement.
	 *
	 * @param string $url  Already-validated, allowlisted URL.
	 * @param string $host Lowercased hostname (used only to guess the cosmetic "type").
	 * @return string Block markup string.
	 */
	private function build_embed_block( string $url, string $host ): string {
		$is_video = false;
		foreach ( self::VIDEO_EMBED_HOSTS as $video_host ) {
			if ( $host === $video_host || str_ends_with( $host, '.' . $video_host ) ) {
				$is_video = true;
				break;
			}
		}
		$type = $is_video ? 'video' : 'rich';

		$attr    = wp_json_encode( [ 'url' => $url, 'type' => $type ] ) ?: '{}';
		$esc_url = esc_url( $url );

		return '<!-- wp:embed ' . $attr . ' --><figure class="wp-block-embed is-type-' . $type . '"><div class="wp-block-embed__wrapper">' . "\n" . $esc_url . "\n" . '</div></figure><!-- /wp:embed -->';
	}

	/**
	 * Persist all post meta, taxonomy terms, and quality data for a newly saved post.
	 *
	 * Separated from create_post() to keep the insert/update block readable.
	 * Safe to call on both inserts and updates — all operations are idempotent.
	 *
	 * @param int                  $post_id   WordPress post ID.
	 * @param array<string, mixed> $primary   Primary AI pipeline result.
	 * @param int[]                $gallery   Ordered attachment IDs.
	 * @param string[]             $all_tags  Merged tag strings from all results.
	 * @param string               $post_type CPT slug.
	 */
	private function write_post_meta( int $post_id, array $primary, array $gallery, array $all_tags, string $post_type ): void {
		// Featured image. A video or audio attachment has no image representation
		// wp_get_attachment_image_src() can use, so pick_thumbnail_id() prefers a
		// video's linked poster frame, or the first genuine image attachment in
		// the gallery, over the raw gallery order.
		if ( ! empty( $gallery ) ) {
			set_post_thumbnail( $post_id, $this->pick_thumbnail_id( $gallery ) );
		}

		// Tags.
		if ( ! empty( $all_tags ) ) {
			wp_set_post_tags( $post_id, $all_tags );
		}

		// Medium taxonomy term — only for artwork posts.
		// Validate against the canonical list to prevent AI hallucinations from
		// creating rogue terms. Empty or unrecognised values are silently skipped;
		// the admin can assign manually from the edit screen.
		if ( 'agnosis_artwork' === $post_type ) {
			$medium = trim( $primary['medium'] ?? '' );
			if ( $medium && in_array( $medium, PromptConfig::CANONICAL_MEDIUMS, true ) ) {
				wp_set_object_terms( $post_id, $medium, 'agnosis_medium' );
			}
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

		// Photo quality assessment from the primary result.
		// Score 0 means the provider could not assess quality (e.g. text-only provider).
		$quality_score  = (int) ( $primary['photo_quality_score']  ?? 0 );
		$quality_issues = (array) ( $primary['photo_quality_issues'] ?? [] );
		$was_enhanced   = (bool) ( $primary['enhanced'] ?? false );

		update_post_meta( $post_id, '_agnosis_photo_quality_score',  $quality_score );
		update_post_meta( $post_id, '_agnosis_photo_quality_issues', wp_json_encode( $quality_issues ) );
		update_post_meta( $post_id, '_agnosis_enhanced',             $was_enhanced ? '1' : '0' );
	}

	/**
	 * Pick the best attachment ID to use as the post's featured image.
	 *
	 * Prefers, in order: a video's linked poster frame, the first genuine
	 * image attachment in the gallery, or — for an audio-only gallery, where
	 * nothing visual exists at all — the raw first gallery entry (harmless:
	 * set_post_thumbnail() just stores the ID; anything that later tries to
	 * render it as an image gets nothing back and degrades gracefully, same
	 * as any post with no featured image at all).
	 *
	 * @param int[] $gallery Ordered attachment IDs (non-empty — caller checks).
	 */
	private function pick_thumbnail_id( array $gallery ): int {
		foreach ( $gallery as $attachment_id ) {
			$poster_id = (int) get_post_meta( $attachment_id, '_agnosis_video_poster_id', true );
			if ( $poster_id ) {
				return $poster_id;
			}
			if ( str_starts_with( (string) get_post_mime_type( $attachment_id ), 'image/' ) ) {
				return $attachment_id;
			}
		}
		return $gallery[0];
	}

	/**
	 * Write media data (image, audio, or video) to the Media Library via
	 * wp_handle_sideload. WordPress's own sideload/attachment machinery is
	 * already mime-agnostic — this was named upload_image() when the
	 * pipeline only ever produced images; renamed once audio and video
	 * started going through the same path.
	 *
	 * @param string $data     Raw binary data.
	 * @param string $mime     MIME type (e.g. 'image/jpeg', 'audio/mpeg', 'video/mp4').
	 * @param string $filename Original filename.
	 * @param string $alt      Alt text — only actually stored for image attachments.
	 * @param string $title    Post title for the attachment.
	 * @return int|\WP_Error Attachment post ID, or WP_Error on failure.
	 *
	 * Public (was private) so Artist\ContentEditor's Phase 2 photo-substitution
	 * endpoint can reuse the exact same sideload/attachment-metadata conventions
	 * for a direct artist-uploaded replacement image, instead of duplicating this
	 * logic (audit §7c: "reuse existing primitives... instead of reinventing
	 * them"). No other behaviour change.
	 */
	public function upload_media(
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
		// Use WP_Filesystem (direct method) — see filesystem() helper.
		$tmp = wp_tempnam( $filename );
		$this->filesystem()->put_contents( $tmp, $data, FS_CHMOD_FILE );

		$file = [
			'name'     => $filename,
			'type'     => $mime,
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => strlen( $data ),
		];

		// Temporarily lift the upload size limit for our sideload.
		// Store the closure reference so remove_filter() can target it precisely —
		// remove_all_filters() would silently discard filters added by other plugins.
		$size_filter = fn() => PHP_INT_MAX;
		add_filter( 'upload_size_limit', $size_filter );
		$sideload = wp_handle_sideload( $file, [ 'test_form' => false ] );
		remove_filter( 'upload_size_limit', $size_filter );

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

		// Alt text is an image-accessibility concept — only meaningful, and
		// only ever read by anything, on image attachments.
		if ( ! empty( $alt ) && str_starts_with( (string) $sideload['type'], 'image/' ) ) {
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
		$json = wp_json_encode( [
			'ids'           => $ids,
			'columns'       => 2,
			'linkTo'        => 'none',
			'imageSizeSlug' => 'agnosis-artwork',
		] ) ?: '{}';
		$imgs = '';
		foreach ( $ids as $id ) {
			$imgs .= $this->build_image_block( $id );
		}
		return '<!-- wp:gallery ' . $json . ' --><figure class="wp-block-gallery">' . $imgs . '</figure><!-- /wp:gallery -->';
	}

	/**
	 * Build a Gutenberg single image block with lightbox enabled.
	 *
	 * Uses the agnosis-artwork registered size so WP serves the correctly
	 * scaled variant and generates responsive srcset automatically.
	 *
	 * @param int $id Attachment post ID.
	 * @return string Block markup string.
	 */
	private function build_image_block( int $id ): string {
		$src_data = wp_get_attachment_image_src( $id, 'agnosis-artwork' );
		$src      = esc_url( $src_data ? $src_data[0] : ( wp_get_attachment_url( $id ) ?: '' ) );
		$attr     = wp_json_encode( [
			'id'       => $id,
			'sizeSlug' => 'agnosis-artwork',
			'lightbox' => [ 'enabled' => true ],
		] ) ?: '{}';
		return '<!-- wp:image ' . $attr . ' --><figure class="wp-block-image size-agnosis-artwork"><img src="' . $src . '" /></figure><!-- /wp:image -->';
	}

	/**
	 * Build a Gutenberg core/video block for a video attachment.
	 *
	 * Uses core/video rather than a custom block — WordPress already renders
	 * it correctly on the frontend and in the editor with no theme support
	 * needed, exactly like build_image_block() relies on core/image. Adds a
	 * `poster` attribute from the linked poster-frame attachment
	 * (_agnosis_video_poster_id, set by upload_video()) when one exists, so
	 * the player shows a real frame instead of a blank/black box before
	 * playback starts.
	 *
	 * @param int $id Video attachment post ID.
	 * @return string Block markup string.
	 */
	private function build_video_block( int $id ): string {
		$src       = esc_url( (string) wp_get_attachment_url( $id ) );
		$poster_id = (int) get_post_meta( $id, '_agnosis_video_poster_id', true );
		$poster    = $poster_id ? wp_get_attachment_image_url( $poster_id, 'agnosis-artwork' ) : '';

		$attr = wp_json_encode( array_filter( [
			'id'     => $id,
			'poster' => $poster ?: null,
		] ) ) ?: '{}';

		$poster_attribute = $poster ? ' poster="' . esc_url( $poster ) . '"' : '';

		return '<!-- wp:video ' . $attr . ' --><figure class="wp-block-video"><video controls src="' . $src . '"' . $poster_attribute . '></video></figure><!-- /wp:video -->';
	}

	/**
	 * Build a Gutenberg core/audio block for an audio attachment.
	 *
	 * Same rationale as build_video_block() — core/audio already renders a
	 * working player everywhere with zero theme support required.
	 *
	 * @param int $id Audio attachment post ID.
	 * @return string Block markup string.
	 */
	private function build_audio_block( int $id ): string {
		$src  = esc_url( (string) wp_get_attachment_url( $id ) );
		$attr = wp_json_encode( [ 'id' => $id ] ) ?: '{}';

		return '<!-- wp:audio ' . $attr . ' --><figure class="wp-block-audio"><audio controls src="' . $src . '"></audio></figure><!-- /wp:audio -->';
	}

	/**
	 * Update the status (and optional post_id / error message) of a queue row.
	 *
	 * @param int    $id      Queue row ID.
	 * @param string $status  New status value.
	 * @param string $error   Optional error message to store.
	 * @param int    $post_id WordPress post ID to record (0 = leave unchanged).
	 */
	/**
	 * Generate a URL slug from an arbitrary-language title.
	 *
	 * For Latin and accented scripts (é, ñ, ü, ø, …) WordPress's sanitize_title()
	 * already does the right thing via remove_accents().
	 *
	 * For non-Latin scripts — CJK (Chinese → pinyin, Japanese → romaji, Korean),
	 * Arabic, Hebrew, Devanagari, Cyrillic, Greek, Thai, etc. — we first run the
	 * title through PHP's ICU Transliterator (intl extension) using the rule chain
	 * "Any-Latin; Latin-ASCII; Lower()" which converts any script to a lowercase
	 * ASCII approximation before sanitize_title() does its final cleanup pass.
	 *
	 * Graceful degradation: if the intl extension is absent (unlikely on PHP 8+
	 * but possible on very constrained hosts), we fall back to sanitize_title()
	 * directly.  For non-Latin input that produces an empty/hyphen-only result,
	 * the caller leaves post_name unset and WordPress derives the slug from the
	 * AI-translated post_title instead.
	 *
	 * Examples (with intl):
	 *   "El Jardín Secreto"  → "el-jardin-secreto"
	 *   "秘密花園"           → "mi-mi-hua-yuan"    (Mandarin pinyin)
	 *   "الحديقة السرية"     → "alhdy-alsry"       (Arabic-Latin approximation)
	 *   "Тайный сад"         → "tajnyj-sad"        (Cyrillic)
	 *
	 * @param string $title The artist's original submitted title.
	 * @return string Sanitised slug (may be empty on intl-less hosts + non-Latin text).
	 */
	private function make_slug( string $title ): string {
		if ( '' === trim( $title ) ) {
			return '';
		}

		// ICU transliteration — converts any Unicode script to a Latin/ASCII slug.
		if ( class_exists( 'Transliterator' ) ) {
			$t = \Transliterator::create( 'Any-Latin; Latin-ASCII; Lower()' );
			if ( $t ) {
				$transliterated = $t->transliterate( $title );
				if ( false !== $transliterated && '' !== trim( $transliterated, " \t\n\r\0\x0B-" ) ) {
					return sanitize_title( $transliterated );
				}
			}
		}

		// Fallback for environments without intl.
		return sanitize_title( $title );
	}

	/**
	 * Generate a cryptographically secure token.
	 *
	 * 32 bytes (256 bits) from the OS CSPRNG, returned as a 64-character
	 * lowercase hex string. No predictable inputs — cannot be reconstructed
	 * from artist ID, timestamp, or site secrets.
	 *
	 * @return string 64-character hexadecimal string.
	 */
	private function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Return an initialised WP_Filesystem_Direct instance.
	 *
	 * Forces 'direct' because FTP/SSH credentials cannot be prompted in cron
	 * or admin-post contexts.
	 */
	/**
	 * Map a file extension to a MIME type for sidecar original uploads.
	 *
	 * Returns '' for unknown extensions — caller should supply a sensible default.
	 */
	private function mime_for_extension( string $ext ): string {
		return match ( strtolower( $ext ) ) {
			'jpg', 'jpeg' => 'image/jpeg',
			'png'         => 'image/png',
			'webp'        => 'image/webp',
			'gif'         => 'image/gif',
			'tiff', 'tif' => 'image/tiff',
			default       => '',
		};
	}

	private function filesystem(): \WP_Filesystem_Base {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		add_filter( 'filesystem_method', fn() => 'direct' );
		WP_Filesystem();
		remove_all_filters( 'filesystem_method' );

		global $wp_filesystem;
		return $wp_filesystem;
	}

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
