<?php
/**
 * Email-link vote handler for the artist admission flow.
 *
 * Artists receive personalised yes/no URLs in the application notification email
 * (see AdmissionNotification::vote_url()). Those URLs contain a stateless HMAC
 * token so the artist does not need to be logged in.
 *
 * This class hooks into template_redirect, verifies the token, and — on POST
 * only — records the vote via Admission::record_vote(), rendering a minimal
 * HTML thank-you page or an error page if the token is invalid or the
 * application is no longer pending. A plain GET (the link as it arrives in the
 * email) renders a confirm page with a single POST button instead of casting
 * the vote immediately: corporate mail-security scanners prefetch links to
 * scan them, and a prefetch alone must never cast a vote (security audit §2a).
 *
 * URL shape (all parameters required):
 *   ?agnosis_vouch=1&voter=<user_id>&app=<application_id>&vote=<yes|no>&token=<hmac>
 *
 * Token construction (must match AdmissionNotification::vote_url()):
 *   hash_hmac( 'sha256', "$voter_id|$application_id|$vote", wp_salt('auth') )
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

class VouchConfirm {

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

		// No WP nonce here — this is an unauthenticated email-link recipient with
		// no WP session; the single-use HMAC vote token plays the nonce's role.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$source = $is_post ? $_POST : $_GET;

		if ( empty( $source['agnosis_vouch'] ) ) {
			return;
		}

		$voter_id       = absint( wp_unslash( $source['voter'] ?? 0 ) );
		$application_id = absint( wp_unslash( $source['app']   ?? 0 ) );
		$vote           = sanitize_text_field( wp_unslash( $source['vote']  ?? '' ) );
		$token          = sanitize_text_field( wp_unslash( $source['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		// Switch to the voter's own language before rendering anything below —
		// they reached this page by clicking a link in an email that was itself
		// already sent in their language (see AdmissionNotification::send_vote_email()).
		// Without this, the vote-confirm/thank-you page reverts to the site's
		// default language even for a voter whose email arrived fully localised.
		$locale = $voter_id ? (string) get_user_meta( $voter_id, 'locale', true ) : '';
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		$this->dispatch( $is_post, $voter_id, $application_id, $vote, $token );

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	/**
	 * The actual token-verify / render dispatch, split out from handle() so the
	 * locale switch above wraps every render_*() call below (each of which ends
	 * in wp_die()) with a single switch/restore pair rather than needing one at
	 * every early return.
	 */
	private function dispatch( bool $is_post, int $voter_id, int $application_id, string $vote, string $token ): void {
		if ( ! $voter_id || ! $application_id || ! in_array( $vote, [ 'yes', 'no' ], true ) || ! $token ) {
			$this->render_error( __( 'Invalid vote link.', 'agnosis' ) );
			return;
		}

		if ( ! $this->verify_token( $voter_id, $application_id, $vote, $token ) ) {
			$this->render_error( __( 'This vote link is invalid or has been tampered with.', 'agnosis' ) );
			return;
		}

		// GET only renders the confirm page — a mail scanner prefetching this
		// URL gets a harmless page, not a recorded vote. The token travels in
		// the confirm form's hidden POST field, never in the form's action URL.
		if ( ! $is_post ) {
			$this->render_confirm( $voter_id, $application_id, $vote, $token );
			return;
		}

		$voter = get_userdata( $voter_id );
		if ( ! $voter ) {
			$this->render_error( __( 'Voter account not found.', 'agnosis' ) );
			return;
		}

		// record_vote() handles application state checks (pending, self-vouch guard, etc.)
		$result = $this->admission->record_vote( $voter_id, $application_id, $vote );

		if ( is_wp_error( $result ) ) {
			$this->render_error( $result->get_error_message() );
			return;
		}

		$data = $result->get_data();
		$this->render_success( $vote, (int) ( $data['vouches_received'] ?? 0 ) );
	}

	/** True when the current request is a POST (the confirm button was clicked). */
	private function is_post_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparison only, not used as output.
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
	}

	// -------------------------------------------------------------------------

	/**
	 * Verify the HMAC token against the expected value.
	 *
	 * Uses hash_equals() for constant-time comparison to resist timing attacks.
	 */
	private function verify_token( int $voter_id, int $application_id, string $vote, string $token ): bool {
		$expected = hash_hmac(
			'sha256',
			"{$voter_id}|{$application_id}|{$vote}",
			wp_salt( 'auth' )
		);

		return hash_equals( $expected, $token );
	}

	// -------------------------------------------------------------------------
	// Confirm interstitial (GET) — no state change, single POST button
	// -------------------------------------------------------------------------

	/**
	 * Render a "are you sure" page with a single POST button for a validated
	 * (but not yet cast) vote. Reached only via GET — the button's form POSTs
	 * voter/app/vote/token back as hidden fields so the token never appears in
	 * the form's action URL.
	 */
	private function render_confirm( int $voter_id, int $application_id, string $vote, string $token ): void {
		$title = 'yes' === $vote
			? __( 'Vote in favour of this application?', 'agnosis' )
			: __( 'Vote against this application?', 'agnosis' );

		$description = __( 'Your vote will be recorded once you confirm below.', 'agnosis' );
		$button      = __( 'Confirm my vote', 'agnosis' );

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:34px;color:#7c6af7;margin:0 0 16px;">✦</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%1$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 32px;">%2$s</p>'
			. '<form method="post" action="%3$s">'
			. '<input type="hidden" name="agnosis_vouch" value="1">'
			. '<input type="hidden" name="voter" value="%4$s">'
			. '<input type="hidden" name="app" value="%5$s">'
			. '<input type="hidden" name="vote" value="%6$s">'
			. '<input type="hidden" name="token" value="%7$s">'
			. '<button type="submit" style="background:#7c6af7;color:#fff;border:0;border-radius:6px;padding:12px 28px;font-size:17px;font-family:inherit;cursor:pointer;">%8$s</button>'
			. '</form>'
			. '<p style="margin:24px 0 0;"><a href="%9$s" style="color:#999;font-size:16px;text-decoration:none;">&larr; %10$s</a></p>'
			. '</div>',
			esc_html( $title ),
			esc_html( $description ),
			esc_url( home_url( '/' ) ),
			esc_attr( (string) $voter_id ),
			esc_attr( (string) $application_id ),
			esc_attr( $vote ),
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

	private function render_success( string $vote, int $vouches_received ): void {
		$label   = 'yes' === $vote
			? __( 'You voted in favour of this application.', 'agnosis' )
			: __( 'You voted against this application.', 'agnosis' );

		$message = sprintf(
			/* translators: %d: number of positive votes so far */
			_n(
				'%d positive vote recorded so far.',
				'%d positive votes recorded so far.',
				$vouches_received,
				'agnosis'
			),
			$vouches_received
		);

		$this->render_page( __( 'Vote recorded', 'agnosis' ), $label, $message, false );
	}

	private function render_error( string $message ): void {
		$this->render_page( __( 'Vote error', 'agnosis' ), $message, '', true );
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
