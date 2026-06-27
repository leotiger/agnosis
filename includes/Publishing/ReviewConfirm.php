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
 *   /?agnosis_review=1&id={id}&action=approve&token=<token>   (email link)
 *       → server processes via rest_do_request() (no logged REST URL)
 *       → 302 → /?agnosis_result=approve                      (clean URL)
 *
 * The token appears only once — in the frontend shim URL's server log entry.
 * It is never forwarded to the REST access log, is never in browser history
 * after the redirect, and is never in a Referer header (same-origin redirect).
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
	 * Handle ?agnosis_review=1 — process the token action internally and
	 * redirect to a clean confirmation URL.
	 *
	 * Must run on 'template_redirect' before WP attempts to load a template.
	 */
	public function handle_confirm(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['agnosis_review'] ) ) {
			return;
		}

		$id     = absint( wp_unslash( $_GET['id'] ?? 0 ) );
		$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );
		$token  = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$allowed = [ 'approve', 'reject', 'remove' ];

		if ( ! $id || ! $token || ! in_array( $action, $allowed, true ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
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
			. '<p style="font-size:32px;color:%1$s;margin:0 0 16px;">%2$s</p>'
			. '<h1 style="font-size:22px;font-weight:700;margin:0 0 12px;">%3$s</h1>'
			. '<p style="font-size:16px;color:#555;margin:0 0 32px;">%4$s</p>'
			. '<a href="%5$s" style="color:%1$s;font-size:14px;text-decoration:none;">&larr; %6$s</a>'
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
}
