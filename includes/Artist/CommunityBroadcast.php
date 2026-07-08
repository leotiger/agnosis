<?php
/**
 * Community-to-community broadcast relay.
 *
 * An active artist can email the configured "Community announcement" alias
 * (Settings → Email → agnosis_email_community) to reach every other admitted
 * artist directly. This never touches PostCreator or the AI content
 * pipeline — no post is created, nothing is published. It exists for things
 * that don't belong on a public gallery page: coordinating a shared show,
 * a logistics question, a heads-up about a maintenance window — community
 * business, not artwork.
 *
 * Gated exactly like every submission alias: only a sender who resolves to an
 * admitted artist (Admission::is_admitted_artist()) can trigger a broadcast —
 * see Email\Inbox::handle_community_email() / Email\Webhook::handle(), which
 * both check this before ever calling broadcast() below — and only to the
 * rest of the community (the sender is excluded from their own broadcast).
 * A per-sender daily cap (agnosis_community_broadcast_limit, default 3) stops
 * one member from flooding every other member's inbox — the same
 * RateLimiter::check_sender() mechanism the artwork/bio/event intake aliases
 * already use for their own per-sender throttle, just a separate bucket.
 *
 * Each recipient gets the sender's subject and message translated into their
 * own account language (their WP `locale` user meta — the same field every
 * other per-recipient email in this plugin already switches to), via the
 * same lightweight AI\SubmissionTranslator::translate_text() call used
 * throughout (not the full generative content pipeline — there is nothing to
 * draft here, only to translate). A recipient who shares the sender's
 * language, or every recipient when no AI provider is configured, gets the
 * original text unchanged rather than blocking the whole broadcast on a
 * missing provider.
 *
 * Length cap (2026-07-08 correction): every recipient's copy costs one AI
 * translation call, so a single long message is not one AI cost but N of
 * them. Callers must check exceeds_max_length() before calling broadcast()
 * and, if it's true, call send_too_long_bounce() instead — see
 * Email\Inbox::handle_community_email() / Email\Webhook::handle(). Measured
 * in characters, not words: this is an international community, and
 * whitespace-delimited "word" counting is meaningless for Chinese, Japanese,
 * Thai, and other scripts that don't separate words with spaces — a
 * character count is the one measure that means roughly the same thing (and
 * costs roughly the same number of AI tokens) regardless of the sender's
 * script. Configurable via agnosis_community_broadcast_max_chars (Settings →
 * Community → Rules, default 2000), clamped to a hard, non-configurable
 * ceiling (HARD_CHAR_CEILING) so a mistyped huge setting can't reopen the
 * cost problem this exists to prevent.
 *
 * The sender's display name and email address are always included in the
 * body, for identification — but Reply-To is set to the community alias
 * itself (agnosis_email_community), NOT to the sender's own address. This is
 * deliberate (2026-07-08 correction): a reply sent directly to the sender
 * would arrive untranslated, in whatever language the replier wrote it —
 * useless if, say, a Chinese recipient replies to a Spanish sender. Routing
 * the reply back through the same alias means it re-enters this exact
 * pipeline and gets translated for everyone, including the original sender,
 * same as the original message. The configured Community sender address
 * (Settings → Community → Rules) is unrelated to this — it is only ever the
 * outgoing From: header here, exactly as it is for every other workflow
 * email (see Core\CommunityMailer).
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Compat\LinguaForge;
use Agnosis\Core\CommunityMailer;
use Agnosis\Core\Logger;

class CommunityBroadcast {

	/**
	 * Default value for agnosis_community_broadcast_max_chars when unset.
	 * ~300-400 words of Latin-script text; a genuinely different order of
	 * magnitude for CJK/Thai/etc. text, which is exactly why this is measured
	 * in characters rather than words — see class docblock.
	 */
	private const DEFAULT_MAX_CHARS = 2000;

	/**
	 * Absolute ceiling on agnosis_community_broadcast_max_chars, regardless of
	 * what the option is set to — belt-and-suspenders alongside the Settings
	 * field's own sanitize callback, in case the option is ever written some
	 * other way (direct DB edit, a future migration, etc.).
	 */
	private const HARD_CHAR_CEILING = 20000;

	/**
	 * The effective max length (characters), honouring the configured option
	 * but never exceeding HARD_CHAR_CEILING.
	 */
	public function max_length(): int {
		$configured = max( 1, (int) get_option( 'agnosis_community_broadcast_max_chars', self::DEFAULT_MAX_CHARS ) );
		return min( $configured, self::HARD_CHAR_CEILING );
	}

	/**
	 * Whether $subject + $body together exceed max_length(). Callers must
	 * check this BEFORE calling broadcast() — the whole point is to bail out
	 * before any AI translation calls are made, not after.
	 */
	public function exceeds_max_length( string $subject, string $body ): bool {
		// mb_strlen(), not strlen(): a byte count would penalise multi-byte
		// scripts (Chinese, Japanese, Arabic, etc.) relative to Latin text for
		// the exact same number of actual characters — the opposite of the
		// language-fairness this whole cap is meant to provide.
		return ( mb_strlen( $subject ) + mb_strlen( $body ) ) > $this->max_length();
	}

	/**
	 * Tell the sender their message was too long instead of broadcasting it.
	 *
	 * Sent in the sender's own account locale, same as every other
	 * per-recipient email in this plugin. Plain text, using the Community
	 * sender identity (Core\CommunityMailer) like every other workflow email.
	 *
	 * @param int $sender_id WP user ID of the artist who sent the message.
	 * @param int $length    Actual combined subject+body character count, for the message.
	 */
	public function send_too_long_bounce( int $sender_id, int $length ): void {
		$sender = get_userdata( $sender_id );
		if ( ! $sender ) {
			return;
		}

		$limit  = $this->max_length();
		$locale = (string) get_user_meta( $sender_id, 'locale', true );
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '[%s] Your community message was not sent — too long', 'agnosis' ),
			$site_name
		);

		$body = sprintf(
			/* translators: 1: sender's display name, 2: character count of their message, 3: configured character limit, 4: site name */
			__( "Hi %1\$s,\n\nYour message to the community was %2\$d characters long — the current limit is %3\$d. It was not sent to anyone.\n\nEvery recipient's copy is translated individually into their own language, so a very long message is costly to translate for the whole community. Please shorten it and send it again.\n\n— %4\$s", 'agnosis' ),
			$sender->display_name,
			$length,
			$limit,
			$site_name
		);

		wp_mail( $sender->user_email, $subject, $body, CommunityMailer::text_headers() );

		if ( '' !== $locale ) {
			restore_current_locale();
		}

		Logger::info(
			sprintf(
				'CommunityBroadcast: message from artist #%d was %d characters (limit %d) — bounced, not broadcast.',
				$sender_id,
				$length,
				$limit
			),
			'community-broadcast'
		);
	}

	/**
	 * Relay $subject/$body from $sender_id to every other admitted artist.
	 *
	 * Callers (Email\Inbox / Email\Webhook) are responsible for verifying the
	 * sender is an admitted artist and that the message is within
	 * max_length() before calling this — it does not repeat either check
	 * itself, since both callers already resolve the WP user and gate on it
	 * as part of matching the community alias.
	 *
	 * @param int    $sender_id WP user ID of the artist who sent the message.
	 * @param string $subject   Original message subject, artist's own language.
	 * @param string $body      Original message body, artist's own language.
	 * @return int Number of recipients the message was actually sent to.
	 */
	public function broadcast( int $sender_id, string $subject, string $body ): int {
		$sender = get_userdata( $sender_id );
		if ( ! $sender ) {
			return 0;
		}

		$subject = trim( $subject );
		$body    = trim( $body );

		if ( '' === $subject && '' === $body ) {
			return 0;
		}

		$recipients = get_users( [
			'role'    => 'agnosis_artist',
			'exclude' => [ $sender_id ],
			'fields'  => [ 'ID', 'user_email', 'display_name' ],
		] );

		if ( empty( $recipients ) ) {
			return 0;
		}

		// The sender's own account locale is the best available signal for what
		// language they wrote in — the same field Admission::apply() sets at
		// signup and every other per-recipient email in this plugin already
		// reads for the opposite direction (recipient locale).
		$sender_locale = (string) get_user_meta( $sender_id, 'locale', true );
		$sender_lang   = '' !== $sender_locale ? LinguaForge::locale_to_lang( $sender_locale ) : '';

		// Resolved once, not per-recipient — from_settings() reads the same
		// site-wide provider config regardless of who the email is going to.
		$translator = SubmissionTranslator::from_settings();

		$sent = 0;
		foreach ( $recipients as $recipient ) {
			$recipient_id = (int) $recipient->ID;
			$locale       = (string) get_user_meta( $recipient_id, 'locale', true );

			if ( '' !== $locale ) {
				switch_to_locale( $locale );
			}

			$target_lang        = '' !== $locale ? LinguaForge::locale_to_lang( $locale ) : '';
			$translated_subject = $subject;
			$translated_body    = $body;

			$needs_translation = null !== $translator
				&& '' !== $sender_lang
				&& '' !== $target_lang
				&& $target_lang !== $sender_lang;

			if ( $needs_translation ) {
				if ( '' !== $subject ) {
					$translated_subject = $translator->translate_text( $subject, $target_lang );
				}
				if ( '' !== $body ) {
					$translated_body = $translator->translate_text( $body, $target_lang );
				}
			}

			$this->send_one( $recipient->user_email, $sender, $translated_subject, $translated_body );
			++$sent;

			if ( '' !== $locale ) {
				restore_current_locale();
			}
		}

		Logger::info(
			sprintf(
				'CommunityBroadcast: relayed message from artist #%d to %d recipient(s).',
				$sender_id,
				$sent
			),
			'community-broadcast'
		);

		return $sent;
	}

	/**
	 * Send one already-localised copy of the broadcast to one recipient.
	 *
	 * Plain text, matching every other artist-to-artist notification in this
	 * plugin (Departure/CommunityCap vote emails) — there is no HTML template
	 * to reuse here and nothing visual to convey.
	 */
	private function send_one( string $to, \WP_User $sender, string $subject, string $body ): void {
		$site_name = get_bloginfo( 'name' );

		$mail_subject = sprintf(
			/* translators: 1: site name, 2: sender's display name */
			__( '[%1$s] Community message from %2$s', 'agnosis' ),
			$site_name,
			$sender->display_name
		);

		$lines   = [];
		$lines[] = sprintf(
			/* translators: 1: sender's display name, 2: sender's email address */
			__( '%1$s <%2$s> sent a message to the community:', 'agnosis' ),
			$sender->display_name,
			$sender->user_email
		);
		$lines[] = '';

		if ( '' !== $subject ) {
			/* translators: %s: message subject */
			$lines[] = sprintf( __( 'Subject: %s', 'agnosis' ), $subject );
			$lines[] = '';
		}

		if ( '' !== $body ) {
			$lines[] = $body;
			$lines[] = '';
		}

		$community_addr = trim( (string) get_option( 'agnosis_email_community', '' ) );

		if ( '' !== $community_addr ) {
			$lines[] = __( 'Hit reply to respond — your reply goes back to the whole community, translated automatically, just like this message.', 'agnosis' );
		} else {
			// No intake alias configured (shouldn't normally happen — broadcast()
			// is only ever reached via that alias — but handled gracefully rather
			// than pointing a reply nowhere useful).
			/* translators: %s: sender's display name */
			$lines[] = sprintf( __( 'This message was sent by %s.', 'agnosis' ), $sender->display_name );
		}
		$lines[] = '';
		$lines[] = $site_name;

		$headers = CommunityMailer::text_headers();
		if ( '' !== $community_addr ) {
			// Reply-To is the community alias itself, NOT the sender's own address
			// (2026-07-08 correction) — a direct reply would arrive in whatever
			// language the replier wrote it, unreadable to a sender who doesn't
			// share that language. Routing back through the alias re-enters this
			// same pipeline, so the reply gets translated for everyone too.
			$headers[] = sprintf(
				'Reply-To: %s <%s>',
				sprintf(
					/* translators: 1: sender's display name, 2: site name */
					__( '%1$s via %2$s', 'agnosis' ),
					$sender->display_name,
					$site_name
				),
				$community_addr
			);
		}

		wp_mail( $to, $mail_subject, implode( "\n", $lines ), $headers );
	}
}
