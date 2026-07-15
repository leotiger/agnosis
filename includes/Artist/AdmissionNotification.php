<?php
/**
 * Email notifications for the artist admission flow.
 *
 * Hooks into the actions fired by Admission.php and sends:
 *   - agnosis_artist_applied     → admin + all current artists get the application
 *                                  with personalised HMAC-signed yes/no vote links.
 *   - agnosis_application_expired → applicant is told the community declined;
 *                                   artists are notified the window has closed.
 *   - agnosis_artist_admitted     → applicant is welcomed with a password-reset link.
 *
 * Email-link votes are processed by VouchConfirm (template_redirect handler).
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\Artist\Admission;
use Agnosis\Core\CommunityMailer;
use Agnosis\Core\EmailFooter;
use Agnosis\Core\EmailTemplate;
use Agnosis\Network\SubdomainRouter;

class AdmissionNotification {

	public function register_hooks(): void {
		add_action( 'agnosis_application_unverified', [ $this, 'on_application_unverified' ], 10, 4 );
		add_action( 'agnosis_artist_applied',     [ $this, 'on_application_received' ], 10, 3 );
		add_action( 'agnosis_application_expired', [ $this, 'on_application_expired' ], 10, 1 );
		add_action( 'agnosis_artist_admitted',     [ $this, 'on_artist_admitted' ],     10, 2 );
	}

	// -------------------------------------------------------------------------
	// Action callbacks
	// -------------------------------------------------------------------------

	/**
	 * Send the double opt-in "confirm your application" email (security audit
	 * §3a/§4a) — the only email Admission::apply() itself triggers.
	 * Deliberately short: a single confirm link, nothing else. Neither the
	 * acknowledgment email nor the community vote blast fire from here — both
	 * wait for Admission::confirm_application() to fire agnosis_artist_applied
	 * (or agnosis_artist_waitlisted), handled by on_application_received()
	 * below exactly as before. This is what closes the audit's two lanes: a
	 * forged address can trigger this one short email to itself, but never
	 * backscatter to a third party, and no attacker-controlled bio/portfolio/
	 * statement content ever reaches the community until the sender proves
	 * they control the inbox.
	 *
	 * @param int    $application_id Row ID in agnosis_applications.
	 * @param string $email          Applicant's email address.
	 * @param string $display_name   Applicant's display name.
	 * @param string $token          Single-use confirm_token from Admission::apply().
	 */
	public function on_application_unverified( int $application_id, string $email, string $display_name, string $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$language = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT language FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$application_id
			)
		);

		$applicant_locale = '' !== $language ? Admission::iso_to_wp_locale( $language ) : '';
		if ( '' !== $applicant_locale ) {
			switch_to_locale( $applicant_locale );
		}

		$confirm_url = add_query_arg(
			[
				'agnosis_admission' => '1',
				'action'            => 'confirm',
				'token'             => $token,
			],
			home_url( '/' )
		);

		wp_mail(
			$email,
			sprintf(
				/* translators: %s: community name */
				__( 'Confirm your application to %s', 'agnosis' ),
				get_bloginfo( 'name' )
			),
			$this->build_confirm_body( $display_name, $confirm_url ),
			$this->html_headers()
		);

		if ( '' !== $applicant_locale ) {
			restore_current_locale();
		}
	}

	/**
	 * Notify admin and every current artist that a new application has arrived.
	 *
	 * Each artist receives a unique email with signed yes/no links so they can
	 * vote without logging in. The admin copy includes the same links for the
	 * admin's own user account (ID = 1 fallback, or the first administrator).
	 *
	 * @param int    $application_id Row ID in agnosis_applications.
	 * @param string $email          Applicant's email address.
	 * @param string $display_name   Applicant's display name.
	 */
	public function on_application_received( int $application_id, string $email, string $display_name ): void {
		global $wpdb;

		// Load the full application row for bio / portfolio / statement.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$application_id
			)
		);

		if ( ! $application ) {
			return;
		}

		/** @var object{id: int, email: string, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null, language: string|null} $application */

		$window = (int) get_option( 'agnosis_admission_window_days', 7 );

		// Acknowledge the application to the applicant in their own language.
		$this->send_application_acknowledgment( $application, $window );

		// Collect current artists who want their vote emails instantly (the
		// default) — excludes an artist who has switched to daily-digest mode
		// (security audit §5b/§4a: the digest option doubles as an amplifier
		// damper on §4a's community-wide vote blast). A digest-mode artist
		// isn't skipped silently: Artist\VoteDigest's daily cron picks up every
		// still-open application they haven't voted on yet, so nothing here
		// needs to track "who still needs notifying" — see that class's own
		// docblock for why re-deriving from open-applications-not-yet-voted-on
		// is simpler and self-healing compared to a delta/queue approach.
		$artists = get_users( [
			'role'       => 'agnosis_artist',
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small table (admitted artists only), acceptable.
				'relation' => 'OR',
				[ 'key' => '_agnosis_vote_email_mode', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_agnosis_vote_email_mode', 'value' => 'digest', 'compare' => '!=' ],
			],
			'fields'     => [ 'ID', 'user_email', 'display_name' ],
		] );

		// Send one personalised email per artist.
		foreach ( $artists as $artist ) {
			$this->send_vote_email(
				(int) $artist->ID,
				$artist->user_email,
				$artist->display_name,
				$application,
				$window
			);
		}

		// Send admin copy — use the first administrator account if no artists exist yet.
		$admin_id = $this->get_admin_user_id( $artists );
		if ( $admin_id && ! $this->is_artist( $admin_id ) ) {
			$admin = get_userdata( $admin_id );
			if ( $admin ) {
				$this->send_vote_email( $admin_id, $admin->user_email, $admin->display_name, $application, $window );
			}
		}

		// Brief summary to the site admin address (no vote links — for archive/oversight).
		// $deferred_count reflects digest-mode artists who were deliberately
		// excluded from $artists above, not an omission — worth surfacing so an
		// operator reading this summary doesn't mistake a lower notified-count
		// for something having gone wrong.
		$total_artist_count = count( get_users( [ 'role' => 'agnosis_artist', 'fields' => 'ID' ] ) );
		$deferred_count      = max( 0, $total_artist_count - count( $artists ) );
		$this->send_admin_summary( $application, count( $artists ), $deferred_count, $window );
	}

	/**
	 * Notify the applicant their application has expired, and inform the community.
	 *
	 * @param int $application_id Row ID in agnosis_applications.
	 */
	public function on_application_expired( int $application_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$application_id
			)
		);

		if ( ! $application ) {
			return;
		}

		/** @var object{id: int, email: string, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null, language: string|null} $application */

		// Switch to the applicant's language for their rejection email.
		$applicant_locale = '' !== ( $application->language ?? '' )
			? Admission::iso_to_wp_locale( (string) $application->language )
			: '';
		if ( '' !== $applicant_locale ) {
			switch_to_locale( $applicant_locale );
		}

		// Notify the applicant.
		wp_mail(
			$application->email,
			sprintf(
				/* translators: %s: community name */
				__( 'Your application to %s', 'agnosis' ),
				get_bloginfo( 'name' )
			),
			$this->build_expiry_applicant_body( $application ),
			$this->html_headers()
		);

		if ( '' !== $applicant_locale ) {
			restore_current_locale();
		}

		// Brief notice to the community — no personal data beyond display name.
		$artists = get_users( [
			'role'   => 'agnosis_artist',
			'fields' => [ 'user_email' ],
		] );

		$to = array_column( (array) $artists, 'user_email' );
		if ( empty( $to ) ) {
			return;
		}

		wp_mail(
			get_option( 'admin_email' ), // From header only — BCC the community.
			sprintf(
				/* translators: %s: applicant display name */
				__( 'Application by %s has closed', 'agnosis' ),
				$application->display_name
			),
			$this->build_expiry_community_body( $application ),
			array_merge( $this->html_headers(), $this->bcc_headers( $to ) )
		);
	}

	/**
	 * Welcome the newly admitted artist.
	 *
	 * No account credentials are pushed on the artist here — working with
	 * Agnosis (submitting, editing, removing work) is entirely email-based
	 * and needs no login at all. This only points to WordPress's own
	 * self-service "Forgot your password?" flow (wp_lostpassword_url()) for
	 * the artist to use later, entirely at their own option, if they want the
	 * optional online features (e.g. previewing a submission before it
	 * publishes). See build_welcome_body()'s docblock for the reasoning.
	 *
	 * @param int $user_id        WP user ID of the newly created artist.
	 * @param int $application_id Row ID in agnosis_applications.
	 */
	public function on_artist_admitted( int $user_id, int $application_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$lostpassword_url = wp_lostpassword_url( home_url( '/my-submissions/' ) );

		// maybe_admit() has already set the user locale — read it fresh from meta.
		$artist_locale = (string) get_user_meta( $user_id, 'locale', true );
		if ( '' !== $artist_locale ) {
			switch_to_locale( $artist_locale );
		}

		wp_mail(
			$user->user_email,
			sprintf(
				/* translators: %s: community name */
				__( 'Welcome to %s', 'agnosis' ),
				get_bloginfo( 'name' )
			),
			$this->build_welcome_body( $user, $lostpassword_url ),
			$this->html_headers()
		);

		if ( '' !== $artist_locale ) {
			restore_current_locale();
		}
	}

	// -------------------------------------------------------------------------
	// Token generation — mirrors VouchConfirm::verify_token()
	// -------------------------------------------------------------------------

	/**
	 * Build the HMAC-signed URL for a vote action.
	 *
	 * Token: hash_hmac( 'sha256', "$voter_id|$application_id|$vote", wp_salt('auth') )
	 * This is a stateless token — no DB write needed. VouchConfirm verifies and records the vote.
	 *
	 * @param int    $voter_id       WP user ID of the voter.
	 * @param int    $application_id Application row ID.
	 * @param string $vote           'yes' or 'no'.
	 */
	public static function vote_url( int $voter_id, int $application_id, string $vote ): string {
		$token = hash_hmac(
			'sha256',
			"{$voter_id}|{$application_id}|{$vote}",
			wp_salt( 'auth' )
		);

		return add_query_arg(
			[
				'agnosis_vouch' => '1',
				'voter'         => $voter_id,
				'app'           => $application_id,
				'vote'          => $vote,
				'token'         => $token,
			],
			home_url( '/' )
		);
	}

	// -------------------------------------------------------------------------
	// Email builders
	// -------------------------------------------------------------------------

	/**
	 * Send an acknowledgment email to the applicant confirming their application
	 * was received and explaining the next steps, in their own language.
	 *
	 * @param object{id: int, email: string, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null, language: string|null} $application
	 * @param int $window Voting window in days.
	 */
	private function send_application_acknowledgment( object $application, int $window ): void {
		$applicant_locale = '' !== ( $application->language ?? '' )
			? Admission::iso_to_wp_locale( (string) $application->language )
			: '';

		if ( '' !== $applicant_locale ) {
			switch_to_locale( $applicant_locale );
		}

		wp_mail(
			$application->email,
			sprintf(
				/* translators: %s: community name */
				__( 'We received your application to %s', 'agnosis' ),
				get_bloginfo( 'name' )
			),
			$this->build_acknowledgment_body( $application, $window ),
			$this->html_headers()
		);

		if ( '' !== $applicant_locale ) {
			restore_current_locale();
		}
	}

	/**
	 * Build the HTML "confirm your application" email — deliberately the
	 * shortest email in this class: a single link, no bio/portfolio/statement
	 * echoed back (nothing has been shown to anyone else yet), and no
	 * work-email footer, same reasoning as build_acknowledgment_body() below
	 * — this recipient hasn't been admitted, so those addresses don't apply.
	 */
	private function build_confirm_body( string $display_name, string $confirm_url ): string {
		$site_name = get_bloginfo( 'name' );

		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $display_name )
			)
			. '</p>'
			. '<p style="margin:0 0 28px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %s: community name */
				esc_html__( 'One last step before %s can review your application: confirm this is really your email address.', 'agnosis' ),
				esc_html( $site_name )
			)
			. '</p>'
			. '<table cellpadding="0" cellspacing="0" style="margin:0 0 24px;"><tr><td>'
			. EmailTemplate::button( $confirm_url, __( 'Confirm my application', 'agnosis' ) )
			. '</td></tr></table>'
			. '<p style="margin:0;font-size:15px;color:#999;">'
			. esc_html__( "If you didn't apply, simply ignore this email — nothing happens until this link is clicked.", 'agnosis' )
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
	}

	/**
	 * Build the HTML "application received" acknowledgment email.
	 *
	 * Deliberately does NOT include EmailFooter::html()/plain_text() — those
	 * work-submission addresses (artwork@, bio@, replace@, etc.) are only
	 * usable by an admitted artist. At this point the applicant is still
	 * pending community review and has no account yet, so listing them here
	 * previously just showed a confusing wall of addresses that would bounce
	 * or go nowhere useful if used. See build_welcome_body() below, where the
	 * same addresses legitimately belong once the applicant is actually admitted.
	 *
	 * @param object{display_name: string} $application
	 */
	private function build_acknowledgment_body( object $application, int $window ): string {
		$site_name = get_bloginfo( 'name' );
		$accent    = EmailTemplate::accent();

		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $application->display_name )
			)
			. '</p>'
			. '<p style="margin:0 0 20px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %s: community name */
				esc_html__( 'Thank you for applying to %s. Your application has been received and is now open for community review.', 'agnosis' ),
				esc_html( $site_name )
			)
			. '</p>'
			. '<p style="margin:0;font-size:17px;line-height:1.6;color:#666;padding:16px 20px;background:#f9f9f9;border-left:3px solid ' . esc_attr( $accent ) . ';border-radius:4px;">'
			. sprintf(
				/* translators: %d: number of days */
				esc_html__( 'The community has %d days to vote. We will let you know the outcome by email.', 'agnosis' ),
				absint( $window )
			)
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
	}

	/**
	 * @param object{id: int, email: string, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null, language: string|null} $application
	 */
	private function send_vote_email(
		int $voter_id,
		string $voter_email,
		string $voter_name,
		object $application,
		int $window
	): void {
		// Switch to this voter's WP locale so translated email strings are correct.
		$voter_locale = (string) get_user_meta( $voter_id, 'locale', true );
		if ( '' !== $voter_locale ) {
			switch_to_locale( $voter_locale );
		}

		$yes_url = self::vote_url( $voter_id, (int) $application->id, 'yes' );
		$no_url  = self::vote_url( $voter_id, (int) $application->id, 'no' );

		wp_mail(
			$voter_email,
			sprintf(
				/* translators: 1: applicant name, 2: community name */
				__( 'New application: %1$s wants to join %2$s', 'agnosis' ),
				$application->display_name,
				get_bloginfo( 'name' )
			),
			$this->build_vote_email_body( $application, $voter_name, $voter_id, $yes_url, $no_url, $window ),
			$this->html_headers()
		);

		if ( '' !== $voter_locale ) {
			restore_current_locale();
		}
	}

	/**
	 * Build the HTML "new application — please vote" email sent to every
	 * current artist (and the admin fallback when there are no artists yet).
	 *
	 * Uses the shared Core\EmailTemplate shell (2026-07-15 — audit-adjacent
	 * finding, not a numbered audit item: every remaining plain-text/
	 * hand-rolled-HTML email in the plugin was converted to this one shared
	 * template in the same pass, see CHANGELOG.md 0.9.29). Same content as
	 * the original version (bio/portfolio/statement, Yes/No links,
	 * work-email footer, edit reminder), just composed through the shared
	 * shell instead of hand-rolling its own DOCTYPE/table/colour constants.
	 *
	 * @param object{id: int, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null} $application
	 * @param int    $voter_id   WP user ID of the recipient — gates EmailFooter::edit_reminder_html()
	 *                           the same way it gated edit_reminder_plain_text() before.
	 */
	private function build_vote_email_body( object $application, string $voter_name, int $voter_id, string $yes_url, string $no_url, int $window ): string {
		$site_name = get_bloginfo( 'name' );
		$accent    = EmailTemplate::accent();

		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $voter_name )
			)
			. '</p>'
			. '<p style="margin:0 0 24px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: 1: applicant display name, 2: community name, 3: number of days */
				esc_html__( '%1$s has applied to join %2$s. You have %3$d days to vote.', 'agnosis' ),
				esc_html( $application->display_name ),
				esc_html( $site_name ),
				absint( $window )
			)
			. '</p>';

		if ( $application->bio ) {
			$body .= '<p style="margin:0 0 6px;font-size:15px;font-weight:700;color:#333;">' . esc_html__( 'Bio', 'agnosis' ) . '</p>'
				. '<p style="margin:0 0 20px;font-size:17px;line-height:1.6;color:#555;padding:14px 16px;background:#f9f9f9;border-left:3px solid ' . esc_attr( $accent ) . ';border-radius:4px;">' . esc_html( $application->bio ) . '</p>';
		}

		if ( $application->portfolio_url ) {
			$body .= '<p style="margin:0 0 20px;font-size:17px;color:#555;"><strong>' . esc_html__( 'Portfolio:', 'agnosis' ) . '</strong> '
				. '<a href="' . esc_url( $application->portfolio_url ) . '" style="color:' . esc_attr( $accent ) . ';">' . esc_html( $application->portfolio_url ) . '</a></p>';
		}

		if ( $application->statement ) {
			$body .= '<p style="margin:0 0 6px;font-size:15px;font-weight:700;color:#333;">' . esc_html__( 'Statement', 'agnosis' ) . '</p>'
				. '<p style="margin:0 0 28px;font-size:17px;line-height:1.6;color:#555;padding:14px 16px;background:#f9f9f9;border-left:3px solid ' . esc_attr( $accent ) . ';border-radius:4px;">' . esc_html( $application->statement ) . '</p>';
		}

		$body .= '<table cellpadding="0" cellspacing="0" style="margin:0 0 16px;"><tr><td>'
			. EmailTemplate::button( $yes_url, '✓ ' . __( 'Vote YES', 'agnosis' ), [ 'margin' => '6px 8px 6px 0' ] )
			. EmailTemplate::button( $no_url, '✕ ' . __( 'Vote NO', 'agnosis' ), [
				'bg'     => '#fff',
				'color'  => EmailTemplate::DANGER,
				'border' => EmailTemplate::DANGER,
				'margin' => '6px 8px 6px 0',
			] )
			. '</td></tr></table>'
			. '<p style="margin:0;font-size:15px;color:#999;">' . esc_html__( 'You can change your vote at any time within the voting window.', 'agnosis' ) . '</p>';

		$footer_extra = '';

		$work_emails_html = EmailFooter::html();
		if ( '' !== $work_emails_html ) {
			$footer_extra .= '<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid #eee;">' . $work_emails_html . '</div>';
		}

		// $voter_id may be the fallback admin account (see on_application_received()
		// above) rather than an artist — edit_reminder_html() simply returns '' for
		// a non-artist with nothing published, so no extra guard is needed here.
		$edit_reminder_html = EmailFooter::edit_reminder_html( $voter_id );
		if ( '' !== $edit_reminder_html ) {
			$footer_extra .= '<p style="margin:12px 0 0;font-size:15px;color:#888;text-align:center;">' . $edit_reminder_html . '</p>';
		}

		// Security audit §5b/§4a: lets a chronic vote-blast recipient switch to a
		// daily digest instead of the spam button. Same non-artist guard as
		// edit_reminder_html() above.
		$prefs_html = EmailFooter::preferences_html( $voter_id );
		if ( '' !== $prefs_html ) {
			$footer_extra .= '<p style="margin:12px 0 0;text-align:center;">' . $prefs_html . '</p>';
		}

		return EmailTemplate::render( $this->html_lang(), $body, $footer_extra );
	}

	/**
	 * @param object{id: int, email: string, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null} $application
	 * @param int $artist_count   Artists emailed the vote request immediately.
	 * @param int $deferred_count Digest-mode artists who will instead see this
	 *                            application in their next Artist\VoteDigest run.
	 */
	private function send_admin_summary( object $application, int $artist_count, int $deferred_count, int $window ): void {
		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: 1: site name, 2: applicant display name */
				__( '[%1$s] New application received: %2$s', 'agnosis' ),
				get_bloginfo( 'name' ),
				$application->display_name
			),
			$this->build_admin_summary_body( $application, $artist_count, $deferred_count, $window ),
			$this->html_headers()
		);
	}

	/**
	 * HTML body for send_admin_summary() above — archive/oversight only, no vote links.
	 *
	 * @param object{id: int, email: string, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null} $application
	 */
	private function build_admin_summary_body( object $application, int $artist_count, int $deferred_count, int $window ): string {
		$body = '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#555;">'
			. sprintf( /* translators: %d: application ID */ esc_html__( 'Application ID: %d', 'agnosis' ), (int) $application->id ) . '<br>'
			. sprintf( /* translators: %s: applicant email address */ esc_html__( 'Email: %s', 'agnosis' ), esc_html( $application->email ) ) . '<br>'
			. sprintf( /* translators: %s: applicant display name */ esc_html__( 'Display name: %s', 'agnosis' ), esc_html( $application->display_name ) )
			. '</p>'
			. '<p style="margin:0;font-size:16px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %d: number of artists notified immediately */
				esc_html__( 'Notified %d artist(s) immediately.', 'agnosis' ),
				absint( $artist_count )
			);

		if ( $deferred_count > 0 ) {
			$body .= ' ' . sprintf(
				/* translators: %d: number of artists deferred to their next daily digest */
				esc_html__( '%d more will see it in their next daily digest.', 'agnosis' ),
				absint( $deferred_count )
			);
		}

		$body .= ' ' . sprintf(
			/* translators: %d: voting window in days */
			esc_html__( 'Voting window: %d day(s).', 'agnosis' ),
			absint( $window )
		)
		. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
	}

	/**
	 * Same reasoning as build_acknowledgment_body() above: this recipient was
	 * never admitted, so the work-submission address footer doesn't apply to
	 * them either — omitted here for the same reason.
	 *
	 * @param object{display_name: string} $application
	 */
	private function build_expiry_applicant_body( object $application ): string {
		$site_name = get_bloginfo( 'name' );

		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $application->display_name )
			)
			. '</p>'
			. '<p style="margin:0 0 20px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %s: community name */
				esc_html__( 'Thank you for applying to %s. Unfortunately, your application did not receive enough votes within the voting window.', 'agnosis' ),
				esc_html( $site_name )
			)
			. '</p>'
			. '<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">'
			. esc_html__( 'You are welcome to apply again in the future.', 'agnosis' )
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
	}

	/**
	 * @param object{display_name: string} $application
	 */
	private function build_expiry_community_body( object $application ): string {
		$body = '<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %s: applicant display name */
				esc_html__( 'The application by %s has closed without reaching the admission threshold. No action required.', 'agnosis' ),
				esc_html( $application->display_name )
			)
			. '</p>';

		$work_emails_html = EmailFooter::html();
		$footer_extra     = '' !== $work_emails_html
			? '<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid #eee;">' . $work_emails_html . '</div>'
			: '';

		return EmailTemplate::render( $this->html_lang(), $body, $footer_extra );
	}

	/**
	 * Build the HTML welcome email sent once the community admits a new artist.
	 *
	 * Deliberately does NOT push credentials or a password-reset action as the
	 * headline of this email: working with Agnosis (submitting, editing,
	 * removing work) is entirely email-based and needs no WP account at all —
	 * see the alias addresses below. A login only unlocks optional extras
	 * (e.g. previewing a submission before it publishes at /my-submissions/),
	 * so it's mentioned last, as a low-key opt-in note pointing at WordPress's
	 * own self-service "Forgot your password?" flow — not a pre-generated,
	 * single-use reset link baked into the email. Keeping this last also keeps
	 * the email's main message simple: no login needed.
	 */
	private function build_welcome_body( \WP_User $user, string $lostpassword_url ): string {
		$site_name   = get_bloginfo( 'name' );
		$gallery_url = SubdomainRouter::url_for_artist( $user->ID );
		$my_subs_url = home_url( '/my-submissions/' );
		$accent      = EmailTemplate::accent();

		$aliases         = $this->configured_aliases();
		$goodbye_address = (string) get_option( 'agnosis_email_goodbye', '' );

		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $user->display_name )
			)
			. '</p>'
			. '<p style="margin:0 0 24px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %s: community name */
				esc_html__( 'The %s community has admitted you as an artist. Welcome!', 'agnosis' ),
				esc_html( $site_name )
			)
			. '</p>'
			. '<p style="margin:0 0 6px;font-size:17px;color:#555;">' . esc_html__( 'Your gallery:', 'agnosis' ) . ' '
			. '<a href="' . esc_url( $gallery_url ) . '" style="color:' . esc_attr( $accent ) . ';font-weight:600;">' . esc_html( $gallery_url ) . '</a></p>'
			. '<p style="margin:0 0 28px;font-size:17px;color:#555;">' . esc_html__( 'Your submissions:', 'agnosis' ) . ' '
			. '<a href="' . esc_url( $my_subs_url ) . '" style="color:' . esc_attr( $accent ) . ';font-weight:600;">' . esc_html( $my_subs_url ) . '</a></p>';

		if ( ! empty( $aliases ) ) {
			$rows = '';
			foreach ( $aliases as $label => $address ) {
				$rows .= '<tr><td style="font-size:16px;color:#555;padding:0 0 6px;">' . esc_html( $label ) . ':</td>'
					. '<td style="font-size:16px;padding:0 0 6px;"><a href="mailto:' . esc_attr( $address ) . '" style="color:' . esc_attr( $accent ) . ';">' . esc_html( $address ) . '</a></td></tr>';
			}
			$body .= '<div style="background:#f9f9f9;padding:16px 20px;border-radius:4px;margin:0 0 20px;">'
				. '<p style="margin:0 0 12px;font-size:16px;font-weight:700;color:#333;">' . esc_html__( 'How to share your work — send an email to:', 'agnosis' ) . '</p>'
				. '<table cellpadding="0" cellspacing="0" width="100%">' . $rows . '</table>'
				. '<p style="margin:12px 0 4px;font-size:15px;font-weight:700;color:#333;">' . esc_html__( 'Subject line conventions:', 'agnosis' ) . '</p>'
				. '<p style="margin:0;font-size:15px;color:#666;line-height:1.6;">'
				. esc_html__( 'Artwork (default) — any subject', 'agnosis' ) . '<br>'
				. esc_html__( '[Biography] — biography update', 'agnosis' ) . '<br>'
				. esc_html__( '[Event] — event announcement', 'agnosis' ) . '<br>'
				. esc_html__( '[Photo] — publish as-is, no AI enhancement (fallback for mail apps without To: aliases)', 'agnosis' ) . '<br>'
				. esc_html__( '[Pure] — publish exactly as sent, no AI at all (fallback for mail apps without To: aliases)', 'agnosis' )
				. '</p></div>';
		}

		if ( '' !== $goodbye_address ) {
			$body .= '<div style="background:#fef9f9;padding:16px 20px;border-radius:4px;border:1px solid #fad7d7;">'
				. '<p style="margin:0 0 8px;font-size:15px;font-weight:700;color:#b34a4a;">' . esc_html__( 'To leave the network and delete your account:', 'agnosis' ) . '</p>'
				. '<p style="margin:0 0 6px;font-size:15px;"><a href="mailto:' . esc_attr( $goodbye_address ) . '" style="color:#b34a4a;">' . esc_html( $goodbye_address ) . '</a></p>'
				. '<p style="margin:0;font-size:15px;color:#999;line-height:1.5;">' . esc_html__( 'Send any email (no attachment needed). You will receive a confirmation link — nothing is deleted until you click it.', 'agnosis' ) . '</p>'
				. '</div>';
		}

		// No login needed — password recovery is opt-in, mentioned last on purpose.
		$body .= '<p style="margin:24px 0 0;font-size:15px;color:#999;line-height:1.6;">'
			. esc_html__( "No login is needed to work with Agnosis — everything above happens by email. If you'd also like to use the site's optional online features (like previewing a submission before it publishes), you can set up a password whenever you like using", 'agnosis' ) . ' '
			. '<a href="' . esc_url( $lostpassword_url ) . '" style="color:' . esc_attr( $accent ) . ';">' . esc_html__( 'password recovery', 'agnosis' ) . '</a>.'
			. '</p>';

		// Security audit §5b/§4a: a brand-new artist is about to start receiving
		// broadcast/vote mail — the very first email they'd see this link in.
		$prefs_html   = EmailFooter::preferences_html( $user->ID );
		$footer_extra = '' !== $prefs_html ? '<p style="margin:12px 0 0;text-align:center;">' . $prefs_html . '</p>' : '';

		return EmailTemplate::render( $this->html_lang(), $body, $footer_extra );
	}

	/**
	 * Every configured work-submission alias, label => address, for the
	 * welcome email's "how to share your work" section. Unconfigured aliases
	 * (empty option value) are silently omitted so the email stays clean even
	 * during partial setups. Unlike the acknowledgment email above, these
	 * addresses ARE legitimate here — this recipient has just been admitted
	 * as an artist and can actually use them.
	 *
	 * @return array<string, string>
	 */
	private function configured_aliases(): array {
		/** @var array<string, string> $content_aliases Option key → translatable label */
		$content_aliases = [
			'agnosis_email_submit'  => __( 'Submit artwork', 'agnosis' ),
			'agnosis_email_bio'     => __( 'Submit biography', 'agnosis' ),
			'agnosis_email_event'   => __( 'Submit event', 'agnosis' ),
			'agnosis_email_replace' => __( 'Replace artwork (subject: exact title)', 'agnosis' ),
			'agnosis_email_remove'  => __( 'Remove artwork (subject: exact title)', 'agnosis' ),
			'agnosis_email_promote' => __( 'Feature artwork in the shared gallery (subject: exact title)', 'agnosis' ),
			'agnosis_email_photo'   => __( 'Photo-only — publish as-is, no AI enhancement', 'agnosis' ),
			'agnosis_email_pure'    => __( 'Pure — publish exactly as sent, no AI at all', 'agnosis' ),
		];

		$aliases = [];
		foreach ( $content_aliases as $option => $label ) {
			$address = (string) get_option( $option, '' );
			if ( '' !== $address ) {
				$aliases[ $label ] = $address;
			}
		}
		return $aliases;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Headers for every email in this class.
	 *
	 * Every email this class sends is now HTML, built through the shared
	 * Core\EmailTemplate shell (2026-07-15) — the plain-text `text_headers()`
	 * variant this method used to sit alongside is gone along with the last
	 * plain-text body (`build_expiry_applicant_body()`/
	 * `build_expiry_community_body()`/`build_admin_summary_body()`, all
	 * converted in the same pass).
	 *
	 * Delegates to Core\CommunityMailer — the shared workflow/transactional
	 * sender identity (Settings → Email → "Mail from:"). Previously borrowed
	 * Newsletter\Mailer::sender_header(), the wrong identity: an admission
	 * acknowledgment or welcome email isn't digest mail (2026-07-08).
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

	/**
	 * @param  string[] $emails
	 * @return string[]
	 */
	private function bcc_headers( array $emails ): array {
		return array_map( fn( string $e ) => "Bcc: {$e}", $emails );
	}

	private function is_artist( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user && in_array( 'agnosis_artist', (array) $user->roles, true );
	}

	/**
	 * Return the best admin user ID for the vote-email fallback.
	 *
	 * @param array<\WP_User|\stdClass> $artists
	 */
	private function get_admin_user_id( array $artists ): int {
		// If there are no artists yet, fall back to the first administrator.
		$admins = get_users( [
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => [ 'ID' ],
		] );

		return ! empty( $admins ) ? (int) $admins[0]->ID : 0;
	}
}
