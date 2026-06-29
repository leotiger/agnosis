<?php
/**
 * Email-link vote handler for the artist admission flow.
 *
 * Artists receive personalised yes/no URLs in the application notification email
 * (see AdmissionNotification::vote_url()). Those URLs contain a stateless HMAC
 * token so the artist does not need to be logged in.
 *
 * This class hooks into template_redirect, verifies the token, records the vote
 * via Admission::record_vote(), and renders a minimal HTML thank-you page — or
 * an error page if the token is invalid or the application is no longer pending.
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['agnosis_vouch'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$voter_id       = absint( wp_unslash( $_GET['voter'] ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$application_id = absint( wp_unslash( $_GET['app']   ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$vote           = sanitize_text_field( wp_unslash( $_GET['vote']  ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token          = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );

		if ( ! $voter_id || ! $application_id || ! in_array( $vote, [ 'yes', 'no' ], true ) || ! $token ) {
			$this->render_error( __( 'Invalid vote link.', 'agnosis' ) );
			return;
		}

		if ( ! $this->verify_token( $voter_id, $application_id, $vote, $token ) ) {
			$this->render_error( __( 'This vote link is invalid or has been tampered with.', 'agnosis' ) );
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

		$this->render_page(
			__( 'Vote recorded', 'agnosis' ),
			"<p>{$label}</p><p>{$message}</p>"
		);
	}

	private function render_error( string $message ): void {
		$this->render_page(
			__( 'Vote error', 'agnosis' ),
			'<p>' . esc_html( $message ) . '</p>'
		);
	}

	private function render_page( string $title, string $body_html ): void {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );

		// Minimal self-contained page — does not load the theme to keep the response fast.
		// Using discrete echo/printf calls so PHPCS can verify escaping inline.
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
		echo '</style></head><body>';
		printf( '<h1>%s</h1>', esc_html( $title ) );
		echo wp_kses_post( $body_html );
		echo '</body></html>';

		exit;
	}
}
