<?php
/**
 * Daily vote-email digest for artists in "digest" mode (security audit
 * §5b/§4a).
 *
 * AdmissionNotification::on_application_received() sends one vote email per
 * artist the instant an application is confirmed by the community — on an
 * active node (daily applications) this is exactly the kind of volume §5b
 * flags: an artist with no way to reduce it short of a full departure has no
 * realistic exit but the spam button, the single worst outcome for the
 * shared domain's reputation. NotificationPreferences lets an artist switch
 * to `_agnosis_vote_email_mode = 'digest'`; this class is what that mode
 * actually delivers — one email per day per digest-mode artist, listing
 * every still-open application they haven't voted on yet, instead of one
 * email per application as it arrives.
 *
 * Deliberately NOT built as a queue/delta ("what's new since their last
 * digest") — every open application a digest-mode artist hasn't voted on is
 * re-derived fresh on every run instead:
 *   SELECT ... WHERE status = 'pending' AND NOT EXISTS (a vouch by this artist)
 * This is simpler and self-healing (an artist who switches into digest mode
 * mid-window, or whose earlier digest send failed, still sees every
 * outstanding application on the very next run — nothing can be silently
 * dropped) at the cost of one small, accepted edge case: an artist who was in
 * 'instant' mode when an application arrived (and so already got that one
 * individually) but switches to 'digest' before voting will see it once more
 * in their next digest. That's a harmless repeat reminder, not a duplicate
 * side effect — voting itself is idempotent (Admission::record_vote() updates
 * the same row) — so it isn't worth a tracking table to prevent.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\Core\CommunityMailer;
use Agnosis\Core\EmailBranding;
use Agnosis\Core\EmailFooter;

class VoteDigest {

	public function register_hooks(): void {
		add_action( 'agnosis_vote_digest', [ $this, 'send_daily' ] );
	}

	/**
	 * Entry point for the `agnosis_vote_digest` daily cron.
	 */
	public function send_daily(): void {
		$artists = get_users( [
			'role'       => 'agnosis_artist',
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small table (admitted artists only), acceptable.
				[ 'key' => '_agnosis_vote_email_mode', 'value' => 'digest' ],
			],
			'fields'     => [ 'ID', 'user_email', 'display_name' ],
		] );

		foreach ( $artists as $artist ) {
			$this->send_one( (int) $artist->ID, $artist->user_email, $artist->display_name );
		}
	}

	// -------------------------------------------------------------------------

	private function send_one( int $voter_id, string $voter_email, string $voter_name ): void {
		$applications = $this->open_applications_awaiting_vote( $voter_id );
		if ( empty( $applications ) ) {
			// Nothing outstanding for this artist today — skip entirely rather
			// than send an empty "you have 0 applications to vote on" email.
			return;
		}

		$voter_locale = (string) get_user_meta( $voter_id, 'locale', true );
		if ( '' !== $voter_locale ) {
			switch_to_locale( $voter_locale );
		}

		$window = (int) get_option( 'agnosis_admission_window_days', 7 );

		wp_mail(
			$voter_email,
			sprintf(
				/* translators: %d: number of applications awaiting this artist's vote */
				_n(
					'%d application awaiting your vote',
					'%d applications awaiting your vote',
					count( $applications ),
					'agnosis'
				),
				count( $applications )
			),
			$this->build_body( $applications, $voter_name, $voter_id, $window ),
			CommunityMailer::html_headers()
		);

		if ( '' !== $voter_locale ) {
			restore_current_locale();
		}
	}

	/**
	 * Every application still open to a vote that $voter_id has not yet cast
	 * (or has since revoked) a vote on — see this class's own docblock for why
	 * this is re-derived fresh rather than tracked as a delta.
	 *
	 * @return array<int, object{id: int, email: string, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null, applied_at: string}>
	 */
	private function open_applications_awaiting_vote( int $voter_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.* FROM {$wpdb->prefix}agnosis_applications a
				 WHERE a.status = 'pending'
				 AND NOT EXISTS (
					 SELECT 1 FROM {$wpdb->prefix}agnosis_application_vouches v
					 WHERE v.application_id = a.id AND v.voucher_id = %d AND v.revoked_at IS NULL
				 )
				 ORDER BY a.applied_at ASC",
				$voter_id
			)
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @param array<int, object{id: int, display_name: string, bio: string|null, portfolio_url: string|null, statement: string|null, applied_at: string}> $applications
	 */
	private function build_body( array $applications, string $voter_name, int $voter_id, int $window ): string {
		$site_name = get_bloginfo( 'name' );
		$header_bg = '#0d0d12';
		$accent    = '#7c6af7';
		$reject    = '#c0392b';
		$btn_base  = 'display:inline-block;padding:10px 20px;border-radius:6px;font-size:15px;font-weight:600;text-decoration:none;margin:4px 6px 4px 0;';

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
		<p style="margin:0 0 28px;font-size:18px;line-height:1.6;color:#555;">
			<?php
			printf(
				/* translators: 1: community name, 2: number of days */
				esc_html__( "Here's your daily digest of applications to %1\$s still awaiting your vote. Each has a %2\$d-day voting window from when it was confirmed.", 'agnosis' ),
				esc_html( $site_name ),
				absint( $window )
			);
			?>
		</p>

		<?php foreach ( $applications as $application ) : ?>
		<div style="margin:0 0 28px;padding:20px;background:#f9f9f9;border-radius:8px;">
			<p style="margin:0 0 12px;font-size:18px;font-weight:700;color:#222;"><?php echo esc_html( $application->display_name ); ?></p>

			<?php if ( ! empty( $application->bio ) ) : ?>
			<p style="margin:0 0 12px;font-size:16px;line-height:1.6;color:#555;"><?php echo esc_html( $application->bio ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $application->portfolio_url ) ) : ?>
			<p style="margin:0 0 12px;font-size:15px;color:#555;">
				<strong><?php esc_html_e( 'Portfolio:', 'agnosis' ); ?></strong>
				<a href="<?php echo esc_url( $application->portfolio_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php echo esc_html( $application->portfolio_url ); ?></a>
			</p>
			<?php endif; ?>

			<?php
			$yes_url = AdmissionNotification::vote_url( $voter_id, (int) $application->id, 'yes' );
			$no_url  = AdmissionNotification::vote_url( $voter_id, (int) $application->id, 'no' );
			?>
			<table cellpadding="0" cellspacing="0"><tr><td>
				<a href="<?php echo esc_url( $yes_url ); ?>" style="<?php echo esc_attr( $btn_base ); ?>background:<?php echo esc_attr( $accent ); ?>;color:#fff;">
					✓ <?php esc_html_e( 'Vote YES', 'agnosis' ); ?>
				</a>
				<a href="<?php echo esc_url( $no_url ); ?>" style="<?php echo esc_attr( $btn_base ); ?>background:#fff;color:<?php echo esc_attr( $reject ); ?>;border:1px solid <?php echo esc_attr( $reject ); ?>;">
					✕ <?php esc_html_e( 'Vote NO', 'agnosis' ); ?>
				</a>
			</td></tr></table>
		</div>
		<?php endforeach; ?>

		<p style="margin:0;font-size:15px;color:#999;">
			<?php esc_html_e( 'Prefer these one at a time again? You can switch back any time from your notification preferences link below.', 'agnosis' ); ?>
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
		<?php $prefs_html = EmailFooter::preferences_html( $voter_id ); ?>
		<?php if ( '' !== $prefs_html ) : ?>
		<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid #eee;text-align:center;">
			<?php echo $prefs_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::preferences_html() escapes internally. ?>
		</div>
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
	 * Same reasoning as AdmissionNotification::html_lang() — must run after
	 * switch_to_locale() so get_locale() reflects the recipient, not the site.
	 */
	private function html_lang(): string {
		$locale = get_locale();
		return $locale ? str_replace( '_', '-', $locale ) : 'en';
	}
}
