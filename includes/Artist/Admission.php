<?php
/**
 * Artist admission — community vouching system.
 *
 * An artist applies without a WP account. The application is stored in
 * agnosis_applications. Existing artists and the admin vote via email links
 * (yes / no) which are recorded in agnosis_application_vouches.
 *
 * Admission rules (all configurable in Settings → Network):
 *   - Minimum positive votes = max( ceil( active_artists × percent / 100 ), minimum )
 *     Default: 10 % of active artists, at least 3 positive votes.
 *   - Voting window: 7 days. If the threshold is not reached within that window
 *     the application is marked rejected and both parties are notified.
 *   - Negative votes are recorded but do not subtract from the positive count.
 *   - Votes can be changed (clicking the other link overwrites the previous vote).
 *
 * No WP user is ever created before the community approves the application.
 *
 * REST endpoints:
 *   POST /agnosis/v1/admission/apply       — submit application (unauthenticated)
 *   POST /agnosis/v1/admission/vouch/{id}  — cast a vote (artists only, REST)
 *   GET  /agnosis/v1/admission/status/{id} — check application status (admin only)
 *
 * Email-link voting is handled by VouchConfirm (template_redirect).
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\Core\Logger;
use Agnosis\Core\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Admission {

	public function register_routes(): void {
		register_rest_route( 'agnosis/v1', '/admission/apply', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'apply' ],
			'permission_callback' => [ $this, 'rate_limit_apply' ],
			'args'                => [
				'email'         => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => fn( string $v ): bool => (bool) is_email( $v ),
				],
				'display_name'  => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'bio'           => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				],
				'portfolio_url' => [
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				],
				'statement'     => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				],
				'language'      => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		register_rest_route( 'agnosis/v1', '/admission/vouch/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'vouch' ],
			'permission_callback' => [ $this, 'require_artist' ],
			'args'                => [
				'id'      => [ 'type' => 'integer', 'required' => true ],
				'vote'    => [
					'type'    => 'string',
					'enum'    => [ 'yes', 'no' ],
					'default' => 'yes',
				],
				'message' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
			],
		] );

		register_rest_route( 'agnosis/v1', '/admission/status/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'status' ],
			'permission_callback' => [ $this, 'require_admin' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	public function apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$email        = (string) $request->get_param( 'email' );
		$display_name = (string) $request->get_param( 'display_name' );
		$bio          = (string) ( $request->get_param( 'bio' ) ?? '' );
		$portfolio    = (string) ( $request->get_param( 'portfolio_url' ) ?? '' );
		$statement    = (string) ( $request->get_param( 'statement' ) ?? '' );
		$language     = sanitize_key( (string) ( $request->get_param( 'language' ) ?? '' ) );

		// Fall back to the browser's primary Accept-Language when none submitted.
		if ( '' === $language ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() applied immediately.
			$accept = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
			if ( '' !== $accept ) {
				// "es-ES,es;q=0.9,en;q=0.8" → "es"
				$primary  = explode( ',', $accept )[0];
				$primary  = explode( ';', $primary )[0];
				$language = sanitize_key( strtolower( (string) explode( '-', trim( $primary ) )[0] ) );
			}
		}

		// Block if a WP account already exists for this email.
		if ( get_user_by( 'email', $email ) ) {
			return new WP_Error(
				'agnosis_email_exists',
				__( 'An account with this email address already exists.', 'agnosis' ),
				[ 'status' => 409 ]
			);
		}

		// Community size cap: when the instance is full, park the application on the
		// FIFO waitlist instead of opening it for vouching. Existing members are
		// never affected — the cap gates new admissions only.
		$waitlisted = ( new CommunityCap() )->is_full();
		$new_status = $waitlisted ? 'waitlisted' : 'pending';

		// Check for an existing application row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
				$email
			)
		);

		if ( $existing ) {
			if ( in_array( $existing->status, [ 'pending', 'admitted' ], true ) ) {
				return new WP_Error(
					'agnosis_already_applied',
					'pending' === $existing->status
						? __( 'An application for this email address is already pending.', 'agnosis' )
						: __( 'This email address belongs to an admitted artist.', 'agnosis' ),
					[ 'status' => 409 ]
				);
			}

			// withdrawn / rejected / left — allow reapplication.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}agnosis_applications
					 SET display_name = %s, bio = %s, portfolio_url = %s, statement = %s,
					     language = %s, status = %s, wp_user_id = NULL,
					     applied_at = %s, resolved_at = NULL
					 WHERE id = %d",
					$display_name,
					$bio,
					$portfolio,
					$statement,
					$language,
					$new_status,
					current_time( 'mysql' ),
					$existing->id
				)
			);

			$application_id = (int) $existing->id;
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'agnosis_applications',
				[
					'email'         => $email,
					'display_name'  => $display_name,
					'bio'           => $bio,
					'portfolio_url' => $portfolio,
					'statement'     => $statement,
					'language'      => '' !== $language ? $language : null,
					'status'        => $new_status,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);

			$application_id = (int) $wpdb->insert_id;
		}

		if ( $waitlisted ) {
			do_action( 'agnosis_artist_waitlisted', $application_id, $email, $display_name );

			return new WP_REST_Response( [
				'status'         => 'waitlisted',
				'application_id' => $application_id,
				'cap'            => ( new CommunityCap() )->cap(),
				'message'        => __( 'This community is currently full. Your application has joined the waitlist — when a member leaves, the next person in line is welcomed in, and the community can also vote to make room for more artists.', 'agnosis' ),
			], 202 );
		}

		do_action( 'agnosis_artist_applied', $application_id, $email, $display_name );

		return new WP_REST_Response( [
			'status'           => 'applied',
			'application_id'   => $application_id,
			'vouches_required' => $this->calculate_required(),
		], 201 );
	}

	public function vouch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$voucher_id     = get_current_user_id();
		$application_id = (int) $request->get_param( 'id' );
		$vote           = (string) ( $request->get_param( 'vote' ) ?? 'yes' );
		$message        = (string) ( $request->get_param( 'message' ) ?? '' );

		return $this->record_vote( $voucher_id, $application_id, $vote, $message );
	}

	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$application_id = (int) $request->get_param( 'id' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$application_id
			)
		);

		if ( ! $application ) {
			return new WP_Error(
				'agnosis_not_found',
				__( 'Application not found.', 'agnosis' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( [
			'id'               => (int) $application->id,
			'email'            => $application->email,
			'display_name'     => $application->display_name,
			'status'           => $application->status,
			'wp_user_id'       => $application->wp_user_id ? (int) $application->wp_user_id : null,
			'vouches_received' => $this->count_positive_vouches( (int) $application->id ),
			'vouches_required' => $this->calculate_required(),
			'applied_at'       => $application->applied_at,
			'resolved_at'      => $application->resolved_at,
		] );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function rate_limit_apply(): bool|WP_Error {
		$rate = RateLimiter::check( 'admission_apply', 5, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		return true;
	}

	public function require_artist(): bool|WP_Error {
		$rate = RateLimiter::check( 'admission_vouch', 20, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		return $this->is_artist( get_current_user_id() )
			? true
			: new WP_Error(
				'agnosis_not_artist',
				__( 'Only admitted artists can vouch.', 'agnosis' ),
				[ 'status' => 403 ]
			);
	}

	public function require_admin(): bool|WP_Error {
		return current_user_can( 'manage_options' )
			? true
			: new WP_Error(
				'agnosis_forbidden',
				__( 'Admin access required.', 'agnosis' ),
				[ 'status' => 403 ]
			);
	}

	// -------------------------------------------------------------------------
	// Core voting logic — used by both the REST endpoint and VouchConfirm
	// -------------------------------------------------------------------------

	/**
	 * Record or update a vote for an application.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE so artists can change their mind
	 * within the voting window. Triggers maybe_admit() after a 'yes' vote.
	 *
	 * @param int    $voucher_id     WP user ID of the voting artist.
	 * @param int    $application_id Row ID in agnosis_applications.
	 * @param string $vote           'yes' or 'no'.
	 * @param string $message        Optional personal note (stored for audit).
	 */
	public function record_vote(
		int $voucher_id,
		int $application_id,
		string $vote,
		string $message = ''
	): WP_REST_Response|WP_Error {
		global $wpdb;

		$vote = in_array( $vote, [ 'yes', 'no' ], true ) ? $vote : 'yes';

		// Load the application.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, email FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$application_id
			)
		);

		if ( ! $application ) {
			return new WP_Error(
				'agnosis_not_found',
				__( 'Application not found.', 'agnosis' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'pending' !== $application->status ) {
			return new WP_REST_Response( [ 'status' => $application->status ], 200 );
		}

		// Artists cannot vote on their own application — guard by email.
		$voucher = get_userdata( $voucher_id );
		if ( $voucher && $voucher->user_email === $application->email ) {
			return new WP_Error(
				'agnosis_self_vouch',
				__( 'You cannot vote on your own application.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		// Upsert: allow vote change via ON DUPLICATE KEY UPDATE.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}agnosis_application_vouches
				 (application_id, voucher_id, vote, message)
				 VALUES (%d, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE vote = VALUES(vote), message = VALUES(message)",
				$application_id,
				$voucher_id,
				$vote,
				$message
			)
		);

		if ( 'yes' === $vote ) {
			$this->maybe_admit( $application_id );
		}

		return new WP_REST_Response( [
			'status'           => 'recorded',
			'vote'             => $vote,
			'vouches_received' => $this->count_positive_vouches( $application_id ),
			'vouches_required' => $this->calculate_required(),
		], 201 );
	}

	// -------------------------------------------------------------------------
	// Cron callback — daily expiry check
	// -------------------------------------------------------------------------

	/**
	 * Check all pending applications that have exceeded the voting window.
	 *
	 * Called by the 'agnosis_check_admissions' daily cron event. For each
	 * expired pending application:
	 *   - If positive votes >= required → admit (handles race where threshold
	 *     was met just before the window closed but maybe_admit was not called).
	 *   - Otherwise → reject, fire 'agnosis_application_expired' action so
	 *     AdmissionNotification can send the notification emails.
	 */
	public function check_expired_applications(): void {
		global $wpdb;

		$window = max( 1, (int) get_option( 'agnosis_admission_window_days', 7 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}agnosis_applications
				 WHERE status = 'pending'
				   AND applied_at <= DATE_SUB(%s, INTERVAL %d DAY)",
				current_time( 'mysql' ),
				$window
			)
		);

		foreach ( $expired as $row ) {
			$app_id = (int) $row->id;

			if ( $this->count_positive_vouches( $app_id ) >= $this->calculate_required() ) {
				// Threshold reached — admit now (edge case).
				$this->maybe_admit( $app_id );
				continue;
			}

			// Reject and notify.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'agnosis_applications',
				[
					'status'      => 'rejected',
					'resolved_at' => current_time( 'mysql' ),
				],
				[ 'id' => $app_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);

			do_action( 'agnosis_application_expired', $app_id );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Admin override actions (bypass vouch threshold)
	// -------------------------------------------------------------------------

	/**
	 * Admit an applicant directly, bypassing the vouch threshold.
	 *
	 * For use by admins via the Settings → Network admission dashboard.
	 * Fires `agnosis_artist_admitted` so the welcome email is sent.
	 *
	 * @param int $application_id  Row ID in agnosis_applications.
	 * @return bool  True on success, false when the row is not pending or user creation fails.
	 */
	public function admin_admit( int $application_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_applications WHERE id = %d AND status IN ('pending','waitlisted')",
				$application_id
			)
		);

		if ( ! $application ) {
			return false;
		}

		// The admin is the ultimate steward: admitting is allowed even when the
		// instance is at its community cap, but it is logged so an over-cap
		// admission is a deliberate, visible act rather than a silent one.
		$cap = new CommunityCap();
		if ( $cap->is_full() ) {
			Logger::warning(
				sprintf(
					'Admin admitted application #%d over the community cap of %d (deliberate override).',
					$application_id,
					$cap->cap()
				),
				'admission'
			);
		}

		// Temporarily make the positive vouch count appear to meet the threshold so
		// maybe_admit() proceeds.  We do this by calling it directly now that we've
		// verified the row — simpler than duplicating the admission logic.
		// Insert a synthetic vouch so count_positive_vouches() passes the guard.
		// Actually, just inline the admit sequence directly (DRY tradeoff accepted
		// because maybe_admit is private and duplicating is cleaner than exposing it).

		/** @var object{email: string, display_name: string, language: string|null} $application */
		$username = $this->unique_username( $application->display_name, $application->email );
		$user_id  = wp_create_user( $username, wp_generate_password(), $application->email );

		if ( is_wp_error( $user_id ) ) {
			return false;
		}

		$update_args = [
			'ID'           => $user_id,
			'display_name' => $application->display_name,
			'first_name'   => $application->display_name,
		];

		if ( ! empty( $application->language ) ) {
			$update_args['locale'] = self::iso_to_wp_locale( (string) $application->language );
		}

		wp_update_user( $update_args );

		$user = get_userdata( $user_id );
		if ( $user ) {
			$user->add_role( 'agnosis_artist' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_applications',
			[
				'status'      => 'admitted',
				'wp_user_id'  => $user_id,
				'resolved_at' => current_time( 'mysql' ),
			],
			[ 'id' => $application_id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);

		do_action( 'agnosis_artist_admitted', $user_id, $application_id );

		return true;
	}

	/**
	 * Reject an applicant directly, bypassing the vouch window.
	 *
	 * For use by admins via the Settings → Network admission dashboard.
	 * Fires `agnosis_artist_rejected` so the rejection email is sent.
	 *
	 * @param int $application_id  Row ID in agnosis_applications.
	 * @return bool  True on success, false when the row is not pending.
	 */
	public function admin_reject( int $application_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'agnosis_applications',
			[
				'status'      => 'rejected',
				'resolved_at' => current_time( 'mysql' ),
			],
			[ 'id' => $application_id, 'status' => 'pending' ],
			[ '%s', '%s' ],
			[ '%d', '%s' ]
		);

		if ( ! $updated ) {
			return false;
		}

		do_action( 'agnosis_artist_rejected', $application_id );

		return true;
	}

	/**
	 * Calculate the required number of positive votes for admission.
	 *
	 * = max( ceil( active_artists × percent / 100 ), minimum )
	 * Default: 10 % of active artists, at least 3.
	 */
	public function calculate_required(): int {
		$percent = max( 0, (int) get_option( 'agnosis_admission_percent', 10 ) );
		$minimum = max( 1, (int) get_option( 'agnosis_admission_minimum', 3 ) );
		$active  = $this->count_active_artists();

		return (int) max( (int) ceil( $active * $percent / 100 ), $minimum );
	}

	private function count_active_artists(): int {
		$query = new \WP_User_Query( [
			'role'        => 'agnosis_artist',
			'count_total' => true,
			'number'      => 0,
		] );
		return (int) $query->get_total();
	}

	public function count_positive_vouches( int $application_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_application_vouches
				 WHERE application_id = %d AND vote = 'yes' AND revoked_at IS NULL",
				$application_id
			)
		);
	}

	private function maybe_admit( int $application_id ): void {
		global $wpdb;

		if ( $this->count_positive_vouches( $application_id ) < $this->calculate_required() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_applications WHERE id = %d AND status = 'pending'",
				$application_id
			)
		);

		if ( ! $application ) {
			return; // Already admitted or not pending.
		}

		// Community size cap: re-check at admission time (the count can change
		// between application and threshold). If full, park on the waitlist instead
		// of admitting — it re-enters this flow when a slot opens (advance_waitlist).
		if ( ( new CommunityCap() )->is_full() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'agnosis_applications',
				[ 'status' => 'waitlisted' ],
				[ 'id' => $application_id, 'status' => 'pending' ],
				[ '%s' ],
				[ '%d', '%s' ]
			);
			do_action( 'agnosis_artist_waitlisted', $application_id, $application->email, $application->display_name );
			return;
		}

		/** @var object{email: string, display_name: string, language: string|null} $application */

		$username = $this->unique_username( $application->display_name, $application->email );
		$user_id  = wp_create_user( $username, wp_generate_password(), $application->email );

		if ( is_wp_error( $user_id ) ) {
			return;
		}

		$update_args = [
			'ID'           => $user_id,
			'display_name' => $application->display_name,
			'first_name'   => $application->display_name,
		];

		// Map the applicant's ISO 639-1 language code to a WP locale and persist it
		// so notification emails can be switched to the artist's language on send.
		if ( ! empty( $application->language ) ) {
			$update_args['locale'] = self::iso_to_wp_locale( $application->language );
		}

		wp_update_user( $update_args );

		$user = get_userdata( $user_id );
		if ( $user ) {
			$user->add_role( 'agnosis_artist' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_applications',
			[
				'status'      => 'admitted',
				'wp_user_id'  => $user_id,
				'resolved_at' => current_time( 'mysql' ),
			],
			[ 'id' => $application_id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);

		do_action( 'agnosis_artist_admitted', $user_id, $application_id );
	}

	/**
	 * Re-evaluate an application for admission after a waitlist slot opened.
	 *
	 * Hooked on `agnosis_waitlist_advanced` (fired by CommunityCap::advance_waitlist
	 * when a member leaves). The row was just moved waitlisted → pending; if it
	 * already meets the vouch threshold and there is now capacity it is admitted at
	 * once, otherwise it stays pending in the normal vouching window.
	 *
	 * @param int $application_id Row ID in agnosis_applications.
	 * @return void
	 */
	public function reconsider( int $application_id ): void {
		$this->maybe_admit( $application_id );
	}

	/**
	 * Revoke a vouch on a pending application.
	 *
	 * Sets revoked_at instead of deleting — the row is preserved for audit.
	 * Returns false when the vouch doesn't exist or was already revoked.
	 *
	 * @param int $voucher_id     WP user ID of the vouching artist.
	 * @param int $application_id Row ID in agnosis_applications.
	 */
	public function revoke_vouch( int $voucher_id, int $application_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agnosis_application_vouches
				 SET revoked_at = %s
				 WHERE voucher_id = %d AND application_id = %d AND revoked_at IS NULL",
				current_time( 'mysql' ),
				$voucher_id,
				$application_id
			)
		);
		return (bool) $rows;
	}

	public function is_artist( int $user_id ): bool {
		return self::is_admitted_artist( $user_id );
	}

	/**
	 * Check whether a WP user is an admitted Agnosis artist (or an admin).
	 *
	 * Public static so it can be used as a shared choke point across the intake
	 * paths (Webhook, Inbox, PostCreator) without coupling those classes to
	 * the full Admission object.
	 *
	 * @param int|null $user_id WordPress user ID, or null for unauthenticated.
	 */
	public static function is_admitted_artist( int|null $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		// user_can() resolves the primitive capability from wp_capabilities meta
		// directly — it does not require the role to be present in the global
		// WP_Roles registry. This means the check works correctly in test
		// environments where register_activation_hook() hasn't run (and therefore
		// add_role('agnosis_artist') was never called globally), while remaining
		// identical in production where the role IS registered.
		return user_can( $user_id, 'agnosis_artist' )
			|| user_can( $user_id, 'manage_options' );
	}

	/**
	 * Map an ISO 639-1 language code to the closest WP locale string.
	 *
	 * WP locales use IETF BCP 47 style tags (e.g. 'es_ES', 'zh_CN').
	 * ISO codes submitted through the join form are converted here before being
	 * stored in user meta so WP can find matching translation files.
	 *
	 * Unmapped codes are returned as-is — WP will gracefully fall back to the
	 * site language when no translation files are found for that locale.
	 */
	public static function iso_to_wp_locale( string $iso ): string {
		/** @var array<string, string> */
		$map = [
			'en'    => 'en_US',
			'es'    => 'es_ES',
			'pt'    => 'pt_PT',
			'fr'    => 'fr_FR',
			'it'    => 'it_IT',
			'de'    => 'de_DE',
			'nl'    => 'nl_NL',
			'ca'    => 'ca',
			'sv'    => 'sv_SE',
			'da'    => 'da_DK',
			'nb'    => 'nb_NO',
			'fi'    => 'fi',
			'pl'    => 'pl_PL',
			'cs'    => 'cs_CZ',
			'hu'    => 'hu_HU',
			'ro'    => 'ro_RO',
			'el'    => 'el',
			'uk'    => 'uk',
			'ru'    => 'ru_RU',
			'ar'    => 'ar',
			'tr'    => 'tr_TR',
			'hi'    => 'hi_IN',
			'id'    => 'id_ID',
			'vi'    => 'vi',
			'th'    => 'th',
			'zh'    => 'zh_CN',
			'zh-tw' => 'zh_TW',
			'ja'    => 'ja',
			'ko'    => 'ko_KR',
		];

		return $map[ $iso ] ?? $iso;
	}

	/**
	 * Generate a unique WP username from the applicant's display name.
	 */
	private function unique_username( string $display_name, string $email ): string {
		$base = sanitize_user( str_replace( ' ', '', strtolower( $display_name ) ), true );
		if ( ! $base ) {
			$local = strstr( $email, '@', true );
			$base  = sanitize_user( false !== $local ? $local : $email, true );
		}
		if ( ! $base ) {
			$base = 'artist';
		}

		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i;
			++$i;
		}

		return $username;
	}
}
