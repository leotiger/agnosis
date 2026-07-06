<?php
/**
 * Front-end correction for artists — Phase 1 (text chunks) + Phase 2 (photo
 * substitution).
 *
 * Lets an admitted artist correct their own published content directly, in
 * place, without composing another intake email:
 *   - Phase 1: post content on the three CPTs, the artwork excerpt, and the
 *     event location/date fields.
 *   - Phase 2: replace the featured image (biography/event) or a specific
 *     gallery image (artwork) with a direct upload — no AI enhancement, no
 *     quality gate; the artist's own photo is used exactly as uploaded.
 *
 * REST:  POST /agnosis/v1/content/{id}/text   { field, value }
 *        POST /agnosis/v1/content/{id}/photo  multipart { file, attachment_id? }
 *
 * This is the feasibility evaluation in the third audit (§7), phased per §7d.
 * Phase 3 (artwork/biography TITLE editing — dual-title regeneration — and
 * "restore original photo") is deliberately not exposed here.
 *
 * Translation coherence (§7c, reassessed 2026-07-06): an artist may only edit the
 * post version matching their OWN declared language (their WP user `locale`, set
 * once at admission — see Admission::apply()/iso_to_wp_locale()), never a free
 * language switcher. The edit is saved verbatim to that post; if that post is not
 * the primary-language post, the edit is translated into the primary language
 * (via Agnosis's own AI\SubmissionTranslator — the same tool already used for
 * primary -> other title translations, not Lingua Forge's translation pipeline,
 * so fields the artist didn't touch are never re-translated) and written to the
 * linked primary post; the primary post is then fanned out to every other
 * configured language, excluding the artist's own source language, which already
 * holds their exact words.
 *
 * Photo substitution needs no such translation leg — images are language-neutral
 * (audit §7c) — so a replacement is instead copied directly and synchronously to
 * every language version of the post via `linguaforge_get_translations()`, with
 * no AI call and no deferred dispatch involved.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Compat\LinguaForge;
use Agnosis\Core\Logger;
use Agnosis\Core\RateLimiter;
use Agnosis\Publishing\PostCreator;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

class ContentEditor {

	/** CPTs eligible for front-end correction. */
	private const EDITABLE_POST_TYPES = [ 'agnosis_artwork', 'agnosis_biography', 'agnosis_event' ];

	/**
	 * Per-post-type map of editable field key => WP storage target.
	 *
	 * A target starting with '_' is a meta key; anything else is a wp_posts column
	 * written via wp_update_post(). Deliberately an allowlist — never derived from
	 * arbitrary request input — so a request can't touch a field (or a whole other
	 * post type's field) that Phase 1 doesn't cover.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const EDITABLE_FIELDS = [
		'agnosis_artwork'   => [
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'title'   => 'post_title',
		],
		'agnosis_biography' => [
			'content' => 'post_content',
			'title'   => 'post_title',
		],
		'agnosis_event'     => [
			'content'        => 'post_content',
			'event_location' => '_agnosis_event_location',
			'event_date'     => '_agnosis_event_date',
		],
	];

	/**
	 * Post types whose 'title' field goes through the dual-title propagation
	 * (propagate_title()) instead of the normal translate-then-fan-out content
	 * propagation. Artwork only — biography's post_title has no dual-title
	 * system (LF translates it normally, same as any other content field); see
	 * Compat\LinguaForge::hold_artist_title()'s docblock.
	 */
	private const DUAL_TITLE_POST_TYPES = [ 'agnosis_artwork' ];

	/** Saves allowed per artist per hour — generous for a human, a wall for a script (§7c). */
	private const RATE_LIMIT = 30;

	/**
	 * Accepted MIME types for a direct photo replacement (Phase 2).
	 *
	 * A fast-fail UX check only — wp_handle_sideload() (via
	 * PostCreator::upload_media()) performs the authoritative MIME/extension
	 * check against get_allowed_mime_types() regardless; this just avoids a
	 * slow round-trip for an obviously-wrong file type.
	 */
	private const PHOTO_MIME_ALLOWLIST = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];

	// -------------------------------------------------------------------------
	// REST route
	// -------------------------------------------------------------------------

	public function register_routes(): void {
		register_rest_route( 'agnosis/v1', '/content/(?P<id>\d+)/text', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'save_text' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'id'    => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
				'field' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
				'value' => [
					'type'     => 'string',
					'required' => true,
				],
			],
		] );

		register_rest_route( 'agnosis/v1', '/content/(?P<id>\d+)/photo', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'save_photo' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'id'            => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
				'attachment_id' => [
					'type'              => 'integer',
					'required'          => false,
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( 'agnosis/v1', '/content/(?P<id>\d+)/photo/restore', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'restore_photo' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'id'            => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
				'attachment_id' => [
					'type'              => 'integer',
					'required'          => false,
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	/**
	 * Coarse REST gate: logged in or reject. Fine-grained authorization (author
	 * match, admitted-artist gate, own-language gate, rate limit) happens in
	 * check_access() inside save_text() — mirrors ReviewEndpoints's existing
	 * two-tier pattern.
	 */
	public function check_permission( WP_REST_Request $request ): true|WP_Error {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error(
			'agnosis_auth_required',
			__( 'Authentication required.', 'agnosis' ),
			[ 'status' => 401 ]
		);
	}

	// -------------------------------------------------------------------------
	// Callback
	// -------------------------------------------------------------------------

	public function save_text( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$auth = $this->check_access( $request, $post_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$post  = get_post( $post_id );
		$field = sanitize_key( (string) $request->get_param( 'field' ) );
		$raw   = (string) $request->get_param( 'value' );

		$allowed_fields = self::EDITABLE_FIELDS[ $post->post_type ] ?? [];
		if ( ! isset( $allowed_fields[ $field ] ) ) {
			return new WP_Error(
				'agnosis_invalid_field',
				__( 'This field cannot be edited here.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		$target = $allowed_fields[ $field ];
		$value  = $this->sanitize_value( $field, $raw );

		if ( 'event_date' === $field && '' !== $value && false === strtotime( $value ) ) {
			return new WP_Error(
				'agnosis_invalid_date',
				__( 'That date could not be understood.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		if ( 'title' === $field && '' === trim( $value ) ) {
			return new WP_Error(
				'agnosis_empty_title',
				__( 'The title cannot be empty.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		// Step 2 (§7c): persist verbatim to the artist's own-language post. This is
		// now the authoritative record of what the artist actually wrote.
		$write = $this->write_field( $post_id, $target, $value );
		if ( is_wp_error( $write ) ) {
			return $write;
		}

		Logger::info(
			sprintf(
				'Content edit: post #%d (%s) field "%s" updated by user #%d.',
				$post_id,
				$post->post_type,
				$field,
				get_current_user_id()
			),
			'content-editor'
		);

		if ( LinguaForge::is_active() ) {
			// Dual-title (artwork only, Phase 3): post_title is the artist's own
			// words and must stay byte-identical on every language version — never
			// translated — unlike every other field, which is translated into the
			// primary language then fanned out. See propagate_title()'s docblock.
			if ( 'title' === $field && in_array( $post->post_type, self::DUAL_TITLE_POST_TYPES, true ) ) {
				$this->propagate_title( $post_id, $value );
			} else {
				$this->propagate_translation( $post_id, $post->post_type, $field, $target, $value );
			}
		}

		return new WP_REST_Response(
			[
				'status'  => 'saved',
				'post_id' => $post_id,
				'message' => __( 'Saved — translations will update within a few minutes.', 'agnosis' ),
			],
			200
		);
	}

	// -------------------------------------------------------------------------
	// Callback — photo substitution (Phase 2)
	// -------------------------------------------------------------------------

	/**
	 * Replace the featured image (biography/event) or a specific gallery image
	 * (artwork) with a direct upload.
	 *
	 * Deliberately skips the AI pipeline entirely: no enhancement, no quality
	 * gate — a direct replacement is the artist's deliberate choice, exactly the
	 * `photo_only` semantics PostCreator already encodes for photo@ submissions.
	 * The replaced attachment is never deleted — it's marked as the new one's
	 * `_agnosis_original_attachment_id` (the same provenance meta pair the
	 * enhancement path already uses for its pre-enhancement sidecar), so it stays
	 * recoverable for a future "restore original photo" action (Phase 3).
	 */
	public function save_photo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$auth = $this->check_access( $request, $post_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$post = get_post( $post_id );

		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;

		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) ) {
			return new WP_Error(
				'agnosis_no_file',
				__( 'No image file was received.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		$mime = (string) ( $file['type'] ?? '' );
		if ( ! in_array( $mime, self::PHOTO_MIME_ALLOWLIST, true ) ) {
			return new WP_Error(
				'agnosis_invalid_mime',
				__( 'Please upload a JPEG, PNG, WebP, or GIF image.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a just-uploaded temp file already validated above; not a remote fetch.
		$binary = '' !== $tmp_name ? file_get_contents( $tmp_name ) : false;
		if ( false === $binary || '' === $binary ) {
			return new WP_Error(
				'agnosis_upload_failed',
				__( 'The uploaded file could not be read.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		$old_attachment_id = $this->resolve_old_attachment( $post, (int) $request->get_param( 'attachment_id' ) );

		$new_id = ( new PostCreator() )->upload_media(
			$binary,
			$mime,
			sanitize_file_name( (string) ( $file['name'] ?? 'photo' ) ),
			'', // Alt text is out of Phase 2 scope — the artist can add it in a future pass.
			$post->post_title ?: __( 'Untitled', 'agnosis' ),
			md5( $binary )
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		if ( $old_attachment_id ) {
			update_post_meta( $new_id, '_agnosis_original_attachment_id', $old_attachment_id );
			update_post_meta( $old_attachment_id, '_agnosis_is_original', '1' );
		}

		$this->swap_photo_everywhere( $post, $old_attachment_id, (int) $new_id );

		Logger::info(
			sprintf(
				'Photo edit: post #%d (%s) — attachment #%d replaced with #%d by user #%d.',
				$post_id,
				$post->post_type,
				$old_attachment_id,
				$new_id,
				get_current_user_id()
			),
			'content-editor'
		);

		return new WP_REST_Response(
			[
				'status'        => 'saved',
				'post_id'       => $post_id,
				'attachment_id' => $new_id,
				'image_url'     => wp_get_attachment_image_url( $new_id, 'large' ) ?: wp_get_attachment_url( $new_id ),
				'message'       => __( 'Photo replaced — the original is kept and can be restored later.', 'agnosis' ),
			],
			200
		);
	}

	/**
	 * One-click "restore original photo" (Phase 3).
	 *
	 * Swaps the current attachment back to whichever one it recorded as its own
	 * `_agnosis_original_attachment_id` when it replaced it (see save_photo()).
	 * No upload involved — this is a pure re-pointing of the gallery/thumbnail
	 * meta, reusing the exact same swap_photo_everywhere() propagation Phase 2
	 * already uses.
	 *
	 * The provenance pointer is reversed rather than deleted: the attachment
	 * being restored FROM becomes the new attachment's `_agnosis_original_attachment_id`,
	 * so a second restore call undoes this one too, the same way Phase 2 already
	 * never deletes anything.
	 */
	public function restore_photo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$auth = $this->check_access( $request, $post_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$post = get_post( $post_id );

		$current_id = $this->resolve_old_attachment( $post, (int) $request->get_param( 'attachment_id' ) );
		if ( ! $current_id ) {
			return new WP_Error(
				'agnosis_no_photo',
				__( 'There is no photo to restore.', 'agnosis' ),
				[ 'status' => 404 ]
			);
		}

		$original_id = (int) get_post_meta( $current_id, '_agnosis_original_attachment_id', true );
		if ( ! $original_id ) {
			return new WP_Error(
				'agnosis_no_original',
				__( 'No earlier version of this photo was found.', 'agnosis' ),
				[ 'status' => 404 ]
			);
		}

		update_post_meta( $original_id, '_agnosis_original_attachment_id', $current_id );
		update_post_meta( $current_id, '_agnosis_is_original', '1' );
		delete_post_meta( $original_id, '_agnosis_is_original' );

		$this->swap_photo_everywhere( $post, $current_id, $original_id );

		Logger::info(
			sprintf(
				'Photo restore: post #%d (%s) — attachment #%d restored over #%d by user #%d.',
				$post_id,
				$post->post_type,
				$original_id,
				$current_id,
				get_current_user_id()
			),
			'content-editor'
		);

		return new WP_REST_Response(
			[
				'status'        => 'saved',
				'post_id'       => $post_id,
				'attachment_id' => $original_id,
				'image_url'     => wp_get_attachment_image_url( $original_id, 'large' ) ?: wp_get_attachment_url( $original_id ),
				'message'       => __( 'Restored the earlier photo.', 'agnosis' ),
			],
			200
		);
	}

	/**
	 * Determine which existing attachment a photo upload should replace.
	 *
	 * Artwork: the requested attachment_id if it's actually in this post's
	 * gallery, else the current featured image if that's in the gallery, else
	 * the gallery's first entry, else 0 (no existing photo — treated as an
	 * addition rather than a replacement).
	 *
	 * Biography/event: these CPTs have no gallery concept — always the current
	 * featured image (possibly 0 if none is set yet).
	 */
	private function resolve_old_attachment( WP_Post $post, int $requested ): int {
		if ( 'agnosis_artwork' !== $post->post_type ) {
			return (int) get_post_thumbnail_id( $post->ID );
		}

		$gallery = array_map( 'intval', (array) get_post_meta( $post->ID, '_agnosis_gallery_ids', true ) );

		if ( $requested && in_array( $requested, $gallery, true ) ) {
			return $requested;
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post->ID );
		if ( $thumbnail_id && in_array( $thumbnail_id, $gallery, true ) ) {
			return $thumbnail_id;
		}

		return $gallery[0] ?? 0;
	}

	/**
	 * Copy a photo replacement to every language version of the post.
	 *
	 * No AI call, no deferred dispatch: images are language-neutral (§7c), so
	 * this runs synchronously against `linguaforge_get_translations()`'s full
	 * group (which — confirmed against the real Lingua Forge source during the
	 * §7c reassessment — is symmetric: querying from any member of the group
	 * returns every member, not just "translations of the primary").
	 */
	private function swap_photo_everywhere( WP_Post $post, int $old_id, int $new_id ): void {
		$targets = [ $post->ID ];

		if ( LinguaForge::is_active() && function_exists( 'linguaforge_get_translations' ) ) {
			foreach ( linguaforge_get_translations( $post->ID ) as $sibling_id ) {
				$sibling_id = (int) $sibling_id;
				if ( $sibling_id && ! in_array( $sibling_id, $targets, true ) ) {
					$targets[] = $sibling_id;
				}
			}
		}

		foreach ( $targets as $target_id ) {
			$this->swap_photo_on_post( $target_id, $old_id, $new_id );
		}
	}

	/** Swap the replaced attachment for the new one on a single post. */
	private function swap_photo_on_post( int $post_id, int $old_id, int $new_id ): void {
		if ( 'agnosis_artwork' === get_post_type( $post_id ) ) {
			$gallery = array_map( 'intval', (array) get_post_meta( $post_id, '_agnosis_gallery_ids', true ) );

			if ( $old_id && in_array( $old_id, $gallery, true ) ) {
				$gallery = array_map(
					static fn( int $id ): int => $id === $old_id ? $new_id : $id,
					$gallery
				);
			} else {
				$gallery[] = $new_id; // No existing photo to replace — add as a new gallery item.
			}

			update_post_meta( $post_id, '_agnosis_gallery_ids', array_values( array_unique( $gallery ) ) );
		}

		$current_thumbnail = (int) get_post_thumbnail_id( $post_id );
		if ( ! $current_thumbnail || $current_thumbnail === $old_id ) {
			set_post_thumbnail( $post_id, $new_id );
		}
	}

	// -------------------------------------------------------------------------
	// Authorization
	// -------------------------------------------------------------------------

	/**
	 * Fine-grained authorization for a content-edit request.
	 *
	 * Order matters: cheap checks first. Admins (manage_options) bypass the
	 * author-match and own-language gates — same "admin passes through" pattern
	 * as ReviewEndpoints::check_access() and RemovalEndpoints.
	 *
	 * @return true|WP_Error
	 */
	private function check_access( WP_REST_Request $request, int $post_id ): true|WP_Error {
		unset( $request ); // Reserved for a future token path; not used today.

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error(
				'agnosis_auth_required',
				__( 'Authentication required.', 'agnosis' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! Admission::is_admitted_artist( $user_id ) ) {
			return new WP_Error(
				'agnosis_forbidden',
				__( 'Only admitted artists may edit their own content.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::EDITABLE_POST_TYPES, true ) ) {
			return new WP_Error(
				'agnosis_not_found',
				__( 'Content not found.', 'agnosis' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error(
				'agnosis_not_published',
				__( 'Only published content can be corrected here.', 'agnosis' ),
				[ 'status' => 409 ]
			);
		}

		$is_admin = user_can( $user_id, 'manage_options' );

		if ( ! $is_admin && (int) $post->post_author !== $user_id ) {
			return new WP_Error(
				'agnosis_forbidden',
				__( 'You may only edit your own content.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! $is_admin ) {
			$language_check = $this->check_own_language( $user_id, $post_id );
			if ( is_wp_error( $language_check ) ) {
				return $language_check;
			}
		}

		return RateLimiter::check_sender( 'content_edit', (string) $user_id, self::RATE_LIMIT, HOUR_IN_SECONDS );
	}

	/**
	 * Restrict correction to the post version matching the artist's own declared
	 * language (audit §7c, reassessed 2026-07-06) — never a free language switcher.
	 *
	 * No-ops (returns true) when Lingua Forge isn't active, the post has no
	 * `_lf_lang` yet, or the artist has no declared locale — in every one of those
	 * cases there is no "wrong language" to compare against.
	 *
	 * @return true|WP_Error
	 */
	private function check_own_language( int $user_id, int $post_id ): true|WP_Error {
		if ( ! LinguaForge::is_active() ) {
			return true;
		}

		$post_lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $post_lang ) {
			return true;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || '' === $user->locale ) {
			return true;
		}

		$artist_lang = LinguaForge::locale_to_lang( $user->locale );
		if ( '' === $artist_lang || $artist_lang === $post_lang ) {
			return true;
		}

		return new WP_Error(
			'agnosis_wrong_language',
			__( 'This page is not in your own language. Edit the version in your language instead.', 'agnosis' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Same eligibility test as check_access(), minus the REST-specific bits —
	 * used by the enqueue gate and by Profile's event render callbacks to decide
	 * whether to expose the edit affordance at all. Never returns a WP_Error:
	 * callers only need a yes/no.
	 */
	public static function is_editable_by_current_user( int $post_id ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! Admission::is_admitted_artist( $user_id ) ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::EDITABLE_POST_TYPES, true ) || 'publish' !== $post->post_status ) {
			return false;
		}

		$is_admin = user_can( $user_id, 'manage_options' );
		if ( ! $is_admin && (int) $post->post_author !== $user_id ) {
			return false;
		}

		if ( $is_admin || ! LinguaForge::is_active() ) {
			return true;
		}

		$post_lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $post_lang ) {
			return true;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || '' === $user->locale ) {
			return true;
		}

		$artist_lang = LinguaForge::locale_to_lang( $user->locale );
		return '' === $artist_lang || $artist_lang === $post_lang;
	}

	// -------------------------------------------------------------------------
	// Persistence
	// -------------------------------------------------------------------------

	private function sanitize_value( string $field, string $value ): string {
		return match ( $field ) {
			'content'        => wp_kses_post( $value ),
			'excerpt'        => sanitize_textarea_field( $value ),
			'event_location' => sanitize_text_field( $value ),
			'event_date'     => sanitize_text_field( $value ),
			'title'          => sanitize_text_field( $value ),
			default          => sanitize_textarea_field( $value ),
		};
	}

	/**
	 * Write a single field to a post — either a wp_posts column (via
	 * wp_update_post(), which also creates a revision now that the three CPTs
	 * support 'revisions') or a meta key, depending on the target string.
	 *
	 * The wp_update_post() branch is a match on the literal target rather than a
	 * dynamic `[ $target => $value ]` array: wp_update_post() expects a fixed
	 * array shape, which PHPStan cannot verify against a variable string key —
	 * matching on the known EDITABLE_FIELDS values keeps every call site
	 * statically typed and doubles as a guard if a future post-column target is
	 * added to EDITABLE_FIELDS without a matching arm here.
	 *
	 * @return true|WP_Error
	 */
	private function write_field( int $post_id, string $target, string $value ): true|WP_Error {
		if ( str_starts_with( $target, '_' ) ) {
			update_post_meta( $post_id, $target, $value );
			return true;
		}

		$update = match ( $target ) {
			'post_content' => [ 'ID' => $post_id, 'post_content' => $value ],
			'post_excerpt' => [ 'ID' => $post_id, 'post_excerpt' => $value ],
			'post_title'   => [ 'ID' => $post_id, 'post_title' => $value ],
			default        => null,
		};

		if ( null === $update ) {
			return new WP_Error(
				'agnosis_invalid_target',
				__( 'Unsupported field target.', 'agnosis' ),
				[ 'status' => 500 ]
			);
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Translation coherence (§7c, reassessed 2026-07-06)
	// -------------------------------------------------------------------------

	/**
	 * Propagate a verbatim edit to the primary-language post (if the edit
	 * happened on a non-primary post) and fan out to every other configured
	 * language, excluding the artist's own source language.
	 *
	 * The bio -> _agnosis_artist_prompt sync and the fan-out are deliberately NOT
	 * gated on $post_lang/$primary_lang both being resolvable — a post with no
	 * `_lf_lang` yet (LF active but this particular post was never tagged) is not
	 * a "do nothing" case: there is nothing to cross-translate, but the edit still
	 * needs its bio-prompt sync, treating the edited post itself as the target
	 * (same as the same-language case). Only the cross-language translate leg
	 * requires both language codes to be known and different.
	 */
	private function propagate_translation( int $post_id, string $post_type, string $field, string $target, string $value ): void {
		$primary_lang = function_exists( 'linguaforge_source_language' ) ? (string) linguaforge_source_language() : '';
		$post_lang    = (string) get_post_meta( $post_id, '_lf_lang', true );

		$primary_post_id = $post_id;
		$primary_value   = $value;

		$is_cross_language = '' !== $primary_lang && '' !== $post_lang && $post_lang !== $primary_lang;

		// The function_exists() check is combined directly into the if() that
		// wraps the call (rather than folded into $is_cross_language above) so
		// static analysis can see the guard and the call together — the same
		// convention swap_photo_everywhere() below and request_translations() in
		// Compat\LinguaForge already use.
		if ( $is_cross_language && function_exists( 'linguaforge_get_translations' ) ) {
			// Step 3: translate the edit into the primary language using Agnosis's
			// own configured AI provider (AI\SubmissionTranslator — the same tool
			// build_title_translations() already uses for primary -> other title
			// translations), then write it directly to the linked primary post.
			// Deliberately NOT linguaforge_trigger_translation()/ChunkTranslation:
			// those operate on the whole post and would re-translate fields the
			// artist didn't touch (e.g. re-deriving the excerpt from a fresh
			// translation of content that never changed).
			$group           = linguaforge_get_translations( $post_id );
			$primary_post_id = (int) ( $group[ $primary_lang ] ?? 0 );

			if ( ! $primary_post_id || $primary_post_id === $post_id ) {
				// No linked primary post yet (translation not created) — nothing to
				// fan out from. The artist's own post already has the correction.
				return;
			}

			$translator = SubmissionTranslator::from_settings();
			if ( null === $translator ) {
				Logger::warning(
					sprintf( 'Content edit: post #%d — no AI provider configured, primary-language post #%d not updated.', $post_id, $primary_post_id ),
					'content-editor'
				);
				return;
			}

			$primary_value = $translator->translate_text( $value, $primary_lang );
			$write         = $this->write_field( $primary_post_id, $target, $primary_value );
			if ( is_wp_error( $write ) ) {
				Logger::warning(
					sprintf( 'Content edit: post #%d — failed to update primary-language post #%d: %s', $post_id, $primary_post_id, $write->get_error_message() ),
					'content-editor'
				);
				return;
			}
		} elseif ( $is_cross_language ) {
			// Language codes differ but linguaforge_get_translations() itself isn't
			// available (LF active without its language-router module — not a
			// configuration this plugin otherwise expects). Too little information
			// to safely locate the primary post, so do nothing further rather than
			// risk writing the artist's edit to the wrong post.
			return;
		}

		if ( 'agnosis_biography' === $post_type && 'content' === $field ) {
			update_post_meta( $primary_post_id, '_agnosis_artist_prompt', wp_strip_all_tags( $primary_value ) );
		}

		// Step 4: fan out from the primary post to every other configured
		// language, excluding the artist's own source language — it already holds
		// the verbatim text from step 2 and must not be re-derived by translating
		// the primary post's re-translation of it back into that language. Skipped
		// entirely when this post has no recorded language at all: with no known
		// language to exclude, scheduling a fan-out risks re-deriving the artist's
		// own (unidentified) language version via an unexcluded round-trip.
		if ( '' !== $post_lang ) {
			LinguaForge::schedule_fanout( $primary_post_id, [ $post_lang ] );
		}
	}

	/**
	 * Propagate an artwork title edit — Phase 3's dual-title handling.
	 *
	 * `post_title` is deliberately NOT translated anywhere in this plugin's
	 * dual-title design (Compat\LinguaForge::hold_artist_title()): it is the
	 * artist's own words and stays byte-identical on every language version of
	 * the post, the same way a photo is language-neutral. So the propagation
	 * here has two independent halves:
	 *
	 *   1. Copy the new title verbatim to every sibling post in the group — no
	 *      AI call, synchronous, exactly like swap_photo_everywhere().
	 *   2. Separately, regenerate the AI-translated *display* title (a distinct
	 *      value — `_agnosis_translated_title`/`_agnosis_title_i18n`, the
	 *      subtitle the agnosis/artwork-title block renders below the artist's
	 *      own title). Translate the new title into the primary language and
	 *      store it as the primary post's own `_agnosis_translated_title`, then
	 *      reuse the *existing* deferred dispatch — `dispatch_translations()`
	 *      already runs `build_title_translations()` before
	 *      `request_translations()` — so `_agnosis_title_i18n` is rebuilt from
	 *      the fresh title and each sibling's own `_agnosis_translated_title` is
	 *      refreshed the next time it's (re)translated. No new LF hook needed.
	 *
	 * A stale display title left over from before the correction — showing an
	 * old AI translation next to a corrected original — is exactly the
	 * mismatch this method exists to prevent.
	 */
	private function propagate_title( int $post_id, string $new_title ): void {
		$primary_lang = function_exists( 'linguaforge_source_language' ) ? (string) linguaforge_source_language() : '';
		$post_lang    = (string) get_post_meta( $post_id, '_lf_lang', true );

		$targets = [ $post_id ];
		if ( function_exists( 'linguaforge_get_translations' ) ) {
			foreach ( linguaforge_get_translations( $post_id ) as $sibling_id ) {
				$sibling_id = (int) $sibling_id;
				if ( $sibling_id && ! in_array( $sibling_id, $targets, true ) ) {
					$targets[] = $sibling_id;
				}
			}
		}

		$primary_post_id = ( '' !== $primary_lang && $post_lang === $primary_lang ) ? $post_id : 0;

		foreach ( $targets as $target_id ) {
			if ( $target_id !== $post_id ) {
				// $post_id already holds $new_title verbatim via write_field() in save_text().
				wp_update_post( [ 'ID' => $target_id, 'post_title' => $new_title ] );
			}
			if ( ! $primary_post_id && '' !== $primary_lang && (string) get_post_meta( $target_id, '_lf_lang', true ) === $primary_lang ) {
				$primary_post_id = $target_id;
			}
		}

		if ( ! $primary_post_id ) {
			// No primary post identified (no LF language recorded, or no linked
			// sibling carries it yet) — the title itself is already saved
			// everywhere resolvable above; there's just no post to regenerate the
			// AI display-title map from.
			return;
		}

		if ( $primary_post_id === $post_id ) {
			// Editing directly on the primary-language post — the new title IS
			// the primary-language display title verbatim, no AI call needed.
			$primary_display_title = $new_title;
		} else {
			$translator = SubmissionTranslator::from_settings();
			if ( null === $translator ) {
				Logger::warning(
					sprintf(
						'Content edit: post #%d — title changed but no AI provider configured; translated display title on primary post #%d not refreshed.',
						$post_id,
						$primary_post_id
					),
					'content-editor'
				);
				return;
			}

			$primary_display_title = $translator->translate_text( $new_title, $primary_lang );
		}

		update_post_meta( $primary_post_id, '_agnosis_translated_title', $primary_display_title );

		if ( '' !== $post_lang ) {
			LinguaForge::schedule_fanout( $primary_post_id, [ $post_lang ] );
		}
	}

	// -------------------------------------------------------------------------
	// Front-end enqueue
	// -------------------------------------------------------------------------

	/**
	 * Conditionally enqueue the overlay editor — only on a singular CPT page,
	 * only when the current viewer is eligible to edit it. Anonymous visitors and
	 * other artists never download this script.
	 *
	 * Hooked to: wp_enqueue_scripts
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! is_singular( self::EDITABLE_POST_TYPES ) ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || ! self::is_editable_by_current_user( $post->ID ) ) {
			return;
		}

		wp_enqueue_script(
			'agnosis-content-editor',
			AGNOSIS_URL . 'blocks/content-editor/frontend.js',
			[],
			AGNOSIS_VERSION,
			[ 'in_footer' => true ]
		);

		wp_enqueue_style(
			'agnosis-content-editor',
			AGNOSIS_URL . 'blocks/content-editor/frontend.css',
			[],
			AGNOSIS_VERSION
		);

		wp_localize_script( 'agnosis-content-editor', 'agnosisContentEditor', [
			'apiUrl'            => rest_url( 'agnosis/v1/content/' . $post->ID . '/text' ),
			'photoApiUrl'       => rest_url( 'agnosis/v1/content/' . $post->ID . '/photo' ),
			'photoRestoreUrl'   => rest_url( 'agnosis/v1/content/' . $post->ID . '/photo/restore' ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'postId'            => $post->ID,
			'i18n'              => [
				'save'            => __( 'Save', 'agnosis' ),
				'cancel'          => __( 'Cancel', 'agnosis' ),
				'saving'          => __( 'Saving…', 'agnosis' ),
				'saved'           => __( 'Saved — translations will update within a few minutes.', 'agnosis' ),
				'error'           => __( 'Something went wrong. Please try again.', 'agnosis' ),
				'replacePhoto'    => __( 'Replace photo', 'agnosis' ),
				'uploading'       => __( 'Uploading…', 'agnosis' ),
				'photoSaved'      => __( 'Photo replaced — the original is kept and can be restored later.', 'agnosis' ),
				'restorePhoto'    => __( 'Restore earlier photo', 'agnosis' ),
				'restoring'       => __( 'Restoring…', 'agnosis' ),
				'photoRestored'   => __( 'Restored the earlier photo.', 'agnosis' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Editable-region markup (post content / excerpt)
	// -------------------------------------------------------------------------

	/**
	 * Wrap post content in an editable-region container when the current viewer
	 * may edit this post. No-ops (returns $content unchanged) otherwise, and only
	 * ever runs on the main query's singular post to avoid decorating unrelated
	 * content (widgets, blocks referencing other posts) elsewhere on the page.
	 *
	 * Hooked to: the_content (filter)
	 */
	public function decorate_content( string $content ): string {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::EDITABLE_POST_TYPES, true ) ) {
			return $content;
		}

		// Every EDITABLE_POST_TYPES entry has a 'content' field (see EDITABLE_FIELDS
		// above) — no isset() needed here, unlike decorate_excerpt() below where
		// 'excerpt' genuinely isn't present for every post type.
		if ( ! self::is_editable_by_current_user( $post->ID ) ) {
			return $content;
		}

		return sprintf(
			'<div class="agnosis-editable" data-agnosis-edit-field="content" data-agnosis-post-id="%d">%s</div>',
			(int) $post->ID,
			$content
		);
	}

	/**
	 * Same as decorate_content() for the artwork excerpt/description.
	 *
	 * Hooked to: the_excerpt (filter)
	 */
	public function decorate_excerpt( string $excerpt ): string {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $excerpt;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::EDITABLE_POST_TYPES, true ) ) {
			return $excerpt;
		}

		if ( ! isset( self::EDITABLE_FIELDS[ $post->post_type ]['excerpt'] ) || ! self::is_editable_by_current_user( $post->ID ) ) {
			return $excerpt;
		}

		return sprintf(
			'<div class="agnosis-editable" data-agnosis-edit-field="excerpt" data-agnosis-post-id="%d">%s</div>',
			(int) $post->ID,
			$excerpt
		);
	}

	// -------------------------------------------------------------------------
	// Editable-region markup (featured image — Phase 2)
	// -------------------------------------------------------------------------

	/**
	 * Wrap the featured-image markup in an editable-region container when the
	 * current viewer may edit this post.
	 *
	 * `post_thumbnail_html` fires for every the_post_thumbnail()/
	 * get_the_post_thumbnail() call regardless of theme (classic or FSE via the
	 * core post-featured-image block, which renders through this same core API),
	 * so this covers the featured image on all three CPTs without any
	 * theme-specific markup assumptions. Artwork gallery images beyond the
	 * featured one are not decorated here — the REST endpoint already accepts an
	 * `attachment_id` for any gallery slot, but wiring the overlay onto a
	 * theme-specific gallery block is left for a fast-follow.
	 *
	 * Hooked to: post_thumbnail_html (filter)
	 *
	 * @param string $html              Current featured-image HTML.
	 * @param int    $post_id           Post the thumbnail belongs to.
	 * @param int    $post_thumbnail_id Attachment ID of the thumbnail.
	 */
	public function decorate_thumbnail( string $html, int $post_id, int $post_thumbnail_id ): string {
		if ( '' === $html ) {
			return $html;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::EDITABLE_POST_TYPES, true ) ) {
			return $html;
		}

		if ( ! self::is_editable_by_current_user( $post_id ) ) {
			return $html;
		}

		$has_original = '' !== (string) get_post_meta( $post_thumbnail_id, '_agnosis_original_attachment_id', true );

		return sprintf(
			'<div class="agnosis-editable agnosis-editable--photo" data-agnosis-edit-field="photo" data-agnosis-post-id="%d" data-agnosis-attachment-id="%d" data-agnosis-has-original="%s">%s</div>',
			$post_id,
			$post_thumbnail_id,
			$has_original ? '1' : '0',
			$html
		);
	}

	// -------------------------------------------------------------------------
	// Editable-region markup (biography title — Phase 3)
	// -------------------------------------------------------------------------

	/**
	 * Wrap a biography's title in an editable-region container.
	 *
	 * Artwork's title uses a custom render callback (Profile::render_artwork_title())
	 * that reads post_title directly rather than calling get_the_title()/the_title(),
	 * so it's decorated there instead — see that method. Biography (the only other
	 * post type with 'title' in EDITABLE_FIELDS) renders its title through the
	 * theme's normal core post-title block, which does go through this filter.
	 * Event has no 'title' field in EDITABLE_FIELDS, so it's never reached here.
	 *
	 * Scoped tightly to the current singular main-query post: the_title filter
	 * fires for every title on a page (menus, related-post lists, admin screens),
	 * not just the one being viewed.
	 *
	 * Hooked to: the_title (filter)
	 *
	 * @param string $title   Current title HTML/text.
	 * @param int    $post_id Post the title belongs to.
	 */
	public function decorate_title( string $title, int $post_id ): string {
		if ( '' === $title || ! in_the_loop() || ! is_main_query() ) {
			return $title;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post
			|| in_array( $post->post_type, self::DUAL_TITLE_POST_TYPES, true )
			|| ! isset( self::EDITABLE_FIELDS[ $post->post_type ]['title'] )
		) {
			return $title;
		}

		if ( ! self::is_editable_by_current_user( $post_id ) ) {
			return $title;
		}

		return sprintf(
			'<span class="agnosis-editable" data-agnosis-edit-field="title" data-agnosis-post-id="%d">%s</span>',
			$post_id,
			$title
		);
	}
}
