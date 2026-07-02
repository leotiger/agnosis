<?php
/**
 * Frontend shim for newsletter email links (confirm + unsubscribe).
 *
 * URL shapes (all query args, no login required):
 *
 *   Public confirm:      ?agnosis_newsletter=1&action=confirm&type=public&token=<token>
 *   Public unsubscribe:  ?agnosis_newsletter=1&action=unsubscribe&type=public&token=<token>
 *   Artist unsubscribe:  ?agnosis_newsletter=1&action=unsubscribe&type=artist&uid=<user_id>&token=<hmac>
 *
 * "confirm" only applies to the public list (double opt-in) — artists are
 * auto-enrolled, so there is nothing to confirm, only to opt out of.
 *
 * Follows the same minimal, theme-free confirmation page pattern as
 * VouchConfirm / ReviewConfirm — fast to render and independent of whatever
 * theme is active.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

class SubscriptionConfirm {

	public function register_hooks(): void {
		add_action( 'template_redirect', [ $this, 'handle' ] );
	}

	// -------------------------------------------------------------------------

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['agnosis_newsletter'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );
		$type   = sanitize_key( wp_unslash( $_GET['type']   ?? '' ) );
		$token  = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		$uid    = absint( wp_unslash( $_GET['uid'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, [ 'confirm', 'unsubscribe' ], true ) || ! in_array( $type, [ 'artist', 'public' ], true ) || '' === $token ) {
			$this->render_error( __( 'Invalid or incomplete link.', 'agnosis' ) );
			return;
		}

		if ( 'public' === $type ) {
			$this->handle_public( $action, $token );
			return;
		}

		// Artists are auto-enrolled — only unsubscribe is meaningful.
		if ( 'confirm' === $action ) {
			$this->render_error( __( 'Artists are automatically subscribed — there is nothing to confirm.', 'agnosis' ) );
			return;
		}

		$this->handle_artist_unsubscribe( $uid, $token );
	}

	// -------------------------------------------------------------------------

	private function handle_public( string $action, string $token ): void {
		if ( 'confirm' === $action ) {
			if ( Subscriber::confirm( $token ) ) {
				$this->render_success( __( 'Subscription confirmed', 'agnosis' ), __( "You're all set — the newsletter will land in your inbox from now on.", 'agnosis' ) );
			} else {
				$this->render_error( __( 'This confirmation link is invalid or has already been used.', 'agnosis' ) );
			}
			return;
		}

		if ( Subscriber::unsubscribe( $token ) ) {
			$this->render_success( __( 'Unsubscribed', 'agnosis' ), __( 'You will no longer receive the newsletter. Sorry to see you go.', 'agnosis' ) );
		} else {
			$this->render_error( __( 'This unsubscribe link is invalid or has already been used.', 'agnosis' ) );
		}
	}

	private function handle_artist_unsubscribe( int $uid, string $token ): void {
		if ( ! $uid || ! Tokens::verify_artist_unsubscribe_token( $uid, $token ) ) {
			$this->render_error( __( 'This unsubscribe link is invalid or has been tampered with.', 'agnosis' ) );
			return;
		}

		$user = get_userdata( $uid );
		if ( ! $user ) {
			$this->render_error( __( 'Account not found.', 'agnosis' ) );
			return;
		}

		update_user_meta( $uid, '_agnosis_newsletter_optout', '1' );

		$this->render_success(
			__( 'Unsubscribed', 'agnosis' ),
			__( 'You will no longer receive the artist newsletter. You can re-subscribe any time from your submissions page.', 'agnosis' )
		);
	}

	// -------------------------------------------------------------------------
	// Minimal page rendering (no theme dependency)
	// -------------------------------------------------------------------------

	private function render_success( string $title, string $message ): void {
		$this->render_page( $title, '<p>' . esc_html( $message ) . '</p>', false );
	}

	private function render_error( string $message ): void {
		$this->render_page( __( 'Link error', 'agnosis' ), '<p>' . esc_html( $message ) . '</p>', true );
	}

	private function render_page( string $title, string $body_html, bool $is_error ): void {
		status_header( $is_error ? 400 : 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );

		echo '<!DOCTYPE html><html lang="en"><head>';
		echo '<meta charset="UTF-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		printf(
			'<title>%1$s — %2$s</title>',
			esc_html( $title ),
			esc_html( get_bloginfo( 'name' ) )
		);
		echo '<style>';
		echo 'body{font-family:sans-serif;max-width:32rem;margin:6rem auto;padding:0 1rem;color:#111;}';
		echo 'h1{font-size:1.25rem;font-weight:600;margin-bottom:1rem;}';
		echo 'p{margin:0 0 .75rem;line-height:1.6;}';
		echo 'a{color:#7c6af7;}';
		echo '</style></head><body>';
		printf( '<h1>%s</h1>', esc_html( $title ) );
		echo wp_kses_post( $body_html );
		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);
		echo '</body></html>';

		exit;
	}
}
