<?php
/**
 * Member vote to change the community size cap (Phase 2 of the size-cap feature).
 *
 * Any artist can propose a new value for `agnosis_community_max_artists`. Once the
 * proposal gathers the configured number of co-signers it opens as a full community
 * vote; the daily `agnosis_check_cap_votes` cron closes the window and, when a strict
 * majority of active artists voted yes, writes the new cap and advances the waitlist.
 *
 * This mirrors the removal-vote machinery in Departure (nominating → open →
 * passed/failed) but acts on a global option instead of removing a member. The admin
 * can still set the cap directly in Settings — this is the *community* path.
 *
 * REST (artists only):
 *   POST /agnosis/v1/cap/propose          — propose a new cap ({ cap: int })
 *   POST /agnosis/v1/cap/cosign/{id}      — co-sign a nominating proposal
 *   POST /agnosis/v1/cap/vote/{id}        — yes/no on an open proposal
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\Core\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class CommunityCapVote {

	/** Daily cron hook that tallies open cap-change votes. */
	public const CRON_HOOK = 'agnosis_check_cap_votes';

	// -------------------------------------------------------------------------
	// REST
	// -------------------------------------------------------------------------

	public function register_routes(): void {
		register_rest_route( 'agnosis/v1', '/cap/propose', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'propose' ],
			'permission_callback' => [ $this, 'require_artist' ],
			'args'                => [
				'cap' => [ 'type' => 'integer', 'required' => true, 'minimum' => 0 ],
			],
		] );

		register_rest_route( 'agnosis/v1', '/cap/cosign/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'cosign' ],
			'permission_callback' => [ $this, 'require_artist' ],
			'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
		] );

		register_rest_route( 'agnosis/v1', '/cap/vote/(?P<id>\d+)', [
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

	/** Only admitted artists may propose or vote. */
	public function require_artist(): bool|WP_Error {
		if ( ! is_user_logged_in() || ! in_array( 'agnosis_artist', (array) wp_get_current_user()->roles, true ) ) {
			return new WP_Error( 'agnosis_forbidden', __( 'Only community members can do this.', 'agnosis' ), [ 'status' => 403 ] );
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Propose / co-sign / vote
	// -------------------------------------------------------------------------

	/**
	 * Propose a new community cap. Creates a `nominating` proposal (or co-signs the
	 * matching active one) and opens the full vote once the co-sign threshold is met.
	 */
	public function propose( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$new_cap = (int) $request->get_param( 'cap' );
		$user_id = get_current_user_id();

		if ( $new_cap < 0 ) {
			return new WP_Error( 'agnosis_invalid', __( 'The cap must be zero or a positive number.', 'agnosis' ), [ 'status' => 400 ] );
		}

		$current = (int) get_option( CommunityCap::OPTION, CommunityCap::DEFAULT_CAP );
		if ( $new_cap === $current ) {
			return new WP_Error( 'agnosis_invalid', __( 'That is already the current cap.', 'agnosis' ), [ 'status' => 400 ] );
		}

		// Only one active (nominating/open) proposal at a time — the cap is global.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			"SELECT id, status, proposed_cap FROM {$wpdb->prefix}agnosis_cap_proposals
			 WHERE status IN ('nominating','open') LIMIT 1"
		);

		if ( $existing ) {
			if ( (int) $existing->proposed_cap !== $new_cap ) {
				return new WP_Error(
					'agnosis_conflict',
					__( 'A different cap proposal is already under way. Resolve or co-sign that one first.', 'agnosis' ),
					[ 'status' => 409 ]
				);
			}
			$proposal_id = (int) $existing->id;
			if ( 'open' === $existing->status ) {
				$this->upsert_vote( $proposal_id, $user_id, 'yes' );
				return new WP_REST_Response( [ 'status' => 'voted', 'proposal_id' => $proposal_id ], 200 );
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'agnosis_cap_proposals',
				[ 'proposed_cap' => $new_cap, 'initiated_by' => $user_id, 'status' => 'nominating' ],
				[ '%d', '%d', '%s' ]
			);
			$proposal_id = (int) $wpdb->insert_id;
		}

		$this->upsert_vote( $proposal_id, $user_id, 'yes' );

		$threshold = max( 1, (int) get_option( 'agnosis_cap_proposal_threshold', 3 ) );
		$cosigns   = $this->count_votes( $proposal_id, 'yes' );

		if ( $cosigns >= $threshold && 'open' !== ( $existing->status ?? '' ) ) {
			$this->open_vote( $proposal_id );
		}

		return new WP_REST_Response( [
			'status'             => 'proposed',
			'proposal_id'        => $proposal_id,
			'proposed_cap'       => $new_cap,
			'cosigns_so_far'     => $cosigns,
			'cosigns_needed'     => $threshold,
		], 201 );
	}

	/** Co-sign a nominating proposal (a yes that counts toward opening the vote). */
	public function cosign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$proposal_id = (int) $request->get_param( 'id' );
		$user_id     = get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$proposal = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status FROM {$wpdb->prefix}agnosis_cap_proposals WHERE id = %d", $proposal_id )
		);

		if ( ! $proposal || ! in_array( $proposal->status, [ 'nominating', 'open' ], true ) ) {
			return new WP_Error( 'agnosis_not_found', __( 'No active proposal to co-sign.', 'agnosis' ), [ 'status' => 404 ] );
		}

		$this->upsert_vote( $proposal_id, $user_id, 'yes' );

		$threshold = max( 1, (int) get_option( 'agnosis_cap_proposal_threshold', 3 ) );
		$cosigns   = $this->count_votes( $proposal_id, 'yes' );

		if ( 'nominating' === $proposal->status && $cosigns >= $threshold ) {
			$this->open_vote( $proposal_id );
		}

		return new WP_REST_Response( [
			'status'         => 'cosigned',
			'proposal_id'    => $proposal_id,
			'cosigns_so_far' => $cosigns,
			'cosigns_needed' => $threshold,
		], 201 );
	}

	/** Cast a yes/no vote on an open proposal. */
	public function cast_vote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$proposal_id = (int) $request->get_param( 'id' );
		$vote        = (string) $request->get_param( 'vote' );
		$user_id     = get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$proposal = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status FROM {$wpdb->prefix}agnosis_cap_proposals WHERE id = %d", $proposal_id )
		);

		if ( ! $proposal ) {
			return new WP_Error( 'agnosis_not_found', __( 'Proposal not found.', 'agnosis' ), [ 'status' => 404 ] );
		}
		if ( 'open' !== $proposal->status ) {
			return new WP_Error( 'agnosis_invalid', __( 'This vote is not currently open.', 'agnosis' ), [ 'status' => 409 ] );
		}

		$this->upsert_vote( $proposal_id, $user_id, $vote );

		return new WP_REST_Response( [
			'status'    => 'recorded',
			'vote'      => $vote,
			'yes_votes' => $this->count_votes( $proposal_id, 'yes' ),
			'no_votes'  => $this->count_votes( $proposal_id, 'no' ),
		], 201 );
	}

	// -------------------------------------------------------------------------
	// Cron tally
	// -------------------------------------------------------------------------

	/**
	 * Resolve open cap-change votes whose window has closed.
	 *
	 * A strict majority (> 50 %) of active artists voting yes passes the proposal:
	 * the new cap is written and the waitlist is advanced (a raised cap may free
	 * slots). Otherwise it fails. Quorum is the same > 50%-of-active rule the
	 * removal vote uses, so a handful of voters cannot swing the cap.
	 */
	public function check_expired_cap_votes(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, proposed_cap FROM {$wpdb->prefix}agnosis_cap_proposals
				 WHERE status = 'open' AND closes_at <= %s",
				current_time( 'mysql' )
			)
		);

		foreach ( $expired as $proposal ) {
			$proposal_id = (int) $proposal->id;
			$new_cap     = (int) $proposal->proposed_cap;

			$yes    = $this->count_votes( $proposal_id, 'yes' );
			$active = $this->count_active_artists();

			if ( $active > 0 && ( $yes / $active ) > 0.5 ) {
				$this->resolve( $proposal_id, 'passed' );
				update_option( CommunityCap::OPTION, $new_cap );
				Logger::info( 'Community cap changed to ' . $new_cap . ' by member vote (proposal #' . $proposal_id . ').', 'admission' );

				// A raised cap may open slots — advance the waitlist.
				( new CommunityCap() )->advance_waitlist();

				do_action( 'agnosis_cap_vote_passed', $proposal_id, $new_cap );
			} else {
				$this->resolve( $proposal_id, 'failed' );
				do_action( 'agnosis_cap_vote_failed', $proposal_id, $new_cap );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function open_vote( int $proposal_id ): void {
		global $wpdb;

		$window_days = max( 1, (int) get_option( 'agnosis_cap_vote_window_days', 7 ) );
		$closes_at   = gmdate( 'Y-m-d H:i:s', (int) strtotime( "+{$window_days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_cap_proposals',
			[ 'status' => 'open', 'opened_at' => current_time( 'mysql' ), 'closes_at' => $closes_at ],
			[ 'id' => $proposal_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		do_action( 'agnosis_cap_vote_opened', $proposal_id, $closes_at );
	}

	private function resolve( int $proposal_id, string $outcome ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_cap_proposals',
			[ 'status' => $outcome, 'resolved_at' => current_time( 'mysql' ) ],
			[ 'id' => $proposal_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	private function upsert_vote( int $proposal_id, int $voter_id, string $vote ): void {
		global $wpdb;
		$vote = in_array( $vote, [ 'yes', 'no' ], true ) ? $vote : 'yes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}agnosis_cap_votes (proposal_id, voter_id, vote)
				 VALUES (%d, %d, %s)
				 ON DUPLICATE KEY UPDATE vote = VALUES(vote)",
				$proposal_id,
				$voter_id,
				$vote
			)
		);
	}

	private function count_votes( int $proposal_id, string $vote ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_cap_votes WHERE proposal_id = %d AND vote = %s",
				$proposal_id,
				$vote
			)
		);
	}

	private function count_active_artists(): int {
		$query = new \WP_User_Query( [ 'role' => 'agnosis_artist', 'count_total' => true, 'number' => 0, 'fields' => 'ID' ] );
		return (int) $query->get_total();
	}
}
