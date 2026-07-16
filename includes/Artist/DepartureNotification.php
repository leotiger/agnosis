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
 * Every email below is built through the shared Core\EmailTemplate shell
 * (2026-07-15 — audit-adjacent finding, not a numbered audit item: this
 * class was plain text end to end, the last one of the plugin's four
 * notification classes still in that state, converted along with
 * Artist\CommunityCapNotification and Artist\CommunityBroadcast in the
 * same pass; see CHANGELOG.md 0.9.29). Each translatable string was split
 * to roughly one sentence per `__()` call to match the granular pattern
 * every other HTML template in the plugin already uses — the previous
 * plain-text bodies each joined 3-5 sentences into one multi-paragraph
 * `\n\n`-separated string, which doesn't map onto discrete `<p>` tags.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\Core\CommunityMailer;
use Agnosis\Core\EmailFooter;
use Agnosis\Core\EmailTemplate;

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
			$this->build_confirmation_requested_body( $user->display_name, $site_name, $confirm_url ),
			$this->html_headers()
		);

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	private function build_confirmation_requested_body( string $display_name, string $site_name, string $confirm_url ): string {
		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf( /* translators: %s: recipient's display name */ esc_html__( 'Hi %s,', 'agnosis' ), esc_html( $display_name ) )
			. '</p>'
			. '<p style="margin:0 0 16px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %s: site name */
				esc_html__( 'We received a request to remove your account and all your published work from %s.', 'agnosis' ),
				esc_html( $site_name )
			)
			. '</p>'
			. '<p style="margin:0 0 24px;font-size:18px;line-height:1.6;color:#555;">'
			. esc_html__( 'If you made this request, click the link below to confirm. This action is permanent and cannot be undone.', 'agnosis' )
			. '</p>'
			. '<table cellpadding="0" cellspacing="0" style="margin:0 0 24px;"><tr><td>'
			. EmailTemplate::button( $confirm_url, __( 'Confirm removal', 'agnosis' ), [ 'bg' => EmailTemplate::DANGER ] )
			. '</td></tr></table>'
			. '<p style="margin:0;font-size:15px;color:#999;">'
			. esc_html__( 'If you did not make this request, you can ignore this email — your account remains unchanged.', 'agnosis' )
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
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

		// Brief notice to admin only — artists don't need to know who left.
		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: site name */
				__( 'An artist has left %s', 'agnosis' ),
				$site_name
			),
			$this->build_artist_left_admin_body( $display_name, $site_name ),
			$this->html_headers()
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
			$this->build_artist_left_confirmation_body( $display_name, $site_name ),
			$this->html_headers()
		);

		if ( '' !== $artist_locale ) {
			restore_current_locale();
		}
	}

	private function build_artist_left_admin_body( string $display_name, string $site_name ): string {
		$body = '<p style="margin:0 0 16px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: 1: artist display name, 2: site name */
				esc_html__( '%1$s has confirmed their departure from %2$s.', 'agnosis' ),
				esc_html( $display_name ),
				esc_html( $site_name )
			)
			. '</p>'
			. '<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">'
			. esc_html__( 'Their account and all published work have been permanently deleted.', 'agnosis' )
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
	}

	private function build_artist_left_confirmation_body( string $display_name, string $site_name ): string {
		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf( /* translators: %s: recipient's display name */ esc_html__( 'Hi %s,', 'agnosis' ), esc_html( $display_name ) )
			. '</p>'
			. '<p style="margin:0 0 16px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %s: site name */
				esc_html__( 'This confirms that your account and everything you published on %s have been permanently deleted, as you requested.', 'agnosis' ),
				esc_html( $site_name )
			)
			. '</p>'
			. '<p style="margin:0 0 16px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %s: site name */
				esc_html__( 'Nothing tied to you — your artwork, biography, events, or account details — is stored on %s anymore.', 'agnosis' ),
				esc_html( $site_name )
			)
			. '</p>'
			. '<p style="margin:0;font-size:15px;color:#999;">'
			. esc_html__( "If you didn't request this, please contact the site admin right away.", 'agnosis' )
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
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

		$until_formatted = $banned_until ? date_i18n( get_option( 'date_format' ), strtotime( $banned_until ) ) : null;
		$body            = $this->build_banned_body( $user->display_name, $site_name, $until_formatted );

		wp_mail(
			$user->user_email,
			sprintf(
				/* translators: %s: site name */
				__( 'Your membership at %s has been suspended', 'agnosis' ),
				$site_name
			),
			$body,
			$this->html_headers()
		);

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	private function build_banned_body( string $display_name, string $site_name, ?string $until_formatted ): string {
		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf( /* translators: %s: recipient's display name */ esc_html__( 'Hi %s,', 'agnosis' ), esc_html( $display_name ) )
			. '</p>';

		if ( null !== $until_formatted ) {
			$body .= '<p style="margin:0 0 16px;font-size:18px;line-height:1.6;color:#555;">'
				. sprintf(
					/* translators: 1: site name, 2: date the suspension ends */
					esc_html__( 'Your membership at %1$s has been temporarily suspended until %2$s.', 'agnosis' ),
					esc_html( $site_name ),
					esc_html( $until_formatted )
				)
				. '</p>'
				. '<p style="margin:0 0 16px;font-size:18px;line-height:1.6;color:#555;">'
				. esc_html__( 'You will be automatically reinstated on that date.', 'agnosis' )
				. '</p>';
		} else {
			$body .= '<p style="margin:0 0 16px;font-size:18px;line-height:1.6;color:#555;">'
				. sprintf(
					/* translators: %s: site name */
					esc_html__( 'Your membership at %s has been suspended.', 'agnosis' ),
					esc_html( $site_name )
				)
				. '</p>';
		}

		$body .= '<p style="margin:0;font-size:15px;color:#999;">'
			. esc_html__( 'If you have questions, please contact the site admin.', 'agnosis' )
			. '</p>';

		// Deliberately no EmailFooter::edit_reminder_html() here — apply_ban()
		// removes the agnosis_artist role for the duration of the ban, which is
		// the same capability ContentEditor::check_access() requires, so "you
		// can edit directly on your page" would be false for as long as this
		// email applies. See build_reinstated_body() below, where the reminder
		// returns once access does.
		$work_emails_html = EmailFooter::html();
		$footer_extra     = '' !== $work_emails_html
			? '<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid ' . esc_attr( EmailTemplate::border_color() ) . ';">' . $work_emails_html . '</div>'
			: '';

		return EmailTemplate::render( $this->html_lang(), $body, $footer_extra );
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

		wp_mail(
			$user->user_email,
			sprintf(
				/* translators: %s: site name */
				__( 'Your membership at %s has been reinstated', 'agnosis' ),
				$site_name
			),
			$this->build_reinstated_body( $user->display_name, $site_name, $user_id ),
			$this->html_headers()
		);

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	private function build_reinstated_body( string $display_name, string $site_name, int $user_id ): string {
		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf( /* translators: %s: recipient's display name */ esc_html__( 'Hi %s,', 'agnosis' ), esc_html( $display_name ) )
			. '</p>'
			. '<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %s: site name */
				esc_html__( 'Your temporary suspension at %s has ended and your membership has been reinstated. You can now log in and submit work as before.', 'agnosis' ),
				esc_html( $site_name )
			)
			. '</p>';

		$footer_extra = '';

		$work_emails_html = EmailFooter::html();
		if ( '' !== $work_emails_html ) {
			$footer_extra .= '<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid ' . esc_attr( EmailTemplate::border_color() ) . ';">' . $work_emails_html . '</div>';
		}

		// Reinstatement is the moment ContentEditor access comes back (the ban
		// removed it — see build_banned_body() above, which deliberately omits
		// this same reminder for exactly that reason).
		$edit_reminder_html = EmailFooter::edit_reminder_html( $user_id );
		if ( '' !== $edit_reminder_html ) {
			$footer_extra .= '<p style="margin:12px 0 0;font-size:15px;color:#888;text-align:center;">' . $edit_reminder_html . '</p>';
		}

		return EmailTemplate::render( $this->html_lang(), $body, $footer_extra );
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

			wp_mail(
				$artist->user_email,
				sprintf(
					/* translators: %s: site name */
					__( 'Community removal vote open at %s', 'agnosis' ),
					$site_name
				),
				$this->build_vote_opened_body( $artist->display_name, $site_name, $close_formatted, $yes_url, $no_url, $voter_id ),
				$this->html_headers()
			);

			if ( '' !== $locale ) {
				restore_current_locale();
			}
		}
	}

	private function build_vote_opened_body( string $voter_name, string $site_name, string $close_formatted, string $yes_url, string $no_url, int $voter_id ): string {
		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf( /* translators: %s: recipient's display name */ esc_html__( 'Hi %s,', 'agnosis' ), esc_html( $voter_name ) )
			. '</p>'
			. '<p style="margin:0 0 24px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: 1: site name, 2: closing date */
				esc_html__( 'The %1$s community has opened a vote to remove a member. The vote closes on %2$s.', 'agnosis' ),
				esc_html( $site_name ),
				esc_html( $close_formatted )
			)
			. '</p>'
			. '<table cellpadding="0" cellspacing="0" style="margin:0 0 16px;"><tr><td>'
			. EmailTemplate::button( $yes_url, __( 'Vote YES (remove)', 'agnosis' ), [ 'bg' => EmailTemplate::DANGER, 'margin' => '6px 8px 6px 0' ] )
			. EmailTemplate::button( $no_url, __( 'Vote NO (keep)', 'agnosis' ), [ 'margin' => '6px 8px 6px 0' ] )
			. '</td></tr></table>'
			. '<p style="margin:0;font-size:15px;color:#999;">'
			. esc_html__( 'A majority of active members (more than 50%) must vote yes for the removal to proceed. You can change your vote by clicking the other link before the deadline.', 'agnosis' )
			. '</p>';

		$footer_extra = '';

		$work_emails_html = EmailFooter::html();
		if ( '' !== $work_emails_html ) {
			$footer_extra .= '<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid ' . esc_attr( EmailTemplate::border_color() ) . ';">' . $work_emails_html . '</div>';
		}

		$edit_reminder_html = EmailFooter::edit_reminder_html( $voter_id );
		if ( '' !== $edit_reminder_html ) {
			$footer_extra .= '<p style="margin:12px 0 0;font-size:15px;color:#888;text-align:center;">' . $edit_reminder_html . '</p>';
		}

		return EmailTemplate::render( $this->html_lang(), $body, $footer_extra );
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
			$this->build_vote_passed_body( $site_name ),
			$this->html_headers()
		);
	}

	private function build_vote_passed_body( string $site_name ): string {
		$body = '<p style="margin:0 0 16px;font-size:18px;line-height:1.6;color:#555;">'
			. esc_html__( 'The community removal vote has closed with a majority in favor.', 'agnosis' )
			. '</p>'
			. '<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %s: site name */
				esc_html__( "The artist's account and all published work have been permanently deleted from %s.", 'agnosis' ),
				esc_html( $site_name )
			)
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
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
			$this->build_vote_failed_body(),
			$this->html_headers()
		);
	}

	private function build_vote_failed_body(): string {
		$body = '<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">'
			. esc_html__( "The community removal vote has closed. The required majority was not reached and the artist's membership remains active.", 'agnosis' )
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
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
	 * Headers for every email in this class.
	 *
	 * Every email this class sends is now HTML, built through the shared
	 * Core\EmailTemplate shell (2026-07-15) — the plain-text `text_headers()`
	 * variant this method replaced delegated to the same Core\CommunityMailer
	 * sender identity (Settings → Email → "Mail from:"); this file previously
	 * carried no From header at all, so wp_mail() fell through to WordPress's
	 * own "WordPress <wordpress@$domain>" default rather than a real,
	 * deliverable address (found 2026-07-08 — same issue as the vouch vote
	 * email in AdmissionNotification).
	 *
	 * @return array<string>
	 */
	private function html_headers(): array {
		return CommunityMailer::html_headers();
	}

	/**
	 * Return the BCP 47 language tag for use in the HTML <html lang="…">
	 * attribute. Must be called AFTER switch_to_locale() so get_locale()
	 * returns the recipient's locale, not the site locale — mirrors
	 * Publishing\Notification::html_lang().
	 */
	private function html_lang(): string {
		$locale = get_locale();
		return $locale ? str_replace( '_', '-', $locale ) : 'en';
	}
}
