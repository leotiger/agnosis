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

class EmailFooter {

	/**
	 * Translatable label => option name, in display order.
	 *
	 * @var array<string, string>
	 */
	private const ADDRESS_OPTIONS = [
		'Artwork'    => 'agnosis_email_submit',
		'Biography'  => 'agnosis_email_bio',
		'Events'     => 'agnosis_email_event',
		'Replace'    => 'agnosis_email_replace',
		'Remove'     => 'agnosis_email_remove',
		'Promote'    => 'agnosis_email_promote',
		'Photo-only' => 'agnosis_email_photo',
	];

	/**
	 * Every configured work address, label => address. Addresses left blank in
	 * Settings → Email (or holding something that isn't a valid email) are
	 * silently skipped, so the line stays clean on a partial setup instead of
	 * printing "Events: " with nothing after it.
	 *
	 * @return array<string, string>
	 */
	public static function addresses(): array {
		$addresses = [];
		foreach ( self::ADDRESS_OPTIONS as $label => $option ) {
			$address = trim( (string) get_option( $option, '' ) );
			if ( '' !== $address && is_email( $address ) ) {
				$addresses[ $label ] = $address;
			}
		}
		return $addresses;
	}

	/**
	 * Plain-text one-line summary, e.g.:
	 *   "Artwork: art@example.art   ·   Biography: bio@example.art"
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

		$parts = [];
		foreach ( $addresses as $label => $address ) {
			$parts[] = sprintf( '%s: %s', $label, $address );
		}

		return implode( '   ·   ', $parts );
	}

	/**
	 * HTML one-line summary with real `mailto:` links, for the HTML templates
	 * in Publishing\Notification. `color:inherit` keeps the links visually
	 * consistent with each template's own footer text color rather than
	 * falling back to the client's default link blue.
	 *
	 * Returns '' when nothing is configured.
	 */
	public static function html(): string {
		$addresses = self::addresses();
		if ( empty( $addresses ) ) {
			return '';
		}

		$parts = [];
		foreach ( $addresses as $label => $address ) {
			$parts[] = sprintf(
				'%s: <a href="mailto:%s" style="color:inherit;text-decoration:underline;">%s</a>',
				esc_html( $label ),
				esc_attr( $address ),
				esc_html( $address )
			);
		}

		return implode( '&nbsp;&nbsp;&middot;&nbsp;&nbsp;', $parts );
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
