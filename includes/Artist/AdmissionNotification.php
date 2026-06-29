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
use Agnosis\Network\SubdomainRouter;

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
	 * Welcome the newly admitted artist with a password-reset link.
	 *
	 * @param int $user_id        WP user ID of the newly created artist.
	 * @param int $application_id Row ID in agnosis_applications.
	 */
	public function on_artist_admitted( int $user_id, int $application_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$reset_key  = get_password_reset_key( $user );
		$reset_url  = is_wp_error( $reset_key )
			? wp_login_url()
			: network_site_url( "wp-login.php?action=rp&key={$reset_key}&login=" . rawurlencode( $user->user_login ) );

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
			$this->build_welcome_body( $user, $reset_url ),
			$this->text_headers()
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
			$this->text_headers()
		);

		if ( '' !== $applicant_locale ) {
			restore_current_locale();
		}
	}

	/**
	 * @param object{display_name: string} $application
	 */
	private function build_acknowledgment_body( object $application, int $window ): string {
		return implode( "\n", [
			sprintf(
				/* translators: %s: applicant display name */
				__( 'Hi %s,', 'agnosis' ),
				$application->display_name
			),
			'',
			sprintf(
				/* translators: %s: community name */
				__( 'Thank you for applying to %s. Your application has been received and is now open for community review.', 'agnosis' ),
				get_bloginfo( 'name' )
			),
			'',
			sprintf(
				/* translators: %d: number of days */
				__( 'The community has %d days to vote. We will let you know the outcome by email.', 'agnosis' ),
				$window
			),
			'',
			get_bloginfo( 'name' ),
		] );
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
		/* translators: %s: voter display name */
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
	 * @param object{display_name: string} $application
	 */
	private function build_expiry_applicant_body( object $application ): string {
		return implode( "\n", [
			/* translators: %s: applicant display name */
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
		return implode( "\n", [
			sprintf(
				/* translators: %s: applicant display name */
				__( 'The application by %s has closed without reaching the admission threshold. No action required.', 'agnosis' ),
				$application->display_name
			),
			'',
			get_bloginfo( 'name' ),
		] );
	}

	private function build_welcome_body( \WP_User $user, string $reset_url ): string {
		$site_name   = get_bloginfo( 'name' );
		$gallery_url = SubdomainRouter::url_for_artist( $user->ID );
		$my_subs_url = home_url( '/my-submissions/' );

		$lines = [
			/* translators: %s: artist display name */
			sprintf( __( 'Hi %s,', 'agnosis' ), $user->display_name ),
			'',
			sprintf(
				/* translators: %s: community name */
				__( 'The %s community has admitted you as an artist. Welcome!', 'agnosis' ),
				$site_name
			),
			'',
			__( 'Set your password and log in here:', 'agnosis' ),
			$reset_url,
			'',
			/* translators: %s: URL of the artist's personal gallery */
			sprintf( __( 'Your gallery: %s', 'agnosis' ), $gallery_url ),
			/* translators: %s: URL of the submissions dashboard */
			sprintf( __( 'Your submissions: %s', 'agnosis' ), $my_subs_url ),
		];

		[ $content_alias_lines, $goodbye_line ] = $this->alias_lines();
		if ( ! empty( $content_alias_lines ) ) {
			$lines[] = '';
			$lines[] = __( 'How to share your work — send an email to:', 'agnosis' );
			$lines[] = '';
			foreach ( $content_alias_lines as $line ) {
				$lines[] = $line;
			}
			$lines[] = '';
			$lines[] = __( 'Subject line conventions:', 'agnosis' );
			$lines[] = '  ' . __( 'Artwork (default) — any subject', 'agnosis' );
			$lines[] = '  ' . __( '[Biography]       — biography update', 'agnosis' );
			$lines[] = '  ' . __( '[Event]           — event announcement', 'agnosis' );
		}
		if ( '' !== $goodbye_line ) {
			$lines[] = '';
			$lines[] = __( 'To leave the network and delete your account:', 'agnosis' );
			$lines[] = $goodbye_line;
			$lines[] = '  ' . __( 'Send any email (no attachment needed). You will receive a', 'agnosis' );
			$lines[] = '  ' . __( 'confirmation link — nothing is deleted until you click it.', 'agnosis' );
		}

		$lines[] = '';
		$lines[] = $site_name;

		return implode( "\n", $lines );
	}

	/**
	 * Build the alias-email reference lines for the welcome email.
	 *
	 * Each configured alias is included with a short localised description.
	 * Unconfigured aliases (empty option value) are silently omitted so the
	 * email stays clean even during partial setups.
	 *
	 * @return array{0: string[], 1: string} [ content_alias_lines, goodbye_line ]
	 */
	private function alias_lines(): array {
		/** @var array<string, string> $content_aliases Option key → translatable label */
		$content_aliases = [
			'agnosis_email_submit'  => __( 'Submit artwork', 'agnosis' ),
			'agnosis_email_bio'     => __( 'Submit biography', 'agnosis' ),
			'agnosis_email_event'   => __( 'Submit event', 'agnosis' ),
			'agnosis_email_replace' => __( 'Replace artwork (subject: exact title)', 'agnosis' ),
			'agnosis_email_remove'  => __( 'Remove artwork (subject: exact title)', 'agnosis' ),
			'agnosis_email_promote' => __( 'Feature artwork (subject: exact title)', 'agnosis' ),
		];

		$lines = [];
		foreach ( $content_aliases as $option => $label ) {
			$address = (string) get_option( $option, '' );
			if ( '' === $address ) {
				continue;
			}
			// Pad label to 42 chars so addresses left-align cleanly in a monospace client.
			$lines[] = sprintf( '  %-42s %s', $label . ':', $address );
		}

		$goodbye_address = (string) get_option( 'agnosis_email_goodbye', '' );
		$goodbye_line    = '' !== $goodbye_address
			? sprintf( '  %s', $goodbye_address )
			: '';

		return [ $lines, $goodbye_line ];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @return array<string> */
	private function text_headers(): array {
		return [ 'Content-Type: text/plain; charset=UTF-8' ];
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
