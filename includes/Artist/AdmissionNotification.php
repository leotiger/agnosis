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
use Agnosis\Core\EmailBranding;
use Agnosis\Core\EmailFooter;
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
			$this->text_headers()
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
			array_merge( $this->text_headers(), $this->bcc_headers( $to ) )
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
		$header_bg = '#0d0d12'; // matches the theme's dark header/background colour on the live site.
		$accent    = '#7c6af7';
		$btn_base  = 'display:inline-block;padding:12px 24px;border-radius:6px;font-size:17px;font-weight:600;text-decoration:none;margin:6px 4px;';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $this->html_lang() ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center" style="background:#f5f5f5;">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<!-- Header -->
	<tr><td style="background:<?php echo esc_attr( $header_bg ); ?>;padding:28px 24px;">
		<?php echo EmailBranding::header_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally. ?>
	</td></tr>

	<!-- Body -->
	<tr><td style="background:#ffffff;padding:36px 24px;">
		<p style="margin:0 0 20px;font-size:18px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $display_name )
			);
			?>
		</p>
		<p style="margin:0 0 28px;font-size:18px;line-height:1.6;color:#555;">
			<?php
			printf(
				/* translators: %s: community name */
				esc_html__( 'One last step before %s can review your application: confirm this is really your email address.', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>

		<table cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
		<tr><td>
			<a href="<?php echo esc_url( $confirm_url ); ?>" style="<?php echo esc_attr( $btn_base ); ?>background:<?php echo esc_attr( $accent ); ?>;color:#fff;">
				<?php esc_html_e( 'Confirm my application', 'agnosis' ); ?>
			</a>
		</td></tr>
		</table>

		<p style="margin:0;font-size:15px;color:#999;">
			<?php esc_html_e( "If you didn't apply, simply ignore this email — nothing happens until this link is clicked.", 'agnosis' ); ?>
		</p>
	</td></tr>

	<!-- Footer -->
	<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:14px;color:#bbb;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
	</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
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
		$header_bg = '#0d0d12'; // matches the theme's dark header/background colour on the live site.

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $this->html_lang() ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center" style="background:#f5f5f5;">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<!-- Header -->
	<tr><td style="background:<?php echo esc_attr( $header_bg ); ?>;padding:28px 24px;">
		<?php echo EmailBranding::header_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally. ?>
	</td></tr>

	<!-- Body -->
	<tr><td style="background:#ffffff;padding:36px 24px;">
		<p style="margin:0 0 20px;font-size:18px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $application->display_name )
			);
			?>
		</p>
		<p style="margin:0 0 20px;font-size:18px;line-height:1.6;color:#555;">
			<?php
			printf(
				/* translators: %s: community name */
				esc_html__( 'Thank you for applying to %s. Your application has been received and is now open for community review.', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>

		<p style="margin:0;font-size:17px;line-height:1.6;color:#666;padding:16px 20px;background:#f9f9f9;border-left:3px solid #7c6af7;border-radius:4px;">
			<?php
			printf(
				/* translators: %d: number of days */
				esc_html__( 'The community has %d days to vote. We will let you know the outcome by email.', 'agnosis' ),
				absint( $window )
			);
			?>
		</p>
	</td></tr>

	<!-- Footer -->
	<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:14px;color:#bbb;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
	</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
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
	 * 2026-07-10: this was the one remaining plain-text, unstyled email path
	 * in the plugin — every other artist-facing email (acknowledgment,
	 * welcome, and everything in Publishing\Notification) already used the
	 * shared EmailBranding header + styled-button template. Same content as
	 * the old plain-text version (bio/portfolio/statement, Yes/No links,
	 * work-email footer, edit reminder), just in that same visual language.
	 *
	 * @param object{id: int, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null} $application
	 * @param int    $voter_id   WP user ID of the recipient — gates EmailFooter::edit_reminder_html()
	 *                           the same way it gated edit_reminder_plain_text() before.
	 */
	private function build_vote_email_body( object $application, string $voter_name, int $voter_id, string $yes_url, string $no_url, int $window ): string {
		$site_name = get_bloginfo( 'name' );
		$header_bg = '#0d0d12'; // matches the theme's dark header/background colour on the live site.
		$accent    = '#7c6af7';
		$reject    = '#c0392b';
		$btn_base  = 'display:inline-block;padding:12px 24px;border-radius:6px;font-size:17px;font-weight:600;text-decoration:none;margin:6px 8px 6px 0;';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $this->html_lang() ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center" style="background:#f5f5f5;">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<!-- Header -->
	<tr><td style="background:<?php echo esc_attr( $header_bg ); ?>;padding:28px 24px;">
		<?php echo EmailBranding::header_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally. ?>
	</td></tr>

	<!-- Body -->
	<tr><td style="background:#ffffff;padding:36px 24px;">
		<p style="margin:0 0 20px;font-size:18px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $voter_name )
			);
			?>
		</p>
		<p style="margin:0 0 24px;font-size:18px;line-height:1.6;color:#555;">
			<?php
			printf(
				/* translators: 1: applicant display name, 2: community name, 3: number of days */
				esc_html__( '%1$s has applied to join %2$s. You have %3$d days to vote.', 'agnosis' ),
				esc_html( $application->display_name ),
				esc_html( $site_name ),
				absint( $window )
			);
			?>
		</p>

		<?php if ( $application->bio ) : ?>
		<p style="margin:0 0 6px;font-size:15px;font-weight:700;color:#333;"><?php esc_html_e( 'Bio', 'agnosis' ); ?></p>
		<p style="margin:0 0 20px;font-size:17px;line-height:1.6;color:#555;padding:14px 16px;background:#f9f9f9;border-left:3px solid <?php echo esc_attr( $accent ); ?>;border-radius:4px;"><?php echo esc_html( $application->bio ); ?></p>
		<?php endif; ?>

		<?php if ( $application->portfolio_url ) : ?>
		<p style="margin:0 0 20px;font-size:17px;color:#555;">
			<strong><?php esc_html_e( 'Portfolio:', 'agnosis' ); ?></strong>
			<a href="<?php echo esc_url( $application->portfolio_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php echo esc_html( $application->portfolio_url ); ?></a>
		</p>
		<?php endif; ?>

		<?php if ( $application->statement ) : ?>
		<p style="margin:0 0 6px;font-size:15px;font-weight:700;color:#333;"><?php esc_html_e( 'Statement', 'agnosis' ); ?></p>
		<p style="margin:0 0 28px;font-size:17px;line-height:1.6;color:#555;padding:14px 16px;background:#f9f9f9;border-left:3px solid <?php echo esc_attr( $accent ); ?>;border-radius:4px;"><?php echo esc_html( $application->statement ); ?></p>
		<?php endif; ?>

		<table cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
		<tr><td>
			<a href="<?php echo esc_url( $yes_url ); ?>" style="<?php echo esc_attr( $btn_base ); ?>background:<?php echo esc_attr( $accent ); ?>;color:#fff;">
				✓ <?php esc_html_e( 'Vote YES', 'agnosis' ); ?>
			</a>
			<a href="<?php echo esc_url( $no_url ); ?>" style="<?php echo esc_attr( $btn_base ); ?>background:#fff;color:<?php echo esc_attr( $reject ); ?>;border:1px solid <?php echo esc_attr( $reject ); ?>;">
				✕ <?php esc_html_e( 'Vote NO', 'agnosis' ); ?>
			</a>
		</td></tr>
		</table>

		<p style="margin:0;font-size:15px;color:#999;">
			<?php esc_html_e( 'You can change your vote at any time within the voting window.', 'agnosis' ); ?>
		</p>
	</td></tr>

	<!-- Footer -->
	<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:14px;color:#bbb;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
		<?php $work_emails_html = EmailFooter::html(); ?>
		<?php if ( '' !== $work_emails_html ) : ?>
		<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid #eee;">
			<?php echo $work_emails_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::html() escapes each label/address itself. ?>
		</div>
		<?php endif; ?>
		<?php // $voter_id may be the fallback admin account (see on_application_received() above) rather than an artist — edit_reminder_html() simply returns '' for a non-artist with nothing published, so no extra guard is needed here. ?>
		<?php $edit_reminder_html = EmailFooter::edit_reminder_html( $voter_id ); ?>
		<?php if ( '' !== $edit_reminder_html ) : ?>
		<p style="margin:12px 0 0;font-size:15px;color:#888;text-align:center;">
			<?php echo $edit_reminder_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::edit_reminder_html() escapes internally. ?>
		</p>
		<?php endif; ?>
		<?php // Security audit §5b/§4a: lets a chronic vote-blast recipient switch to a daily digest instead of the spam button. Same non-artist guard as edit_reminder_html() above. ?>
		<?php $prefs_html = EmailFooter::preferences_html( $voter_id ); ?>
		<?php if ( '' !== $prefs_html ) : ?>
		<p style="margin:12px 0 0;text-align:center;">
			<?php echo $prefs_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::preferences_html() escapes internally. ?>
		</p>
		<?php endif; ?>
	</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param object{id: int, email: string, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null} $application
	 * @param int $artist_count   Artists emailed the vote request immediately.
	 * @param int $deferred_count Digest-mode artists who will instead see this
	 *                            application in their next Artist\VoteDigest run.
	 */
	private function send_admin_summary( object $application, int $artist_count, int $deferred_count, int $window ): void {
		$deferred_line = $deferred_count > 0
			? sprintf( ' %d more will see it in their next daily digest.', $deferred_count )
			: '';

		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				'[%s] New application received: %s',
				get_bloginfo( 'name' ),
				$application->display_name
			),
			sprintf(
				"Application ID: %d\nEmail: %s\nDisplay name: %s\n\nNotified %d artist(s) immediately.%s Voting window: %d day(s).",
				$application->id,
				$application->email,
				$application->display_name,
				$artist_count,
				$deferred_line,
				$window
			),
			$this->text_headers()
		);
	}

	/**
	 * Same reasoning as build_acknowledgment_body() above: this recipient was
	 * never admitted, so the work-submission address footer doesn't apply to
	 * them either — omitted here for the same reason.
	 *
	 * @param object{display_name: string} $application
	 */
	private function build_expiry_applicant_body( object $application ): string {
		return implode( "\n", [
			/* translators: %s: recipient's display name */
			sprintf( __( 'Hi %s,', 'agnosis' ), $application->display_name ),
			'',
			sprintf(
				/* translators: %s: community name */
				__( 'Thank you for applying to %s. Unfortunately, your application did not receive enough votes within the voting window.', 'agnosis' ),
				get_bloginfo( 'name' )
			),
			'',
			__( 'You are welcome to apply again in the future.', 'agnosis' ),
			'',
			get_bloginfo( 'name' ),
		] );
	}

	/**
	 * @param object{display_name: string} $application
	 */
	private function build_expiry_community_body( object $application ): string {
		return implode( "\n", array_merge( [
			sprintf(
				/* translators: %s: applicant display name */
				__( 'The application by %s has closed without reaching the admission threshold. No action required.', 'agnosis' ),
				$application->display_name
			),
			'',
			get_bloginfo( 'name' ),
		], $this->footer_lines() ) );
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
		$accent      = '#7c6af7';
		$header_bg   = '#0d0d12'; // matches the theme's dark header/background colour on the live site.

		$aliases         = $this->configured_aliases();
		$goodbye_address = (string) get_option( 'agnosis_email_goodbye', '' );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $this->html_lang() ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center" style="background:#f5f5f5;">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<!-- Header -->
	<tr><td style="background:<?php echo esc_attr( $header_bg ); ?>;padding:28px 24px;">
		<?php echo EmailBranding::header_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally. ?>
	</td></tr>

	<!-- Body -->
	<tr><td style="background:#ffffff;padding:36px 24px;">
		<p style="margin:0 0 20px;font-size:18px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $user->display_name )
			);
			?>
		</p>
		<p style="margin:0 0 24px;font-size:18px;line-height:1.6;color:#555;">
			<?php
			printf(
				/* translators: %s: community name */
				esc_html__( 'The %s community has admitted you as an artist. Welcome!', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>

		<p style="margin:0 0 6px;font-size:17px;color:#555;">
			<?php esc_html_e( 'Your gallery:', 'agnosis' ); ?>
			<a href="<?php echo esc_url( $gallery_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;font-weight:600;"><?php echo esc_html( $gallery_url ); ?></a>
		</p>
		<p style="margin:0 0 28px;font-size:17px;color:#555;">
			<?php esc_html_e( 'Your submissions:', 'agnosis' ); ?>
			<a href="<?php echo esc_url( $my_subs_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;font-weight:600;"><?php echo esc_html( $my_subs_url ); ?></a>
		</p>

		<?php if ( ! empty( $aliases ) ) : ?>
		<!-- How to share work -->
		<div style="background:#f9f9f9;padding:16px 20px;border-radius:4px;margin:0 0 20px;">
			<p style="margin:0 0 12px;font-size:16px;font-weight:700;color:#333;"><?php esc_html_e( 'How to share your work — send an email to:', 'agnosis' ); ?></p>
			<table cellpadding="0" cellspacing="0" width="100%">
				<?php foreach ( $aliases as $label => $address ) : ?>
				<tr>
					<td style="font-size:16px;color:#555;padding:0 0 6px;"><?php echo esc_html( $label ); ?>:</td>
					<td style="font-size:16px;padding:0 0 6px;"><a href="mailto:<?php echo esc_attr( $address ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php echo esc_html( $address ); ?></a></td>
				</tr>
				<?php endforeach; ?>
			</table>
			<p style="margin:12px 0 4px;font-size:15px;font-weight:700;color:#333;"><?php esc_html_e( 'Subject line conventions:', 'agnosis' ); ?></p>
			<p style="margin:0;font-size:15px;color:#666;line-height:1.6;">
				<?php esc_html_e( 'Artwork (default) — any subject', 'agnosis' ); ?><br>
				<?php esc_html_e( '[Biography] — biography update', 'agnosis' ); ?><br>
				<?php esc_html_e( '[Event] — event announcement', 'agnosis' ); ?><br>
				<?php esc_html_e( '[Photo] — publish as-is, no AI enhancement (fallback for mail apps without To: aliases)', 'agnosis' ); ?><br>
				<?php esc_html_e( '[Pure] — publish exactly as sent, no AI at all (fallback for mail apps without To: aliases)', 'agnosis' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<?php if ( '' !== $goodbye_address ) : ?>
		<!-- Leave the network -->
		<div style="background:#fef9f9;padding:16px 20px;border-radius:4px;border:1px solid #fad7d7;">
			<p style="margin:0 0 8px;font-size:15px;font-weight:700;color:#b34a4a;"><?php esc_html_e( 'To leave the network and delete your account:', 'agnosis' ); ?></p>
			<p style="margin:0 0 6px;font-size:15px;"><a href="mailto:<?php echo esc_attr( $goodbye_address ); ?>" style="color:#b34a4a;"><?php echo esc_html( $goodbye_address ); ?></a></p>
			<p style="margin:0;font-size:15px;color:#999;line-height:1.5;">
				<?php esc_html_e( 'Send any email (no attachment needed). You will receive a confirmation link — nothing is deleted until you click it.', 'agnosis' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<!-- No login needed — password recovery is opt-in, mentioned last on purpose -->
		<p style="margin:24px 0 0;font-size:15px;color:#999;line-height:1.6;">
			<?php esc_html_e( "No login is needed to work with Agnosis — everything above happens by email. If you'd also like to use the site's optional online features (like previewing a submission before it publishes), you can set up a password whenever you like using", 'agnosis' ); ?>
			<a href="<?php echo esc_url( $lostpassword_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'password recovery', 'agnosis' ); ?></a>.
		</p>
	</td></tr>

	<!-- Footer -->
	<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:14px;color:#bbb;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
		<?php // Security audit §5b/§4a: a brand-new artist is about to start receiving broadcast/vote mail — the very first email they'd see this link in. ?>
		<?php $prefs_html = EmailFooter::preferences_html( $user->ID ); ?>
		<?php if ( '' !== $prefs_html ) : ?>
		<p style="margin:12px 0 0;text-align:center;">
			<?php echo $prefs_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::preferences_html() escapes internally. ?>
		</p>
		<?php endif; ?>
	</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
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
	 * Plain-text headers for the vote email, admin summary, and expiry
	 * notices below.
	 *
	 * Previously carried no From header at all, so wp_mail() fell through to
	 * WordPress's own PHPMailer default — "WordPress <wordpress@$domain>", an
	 * address that doesn't exist and has no outbound mail configured, which is
	 * exactly why a vouch-vote email could arrive looking like it came from
	 * "WordPress" rather than the community (found 2026-07-08). Now delegates
	 * to Core\CommunityMailer, the same workflow sender identity html_headers()
	 * uses below.
	 *
	 * @return array<string>
	 */
	private function text_headers(): array {
		return CommunityMailer::text_headers();
	}

	/**
	 * Headers for the two HTML emails above (acknowledgment + welcome).
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
	 * Blank-line-prefixed work-emails footer for the plain-text bodies above.
	 *
	 * Returns an empty array when nothing is configured in Settings → Email,
	 * so those bodies render exactly as they did before this existed rather
	 * than gaining a stray trailing blank line. Not used by build_acknowledgment_body()
	 * or build_expiry_applicant_body() — the recipient there was never admitted,
	 * so these addresses don't apply to them. Not used by build_welcome_body()
	 * either — that email already lists every configured alias address in full,
	 * labelled, right in the body (see configured_aliases()), so appending the
	 * compact one-liner again immediately after would just repeat it. Not
	 * used by send_admin_summary() either, since that email is admin-only.
	 *
	 * @return string[]
	 */
	private function footer_lines(): array {
		$line = EmailFooter::plain_text();
		return '' !== $line ? [ '', $line ] : [];
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
