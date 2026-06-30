<?php
/**
 * Member-governed community size cap.
 *
 * Each Agnosis instance has a maximum number of admitted artists
 * (`agnosis_community_max_artists`, default 50; 0 = unlimited). The community —
 * not a hard-coded number — controls it: an admin can set it directly, or the
 * members can vote to change it (see CommunityCapVote, Phase 2).
 *
 * This class is the single source of truth for "are we full?" and owns the FIFO
 * waitlist: when the instance is full, new applications are parked as
 * `waitlisted` instead of being rejected; when a member leaves and a slot opens,
 * the oldest waitlisted applicant is advanced back into the normal vouching flow.
 *
 * The cap gates *new admissions only* — existing members are always grandfathered,
 * so lowering the cap below the current size never removes anyone.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\Core\Logger;

class CommunityCap {

	/** Option storing the maximum number of admitted artists (0 = unlimited). */
	public const OPTION = 'agnosis_community_max_artists';

	/** Default cap shipped with the plugin. */
	public const DEFAULT_CAP = 50;

	/**
	 * The configured cap. 0 (or negative) means "unlimited".
	 */
	public function cap(): int {
		return (int) get_option( self::OPTION, self::DEFAULT_CAP );
	}

	/** True when no cap is enforced. */
	public function is_unlimited(): bool {
		return $this->cap() <= 0;
	}

	/**
	 * Number of currently admitted artists (holders of the agnosis_artist role).
	 */
	public function count_active_artists(): int {
		$query = new \WP_User_Query(
			[
				'role'        => 'agnosis_artist',
				'count_total' => true,
				'number'      => 0,
				'fields'      => 'ID',
			]
		);

		return (int) $query->get_total();
	}

	/** True when the instance has reached (or exceeded) its cap. */
	public function is_full(): bool {
		if ( $this->is_unlimited() ) {
			return false;
		}

		return $this->count_active_artists() >= $this->cap();
	}

	/** True when there is room for at least one more admission. */
	public function has_capacity(): bool {
		return ! $this->is_full();
	}

	/**
	 * Free slots remaining, or PHP_INT_MAX when unlimited.
	 */
	public function remaining(): int {
		if ( $this->is_unlimited() ) {
			return PHP_INT_MAX;
		}

		return max( 0, $this->cap() - $this->count_active_artists() );
	}

	/**
	 * Advance the oldest waitlisted application back into the vouching flow when a
	 * slot is available — "open a slot, fill a slot".
	 *
	 * Called when a member leaves. Sets the oldest `waitlisted` row to `pending`,
	 * restarts its voting window, and fires `agnosis_waitlist_advanced` so Admission
	 * re-evaluates it (a row that already had enough vouches is admitted at once).
	 * No-ops when the instance is still full or the waitlist is empty.
	 *
	 * @return int The advanced application ID, or 0 when nothing was advanced.
	 */
	public function advance_waitlist(): int {
		global $wpdb;

		if ( ! $this->has_capacity() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$next = $wpdb->get_row(
			"SELECT id FROM {$wpdb->prefix}agnosis_applications
			 WHERE status = 'waitlisted'
			 ORDER BY applied_at ASC, id ASC
			 LIMIT 1"
		);

		if ( ! $next ) {
			return 0;
		}

		$application_id = (int) $next->id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_applications',
			[
				'status'     => 'pending',
				'applied_at' => current_time( 'mysql' ),
			],
			[ 'id' => $application_id, 'status' => 'waitlisted' ],
			[ '%s', '%s' ],
			[ '%d', '%s' ]
		);

		Logger::info( 'Community cap: advanced waitlisted application #' . $application_id . ' to pending (slot opened).', 'admission' );

		do_action( 'agnosis_waitlist_advanced', $application_id );

		return $application_id;
	}
}
