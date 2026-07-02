<?php
/**
 * Public newsletter subscription — REST endpoint + confirmation email.
 *
 * Unauthenticated visitors sign up via the agnosis/newsletter-signup block,
 * which POSTs to /agnosis/v1/newsletter/subscribe. A double opt-in
 * confirmation email is always sent before a subscriber is added to any send
 * list — nobody starts receiving mail just by typing an address into a form.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

use Agnosis\Core\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Subscription {

	public function register_routes(): void {
		register_rest_route( 'agnosis/v1', '/newsletter/subscribe', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'subscribe' ],
			'permission_callback' => [ $this, 'rate_limit' ],
			'args'                => [
				'email'    => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => fn( string $v ): bool => (bool) is_email( $v ),
				],
				'language' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );
	}

	public function rate_limit(): bool|WP_Error {
		return RateLimiter::check( 'newsletter_subscribe', 5, 300 );
	}

	public function subscribe( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email    = (string) $request->get_param( 'email' );
		$language = (string) ( $request->get_param( 'language' ) ?? '' );
		$locale   = $language ? \Agnosis\Artist\Admission::iso_to_wp_locale( $language ) : '';

		$result = Subscriber::subscribe( $email, $locale );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->send_confirmation_email( $email, (string) $result['token'], $locale );

		return new WP_REST_Response( [ 'status' => 'pending_confirmation' ], 201 );
	}

	// -------------------------------------------------------------------------
	// Confirmation email
	// -------------------------------------------------------------------------

	private function send_confirmation_email( string $email, string $token, string $locale ): void {
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		$confirm_url = add_query_arg(
			[
				'agnosis_newsletter' => '1',
				'action'             => 'confirm',
				'type'               => 'public',
				'token'              => $token,
			],
			home_url( '/' )
		);

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '[%s] Confirm your newsletter subscription', 'agnosis' ),
			$site_name
		);

		$accent   = '#7c6af7';
		$btn_base = 'display:inline-block;padding:12px 24px;border-radius:6px;font-size:15px;font-weight:600;text-decoration:none;margin:6px 4px;';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<tr><td style="background:<?php echo esc_attr( $accent ); ?>;padding:28px 40px;">
		<span style="font-size:22px;font-weight:700;color:#fff;letter-spacing:.02em;">✦ <?php echo esc_html( $site_name ); ?></span>
	</td></tr>

	<tr><td style="padding:36px 40px;">
		<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#555;">
			<?php esc_html_e( 'Thanks for signing up! Confirm your email address to start receiving the newsletter.', 'agnosis' ); ?>
		</p>

		<table cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
		<tr><td>
			<a href="<?php echo esc_url( $confirm_url ); ?>" style="<?php echo esc_attr( $btn_base ); ?>background:<?php echo esc_attr( $accent ); ?>;color:#fff;">
				<?php esc_html_e( 'Confirm subscription', 'agnosis' ); ?>
			</a>
		</td></tr>
		</table>

		<p style="font-size:13px;color:#999;margin:0;">
			<?php esc_html_e( "If you didn't request this, simply ignore this email — you will not be subscribed.", 'agnosis' ); ?>
		</p>
	</td></tr>

	<tr><td style="padding:20px 40px;border-top:1px solid #eee;">
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
		$body = (string) ob_get_clean();

		wp_mail( $email, $subject, $body, [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . Mailer::sender_header(),
		] );

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}
}
