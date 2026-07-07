<?php
/**
 * Artist departure — self-removal, admin ban/delete, community removal vote.
 *
 * Three independent departure paths:
 *
 *  1. SELF-REMOVAL — an artist requests to leave and have all their content
 *     deleted. A confirmation email is sent; the artist clicks the link to
 *     finalise. On confirmation: all Agnosis CPT posts are permanently deleted,
 *     the agnosis_artist role is removed, and the membership row is set to
 *     'left'. The WP user account itself is deleted.
 *
 *  2. ADMIN REVOCATION — two actions:
 *     • Temporary ban  — removes role, sets status='banned', records banned_until
 *       (NULL = indefinite). A daily cron reinstates artists whose ban has expired.
 *     • Permanent delete — deletes content + WP account, sets status='left'.
 *
 *  3. COMMUNITY REMOVAL VOTE — any artist can nominate another for removal.
 *     When the nomination count reaches the configured threshold (default 3)
 *     OR when an admin initiates directly, the vote opens to the full community.
 *     If >50 % of active artists vote yes within the voting window, the artist
 *     is removed (same effect as self-removal). If the window closes without a
 *     majority, the vote fails and no action is taken.
 *
 * REST endpoints (registered via register_routes()):
 *   POST /agnosis/v1/departure/request        — artist requests self-removal
 *   POST /agnosis/v1/removal/nominate/{id}    — artist nominates another for removal
 *   POST /agnosis/v1/removal/vote/{id}        — artist casts a community removal vote
 *
 * Admin-post handlers (registered via Plugin.php):
 *   admin_post_agnosis_ban_artist             — admin bans an artist
 *   admin_post_agnosis_delete_artist          — admin permanently deletes an artist
 *   admin_post_agnosis_initiate_removal_vote  — admin opens a community vote directly
 *
 * Self-removal confirmation is handled by a template_redirect shim that reads
 * the ?agnosis_departure=1&token={token} query string (see Plugin.php).
 *
 * Community removal votes cast via the email link (as opposed to the
 * authenticated REST route above) are handled by a separate template_redirect
 * shim, RemovalVoteConfirm, which reads the ?agnosis_removal_vote=1&… query
 * string and calls record_vote_on_request() below (see Plugin.php).
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Departure {

	/** Agnosis CPT slugs whose posts are deleted on departure. */
	private const AGNOSIS_POST_TYPES = [
		'agnosis_artwork',
		'agnosis_biography',
		'agnosis_event',
	];

	// -------------------------------------------------------------------------
	// Frontend shim — self-removal confirmation
	// -------------------------------------------------------------------------

	/**
	 * Hooked to template_redirect (priority 1).
	 *
	 * Processes ?agnosis_departure=1&token={token} links from the confirmation
	 * email. On success: executes removal and redirects to /?agnosis_departure=confirmed.
	 * On failure: redirects to /?agnosis_departure=invalid.
	 */
	public function handle_departure_confirm(): void {
		if ( ! isset( $_GET['agnosis_departure'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $token ) {
			wp_safe_redirect( add_query_arg( 'agnosis_departure', 'invalid', home_url( '/' ) ) );
			exit;
		}

		$result = $this->confirm_self_removal( $token );

		$outcome = $result ? 'confirmed' : 'invalid';
		wp_safe_redirect( add_query_arg( 'agnosis_departure', $outcome, home_url( '/' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// REST route registration
	// -------------------------------------------------------------------------

	public function register_routes(): void {
		register_rest_route( 'agnosis/v1', '/departure/request', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'request_self_removal' ],
			'permission_callback' => [ $this, 'require_artist' ],
		] );

		register_rest_route( 'agnosis/v1', '/removal/nominate/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'nominate' ],
			'permission_callback' => [ $this, 'require_artist' ],
			'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
		] );

		register_rest_route( 'agnosis/v1', '/removal/vote/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'cast_vote' ],
			'permission_callback' => [ $this, 'require_artist' ],
			'args'                => [
				'id'   => [ 'type' => 'integer', 'required' => true ],
				'vote' => [
					'type'              => 'string',
					'required'          => true,
					'enum'              => [ 'yes', 'no' ],
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function require_artist(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'agnosis_unauthorized', __( 'You must be logged in.', 'agnosis' ), [ 'status' => 401 ] );
		}
		$user = wp_get_current_user();
		if ( ! in_array( 'agnosis_artist', (array) $user->roles, true ) ) {
			return new WP_Error( 'agnosis_forbidden', __( 'Artist access required.', 'agnosis' ), [ 'status' => 403 ] );
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Self-removal
	// -------------------------------------------------------------------------

	/**
	 * REST handler: artist requests their own removal.
	 *
	 * Generates a CSPRNG token, stores it on the membership row, and fires
	 * 'agnosis_departure_confirmation_requested' so DepartureNotification can
	 * send the confirmation email. The actual deletion does not happen until
	 * confirm_self_removal() is called via the frontend shim.
	 */
	public function request_self_removal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$ok      = $this->initiate_removal_for_user( $user_id );

		if ( ! $ok ) {
			return new WP_Error(
				'agnosis_not_found',
				__( 'No active membership found for this account.', 'agnosis' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( [ 'status' => 'confirmation_sent' ], 200 );
	}

	/**
	 * Generate a removal-confirmation token for the given user and fire the
	 * confirmation-email action.
	 *
	 * Can be called from any context (REST, cron, email alias handler) — it does
	 * not rely on the current HTTP request or the logged-in user.
	 *
	 * @param int $user_id WP user ID of the artist requesting removal.
	 * @return bool True when the confirmation email was dispatched; false when the
	 *              user has no active membership row.
	 */
	public function initiate_removal_for_user( int $user_id ): bool {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, status FROM {$wpdb->prefix}agnosis_applications WHERE wp_user_id = %d",
				$user_id
			)
		);

		if ( ! $row || ! in_array( $row->status, [ 'admitted', 'banned' ], true ) ) {
			return false;
		}

		$token = bin2hex( random_bytes( 32 ) );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_applications',
			[ 'removal_token' => $token ],
			[ 'id' => (int) $row->id ],
			[ '%s' ],
			[ '%d' ]
		);

		/**
		 * Fires when an artist requests self-removal.
		 *
		 * @param int    $user_id  The WP user ID of the requesting artist.
		 * @param string $token    The single-use confirmation token.
		 */
		do_action( 'agnosis_departure_confirmation_requested', $user_id, $token );

		return true;
	}

	/**
	 * Called by the template_redirect shim when the confirmation token is valid.
	 *
	 * Deletes all Agnosis content, removes the role, marks the row 'left', and
	 * deletes the WP user account. Returns false when the token is not found or
	 * already used. Returns true on success.
	 *
	 * @param string $token  The single-use confirmation token from the email link.
	 */
	public function confirm_self_removal( string $token ): bool {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, wp_user_id FROM {$wpdb->prefix}agnosis_applications
				  WHERE removal_token = %s AND status IN ('admitted','banned')",
				$token
			)
		);

		if ( ! $row || ! $row->wp_user_id ) {
			return false;
		}

		$user_id        = (int) $row->wp_user_id;
		$application_id = (int) $row->id;

		$this->execute_removal( $user_id, $application_id, 'left' );

		/**
		 * Fires after an artist has confirmed and completed self-removal.
		 *
		 * @param int $user_id        WP user ID (account is now deleted).
		 * @param int $application_id Membership row ID.
		 */
		do_action( 'agnosis_artist_left', $user_id, $application_id );

		return true;
	}

	// -------------------------------------------------------------------------
	// Admin revocation
	// -------------------------------------------------------------------------

	/**
	 * Temporarily or indefinitely ban an artist.
	 *
	 * Removes the agnosis_artist role. Sets status='banned' and records
	 * banned_until (NULL for indefinite). Fires 'agnosis_artist_banned'.
	 *
	 * @param int             $application_id  Membership row ID.
	 * @param \DateTimeInterface|null $until   Expiry datetime or null for indefinite.
	 */
	public function admin_ban( int $application_id, ?\DateTimeInterface $until = null ): bool {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, wp_user_id FROM {$wpdb->prefix}agnosis_applications
				  WHERE id = %d AND status = 'admitted'",
				$application_id
			)
		);

		if ( ! $row ) {
			return false;
		}

		$user_id     = (int) $row->wp_user_id;
		$banned_until = $until ? $until->format( 'Y-m-d H:i:s' ) : null;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_applications',
			[
				'status'      => 'banned',
				'banned_until' => $banned_until,
				'resolved_at' => current_time( 'mysql' ),
			],
			[ 'id' => $application_id ],
			[ '%s', $banned_until !== null ? '%s' : 'NULL', '%s' ],
			[ '%d' ]
		);

		$user = get_user_by( 'id', $user_id );
		if ( $user ) {
			$user->remove_role( 'agnosis_artist' );
		}

		/**
		 * Fires after an admin bans an artist.
		 *
		 * @param int             $user_id        WP user ID.
		 * @param int             $application_id Membership row ID.
		 * @param string|null     $banned_until   MySQL datetime string or null.
		 */
		do_action( 'agnosis_artist_banned', $user_id, $application_id, $banned_until );

		return true;
	}

	/**
	 * Permanently delete an artist: content, WP account, membership status.
	 *
	 * @param int $application_id  Membership row ID.
	 */
	public function admin_delete( int $application_id ): bool {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, wp_user_id FROM {$wpdb->prefix}agnosis_applications
				  WHERE id = %d AND status IN ('admitted','banned')",
				$application_id
			)
		);

		if ( ! $row || ! $row->wp_user_id ) {
			return false;
		}

		$user_id = (int) $row->wp_user_id;

		$this->execute_removal( $user_id, $application_id, 'left' );

		/**
		 * Fires after an admin permanently deletes an artist.
		 *
		 * @param int $user_id        WP user ID (account now deleted).
		 * @param int $application_id Membership row ID.
		 */
		do_action( 'agnosis_artist_deleted_by_admin', $user_id, $application_id );

		return true;
	}

	// -------------------------------------------------------------------------
	// Temporary ban expiry cron
	// -------------------------------------------------------------------------

	/**
	 * Cron callback: reinstate artists whose temporary ban has expired.
	 *
	 * Runs daily via 'agnosis_check_bans'. Finds all rows with
	 * status='banned' and a non-null banned_until in the past, restores the
	 * agnosis_artist role, and fires 'agnosis_artist_reinstated'.
	 */
	public function check_expired_bans(): void {
		global $wpdb;

		$expired = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, wp_user_id FROM {$wpdb->prefix}agnosis_applications
				  WHERE status = 'banned' AND banned_until IS NOT NULL AND banned_until <= %s",
				current_time( 'mysql' )
			)
		);

		foreach ( $expired as $row ) {
			$user_id = (int) $row->wp_user_id;

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prefix . 'agnosis_applications',
				[
					'status'      => 'admitted',
					'banned_until' => null,
					'resolved_at' => null,
				],
				[ 'id' => (int) $row->id ],
				[ '%s', 'NULL', 'NULL' ],
				[ '%d' ]
			);

			$user = get_user_by( 'id', $user_id );
			if ( $user ) {
				$user->add_role( 'agnosis_artist' );
			}

			/** @param int $user_id  WP user ID. */
			do_action( 'agnosis_artist_reinstated', $user_id );
		}
	}

	// -------------------------------------------------------------------------
	// Community removal vote
	// -------------------------------------------------------------------------

	/**
	 * REST handler: artist nominates another artist for community removal.
	 *
	 * Records the nominating artist's yes vote on an open or nominating removal
	 * request for the subject. Creates the request if none exists. When the
	 * nomination count reaches the threshold, opens the vote to all artists.
	 * Artists cannot nominate themselves.
	 */
	public function nominate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$subject_user_id = (int) $request->get_param( 'id' );
		$voter_id        = get_current_user_id();

		if ( $subject_user_id === $voter_id ) {
			return new WP_Error(
				'agnosis_invalid',
				__( 'You cannot nominate yourself for removal.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		// Confirm subject is an active artist.
		$subject = get_user_by( 'id', $subject_user_id );
		if ( ! $subject || ! in_array( 'agnosis_artist', (array) $subject->roles, true ) ) {
			return new WP_Error(
				'agnosis_not_found',
				__( 'Artist not found.', 'agnosis' ),
				[ 'status' => 404 ]
			);
		}

		// Find an existing open/nominating request.
		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, status FROM {$wpdb->prefix}agnosis_removal_requests
				  WHERE subject_user_id = %d AND status IN ('nominating','open')",
				$subject_user_id
			)
		);

		if ( $existing && 'open' === $existing->status ) {
			// Full vote already open — cast their vote there instead.
			return $this->record_removal_vote( (int) $existing->id, $voter_id, 'yes' );
		}

		if ( ! $existing ) {
			// Create a new nominating request.
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prefix . 'agnosis_removal_requests',
				[
					'subject_user_id' => $subject_user_id,
					'initiated_by'    => $voter_id,
					'status'          => 'nominating',
				],
				[ '%d', '%d', '%s' ]
			);
			$request_id = (int) $wpdb->insert_id;
		} else {
			$request_id = (int) $existing->id;
		}

		// Record the nominating artist's yes vote.
		$this->upsert_vote( $request_id, $voter_id, 'yes' );

		// Check nomination threshold.
		$threshold = max( 1, (int) get_option( 'agnosis_removal_nomination_threshold', 3 ) );
		$nom_count = $this->count_votes( $request_id, 'yes' );

		if ( $nom_count >= $threshold ) {
			$this->open_removal_vote( $request_id );
		}

		return new WP_REST_Response( [
			'status'              => 'nominated',
			'nominations_so_far'  => $nom_count,
			'nominations_needed'  => $threshold,
		], 201 );
	}

	/**
	 * REST handler: artist casts a yes/no vote on an open removal request.
	 *
	 * Only valid while the request is 'open'. Artists cannot vote on removal
	 * of themselves.
	 */
	public function cast_vote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$request_id = (int) $request->get_param( 'id' );
		$voter_id   = get_current_user_id();
		$vote       = (string) $request->get_param( 'vote' );

		return $this->record_vote_on_request( $request_id, $voter_id, $vote );
	}

	/**
	 * Core removal-vote logic — used by both the authenticated REST endpoint
	 * above (cast_vote()) and the email-link shim (RemovalVoteConfirm), the
	 * same way Admission::record_vote() is shared between its REST route and
	 * VouchConfirm.
	 *
	 * Only valid while the request is 'open'. Voters cannot vote on their own
	 * removal. Distinct from the private record_removal_vote() helper used by
	 * nominate() — that one intentionally skips these checks because
	 * nominate() has already validated its own (different) business rules
	 * before calling it.
	 *
	 * @param int    $request_id Removal request row ID.
	 * @param int    $voter_id   WP user ID of the voting artist.
	 * @param string $vote       'yes' or 'no'.
	 */
	public function record_vote_on_request( int $request_id, int $voter_id, string $vote ): WP_REST_Response|WP_Error {
		global $wpdb;

		$removal = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, subject_user_id, status FROM {$wpdb->prefix}agnosis_removal_requests
				  WHERE id = %d",
				$request_id
			)
		);

		if ( ! $removal ) {
			return new WP_Error( 'agnosis_not_found', __( 'Removal request not found.', 'agnosis' ), [ 'status' => 404 ] );
		}

		if ( 'open' !== $removal->status ) {
			return new WP_Error( 'agnosis_invalid', __( 'This vote is not currently open.', 'agnosis' ), [ 'status' => 409 ] );
		}

		if ( (int) $removal->subject_user_id === $voter_id ) {
			return new WP_Error( 'agnosis_invalid', __( 'You cannot vote on your own removal.', 'agnosis' ), [ 'status' => 400 ] );
		}

		$this->upsert_vote( $request_id, $voter_id, $vote );

		return new WP_REST_Response( [
			'status'    => 'recorded',
			'vote'      => $vote,
			'yes_votes' => $this->count_votes( $request_id, 'yes' ),
			'no_votes'  => $this->count_votes( $request_id, 'no' ),
		], 201 );
	}

	/**
	 * Admin entry point: open a community removal vote directly, bypassing nominations.
	 *
	 * Creates a new request in 'open' status and notifies all artists.
	 *
	 * @param int $subject_user_id  WP user ID of the artist to vote on.
	 * @param int $initiated_by     Admin WP user ID.
	 */
	public function admin_open_removal_vote( int $subject_user_id, int $initiated_by ): bool {
		global $wpdb;

		// Only one open/nominating request allowed per subject.
		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}agnosis_removal_requests
				  WHERE subject_user_id = %d AND status IN ('nominating','open')",
				$subject_user_id
			)
		);

		if ( $existing ) {
			// Escalate existing nominating request to open.
			$this->open_removal_vote( (int) $existing );
			return true;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_removal_requests',
			[
				'subject_user_id' => $subject_user_id,
				'initiated_by'    => $initiated_by,
				'status'          => 'nominating',
			],
			[ '%d', '%d', '%s' ]
		);

		$this->open_removal_vote( (int) $wpdb->insert_id );
		return true;
	}

	/**
	 * Cron callback: resolve expired removal votes.
	 *
	 * Runs daily via 'agnosis_check_removal_votes'. For each 'open' request
	 * whose closes_at has passed, tallies votes against the active artist count.
	 * >50 % yes → passed (artist removed). Otherwise → failed.
	 */
	public function check_expired_removal_votes(): void {
		global $wpdb;

		$expired = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, subject_user_id FROM {$wpdb->prefix}agnosis_removal_requests
				  WHERE status = 'open' AND closes_at <= %s",
				current_time( 'mysql' )
			)
		);

		foreach ( $expired as $removal ) {
			$request_id      = (int) $removal->id;
			$subject_user_id = (int) $removal->subject_user_id;

			$yes_votes    = $this->count_votes( $request_id, 'yes' );
			$active_count = $this->count_active_artists();

			// Majority = strictly more than 50 % of active artists voted yes.
			if ( $active_count > 0 && ( $yes_votes / $active_count ) > 0.5 ) {
				$this->resolve_removal_vote( $request_id, 'passed' );

				// Find the membership row and execute removal.
				$app = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}agnosis_applications WHERE wp_user_id = %d",
						$subject_user_id
					)
				);
				if ( $app ) {
					$this->execute_removal( $subject_user_id, (int) $app->id, 'left' );
				}

				/**
				 * @param int $subject_user_id WP user ID of the removed artist.
				 * @param int $request_id      Removal request ID.
				 */
				do_action( 'agnosis_removal_vote_passed', $subject_user_id, $request_id );
			} else {
				$this->resolve_removal_vote( $request_id, 'failed' );

				/**
				 * @param int $subject_user_id WP user ID of the artist who was not removed.
				 * @param int $request_id      Removal request ID.
				 */
				do_action( 'agnosis_removal_vote_failed', $subject_user_id, $request_id );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Delete all Agnosis CPT posts by $user_id, remove the agnosis_artist role,
	 * mark the membership row with $final_status and resolved_at, clear the
	 * removal_token, and delete the WP user account.
	 */
	public function execute_removal( int $user_id, int $application_id, string $final_status ): void {
		global $wpdb;

		$this->delete_artist_content( $user_id );

		// Update membership row.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_applications',
			[
				'status'         => $final_status,
				'removal_token'  => null,
				'banned_until'   => null,
				'resolved_at'    => current_time( 'mysql' ),
				'wp_user_id'     => null, // Dissociate after account deletion.
			],
			[ 'id' => $application_id ],
			[ '%s', 'NULL', 'NULL', '%s', 'NULL' ],
			[ '%d' ]
		);

		// Remove role then delete the account. wp_delete_user() with no reassign
		// deletes the user's posts — but we've already handled CPT deletion above.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}

	/**
	 * Permanently delete all Agnosis CPT posts authored by $user_id.
	 *
	 * Uses get_posts() with a high limit rather than a direct DB query so WP
	 * fires the correct action hooks (delete_post, etc.) for each deletion.
	 *
	 * 'post_status' is deliberately NOT 'any': WP_Query's 'any' silently
	 * excludes 'trash' (and 'auto-draft'/'inherit') — see
	 * https://developer.wordpress.org/reference/classes/wp_query/#status-parameters.
	 * Found 2026-07-08 auditing the self-departure flow: an artwork the artist
	 * had individually removed via remove@ shortly before departing (still
	 * inside WordPress's default 30-day trash retention) — along with any of
	 * its Lingua Forge translations, also left in the trash by that same
	 * cascade — would silently survive a "this action is permanent and cannot
	 * be undone" departure, recoverable until WP's own trash-empty cron ran.
	 * Explicitly including 'trash' here closes that gap.
	 */
	public function delete_artist_content( int $user_id ): void {
		$posts = get_posts( [
			'post_type'      => self::AGNOSIS_POST_TYPES,
			'author'         => $user_id,
			'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private', 'trash' ],
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'fields'         => 'ids',
		] );

		foreach ( $posts as $post_id ) {
			wp_delete_post( (int) $post_id, true ); // force_delete = true (bypass trash).
		}
	}

	/**
	 * Transition a removal request from 'nominating' → 'open' and notify artists.
	 */
	private function open_removal_vote( int $request_id ): void {
		global $wpdb;

		$window_days = max( 1, (int) get_option( 'agnosis_removal_window_days', 7 ) );
		$opens_at    = current_time( 'mysql' );
		$closes_at   = gmdate( 'Y-m-d H:i:s', (int) strtotime( "+{$window_days} days" ) );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_removal_requests',
			[
				'status'    => 'open',
				'opened_at' => $opens_at,
				'closes_at' => $closes_at,
			],
			[ 'id' => $request_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		/**
		 * Fires when a community removal vote opens.
		 *
		 * @param int    $request_id  The removal request ID.
		 * @param string $closes_at   MySQL datetime string when the vote closes.
		 */
		do_action( 'agnosis_removal_vote_opened', $request_id, $closes_at );
	}

	/** Record the final outcome on a removal request row. */
	private function resolve_removal_vote( int $request_id, string $outcome ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_removal_requests',
			[
				'status'      => $outcome,
				'resolved_at' => current_time( 'mysql' ),
			],
			[ 'id' => $request_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/** Insert or update a vote record. */
	private function upsert_vote( int $request_id, int $voter_id, string $vote ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}agnosis_removal_votes
				       (request_id, voter_id, vote)
				 VALUES (%d, %d, %s)
				 ON DUPLICATE KEY UPDATE vote = VALUES(vote)",
				$request_id,
				$voter_id,
				$vote
			)
		);
	}

	/** Count yes or no votes on a removal request. */
	private function count_votes( int $request_id, string $vote ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_removal_votes
				  WHERE request_id = %d AND vote = %s",
				$request_id,
				$vote
			)
		);
	}

	/** Count artists currently holding the agnosis_artist role. */
	private function count_active_artists(): int {
		$query = new \WP_User_Query( [
			'role'        => 'agnosis_artist',
			'count_total' => true,
			'number'      => 0,
		] );
		return (int) $query->get_total();
	}

	/**
	 * Record a removal vote for a user on an already-open request.
	 * Used internally when a nomination arrives on an already-open vote.
	 */
	private function record_removal_vote( int $request_id, int $voter_id, string $vote ): WP_REST_Response {
		$this->upsert_vote( $request_id, $voter_id, $vote );

		return new WP_REST_Response( [
			'status'    => 'recorded',
			'vote'      => $vote,
			'yes_votes' => $this->count_votes( $request_id, 'yes' ),
		], 201 );
	}
}
