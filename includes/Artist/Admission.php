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
			'permission_callback' => '__return_true',
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

	public function status( WP_REST_Request $request ): WP_REST_Response {
		$candidate_id = (int) $request->get_param( 'id' );

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

	public function require_logged_in(): bool|WP_Error {
		return is_user_logged_in()
			? true
			: new WP_Error( 'agnosis_auth', __( 'You must be logged in.', 'agnosis' ), [ 'status' => 401 ] );
	}

	public function require_artist(): bool|WP_Error {
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
			|| user_can( $user_id, 'administrator' );
	}

	private function count_vouches( int $candidate_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_vouches WHERE candidate_id = %d",
				$candidate_id
			)
		);
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
