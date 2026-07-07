<?php
/**
 * Frontend shim for email action links.
 *
 * Artists receive one-click action links in notification emails.  Previously
 * those links pointed directly at REST endpoints:
 *
 *   /wp-json/agnosis/v1/review/{id}/approve?token=<token>
 *
 * Tokens in query strings appear in server access logs, HTTP Referer headers
 * sent to external resources, and browser history.  This class moves those
 * tokens off the REST layer:
 *
 *   /?agnosis_review=1&id={id}&action=approve&token=<token>   (email link, GET)
 *       → renders a confirm page with a single POST button (no action taken yet)
 *       → artist clicks the button → POST /?agnosis_review=1 (token in POST body)
 *       → server processes via rest_do_request() (no logged REST URL)
 *       → 302 → /?agnosis_result=approve                      (clean URL)
 *
 * The GET/POST split exists because corporate mail-security scanners (Outlook
 * SafeLinks, Mimecast, Proofpoint, etc.) prefetch links in incoming email to
 * scan them — issuing a GET, never a POST, and never clicking a button. Before
 * this split, that prefetch alone was enough to approve, reject, or trash
 * artwork, or consume a single-use token so the artist's real click showed
 * "link expired". See docs/security audit §2a.
 *
 * The token still appears only once in a URL — in the initial GET's server log
 * entry. The confirmation POST carries it only in the request body (a hidden
 * form field), never in a query string, so it is never logged a second time,
 * never forwarded to the REST access log, never in browser history after the
 * final redirect, and never in a Referer header (same-origin redirect).
 *
 * Hooks registered in Plugin::register_services() on 'template_redirect' (priority 1).
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

class ReviewConfirm {

	// -------------------------------------------------------------------------
	// Public hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Handle ?agnosis_review=1.
	 *
	 * On GET: renders a confirm page with a single POST button — no state
	 * changes yet. On POST: processes the token action and redirects to a
	 * clean confirmation URL.
	 *
	 * Must run on 'template_redirect' before WP attempts to load a template.
	 */
	public function handle_confirm(): void {
		$is_post = $this->is_post_request();

		// This branches to $_POST once the confirm button is clicked (§2a) —
		// there is no WP nonce here because the request is unauthenticated by
		// design (an email-link recipient with no WP session); the single-use
		// HMAC-style review token plays the nonce's role instead.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$source = $is_post ? $_POST : $_GET;

		if ( empty( $source['agnosis_review'] ) ) {
			return;
		}

		$id     = absint( wp_unslash( $source['id'] ?? 0 ) );
		$action = sanitize_key( wp_unslash( $source['action'] ?? '' ) );
		$token  = sanitize_text_field( wp_unslash( $source['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		$allowed = [ 'approve', 'reject', 'remove' ];

		if ( ! $id || ! $token || ! in_array( $action, $allowed, true ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// GET only renders the confirm page — a mail scanner prefetching this
		// URL gets a harmless page, not a state change. The token travels in
		// the confirm form's hidden POST field, never in the form's action URL.
		if ( ! $is_post ) {
			$this->render_confirm( $id, $action, $token );
			return;
		}

		// Map action to REST path.  Token travels in the POST body via
		// set_param(), so it never appears in a REST access-log URL.
		$path = ( 'remove' === $action )
			? '/agnosis/v1/removal/' . $id . '/confirm'
			: '/agnosis/v1/review/' . $id . '/' . $action;

		$rest_request = new \WP_REST_Request( 'POST', $path );
		$rest_request->set_param( 'token', $token );

		$response = rest_do_request( $rest_request );

		if ( $response->is_error() ) {
			wp_safe_redirect( add_query_arg( 'agnosis_result', 'error', home_url( '/' ) ) );
		} else {
			wp_safe_redirect( add_query_arg( 'agnosis_result', $action, home_url( '/' ) ) );
		}
		exit;
	}

	/**
	 * Handle ?agnosis_result={action|error} — show a minimal confirmation page.
	 *
	 * This runs on the same 'template_redirect' hook (priority 1).  Because
	 * handle_confirm() always exits, only one of these two handlers fires per
	 * request.
	 */
	public function handle_result(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['agnosis_result'] ) ) {
			return;
		}
		$result = sanitize_key( wp_unslash( $_GET['agnosis_result'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$labels = [
			'approve' => __( 'Artwork published', 'agnosis' ),
			'reject'  => __( 'Submission discarded', 'agnosis' ),
			'remove'  => __( 'Artwork removed', 'agnosis' ),
			'error'   => __( 'Link expired or already used', 'agnosis' ),
		];

		$messages = [
			'approve' => __( 'The artwork has been published to the gallery.', 'agnosis' ),
			'reject'  => __( 'The submission has been discarded.', 'agnosis' ),
			'remove'  => __( 'Your artwork has been removed from the gallery.', 'agnosis' ),
			'error'   => __( 'This link may have already been used or may have expired. Please log in to manage your submissions.', 'agnosis' ),
		];

		$label   = $labels[ $result ]   ?? $labels['error'];
		$message = $messages[ $result ] ?? $messages['error'];
		$is_err  = ! in_array( $result, [ 'approve', 'reject', 'remove' ], true );
		$status  = $is_err ? 400 : 200;
		$icon    = $is_err ? '✕' : '✦';
		$color   = $is_err ? '#c0392b' : '#7c6af7';

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:34px;color:%1$s;margin:0 0 16px;">%2$s</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%3$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 32px;">%4$s</p>'
			. '<a href="%5$s" style="color:%1$s;font-size:16px;text-decoration:none;">&larr; %6$s</a>'
			. '</div>',
			esc_attr( $color ),
			esc_html( $icon ),
			esc_html( $label ),
			esc_html( $message ),
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);

		wp_die( $html, esc_html( $label ), [ 'response' => $status ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is fully escaped above.
	}

	// -------------------------------------------------------------------------
	// Confirm interstitial (GET) — no state change, single POST button
	// -------------------------------------------------------------------------

	/**
	 * Render a "are you sure" page with a single POST button for a validated
	 * (but not yet executed) action. Reached only via GET — the button's form
	 * POSTs the id/action/token back as hidden fields so the token never
	 * appears in the form's action URL.
	 */
	private function render_confirm( int $id, string $action, string $token ): void {
		$prompts = [
			'approve' => [
				__( 'Publish this artwork?', 'agnosis' ),
				__( 'This will make the submission visible in the gallery.', 'agnosis' ),
				__( 'Yes, publish it', 'agnosis' ),
			],
			'reject'  => [
				__( 'Discard this submission?', 'agnosis' ),
				__( 'This will permanently discard the submission — it will not be published.', 'agnosis' ),
				__( 'Yes, discard it', 'agnosis' ),
			],
			'remove'  => [
				__( 'Remove this artwork?', 'agnosis' ),
				__( 'This will remove the published artwork from the gallery.', 'agnosis' ),
				__( 'Yes, remove it', 'agnosis' ),
			],
		];

		[ $title, $description, $button ] = $prompts[ $action ];

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:34px;color:#7c6af7;margin:0 0 16px;">✦</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%1$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 32px;">%2$s</p>'
			. '<form method="post" action="%3$s">'
			. '<input type="hidden" name="agnosis_review" value="1">'
			. '<input type="hidden" name="id" value="%4$s">'
			. '<input type="hidden" name="action" value="%5$s">'
			. '<input type="hidden" name="token" value="%6$s">'
			. '<button type="submit" style="background:#7c6af7;color:#fff;border:0;border-radius:6px;padding:12px 28px;font-size:17px;font-family:inherit;cursor:pointer;">%7$s</button>'
			. '</form>'
			. '<p style="margin:24px 0 0;"><a href="%8$s" style="color:#999;font-size:16px;text-decoration:none;">&larr; %9$s</a></p>'
			. '</div>',
			esc_html( $title ),
			esc_html( $description ),
			esc_url( home_url( '/' ) ),
			esc_attr( (string) $id ),
			esc_attr( $action ),
			esc_attr( $token ),
			esc_html( $button ),
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);

		wp_die( $html, esc_html( $title ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is fully escaped above.
	}

	/** True when the current request is a POST (the confirm button was clicked). */
	private function is_post_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparison only, not used as output.
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
	}
}
