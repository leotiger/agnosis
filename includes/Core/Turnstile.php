<?php
/**
 * Cloudflare Turnstile — shared human-verification helper for public forms.
 *
 * Used by the newsletter signup (agnosis/newsletter-signup) and artist
 * application (agnosis/join) blocks — both are unauthenticated, POST to open
 * REST endpoints, and are the obvious targets for spam/bot abuse that rate
 * limiting alone doesn't stop (a slow-and-steady bot stays under the limit).
 *
 * Opt-in by configuration, not a toggle: until both a site key and a secret
 * key are set in Settings → General, is_enabled() is false and neither form
 * renders a widget, loads Cloudflare's script, or requires a token — existing
 * installs are unaffected until an admin adds keys.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

use WP_Error;

class Turnstile {

	private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	private const WIDGET_SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	private const SCRIPT_HANDLE = 'agnosis-cloudflare-turnstile';

	/** True once both keys are configured — the only thing that turns this feature on. */
	public static function is_enabled(): bool {
		return '' !== self::site_key() && '' !== self::secret_key();
	}

	public static function site_key(): string {
		return Secrets::turnstile_site_key();
	}

	private static function secret_key(): string {
		return Secrets::turnstile_secret_key();
	}

	/**
	 * Enqueue Cloudflare's widget script. No-op when not enabled, and safe to
	 * call from both forms' enqueue_assets() on the same page.
	 *
	 * 'strategy' => 'defer' (native WP script-loading strategy, available
	 * since 6.3) runs the script after the DOM is parsed — by then the
	 * `.cf-turnstile` container is already in the markup for Turnstile's
	 * implicit-rendering scan to find, so there's no need for a custom
	 * script_loader_tag filter to add async/defer by hand.
	 */
	public static function enqueue_script(): void {
		if ( ! self::is_enabled() || wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			self::WIDGET_SCRIPT_URL,
			[],
			AGNOSIS_VERSION,
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);
	}

	/**
	 * Widget markup for a form. Empty string when not enabled, so callers can
	 * always echo the result unconditionally without an extra if().
	 *
	 * Cloudflare's script auto-adds a hidden `cf-turnstile-response` input to
	 * the nearest enclosing <form> once it renders — the frontend JS for each
	 * block reads that field's value and sends it along as `turnstile_token`.
	 */
	public static function render_widget(): string {
		if ( ! self::is_enabled() ) {
			return '';
		}

		return sprintf(
			'<div class="cf-turnstile agnosis-turnstile" data-sitekey="%s"></div>',
			esc_attr( self::site_key() )
		);
	}

	/**
	 * Verify a widget response token against Cloudflare's siteverify endpoint.
	 *
	 * Called first thing from each form's REST callback (Subscription::subscribe(),
	 * Admission::apply()), before any DB writes — always returns true when
	 * Turnstile isn't configured.
	 *
	 * @param string $token Value of the `cf-turnstile-response` field submitted by the form.
	 * @return true|WP_Error
	 */
	public static function verify( string $token ): bool|WP_Error {
		if ( ! self::is_enabled() ) {
			return true;
		}

		if ( '' === trim( $token ) ) {
			return new WP_Error(
				'agnosis_turnstile_missing',
				__( 'Please complete the verification check and try again.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		$remote_ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through to a trusted outbound API call only, never stored or output.
			$remote_ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}

		$response = wp_remote_post( self::SITEVERIFY_URL, [
			'timeout' => 10,
			'body'    => [
				'secret'   => self::secret_key(),
				'response' => $token,
				'remoteip' => $remote_ip,
			],
		] );

		if ( is_wp_error( $response ) ) {
			Logger::warning( 'Turnstile siteverify request failed: ' . $response->get_error_message(), 'turnstile' );
			return new WP_Error(
				'agnosis_turnstile_unreachable',
				__( "Could not verify you're human right now. Please try again in a moment.", 'agnosis' ),
				[ 'status' => 503 ]
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['success'] ) ) {
			$codes = isset( $body['error-codes'] ) ? implode( ', ', (array) $body['error-codes'] ) : 'unknown';
			Logger::warning( 'Turnstile verification failed: ' . $codes, 'turnstile' );
			return new WP_Error(
				'agnosis_turnstile_failed',
				__( 'Verification failed. Please try again.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}
}
