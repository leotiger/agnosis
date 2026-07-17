<?php
/**
 * Shared "work emails" footer line for artist-facing notification emails.
 *
 * Every notification class (Publishing\Notification, Artist\AdmissionNotification,
 * Artist\DepartureNotification, Artist\CommunityCapNotification) builds its own
 * email body independently — there is no shared template to hook a footer into.
 * This class is that shared piece instead: a compact, one-line summary of every
 * configured work-submission address (Settings → Email), so an artist reading
 * any of these emails always has every address they might need one glance away,
 * without hunting back through old emails to find "which address was it for
 * replacing an artwork again?".
 *
 * Deliberately excludes:
 *   - The "goodbye" (self-removal) address — that's a departure action, not a
 *     "work" address, and doesn't belong next to "here's how to submit more".
 *   - Newsletter emails — those already carry their own footer/unsubscribe link
 *     (see Newsletter\Mailer) and aren't work-submission correspondence at all.
 *
 * The "community" announcement address (agnosis_email_community) IS included
 * below (2026-07-10, was missing) — unlike goodbye, it's something an artist
 * actively sends mail to as part of participating in the community, the same
 * shape as every other address here, just not something that produces a post.
 *
 * Also home to edit_reminder_plain_text()/edit_reminder_html() — a similarly
 * shared, one-line "you can also fix things directly on the page" reminder
 * for the front-end correction feature (Artist\ContentEditor, 0.8.0). Unlike
 * the address summary, this one is gated per-recipient: the pencil-edit
 * overlay only ever appears on a post the artist authored that is already
 * published, so the reminder would just be confusing noise before an
 * artist's first artwork/biography/event has gone live. Deliberately NOT
 * wired into every email the address footer appears in — e.g. never shown
 * while an artist is suspended, since Departure::apply_ban() actually
 * removes the agnosis_artist role (and with it, ContentEditor access) for
 * the duration of the ban, which would make the reminder false.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

use Agnosis\Artist\NotificationPreferences;

class EmailFooter {

	/**
	 * Translatable label => [option name, one-line explanation], in display order.
	 *
	 * The description exists because the address alone ("Replace:
	 * replace@example.art") doesn't tell an artist what sending mail there
	 * actually does — this is meant to stand alone as a quick-reference card,
	 * not require them to remember or dig up the original settings/onboarding copy.
	 *
	 * A method, NOT a class constant: PHP constant expressions must be
	 * compile-time-evaluable, so `__()` cannot appear inside a `const` array —
	 * previously these labels and descriptions were plain hardcoded English
	 * strings despite the docblock calling them "Translatable", silently
	 * bypassing every switch_to_locale() call every caller wraps this in.
	 * Evaluated fresh on every call so it always reflects whatever locale is
	 * currently active (via switch_to_locale()) at the time the caller — every
	 * artist-facing notification email — builds its footer.
	 *
	 * @return array<string, array{option: string, desc: string}>
	 */
	private static function address_options(): array {
		return [
			__( 'Artwork', 'agnosis' )    => [
				'option' => 'agnosis_email_submit',
				'desc'   => __( 'Send new artwork to be published.', 'agnosis' ),
			],
			__( 'Biography', 'agnosis' )  => [
				'option' => 'agnosis_email_bio',
				'desc'   => __( 'Update your artist biography.', 'agnosis' ),
			],
			__( 'Events', 'agnosis' )     => [
				'option' => 'agnosis_email_event',
				'desc'   => __( 'Announce an upcoming event.', 'agnosis' ),
			],
			__( 'Replace', 'agnosis' )    => [
				'option' => 'agnosis_email_replace',
				'desc'   => __( 'Send a new version of an existing artwork — subject must match its title.', 'agnosis' ),
			],
			__( 'Remove', 'agnosis' )     => [
				'option' => 'agnosis_email_remove',
				'desc'   => __( 'Request an existing artwork or event be taken down — subject must match its title.', 'agnosis' ),
			],
			__( 'Promote', 'agnosis' )    => [
				'option' => 'agnosis_email_promote',
				'desc'   => __( 'Choose which artwork represents you in the shared community gallery on the main site — subject must match its title. Your own subdomain already shows everything you\'ve published.', 'agnosis' ),
			],
			__( 'Photo-only', 'agnosis' ) => [
				'option' => 'agnosis_email_photo',
				'desc'   => __( 'Publish a photo exactly as sent — no AI enhancement, no quality check.', 'agnosis' ),
			],
			__( 'Pure', 'agnosis' )       => [
				'option' => 'agnosis_email_pure',
				'desc'   => __( 'Publish exactly as sent — no AI at all, not even the title/description pass.', 'agnosis' ),
			],
			__( 'Community', 'agnosis' )  => [
				'option' => 'agnosis_email_community',
				'desc'   => __( 'Send a message to every other artist in the community.', 'agnosis' ),
			],
		];
	}

	/**
	 * Every configured work address, label => [address, desc]. Addresses left
	 * blank in Settings → Email (or holding something that isn't a valid
	 * email) are silently skipped, so the footer stays clean on a partial
	 * setup instead of printing an entry with nothing after it.
	 *
	 * @return array<string, array{address: string, desc: string}>
	 */
	public static function addresses(): array {
		$addresses = [];
		foreach ( self::address_options() as $label => $info ) {
			$address = trim( (string) get_option( $info['option'], '' ) );
			if ( '' !== $address && is_email( $address ) ) {
				$addresses[ $label ] = [
					'address' => $address,
					'desc'    => $info['desc'],
				];
			}
		}
		return $addresses;
	}

	/**
	 * Plain-text summary, one address per line, each followed by its one-line
	 * explanation — e.g.:
	 *   "Artwork: art@example.art — Send new artwork to be published."
	 *   "Biography: bio@example.art — Update your artist biography."
	 *
	 * No markup — most mail clients auto-link recognisable email addresses on
	 * their own even inside a plain-text body, so these read as "directly
	 * callable" (tap/click to compose) without this class needing to know
	 * anything about the receiving client.
	 *
	 * Returns '' when nothing is configured, so callers can skip the footer
	 * entirely on a bare-bones setup rather than printing an empty line.
	 */
	public static function plain_text(): string {
		$addresses = self::addresses();
		if ( empty( $addresses ) ) {
			return '';
		}

		$lines = [];
		foreach ( $addresses as $label => $info ) {
			$lines[] = sprintf( '%s: %s — %s', $label, $info['address'], $info['desc'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * HTML summary with real `mailto:` links, one address per line with its
	 * one-line explanation underneath — for the HTML templates in
	 * Publishing\Notification.
	 *
	 * Fully self-styled (address label, link color, and description color
	 * are all set here) rather than relying on `color:inherit` from whatever
	 * footer wrapper the caller uses — this is meant to be legible on its
	 * own, since it's the reference card for exactly which address does what.
	 * Callers should NOT wrap the return value in a low-contrast/small-font
	 * `<p>` — just place it directly in the footer area.
	 *
	 * Returns '' when nothing is configured.
	 */
	public static function html(): string {
		$addresses = self::addresses();
		if ( empty( $addresses ) ) {
			return '';
		}

		$rows = '';
		foreach ( $addresses as $label => $info ) {
			$rows .= sprintf(
				'<p style="margin:0 0 10px;font-size:15px;line-height:1.5;text-align:left;">'
				. '<strong style="color:' . esc_attr( EmailTemplate::footer_label_color() ) . ';">%s:</strong> '
				. '<a href="mailto:%s" style="color:' . esc_attr( EmailTemplate::accent() ) . ';text-decoration:underline;">%s</a>'
				. '<br><span style="color:' . esc_attr( EmailTemplate::text_secondary_color() ) . ';">%s</span>'
				. '</p>',
				esc_html( $label ),
				esc_attr( $info['address'] ),
				esc_html( $info['address'] ),
				esc_html( $info['desc'] )
			);
		}

		return $rows;
	}

	// -------------------------------------------------------------------------
	// Front-end editing reminder
	// -------------------------------------------------------------------------

	/**
	 * Plain-text "you can also fix things directly on the page" reminder.
	 *
	 * Returns '' for an artist with nothing published yet (see has_published_work()),
	 * so callers can use the same "skip the line entirely when empty" pattern
	 * already used for plain_text()/html() above.
	 *
	 * @param int $artist_id WP user ID of the email recipient.
	 */
	public static function edit_reminder_plain_text( int $artist_id ): string {
		if ( ! self::has_published_work( $artist_id ) ) {
			return '';
		}

		return __( 'Spotted something to fix after publishing? Log in and look for the pencil icon on your published page — you can correct the title, text, or photo yourself, no need to email us again.', 'agnosis' );
	}

	/**
	 * HTML version of edit_reminder_plain_text() — plain text only (no links
	 * needed), pre-escaped so callers can echo it directly like html() above.
	 *
	 * @param int $artist_id WP user ID of the email recipient.
	 */
	public static function edit_reminder_html( int $artist_id ): string {
		if ( ! self::has_published_work( $artist_id ) ) {
			return '';
		}

		return esc_html__( 'Spotted something to fix after publishing? Log in and look for the pencil icon on your published page — you can correct the title, text, or photo yourself, no need to email us again.', 'agnosis' );
	}

	// -------------------------------------------------------------------------
	// Notification preferences link (security audit §5b/§4a)
	// -------------------------------------------------------------------------

	/**
	 * Plain-text "manage notification preferences" line pointing at
	 * NotificationPreferences' tokenized front end — lets an artist mute
	 * community broadcasts or switch application-vote emails to a daily
	 * digest without emailing anyone or logging in (see that class's
	 * docblock). Gated to actual admitted artists only: the vote email's
	 * admin fallback recipient (AdmissionNotification::get_admin_user_id())
	 * may not hold the agnosis_artist role, and NotificationPreferences
	 * itself would reject that account's token anyway — returning '' here
	 * keeps a non-artist recipient from seeing a link that wouldn't work.
	 *
	 * @param int $artist_id WP user ID of the email recipient.
	 */
	public static function preferences_plain_text( int $artist_id ): string {
		if ( ! self::is_artist( $artist_id ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: notification preferences link */
			__( 'Manage notification preferences: %s', 'agnosis' ),
			NotificationPreferences::prefs_url( $artist_id )
		);
	}

	/**
	 * HTML version of preferences_plain_text() — a single small, muted link,
	 * pre-escaped so callers can echo it directly like html() above.
	 *
	 * @param int $artist_id WP user ID of the email recipient.
	 */
	public static function preferences_html( int $artist_id ): string {
		if ( ! self::is_artist( $artist_id ) ) {
			return '';
		}

		return sprintf(
			'<a href="%s" style="color:' . esc_attr( EmailTemplate::text_secondary_color() ) . ';font-size:13px;text-decoration:underline;">%s</a>',
			esc_url( NotificationPreferences::prefs_url( $artist_id ) ),
			esc_html__( 'Manage notification preferences', 'agnosis' )
		);
	}

	private static function is_artist( int $artist_id ): bool {
		if ( $artist_id <= 0 ) {
			return false;
		}
		$user = get_userdata( $artist_id );
		return $user && in_array( 'agnosis_artist', (array) $user->roles, true );
	}

	/**
	 * Whether $artist_id has at least one published artwork, biography, or
	 * event post. The front-end editor (Artist\ContentEditor) only ever
	 * decorates a post the artist authored that is already live, so this is
	 * the gate for whether the reminder above means anything yet.
	 *
	 * Uses WordPress's own count_user_posts() (public-status counts only)
	 * rather than a custom query — no new DB access pattern needed.
	 */
	private static function has_published_work( int $artist_id ): bool {
		if ( $artist_id <= 0 ) {
			return false;
		}

		foreach ( [ 'agnosis_artwork', 'agnosis_biography', 'agnosis_event' ] as $post_type ) {
			if ( (int) count_user_posts( $artist_id, $post_type, true ) > 0 ) {
				return true;
			}
		}

		return false;
	}
}
