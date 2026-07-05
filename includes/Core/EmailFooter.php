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
}
