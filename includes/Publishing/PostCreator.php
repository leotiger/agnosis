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
use Agnosis\AI\SubmissionTranslator;
use Agnosis\Artist\Admission;
use Agnosis\Compat\LinguaForge;
use Agnosis\Core\Debug;
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
		// English (canonical).
		'biography' => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ],
		'event'     => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ],
		'photo'     => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => false ],
		// pure@ implies photo_only too — no enhancement, on top of no description AI at all.
		'pure'      => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ],

		// Localized aliases (fifth audit §2d). Address-based routing
		// (resolve_post_type()'s primary mechanism) makes this bracket-keyword
		// table a fallback concern, but the fallback exists precisely for
		// artists whose mail situation is odd — the least technical cohort —
		// and an English-only keyword list silently turned a Spanish
		// "[Biografía]" into a plain artwork submission. Covers the same four
		// indicators translated into the 17 locales this plugin ships
		// template-string catalogs for (see languages/*.po) — a static array,
		// no AI, no settings. Several languages share an identical unaccented
		// spelling (e.g. "foto"), so one key legitimately covers more than
		// one locale; each is annotated with which locale(s) it serves.
		// Biography.
		'biografía'  => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // es (accented)
		'biografia'  => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // es/it/pt/ca (unaccented)
		'biographie' => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // fr
		'biografie'  => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // de/nl
		'biyografi'  => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // tr
		'biografi'   => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // id
		'wasifu'     => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // sw
		'биография'  => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // ru
		'プロフィール'  => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // ja
		'経歴'         => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // ja (alt)
		'简介'         => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // zh
		'传记'         => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // zh (alt)
		'سيرة'        => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // ar
		'بیوگرافی'    => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // fa
		'سوانح'       => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // ur
		'जीवनी'       => [ 'post_type' => 'agnosis_biography', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // hi

		// Event.
		'evento'        => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // es/it/pt
		'esdeveniment'  => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // ca
		'événement'     => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // fr (accented)
		'evenement'     => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // fr (unaccented)/nl
		'veranstaltung' => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // de
		'etkinlik'      => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // tr
		'acara'         => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // id
		'tukio'         => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // sw
		'событие'       => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // ru
		'イベント'        => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // ja
		'活动'           => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // zh
		'حدث'           => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // ar
		'رویداد'        => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // fa
		'تقریب'         => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // ur
		'कार्यक्रम'     => [ 'post_type' => 'agnosis_event', 'singleton' => true, 'photo_only' => false, 'pure' => false ], // hi

		// Photo.
		'foto'      => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => false ], // es/it/pt/de/nl/id (all spelled "foto")
		'fotoğraf'  => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => false ], // tr
		'picha'     => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => false ], // sw
		'фото'      => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => false ], // ru
		'写真'        => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => false ], // ja
		'照片'        => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => false ], // zh
		'صورة'       => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => false ], // ar
		'عکس'        => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => false ], // fa
		'تصویر'      => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => false ], // ur
		'फोटो'       => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => false ], // hi

		// Pure (implies photo_only — see canonical 'pure' above).
		'puro'   => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // es/it/pt
		'pur'    => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // fr
		'rein'   => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // de
		'puur'   => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // nl
		'saf'    => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // tr
		'murni'  => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // id
		'safi'   => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // sw
		'чистый' => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // ru
		'ピュア'    => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // ja
		'纯'      => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // zh
		'نقي'    => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // ar
		'خالص'   => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // fa/ur
		'शुद्ध'  => [ 'post_type' => 'agnosis_artwork', 'singleton' => false, 'photo_only' => true, 'pure' => true ], // hi
	];

	/**
	 * Values stored in `_agnosis_intake_endpoint` — which address created an
	 * agnosis_artwork post. Written once at creation (never on update, never
	 * by replace@) and read by replace@ to reuse the same processing strategy
	 * on a resend. See handle()'s replace@ branch and create_post().
	 */
	private const ENDPOINT_ARTWORK = 'artwork';
	private const ENDPOINT_PHOTO   = 'photo';
	private const ENDPOINT_PURE    = 'pure';

	/**
	 * Video-type (vs. generic "rich") hosts, purely for the block's cosmetic
	 * `type` attribute — see build_embed_block(). Whether a host is trusted
	 * enough to embed at all is decided by EmbedPolicy, not this list.
	 */
	private const VIDEO_EMBED_HOSTS = [ 'youtube.com', 'youtu.be', 'vimeo.com', 'dailymotion.com' ];

	/** Cap on how many external links a single submission can turn into embeds — avoids link-spam in the body. */
	private const MAX_EMBEDDED_LINKS = 3;

	/** @var Pipeline AI pipeline instance. */
	private Pipeline $pipeline;

	/** @var EmbedPolicy Decides whether a submitted link may become a wp:embed block. */
	private EmbedPolicy $embed_policy;

	/**
	 * Links dropped by the current build_post_content() call — reset at the
	 * start of build_external_link_embeds(), read by create_post() right
	 * after, and written into the new post's `_agnosis_dropped_links` meta so
	 * Notification::build_email() can tell the artist why a link they
	 * mentioned didn't turn into an embed, instead of it just silently not
	 * being there. See build_external_link_embeds()'s docblock.
	 *
	 * @var array<int, array{url: string, reason: string}>
	 */
	private array $last_dropped_links = [];

	/**
	 * Fuzzy title-match suggestion for the current handle() call, when it was
	 * a replace@ or [Event] update whose subject matched no existing post
	 * exactly (audit §2a). Reset unconditionally at the top of every handle()
	 * call — not just inside the replace@/event@ branches — so a suggestion
	 * from one queue row can never leak onto an unrelated later one that
	 * doesn't touch either branch at all (same reset-then-conditionally-fill
	 * shape as $last_dropped_links above, just triggered per-row instead of
	 * per-build_post_content()-call). Populated via gather_title_context() —
	 * the same fuzzy AI-comparison "did you mean" machinery §2c already built
	 * for remove@/promote@ — and read by create_post() right after insertion,
	 * written into the new post's `_agnosis_merge_miss_suggestion` meta so
	 * Notification::build_email() can tell the artist this may be an
	 * unintended duplicate, instead of the review email reading exactly like
	 * an ordinary new-artwork draft.
	 *
	 * @var array{type: string, title: string}|array{}
	 */
	private array $pending_merge_miss_suggestion = [];

	/**
	 * Inject or auto-create the AI pipeline (and, from it, the embed policy).
	 *
	 * Accepts optional collaborators so tests can pass lightweight stubs
	 * without hitting real AI endpoints or the network. Production code calls
	 * `new PostCreator()` and gets fully-configured ones automatically.
	 *
	 * @param Pipeline|null    $pipeline     Pipeline instance, or null to create one.
	 * @param EmbedPolicy|null $embed_policy EmbedPolicy instance, or null to create one
	 *                                       (reusing $pipeline, so an injected AI stub
	 *                                       covers both artwork description and link review).
	 */
	public function __construct( ?Pipeline $pipeline = null, ?EmbedPolicy $embed_policy = null ) {
		$this->pipeline     = $pipeline ?? new Pipeline();
		$this->embed_policy = $embed_policy ?? new EmbedPolicy( $this->pipeline );
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
			[ $post_type, $singleton, $clean_subject, $photo_only, $pure ] = $this->resolve_post_type( $submission );
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

			// ---- replace@: resolve the merge target BEFORE the AI pipeline runs ---
			// Must happen this early (not in the "Duplicate / singleton resolution"
			// block further down, which runs after the pipeline) so that when a
			// matching post is found, its _agnosis_intake_endpoint meta can force
			// $photo_only/$pure to the SAME strategy the artist used on their
			// original submission before pipeline->process()/process_raw() is ever
			// called. replace@ only ever READS this meta — it is never written or
			// changed here or anywhere else in this branch.
			$is_replace = ( 'agnosis_replace' === $post_type );
			$merge_into = 0;

			// Reset unconditionally for every queue row, regardless of post
			// type — see this property's own docblock for why (audit §2a).
			$this->pending_merge_miss_suggestion = [];

			if ( $is_replace ) {
				$merge_into = $this->find_post_by_subject( $submission['subject'], (int) $row->artist_id, [ 'agnosis_artwork', 'agnosis_event' ] );
				$post_type  = $merge_into ? (string) get_post_type( $merge_into ) : 'agnosis_artwork';

				if ( $merge_into && 'agnosis_artwork' === $post_type ) {
					$original_endpoint = (string) get_post_meta( $merge_into, '_agnosis_intake_endpoint', true );
					$pure              = ( self::ENDPOINT_PURE === $original_endpoint );
					$photo_only        = $pure || ( self::ENDPOINT_PHOTO === $original_endpoint );

					Logger::info(
						sprintf( 'Queue #%d: replace@ — matched post #%d was originally submitted via "%s"; reusing that strategy.', $queue_id, $merge_into, $original_endpoint ?: self::ENDPOINT_ARTWORK ),
						'publisher'
					);
				}

				if ( $merge_into ) {
					Logger::info( sprintf( 'Queue #%d: replace@ — updating existing %s #%d.', $queue_id, $post_type, $merge_into ), 'publisher' );
				} else {
					Logger::info( sprintf( 'Queue #%d: replace@ — no existing post found, creating new artwork.', $queue_id ), 'publisher' );

					// audit §2a: a replace@ miss doesn't fail silently — it fails
					// WRONGLY, creating a duplicate that looks like an ordinary new
					// artwork. Never auto-merge on a fuzzy guess (replace is
					// destructive) — just carry a suggestion through to the review
					// email via create_post()'s always-written meta below.
					$miss_context = $this->gather_title_context(
						$submission['subject'],
						(int) $row->artist_id,
						[ 'agnosis_artwork', 'agnosis_event' ],
						[ 'draft', 'pending', 'publish' ]
					);
					if ( '' !== $miss_context['suggestion_title'] ) {
						$this->pending_merge_miss_suggestion = [ 'type' => 'replace', 'title' => $miss_context['suggestion_title'] ];
					}
				}
			}

			if ( Debug::enabled() ) {
				$dbg_atts = [];
				foreach ( (array) ( $submission['attachments'] ?? [] ) as $i => $a ) {
					$dbg_atts[] = sprintf(
						'  [%d] filename=%s mime=%s has_data=%s has_file_ref=%s bytes=%d',
						$i,
						(string) ( $a['filename'] ?? '(none)' ),
						(string) ( $a['mime'] ?? '(none)' ),
						isset( $a['data'] ) ? 'yes' : 'no',
						isset( $a['file'] ) ? 'yes' : 'no',
						isset( $a['data'] ) ? strlen( (string) $a['data'] ) : 0
					);
				}
				Debug::write(
					'post-creator-entry',
					implode( "\n", array_merge(
						[
							sprintf( 'Queue #%d, artist #%s', $queue_id, (string) ( $row->artist_id ?? '?' ) ),
							sprintf( 'post_type=%s singleton=%s photo_only=%s', $post_type, $singleton ? 'yes' : 'no', $photo_only ? 'yes' : 'no' ),
							sprintf( 'description length=%d', strlen( (string) ( $submission['description'] ?? '' ) ) ),
							sprintf( 'Attachments in decoded submission: %d', count( $dbg_atts ) ),
						],
						$dbg_atts
					) )
				);
			}

			// ---- Special handlers (no AI pipeline) ------------------------------

			if ( 'agnosis_remove' === $post_type ) {
				// fifth audit §2b: a title that matches nothing used to be marked
				// 'published' — misleading in the Inbox admin table for a request
				// that did nothing — and the artist got no email at all. The queue
				// row now honestly reflects what happened, and
				// handle_removal_request() always fires an artist-facing action
				// (a removal confirmation on match, a "not found" feedback email
				// with title suggestions otherwise — see Notification).
				$found = $this->handle_removal_request( $submission, (int) $row->artist_id, $queue_id );
				$this->mark(
					$queue_id,
					$found ? 'published' : 'skipped',
					$found ? '' : sprintf( 'Skipped: remove@ found no artwork or event titled "%s" for this artist — feedback email sent.', trim( (string) $submission['subject'] ) )
				);
				return;
			}

			if ( 'agnosis_promote' === $post_type ) {
				// fifth audit §2b: same truthful-status treatment as remove@ above,
				// plus promote@ now also gets a *success* confirmation email — it
				// previously had no feedback in either direction.
				$found = $this->handle_promotion_request( $submission, (int) $row->artist_id, $queue_id );
				$this->mark(
					$queue_id,
					$found ? 'published' : 'skipped',
					$found ? '' : sprintf( 'Skipped: promote@ found no published artwork titled "%s" for this artist — feedback email sent.', trim( (string) $submission['subject'] ) )
				);
				return;
			}

			// ---- pure@ with no attachment: generate a text-poster image --------
			// Poetry (or any text-only submission) is still art — pure@ no
			// longer requires a photo (Email\Parser::parse_imap_message()/
			// parse_webhook_payload() now let a text-only submission through).
			// agnosis_artwork still needs SOME visual for the gallery, though,
			// so when the artist sent text with no photo, generate one:
			// TextPosterGenerator renders the submission's own words as a
			// poster (see that class's docblock for the "edge-to-edge
			// overflow" design) and this injects it as a synthetic attachment
			// — same shape Parser itself would have produced — so the rest of
			// this method (binary loading below, Pipeline::process_raw(),
			// merge_gallery()) treats it exactly like a normally-submitted photo.
			if ( $pure && empty( $submission['attachments'] ) ) {
				// 2026-07-21: TextPosterGenerator now resolves subject-vs-body
				// priority itself (title-first, body-excerpt fallback — see
				// its own extract_lines() docblock) — no need to pre-resolve
				// a single $poster_source here any more.
				$poster_blob = TextPosterGenerator::generate(
					(string) $submission['subject'],
					(string) ( $submission['description'] ?? '' )
				);

				if ( null !== $poster_blob ) {
					$submission['attachments'][] = [
						'filename' => 'pure-poster-' . uniqid() . '.png',
						'mime'     => 'image/png',
						'data'     => $poster_blob,
					];
					Logger::info( sprintf( 'Queue #%d: pure@ submission had no photo — generated a text-poster image from the artist\'s own words.', $queue_id ), 'publisher' );
				} else {
					Logger::warning( sprintf( 'Queue #%d: pure@ submission had no photo and poster generation failed (Imagick/font unavailable, or an error) — publishing text-only.', $queue_id ), 'publisher' );
				}
			}

			// ---- Load attachment binaries ---------------------------------------
			// New path: binary was written to uploads/agnosis-queue/{uid}/ at
			// ingest time; read it back now and remove the temp file reference.
			// Legacy path (rows enqueued before this change): binary is still
			// base64-encoded inline — decode it for backwards compatibility.
			//
			// A missing/unreadable temp file used to leave $att with no 'data'
			// key at all — silently `null` once MediaAdapter/Pipeline read it —
			// which threw an opaque `Pipeline::process_single(): Argument #1
			// ($image_data) must be of type string, null given` deep inside the
			// AI layer, with nothing in the log pointing back at the actual
			// cause (a file that vanished or was never readable from THIS
			// process — e.g. two web nodes with non-shared /wp-content/uploads,
			// or the orphan-sweep cron racing a slow queue). The attachment is
			// now dropped with a clear, specific error logged at the point of
			// failure instead — the submission still processes on whatever
			// text/other attachments it has, rather than the whole queue row
			// dying on a low-level type error that gives no indication why.
			if ( ! empty( $submission['attachments'] ) ) {
				foreach ( $submission['attachments'] as $i => &$att ) {
					if ( isset( $att['file'] ) ) {
						$binary = $this->filesystem()->get_contents( $att['file'] );

						if ( false !== $binary ) {
							$att['data'] = $binary;
						} else {
							Logger::error(
								sprintf(
									'Queue #%d: could not read attachment #%d binary from "%s" — file missing or unreadable from this process. Dropping this attachment; the submission continues without it.',
									$queue_id,
									$i,
									$att['file']
								),
								'publisher'
							);
						}
						unset( $att['file'] );
					} elseif ( ( $att['encoding'] ?? '' ) === 'base64' && isset( $att['data'] ) ) {
						$att['data'] = base64_decode( $att['data'] );
						unset( $att['encoding'] );
					}
				}
				unset( $att );

				// Drop any attachment that still has no usable binary after the
				// loop above (the failed-read case just logged, or a malformed
				// entry with neither a file reference nor base64 data to begin
				// with) — re-index so downstream code sees a clean, gap-free list.
				$submission['attachments'] = array_values( array_filter(
					$submission['attachments'],
					static fn( array $att ): bool => ! empty( $att['data'] ) && is_string( $att['data'] )
				) );
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
						$pure ? ' (pure — no AI at all)' : ( $photo_only ? ' (photo-only — enhancement skipped)' : '' )
					),
					'publisher'
				);
				$results = $pure
					? $this->pipeline->process_raw( $submission )
					: $this->pipeline->process( $submission, $photo_only );
				foreach ( $results as $i => $r ) {
					if ( $r['description_ok'] ) {
						// A secondary gallery image described via the slim
						// AI\ProviderInterface::describe_secondary() pass (fifth
						// audit §4c) always has title === '' — its alt text is
						// the one AI-generated string it actually carries, so
						// log that instead of an empty pair of quotes.
						Logger::info( sprintf( 'Queue #%d: attachment %d described — "%s".', $queue_id, $i + 1, $r['title'] ?: ( $r['alt_text'] ?? '' ) ), 'publisher' );
					} else {
						Logger::warning( sprintf( 'Queue #%d: attachment %d description failed — %s', $queue_id, $i + 1, $r['error'] ?? 'unknown' ), 'publisher' );
					}
				}

				// The submission arrived with attachment(s), but MediaAdapter
				// couldn't turn any of them into something the pipeline can use —
				// e.g. a HEIC/HEIF photo on a server whose ImageMagick build lacks
				// the libheif delegate (see MediaAdapter::adapt_heic()), or a PDF
				// with Imagick unavailable at all. Without this guard, an artwork
				// submission would silently continue into create_post() with an
				// empty gallery and no AI-generated content — a bare draft with no
				// image and no explanation. Reuse the same "no usable attachment"
				// notification the intake gate fires for an unrecognized/empty
				// attachment, since the artist-facing outcome is identical: nothing
				// was published, and they should resend.
				//
				// Scoped to agnosis_artwork only, same as the quality gate below —
				// an artwork submission's whole point is the image(s), but a
				// biography/event email's attachment is supplementary; its body
				// text is still perfectly publishable on its own even if an
				// accompanying photo failed to convert.
				if ( 'agnosis_artwork' === $post_type && empty( $results ) ) {
					Logger::warning(
						sprintf(
							'Queue #%d: all %d attachment(s) failed to convert/process — nothing to publish.',
							$queue_id, $attach_count
						),
						'publisher'
					);

					do_action( 'agnosis_submission_no_attachment', (int) $row->artist_id, $row->message_uid );

					$this->mark( $queue_id, 'failed', 'Rejected: none of the submitted file(s) could be processed (unsupported or corrupt format). Artist notified.' );
					return;
				}
			} else {
				$results = []; // Biography/event emails may have no attachments.

				// Defensive backstop: a genuinely new agnosis_artwork submission can
				// never reach this branch with zero attachments AND no text — Parser
				// itself refuses to enqueue an attachment-less email in the first
				// place (parse_imap_message()/parse_webhook_payload() return null).
				// The one way this shape CAN occur is a stale/resurrected queue row:
				// is_already_queued() auto-retries a 'failed' row, and a row recorded
				// by Inbox::mark_no_artwork() (unregistered sender, not admitted,
				// throttled, etc.) stores raw_email = '{}' — no attachments, no
				// description, nothing. (as of 2026-07-07 those specific rows are no
				// longer auto-retried either, but this guard stays as a second,
				// independent line of defense rather than relying on that alone.)
				// Silent — no artist notification here, unlike the guard above: the
				// original intake skip already told the artist their email didn't go
				// through, if that's genuinely what happened. Firing it again here
				// would just be a duplicate.
				if ( 'agnosis_artwork' === $post_type
					&& '' === trim( (string) ( $submission['description'] ?? '' ) )
				) {
					Logger::warning(
						sprintf( 'Queue #%d: empty artwork submission (no attachments, no text) — nothing to publish.', $queue_id ),
						'publisher'
					);
					$this->mark( $queue_id, 'failed', 'Rejected: empty submission — nothing to publish.' );
					return;
				}
			}

			// ---- Quality rejection gate -----------------------------------------
			// Only applies to artwork submissions with actual pipeline results.
			// Score 0 means the provider could not assess quality — never reject.
			// Rejection threshold must be > 0 (setting to 0 disables the gate).
			// photo_only submissions bypass this gate entirely: a deliberately low-fi
			// or stylised photograph is an artistic choice, not a defect.
			//
			// Evaluated per attachment, not just the first: a gallery submission
			// (several images in one email) previously lived or died on
			// $results[0] alone — a bad first photo rejected otherwise-fine images
			// sitting right behind it, and a bad 2nd+ photo was never checked at
			// all and got published unreviewed. Each image is now judged on its
			// own merits: individually-failing images are dropped from the
			// gallery and only the survivors are published; the whole submission
			// is rejected only when every image fails.
			if ( 'agnosis_artwork' === $post_type && ! empty( $results ) && ! $photo_only && ! $pure ) {
				$reject_below = (int) get_option( 'agnosis_quality_rejection_threshold', 3 );

				if ( $reject_below > 0 ) {
					[ $kept, $rejected ] = $this->apply_quality_gate( $results, $reject_below );

					foreach ( $rejected as $r ) {
						Logger::warning(
							sprintf(
								'Queue #%d: attachment %d quality score %d ≤ rejection threshold %d — dropped from gallery.',
								$queue_id, $r['index'] + 1, $r['score'], $reject_below
							),
							'publisher'
						);
					}

					if ( empty( $kept ) ) {
						// Every image failed — nothing left to publish, reject the whole submission.
						$worst = $rejected[0];
						foreach ( $rejected as $r ) {
							if ( $r['score'] < $worst['score'] ) {
								$worst = $r;
							}
						}
						$all_issues = array_values( array_unique( array_merge( ...array_column( $rejected, 'issues' ) ) ) );

						Logger::warning(
							sprintf(
								'Queue #%d: all %d attachment(s) failed quality (worst score %d ≤ threshold %d) — rejecting submission.',
								$queue_id, count( $rejected ), $worst['score'], $reject_below
							),
							'publisher'
						);

						/**
						 * Fires when a submission is automatically rejected due to low image quality.
						 *
						 * @param int      $queue_id  Queue row ID.
						 * @param int      $artist_id WordPress user ID of the submitting artist.
						 * @param int      $score     Detected quality score (1–10) — the worst among the rejected images.
						 * @param string[] $issues    Array of human-readable issue labels from the AI, merged across every rejected image.
						 */
						do_action( 'agnosis_submission_rejected', $queue_id, (int) $row->artist_id, $worst['score'], $all_issues );

						$this->mark( $queue_id, 'failed', sprintf(
							'Rejected: all %d image(s) scored at or below the rejection threshold (%d). Artist notified.',
							count( $rejected ), $reject_below
						) );
						return;
					}

					// Continue with only the images that passed — this is what
					// primary_result()/merge_gallery()/write_post_meta() below will see.
					$results = $kept;
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
			// For event posts, ask the AI to pull the location, address, event
			// date, and timezone out of the email so the agnosis/event-location,
			// agnosis/event-date, and agnosis/event-address blocks have data
			// without admin entry. address/timezone added 2026-07-10 (see
			// Pipeline::extract_event_fields()'s docblock) — both also editable
			// on the approve confirm form (ReviewConfirm) before publish.
			if ( 'agnosis_event' === $post_type ) {
				$event_fields                   = $this->pipeline->extract_event_fields( $submission );
				$submission['_event_location']  = $event_fields['location'];
				$submission['_event_address']   = $event_fields['address'];
				$submission['_event_date']      = $event_fields['event_date'];
				$submission['_event_timezone']  = $event_fields['timezone'];
				if ( $event_fields['location'] ) {
					Logger::info( sprintf( 'Queue #%d: event location extracted — "%s".', $queue_id, $event_fields['location'] ), 'publisher' );
				}
				if ( $event_fields['event_date'] ) {
					Logger::info( sprintf( 'Queue #%d: event date extracted — "%s".', $queue_id, $event_fields['event_date'] ), 'publisher' );
				}
			}

			// ---- Duplicate / singleton resolution -------------------------------
			if ( $is_replace ) {
				// Explicit replacement: skip AI fuzzy detection entirely.
				// $merge_into, $post_type, $photo_only, and $pure were already
				// resolved earlier — before the AI pipeline ran — precisely so the
				// pipeline call above could reuse the matched post's original
				// intake strategy. Nothing left to do here except drop $singleton.
				$singleton = false;
			} elseif ( 'agnosis_event' === $post_type ) {
				// 2026-07-06: an artist can now have several events, so a new
				// [Event] email no longer blindly merges into "the" event (there
				// isn't just one). Instead it mirrors replace@: if the subject
				// exactly matches an existing event's title, that event is
				// updated in place (same address, no separate "replace" step);
				// otherwise a new event post is created. The MERGE decision
				// itself stays plain-title-match only — no AI fuzzy detection,
				// that's tuned for artwork photo/description matching and
				// doesn't apply here. $singleton stays true for this type
				// (still gates AI polish above) — only the merge decision changes.
				$merge_into = $this->find_post_by_subject( $submission['subject'], (int) $row->artist_id, 'agnosis_event' );
				if ( $merge_into ) {
					Logger::info( sprintf( 'Queue #%d: event@ — subject matches existing event #%d, updating in place.', $queue_id, $merge_into ), 'publisher' );
				} else {
					Logger::info( sprintf( 'Queue #%d: event@ — no title match, creating new event.', $queue_id ), 'publisher' );

					// audit §2a: same silent-duplicate risk as replace@ above — a
					// miss here still creates a new event (unchanged), but now
					// carries a fuzzy suggestion through to the review email.
					// Scoped to the artist's OTHER events only, not artwork —
					// an event update wouldn't plausibly mean an artwork title.
					$miss_context = $this->gather_title_context(
						$submission['subject'],
						(int) $row->artist_id,
						'agnosis_event',
						[ 'draft', 'pending', 'publish' ]
					);
					if ( '' !== $miss_context['suggestion_title'] ) {
						$this->pending_merge_miss_suggestion = [ 'type' => 'event_update', 'title' => $miss_context['suggestion_title'] ];
					}
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

			$intake_endpoint = $pure ? self::ENDPOINT_PURE : ( $photo_only ? self::ENDPOINT_PHOTO : self::ENDPOINT_ARTWORK );
			$post_id         = $this->create_post( $submission, $results, (int) $row->artist_id, $queue_id, $merge_into, $post_type, $original_title, $intake_endpoint );

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
	 *   1. Recipient address — every To:/Cc: recipient (fifth audit §5a; see
	 *      'to_addresses' in the submission array), matched case-insensitively
	 *      against the configured routing addresses.
	 *   2. Subject-line [Indicator] prefix — backward-compatible fallback for artists
	 *      who already use the bracket syntax or whose mail client doesn't set To:.
	 *   3. Default: agnosis_artwork.
	 *
	/**
	 * Resolve the post type, singleton flag, cleaned subject, photo-only flag,
	 * and pure flag from the submission's To: address and subject line.
	 *
	 * Returns a five-element array: [post_type, singleton, clean_subject, photo_only, pure].
	 *
	 * photo_only = true means the submission came via photo@ or [Photo] (or pure@/[Pure]):
	 *   - AI enhancement is skipped entirely (no API call, no image mutation).
	 *   - Quality rejection gate is bypassed (a deliberately low-fi image is not a defect).
	 *   - AI description (title, excerpt, tags, alt text) still runs normally — UNLESS
	 *     $pure is also true, in which case no AI runs at all (see below).
	 *   - The original binary is published as-is.
	 *
	 * pure = true means the submission came via pure@ or [Pure] — a strictly stronger
	 * lane than photo_only:
	 *   - No AI call of any kind runs (no describe(), no enhance(), no translate()).
	 *   - Title/excerpt/body/tags/alt text are taken directly from the artist's own
	 *     subject and message text (see Pipeline::process_raw()).
	 *   - Implies photo_only (no enhancement, no quality gate) as a subset.
	 *
	 * @param array<string, mixed> $submission
	 * @return array{0: string, 1: bool, 2: string, 3: bool, 4: bool}
	 */
	private function resolve_post_type( array $submission ): array {
		// 2026-07-09 (fifth audit §5a): match against every To:/Cc: recipient,
		// not just the first To: address. Previously an artist writing to
		// `remove@` while CCing a friend (or whose mail client serialised To:
		// in an unexpected order) routed on whichever single address Parser
		// happened to capture — the message fell through to the plain artwork
		// pipeline and bounced a confusing "no attachment" reply for what was
		// actually a management request. Falls back to the single legacy
		// 'to_address' when 'to_addresses' isn't present (older queued rows,
		// and test doubles that only ever set the singular key).
		$addresses = array_map(
			static fn( $a ) => strtolower( trim( (string) $a ) ),
			(array) ( $submission['to_addresses'] ?? [ $submission['to_address'] ?? '' ] )
		);
		$addresses = array_values( array_filter( $addresses ) );
		$subject   = (string) ( $submission['subject'] ?? '' );

		if ( ! empty( $addresses ) ) {
			$bio_addr     = strtolower( trim( (string) get_option( 'agnosis_email_bio',     '' ) ) );
			$event_addr   = strtolower( trim( (string) get_option( 'agnosis_email_event',   '' ) ) );
			$replace_addr = strtolower( trim( (string) get_option( 'agnosis_email_replace', '' ) ) );
			$remove_addr  = strtolower( trim( (string) get_option( 'agnosis_email_remove',  '' ) ) );
			$promote_addr = strtolower( trim( (string) get_option( 'agnosis_email_promote', '' ) ) );
			$photo_addr   = strtolower( trim( (string) get_option( 'agnosis_email_photo',   '' ) ) );
			$pure_addr    = strtolower( trim( (string) get_option( 'agnosis_email_pure',    '' ) ) );

			if ( $bio_addr && in_array( $bio_addr, $addresses, true ) ) {
				return [ 'agnosis_biography', true, $subject, false, false ];
			}
			if ( $event_addr && in_array( $event_addr, $addresses, true ) ) {
				return [ 'agnosis_event', true, $subject, false, false ];
			}
			// Pure lane: zero AI — checked before photo@ since both match on the
			// same shape (agnosis_artwork, non-singleton) and pure@ is the more
			// specific/stronger of the two.
			if ( $pure_addr && in_array( $pure_addr, $addresses, true ) ) {
				return [ 'agnosis_artwork', false, $subject, true, true ];
			}
			// Photo-only lane: AI description + no enhancement + no quality rejection.
			if ( $photo_addr && in_array( $photo_addr, $addresses, true ) ) {
				return [ 'agnosis_artwork', false, $subject, true, false ];
			}
			// Pseudo-types — handled specially in handle() before create_post() is called.
			if ( $replace_addr && in_array( $replace_addr, $addresses, true ) ) {
				return [ 'agnosis_replace', false, $subject, false, false ];
			}
			if ( $remove_addr && in_array( $remove_addr, $addresses, true ) ) {
				return [ 'agnosis_remove', false, $subject, false, false ];
			}
			if ( $promote_addr && in_array( $promote_addr, $addresses, true ) ) {
				return [ 'agnosis_promote', false, $subject, false, false ];
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
	 * @return array{0: string, 1: bool, 2: string, 3: bool, 4: bool} [post_type, is_singleton, clean_subject, photo_only, pure]
	 */
	private function resolve_indicator( string $subject ): array {
		if ( preg_match( '/^\[([^\]]+)\]\s*/u', $subject, $m ) ) {
			// mb_strtolower() (not strtolower()) — strtolower() only lowercases
			// ASCII A-Z and silently leaves multibyte UTF-8 characters like the
			// 'Í' in "[BIOGRAFÍA]" untouched, so an uppercased accented keyword
			// would never match any of the lowercase-accented INDICATORS keys
			// this same fix added (e.g. 'biografía').
			$keyword   = mb_strtolower( trim( $m[1] ), 'UTF-8' );
			$clean     = substr( $subject, strlen( $m[0] ) );
			$indicator = self::INDICATORS[ $keyword ] ?? null;
			if ( $indicator ) {
				return [
					$indicator['post_type'],
					$indicator['singleton'],
					$clean,
					$indicator['photo_only'],
					$indicator['pure'],
				];
			}
		}
		return [ 'agnosis_artwork', false, $subject, false, false ];
	}

	/**
	 * Human-readable label for which email endpoint routed a submission —
	 * powers the Inbox admin table's Endpoint column (patch 18). Mirrors
	 * resolve_post_type()'s own address-first, subject-indicator-fallback
	 * order exactly, but returns a display string instead of the five-tuple
	 * used to actually create the post, so a queue row's raw_email JSON can
	 * be labelled directly — no Pipeline/Admission instantiation needed, and
	 * it works identically for 'failed' rows that never reached create_post().
	 *
	 * 2026-07-14: also recognises the goodbye@/community@ aliases (previously
	 * unrecognised here even though Inbox.php has always routed them
	 * specially, silently falling through to the generic "Artwork" default
	 * below), and — crucially — no longer returns "Artwork" as a guess when
	 * the row carries no recipient/subject context to go on at all. Every
	 * `Inbox::mark_no_artwork()` call site now stashes subject/to_addresses
	 * whenever it has them (see that method's own docblock), so an empty
	 * `$submission` here should only ever happen for a row from before that
	 * fix landed — "Artwork" previously asserted a specific, often wrong,
	 * classification for a message this code never actually looked at.
	 *
	 * @param array<string, mixed> $submission Raw email submission (as stored
	 *                                          in agnosis_queue.raw_email).
	 * @return string One of: Biography, Event, Pure, Photo, Replace, Remove,
	 *                 Promote, Goodbye, Community, Artwork (the same default
	 *                 resolve_post_type() falls back to when neither a
	 *                 recipient address nor a recognised subject indicator
	 *                 matches — only returned here when the row actually
	 *                 carries that context), or Unknown when the row has no
	 *                 recipient address and no subject to classify at all.
	 */
	public static function resolve_endpoint_label( array $submission ): string {
		$addresses = array_map(
			static fn( $a ) => strtolower( trim( (string) $a ) ),
			(array) ( $submission['to_addresses'] ?? [ $submission['to_address'] ?? '' ] )
		);
		$addresses = array_values( array_filter( $addresses ) );
		$subject   = (string) ( $submission['subject'] ?? '' );

		// No recipient address AND no subject were ever captured for this row
		// — there is nothing here to classify by, so say so plainly rather
		// than asserting the "Artwork" default as if it were a real finding.
		if ( empty( $addresses ) && '' === $subject ) {
			return __( 'Unknown', 'agnosis' );
		}

		if ( ! empty( $addresses ) ) {
			// Same option keys, same order, as resolve_post_type() above —
			// pure@ before photo@ since both match the same post shape and
			// pure@ is the more specific of the two. Goodbye/Community are
			// routed entirely outside resolve_post_type() (Inbox.php intercepts
			// both before the normal pipeline ever runs), but belong here too —
			// this label just describes which address a message was sent to.
			$routes = [
				'agnosis_email_bio'       => __( 'Biography', 'agnosis' ),
				'agnosis_email_event'     => __( 'Event', 'agnosis' ),
				'agnosis_email_pure'      => __( 'Pure', 'agnosis' ),
				'agnosis_email_photo'     => __( 'Photo', 'agnosis' ),
				'agnosis_email_replace'   => __( 'Replace', 'agnosis' ),
				'agnosis_email_remove'    => __( 'Remove', 'agnosis' ),
				'agnosis_email_promote'   => __( 'Promote', 'agnosis' ),
				'agnosis_email_goodbye'   => __( 'Goodbye', 'agnosis' ),
				'agnosis_email_community' => __( 'Community', 'agnosis' ),
			];
			foreach ( $routes as $option => $label ) {
				$addr = strtolower( trim( (string) get_option( $option, '' ) ) );
				if ( $addr && in_array( $addr, $addresses, true ) ) {
					return $label;
				}
			}
		}

		// Fallback: subject-line [Indicator] prefix — same INDICATORS table
		// resolve_indicator() uses.
		if ( preg_match( '/^\[([^\]]+)\]\s*/u', $subject, $m ) ) {
			$keyword   = mb_strtolower( trim( $m[1] ), 'UTF-8' );
			$indicator = self::INDICATORS[ $keyword ] ?? null;
			if ( $indicator ) {
				if ( $indicator['pure'] ) {
					return __( 'Pure', 'agnosis' );
				}
				if ( $indicator['photo_only'] ) {
					return __( 'Photo', 'agnosis' );
				}
				if ( 'agnosis_biography' === $indicator['post_type'] ) {
					return __( 'Biography', 'agnosis' );
				}
				if ( 'agnosis_event' === $indicator['post_type'] ) {
					return __( 'Event', 'agnosis' );
				}
			}
		}

		// A recipient address and/or subject WAS captured, it just didn't
		// match a special route or subject indicator above — this is the
		// same default resolve_post_type() itself would land on given the
		// same input, so "Artwork" here is a real classification, not a guess.
		return __( 'Artwork', 'agnosis' );
	}

	/**
	 * Meta-query fragment restricting a merge-target lookup to the PRIMARY/
	 * source-language version of a post — never one of Lingua Forge's
	 * translated siblings.
	 *
	 * Root cause this guards against: every "find the existing post to merge
	 * into" lookup in this class (find_singleton_post(), find_duplicate_post()'s
	 * exact-subject-match layer, find_post_by_subject()) queries by post_type +
	 * author only, with no language scoping. Lingua Forge translates a
	 * published biography/artwork/event into a SEPARATE post per configured
	 * language, all sharing the SAME post_author — so once translations exist,
	 * a plain author+post_type query can return ANY language sibling, not
	 * necessarily the true source post, since get_posts()'s default date-DESC
	 * ordering has no reason to prefer the source over a sibling that happens
	 * to have a later post_date (e.g. one re-translated more recently). A merge
	 * whose target resolves to a translated sibling instead of the source post
	 * silently applies the artist's update to a page almost nobody visits by
	 * default, while the source (and every OTHER language) stays stale
	 * forever — confirmed live: an update meant for a Catalan-speaking artist's
	 * biography landed on its Urdu translation; the Catalan source and English
	 * sibling never changed.
	 *
	 * `Compat\LinguaForge::set_language_meta()` tags a post with `_lf_lang`
	 * (the PRIMARY language, since Agnosis content is always normalised to
	 * primary at intake) the first time it's published via
	 * 'agnosis_post_published' — a translated sibling instead carries ITS OWN
	 * target language in that same meta key (Lingua Forge's own translation-
	 * creation code sets it when the sibling is born). Filtering to "no
	 * `_lf_lang` at all, OR `_lf_lang` equals the primary language" therefore
	 * matches the source post and excludes every translated sibling, while
	 * staying a complete no-op on a site where Lingua Forge isn't active (no
	 * post ever has this meta key at all, so every post keeps matching the
	 * first branch exactly as before this fix existed).
	 *
	 * @return array<string, mixed> A `get_posts()`/`WP_Query` args fragment —
	 *                               empty when Lingua Forge isn't active.
	 */
	private function primary_language_meta_query(): array {
		if ( ! LinguaForge::is_active() ) {
			return [];
		}

		$primary_lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		if ( '' === $primary_lang ) {
			$primary_lang = LinguaForge::locale_to_lang( get_locale() );
		}

		return [
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- author+post_type-scoped, posts_per_page capped at every call site; no realistic row count here.
				'relation' => 'OR',
				[
					'key'     => '_lf_lang',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'   => '_lf_lang',
					'value' => $primary_lang,
				],
			],
		];
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
	 * Patch 18 ("true staging"): once the artist's biography is published,
	 * an update to it is never written directly onto the live post —
	 * create_post() instead creates (or reuses) a separate draft "staging"
	 * post, tagged with '_agnosis_pending_update_for' pointing at the live
	 * post, and only ReviewEndpoints applies it, on approval (see that
	 * class's finalize_publish()). So there can now be up to TWO posts of
	 * this type for the same artist at once: the live one, and a pending
	 * staging draft for an update to it that hasn't been approved yet. A
	 * second resubmission before the first is approved must merge into that
	 * SAME staging draft — not create a competing second one, and not touch
	 * the still-published live post — hence checking for one explicitly
	 * FIRST, rather than trusting get_posts()'s default date ordering to
	 * happen to prefer it.
	 *
	 * @param string $post_type CPT slug (e.g. 'agnosis_biography').
	 * @param int    $artist_id WordPress user ID.
	 * @param int    $queue_id  Current queue row (used only for logging).
	 */
	private function find_singleton_post( string $post_type, int $artist_id, int $queue_id ): int {
		if ( ! $artist_id ) {
			return 0;
		}

		// 'fields' => 'ids' guarantees an int[] at runtime; phpstan can't see
		// through the array_merge() to confirm it statically, hence the assert.
		/** @var array<int, int> $staging */
		$staging = get_posts( array_merge( [
			'post_type'      => $post_type,
			'author'         => $artist_id,
			'post_status'    => 'draft',
			'meta_key'       => '_agnosis_pending_update_for',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		], $this->primary_language_meta_query() ) );
		if ( ! empty( $staging ) ) {
			$post_id = (int) $staging[0];
			Logger::info(
				sprintf( 'Queue #%d: singleton %s — merging into existing pending update #%d.', $queue_id, $post_type, $post_id ),
				'publisher'
			);
			return $post_id;
		}

		// Same phpstan reasoning as $staging above.
		/** @var array<int, int> $existing */
		$existing = get_posts( array_merge( [
			'post_type'      => $post_type,
			'author'         => $artist_id,
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		], $this->primary_language_meta_query() ) );
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
			// 'fields' => 'ids' guarantees an int[] at runtime; phpstan can't
			// see through the array_merge() to confirm it statically, hence the assert.
			/** @var array<int, int> $exact */
			$exact = get_posts( array_merge( [
				'post_type'      => 'agnosis_artwork',
				'author'         => $artist_id,
				'title'          => $subject,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			], $this->primary_language_meta_query() ) );
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
		// 'fields' => 'ids' guarantees an int[] at runtime; phpstan can't see
		// through the array_merge() to confirm it statically, hence the assert.
		/** @var array<int, int> $matches */
		$matches = get_posts( array_merge( [
			'post_type'      => $post_type,
			'author'         => $artist_id,
			'title'          => $subject,
			'post_status'    => [ 'draft', 'pending', 'publish' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		], $this->primary_language_meta_query() ) );
		return ! empty( $matches ) ? (int) $matches[0] : 0;
	}

	/**
	 * Find EVERY post — across every eligible type, not just the first hit —
	 * matching an exact title for a given artist, collapsed to at most ONE
	 * match per post type (2026-07-16).
	 *
	 * Used by remove@ (handle_removal_request()): an artist's artwork and
	 * event titles aren't required to be unique from each other (nothing
	 * enforces that — they're two separate post types, each free to reuse
	 * whatever title the artist likes), so a subject that happens to match
	 * both an artwork and an event by the same name is a real, if uncommon,
	 * case, worth offering the artist a choice between — not a bug to
	 * collapse silently onto whichever one find_post_by_subject() happens to
	 * return first. Every other caller of the singular find_post_by_subject()
	 * (replace@, event@ resend-in-place) intentionally wants exactly one best
	 * match and is unaffected by this method's existence.
	 *
	 * Collapsing to one match per TYPE (2026-07-16) closes a real confusion:
	 * two rows of the SAME post type matching an exact title is virtually
	 * never "the artist genuinely has two different artworks with the exact
	 * same title" — far more often it's an artefact (a stale/abandoned draft
	 * from an earlier attempt, or a data quirk this scoping doesn't fully
	 * catch) that has no business being offered as a distinct, equally-valid
	 * "which one did you mean?" choice next to the artist's real work. The
	 * "different CPTs sharing a title" case above is the only scenario this
	 * method's multi-match design is actually for; two same-type rows are
	 * reduced to the single one this query's own stable `orderby` ranks
	 * first, exactly like find_post_by_subject() (singular) already would if
	 * it were only ever asked about that one type.
	 *
	 * Same primary-language-only scoping as find_post_by_subject() — a
	 * translated sibling is never an independent match target; removing the
	 * primary post cascades to its siblings via
	 * RemovalEndpoints::trash_post_and_translations().
	 *
	 * @param string          $subject   Post title to match.
	 * @param int             $artist_id WordPress user ID.
	 * @param string|string[] $post_type CPT slug, or an array of slugs to search across.
	 * @return int[] Matching post IDs, at most one per distinct post type (empty array if none).
	 */
	private function find_posts_by_subject( string $subject, int $artist_id, string|array $post_type = 'agnosis_artwork' ): array {
		if ( ! $subject || ! $artist_id ) {
			return [];
		}
		// 'fields' => 'ids' guarantees an int[] at runtime; phpstan can't see
		// through the array_merge() to confirm it statically, hence the assert.
		/** @var array<int, int> $matches */
		$matches = get_posts( array_merge( [
			'post_type'      => $post_type,
			'author'         => $artist_id,
			'title'          => $subject,
			'post_status'    => [ 'draft', 'pending', 'publish' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'type title', // stable, deterministic ordering — also what "first per type wins" below relies on.
			'order'          => 'ASC',
		], $this->primary_language_meta_query() ) );

		// One match per post type — see this method's own docblock. The
		// 'type title' ASC ordering above already groups same-type rows
		// together, so the first ID encountered for a given type is kept and
		// every subsequent same-type row is dropped.
		$first_per_type = [];
		foreach ( $matches as $post_id ) {
			$type = (string) get_post_type( (int) $post_id );
			if ( ! isset( $first_per_type[ $type ] ) ) {
				$first_per_type[ $type ] = (int) $post_id;
			}
		}

		return array_values( $first_per_type );
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
	 * fifth audit §2b/§2c: on no exact match, this used to log a warning and
	 * silently return — the artist got no email, and the queue row was marked
	 * 'published' by the caller regardless. It now fires
	 * 'agnosis_removal_target_not_found' with the artist's current titles and
	 * (§2c) a fuzzy-matched suggestion — reusing find_duplicate_post()'s
	 * AI-comparison pattern — so Notification can send a helpful "we couldn't
	 * find X; did you mean Y?" email. The fuzzy match is NEVER acted on
	 * directly; it only ever informs a suggestion in that email. The one
	 * exception: since remove@'s real consent step is the confirmation link
	 * click (not the email request itself), a removal token IS safely
	 * pre-generated for the suggested post — a wrong guess simply dies
	 * unclicked, exactly like any other removal request.
	 *
	 * 2026-07-14: an exact title can match MORE than one post — an artwork
	 * and an event are two independent post types, nothing stops an artist
	 * naming both the same thing. find_post_by_subject() (singular, still
	 * used by replace@/event@ resend-in-place, which each want exactly one
	 * best match) would have silently picked whichever one happened to sort
	 * first, removing a post the artist never named. This method now uses
	 * find_posts_by_subject() (plural) and branches on how many titles
	 * actually matched: one match keeps the exact single-post behavior
	 * above (same 'agnosis_removal_requested' action, same email); two or
	 * more instead pre-generates a token for EACH match and fires a new
	 * 'agnosis_removal_requested_multiple' action, so Notification can send
	 * one email listing every match with its own individual confirm
	 * link — the artist picks which one(s) they actually meant, rather than
	 * either of them being removed (or guessed at) automatically.
	 *
	 * @param array<string, mixed> $submission Parsed email submission.
	 * @param int                  $artist_id  WordPress user ID of the requesting artist.
	 * @param int                  $queue_id   Current queue row (for logging).
	 * @return bool True if at least one matching post was found and a removal confirmation queued.
	 */
	private function handle_removal_request( array $submission, int $artist_id, int $queue_id ): bool {
		$subject = trim( $submission['subject'] ?? '' );

		if ( ! $subject ) {
			Logger::warning( sprintf( 'Queue #%d: remove@ request has no subject — cannot identify post.', $queue_id ), 'publisher' );
			return false;
		}

		$post_ids = $this->find_posts_by_subject( $subject, $artist_id, [ 'agnosis_artwork', 'agnosis_event' ] );

		if ( empty( $post_ids ) ) {
			Logger::warning(
				sprintf( 'Queue #%d: remove@ — no artwork or event titled "%s" found for this artist.', $queue_id, $subject ),
				'publisher'
			);

			$context = $this->gather_title_context( $subject, $artist_id, [ 'agnosis_artwork', 'agnosis_event' ], [ 'draft', 'pending', 'publish' ] );

			$suggestion_token = '';
			if ( $context['suggestion_id'] ) {
				// Pre-generate a removal confirmation token for the fuzzy-matched
				// post (§2c) — the confirmation click is the real consent step, so
				// including a ready-to-use link in the feedback email is safe even
				// if the fuzzy guess is wrong: it simply dies unclicked.
				$suggestion_token = $this->generate_token();
				update_post_meta( $context['suggestion_id'], '_agnosis_removal_token', $suggestion_token );
				update_post_meta( $context['suggestion_id'], '_agnosis_removal_expiry', time() + ( 7 * DAY_IN_SECONDS ) );
				update_post_meta( $context['suggestion_id'], '_agnosis_removal_reason', sanitize_textarea_field( $submission['description'] ?? '' ) );
			}

			/**
			 * Fires when a remove@ request's subject matches no existing artwork
			 * or event for this artist.
			 *
			 * @param int      $artist_id        Requesting artist's user ID.
			 * @param string   $subject          The subject line that didn't match.
			 * @param string[] $titles           The artist's current artwork/event titles.
			 * @param int      $suggestion_id     Fuzzy-matched post ID, or 0 if none.
			 * @param string   $suggestion_title  Fuzzy-matched post title, or ''.
			 * @param string   $suggestion_token  Pre-generated removal token for the
			 *                                    suggested post, or '' if no suggestion.
			 */
			do_action(
				'agnosis_removal_target_not_found',
				$artist_id,
				$subject,
				$context['titles'],
				$context['suggestion_id'],
				$context['suggestion_title'],
				$suggestion_token
			);

			return false;
		}

		$reason = sanitize_textarea_field( $submission['description'] ?? '' );
		$expiry = time() + ( 7 * DAY_IN_SECONDS );

		if ( 1 === count( $post_ids ) ) {
			$post_id = $post_ids[0];

			// Generate a cryptographically random removal token.
			// The post is NOT moved or modified yet; the artist must confirm via email link.
			$token = $this->generate_token();

			update_post_meta( $post_id, '_agnosis_removal_token',  $token );
			update_post_meta( $post_id, '_agnosis_removal_expiry', $expiry );
			update_post_meta( $post_id, '_agnosis_removal_reason', $reason );

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

			return true;
		}

		// Two or more posts share this exact title — generate a token for
		// EACH one rather than guessing which the artist meant. Every token
		// is independently valid and single-use (RemovalEndpoints::confirm()
		// already operates per-post-id, unaware of how it got here), so
		// confirming one has no effect on the others.
		$matches = [];
		foreach ( $post_ids as $post_id ) {
			$token = $this->generate_token();

			update_post_meta( $post_id, '_agnosis_removal_token',  $token );
			update_post_meta( $post_id, '_agnosis_removal_expiry', $expiry );
			update_post_meta( $post_id, '_agnosis_removal_reason', $reason );

			$matches[] = [
				'id'    => $post_id,
				'type'  => (string) get_post_type( $post_id ),
				'token' => $token,
			];
		}

		Logger::info(
			sprintf(
				'Queue #%d: remove@ — %d posts titled "%s" for this artist; removal choice email queued.',
				$queue_id,
				count( $matches ),
				$subject
			),
			'publisher'
		);

		/**
		 * Fires when a remove@ request's subject exactly matches more than one
		 * of the artist's own posts (e.g. an artwork and an event sharing a
		 * title). A signed token has been stored on each — Notification sends
		 * one email listing every match with its own individual confirm link.
		 *
		 * @param array<int, array{id: int, type: string, token: string}> $matches
		 *                          Every matched post, each with its own removal token.
		 * @param int               $artist_id Requesting artist's user ID.
		 * @param string            $subject   The shared title all matches were found under.
		 */
		do_action( 'agnosis_removal_requested_multiple', $matches, $artist_id, $subject );

		return true;
	}

	/**
	 * Handle a promote@ request: find the published artwork by subject and mark
	 * it as the artist's featured piece in the gallery overview.
	 *
	 * Subject must exactly match the artwork's title.  Only published artworks
	 * are eligible — a draft cannot be featured until it has been approved.
	 *
	 * fifth audit §2b/§2c: promote@ used to have no artist feedback in either
	 * direction — a match silently flipped the featured meta, and a miss
	 * silently did nothing. Both now fire 'agnosis_promotion_result' so
	 * Notification can confirm success or, on a miss, suggest a likely title
	 * (§2c, same fuzzy-match-as-suggestion-only pattern as remove@ — promote@
	 * has no confirmation step to pre-generate a token for, so a miss here
	 * only ever informs the email text, never acts).
	 *
	 * @param array<string, mixed> $submission Parsed email submission.
	 * @param int                  $artist_id  WordPress user ID of the requesting artist.
	 * @param int                  $queue_id   Current queue row (for logging).
	 * @return bool True if a matching published artwork was found and featured.
	 */
	private function handle_promotion_request( array $submission, int $artist_id, int $queue_id ): bool {
		$subject = trim( $submission['subject'] ?? '' );

		if ( ! $subject ) {
			Logger::warning( sprintf( 'Queue #%d: promote@ request has no subject — cannot identify post.', $queue_id ), 'publisher' );
			return false;
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

			$context = $this->gather_title_context( $subject, $artist_id, 'agnosis_artwork', [ 'publish' ] );

			/**
			 * Fires after a promote@ request completes — success or failure.
			 *
			 * @param int      $artist_id        Requesting artist's user ID.
			 * @param string   $subject          The subject line that was matched (or not).
			 * @param bool     $found            Whether a matching published artwork was found.
			 * @param string[] $titles           The artist's current published artwork titles (only on failure).
			 * @param string   $suggestion_title Fuzzy-matched title suggestion, or '' (only on failure).
			 */
			do_action( 'agnosis_promotion_result', $artist_id, $subject, false, $context['titles'], $context['suggestion_title'] );

			return false;
		}

		$post_id = (int) $matches[0];
		$this->set_featured( $post_id, $artist_id );

		Logger::info(
			sprintf( 'Queue #%d: promote@ — artwork #%d "%s" is now featured.', $queue_id, $post_id, $subject ),
			'publisher'
		);

		do_action( 'agnosis_promotion_result', $artist_id, $subject, true, [], '' );

		return true;
	}

	/**
	 * Gather feedback-email context for a remove@/promote@ subject that
	 * didn't match anything exactly (fifth audit §2b/§2c): every current
	 * title the artist could have meant, plus (when the subject is non-empty)
	 * an AI fuzzy-match suggestion for which one they most likely did mean.
	 *
	 * Reuses the same cheap, text-only AI comparison pattern as
	 * find_duplicate_post()'s third (fuzzy) layer — allowing for typos,
	 * missing words, or reworded titles — but this method never acts on the
	 * result itself; callers only ever use it to inform an email. Costs at
	 * most one get_posts() call and, when the subject is non-empty and at
	 * least one candidate exists, one cheap chat() call.
	 *
	 * Primary-language-only scoping (2026-07-16 — via the same
	 * primary_language_meta_query() find_post_by_subject()/
	 * find_posts_by_subject() already use, never applied here before): titles
	 * are never translated for artwork/event (the dual-title system keeps
	 * post_title byte-identical across every Lingua Forge language sibling —
	 * only the separately-stored display title differs), so without this
	 * scoping every OTHER language version of the exact same work — the
	 * primary post, its native-language sibling, and every LF machine-
	 * translated sibling — surfaced as its own row here, each with the
	 * identical title. On a site with several configured languages this
	 * "current titles" list was less a list of the artist's distinct works
	 * and more the same handful of titles repeated once per language, at
	 * `posts_per_page => 20` frequently crowding out genuinely different
	 * titles entirely. This scoping collapses that back to one row per real
	 * work, matching find_posts_by_subject()'s identical fix for the exact-
	 * match path.
	 *
	 * @param string          $subject      Subject line that didn't match exactly.
	 * @param int             $artist_id    WordPress user ID.
	 * @param string|string[] $post_type    CPT slug, or an array of slugs to search across.
	 * @param string[]        $post_status  Post statuses to include as candidates.
	 * @return array{titles: string[], suggestion_id: int, suggestion_title: string}
	 */
	private function gather_title_context( string $subject, int $artist_id, string|array $post_type, array $post_status ): array {
		$empty = [ 'titles' => [], 'suggestion_id' => 0, 'suggestion_title' => '' ];

		if ( ! $artist_id ) {
			return $empty;
		}

		// 'fields' => 'ids' guarantees an int[] at runtime; phpstan can't see
		// through the array_merge() to confirm it statically, hence the assert
		// (same as find_post_by_subject()/find_posts_by_subject() above).
		/** @var array<int, int> $candidates */
		$candidates = get_posts( array_merge( [
			'post_type'      => $post_type,
			'author'         => $artist_id,
			'post_status'    => $post_status,
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		], $this->primary_language_meta_query() ) );

		$titles_map = [];
		foreach ( $candidates as $pid ) {
			$post = get_post( (int) $pid );
			if ( $post && '' !== trim( $post->post_title ) ) {
				$titles_map[ (int) $pid ] = $post->post_title;
			}
		}

		if ( empty( $titles_map ) ) {
			return $empty;
		}

		$suggestion_id = 0;
		if ( '' !== $subject ) {
			$lines = [];
			foreach ( $titles_map as $pid => $title ) {
				$lines[] = sprintf( 'Post #%d: "%s"', $pid, $title );
			}
			$prompt = sprintf(
				"An artist emailed a request referencing one of their own works by title: \"%s\"\n\n"
				. "Their current posts:\n%s\n\n"
				. 'Which post (if any) did they most likely mean — allowing for typos, missing words, or slightly different wording? '
				. 'Reply with ONLY the matching post ID number (e.g. "42"), or "0" if none is a plausible match. No explanation.',
				$subject,
				implode( "\n", $lines )
			);

			// Defensive: a fuzzy-match suggestion is a nice-to-have addition to
			// the feedback email, never a prerequisite for sending it. If the AI
			// call fails for any reason (provider misconfigured, network error),
			// fall back to no suggestion rather than losing the whole "here are
			// your current titles" email over it.
			try {
				$response      = $this->pipeline->chat( $prompt );
				$candidate_id  = (int) preg_replace( '/\D/', '', $response );
				$suggestion_id = isset( $titles_map[ $candidate_id ] ) ? $candidate_id : 0;
			} catch ( \Throwable $e ) {
				Logger::warning( 'gather_title_context(): fuzzy-match chat() call failed — ' . $e->getMessage(), 'publisher' );
				$suggestion_id = 0;
			}
		}

		return [
			'titles'           => array_values( $titles_map ),
			'suggestion_id'    => $suggestion_id,
			'suggestion_title' => $suggestion_id ? $titles_map[ $suggestion_id ] : '',
		];
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
	 * Patch 18 ("true staging"): if $merge_into_post is currently published,
	 * its content is never touched here — a separate draft "staging" post is
	 * inserted instead (tagged '_agnosis_pending_update_for' => the live
	 * post's ID), and the live post stays exactly as visitors see it until
	 * ReviewEndpoints::finalize_publish() applies the staging draft's fields
	 * onto it on approval. See the "True staging" block inside this method.
	 *
	 * @param array<string, mixed>             $submission       Parsed email submission data.
	 * @param array<int, array<string, mixed>> $results          AI pipeline results, one per attachment.
	 * @param int                              $artist_id        WordPress user ID of the submitting artist.
	 * @param int                              $queue_id         Queue row ID — stored in post meta for reverse lookup.
	 * @param int                              $merge_into_post  Post ID to update instead of inserting (0 = auto-detect) —
	 *                                                           redirected to a staging draft instead when already published.
	 * @param string                           $post_type        CPT slug (default: agnosis_artwork).
	 * @param string                           $intake_endpoint  Which address created this submission (ENDPOINT_* const) —
	 *                                                           written once to _agnosis_intake_endpoint on agnosis_artwork
	 *                                                           posts only, never overwritten on a later update/replace.
	 * @return int|\WP_Error Post ID on success (the staging draft's ID, when
	 *                       one was created — NOT the live post's), WP_Error on failure.
	 */
	private function create_post( array $submission, array $results, int $artist_id, int $queue_id = 0, int $merge_into_post = 0, string $post_type = 'agnosis_artwork', string $original_title = '', string $intake_endpoint = self::ENDPOINT_ARTWORK ): int|\WP_Error {
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

		// ---- True staging (patch 18) ------------------------------------------
		// A merge target that is already LIVE (published) is never written to
		// directly — not by this call, not ever, until the artist explicitly
		// approves the update. Doing so used to mean an update to already-
		// published content (a biography singleton merge, a replace@ resend, an
		// event resent to an existing title) minted a review token that could
		// never actually be approved: ReviewEndpoints::approve()/reject() both
		// hard-require 'draft' === $post->post_status, but the merge target's
		// status was deliberately preserved as 'publish' so the live page
		// wouldn't vanish while the update was pending — an irreconcilable
		// contradiction between "stays live" and "needs a working approve link"
		// that always resolved in favor of a permanently unredeemable token.
		//
		// Redirecting to $existing_id = 0 here means the rest of this method
		// takes its ordinary "brand new post" path below — inserting a genuine,
		// separate draft post with its own review token, completely unrelated
		// to the live one until approved. $stage_for is threaded through to
		// that insert so the new draft can be tagged with
		// '_agnosis_pending_update_for', the pointer
		// ReviewEndpoints::finalize_publish() reads to know it must copy this
		// draft's fields onto $stage_for (and delete this draft) instead of
		// publishing the draft as its own thing. The live post is completely
		// untouched — title, content, status, everything — for as long as the
		// update sits awaiting approval; nothing about it ever goes offline.
		//
		// find_singleton_post() already prefers an existing staging draft over
		// the live post for biography specifically, so $existing_id here is
		// only ever the LIVE post in that case — this redirect still applies to
		// it as the safety net for every other caller (replace@, event resend,
		// artwork duplicate detection) that has no staging-draft awareness of
		// its own.
		$stage_for = 0;
		if ( $existing_id && 'publish' === get_post_status( $existing_id ) ) {
			$stage_for   = $existing_id;
			$existing_id = 0;
		}

		// ---- Build content ----------------------------------------------------
		$primary  = $this->primary_result( $results );
		$all_tags = array_unique( array_merge( ...array_column( $results, 'tags' ) ) );

		// merge_gallery()'s "existing" gallery must come from whichever post
		// actually holds the currently-accepted photos — for a staged update
		// that's the LIVE post ($stage_for), not the brand-new staging draft
		// being inserted ($existing_id, forced to 0 above). Without this, a
		// text-only biography update to an already-published bio would stage
		// an empty gallery and wipe the existing photo on approval, and an
		// artwork resend that appends a photo would lose the previously
		// published ones the same way.
		$gallery      = $this->merge_gallery( $existing_id ?: $stage_for, $results, $post_type );
		$post_content = $this->build_post_content( $primary, $gallery, $post_type, $submission['description'] ?? '' );

		// Keep the existing review token when updating so artist links stay valid.
		// New tokens are 32 bytes of CSPRNG — no reconstruction possible.
		$review_token  = $existing_id
			? ( get_post_meta( $existing_id, '_agnosis_review_token', true ) ?: $this->generate_token() )
			: $this->generate_token();
		// Settings → Behavior → "Review link expiry (days)" (agnosis_review_token_expiry_days,
		// default 7). max( 1, ... ) guards a raw get_option() read against a
		// corrupted/pre-upgrade option value slipping past the settings page's
		// own sanitize_callback clamp.
		$review_expiry = time() + ( max( 1, (int) get_option( 'agnosis_review_token_expiry_days', 7 ) ) * DAY_IN_SECONDS );

		// The artist's original submitted title is the canonical post title — it is
		// the name the artist gave their work, in their own language.  The AI-generated
		// translation (site language) is stored separately in _agnosis_translated_title
		// and surfaced to visitors via the agnosis/artwork-title block.
		//
		// Native-language pipeline (Phase 1, 2026-07-12 — agnosis-audit/
		// NATIVE-LANGUAGE-PIPELINE.md §4a/§4b): the AI description pipeline no
		// longer pre-translates the artist's submission to primary language
		// before generating title/excerpt/body/medium — Pipeline::process()
		// now runs natively, in the artist's own language. That means $ai_title
		// here (and $primary['excerpt']/$post_content below) are the artist's
		// OWN language, not yet primary — so _agnosis_translated_title's stored
		// value is, at this exact moment, simply a second copy of the native
		// title, not a real translation. ReviewEndpoints::finalize_publish()
		// (§4c, Phase 3) is what actually produces the primary-language
		// translation and overwrites _agnosis_translated_title with it, at
		// approval time — the one point downstream code should treat that meta
		// as trustworthy. Nothing between intake and approval reads it as if it
		// were already primary-language.
		$ai_title = $primary['title'] ?? '';
		$native_lang = SubmissionTranslator::resolve_artist_lang( $artist_id );
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
				// The artist's own language (ISO 639-1), resolved once from
				// their WP user locale at intake — the persisted signal every
				// downstream consumer (ReviewConfirm's display logic,
				// Notification's review-email preview, ReviewEndpoints'
				// approval-time translation) uses to know this content is
				// natively-generated rather than re-deriving the artist's
				// locale live each time. '' when undeclared/unknown, same
				// graceful-degradation convention SubmissionTranslator itself
				// uses elsewhere.
				'_agnosis_native_lang'      => $native_lang,
				// Written every time (not just when non-empty) so a resubmission
				// that no longer has any dropped link correctly clears a stale
				// notice from an earlier attempt — Notification::build_email()
				// treats '[]' the same as the meta being entirely absent.
				'_agnosis_dropped_links'    => wp_json_encode( $this->last_dropped_links ),
				// Same always-written, empty-clears-stale shape as dropped_links
				// above — audit §2a's replace@/event@ "did you mean" suggestion.
				// Empty array encodes to '[]', which Notification::build_email()
				// treats identically to the meta being entirely absent.
				'_agnosis_merge_miss_suggestion' => wp_json_encode( $this->pending_merge_miss_suggestion ),
				// eighth audit §3c — true when Email\Parser's reply-above-quote/
				// forward extraction is what produced this content, rather than
				// an ordinary fresh email (see IntakeGates::extract_original_content()).
				// Always written, like the two meta keys just above, so a LATER
				// resubmission that merges into the same post (replace@/event
				// update) with genuinely fresh, non-extracted content correctly
				// clears a stale 'true' from an earlier reply-extracted update —
				// Notification::build_email() reads this fresh each time to
				// decide whether to show the "a fresh email works best next
				// time" note on the review email.
				'_agnosis_extracted_from_reply' => (bool) ( $submission['extracted_from_reply'] ?? false ),
			],
		];

		if ( 'agnosis_event' === $post_type ) {
			$post_data['meta_input']['_agnosis_event_location'] = $submission['_event_location'] ?? '';
			$post_data['meta_input']['_agnosis_event_address']  = $submission['_event_address']  ?? '';
			$post_data['meta_input']['_agnosis_event_date']     = $submission['_event_date']     ?? '';
			$post_data['meta_input']['_agnosis_event_timezone'] = $submission['_event_timezone'] ?? '';
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
			// $existing_id can never be a currently-published post at this
			// point — the "true staging" redirect above already turned that
			// case into $existing_id = 0 (a fresh staging draft) before we got
			// here. So $existing_id is always either a not-yet-approved
			// original draft or an existing staging draft (found by
			// find_singleton_post()'s own staging-aware lookup, for
			// biography) — both are ordinary drafts, and post_status staying
			// at the 'draft' default set above is simply correct, not
			// something that needs preserving or overriding.
			$post_data['ID'] = $existing_id;

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

		// Tag a freshly-created staging draft with the live post it's a
		// pending update for (patch 18) — see the "True staging" block above.
		// ReviewEndpoints::finalize_publish() is what reads this back.
		if ( $stage_for ) {
			update_post_meta( $post_id, '_agnosis_pending_update_for', $stage_for );
			Logger::info(
				sprintf( 'Queue #%d: created pending-update draft #%d for already-published post #%d.', $queue_id, $post_id, $stage_for ),
				'publisher'
			);
		}

		// Persist the artist's original submitted title (pre-translation) once.
		// Subsequent updates (resends, singleton merges) leave this value intact so
		// the artist's creative intent is never lost or overwritten by a later AI pass.
		if ( '' !== $original_title && '' === (string) get_post_meta( $post_id, '_agnosis_original_title', true ) ) {
			update_post_meta( $post_id, '_agnosis_original_title', $original_title );
		}

		// Persist which address created this submission — once, same write-once
		// pattern as _agnosis_original_title above. A replace@ update reads this
		// (see handle()'s early replace@ resolution) but must never change it:
		// the artwork's original intake strategy is what a resend should keep
		// reusing, not whatever address the artist happened to send the resend to.
		if ( 'agnosis_artwork' === $post_type && '' === (string) get_post_meta( $post_id, '_agnosis_intake_endpoint', true ) ) {
			update_post_meta( $post_id, '_agnosis_intake_endpoint', $intake_endpoint );
		}

		$this->write_post_meta( $post_id, $primary, $gallery, $all_tags, $post_type );

		return $post_id;
	}

	/**
	 * Build the merged gallery of attachment IDs for a post.
	 *
	 * When updating an existing post, reuses already-uploaded images (matched by
	 * MD5 hash of the original binary) to avoid duplicates in the media library.
	 * Newly uploaded images are appended after existing ones — EXCEPT for
	 * agnosis_biography (patch 18): a biography carries at most one image, so a
	 * submission that includes a new one replaces whatever was there rather than
	 * accumulating a gallery across resubmissions.
	 *
	 * Patch 18 bugfix: $existing_id can point at a post that was never created
	 * via PostCreator::create_post() at all — e.g. Artist\ApplicationBiography
	 * auto-creates an artist's first agnosis_biography draft straight from their
	 * admission application (bio text + portfolio link), and its meta_input
	 * never included '_agnosis_gallery_ids'. get_post_meta( $id, $key, true )
	 * returns '' (an empty STRING) for a meta key that was never set at all —
	 * distinct from a key set to an empty array — and PHP's (array) cast on a
	 * scalar wraps it as a one-element array ( (array) '' === [ '' ] ), not [].
	 * That stray '' then survived array_merge()/array_unique() into the
	 * returned gallery, into build_post_content()'s $image_ids, and finally
	 * into build_image_block( int $id ) — this file is strict_types=1, so that
	 * threw a TypeError instead of silently coercing. Every artist whose
	 * biography was auto-created on admission and who then emailed a photo to
	 * bio@ for the first time hit this. Fixed by validating the meta value is
	 * actually an array before trusting its contents, rather than blindly
	 * casting whatever get_post_meta() returned.
	 *
	 * @param int                              $existing_id Post ID to merge into, or 0 for new.
	 * @param array<int, array<string, mixed>> $results     AI pipeline results, one per attachment.
	 * @param string                           $post_type   CPT slug — only agnosis_biography caps at one image.
	 * @return int[] Ordered, deduplicated attachment IDs.
	 */
	private function merge_gallery( int $existing_id, array $results, string $post_type = 'agnosis_artwork' ): array {
		$existing_hash_map = [];
		$existing_gallery  = [];

		if ( $existing_id ) {
			$raw_existing = get_post_meta( $existing_id, '_agnosis_gallery_ids', true );
			// is_array() guard (not a bare (array) cast) — see this method's own
			// docblock: get_post_meta() returns '' for a meta key that was never
			// set, and (array) '' is [ '' ], not [] — a stray non-int id that
			// later blew up build_image_block()'s int-typed parameter.
			$existing_gallery = is_array( $raw_existing )
				? array_values( array_filter( array_map( 'intval', $raw_existing ) ) )
				: [];

			foreach ( $existing_gallery as $att_id ) {
				$h = (string) get_post_meta( $att_id, '_agnosis_image_hash', true );
				if ( $h ) {
					$existing_hash_map[ $h ] = $att_id;
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

		// Biography carries at most one image (patch 18, confirmed product
		// rule) — unlike artwork/event, a new photo REPLACES whatever was
		// there instead of accumulating alongside it across resubmissions,
		// and a single email with several attachments only ever keeps the
		// first. Also self-heals a biography that already accumulated more
		// than one image before this fix existed: the very next
		// resubmission (with or without a new photo) trims it back down to
		// one.
		if ( 'agnosis_biography' === $post_type ) {
			if ( ! empty( $new_gallery ) ) {
				return [ $new_gallery[0] ];
			}
			return ! empty( $existing_gallery ) ? [ $existing_gallery[0] ] : [];
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
		//
		// wpautop() added to the bio/event path 2026-07-21 — previously this
		// branch ran the artist's raw text through wp_kses_post() alone, with
		// no paragraph/line-break structure added at all (no <p>, no <br>),
		// so a multi-line statement rendered as one run-on line with the
		// artist's own line breaks silently collapsed by the browser. Safe to
		// apply even when $artist_text already carries basic HTML (e.g.
		// AI\Pipeline::merge_biography()'s <p>/<em>/<strong> output) —
		// wpautop() is the same idempotent pass WordPress's own `the_content`
		// filter already applies to every classic post on every page load,
		// regardless of whether the stored content already has <p> tags.
		$body = in_array( $post_type, [ 'agnosis_biography', 'agnosis_event' ], true )
			? wpautop( wp_kses_post( $artist_text ) )
			: ( $primary['body'] ?? '' );

		// Wrap each paragraph in explicit Gutenberg block-comment markers
		// (2026-07-21) — both branches above hand back raw, unwrapped classic
		// HTML (bare <p>/<br> tags, no <!-- wp:paragraph --> markers). On
		// agnosis-theme, a pure FSE block theme with no classic-editor
		// fallback, that orphaned HTML is invisible to Gutenberg's block
		// parser: the first time such a post is opened in the block editor,
		// its auto-recovery logic adopts the lone <p>...</p> as a genuine
		// wp:paragraph block by reconstructing it from its own rich-text
		// model rather than preserving the original markup byte-for-byte —
		// and that reconstruction doesn't round-trip a manually-inserted
		// <br /> the way a real Shift+Enter break would, silently collapsing
		// every line break in the post (confirmed live: a poem's 5 lines
		// became one run-on sentence after the post was opened once in the
		// editor). Emitting real wp:paragraph blocks from creation means
		// there's nothing left for the editor to "recover" — the content is
		// already valid, native block markup the moment the post is made.
		$body = $this->paragraphs_to_blocks( $body );

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

		// Biography and event single templates both render wp:post-featured-image
		// above wp:post-content (single-agnosis_biography.html,
		// single-agnosis_event.html — agnosis-theme) — write_post_meta() already
		// sets a featured image whenever the gallery is non-empty, via the same
		// pick_thumbnail_id() logic reused directly below. Adding that same
		// image here too, as a leading wp:image block, would show it a second
		// time: once as the sidebar/hero featured image, once again at the top
		// of the body. Artwork's own template has no featured-image block at
		// all — the gallery block IS the page's content there — so it's
		// unaffected.
		//
		// Scoped precisely to "this is the exact attachment that becomes the
		// featured image", not just "there's only one image" — pick_thumbnail_id()
		// prefers a video's poster frame over a genuine image when both are
		// present in the same gallery, so a lone photo submitted alongside a
		// video would NOT be the featured image in that case, and must still
		// get its own content block or it would vanish from the post entirely.
		// $gallery is guaranteed non-empty here — the empty-gallery case already
		// returned above, before $image_ids/$media_blocks were even built.
		$thumbnail_id     = $this->pick_thumbnail_id( $gallery );
		$skip_solo_image  = in_array( $post_type, [ 'agnosis_biography', 'agnosis_event' ], true )
			&& count( $image_ids ) === 1
			&& $thumbnail_id === $image_ids[0];

		$image_block = '';
		if ( count( $image_ids ) > 1 ) {
			$image_block = $this->build_gallery_block( $image_ids ) . "\n\n";
		} elseif ( count( $image_ids ) === 1 && ! $skip_solo_image ) {
			$image_block = $this->build_image_block( $image_ids[0] ) . "\n\n";
		}

		return $media_blocks . $image_block . $body . $embed_blocks;
	}

	/**
	 * Wrap each top-level <p>...</p> paragraph produced by wpautop() in
	 * explicit Gutenberg block-comment markers, so the stored post_content is
	 * valid native block markup from the moment the post is created — see the
	 * long comment in build_post_content() (2026-07-21) for why this matters
	 * on a block-only FSE theme: without it, Gutenberg's block-recovery logic
	 * silently drops manually-inserted <br /> line breaks the first time the
	 * post is opened in the editor.
	 *
	 * Only wraps <p> tags at all — any markup wpautop() left outside of a <p>
	 * (rare, but possible with malformed input) passes through untouched
	 * rather than being dropped.
	 *
	 * @param string $html wpautop()-processed HTML (bare <p>/<br> tags, no block comments).
	 * @return string Same content with each <p>...</p> wrapped in a wp:paragraph block.
	 */
	private function paragraphs_to_blocks( string $html ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#<p([^>]*)>(.*?)</p>#s',
			static function ( array $matches ): string {
				return "<!-- wp:paragraph -->\n<p{$matches[1]}>{$matches[2]}</p>\n<!-- /wp:paragraph -->";
			},
			$html
		);
	}

	/**
	 * Scan the artist's raw submitted text for links and turn each one
	 * EmbedPolicy approves into a wp:embed block, for when the actual file
	 * (typically a video) was too large to email and the artist points to it
	 * elsewhere instead (YouTube, Vimeo, SoundCloud, Bandcamp, … or, if the
	 * admin has enabled AI review, any other site the AI doesn't reject — see
	 * EmbedPolicy::is_allowed()). Appended at the very bottom of the post,
	 * after all attached media and body text.
	 *
	 * A link EmbedPolicy does not approve is dropped, logged, and — 2026-07-10 —
	 * recorded (URL + EmbedPolicy::last_reason()) in $this->last_dropped_links,
	 * which create_post() reads right after this returns and writes to the new
	 * post's `_agnosis_dropped_links` meta, so the artist's review email can
	 * explain why a link they mentioned isn't there instead of it just quietly
	 * not being there. Never embedded, never shown as a raw link either.
	 *
	 * @param string $artist_text Raw submitted email body (pre-translation, pre-AI).
	 * @return string Zero or more wp:embed blocks, each followed by a blank line; '' if none.
	 */
	private function build_external_link_embeds( string $artist_text ): string {
		$this->last_dropped_links = [];

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

			if ( ! $this->embed_policy->is_allowed( $url ) ) {
				$reason = $this->embed_policy->last_reason();
				Logger::info(
					sprintf( 'PostCreator: link to "%s" was not approved for embedding — omitted from post content (%s).', $host, $reason ),
					'publisher'
				);
				$this->last_dropped_links[] = [ 'url' => $url, 'reason' => $reason ];
				continue;
			}

			$blocks .= $this->build_embed_block( $url, $host ) . "\n\n";
			++$count;
		}

		return $blocks;
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
	 * @param string $url  URL already approved by EmbedPolicy::is_allowed().
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
		// Validate against the LIVE medium vocabulary (PromptConfig::medium_terms()),
		// not just the built-in CANONICAL_MEDIUMS seed list — an admin can add,
		// rename, or remove terms under Artwork → Mediums same as Categories, and
		// the AI is already prompted with that same live list (see the providers'
		// resolved_system_prompt() calls), so this guard has to accept whatever
		// it was actually offered. This still exists to prevent AI hallucinations
		// (a term the AI invents that isn't in the live list either) from creating
		// rogue taxonomy terms. Empty or unrecognised values are silently skipped;
		// the admin can assign manually from the edit screen.
		if ( 'agnosis_artwork' === $post_type ) {
			$medium = trim( $primary['medium'] ?? '' );

			// Native-language pipeline (Phase 1/3): the AI's medium pick is now
			// in the artist's own language, not necessarily primary, so the
			// in_array() match below will legitimately miss for a non-primary-
			// language artist — that's expected here, not a bug, and is exactly
			// why the raw value is preserved in _agnosis_native_medium
			// regardless of whether it matched: ReviewEndpoints::finalize_publish()
			// (§4c, Phase 3) translates it to primary and re-runs this same
			// match at approval time, once the term name is actually comparable
			// to the (primary-language) controlled vocabulary. Stored
			// unconditionally whenever the AI returned a medium at all — for a
			// primary-language artist this is just a redundant copy of a value
			// that already matched below; harmless, and simpler than branching
			// on whether translation will actually be needed later.
			if ( $medium ) {
				update_post_meta( $post_id, '_agnosis_native_medium', $medium );
			}

			if ( $medium && in_array( $medium, PromptConfig::medium_terms(), true ) ) {
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
	 * them"). No other behavior change.
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
	 * Split pipeline results into those that pass the quality-rejection
	 * threshold and those that don't, evaluating each attachment on its own
	 * score rather than only the first.
	 *
	 * A result with no assessable score (0 — the provider couldn't judge it,
	 * e.g. audio/video, which carry no photo_quality_score at all) always
	 * passes: absence of a score is never treated as a failure.
	 *
	 * @param array<int, array<string, mixed>> $results      Pipeline results, one per attachment.
	 * @param int                               $reject_below Threshold — scores at or below this fail. Caller guarantees > 0.
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{index: int, score: int, issues: string[]}>}
	 *         [ kept results (re-indexed), rejected entries (original index, score, issues) ].
	 */
	private function apply_quality_gate( array $results, int $reject_below ): array {
		$kept     = [];
		$rejected = [];

		foreach ( array_values( $results ) as $i => $r ) {
			$score  = (int) ( $r['photo_quality_score']  ?? 0 );
			$issues = (array) ( $r['photo_quality_issues'] ?? [] );

			if ( $score > 0 && $score <= $reject_below ) {
				$rejected[] = [ 'index' => $i, 'score' => $score, 'issues' => $issues ];
				continue;
			}

			$kept[] = $r;
		}

		return [ $kept, $rejected ];
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

	/**
	 * Write status/error/post_id onto an agnosis_queue row.
	 *
	 * Public and static (2026-07-13) so `ReviewEndpoints::finalize_publish()`
	 * can reuse this exact write when repointing a staged update's queue row
	 * off the staging draft it was minted against — see that method's own
	 * docblock note on why a stale post_id there caused a real bug
	 * (Inbox::is_already_queued()'s 'published' branch re-running the whole
	 * submission and drafting a second review email for already-live
	 * content). Kept as the one place this table's row shape is written,
	 * rather than a second ad hoc $wpdb->update() call in ReviewEndpoints.php.
	 * Was already called exclusively as `$this->mark(...)` from within this
	 * class — those calls keep working unchanged (PHP allows invoking a
	 * static method through `->`).
	 */
	public static function mark( int $id, string $status, string $error = '', int $post_id = 0 ): void {
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
