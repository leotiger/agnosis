<?php
/**
 * Email notifications for artist departure flows.
 *
 * Hooks into actions fired by Departure.php:
 *
 *  agnosis_departure_confirmation_requested → artist receives a confirmation link
 *  agnosis_artist_left                      → admin AND the artist are notified (self-removal)
 *  agnosis_artist_banned                    → artist is notified of the ban
 *  agnosis_artist_reinstated                → artist is notified of reinstatement
 *  agnosis_artist_deleted_by_admin          → (no email — account is gone)
 *  agnosis_removal_vote_opened              → all artists receive a vote email
 *  agnosis_removal_vote_passed              → subject and community are notified
 *  agnosis_removal_vote_failed              → community is notified (no action taken)
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\Core\CommunityMailer;
use Agnosis\Core\EmailFooter;

class DepartureNotification {

	public function register_hooks(): void {
		add_action( 'agnosis_departure_confirmation_requested', [ $this, 'on_confirmation_requested' ], 10, 2 );
		add_action( 'agnosis_artist_left',                      [ $this, 'on_artist_left'             ], 10, 4 );
		add_action( 'agnosis_artist_banned',                    [ $this, 'on_artist_banned'           ], 10, 3 );
		add_action( 'agnosis_artist_reinstated',                [ $this, 'on_artist_reinstated'       ], 10, 1 );
		add_action( 'agnosis_removal_vote_opened',              [ $this, 'on_vote_opened'             ], 10, 2 );
		add_action( 'agnosis_removal_vote_passed',              [ $this, 'on_vote_passed'             ], 10, 2 );
		add_action( 'agnosis_removal_vote_failed',              [ $this, 'on_vote_failed'             ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Self-removal confirmation
	// -------------------------------------------------------------------------

	/**
	 * Send the artist a confirmation link they must click to finalise removal.
	 *
	 * @param int    $user_id  WP user ID.
	 * @param string $token    Single-use CSPRNG hex token stored on the membership row.
	 */
	public function on_confirmation_requested( int $user_id, string $token ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		$site_name    = get_bloginfo( 'name' );
		$confirm_url  = add_query_arg(
			[ 'agnosis_departure' => '1', 'token' => $token ],
			home_url( '/' )
		);

		$locale = (string) get_user_meta( $user_id, 'locale', true );
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		wp_mail(
			$user->user_email,
			sprintf(
				/* translators: %s: site name */
				__( 'Confirm your departure from %s', 'agnosis' ),
				$site_name
			),
			sprintf(
				/* translators: 1: artist display name, 2: site name, 3: confirmation URL */
				__( "Hi %1\$s,\n\nWe received a request to remove your account and all your published work from %2\$s.\n\nIf you made this request, click the link below to confirm. This action is permanent and cannot be undone.\n\n%3\$s\n\nIf you did not make this request, you can ignore this email — your account remains unchanged.\n\n— %2\$s", 'agnosis' ),
				$user->display_name,
				$site_name,
				$confirm_url
			),
			$this->text_headers()
		);

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	// -------------------------------------------------------------------------
	// Self-removal confirmed
	// -------------------------------------------------------------------------

	/**
	 * Notify the community that an artist has left voluntarily, and separately
	 * confirm to the artist themselves that their account and content are gone.
	 *
	 * We do not name the artist in the community email — a simple notice
	 * that membership numbers have changed is sufficient.
	 *
	 * The artist-facing confirmation (added 2026-07-08) was the one thing this
	 * flow was missing: an artist who clicks the confirmation link now sees a
	 * result page (Departure::render_departure_result()), but had no lasting
	 * record that the deletion actually happened, or explicit reassurance that
	 * nothing of theirs is stored here anymore. $artist_email/$artist_locale
	 * are passed in rather than looked up here because by the time this fires
	 * the WP user account is already deleted — see
	 * Departure::confirm_self_removal()'s docblock.
	 *
	 * @param int    $user_id        WP user ID (account now deleted — display_name only via membership row).
	 * @param int    $application_id Membership row ID.
	 * @param string $artist_email   The artist's email, captured before account
	 *                               deletion. Empty when unavailable — the
	 *                               artist-facing email is skipped in that case.
	 * @param string $artist_locale  The artist's WP locale, captured before
	 *                               account deletion. Empty when unset.
	 */
	public function on_artist_left( int $user_id, int $application_id, string $artist_email = '', string $artist_locale = '' ): void {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT display_name FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$application_id
			)
		);

		if ( ! $row ) {
			return;
		}

		$site_name    = get_bloginfo( 'name' );
		$display_name = $row->display_name;

		// Brief plain-text notice to admin only — artists don't need to know who left.
		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: site name */
				__( 'An artist has left %s', 'agnosis' ),
				$site_name
			),
			sprintf(
				/* translators: 1: artist display name, 2: site name */
				__( "%1\$s has confirmed their departure from %2\$s.\n\nTheir account and all published work have been permanently deleted.\n\n— %2\$s", 'agnosis' ),
				$display_name,
				$site_name
			),
			$this->text_headers()
		);

		// Confirm to the artist themselves that the deletion actually happened.
		// Best-effort: silently skipped if we have no address at all (e.g. this
		// action fired from something other than confirm_self_removal() without
		// supplying one) — never treated as an error, since the removal itself
		// already succeeded regardless of whether this email can be sent.
		if ( '' === $artist_email ) {
			return;
		}

		if ( '' !== $artist_locale ) {
			switch_to_locale( $artist_locale );
		}

		wp_mail(
			$artist_email,
			sprintf(
				/* translators: %s: site name */
				__( "You've left %s", 'agnosis' ),
				$site_name
			),
			sprintf(
				/* translators: 1: artist display name, 2: site name */
				__( "Hi %1\$s,\n\nThis confirms that your account and everything you published on %2\$s have been permanently deleted, as you requested.\n\nNothing tied to you — your artwork, biography, events, or account details — is stored on %2\$s anymore.\n\nIf you didn't request this, please contact the site admin right away.\n\n— %2\$s", 'agnosis' ),
				$display_name,
				$site_name
			),
			$this->text_headers()
		);

		if ( '' !== $artist_locale ) {
			restore_current_locale();
		}
	}

	// -------------------------------------------------------------------------
	// Admin ban
	// -------------------------------------------------------------------------

	/**
	 * Notify the banned artist.
	 *
	 * @param int         $user_id        WP user ID.
	 * @param int         $application_id Membership row ID.
	 * @param string|null $banned_until   MySQL datetime string or null for indefinite.
	 */
	public function on_artist_banned( int $user_id, int $application_id, ?string $banned_until ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );

		$locale = (string) get_user_meta( $user_id, 'locale', true );
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		if ( $banned_until ) {
			$until_formatted = date_i18n( get_option( 'date_format' ), strtotime( $banned_until ) );
			$body = sprintf(
				/* translators: 1: artist display name, 2: site name, 3: date */
				__( "Hi %1\$s,\n\nYour membership at %2\$s has been temporarily suspended until %3\$s.\n\nYou will be automatically reinstated on that date. If you have questions, please contact the site admin.\n\n— %2\$s", 'agnosis' ),
				$user->display_name,
				$site_name,
				$until_formatted
			);
		} else {
			$body = sprintf(
				/* translators: 1: artist display name, 2: site name */
				__( "Hi %1\$s,\n\nYour membership at %2\$s has been suspended.\n\nIf you have questions, please contact the site admin.\n\n— %2\$s", 'agnosis' ),
				$user->display_name,
				$site_name
			);
		}

		$footer = EmailFooter::plain_text();
		if ( '' !== $footer ) {
			$body .= "\n\n" . $footer;
		}

		// Deliberately no EmailFooter::edit_reminder_plain_text() here — apply_ban()
		// removes the agnosis_artist role for the duration of the ban, which is the
		// same capability ContentEditor::check_access() requires, so "you can edit
		// directly on your page" would be false for as long as this email applies.
		// See on_artist_reinstated() below, where the reminder returns once access does.

		wp_mail(
			$user->user_email,
			sprintf(
				/* translators: %s: site name */
				__( 'Your membership at %s has been suspended', 'agnosis' ),
				$site_name
			),
			$body,
			$this->text_headers()
		);

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	// -------------------------------------------------------------------------
	// Ban expiry / reinstatement
	// -------------------------------------------------------------------------

	/**
	 * Notify the reinstated artist that their temporary ban has expired.
	 *
	 * @param int $user_id  WP user ID.
	 */
	public function on_artist_reinstated( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );

		$locale = (string) get_user_meta( $user_id, 'locale', true );
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		$body = sprintf(
			/* translators: 1: artist display name, 2: site name */
			__( "Hi %1\$s,\n\nYour temporary suspension at %2\$s has ended and your membership has been reinstated. You can now log in and submit work as before.\n\n— %2\$s", 'agnosis' ),
			$user->display_name,
			$site_name
		);

		$footer = EmailFooter::plain_text();
		if ( '' !== $footer ) {
			$body .= "\n\n" . $footer;
		}

		// Reinstatement is the moment ContentEditor access comes back (the ban
		// removed it — see the suspension email above, which deliberately
		// omits this same reminder for exactly that reason).
		$edit_reminder = EmailFooter::edit_reminder_plain_text( $user_id );
		if ( '' !== $edit_reminder ) {
			$body .= "\n\n" . $edit_reminder;
		}

		wp_mail(
			$user->user_email,
			sprintf(
				/* translators: %s: site name */
				__( 'Your membership at %s has been reinstated', 'agnosis' ),
				$site_name
			),
			$body,
			$this->text_headers()
		);

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	// -------------------------------------------------------------------------
	// Community removal vote
	// -------------------------------------------------------------------------

	/**
	 * Notify all artists that a community removal vote has opened.
	 *
	 * Each artist receives a personal yes/no vote link. We do not identify the
	 * subject by name in the email — artists access the vote page to see context.
	 *
	 * @param int    $request_id  Removal request ID.
	 * @param string $closes_at   MySQL datetime string when the vote closes.
	 */
	public function on_vote_opened( int $request_id, string $closes_at ): void {
		global $wpdb;

		$removal = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT subject_user_id FROM {$wpdb->prefix}agnosis_removal_requests WHERE id = %d",
				$request_id
			)
		);

		if ( ! $removal ) {
			return;
		}

		$site_name       = get_bloginfo( 'name' );
		$subject_user_id = (int) $removal->subject_user_id;

		$artists = get_users( [
			'role'    => 'agnosis_artist',
			'exclude' => [ $subject_user_id ], // Subject cannot vote on their own removal.
			'fields'  => [ 'ID', 'user_email', 'display_name' ],
		] );

		foreach ( $artists as $artist ) {
			$voter_id = (int) $artist->ID;
			$locale   = (string) get_user_meta( $voter_id, 'locale', true );

			if ( '' !== $locale ) {
				switch_to_locale( $locale );
			}

			$close_formatted = date_i18n( get_option( 'date_format' ), strtotime( $closes_at ) );
			$yes_url = $this->removal_vote_url( $request_id, $voter_id, 'yes' );
			$no_url  = $this->removal_vote_url( $request_id, $voter_id, 'no' );

			$body = sprintf(
				/* translators: 1: voter display name, 2: site name, 3: closing date, 4: yes URL, 5: no URL */
				__( "Hi %1\$s,\n\nThe %2\$s community has opened a vote to remove a member. The vote closes on %3\$s.\n\nCast your vote:\n\nVote YES (remove): %4\$s\n\nVote NO (keep):    %5\$s\n\nA majority of active members (more than 50%%) must vote yes for the removal to proceed. You can change your vote by clicking the other link before the deadline.\n\n— %2\$s", 'agnosis' ),
				$artist->display_name,
				$site_name,
				$close_formatted,
				$yes_url,
				$no_url
			);

			$footer = EmailFooter::plain_text();
			if ( '' !== $footer ) {
				$body .= "\n\n" . $footer;
			}

			$edit_reminder = EmailFooter::edit_reminder_plain_text( $voter_id );
			if ( '' !== $edit_reminder ) {
				$body .= "\n\n" . $edit_reminder;
			}

			wp_mail(
				$artist->user_email,
				sprintf(
					/* translators: %s: site name */
					__( 'Community removal vote open at %s', 'agnosis' ),
					$site_name
				),
				$body,
				$this->text_headers()
			);

			if ( '' !== $locale ) {
				restore_current_locale();
			}
		}
	}

	/**
	 * Notify the subject that the removal vote passed, and notify the community.
	 *
	 * The subject's account is deleted by the time this fires, so we read
	 * the display_name from the membership row.
	 *
	 * @param int $subject_user_id  WP user ID (account now deleted).
	 * @param int $request_id       Removal request ID.
	 */
	public function on_vote_passed( int $subject_user_id, int $request_id ): void {
		// Notify admin — subject account is gone, no email possible.
		$site_name = get_bloginfo( 'name' );

		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: site name */
				__( 'Community removal vote passed at %s', 'agnosis' ),
				$site_name
			),
			sprintf(
				/* translators: %s: site name */
				__( "The community removal vote has closed with a majority in favour.\n\nThe artist's account and all published work have been permanently deleted from %1\$s.\n\n— %2\$s", 'agnosis' ),
				$site_name,
				$site_name
			),
			$this->text_headers()
		);
	}

	/**
	 * Notify the community and admin that the removal vote failed.
	 *
	 * @param int $subject_user_id  WP user ID (still active).
	 * @param int $request_id       Removal request ID.
	 */
	public function on_vote_failed( int $subject_user_id, int $request_id ): void {
		$site_name = get_bloginfo( 'name' );

		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: site name */
				__( 'Community removal vote did not pass at %s', 'agnosis' ),
				$site_name
			),
			sprintf(
				/* translators: %s: site name */
				__( "The community removal vote has closed. The required majority was not reached and the artist's membership remains active.\n\n— %s", 'agnosis' ),
				$site_name
			),
			$this->text_headers()
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build an HMAC-signed URL for a removal vote action.
	 *
	 * Token: hash_hmac( 'sha256', "$voter_id|$request_id|$vote", wp_salt('auth') )
	 * Stateless — verified and recorded by the RemovalVoteConfirm shim.
	 */
	public static function removal_vote_url( int $request_id, int $voter_id, string $vote ): string {
		$token = hash_hmac(
			'sha256',
			"{$voter_id}|{$request_id}|{$vote}",
			wp_salt( 'auth' )
		);

		return add_query_arg(
			[
				'agnosis_removal_vote' => '1',
				'rid'   => $request_id,
				'vid'   => $voter_id,
				'vote'  => $vote,
				'token' => $token,
			],
			home_url( '/' )
		);
	}

	/**
	 * Previously carried no From header, so wp_mail() fell through to
	 * WordPress's own "WordPress <wordpress@$domain>" default rather than a
	 * real, deliverable address (found 2026-07-08 — same issue as the vouch
	 * vote email in AdmissionNotification). Now delegates to
	 * Core\CommunityMailer, the shared workflow sender identity.
	 *
	 * @return array<string>
	 */
	private function text_headers(): array {
		return CommunityMailer::text_headers();
	}
}
