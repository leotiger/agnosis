<?php
/**
 * REST endpoints for artist submission review.
 *
 * POST /agnosis/v1/review/{id}/approve  — publish the draft artwork.
 * POST /agnosis/v1/review/{id}/reject   — trash the draft artwork.
 *
 * Authentication accepts two paths:
 *   1. Token-based  — ?token=<signed_token> in the URL (email links, no login needed).
 *   2. WP auth      — logged-in user who is the post author or has manage_options.
 *
 * On approve: post_status set to 'publish', fires 'agnosis_post_published'
 *             (triggers ActivityPub broadcast).
 * On reject:  post moved to trash.
 *
 * Post types (2026-07-08 fix): every method here used to hard-check
 * `'agnosis_artwork' !== $post->post_type`, rejecting any biography or event
 * draft with a 404 ("Submission not found") regardless of token validity —
 * even though PostCreator::create_post() has always written the same
 * `_agnosis_review_token`/`_agnosis_review_expiry` pair for all three CPTs,
 * and Artist\ApplicationBiography explicitly documents biography drafts going
 * through "the exact same review pipeline every other Agnosis post uses"
 * (this class). RemovalEndpoints was already fixed for this (2026-07-06); this
 * class was missed. Now gated on REVIEWABLE_POST_TYPES instead.
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\Compat\LinguaForge;
use Agnosis\Core\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ReviewEndpoints {

	/** Every CPT PostCreator::create_post() can draft with a review token. */
	public const REVIEWABLE_POST_TYPES = [ 'agnosis_artwork', 'agnosis_biography', 'agnosis_event' ];

	public function register_routes(): void {
		$id_arg = [
			'id' => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
		];

		register_rest_route( 'agnosis/v1', '/review/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'save' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => array_merge( $id_arg, [
				'title'   => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
				'excerpt' => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field' ],
				'body'    => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field' ],
				'tags'    => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
				'publish' => [ 'type' => 'boolean', 'default' => false ],
			] ),
		] );

		register_rest_route( 'agnosis/v1', '/review/(?P<id>\d+)/approve', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'approve' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => $id_arg,
		] );

		register_rest_route( 'agnosis/v1', '/review/(?P<id>\d+)/reject', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'reject' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => $id_arg,
		] );
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * Save artist edits to a draft submission.
	 *
	 * Accepts title, excerpt, body and tags. The image block(s) already embedded
	 * at the top of post_content are preserved — only the text body is replaced.
	 * Pass ?publish=true to save and publish in a single call.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::REVIEWABLE_POST_TYPES, true ) ) {
			return new WP_Error( 'agnosis_not_found', __( 'Submission not found.', 'agnosis' ), [ 'status' => 404 ] );
		}

		$auth = $this->check_access( $request, $post_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( ! in_array( $post->post_status, [ 'draft', 'pending' ], true ) ) {
			return new WP_Error( 'agnosis_not_draft', __( 'This submission is not awaiting review.', 'agnosis' ), [ 'status' => 409 ] );
		}

		// Preserve any image/gallery blocks embedded at the top of the content.
		$image_blocks = $this->extract_image_blocks( $post->post_content );
		$body         = (string) ( $request->get_param( 'body' ) ?? '' );
		$body_block   = $body ? '<!-- wp:paragraph --><p>' . wp_kses_post( $body ) . '</p><!-- /wp:paragraph -->' : '';
		$new_content  = $image_blocks ? $image_blocks . "\n\n" . $body_block : $body_block;

		$should_publish = (bool) $request->get_param( 'publish' );

		$update_data = [
			'ID'           => $post_id,
			'post_title'   => (string) ( $request->get_param( 'title' ) ?? $post->post_title ),
			'post_excerpt' => (string) ( $request->get_param( 'excerpt' ) ?? $post->post_excerpt ),
			'post_content' => $new_content ?: $post->post_content,
		];
		// post_status is only ever touched here for the plain-save (not
		// publishing) case. When $should_publish is true, finalize_publish()
		// below owns the status transition — for a pending-update staging
		// draft (patch 18) it must NEVER become 'publish' itself (it gets
		// deleted, not published), so this call leaving it alone and letting
		// finalize_publish() decide is what makes both cases correct with no
		// special-casing needed right here.
		if ( ! $should_publish ) {
			$update_data['post_status'] = 'draft';
		}

		$result = wp_update_post( $update_data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update tags if provided.
		$tags_raw = (string) ( $request->get_param( 'tags' ) ?? '' );
		if ( '' !== $tags_raw ) {
			$tags = array_map( 'trim', explode( ',', $tags_raw ) );
			wp_set_post_tags( $post_id, array_filter( $tags ) );
		}

		$final_id = $post_id;
		if ( $should_publish ) {
			$final_id = $this->finalize_publish( $post_id );
			if ( is_wp_error( $final_id ) ) {
				return $final_id;
			}
		}

		return new WP_REST_Response(
			[
				'status'   => $should_publish ? 'published' : 'saved',
				'post_id'  => $final_id,
				'post_url' => $should_publish ? get_permalink( $final_id ) : null,
			],
			200
		);
	}

	public function approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::REVIEWABLE_POST_TYPES, true ) ) {
			return new WP_Error( 'agnosis_not_found', __( 'Submission not found.', 'agnosis' ), [ 'status' => 404 ] );
		}

		$auth = $this->check_access( $request, $post_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( 'draft' !== $post->post_status ) {
			return new WP_Error( 'agnosis_not_draft', __( 'This submission is not awaiting review.', 'agnosis' ), [ 'status' => 409 ] );
		}

		$final_id = $this->finalize_publish( $post_id );
		if ( is_wp_error( $final_id ) ) {
			return $final_id;
		}

		return new WP_REST_Response(
			[
				'status'   => 'published',
				'post_id'  => $final_id,
				'post_url' => get_permalink( $final_id ),
			],
			200
		);
	}

	public function reject( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::REVIEWABLE_POST_TYPES, true ) ) {
			return new WP_Error( 'agnosis_not_found', __( 'Submission not found.', 'agnosis' ), [ 'status' => 404 ] );
		}

		$auth = $this->check_access( $request, $post_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( 'draft' !== $post->post_status ) {
			return new WP_Error( 'agnosis_not_draft', __( 'This submission is not awaiting review.', 'agnosis' ), [ 'status' => 409 ] );
		}

		// A pending-update staging draft (patch 18) is discarded outright —
		// force-deleted, never trashed — rather than the normal reject()
		// trash-and-clear-meta path: there is no "live" version of THIS post
		// to preserve in the trash, it was only ever a proposed change, and
		// the post it's an update FOR was never touched in the first place,
		// so there is nothing to restore or protect. wp_delete_post()'s
		// cascade removes all of this draft's own postmeta (including the
		// review token) with it — nothing left to clean up separately.
		if ( get_post_meta( $post_id, '_agnosis_pending_update_for', true ) ) {
			wp_delete_post( $post_id, true );
		} else {
			wp_trash_post( $post_id );

			delete_post_meta( $post_id, '_agnosis_review_token' );
			delete_post_meta( $post_id, '_agnosis_review_expiry' );
			delete_post_meta( $post_id, '_agnosis_review_backtranslation' );
		}

		do_action( 'agnosis_submission_rejected', $post_id, (int) $post->post_author, 0, [] );

		return new WP_REST_Response( [ 'status' => 'rejected', 'post_id' => $post_id ], 200 );
	}

	/**
	 * Finalize an approved submission — the shared tail of both approve()
	 * and save( publish: true ).
	 *
	 * Normal case (no pending-update meta): publishes $post_id itself,
	 * exactly as always — flips post_status to 'publish', clears the review
	 * token, fires 'agnosis_post_published' (ActivityPub broadcast, Lingua
	 * Forge language/translation scheduling).
	 *
	 * Staged case (patch 18 — "true staging"): $post_id is a draft that was
	 * never meant to become a post of its own — it was standing in for a
	 * pending update to an already-published post ($pending_for, read from
	 * '_agnosis_pending_update_for' — see PostCreator::create_post()). Its
	 * current title/excerpt/content and the metadata PostCreator would
	 * otherwise have written directly are copied onto that live post
	 * instead, the live post's own post_status is left completely alone
	 * (never toggled — it was 'publish' throughout and stays that way), the
	 * staging draft is deleted, and 'agnosis_post_published' does NOT fire:
	 * this is an edit to already-published content, not a new publish, the
	 * same distinction Artist\ContentEditor's own direct-edit-while-
	 * published flow already draws by never firing that action either.
	 *
	 * @param int $post_id The draft (ordinary or staging) that was just approved.
	 * @return int|WP_Error The FINAL live post ID — equal to $post_id unless
	 *                      this was a staged update, in which case it's
	 *                      $pending_for. WP_Error if applying the update failed.
	 */
	private function finalize_publish( int $post_id ): int|WP_Error {
		$pending_for = (int) get_post_meta( $post_id, '_agnosis_pending_update_for', true );

		if ( ! $pending_for ) {
			Logger::info( sprintf( 'finalize_publish(#%d): no pending-update meta — publishing this post directly (not a staged update).', $post_id ), 'review' );

			$result = wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ], true );
			if ( is_wp_error( $result ) ) {
				Logger::error( sprintf( 'finalize_publish(#%d): direct publish failed — %s', $post_id, $result->get_error_message() ), 'review' );
				return $result;
			}

			delete_post_meta( $post_id, '_agnosis_review_token' );
			delete_post_meta( $post_id, '_agnosis_review_expiry' );
			delete_post_meta( $post_id, '_agnosis_review_backtranslation' );

			do_action( 'agnosis_post_published', $post_id );

			return $post_id;
		}

		$staging = get_post( $post_id );
		$target  = get_post( $pending_for );
		Logger::info(
			sprintf(
				'finalize_publish(#%d): staged update — pending_for=#%d, staging exists=%s, target exists=%s, target status=%s.',
				$post_id,
				$pending_for,
				$staging ? 'yes' : 'NO',
				$target ? 'yes' : 'NO',
				$target ? $target->post_status : 'n/a'
			),
			'review'
		);
		if ( ! $staging || ! $target ) {
			Logger::error( sprintf( 'finalize_publish(#%d): staged update aborted — staging or target post missing (pending_for=#%d).', $post_id, $pending_for ), 'review' );
			return new WP_Error(
				'agnosis_pending_target_missing',
				__( 'The original published item could not be found.', 'agnosis' ),
				[ 'status' => 500 ]
			);
		}

		$result = wp_update_post(
			[
				'ID'           => $pending_for,
				'post_title'   => $staging->post_title,
				'post_excerpt' => $staging->post_excerpt,
				'post_content' => $staging->post_content,
			],
			true
		);
		if ( is_wp_error( $result ) ) {
			Logger::error( sprintf( 'finalize_publish(#%d): wp_update_post() onto target #%d failed — %s', $post_id, $pending_for, $result->get_error_message() ), 'review' );
			return $result;
		}

		// Read the target straight back from the DB (bypassing the object
		// cache wp_update_post() just primed) to confirm the write actually
		// landed — the single most useful line in this log if a staged
		// update ever again appears to "succeed" without visibly changing
		// the live post.
		clean_post_cache( $pending_for );
		$verify = get_post( $pending_for );
		Logger::info(
			sprintf(
				'finalize_publish(#%d): wp_update_post() on target #%d returned #%d. Re-read title="%s", content length=%d.',
				$post_id,
				$pending_for,
				(int) $result,
				$verify ? $verify->post_title : '(missing)',
				$verify ? strlen( $verify->post_content ) : -1
			),
			'review'
		);

		// Everything PostCreator::create_post() would otherwise have written
		// directly onto the live post for a non-staged merge. Deliberately
		// excludes write-once/identity-style meta ('_agnosis_original_title',
		// '_agnosis_intake_endpoint') — those are never meant to change once
		// set, on a staged update any more than on a direct one.
		foreach ( [
			'_agnosis_gallery_ids',
			'_agnosis_artist_prompt',
			'_agnosis_translated_title',
			'_agnosis_queue_id',
			'_agnosis_event_location',
			'_agnosis_event_address',
			'_agnosis_event_date',
			'_agnosis_event_timezone',
			'_agnosis_biography_portfolio_url',
			'_agnosis_biography_portfolio_embedded',
			'_agnosis_dropped_links',
		] as $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( '' !== $value ) {
				update_post_meta( $pending_for, $meta_key, $value );
			}
		}

		$tags = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
		// wp_get_post_tags() can return WP_Error (e.g. an invalid taxonomy) —
		// a WP_Error object is never empty(), so the old bare `! empty( $tags )`
		// check would have passed it straight into wp_set_post_tags(), which
		// only accepts array|string for its second parameter.
		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			wp_set_post_tags( $pending_for, $tags );
		}

		// Staging post was never meant to be kept — delete outright (skip
		// trash); its own postmeta (including the review token) goes with it.
		wp_delete_post( $post_id, true );

		// Refresh Lingua Forge's translated siblings with the corrected
		// content. 'agnosis_post_published' deliberately does NOT fire here
		// (this is an edit to already-live content, not a new publish — see
		// this method's own docblock) — but that also means nothing else
		// would ever tell Lingua Forge the source content changed. Without
		// this call, every translated sibling silently goes stale on every
		// single staged update forever: the artist's correction lands on the
		// primary/source post but never reaches any other language. Same
		// explicit schedule_fanout() call Artist\ContentEditor already makes
		// for its own direct-edit-to-published-content path, for the exact
		// same reason.
		LinguaForge::schedule_fanout( $pending_for );

		Logger::info( sprintf( 'finalize_publish(#%d): staged update applied to #%d and staging draft deleted.', $post_id, $pending_for ), 'review' );

		return $pending_for;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract the leading image/gallery block markup from post content.
	 *
	 * When the artist edits the body text we must not touch the AI-uploaded image
	 * block(s) at the top of the content — only the text below them changes.
	 *
	 * @param string $content Existing post_content.
	 * @return string Image/gallery block markup, or empty string if none.
	 */
	private function extract_image_blocks( string $content ): string {
		// Match one or more leading wp:image or wp:gallery blocks.
		if ( preg_match( '/^((?:<!-- wp:(?:image|gallery)[^>]*-->.*?<!-- \/wp:(?:image|gallery) -->[\s]*)+)/s', $content, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	// -------------------------------------------------------------------------
	// Access control
	// -------------------------------------------------------------------------

	/**
	 * Verify that the request is authorised.
	 *
	 * Accepts either a valid signed token in the query string or a logged-in
	 * user who owns the post (or has manage_options).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @param int             $post_id The artwork post ID.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	/**
	 * REST permission gate — called before the route callback.
	 *
	 * Accepts the request if a token is present (will be verified inside the
	 * callback) OR the user is already authenticated. Returning WP_Error here
	 * ensures the route is unreachable even if check_access() has a logic bug.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ): bool|WP_Error {
		if ( ! empty( sanitize_text_field( (string) $request->get_param( 'token' ) ) ) ) {
			return true; // Token path — verified inside the callback.
		}
		if ( is_user_logged_in() ) {
			return true; // Auth path — specific permissions verified inside the callback.
		}
		return new WP_Error(
			'agnosis_auth_required',
			__( 'Authentication required.', 'agnosis' ),
			[ 'status' => 401 ]
		);
	}

	private function check_access( WP_REST_Request $request, int $post_id ): bool|WP_Error {
		// Path 1 — token from query string (email link).
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( ! empty( $token ) ) {
			return self::verify_token( $post_id, $token );
		}

		// Path 2 — logged-in user.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error(
				'agnosis_auth_required',
				__( 'Authentication required.', 'agnosis' ),
				[ 'status' => 401 ]
			);
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$post = get_post( $post_id );
		if ( $post && (int) $post->post_author === $user_id ) {
			return true;
		}

		return new WP_Error(
			'agnosis_forbidden',
			__( 'You do not have permission to review this submission.', 'agnosis' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Validate a signed review token against what is stored in post meta.
	 *
	 * Public and static (fourth audit §3a): a pure, read-only check with no
	 * dependency on instance state, so `Publishing\ReviewConfirm` can reuse the
	 * exact same check the REST layer performs — the token-first-then-act order
	 * only holds if both sides use one authoritative implementation, not two
	 * copies that could drift apart.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Token from the request.
	 * @return true|WP_Error
	 */
	public static function verify_token( int $post_id, string $token ): bool|WP_Error {
		$stored_token  = (string) get_post_meta( $post_id, '_agnosis_review_token', true );
		$stored_expiry = (int) get_post_meta( $post_id, '_agnosis_review_expiry', true );

		if ( empty( $stored_token ) ) {
			return new WP_Error(
				'agnosis_token_invalid',
				__( 'Review link not found or already used.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! hash_equals( $stored_token, $token ) ) {
			return new WP_Error(
				'agnosis_token_invalid',
				__( 'Invalid review token.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		if ( $stored_expiry && time() > $stored_expiry ) {
			return new WP_Error(
				'agnosis_token_expired',
				__( 'This review link has expired. Please log in to manage your submissions.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}
}
