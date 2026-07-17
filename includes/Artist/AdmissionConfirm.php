<?php
/**
 * Email-link confirmation handler for the join application double opt-in
 * (security audit §3a/§4a).
 *
 * Admission::apply() no longer opens an application for community review by
 * itself — it parks the row as 'unverified' and emails only a short "confirm
 * your application" link (see AdmissionNotification::on_application_unverified()).
 * This class hooks into template_redirect, verifies the single-use token, and
 * — on POST only — calls Admission::confirm_application(), which flips the
 * row to 'pending'/'waitlisted' and fires the acknowledgment email + community
 * vote blast for the first time. A plain GET (the link as it arrives in the
 * email) renders a confirm page with a single POST button instead of acting
 * immediately: corporate mail-security scanners prefetch links in incoming
 * email to scan them, and a prefetch alone must never confirm an application
 * (same reasoning as VouchConfirm / SubscriptionConfirm §2a).
 *
 * URL shape:
 *   ?agnosis_admission=1&action=confirm&token=<confirm_token>
 *
 * Follows the same minimal, theme-free confirmation page pattern as
 * VouchConfirm / SubscriptionConfirm — fast to render and independent of
 * whatever theme is active.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

class AdmissionConfirm {

	private Admission $admission;

	public function __construct( Admission $admission ) {
		$this->admission = $admission;
	}

	public function register_hooks(): void {
		add_action( 'template_redirect', [ $this, 'handle' ] );
	}

	// -------------------------------------------------------------------------

	public function handle(): void {
		$is_post = $this->is_post_request();

		// No WP nonce here — this is an unauthenticated email-link recipient
		// with no WP session; the single-use confirm_token plays the nonce's
		// role instead.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$source = $is_post ? $_POST : $_GET;

		if ( empty( $source['agnosis_admission'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $source['action'] ?? '' ) );
		$token  = sanitize_text_field( wp_unslash( $source['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		if ( 'confirm' !== $action || '' === $token ) {
			$this->render_error( __( 'Invalid or incomplete link.', 'agnosis' ) );
			return;
		}

		// GET only renders the confirm page — a mail scanner prefetching this
		// URL gets a harmless page, not a confirmed application. The token
		// travels in the confirm form's hidden POST field, never in the
		// form's action URL.
		if ( ! $is_post ) {
			$this->render_confirm( $token );
			return;
		}

		$result = $this->admission->confirm_application( $token );

		if ( false === $result ) {
			$this->render_error( __( 'This confirmation link is invalid or has already been used.', 'agnosis' ) );
			return;
		}

		$this->render_success( $result['status'], $result['display_name'] );
	}

	/** True when the current request is a POST (the confirm button was clicked). */
	private function is_post_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparison only, not used as output.
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
	}

	// -------------------------------------------------------------------------
	// Confirm interstitial (GET) — no state change, single POST button
	// -------------------------------------------------------------------------

	/**
	 * Render the confirm interstitial for a validated (but not yet actioned)
	 * confirm request, reached only via GET. The confirm button's form POSTs
	 * action/token back as hidden fields so the token never appears in the
	 * form's action URL — that's what keeps a mail-scanner's GET prefetch of
	 * this page from confirming anything (see class docblock).
	 *
	 * The form is auto-submitted via a small inline script on page load so a
	 * human visitor doesn't have to click anything — applicants were landing
	 * here and not realizing a second step was needed. This preserves the
	 * scanner protection above: scanners that prefetch links generally don't
	 * execute JavaScript, so a bare GET still can't trigger the POST. The
	 * button itself stays as a <noscript> fallback for the rare visitor
	 * without JS.
	 */
	private function render_confirm( string $token ): void {
		$title       = __( 'Confirming your application…', 'agnosis' );
		$description = __( "Hang tight — we're opening your application for community review.", 'agnosis' );
		$button      = __( 'Confirm my application', 'agnosis' );

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:34px;color:#7c6af7;margin:0 0 16px;">✦</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%1$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 32px;">%2$s</p>'
			. '<form method="post" action="%3$s" id="agnosis-admission-confirm-form">'
			. '<input type="hidden" name="agnosis_admission" value="1">'
			. '<input type="hidden" name="action" value="confirm">'
			. '<input type="hidden" name="token" value="%4$s">'
			. '<noscript><button type="submit" style="background:#7c6af7;color:#fff;border:0;border-radius:6px;padding:12px 28px;font-size:17px;font-family:inherit;cursor:pointer;">%5$s</button></noscript>'
			. '</form>'
			. '<script>document.getElementById("agnosis-admission-confirm-form").submit();</script>'
			. '<p style="margin:24px 0 0;"><a href="%6$s" style="color:#999;font-size:16px;text-decoration:none;">&larr; %7$s</a></p>'
			. '</div>',
			esc_html( $title ),
			esc_html( $description ),
			esc_url( home_url( '/' ) ),
			esc_attr( $token ),
			esc_html( $button ),
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);

		wp_die( $html, esc_html( $title ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is fully escaped above.
	}

	// -------------------------------------------------------------------------
	// Result pages (POST)
	// -------------------------------------------------------------------------

	/**
	 * @param string $status       'pending' or 'waitlisted' — the row's new status.
	 * @param string $display_name Applicant's display name, for a personal touch.
	 */
	private function render_success( string $status, string $display_name ): void {
		$message = 'waitlisted' === $status
			? __( 'This community is currently full, so your application has joined the waitlist — when a member leaves, the next person in line is welcomed in.', 'agnosis' )
			: __( "You're all set — the community can now review your application and vote.", 'agnosis' );

		$this->render_page(
			__( 'Application confirmed', 'agnosis' ),
			sprintf(
				/* translators: %s: applicant's display name */
				__( 'Thanks, %s!', 'agnosis' ),
				$display_name
			),
			$message,
			false
		);
	}

	private function render_error( string $message ): void {
		$this->render_page( __( 'Link error', 'agnosis' ), $message, '', true );
	}

	private function render_page( string $title, string $line1, string $line2, bool $is_error ): void {
		$status = $is_error ? 400 : 200;
		$icon   = $is_error ? '✕' : '✦';
		$color  = $is_error ? '#c0392b' : '#7c6af7';

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:34px;color:%1$s;margin:0 0 16px;">%2$s</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%3$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 8px;">%4$s</p>'
			. '%5$s'
			. '<p style="margin:32px 0 0;"><a href="%6$s" style="color:%1$s;font-size:16px;text-decoration:none;">&larr; %7$s</a></p>'
			. '</div>',
			esc_attr( $color ),
			esc_html( $icon ),
			esc_html( $title ),
			esc_html( $line1 ),
			'' !== $line2 ? '<p style="font-size:18px;color:#555;margin:0 0 32px;">' . esc_html( $line2 ) . '</p>' : '',
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);

		wp_die( $html, esc_html( $title ), [ 'response' => $status ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is fully escaped above.
	}
}
