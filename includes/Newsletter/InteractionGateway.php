<?php
/**
 * The no-login gateway for newsletter interaction links.
 *
 * Interaction-surface roadmap, Phase 3, WP3/WP5 (agnosis-audit/
 * INTERACTION-SURFACE-ROADMAP.md §4 Phase 3G/3E, §7 Q6 third answer, §8). An
 * artist reaches "like" AND "boost" not through a login session — Agnosis
 * artists never log in — but through a one-click link in their own
 * newsletter, exactly the same stateless-HMAC-token shape already used five
 * times over elsewhere in this plugin (Publishing\ReviewConfirm,
 * Artist\NotificationPreferences, Artist\VouchConfirm, Artist\AdmissionConfirm,
 * Admin\RemovalVoteConfirm). A public-newsletter subscriber gets a like link
 * too (§4 Phase 3G step 1 — public recipients have no actor, so like only,
 * never boost, since a boost is republication under a real identity); their
 * click is identified exactly like an anonymous on-site visitor
 * (ActivityPub::like_identity(), resolved fresh at click time), not from
 * anything encoded in the link itself. A boost link always carries a real
 * artist id (never 0) — see ALLOWED_ACTIONS' own note.
 *
 * Token shape is deliberately self-contained (post id, artist id — 0 means
 * "no artist, resolve identity live" — action, and an expiry timestamp, all
 * folded into one HMAC): a newsletter digest is rendered ONCE per locale and
 * stored verbatim on the issue row (Digest::render_post_list() embeds an
 * inert `{{AGNOSIS_LIKE:<post_id>}}` placeholder, never a real token), so
 * there is no per-recipient row this token could be attached to ahead of
 * time the way the reply-moderation token (WP0) attaches to a specific
 * comment's own meta. QueueProcessor substitutes the placeholder with a real,
 * per-recipient, freshly-expiring token at actual send time instead — see
 * substitute_links().
 *
 * GET renders / POST acts (§7a) — reuses ReviewConfirm::render_action_confirm_page()
 * rather than a second hand-rolled interstitial.
 *
 * Hooked on 'template_redirect' (priority 1) in Core\Plugin, same convention
 * as every other emailed-action-link shim.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

use Agnosis\Network\ActivityPub;
use Agnosis\Publishing\ReviewConfirm;

class InteractionGateway {

	/** Query var distinguishing this shim's GET/POST from every other template_redirect-hooked one. */
	private const QUERY_KEY = 'agnosis_interaction';

	/**
	 * Actions this gateway currently knows how to actually perform.
	 * 'boost' (WP5) is only ever offered by Digest::build_artist() — the
	 * ARTIST digest — never build_public(); a public-newsletter recipient
	 * has no actor to boost under (§4 Phase 3G step 1's audience rule), so a
	 * boost link should never even reach handle_confirm() with artist_id=0
	 * in practice. handle_confirm() re-checks this server-side anyway
	 * (never trusting that the link a client followed was actually built
	 * the way this class itself would have built it) rather than assuming
	 * the audience rule alone is enough.
	 */
	private const ALLOWED_ACTIONS = [ 'like', 'boost' ];

	/** Placeholder embedded in the shared, per-locale digest HTML by Digest::render_post_list(). */
	public const LIKE_PLACEHOLDER_PATTERN = '/\{\{AGNOSIS_LIKE:(\d+)\}\}/';

	/** Placeholder embedded ONLY in the artist digest (Digest::build_artist()) — see LIKE_PLACEHOLDER_PATTERN's own note on why a public recipient never sees this one. */
	public const BOOST_PLACEHOLDER_PATTERN = '/\{\{AGNOSIS_BOOST:(\d+)\}\}/';

	// -------------------------------------------------------------------------
	// Placeholder → real link substitution (called from QueueProcessor/Archive)
	// -------------------------------------------------------------------------

	/**
	 * Replace every `{{AGNOSIS_LIKE:<post_id>}}` placeholder in a rendered
	 * digest with a real, freshly-expiring per-recipient like link.
	 *
	 * $recipient_artist_id is 0 for a public-newsletter recipient (no actor —
	 * their eventual click resolves anonymously, live, via
	 * ActivityPub::like_identity()) or an admitted artist's real user id for
	 * an artist-newsletter recipient (their click uses their own actor URL).
	 */
	public static function substitute_links( string $html, int $recipient_artist_id ): string {
		return (string) preg_replace_callback(
			self::LIKE_PLACEHOLDER_PATTERN,
			function ( array $matches ) use ( $recipient_artist_id ): string {
				$post_id = (int) $matches[1];
				$url     = self::build_url( $post_id, $recipient_artist_id, 'like' );

				return sprintf(
					'<a href="%1$s" style="color:#7c6af7;text-decoration:none;font-size:15px;">%2$s</a>',
					esc_url( $url ),
					esc_html__( '♥ Like this', 'agnosis' )
				);
			},
			$html
		);
	}

	/**
	 * Replace every `{{AGNOSIS_BOOST:<post_id>}}` placeholder (WP5) with a
	 * real, freshly-expiring per-recipient boost link. Unlike
	 * substitute_links() above, $recipient_artist_id of 0 cannot resolve to
	 * "anonymous" here — a boost is only ever performable by a real artist
	 * (§4 3E step 1) — so a 0 defensively strips the placeholder to nothing
	 * rather than emit a link that could only ever fail server-side. In
	 * practice this placeholder is only ever embedded in the artist digest
	 * (Digest::build_artist()), whose recipients always have a real id, so
	 * this branch is a safety net, not an expected path.
	 */
	public static function substitute_boost_links( string $html, int $recipient_artist_id ): string {
		if ( $recipient_artist_id <= 0 ) {
			return (string) preg_replace( self::BOOST_PLACEHOLDER_PATTERN, '', $html );
		}

		return (string) preg_replace_callback(
			self::BOOST_PLACEHOLDER_PATTERN,
			function ( array $matches ) use ( $recipient_artist_id ): string {
				$post_id = (int) $matches[1];
				$url     = self::build_url( $post_id, $recipient_artist_id, 'boost' );

				return sprintf(
					'<a href="%1$s" style="color:#7c6af7;text-decoration:none;font-size:15px;">%2$s</a>',
					esc_url( $url ),
					esc_html__( '⟲ Boost this', 'agnosis' )
				);
			},
			$html
		);
	}

	/**
	 * Strip every like/boost placeholder to nothing — used by
	 * Newsletter\Archive's public "view in browser" rendering, which (like
	 * Mailer::build_body()'s existing `$unsubscribe_url = null` path) never
	 * carries any recipient-specific link at all. In practice Archive only
	 * ever renders a PUBLIC issue (its own query is hard-scoped to
	 * `newsletter_type = 'public'`), and the boost placeholder only ever
	 * appears in the ARTIST digest, so the boost branch here is defensive
	 * rather than a path that fires today — cheap insurance against a
	 * literal `{{AGNOSIS_BOOST:…}}` ever leaking into rendered HTML if that
	 * scoping ever changes.
	 */
	public static function inert( string $html ): string {
		$html = (string) preg_replace( self::LIKE_PLACEHOLDER_PATTERN, '', $html );
		return (string) preg_replace( self::BOOST_PLACEHOLDER_PATTERN, '', $html );
	}

	// -------------------------------------------------------------------------
	// Token
	// -------------------------------------------------------------------------

	private static function build_url( int $post_id, int $artist_id, string $action ): string {
		$days    = max( 1, (int) get_option( 'agnosis_review_token_expiry_days', 7 ) );
		$expires = time() + $days * DAY_IN_SECONDS;
		$token   = self::token( $post_id, $artist_id, $action, $expires );

		return add_query_arg(
			[
				self::QUERY_KEY => 1,
				'post'          => $post_id,
				'artist'        => $artist_id,
				'do'            => $action,
				'expires'       => $expires,
				'token'         => $token,
			],
			home_url( '/' )
		);
	}

	private static function token( int $post_id, int $artist_id, string $action, int $expires ): string {
		return hash_hmac( 'sha256', "interaction|{$post_id}|{$artist_id}|{$action}|{$expires}", wp_salt( 'auth' ) );
	}

	/** Constant-time verification, including expiry — a stale link is simply invalid, same as an expired review/vouch/admission token. */
	private static function verify( int $post_id, int $artist_id, string $action, int $expires, string $token ): bool {
		if ( $expires < time() ) {
			return false;
		}

		return hash_equals( self::token( $post_id, $artist_id, $action, $expires ), $token );
	}

	// -------------------------------------------------------------------------
	// GET renders / POST acts (§7a)
	// -------------------------------------------------------------------------

	/**
	 * Handle ?agnosis_interaction=1. Mirrors Publishing\ReviewConfirm::handle_confirm()'s
	 * exact GET/POST split and reasoning — see that class's own docblock.
	 */
	public function handle_confirm(): void {
		$is_post = ReviewConfirm::is_post_request();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- unauthenticated by design (an emailed-link recipient with no WP session); the HMAC token plays the nonce's role.
		$source = $is_post ? $_POST : $_GET;

		if ( empty( $source[ self::QUERY_KEY ] ) ) {
			return;
		}

		$post_id   = absint( wp_unslash( $source['post'] ?? 0 ) );
		$artist_id = absint( wp_unslash( $source['artist'] ?? 0 ) );
		$action    = sanitize_key( wp_unslash( $source['do'] ?? '' ) );
		$expires   = absint( wp_unslash( $source['expires'] ?? 0 ) );
		$token     = sanitize_text_field( wp_unslash( $source['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! $token || ! in_array( $action, self::ALLOWED_ACTIONS, true ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if ( ! self::verify( $post_id, $artist_id, $action, $expires, $token ) ) {
			$this->render_error( __( 'This link has expired or is no longer valid.', 'agnosis' ) );
			return;
		}

		$activitypub = new ActivityPub();
		$post        = $activitypub->likeable_artwork( $post_id );
		if ( is_wp_error( $post ) ) {
			$this->render_error( __( 'This artwork could not be found.', 'agnosis' ) );
			return;
		}

		// A boost requires a real artist identity (§4 3E step 1) — re-checked
		// here rather than trusted from the audience rule alone (see
		// ALLOWED_ACTIONS' own note): a crafted or stale artist=0 link with
		// do=boost must fail cleanly, not silently fall through to
		// like_identity()'s anonymous-visitor path the way a genuine like
		// link legitimately does.
		if ( 'boost' === $action && $artist_id <= 0 ) {
			$this->render_error( __( 'This link is invalid.', 'agnosis' ) );
			return;
		}

		// GET only renders the confirm page — a mail scanner prefetching this
		// URL gets a harmless page, not a state change (§7a).
		if ( ! $is_post ) {
			$this->render_confirm( $post, $post_id, $artist_id, $action, $expires, $token );
			return;
		}

		if ( 'boost' === $action ) {
			$activitypub->write_boost( $post_id, $artist_id, true );
			wp_safe_redirect( add_query_arg( 'agnosis_interaction_result', 'boosted', home_url( '/' ) ) );
			exit;
		}

		$actor_id = $artist_id > 0
			? $activitypub->actor_url_for( 'artist', $artist_id )
			: $activitypub->like_identity();

		$activitypub->write_like( $post_id, $actor_id, true );

		wp_safe_redirect( add_query_arg( 'agnosis_interaction_result', 'liked', home_url( '/' ) ) );
		exit;
	}

	/**
	 * Handle ?agnosis_interaction_result=liked|boosted — a minimal thank-you
	 * page, same "clean URL, token never appears in it" convention as
	 * ReviewConfirm::handle_result().
	 */
	public function handle_result(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = sanitize_key( wp_unslash( $_GET['agnosis_interaction_result'] ?? '' ) );
		if ( ! in_array( $result, [ 'liked', 'boosted' ], true ) ) {
			return;
		}

		$is_boost = 'boosted' === $result;
		$title    = $is_boost ? __( 'Boosted', 'agnosis' ) : __( 'Liked', 'agnosis' );
		$body     = $is_boost
			? __( 'Thanks for sharing this artist\'s work with your own followers.', 'agnosis' )
			: __( 'Thanks for supporting this artist.', 'agnosis' );
		$symbol   = $is_boost ? '⟲' : '♥';

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:34px;color:#7c6af7;margin:0 0 16px;">%1$s</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%2$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 32px;">%3$s</p>'
			. '<a href="%4$s" style="color:#7c6af7;font-size:16px;text-decoration:none;">&larr; %5$s</a>'
			. '</div>',
			esc_html( $symbol ),
			esc_html( $title ),
			esc_html( $body ),
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);

		wp_die( $html, esc_html( $title ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is fully escaped above.
	}

	private function render_confirm( \WP_Post $post, int $post_id, int $artist_id, string $action, int $expires, string $token ): void {
		$is_boost = 'boost' === $action;

		ReviewConfirm::render_action_confirm_page(
			$is_boost
				/* translators: %s: artwork title. */
				? sprintf( __( 'Boost "%s"?', 'agnosis' ), get_the_title( $post ) )
				/* translators: %s: artwork title. */
				: sprintf( __( 'Like "%s"?', 'agnosis' ), get_the_title( $post ) ),
			$is_boost
				? __( 'This will share the artwork with your own followers, under your own name.', 'agnosis' )
				: __( 'Your like will be recorded once you confirm below.', 'agnosis' ),
			$is_boost
				? __( 'Yes, boost it', 'agnosis' )
				: __( 'Yes, like it', 'agnosis' ),
			[
				self::QUERY_KEY => '1',
				'post'          => (string) $post_id,
				'artist'        => (string) $artist_id,
				'do'            => $action,
				'expires'       => (string) $expires,
				'token'         => $token,
			]
		);
	}

	private function render_error( string $message ): void {
		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:34px;color:#c0392b;margin:0 0 16px;">✕</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%1$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 32px;">%2$s</p>'
			. '<a href="%3$s" style="color:#999;font-size:16px;text-decoration:none;">&larr; %4$s</a>'
			. '</div>',
			esc_html__( 'Link expired or invalid', 'agnosis' ),
			esc_html( $message ),
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);

		wp_die( $html, esc_html__( 'Link expired or invalid', 'agnosis' ), [ 'response' => 400 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is fully escaped above.
	}
}
