<?php
/**
 * Shared bounce/complaint suppression (security audit §5a).
 *
 * `wp_mail()` returning true only means a message was handed to the
 * transport — a hard bounce (dead address, domain gone) or a spam complaint
 * comes back LATER, out-of-band, as either a webhook event (Mailgun/
 * SendGrid/Postmark) or a DSN delivered back into the IMAP mailbox itself.
 * Nothing previously read either signal: a dead newsletter subscriber was
 * mailed every issue forever, and an artist whose inbox died silently
 * stopped receiving review links with no operator-visible sign anything
 * was wrong.
 *
 * Both intake transports funnel through record() below so a bounce/
 * complaint is handled identically regardless of which one detected it —
 * Webhook::handle_bounce_event() for ESP event webhooks, Inbox's DSN header
 * recognition for IMAP sites. One address can be both a newsletter
 * subscriber and an admitted artist; record() handles either, both, or
 * neither without the caller needing to know which.
 *
 * @package Agnosis\Email
 */

declare(strict_types=1);

namespace Agnosis\Email;

use Agnosis\Artist\Admission;
use Agnosis\Core\Logger;
use Agnosis\Newsletter\Subscriber;

class BounceHandler {

	/**
	 * Record a hard bounce or spam complaint against an email address.
	 *
	 * - If the address is a newsletter subscriber (pending or confirmed),
	 *   it's flipped to 'bounced' — see Subscriber::suppress() — so no
	 *   future issue is ever sent there again.
	 * - If the address belongs to an admitted artist, a per-artist bounce
	 *   counter (user meta) is incremented and surfaced on the admin
	 *   Members dashboard — an operator-visible signal that this artist's
	 *   review links, welcome mail, etc. may no longer be arriving.
	 * - Neither, one, or both may apply to the same address; every call is
	 *   a cheap no-op for whichever doesn't.
	 *
	 * @param string $email  The address the ESP/DSN reported as failed.
	 * @param string $type   'bounce' or 'complaint' — logging only, both are suppressed identically.
	 * @param string $source 'webhook' or 'imap' — logging only.
	 * @return array{subscriber_suppressed: bool, artist_id: int|null}
	 */
	public static function record( string $email, string $type = 'bounce', string $source = '' ): array {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return [ 'subscriber_suppressed' => false, 'artist_id' => null ];
		}

		$suppressed = Subscriber::suppress( $email );

		$artist_id = null;
		$user      = get_user_by( 'email', $email );
		if ( $user && Admission::is_admitted_artist( $user->ID ) ) {
			$artist_id = (int) $user->ID;
			$count     = (int) get_user_meta( $artist_id, '_agnosis_bounce_count', true );
			update_user_meta( $artist_id, '_agnosis_bounce_count', $count + 1 );
			update_user_meta( $artist_id, '_agnosis_bounce_last_at', current_time( 'mysql' ) );
		}

		// Only log when something actually happened — an event for an address
		// that matches neither a subscriber nor an artist (e.g. a long-departed
		// address, or a bounce for mail this plugin never sent) is expected
		// background noise, not worth a log line every time.
		if ( $suppressed || null !== $artist_id ) {
			Logger::info(
				sprintf(
					'%s%s recorded for <%s>%s%s.',
					ucfirst( $type ),
					'' !== $source ? " ({$source})" : '',
					$email,
					$suppressed ? ' — newsletter subscriber suppressed' : '',
					null !== $artist_id ? sprintf( ' — artist #%d bounce counter incremented', $artist_id ) : ''
				),
				'bounce'
			);
		}

		return [ 'subscriber_suppressed' => $suppressed, 'artist_id' => $artist_id ];
	}
}
