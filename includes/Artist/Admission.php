<?php
/**
 * Artist admission — community vouching system.
 *
 * An artist applies by registering a WP account and requesting admission.
 * Existing admitted artists can vouch for them. Once the required number
 * of vouches is reached the applicant is granted the 'agnosis_artist' role.
 *
 * REST endpoints:
 *   POST /agnosis/v1/admission/apply       — submit application
 *   POST /agnosis/v1/admission/vouch/{id}  — vouch for a user
 *   GET  /agnosis/v1/admission/status/{id} — check admission status
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\Core\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Admission {

	public function register_routes(): void {
		register_rest_route( 'agnosis/v1', '/admission/apply', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'apply' ],
			'permission_callback' => [ $this, 'require_logged_in' ],
		] );

		register_rest_route( 'agnosis/v1', '/admission/vouch/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'vouch' ],
			'permission_callback' => [ $this, 'require_artist' ],
			'args'                => [
				'id'      => [ 'type' => 'integer', 'required' => true ],
				'message' => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field' ],
			],
		] );

		register_rest_route( 'agnosis/v1', '/admission/status/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'status' ],
			'permission_callback' => [ $this, 'require_logged_in_for_status' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	public function apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();

		if ( $this->is_artist( $user_id ) ) {
			return new WP_REST_Response( [ 'status' => 'already_artist' ], 200 );
		}

		update_user_meta( $user_id, '_agnosis_applied', current_time( 'mysql' ) );

		// Bio / statement submitted with the application.
		$bio = sanitize_textarea_field( $request->get_param( 'bio' ) ?? '' );
		if ( $bio ) {
			update_user_meta( $user_id, '_agnosis_application_bio', $bio );
		}

		$portfolio_url = esc_url_raw( $request->get_param( 'portfolio_url' ) ?? '' );
		if ( $portfolio_url ) {
			update_user_meta( $user_id, '_agnosis_portfolio_url', $portfolio_url );
		}

		do_action( 'agnosis_artist_applied', $user_id );

		return new WP_REST_Response( [
			'status'           => 'applied',
			'vouches_received' => 0,
			'vouches_required' => (int) get_option( 'agnosis_vouches_required', 2 ),
		], 201 );
	}

	public function vouch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$voucher_id   = get_current_user_id();
		$candidate_id = (int) $request->get_param( 'id' );
		$message      = $request->get_param( 'message' ) ?? '';

		if ( $voucher_id === $candidate_id ) {
			return new WP_Error( 'agnosis_self_vouch', __( 'You cannot vouch for yourself.', 'agnosis' ), [ 'status' => 400 ] );
		}

		if ( ! get_user_meta( $candidate_id, '_agnosis_applied', true ) ) {
			return new WP_Error( 'agnosis_not_applied', __( 'This user has not applied.', 'agnosis' ), [ 'status' => 404 ] );
		}

		if ( $this->is_artist( $candidate_id ) ) {
			return new WP_REST_Response( [ 'status' => 'already_artist' ], 200 );
		}

		// Insert vouch (unique constraint prevents duplicates).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table, no WP abstraction available.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'agnosis_vouches',
			[
				'voucher_id'   => $voucher_id,
				'candidate_id' => $candidate_id,
				'message'      => $message,
			],
			[ '%d', '%d', '%s' ]
		);

		if ( ! $inserted ) {
			return new WP_REST_Response( [ 'status' => 'already_vouched' ], 200 );
		}

		$this->maybe_admit( $candidate_id );

		return new WP_REST_Response( [
			'status'           => 'vouched',
			'vouches_received' => $this->count_vouches( $candidate_id ),
			'vouches_required' => (int) get_option( 'agnosis_vouches_required', 2 ),
		], 201 );
	}

	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$candidate_id = (int) $request->get_param( 'id' );
		$current_id   = get_current_user_id();

		// Non-admins may only read their own status.
		if ( $candidate_id !== $current_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'agnosis_forbidden',
				__( 'You may only view your own admission status.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		return new WP_REST_Response( [
			'user_id'          => $candidate_id,
			'is_artist'        => $this->is_artist( $candidate_id ),
			'has_applied'      => (bool) get_user_meta( $candidate_id, '_agnosis_applied', true ),
			'vouches_received' => $this->count_vouches( $candidate_id ),
			'vouches_required' => (int) get_option( 'agnosis_vouches_required', 2 ),
		] );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function require_logged_in_for_status(): bool|WP_Error {
		return is_user_logged_in()
			? true
			: new WP_Error( 'agnosis_auth', __( 'You must be logged in.', 'agnosis' ), [ 'status' => 401 ] );
	}

	public function require_logged_in(): bool|WP_Error {
		$rate = RateLimiter::check( 'admission_apply', 5, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		return is_user_logged_in()
			? true
			: new WP_Error( 'agnosis_auth', __( 'You must be logged in.', 'agnosis' ), [ 'status' => 401 ] );
	}

	public function require_artist(): bool|WP_Error {
		$rate = RateLimiter::check( 'admission_vouch', 20, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		return $this->is_artist( get_current_user_id() )
			? true
			: new WP_Error( 'agnosis_not_artist', __( 'Only admitted artists can vouch.', 'agnosis' ), [ 'status' => 403 ] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function is_artist( int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return in_array( 'agnosis_artist', (array) $user->roles, true )
			|| user_can( $user_id, 'manage_options' );
	}

	private function count_vouches( int $candidate_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no WP abstraction available.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_vouches WHERE candidate_id = %d AND revoked_at IS NULL",
				$candidate_id
			)
		);
	}

	/**
	 * Revoke a single vouch so it no longer counts toward admission.
	 *
	 * Does not delete the row — the history is preserved for audit purposes.
	 * The vouch is timestamped with the revocation time and excluded from all
	 * future `count_vouches()` calls.
	 *
	 * @param int $voucher_id   WordPress user ID of the vouching artist.
	 * @param int $candidate_id WordPress user ID of the applicant.
	 * @return bool True if a row was updated; false if the vouch was not found or already revoked.
	 */
	public function revoke_vouch( int $voucher_id, int $candidate_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write; caching not applicable to UPDATE.
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agnosis_vouches
				 SET revoked_at = %s
				 WHERE voucher_id = %d AND candidate_id = %d AND revoked_at IS NULL",
				current_time( 'mysql' ),
				$voucher_id,
				$candidate_id
			)
		);
		return (bool) $rows;
	}

	private function maybe_admit( int $candidate_id ): void {
		$required = (int) get_option( 'agnosis_vouches_required', 2 );
		if ( $this->count_vouches( $candidate_id ) >= $required ) {
			$user = get_userdata( $candidate_id );
			if ( $user ) {
				$user->add_role( 'agnosis_artist' );
				update_user_meta( $candidate_id, '_agnosis_admitted_at', current_time( 'mysql' ) );
				do_action( 'agnosis_artist_admitted', $candidate_id );
			}
		}
	}
}
