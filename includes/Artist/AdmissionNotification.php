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
use Agnosis\Core\EmailBranding;
use Agnosis\Core\EmailFooter;
use Agnosis\Network\SubdomainRouter;
use Agnosis\Newsletter\Mailer;

class AdmissionNotification {

	public function register_hooks(): void {
		add_action( 'agnosis_artist_applied',     [ $this, 'on_application_received' ], 10, 3 );
		add_action( 'agnosis_application_expired', [ $this, 'on_application_expired' ], 10, 1 );
		add_action( 'agnosis_artist_admitted',     [ $this, 'on_artist_admitted' ],     10, 2 );
	}

	// -------------------------------------------------------------------------
	// Action callbacks
	// -------------------------------------------------------------------------

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

		// Collect all current artists.
		$artists = get_users( [
			'role'   => 'agnosis_artist',
			'fields' => [ 'ID', 'user_email', 'display_name' ],
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
		$this->send_admin_summary( $application, count( $artists ), $window );
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
		<p style="margin:0 0 20px;font-size:16px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $application->display_name )
			);
			?>
		</p>
		<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#555;">
			<?php
			printf(
				/* translators: %s: community name */
				esc_html__( 'Thank you for applying to %s. Your application has been received and is now open for community review.', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>

		<p style="margin:0;font-size:15px;line-height:1.6;color:#666;padding:16px 20px;background:#f9f9f9;border-left:3px solid #7c6af7;border-radius:4px;">
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
		<p style="margin:0;font-size:12px;color:#bbb;text-align:center;">
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

		$lines = [];
		/* translators: %s: recipient's display name */
		$lines[] = sprintf( __( 'Hi %s,', 'agnosis' ), $voter_name );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: 1: applicant display name, 2: community name, 3: number of days */
			__( '%1$s has applied to join %2$s. You have %3$d days to vote.', 'agnosis' ),
			$application->display_name,
			get_bloginfo( 'name' ),
			$window
		);
		$lines[] = '';

		if ( $application->bio ) {
			$lines[] = __( 'Bio:', 'agnosis' );
			$lines[] = $application->bio;
			$lines[] = '';
		}
		if ( $application->portfolio_url ) {
			/* translators: %s: portfolio URL */
			$lines[] = sprintf( __( 'Portfolio: %s', 'agnosis' ), $application->portfolio_url );
			$lines[] = '';
		}
		if ( $application->statement ) {
			$lines[] = __( 'Statement:', 'agnosis' );
			$lines[] = $application->statement;
			$lines[] = '';
		}

		/* translators: %s: vote URL */
		$lines[] = sprintf( __( 'Vote YES: %s', 'agnosis' ), $yes_url );
		/* translators: %s: vote URL */
		$lines[] = sprintf( __( 'Vote NO:  %s', 'agnosis' ), $no_url );
		$lines[] = '';
		$lines[] = __( 'You can change your vote at any time within the voting window.', 'agnosis' );
		$lines   = array_merge( $lines, $this->footer_lines() );

		// $voter_id may be the fallback admin account (see on_application_received()
		// above) rather than an artist — edit_reminder_plain_text() simply returns
		// '' for a non-artist with nothing published, so no extra guard is needed here.
		$edit_reminder = EmailFooter::edit_reminder_plain_text( $voter_id );
		if ( '' !== $edit_reminder ) {
			$lines[] = '';
			$lines[] = $edit_reminder;
		}

		wp_mail(
			$voter_email,
			sprintf(
				/* translators: 1: applicant name, 2: community name */
				__( 'New application: %1$s wants to join %2$s', 'agnosis' ),
				$application->display_name,
				get_bloginfo( 'name' )
			),
			implode( "\n", $lines ),
			$this->text_headers()
		);

		if ( '' !== $voter_locale ) {
			restore_current_locale();
		}
	}

	/**
	 * @param object{id: int, email: string, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null} $application
	 */
	private function send_admin_summary( object $application, int $artist_count, int $window ): void {
		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				'[%s] New application received: %s',
				get_bloginfo( 'name' ),
				$application->display_name
			),
			sprintf(
				"Application ID: %d\nEmail: %s\nDisplay name: %s\n\nNotified %d artist(s). Voting window: %d day(s).",
				$application->id,
				$application->email,
				$application->display_name,
				$artist_count,
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
		<p style="margin:0 0 20px;font-size:16px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $user->display_name )
			);
			?>
		</p>
		<p style="margin:0 0 24px;font-size:16px;line-height:1.6;color:#555;">
			<?php
			printf(
				/* translators: %s: community name */
				esc_html__( 'The %s community has admitted you as an artist. Welcome!', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>

		<p style="margin:0 0 6px;font-size:15px;color:#555;">
			<?php esc_html_e( 'Your gallery:', 'agnosis' ); ?>
			<a href="<?php echo esc_url( $gallery_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;font-weight:600;"><?php echo esc_html( $gallery_url ); ?></a>
		</p>
		<p style="margin:0 0 28px;font-size:15px;color:#555;">
			<?php esc_html_e( 'Your submissions:', 'agnosis' ); ?>
			<a href="<?php echo esc_url( $my_subs_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;font-weight:600;"><?php echo esc_html( $my_subs_url ); ?></a>
		</p>

		<?php if ( ! empty( $aliases ) ) : ?>
		<!-- How to share work -->
		<div style="background:#f9f9f9;padding:16px 20px;border-radius:4px;margin:0 0 20px;">
			<p style="margin:0 0 12px;font-size:14px;font-weight:700;color:#333;"><?php esc_html_e( 'How to share your work — send an email to:', 'agnosis' ); ?></p>
			<table cellpadding="0" cellspacing="0" width="100%">
				<?php foreach ( $aliases as $label => $address ) : ?>
				<tr>
					<td style="font-size:14px;color:#555;padding:0 0 6px;"><?php echo esc_html( $label ); ?>:</td>
					<td style="font-size:14px;padding:0 0 6px;"><a href="mailto:<?php echo esc_attr( $address ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php echo esc_html( $address ); ?></a></td>
				</tr>
				<?php endforeach; ?>
			</table>
			<p style="margin:12px 0 4px;font-size:13px;font-weight:700;color:#333;"><?php esc_html_e( 'Subject line conventions:', 'agnosis' ); ?></p>
			<p style="margin:0;font-size:13px;color:#666;line-height:1.6;">
				<?php esc_html_e( 'Artwork (default) — any subject', 'agnosis' ); ?><br>
				<?php esc_html_e( '[Biography] — biography update', 'agnosis' ); ?><br>
				<?php esc_html_e( '[Event] — event announcement', 'agnosis' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<?php if ( '' !== $goodbye_address ) : ?>
		<!-- Leave the network -->
		<div style="background:#fef9f9;padding:16px 20px;border-radius:4px;border:1px solid #fad7d7;">
			<p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#b34a4a;"><?php esc_html_e( 'To leave the network and delete your account:', 'agnosis' ); ?></p>
			<p style="margin:0 0 6px;font-size:13px;"><a href="mailto:<?php echo esc_attr( $goodbye_address ); ?>" style="color:#b34a4a;"><?php echo esc_html( $goodbye_address ); ?></a></p>
			<p style="margin:0;font-size:13px;color:#999;line-height:1.5;">
				<?php esc_html_e( 'Send any email (no attachment needed). You will receive a confirmation link — nothing is deleted until you click it.', 'agnosis' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<!-- No login needed — password recovery is opt-in, mentioned last on purpose -->
		<p style="margin:24px 0 0;font-size:13px;color:#999;line-height:1.6;">
			<?php esc_html_e( "No login is needed to work with Agnosis — everything above happens by email. If you'd also like to use the site's optional online features (like previewing a submission before it publishes), you can set up a password whenever you like using", 'agnosis' ); ?>
			<a href="<?php echo esc_url( $lostpassword_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'password recovery', 'agnosis' ); ?></a>.
		</p>
	</td></tr>

	<!-- Footer -->
	<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:12px;color:#bbb;text-align:center;">
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
			'agnosis_email_promote' => __( 'Feature artwork (subject: exact title)', 'agnosis' ),
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

	/** @return array<string> */
	private function text_headers(): array {
		return [ 'Content-Type: text/plain; charset=UTF-8' ];
	}

	/**
	 * Headers for the two HTML emails above (acknowledgment + welcome).
	 * Mirrors Publishing\Notification's own HTML headers; delegates the From
	 * header to Newsletter\Mailer::sender_header() (a general-purpose "site
	 * name <admin_email>, or the configured override" builder despite its
	 * namespace — the same helper Artist\Invitation already reuses) rather
	 * than duplicating that logic a third time in this class.
	 *
	 * @return array<string>
	 */
	private function html_headers(): array {
		return [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . Mailer::sender_header(),
		];
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
