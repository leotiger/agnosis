<?php
/**
 * Email notifications for community cap-change votes.
 *
 * Listens to the actions fired by CommunityCapVote and keeps the community and
 * admin informed:
 *
 *   agnosis_cap_vote_opened  → every active artist is told a cap-change vote is open
 *   agnosis_cap_vote_passed  → admin is told the cap changed
 *   agnosis_cap_vote_failed  → admin is told the proposal failed
 *
 * Members cast their vote through the authenticated REST endpoints
 * (`/agnosis/v1/cap/vote/{id}`); a member-facing voting block and one-click email
 * links are a follow-up (mirroring the removal-vote HMAC links).
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\Core\EmailFooter;

class CommunityCapNotification {

	public function register_hooks(): void {
		add_action( 'agnosis_cap_vote_opened', [ $this, 'on_vote_opened' ], 10, 2 );
		add_action( 'agnosis_cap_vote_passed', [ $this, 'on_vote_passed' ], 10, 2 );
		add_action( 'agnosis_cap_vote_failed', [ $this, 'on_vote_failed' ], 10, 2 );
	}

	/** Tell every active artist that a cap-change vote has opened. */
	public function on_vote_opened( int $proposal_id, string $closes_at ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$proposed = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT proposed_cap FROM {$wpdb->prefix}agnosis_cap_proposals WHERE id = %d", $proposal_id )
		);

		$site_name = get_bloginfo( 'name' );
		$cap_label = 0 === $proposed ? __( 'no limit', 'agnosis' ) : (string) $proposed;

		$artists = get_users( [
			'role'   => 'agnosis_artist',
			'fields' => [ 'ID', 'user_email', 'display_name' ],
		] );

		foreach ( $artists as $artist ) {
			$locale = (string) get_user_meta( (int) $artist->ID, 'locale', true );
			if ( '' !== $locale ) {
				switch_to_locale( $locale );
			}

			$close_formatted = date_i18n( get_option( 'date_format' ), (int) strtotime( $closes_at ) );

			$body = sprintf(
				/* translators: 1: artist name, 2: site name, 3: proposed cap, 4: closing date */
				__( "Hi %1\$s,\n\nThe %2\$s community has opened a vote to change the membership size cap to %3\$s. The vote closes on %4\$s.\n\nA strict majority of active members (more than 50%%) must vote yes for the new cap to be adopted. Sign in to your account to cast your vote.\n\n— %2\$s", 'agnosis' ),
				$artist->display_name,
				$site_name,
				$cap_label,
				$close_formatted
			);

			$footer = EmailFooter::plain_text();
			if ( '' !== $footer ) {
				$body .= "\n\n" . $footer;
			}

			$edit_reminder = EmailFooter::edit_reminder_plain_text( (int) $artist->ID );
			if ( '' !== $edit_reminder ) {
				$body .= "\n\n" . $edit_reminder;
			}

			wp_mail(
				$artist->user_email,
				sprintf(
					/* translators: %s: site name */
					__( 'Community size-cap vote open at %s', 'agnosis' ),
					$site_name
				),
				$body,
				[ 'Content-Type: text/plain; charset=UTF-8' ]
			);

			if ( '' !== $locale ) {
				restore_current_locale();
			}
		}
	}

	/** Tell the admin the cap changed by member vote. */
	public function on_vote_passed( int $proposal_id, int $new_cap ): void {
		$cap_label = 0 === $new_cap ? __( 'no limit', 'agnosis' ) : (string) $new_cap;

		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: site name */
				__( 'Community size cap changed at %s', 'agnosis' ),
				get_bloginfo( 'name' )
			),
			sprintf(
				/* translators: 1: proposed cap, 2: proposal id */
				__( 'The community voted to change the membership size cap to %1$s (proposal #%2$d). The new cap is now in effect.', 'agnosis' ),
				$cap_label,
				$proposal_id
			),
			[ 'Content-Type: text/plain; charset=UTF-8' ]
		);
	}

	/** Tell the admin a cap-change proposal failed. */
	public function on_vote_failed( int $proposal_id, int $new_cap ): void {
		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: site name */
				__( 'Community size-cap proposal did not pass at %s', 'agnosis' ),
				get_bloginfo( 'name' )
			),
			sprintf(
				/* translators: 1: proposal id */
				__( 'A community vote to change the membership size cap (proposal #%1$d) closed without a majority. The cap is unchanged.', 'agnosis' ),
				$proposal_id
			),
			[ 'Content-Type: text/plain; charset=UTF-8' ]
		);
	}
}
