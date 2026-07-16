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
use Agnosis\Core\EmailTemplate;

class Mailer {

	/**
	 * Build the full HTML email document (doctype/head/body) for one
	 * newsletter recipient. Thin wrapper around EmailTemplate::render() — see
	 * build_parts() for the actual per-issue content this fills in.
	 */
	public static function build_email( string $type, string $intro, string $digest_html, ?string $unsubscribe_url = null, string $view_online_url = '' ): string {
		[ $body_html, $footer_extra_html, $header_extra_html, $notice_row_html ] = self::build_parts( $type, $intro, $digest_html, $unsubscribe_url, $view_online_url );

		return EmailTemplate::render(
			str_replace( '_', '-', get_locale() ),
			$body_html,
			$footer_extra_html,
			$header_extra_html,
			$notice_row_html
		);
	}

	/**
	 * Build just the branded "card" markup (the two nested `<table>`s) with
	 * no surrounding doctype/head/body — a reusable fragment.
	 *
	 * Split out from build_email() since 2026-07-06 so Newsletter\Archive's
	 * "view in browser" pages can reuse the exact same visual card while
	 * still going through wp_die() for their own document wrapper (title,
	 * language attributes, HTTP status) — the same convention already used
	 * by SubscriptionConfirm/VouchConfirm/ReviewConfirm for every other
	 * render-a-page-and-stop flow in this plugin, chosen specifically so
	 * those flows stay testable via the 'wp_die_handler' filter instead of a
	 * raw echo+exit that would kill the PHP process running the test.
	 *
	 * @param string      $type             'artist' or 'public' — only affects copy.
	 * @param string      $intro            Optional admin-written intro paragraph (plain text, one per issue).
	 * @param string      $digest_html      Pre-rendered digest content (see Digest::build_*()).
	 * @param string|null $unsubscribe_url  Per-recipient one-click unsubscribe link, or null when
	 *                                      there is no recipient to unsubscribe (the public archive
	 *                                      view) — the footer shows a "Subscribe" link instead.
	 * @param string      $view_online_url  Permalink to this issue's archive page (Newsletter\Archive).
	 *                                      Shown as a small "having trouble viewing this?" line only
	 *                                      when non-empty — omitted when rendering the archive page
	 *                                      itself, since a "view online" link makes no sense there.
	 */
	public static function build_body( string $type, string $intro, string $digest_html, ?string $unsubscribe_url = null, string $view_online_url = '' ): string {
		[ $body_html, $footer_extra_html, $header_extra_html, $notice_row_html ] = self::build_parts( $type, $intro, $digest_html, $unsubscribe_url, $view_online_url );

		return EmailTemplate::card( $body_html, $footer_extra_html, $header_extra_html, $notice_row_html );
	}

	/**
	 * Compute the four EmailTemplate::render()/card() slots shared by
	 * build_email() and build_body() — extracted so the doctype-wrapped email
	 * and the doctype-less Archive fragment can never drift out of sync with
	 * each other, since both are now built from this single source.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string} [body_html, footer_extra_html, header_extra_html, notice_row_html]
	 */
	private static function build_parts( string $type, string $intro, string $digest_html, ?string $unsubscribe_url, string $view_online_url ): array {
		$heading = 'artist' === $type
			? __( 'Community Newsletter', 'agnosis' )
			: __( 'Newsletter', 'agnosis' );

		$header_extra_html = '<div style="font-size:15px;color:' . esc_attr( EmailBranding::header_subtitle_color() ) . ';margin-top:4px;">' . esc_html( $heading ) . '</div>';

		$notice_row_html = '';
		if ( '' !== $view_online_url ) {
			$notice_row_html = '<tr><td style="padding:10px 24px;background:' . esc_attr( EmailTemplate::notice_bg() ) . ';border-bottom:1px solid ' . esc_attr( EmailTemplate::border_color() ) . ';">'
				. '<p style="margin:0;font-size:14px;color:' . esc_attr( EmailTemplate::text_secondary_color() ) . ';text-align:center;">'
				. esc_html__( 'Having trouble viewing this email?', 'agnosis' ) . ' '
				. '<a href="' . esc_url( $view_online_url ) . '" style="color:' . esc_attr( EmailTemplate::accent() ) . ';">' . esc_html__( 'View it online.', 'agnosis' ) . '</a>'
				. '</p></td></tr>';
		}

		ob_start();
		if ( '' !== trim( $intro ) ) {
			?>
			<p style="margin:0 0 28px;font-size:18px;line-height:1.7;color:#333;"><?php echo wp_kses_post( wpautop( $intro ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() escapes/strips internally. ?></p>
			<?php
		}
		echo $digest_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped in Digest::build_*().
		$body_html = (string) ob_get_clean();

		ob_start();
		?>
		<p style="margin:8px 0 0;font-size:<?php echo esc_attr( (string) EmailTemplate::text_secondary_size() ); ?>px;color:<?php echo esc_attr( EmailTemplate::text_secondary_color() ); ?>;text-align:center;">
			<?php if ( null !== $unsubscribe_url ) : ?>
				<a href="<?php echo esc_url( $unsubscribe_url ); ?>" style="color:<?php echo esc_attr( EmailTemplate::text_secondary_color() ); ?>;"><?php esc_html_e( 'Unsubscribe', 'agnosis' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:<?php echo esc_attr( EmailTemplate::text_secondary_color() ); ?>;"><?php esc_html_e( 'Subscribe to get these by email', 'agnosis' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
		$footer_extra_html = (string) ob_get_clean();

		return [ $body_html, $footer_extra_html, $header_extra_html, $notice_row_html ];
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
