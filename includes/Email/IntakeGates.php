<?php
/**
 * Shared intake logic used by both mail transports (IMAP and webhook), so
 * the *next* goodbye@/community@-style alias feature physically cannot drift
 * the way the recipient-address parser and the alias reason/status ledgers
 * already had, twice, across audit cycles (fourth and fifth audit §3d/§5a;
 * called out as "the third transport-parity episode" in the sixth audit §6).
 *
 * This class deliberately holds only the pieces that are genuinely
 * transport-agnostic:
 *
 *   - recipient_addresses() — parses To:/Cc: addresses out of a raw webhook
 *     POST payload array. Email\Inbox's own IMAP-side equivalent
 *     (message_recipient_addresses()) takes a real Webklex\PHPIMAP\Message
 *     object and calls its getTo()/getCc() methods — a fundamentally
 *     different input shape this regex-based parser can't serve, so that one
 *     stays where it is; only the two payload-array parsers (previously
 *     duplicated byte-for-byte between Webhook.php and Parser.php) move here.
 *   - SHARED_ALIAS_REASONS / SHARED_ALIAS_STATUSES — the goodbye@/community@
 *     reason strings and status overrides both Webhook::ALIAS_REASONS/
 *     ALIAS_STATUSES and Inbox::SKIP_REASONS/SKIP_STATUSES need verbatim.
 *     Each transport still declares its own full constant (composed from
 *     these plus whatever is transport-specific — Inbox additionally has
 *     five IMAP-only submission-gate reasons and two bounce/DSN reasons with
 *     no webhook equivalent), so every existing caller keeps reading
 *     `Webhook::ALIAS_REASONS[...]` / `Inbox::SKIP_REASONS[...]` exactly as
 *     before; only the *shared* subset now has one place to edit instead of
 *     two hand-maintained copies.
 *
 * Pure refactor (sixth audit §6) — no behavior change. Both transports'
 * recipient parsing, reason text, and status overrides are unchanged; only
 * where the logic lives moved.
 *
 * @package Agnosis\Email
 */

declare(strict_types=1);

namespace Agnosis\Email;

class IntakeGates {

	/**
	 * Human-readable text for every goodbye@/community@ alias outcome that
	 * BOTH transports can reach — see class docblock. `Webhook::ALIAS_REASONS`
	 * is exactly this array; `Inbox::SKIP_REASONS` is this array plus its own
	 * IMAP-only gate and bounce/DSN reasons, spliced in around it (via the
	 * array `+` operator) so the resulting iteration order — and therefore
	 * the Inbox admin table's reason-filter dropdown order — is unchanged
	 * from before this refactor.
	 *
	 * @var array<string, string>
	 */
	public const SHARED_ALIAS_REASONS = [
		'goodbye_non_artist'        => 'Skipped: goodbye request from a non-artist sender.',
		'goodbye_handled'           => 'Goodbye request processed — self-removal confirmation sent.',
		'goodbye_no_membership'     => 'Goodbye request could not be processed — no active membership found for this sender.',
		'goodbye_throttled'         => 'Skipped: sender exceeded the daily self-removal (goodbye) request limit.',
		'community_non_artist'      => 'Skipped: community broadcast request from a non-artist sender.',
		'community_throttled'       => 'Skipped: sender exceeded the daily community broadcast limit.',
		'community_empty'           => 'Community broadcast had no subject or body text — bounced back to sender, not broadcast.',
		'community_auto_submitted'  => 'Skipped: message looked like an automated response (Auto-Submitted header), not a genuine community message.',
		'community_too_long'        => 'Community broadcast exceeded the configured length limit — bounced back to sender, not broadcast.',
		'community_handled'         => 'Community broadcast processed — sent to every other community member.',
	];

	/**
	 * Per-reason queue-row status override for the subset of the reasons
	 * above that represent a genuine success rather than a rejection —
	 * reasons not listed here default to 'failed'. See SHARED_ALIAS_REASONS's
	 * docblock for how each transport composes its own full constant from
	 * this shared one.
	 *
	 * @var array<string, string>
	 */
	public const SHARED_ALIAS_STATUSES = [
		'goodbye_handled'    => 'skipped',
		'community_handled'  => 'skipped',
		'community_too_long' => 'skipped',
	];

	/**
	 * The payload's single PRIMARY recipient, lowercased and sanitized
	 * (2026-07-15). Shared by `Webhook::handle()` and
	 * `Parser::parse_webhook_payload()` — previously each defined its own,
	 * byte-identical copy of this exact parser (sixth audit §6).
	 *
	 * Prefers Mailgun's 'recipient' field (the single address its own routing
	 * already matched — inherently "primary", nothing to disambiguate) when
	 * present; otherwise takes only the FIRST address out of the raw 'to'/'To'
	 * header. 'cc'/'Cc' is never read at all — this intentionally reverses
	 * the "fifth audit §5a" broadening (which matched every To:/Cc: address so
	 * a message reaching an alias only via Cc:, or a non-first To: address,
	 * wouldn't silently fall through to the plain artwork pipeline) per
	 * explicit product policy: no CC, and only the first/primary To: address
	 * counts — see Inbox::message_recipient_addresses()'s identical policy on
	 * the IMAP side.
	 *
	 * Still returns an array (not a bare string) purely so every existing
	 * caller (goodbye/community `in_array()` checks, is_recognized_recipient())
	 * keeps working unchanged — the array just never holds more than one
	 * address now.
	 *
	 * @param array<string, mixed> $payload Webhook POST payload.
	 * @return string[] Zero or one lowercased, sanitized email address.
	 */
	public static function recipient_addresses( array $payload ): array {
		if ( ! empty( $payload['recipient'] ) && is_string( $payload['recipient'] ) ) {
			$addr = strtolower( sanitize_email( trim( $payload['recipient'] ) ) );
			return '' !== $addr ? [ $addr ] : [];
		}

		foreach ( [ 'to', 'To' ] as $key ) {
			if ( ! empty( $payload[ $key ] ) && is_string( $payload[ $key ] ) ) {
				// First bare email address out of "Name <addr>, Name2 <addr2>" or a
				// plain comma-separated header string — everything after the first
				// is a secondary recipient and no longer counts (see docblock above).
				preg_match( '/[^\s,<>"]+@[^\s,<>"]+/', $payload[ $key ], $m );
				if ( ! empty( $m[0] ) ) {
					$addr = strtolower( sanitize_email( $m[0] ) );
					return '' !== $addr ? [ $addr ] : [];
				}
			}
		}

		return [];
	}

	/**
	 * Every address this site actually recognises as its own — every
	 * Settings → Email routing address that's actually been configured
	 * (submit@/bio@/event@/replace@/remove@/promote@/photo@/pure@/goodbye@/
	 * community@), plus the IMAP mailbox's own login address when it looks
	 * like a real email (2026-07-14) — some installs route everything
	 * through a single address plus subject-line [Indicator] prefixes rather
	 * than a distinct To: alias per type (see the Photo/Pure Settings field
	 * descriptions), so genuine mail may be addressed directly to the
	 * mailbox account rather than to any of the ten routing settings.
	 *
	 * Used by Inbox::process_messages() to reject a message whose primary
	 * (first To:) recipient matches none of these — see
	 * is_recognized_recipient() below.
	 *
	 * @return string[] Lowercased, trimmed, non-empty addresses. Empty array
	 *                   when nothing is configured at all.
	 */
	public static function known_addresses(): array {
		$addresses = array_map(
			static fn( $option ) => strtolower( trim( (string) get_option( $option, '' ) ) ),
			[
				'agnosis_email_submit',
				'agnosis_email_bio',
				'agnosis_email_event',
				'agnosis_email_replace',
				'agnosis_email_remove',
				'agnosis_email_promote',
				'agnosis_email_photo',
				'agnosis_email_pure',
				'agnosis_email_goodbye',
				'agnosis_email_community',
			]
		);

		$imap_user = strtolower( trim( (string) get_option( 'agnosis_imap_user', '' ) ) );
		if ( is_email( $imap_user ) ) {
			$addresses[] = $imap_user;
		}

		return array_values( array_unique( array_filter( $addresses ) ) );
	}

	/**
	 * Whether $recipients — a message's own To:/Cc: addresses — contains at
	 * least one address this site recognises as its own (2026-07-14).
	 *
	 * A message that matches none of them never arrived via a genuine direct
	 * send to a known endpoint: it was BCC'd, or its real recipient was
	 * otherwise stripped before we ever saw it (see Inbox::process_messages()'s
	 * own docblock for why this is IMAP-specific — there is no equivalent
	 * ambiguity on the webhook transport, where the provider's own routing
	 * rule already matched a configured address before our endpoint is ever
	 * invoked at all).
	 *
	 * Deliberately permissive when known_addresses() itself is empty: an
	 * unconfigured or still-being-set-up site has nothing to validate
	 * against, and treating every message as unrecognised in that state would
	 * silently discard genuine submissions rather than flag actual BCC mail.
	 *
	 * @param string[] $recipients This message's own primary recipient (zero or one address).
	 */
	public static function is_recognized_recipient( array $recipients ): bool {
		$known = self::known_addresses();
		if ( empty( $known ) ) {
			return true;
		}
		return (bool) array_intersect( $recipients, $known );
	}

	/**
	 * Whether a message looks like a reply or a forward carrying quoted
	 * content, rather than an original, curated submission (2026-07-15).
	 *
	 * Agnosis's own outbound mail (submission-review confirmations etc.)
	 * deliberately sets Reply-To back to the artist's own intake address
	 * (`Core\CommunityMailer::reply_to_header_for_post()`), specifically so
	 * hitting "reply" starts a new submission — but a mail client's default
	 * reply behaviour quotes the entire original message and prefixes the
	 * subject with "Re:", which is never what we want published: it's not
	 * original content, and the quoted portion is often our own previous
	 * email verbatim. Three independent signals, any one of which rejects:
	 *
	 *   1. Subject starts with "Re:" (any capitalisation, optional space
	 *      before the colon) — the standard reply-prefix convention nearly
	 *      every mail client adds.
	 *   2. Subject contains "[Agnosis]" anywhere — catches our own outbound
	 *      notification subjects (several of which are literally prefixed
	 *      this way) being replied to verbatim without editing the subject.
	 *      Deliberately a DIFFERENT literal string than the "[Biography]"/
	 *      "[Event]"/"[Photo]"/"[Pure]" subject-line indicators
	 *      (PostCreator::INDICATORS) — no collision with that unrelated,
	 *      intentional feature.
	 *   3. Body contains a quoted-reply attribution line — either the
	 *      "On <date>, <name> <<email>> wrote:" style nearly every mail
	 *      client (Apple Mail, Gmail, etc.) inserts above quoted text, or
	 *      Outlook's "-----Original Message-----" separator.
	 *
	 * False-positive risk is real and deliberately accepted per explicit
	 * product policy: an artist who genuinely titles a piece "Re: Something"
	 * would be rejected too. The rejection email (see
	 * Publishing\Notification::on_submission_looks_like_reply()) tells the
	 * sender plainly to resend as a fresh, original message rather than a
	 * reply, so this is recoverable, not a silent loss.
	 *
	 * @param string $subject Message subject.
	 * @param string $body    Plain-text body.
	 */
	public static function is_reply_or_quote( string $subject, string $body ): bool {
		if ( preg_match( '/^\s*re\s*:/i', $subject ) ) {
			return true;
		}
		if ( false !== stripos( $subject, '[agnosis]' ) ) {
			return true;
		}
		if ( preg_match( '/^on .{0,120}wrote:\s*$/mi', $body ) ) {
			return true;
		}
		if ( preg_match( '/-{2,}\s*original message\s*-{2,}/i', $body ) ) {
			return true;
		}
		return false;
	}
}
