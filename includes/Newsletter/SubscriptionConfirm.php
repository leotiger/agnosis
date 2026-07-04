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
 * A plain GET (the link as it arrives in the email) only renders a confirm
 * page with a single POST button — it does not confirm or unsubscribe
 * anything. Corporate mail-security scanners prefetch links in incoming email
 * to scan them, and a prefetch alone must never silently confirm or
 * unsubscribe a recipient (security audit §2a). The action is only taken once
 * the POST arrives, with the token carried in a hidden form field rather than
 * the form's action URL.
 *
 * RFC 8058 one-click unsubscribe (security audit §2b): every send also carries
 * a `List-Unsubscribe-Post: List-Unsubscribe=One-Click` header alongside
 * `List-Unsubscribe`, so a compliant mail client may POST straight to the
 * unsubscribe URL above with a body of literally `List-Unsubscribe=One-Click`
 * — no page shown, no further click. That request's action/type/token/uid
 * still travel in the URL's query string (as written into the header), not
 * the POST body, so this shim reads $_GET for that one specific case even
 * though the request method is POST. This is safe to act on immediately,
 * unlike a bare GET: mail-security scanners prefetch GETs, never issue POSTs.
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
		$is_post = $this->is_post_request();

		// RFC 8058 one-click unsubscribe (§2b) — a mail client's automated POST
		// to the List-Unsubscribe header URL, identified by the literal body
		// `List-Unsubscribe=One-Click`. Acts immediately, with no confirm page:
		// the identifying params still come from the query string since that's
		// where the mail client's request puts them (it doesn't know about our
		// hidden form fields).
		if ( $is_post && $this->is_one_click_post() ) {
			$this->handle_one_click_unsubscribe();
			return;
		}

		// No WP nonce here — this is an unauthenticated email-link recipient with
		// no WP session; the single-use token (or artist HMAC) plays the nonce's
		// role instead.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$source = $is_post ? $_POST : $_GET;

		if ( empty( $source['agnosis_newsletter'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $source['action'] ?? '' ) );
		$type   = sanitize_key( wp_unslash( $source['type']   ?? '' ) );
		$token  = sanitize_text_field( wp_unslash( $source['token'] ?? '' ) );
		$uid    = absint( wp_unslash( $source['uid'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, [ 'confirm', 'unsubscribe' ], true ) || ! in_array( $type, [ 'artist', 'public' ], true ) || '' === $token ) {
			$this->render_error( __( 'Invalid or incomplete link.', 'agnosis' ) );
			return;
		}

		// Artists are auto-enrolled — only unsubscribe is meaningful.
		if ( 'artist' === $type && 'confirm' === $action ) {
			$this->render_error( __( 'Artists are automatically subscribed — there is nothing to confirm.', 'agnosis' ) );
			return;
		}

		// GET only renders the confirm page — a mail scanner prefetching this
		// URL gets a harmless page, not a confirmed/unsubscribed recipient. The
		// token travels in the confirm form's hidden POST field, never in the
		// form's action URL.
		if ( ! $is_post ) {
			$this->render_confirm( $action, $type, $token, $uid );
			return;
		}

		if ( 'public' === $type ) {
			$this->handle_public( $action, $token );
			return;
		}

		$this->handle_artist_unsubscribe( $uid, $token );
	}

	/** True when the current request is a POST (the confirm button was clicked). */
	private function is_post_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparison only, not used as output.
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
	}

	/**
	 * True for an RFC 8058 one-click unsubscribe POST — identified solely by
	 * its mandated literal body, `List-Unsubscribe=One-Click` (RFC 8058 §3.1).
	 * Never sent by our own confirm-page form, which posts our own field names.
	 */
	private function is_one_click_post(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparison only, not used as output; no nonce for an unauthenticated mail-client request.
		return isset( $_POST['List-Unsubscribe'] ) && 'One-Click' === $_POST['List-Unsubscribe'];
	}

	/**
	 * Act on an RFC 8058 one-click unsubscribe immediately — no confirm page.
	 * type/token/uid come from the query string (the URL the header pointed
	 * at), since the mail client's POST body only ever carries the RFC 8058
	 * marker, not our own field names.
	 */
	private function handle_one_click_unsubscribe(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$type  = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) );
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		$uid   = absint( wp_unslash( $_GET['uid'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $type, [ 'artist', 'public' ], true ) || '' === $token ) {
			$this->render_error( __( 'Invalid or incomplete link.', 'agnosis' ) );
			return;
		}

		if ( 'public' === $type ) {
			$this->handle_public( 'unsubscribe', $token );
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
	// Confirm interstitial (GET) — no state change, single POST button
	// -------------------------------------------------------------------------

	/**
	 * Render a "are you sure" page with a single POST button for a validated
	 * (but not yet actioned) confirm/unsubscribe request. Reached only via
	 * GET — the button's form POSTs action/type/token/uid back as hidden
	 * fields so the token never appears in the form's action URL.
	 */
	private function render_confirm( string $action, string $type, string $token, int $uid ): void {
		if ( 'confirm' === $action ) {
			$title       = __( 'Confirm your subscription?', 'agnosis' );
			$description = __( "You'll start receiving the newsletter once you confirm below.", 'agnosis' );
			$button      = __( 'Confirm subscription', 'agnosis' );
		} else {
			$title       = __( 'Unsubscribe from the newsletter?', 'agnosis' );
			$description = __( 'You will stop receiving the newsletter once you confirm below.', 'agnosis' );
			$button      = __( 'Confirm unsubscribe', 'agnosis' );
		}

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:32px;color:#7c6af7;margin:0 0 16px;">✦</p>'
			. '<h1 style="font-size:22px;font-weight:700;margin:0 0 12px;">%1$s</h1>'
			. '<p style="font-size:16px;color:#555;margin:0 0 32px;">%2$s</p>'
			. '<form method="post" action="%3$s">'
			. '<input type="hidden" name="agnosis_newsletter" value="1">'
			. '<input type="hidden" name="action" value="%4$s">'
			. '<input type="hidden" name="type" value="%5$s">'
			. '<input type="hidden" name="token" value="%6$s">'
			. '<input type="hidden" name="uid" value="%7$s">'
			. '<button type="submit" style="background:#7c6af7;color:#fff;border:0;border-radius:6px;padding:12px 28px;font-size:15px;font-family:inherit;cursor:pointer;">%8$s</button>'
			. '</form>'
			. '<p style="margin:24px 0 0;"><a href="%9$s" style="color:#999;font-size:14px;text-decoration:none;">&larr; %10$s</a></p>'
			. '</div>',
			esc_html( $title ),
			esc_html( $description ),
			esc_url( home_url( '/' ) ),
			esc_attr( $action ),
			esc_attr( $type ),
			esc_attr( $token ),
			esc_attr( (string) $uid ),
			esc_html( $button ),
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);

		wp_die( $html, esc_html( $title ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is fully escaped above.
	}

	// -------------------------------------------------------------------------
	// Result pages (POST)
	// -------------------------------------------------------------------------

	private function render_success( string $title, string $message ): void {
		$this->render_page( $title, $message, false );
	}

	private function render_error( string $message ): void {
		$this->render_page( __( 'Link error', 'agnosis' ), $message, true );
	}

	private function render_page( string $title, string $message, bool $is_error ): void {
		$status = $is_error ? 400 : 200;
		$icon   = $is_error ? '✕' : '✦';
		$color  = $is_error ? '#c0392b' : '#7c6af7';

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:32px;color:%1$s;margin:0 0 16px;">%2$s</p>'
			. '<h1 style="font-size:22px;font-weight:700;margin:0 0 12px;">%3$s</h1>'
			. '<p style="font-size:16px;color:#555;margin:0 0 32px;">%4$s</p>'
			. '<a href="%5$s" style="color:%1$s;font-size:14px;text-decoration:none;">&larr; %6$s</a>'
			. '</div>',
			esc_attr( $color ),
			esc_html( $icon ),
			esc_html( $title ),
			esc_html( $message ),
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);

		wp_die( $html, esc_html( $title ), [ 'response' => $status ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is fully escaped above.
	}
}
