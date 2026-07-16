<?php
/**
 * Public newsletter subscription — REST endpoint + confirmation email.
 *
 * Unauthenticated visitors sign up via the agnosis/newsletter-signup block,
 * which POSTs to /agnosis/v1/newsletter/subscribe. A double opt-in
 * confirmation email is always sent before a subscriber is added to any send
 * list — nobody starts receiving mail just by typing an address into a form.
 *
 * The endpoint's response is enumeration-safe (security audit §2c): it always
 * returns 201 with the same body, whether the address is new, still pending,
 * or already confirmed — see Subscriber::subscribe()'s `already_confirmed`
 * flag, which only ever changes whether a confirmation email goes out.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

use Agnosis\Core\EmailTemplate;
use Agnosis\Core\RateLimiter;
use Agnosis\Core\Turnstile;
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
				'turnstile_token' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	public function rate_limit(): bool|WP_Error {
		return RateLimiter::check( 'newsletter_subscribe', 5, 300 );
	}

	public function subscribe( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$turnstile = Turnstile::verify( (string) ( $request->get_param( 'turnstile_token' ) ?? '' ) );
		if ( is_wp_error( $turnstile ) ) {
			return $turnstile;
		}

		$email    = (string) $request->get_param( 'email' );
		$language = (string) ( $request->get_param( 'language' ) ?? '' );

		// Same defensive re-check Admission::apply() does for the join form's
		// language select (audit-style hardening, not just trusting whatever
		// survived sanitize_key()): never trust a code that isn't one of the
		// languages the signup form's own <select> actually offered.
		if ( '' !== $language && ! array_key_exists( $language, \Agnosis\AI\SubmissionTranslator::language_names() ) ) {
			$language = '';
		}

		$locale = $language ? \Agnosis\Artist\Admission::iso_to_wp_locale( $language ) : '';

		$result = Subscriber::subscribe( $email, $locale );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Enumeration-safe (security audit §2c): the response is identical
		// (201, same body) whether this address was brand new, still pending,
		// or already confirmed — only whether an email goes out differs, and
		// that's invisible to the caller. Skip re-sending a confirmation to an
		// address that's already confirmed (nothing left to confirm), and skip
		// it too when the address just resubmitted within the resend cooldown
		// (security audit §2d) — an impatient double-click or a bot hammering
		// the form must not get a fresh email every time.
		if ( empty( $result['already_confirmed'] ) && empty( $result['throttled'] ) ) {
			$this->send_confirmation_email( $email, (string) $result['token'], $locale );
		}

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

		$accent   = EmailTemplate::accent();
		$btn_base = 'display:inline-block;padding:12px 24px;border-radius:6px;font-size:17px;font-weight:600;text-decoration:none;margin:6px 4px;';

		ob_start();
		?>
		<p style="margin:0 0 20px;font-size:18px;line-height:1.6;color:#555;">
			<?php esc_html_e( 'Thanks for signing up! Confirm your email address to start receiving the newsletter.', 'agnosis' ); ?>
		</p>

		<table cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
		<tr><td>
			<a href="<?php echo esc_url( $confirm_url ); ?>" style="<?php echo esc_attr( $btn_base ); ?>background:<?php echo esc_attr( $accent ); ?>;color:#fff;">
				<?php esc_html_e( 'Confirm subscription', 'agnosis' ); ?>
			</a>
		</td></tr>
		</table>

		<p style="font-size:15px;color:#999;margin:0;">
			<?php esc_html_e( "If you didn't request this, simply ignore this email — you will not be subscribed.", 'agnosis' ); ?>
		</p>
		<?php
		$body_html = (string) ob_get_clean();

		$body = EmailTemplate::render( str_replace( '_', '-', get_locale() ), $body_html );

		wp_mail( $email, $subject, $body, [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . Mailer::sender_header(),
		] );

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}
}
