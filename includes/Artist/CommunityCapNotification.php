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
 * Every email below is built through the shared Core\EmailTemplate shell
 * (2026-07-15 — audit-adjacent finding, not a numbered audit item: this
 * class was plain text end to end, converted along with
 * Artist\DepartureNotification and Artist\CommunityBroadcast in the same
 * pass; see CHANGELOG.md 0.9.29).
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\Core\CommunityMailer;
use Agnosis\Core\EmailFooter;
use Agnosis\Core\EmailTemplate;

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
			$artist_id = (int) $artist->ID;
			$locale    = (string) get_user_meta( $artist_id, 'locale', true );
			if ( '' !== $locale ) {
				switch_to_locale( $locale );
			}

			$close_formatted = date_i18n( get_option( 'date_format' ), (int) strtotime( $closes_at ) );

			wp_mail(
				$artist->user_email,
				sprintf(
					/* translators: %s: site name */
					__( 'Community size-cap vote open at %s', 'agnosis' ),
					$site_name
				),
				$this->build_vote_opened_body( $artist->display_name, $site_name, $cap_label, $close_formatted, $artist_id ),
				$this->html_headers()
			);

			if ( '' !== $locale ) {
				restore_current_locale();
			}
		}
	}

	private function build_vote_opened_body( string $artist_name, string $site_name, string $cap_label, string $close_formatted, int $artist_id ): string {
		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf( /* translators: %s: recipient's display name */ esc_html__( 'Hi %s,', 'agnosis' ), esc_html( $artist_name ) )
			. '</p>'
			. '<p style="margin:0 0 20px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: 1: site name, 2: proposed cap, 3: closing date */
				esc_html__( 'The %1$s community has opened a vote to change the membership size cap to %2$s. The vote closes on %3$s.', 'agnosis' ),
				esc_html( $site_name ),
				esc_html( $cap_label ),
				esc_html( $close_formatted )
			)
			. '</p>'
			. '<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">'
			. esc_html__( 'A strict majority of active members (more than 50%) must vote yes for the new cap to be adopted. Sign in to your account to cast your vote.', 'agnosis' )
			. '</p>';

		$footer_extra = '';

		$work_emails_html = EmailFooter::html();
		if ( '' !== $work_emails_html ) {
			$footer_extra .= '<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid ' . esc_attr( EmailTemplate::border_color() ) . ';">' . $work_emails_html . '</div>';
		}

		$edit_reminder_html = EmailFooter::edit_reminder_html( $artist_id );
		if ( '' !== $edit_reminder_html ) {
			$footer_extra .= '<p style="margin:12px 0 0;font-size:15px;color:#888;text-align:center;">' . $edit_reminder_html . '</p>';
		}

		return EmailTemplate::render( $this->html_lang(), $body, $footer_extra );
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
			$this->build_vote_passed_body( $cap_label, $proposal_id ),
			$this->html_headers()
		);
	}

	private function build_vote_passed_body( string $cap_label, int $proposal_id ): string {
		$body = '<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: 1: proposed cap, 2: proposal id */
				esc_html__( 'The community voted to change the membership size cap to %1$s (proposal #%2$d). The new cap is now in effect.', 'agnosis' ),
				esc_html( $cap_label ),
				absint( $proposal_id )
			)
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
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
			$this->build_vote_failed_body( $proposal_id ),
			$this->html_headers()
		);
	}

	private function build_vote_failed_body( int $proposal_id ): string {
		$body = '<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: %d: proposal id */
				esc_html__( 'A community vote to change the membership size cap (proposal #%d) closed without a majority. The cap is unchanged.', 'agnosis' ),
				absint( $proposal_id )
			)
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
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
