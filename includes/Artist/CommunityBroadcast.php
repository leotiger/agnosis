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
 * Translation is grouped by target language, not done once per recipient
 * (fifth audit §4a) — broadcast() buckets recipients by their target
 * language first and translates each distinct language exactly once, so
 * twenty Spanish-speaking members cost one Spanish translation, not twenty
 * byte-identical ones (N recipients × 2 calls → L languages × 2 calls). Every
 * recipient in a group still gets their own account locale switched for the
 * surrounding template chrome in send_one() — grouping only changes how many
 * times the AI is asked to translate the same words, not what any individual
 * recipient receives.
 *
 * Length cap (2026-07-08 correction): a single long message is still not a
 * fixed, one-time AI cost — even grouped by language (§4a above), it's L
 * translation calls, one per distinct language among the recipients, so a
 * long message on a multi-language community is still a multiplied cost, just
 * by language count rather than recipient count. Callers must check
 * exceeds_max_length() before calling broadcast()
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
 * same as the original message. The configured "Mail from:" sender address
 * (Settings → Email) is unrelated to this — it is only ever the
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
use Agnosis\Core\EmailFooter;
use Agnosis\Core\EmailTemplate;
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
	 * per-recipient email in this plugin. HTML, via the shared
	 * Core\EmailTemplate shell (2026-07-15 — audit-adjacent finding, not a
	 * numbered audit item; see CHANGELOG.md 0.9.29), using the Community
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

		wp_mail(
			$sender->user_email,
			$subject,
			$this->build_too_long_bounce_body( $sender->display_name, $length, $limit ),
			$this->html_headers()
		);

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

	private function build_too_long_bounce_body( string $display_name, int $length, int $limit ): string {
		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf( /* translators: %s: recipient's display name */ esc_html__( 'Hi %s,', 'agnosis' ), esc_html( $display_name ) )
			. '</p>'
			. '<p style="margin:0 0 16px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: 1: character count of the sent message, 2: configured character limit */
				esc_html__( 'Your message to the community was %1$d characters long — the current limit is %2$d. It was not sent to anyone.', 'agnosis' ),
				absint( $length ),
				absint( $limit )
			)
			. '</p>'
			. '<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">'
			. esc_html__( 'Every recipient\'s copy is translated individually into their own language, so a very long message is costly to translate for the whole community. Please shorten it and send it again.', 'agnosis' )
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
	}

	/**
	 * Tell the sender their message had no usable content instead of silently
	 * dropping it (fifth/sixth audit §2c — parity with send_too_long_bounce()
	 * above: a too-long message already got an explanation; an empty one
	 * — e.g. an artist whose mail client sent an HTML-only body the parser
	 * reduced to nothing — previously got only silence, recorded internally
	 * as 'community_empty' with no reply at all, so the artist believed
	 * their announcement went out).
	 *
	 * Sent in the sender's own account locale, same as every other
	 * per-recipient email in this plugin.
	 *
	 * @param int $sender_id WP user ID of the artist whose message was empty.
	 */
	public function send_empty_bounce( int $sender_id ): void {
		$sender = get_userdata( $sender_id );
		if ( ! $sender ) {
			return;
		}

		$locale = (string) get_user_meta( $sender_id, 'locale', true );
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '[%s] Your community message was not sent — no content found', 'agnosis' ),
			$site_name
		);

		wp_mail(
			$sender->user_email,
			$subject,
			$this->build_empty_bounce_body( $sender->display_name ),
			$this->html_headers()
		);

		if ( '' !== $locale ) {
			restore_current_locale();
		}

		Logger::info(
			sprintf( 'CommunityBroadcast: message from artist #%d had no usable subject or body — bounced, not broadcast.', $sender_id ),
			'community-broadcast'
		);
	}

	private function build_empty_bounce_body( string $display_name ): string {
		$body = '<p style="margin:0 0 20px;font-size:18px;color:#555;">'
			. sprintf( /* translators: %s: recipient's display name */ esc_html__( 'Hi %s,', 'agnosis' ), esc_html( $display_name ) )
			. '</p>'
			. '<p style="margin:0 0 16px;font-size:18px;line-height:1.6;color:#555;">'
			. esc_html__( 'Your message to the community had no subject or message text that could be found — this often happens when an email client sends an HTML-only message with no plain-text version included. It was not sent to anyone.', 'agnosis' )
			. '</p>'
			. '<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">'
			. esc_html__( 'Please try sending it again with plain text included.', 'agnosis' )
			. '</p>';

		return EmailTemplate::render( $this->html_lang(), $body );
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

		// Excludes an artist who has muted community broadcasts (security audit
		// §5b — the practical alternative to muting was the spam button, the
		// single worst outcome for the shared domain's reputation). Same
		// OR/NOT EXISTS meta_query shape as Newsletter\Scheduler::artist_recipients()'s
		// newsletter opt-out, applied to the separate `_agnosis_broadcast_optout`
		// flag — muting broadcasts says nothing about the newsletter or vote
		// emails, which are independent preferences (see also
		// AdmissionNotification::on_application_received()'s vote-email-mode filter).
		$recipients = get_users( [
			'role'       => 'agnosis_artist',
			'exclude'    => [ $sender_id ],
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small table (admitted artists only), acceptable.
				'relation' => 'OR',
				[ 'key' => '_agnosis_broadcast_optout', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_agnosis_broadcast_optout', 'value' => '1', 'compare' => '!=' ],
			],
			'fields'     => [ 'ID', 'user_email', 'display_name' ],
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

		// ---- Group recipients by target language (fifth audit §4a) -----------
		// Previously this loop called translate_text() (subject + body) once
		// per RECIPIENT — twenty Spanish-speaking members meant twenty separate,
		// byte-identical Spanish translations. Recipients are grouped by target
		// language first so each distinct language is translated exactly once
		// below (N recipients × 2 calls → L languages × 2 calls), then every
		// recipient in a group reuses that group's copy — no feature change,
		// since a "broadcast" already implies identical wording for recipients
		// who share a language. switch_to_locale() still happens per recipient,
		// not per group: that's template chrome (the "Subject:"/"Hit reply…"
		// wrapper strings in send_one()), which reads WP's full locale (e.g.
		// es_ES vs es_MX) — a finer distinction than the 2-letter language code
		// translation is grouped by, so two recipients can share a translation
		// group while still getting their own locale's chrome.
		$no_translation_key = '__original__'; // Sentinel: not a valid ISO 639-1 code, so it can never collide with a real target language group.
		$groups             = []; // lang code (or the sentinel above) => list of ['recipient' => WP_User-like, 'locale' => string]

		foreach ( $recipients as $recipient ) {
			$recipient_id = (int) $recipient->ID;
			$locale       = (string) get_user_meta( $recipient_id, 'locale', true );
			$target_lang  = '' !== $locale ? LinguaForge::locale_to_lang( $locale ) : '';

			$needs_translation = null !== $translator
				&& '' !== $sender_lang
				&& '' !== $target_lang
				&& $target_lang !== $sender_lang;

			$group_key            = $needs_translation ? $target_lang : $no_translation_key;
			$groups[ $group_key ][] = [ 'recipient' => $recipient, 'locale' => $locale ];
		}

		$sent = 0;
		foreach ( $groups as $group_key => $members ) {
			if ( $no_translation_key === $group_key ) {
				$translated_subject = $subject;
				$translated_body    = $body;
			} else {
				// One call per field, per language group — not per recipient.
				// $translator is guaranteed non-null here: $group_key can only be
				// a real language code (never $no_translation_key) when
				// $needs_translation was true above, which itself requires
				// `null !== $translator` — re-checked explicitly anyway so static
				// analysis doesn't have to infer that invariant across the loops.
				$translated_subject = ( '' !== $subject && null !== $translator ) ? $translator->translate_text( $subject, $group_key ) : $subject;
				$translated_body    = ( '' !== $body && null !== $translator ) ? $translator->translate_text( $body, $group_key ) : $body;
			}

			foreach ( $members as $member ) {
				$recipient = $member['recipient'];
				$locale    = $member['locale'];

				if ( '' !== $locale ) {
					switch_to_locale( $locale );
				}

				$this->send_one( $recipient->user_email, (int) $recipient->ID, $sender, $translated_subject, $translated_body );
				++$sent;

				if ( '' !== $locale ) {
					restore_current_locale();
				}
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
	 * HTML, via the shared Core\EmailTemplate shell (2026-07-15 —
	 * audit-adjacent finding, not a numbered audit item; see CHANGELOG.md
	 * 0.9.29) — matching every other artist-to-artist notification in the
	 * plugin now that all of them share one template. $subject and $body
	 * are the SENDER'S OWN content (translated, but otherwise theirs), not
	 * plugin copy — escaped with esc_html()/nl2br(), never esc_html__(),
	 * since there is no msgid for arbitrary artist-written text.
	 */
	private function send_one( string $to, int $recipient_id, \WP_User $sender, string $subject, string $body ): void {
		$site_name = get_bloginfo( 'name' );

		$mail_subject = sprintf(
			/* translators: 1: site name, 2: sender's display name */
			__( '[%1$s] Community message from %2$s', 'agnosis' ),
			$site_name,
			$sender->display_name
		);

		$email_body = '<p style="margin:0 0 20px;font-size:18px;line-height:1.6;color:#555;">'
			. sprintf(
				/* translators: 1: sender's display name, 2: sender's email address */
				esc_html__( '%1$s <%2$s> sent a message to the community:', 'agnosis' ),
				esc_html( $sender->display_name ),
				esc_html( $sender->user_email )
			)
			. '</p>';

		if ( '' !== $subject ) {
			$email_body .= '<p style="margin:0 0 16px;font-size:17px;color:#555;"><strong>'
				. sprintf( /* translators: %s: message subject */ esc_html__( 'Subject: %s', 'agnosis' ), esc_html( $subject ) )
				. '</strong></p>';
		}

		if ( '' !== $body ) {
			$email_body .= '<p style="margin:0 0 24px;font-size:17px;line-height:1.6;color:#555;padding:14px 16px;background:#f9f9f9;border-left:3px solid ' . esc_attr( EmailTemplate::accent() ) . ';border-radius:4px;">'
				. nl2br( esc_html( $body ) )
				. '</p>';
		}

		$community_addr = trim( (string) get_option( 'agnosis_email_community', '' ) );

		if ( '' !== $community_addr ) {
			$email_body .= '<p style="margin:0;font-size:15px;color:#999;">'
				. esc_html__( 'Hit reply to respond — your reply goes back to the whole community, translated automatically, just like this message.', 'agnosis' )
				. '</p>';
		} else {
			// No intake alias configured (shouldn't normally happen — broadcast()
			// is only ever reached via that alias — but handled gracefully rather
			// than pointing a reply nowhere useful).
			$email_body .= '<p style="margin:0;font-size:15px;color:#999;">'
				. sprintf( /* translators: %s: sender's display name */ esc_html__( 'This message was sent by %s.', 'agnosis' ), esc_html( $sender->display_name ) )
				. '</p>';
		}

		// Security audit §5b/§4a: a recipient annoyed by broadcast volume
		// otherwise has no dial short of the spam button. Empty when
		// $recipient_id somehow isn't an artist (EmailFooter::is_artist() —
		// shouldn't happen here since broadcast()'s own query is role-scoped,
		// but handled gracefully rather than assumed).
		$prefs_html   = EmailFooter::preferences_html( $recipient_id );
		$footer_extra = '' !== $prefs_html ? '<p style="margin:12px 0 0;text-align:center;">' . $prefs_html . '</p>' : '';

		$headers = $this->html_headers();

		// Anti-loop headers (fourth audit §3c) — this is a bulk copy sent to
		// every other admitted artist, and Reply-To below deliberately points
		// back at the community alias itself (needed so a reply gets translated
		// for everyone). Without these, a recipient's vacation auto-responder
		// fires on THIS message and its reply lands right back on the alias,
		// re-entering the broadcast pipeline as if it were a genuine new
		// message — these three headers suppress the auto-responder at the
		// recipient's own mail server before that can happen. Only added here,
		// not in CommunityMailer::text_headers()/html_headers(), since those are
		// shared by one-off transactional emails (vote links, welcome,
		// departure) that were never the mail-loop risk this addresses.
		$headers[] = 'Auto-Submitted: auto-generated';
		$headers[] = 'Precedence: bulk';
		$headers[] = 'X-Auto-Response-Suppress: All';

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

		wp_mail( $to, $mail_subject, EmailTemplate::render( $this->html_lang(), $email_body, $footer_extra ), $headers );
	}

	/**
	 * Headers for every email in this class.
	 *
	 * Every email this class sends is now HTML, built through the shared
	 * Core\EmailTemplate shell (2026-07-15) — see the class docblock's
	 * "Length cap" section's history for why this class ever needed its own
	 * sender-identity delegation in the first place (Core\CommunityMailer,
	 * not WordPress's own default).
	 *
	 * @return array<string>
	 */
	private function html_headers(): array {
		return CommunityMailer::html_headers();
	}

	/**
	 * Return the BCP 47 language tag for use in the HTML <html lang="…">
	 * attribute. Must be called AFTER switch_to_locale() so get_locale()
	 * returns the recipient's locale, not the site locale — mirrors
	 * Publishing\Notification::html_lang().
	 */
	private function html_lang(): string {
		$locale = get_locale();
		return $locale ? str_replace( '_', '-', $locale ) : 'en';
	}
}
