<?php
/**
 * Tokenized, unauthenticated "manage notification preferences" front end
 * (security audit §5b/§4a).
 *
 * Before this existed, an artist could only opt out of the newsletter
 * (`_agnosis_newsletter_optout`) — community broadcasts and application-vote
 * emails had no dial at all short of a full departure via goodbye@. On an
 * active node the realistic move for an artist drowning in either was the
 * spam button, which trains their mailbox provider against the whole shared
 * sending domain and degrades delivery for everyone, not just them (see
 * §5a/§5c on why that reputation matters so much here). This class is the
 * "dial" the audit calls for: independent per-artist preferences,
 *   - `_agnosis_broadcast_optout` ('1' mutes community broadcasts entirely —
 *     honored by CommunityBroadcast::broadcast()'s recipient query)
 *   - `_agnosis_vote_email_mode`  ('instant', the default, or 'digest' —
 *     honored by AdmissionNotification::on_application_received() choosing
 *     who gets the vote email immediately vs. Artist\VoteDigest's daily
 *     rollup for everyone else)
 *   - `_agnosis_contact_optout`  ('1' turns off the visitor contact form
 *     entirely — honored by Artist\ContactForm::contactable_artist(), which
 *     also hides the contact icon itself (SubdomainNavigation's
 *     `type=contact` breadcrumb link) so an opted-out artist's page never
 *     even offers a form nobody will read; 2026-07-12)
 * deliberately NOT including an "off" option for vote emails — per the
 * audit, voting is a membership duty, not something to silence entirely;
 * "digest" is the throttle, not "never". The contact form has no such
 * membership-duty framing — replying to a stranger's message is discretionary,
 * not a community obligation — so it gets a plain on/off toggle instead.
 *
 * Working with Agnosis is entirely email-based by design (see
 * AdmissionNotification::on_artist_admitted()'s docblock — no login is ever
 * required), so this follows the same "authenticated by a stateless,
 * long-lived HMAC token" convention as everywhere else in this plugin an
 * artist needs to act on something without a WP session, rather than
 * ContentEditor's logged-in front end (that one exists to edit already-
 * published post content, a different concern). Unlike VouchConfirm's/
 * AdmissionConfirm's single-use-by-side-effect tokens (a vote or a
 * confirmation only ever needs to fire once), this token is meant to be
 * clicked from any email's footer at any time indefinitely — so it is NOT
 * single-use, and carries no expiry: it is a deterministic function of the
 * artist's own user ID and wp_salt('auth'), the same "no DB row needed"
 * approach AdmissionNotification::vote_url() already uses, just without that
 * one's single-action ('yes'/'no') component baked into the hash. Its only
 * capability is viewing/editing that one artist's own preferences below
 * — a narrow, bounded blast radius if a token were ever leaked.
 *
 * URL shape: ?agnosis_prefs=1&artist=<user_id>&token=<hmac>
 *
 * GET renders the actual settings form pre-filled with current values —
 * unlike VouchConfirm/AdmissionConfirm's GET-only-shows-a-confirm-button
 * pattern, merely *displaying* current preferences has no state-changing
 * side effect for a mail-scanner prefetch to worry about; only the POST that
 * saves changes needs that same "prefetch must never act" discipline, which
 * it gets for free by being a separate verb entirely.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

class NotificationPreferences {

	/** Allowed values for `_agnosis_vote_email_mode` — no "off", see class docblock. */
	private const VOTE_MODES = [ 'instant', 'digest' ];

	public function register_hooks(): void {
		add_action( 'template_redirect', [ $this, 'handle' ] );
	}

	// -------------------------------------------------------------------------
	// Public helpers — read by CommunityBroadcast / AdmissionNotification / VoteDigest
	// -------------------------------------------------------------------------

	/**
	 * Build the stateless, long-lived preferences-link URL for one artist.
	 * See class docblock for why this token is neither single-use nor time-limited.
	 */
	public static function prefs_url( int $user_id ): string {
		return add_query_arg(
			[
				'agnosis_prefs' => '1',
				'artist'        => $user_id,
				'token'         => self::token( $user_id ),
			],
			home_url( '/' )
		);
	}

	private static function token( int $user_id ): string {
		return hash_hmac( 'sha256', "{$user_id}|notification_prefs", wp_salt( 'auth' ) );
	}

	/**
	 * FEP-5feb discovery consent (Interaction-surface roadmap, Phase 3, WP1) —
	 * `_agnosis_discovery_optout` gates the `indexable`/`discoverable` booleans
	 * `Network\ActivityPub::artist_actor()` emits on that artist's `Person`
	 * actor. Actor-level, not per-artwork (§7 Q7's own correction: "setting it
	 * governs everything that artist has ever published"), default
	 * discoverable — same NOT-EXISTS-or-not-'1' opt-out convention as every
	 * other preference on this page, and same "on by default" posture Ulises
	 * chose explicitly for this flag ("Default ON... artist should be able to
	 * use/configure everything without the need to login"). This class stays
	 * the single source of truth for both reading and writing the flag —
	 * `Publishing\ReviewConfirm`'s mirrored checkbox on the artwork/biography
	 * approval pages calls these same two methods rather than touching the
	 * meta key directly, so there is exactly one place that knows what the
	 * meta key is and what "opted out" means.
	 */
	public static function is_discovery_opted_out( int $user_id ): bool {
		return '1' === get_user_meta( $user_id, '_agnosis_discovery_optout', true );
	}

	public static function set_discovery_optout( int $user_id, bool $opted_out ): void {
		if ( $opted_out ) {
			update_user_meta( $user_id, '_agnosis_discovery_optout', '1' );
		} else {
			delete_user_meta( $user_id, '_agnosis_discovery_optout' );
		}
	}

	/**
	 * NL1 (RHIZOME-NETWORK-ROADMAP.md §11a, 2026-07-30) — `_agnosis_interaction_summary_optout`
	 * mutes the personal "N likes / N boosts on your work" bullet
	 * `Newsletter\Digest::substitute_interaction_summary()` adds to the artist
	 * digest. Default ON (no meta row means "receiving it"), same
	 * NOT-EXISTS-or-not-'1' convention as every other optout meta on this
	 * page — this is zero-cost personal information about the artist's own
	 * work, not something needing an opt-in.
	 */
	public static function is_interaction_summary_opted_out( int $user_id ): bool {
		return '1' === get_user_meta( $user_id, '_agnosis_interaction_summary_optout', true );
	}

	public static function set_interaction_summary_optout( int $user_id, bool $opted_out ): void {
		if ( $opted_out ) {
			update_user_meta( $user_id, '_agnosis_interaction_summary_optout', '1' );
		} else {
			delete_user_meta( $user_id, '_agnosis_interaction_summary_optout' );
		}
	}

	// -------------------------------------------------------------------------

	public function handle(): void {
		$is_post = $this->is_post_request();

		// No WP nonce — this is an unauthenticated email-link recipient with no
		// WP session; the HMAC token plays the nonce's role instead, same as
		// every other stateless-token flow in this plugin.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$source = $is_post ? $_POST : $_GET;

		if ( empty( $source['agnosis_prefs'] ) ) {
			return;
		}

		$artist_id = absint( wp_unslash( $source['artist'] ?? 0 ) );
		$token     = sanitize_text_field( wp_unslash( $source['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		if ( ! $artist_id || '' === $token || ! hash_equals( self::token( $artist_id ), $token ) ) {
			$this->render_error( __( 'This preferences link is invalid or has been tampered with.', 'agnosis' ) );
			return;
		}

		$artist = get_userdata( $artist_id );
		if ( ! $artist || ! in_array( 'agnosis_artist', (array) $artist->roles, true ) ) {
			$this->render_error( __( 'This preferences link is no longer valid for this account.', 'agnosis' ) );
			return;
		}

		$locale = (string) get_user_meta( $artist_id, 'locale', true );
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		if ( $is_post ) {
			$this->save( $artist_id, $source );
		} else {
			$this->render_form( $artist_id, $token );
		}

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	/** True when the current request is a POST (the save button was clicked). */
	private function is_post_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparison only, not used as output.
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
	}

	/**
	 * @param array<string, mixed> $source Raw $_POST (already token-verified by handle()).
	 */
	private function save( int $artist_id, array $source ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- see handle()'s docblock: the HMAC token is this flow's nonce equivalent.
		$mute_broadcasts   = ! empty( $source['mute_broadcasts'] );
		$vote_mode         = sanitize_key( wp_unslash( $source['vote_mode'] ?? 'instant' ) );
		$contact_optout    = ! empty( $source['contact_optout'] );
		$replies_optout    = ! empty( $source['replies_optout'] );
		$discovery_optout  = ! empty( $source['discovery_optout'] );
		$interaction_summary_optout = ! empty( $source['interaction_summary_optout'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $vote_mode, self::VOTE_MODES, true ) ) {
			$vote_mode = 'instant';
		}

		if ( $mute_broadcasts ) {
			update_user_meta( $artist_id, '_agnosis_broadcast_optout', '1' );
		} else {
			delete_user_meta( $artist_id, '_agnosis_broadcast_optout' );
		}

		// 'instant' is the implicit default (see Scheduler::artist_recipients()'s
		// NOT-EXISTS-or-not-equal convention this mirrors) — no meta row needed
		// for it, only 'digest' is ever actually stored.
		if ( 'digest' === $vote_mode ) {
			update_user_meta( $artist_id, '_agnosis_vote_email_mode', 'digest' );
		} else {
			delete_user_meta( $artist_id, '_agnosis_vote_email_mode' );
		}

		if ( $contact_optout ) {
			update_user_meta( $artist_id, '_agnosis_contact_optout', '1' );
		} else {
			delete_user_meta( $artist_id, '_agnosis_contact_optout' );
		}

		// Interaction-surface roadmap, Phase 2 (§4 step 5) — account-wide
		// master switch for federated replies, on by default (Ulises: "on by
		// default and possible to opt-out on... every reply") — same
		// NOT-EXISTS-or-not-'1' convention as every other optout meta here,
		// checked directly by ActivityPub::handle_create_reply().
		if ( $replies_optout ) {
			update_user_meta( $artist_id, '_agnosis_replies_optout', '1' );
		} else {
			delete_user_meta( $artist_id, '_agnosis_replies_optout' );
		}

		// Interaction-surface roadmap, Phase 3 (WP1) — FEP-5feb discovery
		// consent, account-wide, default discoverable. See
		// is_discovery_opted_out()/set_discovery_optout()'s own docblock: this
		// is the canonical write path, mirrored (not duplicated) by the
		// artwork/biography approval pages in Publishing\ReviewConfirm.
		self::set_discovery_optout( $artist_id, $discovery_optout );

		// NL1 (RHIZOME-NETWORK-ROADMAP.md §11a) — see
		// is_interaction_summary_opted_out()'s own docblock; this class stays
		// the single source of truth for both reading and writing the flag,
		// same as every other preference on this page.
		self::set_interaction_summary_optout( $artist_id, $interaction_summary_optout );

		$this->render_saved( $mute_broadcasts, $vote_mode, $contact_optout, $replies_optout, $discovery_optout, $interaction_summary_optout );
	}

	// -------------------------------------------------------------------------
	// Form (GET)
	// -------------------------------------------------------------------------

	private function render_form( int $artist_id, string $token ): void {
		$muted          = '1' === get_user_meta( $artist_id, '_agnosis_broadcast_optout', true );
		$vote_mode      = 'digest' === get_user_meta( $artist_id, '_agnosis_vote_email_mode', true ) ? 'digest' : 'instant';
		$contact_opted_out = '1' === get_user_meta( $artist_id, '_agnosis_contact_optout', true );
		// Interaction-surface roadmap, Phase 2 — on by default (no meta row
		// means "receiving replies"), same NOT-EXISTS-or-not-'1' convention as
		// every other optout meta on this page.
		$replies_opted_out = '1' === get_user_meta( $artist_id, '_agnosis_replies_optout', true );
		// Interaction-surface roadmap, Phase 3 (WP1) — see is_discovery_opted_out()'s docblock.
		$discovery_opted_out = self::is_discovery_opted_out( $artist_id );
		// NL1 (RHIZOME-NETWORK-ROADMAP.md §11a) — see is_interaction_summary_opted_out()'s docblock.
		$interaction_summary_opted_out = self::is_interaction_summary_opted_out( $artist_id );

		$html = sprintf(
			'<div style="max-width:520px;margin:60px auto;font-family:Georgia,serif;color:#222;padding:0 20px;">'
			. '<p style="font-size:34px;color:#7c6af7;margin:0 0 16px;text-align:center;">✦</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 24px;text-align:center;">%1$s</h1>'
			. '<form method="post" action="%2$s">'
			. '<input type="hidden" name="agnosis_prefs" value="1">'
			. '<input type="hidden" name="artist" value="%3$s">'
			. '<input type="hidden" name="token" value="%4$s">'
			. '<label style="display:block;margin:0 0 20px;font-size:17px;line-height:1.5;">'
			. '<input type="checkbox" name="mute_broadcasts" value="1" %5$s style="margin-right:8px;">%6$s'
			. '</label>'
			. '<p style="font-size:17px;font-weight:700;margin:0 0 8px;">%7$s</p>'
			. '<label style="display:block;margin:0 0 8px;font-size:17px;">'
			. '<input type="radio" name="vote_mode" value="instant" %8$s style="margin-right:8px;">%9$s'
			. '</label>'
			. '<label style="display:block;margin:0 0 24px;font-size:17px;">'
			. '<input type="radio" name="vote_mode" value="digest" %10$s style="margin-right:8px;">%11$s'
			. '</label>'
			. '<label style="display:block;margin:0 0 24px;font-size:17px;line-height:1.5;">'
			. '<input type="checkbox" name="contact_optout" value="1" %12$s style="margin-right:8px;">%13$s'
			. '</label>'
			. '<label style="display:block;margin:0 0 24px;font-size:17px;line-height:1.5;">'
			. '<input type="checkbox" name="replies_optout" value="1" %14$s style="margin-right:8px;">%15$s'
			. '</label>'
			. '<label style="display:block;margin:0 0 24px;font-size:17px;line-height:1.5;">'
			. '<input type="checkbox" name="discovery_optout" value="1" %16$s style="margin-right:8px;">%17$s'
			. '</label>'
			. '<label style="display:block;margin:0 0 24px;font-size:17px;line-height:1.5;">'
			. '<input type="checkbox" name="interaction_summary_optout" value="1" %18$s style="margin-right:8px;">%19$s'
			. '</label>'
			. '<button type="submit" style="background:#7c6af7;color:#fff;border:0;border-radius:6px;padding:12px 28px;font-size:17px;font-family:inherit;cursor:pointer;">%20$s</button>'
			. '</form>'
			. '</div>',
			esc_html__( 'Notification preferences', 'agnosis' ),
			esc_url( home_url( '/' ) ),
			esc_attr( (string) $artist_id ),
			esc_attr( $token ),
			checked( $muted, true, false ),
			esc_html__( 'Mute community broadcast messages from other artists.', 'agnosis' ),
			esc_html__( 'Application vote emails', 'agnosis' ),
			checked( $vote_mode, 'instant', false ),
			esc_html__( 'Instantly, one email per application (default).', 'agnosis' ),
			checked( $vote_mode, 'digest', false ),
			esc_html__( 'Once a day, a single digest of every application still awaiting my vote.', 'agnosis' ),
			checked( $contact_opted_out, true, false ),
			esc_html__( "Turn off the contact form on my page — visitors won't be able to message me.", 'agnosis' ),
			checked( $replies_opted_out, true, false ),
			esc_html__( "Turn off federated replies on my artworks entirely — no new reply will be accepted from the Fediverse (this doesn't affect replies already showing).", 'agnosis' ),
			checked( $discovery_opted_out, true, false ),
			esc_html__( 'Turn off search engine and Fediverse discovery indexing — applies to my whole profile and everything I publish here, not just one piece.', 'agnosis' ),
			checked( $interaction_summary_opted_out, true, false ),
			esc_html__( 'Turn off the personal "likes and boosts on your work" line in your newsletter digest.', 'agnosis' ),
			esc_html__( 'Save preferences', 'agnosis' )
		);

		wp_die( $html, esc_html__( 'Notification preferences', 'agnosis' ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is fully escaped above.
	}

	// -------------------------------------------------------------------------
	// Result pages (POST)
	// -------------------------------------------------------------------------

	private function render_saved( bool $muted, string $vote_mode, bool $contact_opted_out = false, bool $replies_opted_out = false, bool $discovery_opted_out = false, bool $interaction_summary_opted_out = false ): void {
		$lines = [
			$muted
				? __( 'Community broadcasts are now muted.', 'agnosis' )
				: __( "You'll continue to receive community broadcasts.", 'agnosis' ),
			'digest' === $vote_mode
				? __( "You'll receive one daily digest of applications awaiting your vote.", 'agnosis' )
				: __( "You'll receive an email as soon as each new application arrives.", 'agnosis' ),
			$contact_opted_out
				? __( 'The contact form on your page is now turned off.', 'agnosis' )
				: __( 'Visitors can still reach you through the contact form on your page.', 'agnosis' ),
			$replies_opted_out
				? __( 'Federated replies on your artworks are now turned off.', 'agnosis' )
				: __( "You'll continue to receive federated replies (and their notification emails) on your artworks.", 'agnosis' ),
			$discovery_opted_out
				? __( 'Search engines and Fediverse discovery services will no longer be told to index your profile or your work.', 'agnosis' )
				: __( 'Search engines and Fediverse discovery services may continue indexing your profile and your work.', 'agnosis' ),
			$interaction_summary_opted_out
				? __( 'The personal likes/boosts line is now off in your newsletter digest.', 'agnosis' )
				: __( "You'll continue to see a personal likes/boosts line in your newsletter digest.", 'agnosis' ),
		];

		$this->render_page( __( 'Preferences saved', 'agnosis' ), implode( ' ', $lines ), false );
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
			. '<p style="font-size:34px;color:%1$s;margin:0 0 16px;">%2$s</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%3$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 32px;">%4$s</p>'
			. '<p style="margin:0;"><a href="%5$s" style="color:%1$s;font-size:16px;text-decoration:none;">&larr; %6$s</a></p>'
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
