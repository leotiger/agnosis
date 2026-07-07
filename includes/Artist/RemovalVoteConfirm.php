<?php
/**
 * Email-link vote handler for the community removal-vote flow.
 *
 * Artists receive personalised yes/no URLs in the removal-vote notification
 * email (see DepartureNotification::removal_vote_url()). Those URLs contain a
 * stateless HMAC token so the artist does not need to be logged in — the same
 * pattern as VouchConfirm's admission-vote links.
 *
 * This class hooks into template_redirect, verifies the token, and — on POST
 * only — records the vote via Departure::record_vote_on_request(), rendering
 * a minimal HTML thank-you page or an error page if the token is invalid or
 * the vote is no longer open. A plain GET (the link as it arrives in the
 * email) renders a confirm page with a single POST button instead of casting
 * the vote immediately: corporate mail-security scanners prefetch links to
 * scan them, and a prefetch alone must never cast a vote (security audit
 * §2a — the same fix already applied to VouchConfirm/ReviewConfirm/
 * SubscriptionConfirm).
 *
 * This shim did not exist before 0.4.3: DepartureNotification::removal_vote_url()
 * built links pointing at it, but nothing was ever hooked to consume them —
 * every removal-vote email link was silently dead (security audit §2e).
 *
 * URL shape (all parameters required):
 *   ?agnosis_removal_vote=1&rid=<request_id>&vid=<voter_id>&vote=<yes|no>&token=<hmac>
 *
 * Token construction (must match DepartureNotification::removal_vote_url()):
 *   hash_hmac( 'sha256', "$voter_id|$request_id|$vote", wp_salt('auth') )
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

class RemovalVoteConfirm {

	private Departure $departure;

	public function __construct( Departure $departure ) {
		$this->departure = $departure;
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

		if ( empty( $source['agnosis_removal_vote'] ) ) {
			return;
		}

		$request_id = absint( wp_unslash( $source['rid']   ?? 0 ) );
		$voter_id   = absint( wp_unslash( $source['vid']   ?? 0 ) );
		$vote       = sanitize_text_field( wp_unslash( $source['vote']  ?? '' ) );
		$token      = sanitize_text_field( wp_unslash( $source['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		// Switch to the voter's own language before rendering anything below —
		// they reached this page by clicking a link in an email that was itself
		// already sent in their language (see DepartureNotification's removal-vote
		// notice, which already switches locale per recipient). Without this, the
		// vote-confirm/thank-you page reverts to the site's default language even
		// for a voter whose email arrived fully localised.
		$locale = $voter_id ? (string) get_user_meta( $voter_id, 'locale', true ) : '';
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		$this->dispatch( $is_post, $request_id, $voter_id, $vote, $token );

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
	private function dispatch( bool $is_post, int $request_id, int $voter_id, string $vote, string $token ): void {
		if ( ! $request_id || ! $voter_id || ! in_array( $vote, [ 'yes', 'no' ], true ) || ! $token ) {
			$this->render_error( __( 'Invalid vote link.', 'agnosis' ) );
			return;
		}

		if ( ! $this->verify_token( $voter_id, $request_id, $vote, $token ) ) {
			$this->render_error( __( 'This vote link is invalid or has been tampered with.', 'agnosis' ) );
			return;
		}

		// GET only renders the confirm page — a mail scanner prefetching this
		// URL gets a harmless page, not a recorded vote. The token travels in
		// the confirm form's hidden POST field, never in the form's action URL.
		if ( ! $is_post ) {
			$this->render_confirm( $request_id, $voter_id, $vote, $token );
			return;
		}

		$voter = get_userdata( $voter_id );
		if ( ! $voter ) {
			$this->render_error( __( 'Voter account not found.', 'agnosis' ) );
			return;
		}

		$result = $this->departure->record_vote_on_request( $request_id, $voter_id, $vote );

		if ( is_wp_error( $result ) ) {
			$this->render_error( $result->get_error_message() );
			return;
		}

		$data = $result->get_data();
		$this->render_success(
			$vote,
			(int) ( $data['yes_votes'] ?? 0 ),
			(int) ( $data['no_votes']  ?? 0 )
		);
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
	private function verify_token( int $voter_id, int $request_id, string $vote, string $token ): bool {
		$expected = hash_hmac(
			'sha256',
			"{$voter_id}|{$request_id}|{$vote}",
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
	 * rid/vid/vote/token back as hidden fields so the token never appears in
	 * the form's action URL.
	 */
	private function render_confirm( int $request_id, int $voter_id, string $vote, string $token ): void {
		$title = 'yes' === $vote
			? __( 'Vote to remove this artist from the community?', 'agnosis' )
			: __( 'Vote to keep this artist in the community?', 'agnosis' );

		$description = __( 'Your vote will be recorded once you confirm below.', 'agnosis' );
		$button      = __( 'Confirm my vote', 'agnosis' );

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:34px;color:#7c6af7;margin:0 0 16px;">✦</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%1$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 32px;">%2$s</p>'
			. '<form method="post" action="%3$s">'
			. '<input type="hidden" name="agnosis_removal_vote" value="1">'
			. '<input type="hidden" name="rid" value="%4$s">'
			. '<input type="hidden" name="vid" value="%5$s">'
			. '<input type="hidden" name="vote" value="%6$s">'
			. '<input type="hidden" name="token" value="%7$s">'
			. '<button type="submit" style="background:#7c6af7;color:#fff;border:0;border-radius:6px;padding:12px 28px;font-size:17px;font-family:inherit;cursor:pointer;">%8$s</button>'
			. '</form>'
			. '<p style="margin:24px 0 0;"><a href="%9$s" style="color:#999;font-size:16px;text-decoration:none;">&larr; %10$s</a></p>'
			. '</div>',
			esc_html( $title ),
			esc_html( $description ),
			esc_url( home_url( '/' ) ),
			esc_attr( (string) $request_id ),
			esc_attr( (string) $voter_id ),
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

	private function render_success( string $vote, int $yes_votes, int $no_votes ): void {
		unset( $no_votes ); // Not shown — mirrors VouchConfirm, which only surfaces the positive count.

		$label = 'yes' === $vote
			? __( 'You voted to remove this artist from the community.', 'agnosis' )
			: __( 'You voted to keep this artist in the community.', 'agnosis' );

		$message = sprintf(
			/* translators: %d: number of votes to remove recorded so far */
			_n(
				'%d vote to remove recorded so far.',
				'%d votes to remove recorded so far.',
				$yes_votes,
				'agnosis'
			),
			$yes_votes
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
