<?php
/**
 * Newsletter email renderer — wraps intro + digest HTML in the branded
 * template shared with the plugin's other outbound mail (see Notification.php).
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

use Agnosis\Core\EmailBranding;

class Mailer {

	/**
	 * Build the full HTML email body for one newsletter recipient.
	 *
	 * @param string $type            'artist' or 'public' — only affects copy.
	 * @param string $intro           Optional admin-written intro paragraph (plain text, one per issue).
	 * @param string $digest_html     Pre-rendered digest content (see Digest::build_*()).
	 * @param string $unsubscribe_url Per-recipient one-click unsubscribe link.
	 */
	public static function build_email( string $type, string $intro, string $digest_html, string $unsubscribe_url ): string {
		$site_name = get_bloginfo( 'name' );
		$header_bg = '#0d0d12'; // matches the theme's dark header/background colour on the live site.

		$heading = 'artist' === $type
			? __( 'Community Newsletter', 'agnosis' )
			: __( 'Newsletter', 'agnosis' );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<tr><td style="background:<?php echo esc_attr( $header_bg ); ?>;padding:28px 24px;">
		<?php echo EmailBranding::header_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally. ?>
		<div style="font-size:13px;color:#ece9ff;margin-top:4px;"><?php echo esc_html( $heading ); ?></div>
	</td></tr>

	<tr><td style="padding:36px 24px;">
		<?php if ( '' !== trim( $intro ) ) : ?>
		<p style="margin:0 0 28px;font-size:16px;line-height:1.7;color:#333;"><?php echo wp_kses_post( wpautop( $intro ) ); ?></p>
		<?php endif; ?>

		<?php echo $digest_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped in Digest::build_*(). ?>
	</td></tr>

	<tr><td style="padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0 0 8px;font-size:12px;color:#bbb;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
		<p style="margin:0;font-size:12px;color:#bbb;text-align:center;">
			<a href="<?php echo esc_url( $unsubscribe_url ); ?>" style="color:#bbb;"><?php esc_html_e( 'Unsubscribe', 'agnosis' ); ?></a>
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
	 * Build the email subject line.
	 */
	public static function build_subject( string $type ): string {
		$site_name = get_bloginfo( 'name' );

		return 'artist' === $type
			/* translators: %s: site name */
			? sprintf( __( '[%s] Community newsletter', 'agnosis' ), $site_name )
			/* translators: %s: site name */
			: sprintf( __( '[%s] Newsletter', 'agnosis' ), $site_name );
	}

	/**
	 * Build the sender header (From: Name <email>).
	 *
	 * Uses the dedicated newsletter sender configured in Settings → Newsletter
	 * when set (agnosis_newsletter_from_name / agnosis_newsletter_from_email) —
	 * keeping digest mail on its own address (e.g. newsletter@agnosis.art)
	 * separate from the site's general admin_email, which matters for
	 * deliverability/reputation and for artists filtering their inbox. Falls
	 * back to the site name and admin_email when either is left blank.
	 */
	public static function sender_header(): string {
		$name  = (string) get_option( 'agnosis_newsletter_from_name', '' );
		$email = (string) get_option( 'agnosis_newsletter_from_email', '' );

		if ( '' === $name ) {
			$name = get_bloginfo( 'name' );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}

		return sprintf( '%s <%s>', $name, $email );
	}
}
