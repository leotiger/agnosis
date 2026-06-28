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
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ReviewEndpoints {

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
		if ( ! $post || 'agnosis_artwork' !== $post->post_type ) {
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
			'post_status'  => $should_publish ? 'publish' : 'draft',
		];

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

		if ( $should_publish ) {
			delete_post_meta( $post_id, '_agnosis_review_token' );
			delete_post_meta( $post_id, '_agnosis_review_expiry' );
			do_action( 'agnosis_post_published', $post_id );
		}

		return new WP_REST_Response(
			[
				'status'   => $should_publish ? 'published' : 'saved',
				'post_id'  => $post_id,
				'post_url' => $should_publish ? get_permalink( $post_id ) : null,
			],
			200
		);
	}

	public function approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$post = get_post( $post_id );
		if ( ! $post || 'agnosis_artwork' !== $post->post_type ) {
			return new WP_Error( 'agnosis_not_found', __( 'Submission not found.', 'agnosis' ), [ 'status' => 404 ] );
		}

		$auth = $this->check_access( $request, $post_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( 'draft' !== $post->post_status ) {
			return new WP_Error( 'agnosis_not_draft', __( 'This submission is not awaiting review.', 'agnosis' ), [ 'status' => 409 ] );
		}

		$result = wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ], true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Invalidate the token so the email link cannot be reused.
		delete_post_meta( $post_id, '_agnosis_review_token' );
		delete_post_meta( $post_id, '_agnosis_review_expiry' );

		// Trigger ActivityPub broadcast and any other publish subscribers.
		do_action( 'agnosis_post_published', $post_id );

		return new WP_REST_Response(
			[
				'status'   => 'published',
				'post_id'  => $post_id,
				'post_url' => get_permalink( $post_id ),
			],
			200
		);
	}

	public function reject( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$post = get_post( $post_id );
		if ( ! $post || 'agnosis_artwork' !== $post->post_type ) {
			return new WP_Error( 'agnosis_not_found', __( 'Submission not found.', 'agnosis' ), [ 'status' => 404 ] );
		}

		$auth = $this->check_access( $request, $post_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( 'draft' !== $post->post_status ) {
			return new WP_Error( 'agnosis_not_draft', __( 'This submission is not awaiting review.', 'agnosis' ), [ 'status' => 409 ] );
		}

		wp_trash_post( $post_id );

		delete_post_meta( $post_id, '_agnosis_review_token' );
		delete_post_meta( $post_id, '_agnosis_review_expiry' );

		do_action( 'agnosis_submission_rejected', $post_id, (int) $post->post_author, 0, [] );

		return new WP_REST_Response( [ 'status' => 'rejected', 'post_id' => $post_id ], 200 );
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
	public function check_permission( WP_REST_Request $request ): true|WP_Error {
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

	private function check_access( WP_REST_Request $request, int $post_id ): true|WP_Error {
		// Path 1 — token from query string (email link).
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( ! empty( $token ) ) {
			return $this->verify_token( $post_id, $token );
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
	 * @param int    $post_id Post ID.
	 * @param string $token   Token from the request.
	 * @return true|WP_Error
	 */
	private function verify_token( int $post_id, string $token ): true|WP_Error {
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
