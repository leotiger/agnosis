<?php
/**
 * "Preview" test-send card (Branding tab) — rendering plus its own
 * admin-post handler for sending a representative branded-email sample.
 *
 * Split out of Admin\Settings (2026-07-17, AUDIT-1.0.0.md §4d — the "god
 * class" finding): this render method and its one handler were already a
 * self-contained cluster, so this is a pure move — same behavior, same hook
 * name (`admin_post_agnosis_send_branding_test`, rewired in Core\Plugin to
 * this class instead of Settings).
 *
 * @package Agnosis\Admin\Dashboards
 */

declare(strict_types=1);

namespace Agnosis\Admin\Dashboards;

use Agnosis\Core\CommunityMailer;
use Agnosis\Core\EmailBranding;
use Agnosis\Core\EmailFooter;
use Agnosis\Core\EmailTemplate;

class BrandingTestForm {

	/**
	 * "Send test email" card on Settings → Branding (audit AUDIT-0.9.29.md
	 * §2d, 💡): the color/logo/width fields save blind — an operator's first
	 * real look at how they combine is whatever email happens to go out
	 * next, which could be days away and isn't guaranteed to exercise every
	 * field (e.g. the footer card only appears on artist-facing mail). This
	 * renders one representative `EmailTemplate::render()` body — the same
	 * shell every real email uses, with a sample header subtitle, body
	 * paragraph, button, and a "work addresses" footer card so all of
	 * Branding's fields are exercised at once — and sends it to an address
	 * the operator chooses, same one-click pattern as the newsletter/
	 * invitation/deliverability test-send buttons already on other tabs.
	 */
	public function render(): void {
		$current_user = wp_get_current_user();
		?>
		<div class="card" style="max-width:800px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Preview', 'agnosis' ); ?></h2>
			<p class="description" style="margin-top:0">
				<?php esc_html_e( 'These fields save without any preview — the first real look at how your colors, width, and logo combine is otherwise whatever email happens to go out next. Send yourself a sample using the settings currently saved above (save first if you just changed something and want the test to match).', 'agnosis' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:.4rem;align-items:center">
				<input type="hidden" name="action" value="agnosis_send_branding_test">
				<?php wp_nonce_field( 'agnosis_send_branding_test' ); ?>
				<input type="email" name="test_email" value="<?php echo esc_attr( $current_user->user_email ); ?>" required class="small-text" style="width:14rem">
				<?php submit_button( __( 'Send Test', 'agnosis' ), 'secondary small', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * admin-post handler: send a one-off sample of the branded email shell
	 * to a single address, exercising header/body/button/footer together
	 * (AUDIT-0.9.29.md §2d). Diagnostic only — does not touch any
	 * subscriber, queue, or setting; uses whatever is currently *saved* in
	 * Settings → Branding, since this handler runs after the settings form
	 * (if any) has already submitted and persisted.
	 */
	public function handle_send_branding_test(): void {
		check_admin_referer( 'agnosis_send_branding_test' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$test_email = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
		$result     = false;

		if ( is_email( $test_email ) ) {
			$accent            = EmailTemplate::accent();
			$header_extra_html = '<div style="font-size:15px;color:' . esc_attr( EmailBranding::header_subtitle_color() ) . ';margin-top:4px;">' . esc_html__( 'Branding preview', 'agnosis' ) . '</div>';

			ob_start();
			?>
			<p style="margin:0 0 20px;font-size:18px;line-height:1.6;">
				<?php esc_html_e( 'This is a sample of the branded email shell — header, body text, a button, and (below) the footer card — built from whatever is currently saved on Settings → Branding.', 'agnosis' ); ?>
			</p>
			<table cellpadding="0" cellspacing="0" style="margin-bottom:20px;"><tr><td>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=agnosis-settings&tab=branding' ) ); ?>" style="display:inline-block;padding:12px 24px;border-radius:6px;font-size:17px;font-weight:600;text-decoration:none;background:<?php echo esc_attr( $accent ); ?>;color:<?php echo esc_attr( EmailTemplate::button_text_color() ); ?>;">
					<?php esc_html_e( 'Sample button', 'agnosis' ); ?>
				</a>
			</td></tr></table>
			<p style="margin:0;font-size:15px;color:#999;">
				<?php esc_html_e( "Didn't look right? Adjust the fields above and send another test — nothing here was recorded or sent to anyone but you.", 'agnosis' ); ?>
			</p>
			<?php
			$body_html = (string) ob_get_clean();

			ob_start();
			$work_emails_html = EmailFooter::html();
			if ( '' !== $work_emails_html ) :
				?>
			<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid <?php echo esc_attr( EmailTemplate::border_color() ); ?>;">
				<?php echo $work_emails_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::html() escapes each label/address itself. ?>
			</div>
				<?php
			endif;
			$footer_extra_html = (string) ob_get_clean();

			$body = EmailTemplate::render( str_replace( '_', '-', get_locale() ), $body_html, $footer_extra_html, $header_extra_html );

			$result = wp_mail(
				$test_email,
				sprintf(
					/* translators: %s: site name */
					__( '[TEST] Branding preview from %s', 'agnosis' ),
					get_bloginfo( 'name' )
				),
				$body,
				CommunityMailer::html_headers()
			);
		}

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'branding',
				'agnosis_message' => $result ? 'branding_test_sent' : 'branding_test_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
