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
	 * content, rather than an original, curated submission (2026-07-15;
	 * forward detection widened 2026-07-14 — eighth audit §3b).
	 *
	 * Agnosis's own outbound mail (submission-review confirmations etc.)
	 * deliberately sets Reply-To back to the artist's own intake address
	 * (`Core\CommunityMailer::reply_to_header_for_post()`), specifically so
	 * hitting "reply" starts a new submission — but a mail client's default
	 * reply behaviour quotes the entire original message and prefixes the
	 * subject with "Re:", which is never what we want published verbatim:
	 * the quoted portion is not original content, and is often our own
	 * previous email verbatim. The policy (both this method's own name and
	 * the 0.9.25 changelog) has always been framed as "reply **or
	 * forward**", but until eighth-audit §3b nothing actually matched a
	 * forward as such — only a reply's own signals happened to also catch
	 * some forwards incidentally (e.g. Outlook reuses its reply separator
	 * for forwards too). Five independent signals, any one of which is a
	 * match:
	 *
	 * A match here is no longer an automatic reject on its own (eighth
	 * audit §3c, 2026-07-14) — the caller (`Email\Parser`) now runs a match
	 * through `extract_original_content()` below first, which pulls out
	 * whatever the sender actually wrote above the quoted/forwarded
	 * portion; only a message with NOTHING left after extraction (no
	 * original text, no attachment) is actually rejected. This method's own
	 * job stays exactly what its name says — detect — the accept/extract/
	 * reject decision lives one level up.
	 *
	 *   1. Subject starts with "Re:" or "Fwd:"/"Fw:" (any capitalisation,
	 *      optional space before the colon) — the standard reply/forward
	 *      prefix convention nearly every mail client adds.
	 *   2. Subject contains "[Agnosis]" anywhere — catches our own outbound
	 *      notification subjects (several of which are literally prefixed
	 *      this way) being replied to or forwarded verbatim without editing
	 *      the subject. Deliberately a DIFFERENT literal string than the
	 *      "[Biography]"/"[Event]"/"[Photo]"/"[Pure]" subject-line
	 *      indicators (PostCreator::INDICATORS) — no collision with that
	 *      unrelated, intentional feature.
	 *   3. Body contains a quoted-reply attribution line — either the
	 *      "On <date>, <name> <<email>> wrote:" style nearly every mail
	 *      client (Apple Mail, Gmail, etc.) inserts above quoted text, or
	 *      Outlook's "-----Original Message-----" separator (Outlook reuses
	 *      this same separator for a forward, so Outlook forwards were
	 *      already covered before this widening).
	 *   4. Body contains Gmail's own forward separator,
	 *      "---------- Forwarded message ---------" (hyphen count varies
	 *      slightly by client version, hence `-{2,}` rather than a fixed
	 *      count) — previously the one gap the policy's own docblock and
	 *      changelog claimed was covered but wasn't: a Gmail forward with no
	 *      "On … wrote:" line (i.e. the forwarder added no comment of their
	 *      own above the separator) sailed straight through.
	 *   5. Body contains Apple Mail/iOS Mail's own forward marker, "Begin
	 *      forwarded message:" — the same class of gap as #4, for the other
	 *      mail client the "On … wrote:" pattern's own docblock already
	 *      names as a reference implementation.
	 *
	 * False-positive risk is much smaller than it was before §3c: an artist
	 * who genuinely titles a piece "Re: Something" no longer loses the
	 * submission outright — extraction still finds their real body/subject
	 * content and processes it (their literal "Re: Something" title does
	 * get its "Re:" prefix stripped, the one remaining, deliberately
	 * accepted quirk of this policy). Only a message that resolves to
	 * NOTHING after extraction is rejected; that rejection email (see
	 * Publishing\Notification::on_submission_looks_like_reply()) tells the
	 * sender plainly to resend as a fresh, original message rather than a
	 * reply/forward, so even that remaining case is recoverable, not a
	 * silent loss.
	 *
	 * @param string $subject Message subject.
	 * @param string $body    Plain-text body.
	 */
	public static function is_reply_or_quote( string $subject, string $body ): bool {
		if ( preg_match( '/^\s*(re|fwd?|fw)\s*:/i', $subject ) ) {
			return true;
		}
		if ( false !== stripos( $subject, '[agnosis]' ) ) {
			return true;
		}
		foreach ( self::QUOTE_BODY_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $body ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Regex patterns matching a quoted-reply attribution line or a
	 * forward-message marker in a body — the shared source both
	 * is_reply_or_quote() (detection) and extract_original_content()
	 * (extraction, see below) match against, so the two can never drift
	 * apart on what counts as "quoted content starts here."
	 *
	 * @var string[]
	 */
	private const QUOTE_BODY_PATTERNS = [
		'/^on .{0,120}wrote:\s*$/mi',
		'/-{2,}\s*original message\s*-{2,}/i',
		'/-{2,}\s*forwarded message\s*-{2,}/i',
		'/^begin forwarded message:/mi',
	];

	/**
	 * Reply-above-quote / comment-above-forward extraction (eighth audit
	 * §3c, option (c) — the resolution chosen for the product tension
	 * between the 0.9.22 Reply-To "just hit reply to submit again" feature
	 * and the 0.9.25 blanket reply/forward rejection).
	 *
	 * Rather than outright rejecting every message is_reply_or_quote()
	 * flags, this pulls out whatever the sender actually wrote — their own
	 * comment above a reply's quoted thread, or above a forward's quoted
	 * original — so a message with genuine original content (or a genuine
	 * attachment) still gets published, while a message that's nothing but
	 * quoted/forwarded content with no comment of the sender's own still
	 * gets rejected exactly as before. This is a strict widening: it never
	 * changes what is_reply_or_quote() itself matches, only what happens
	 * once it matches.
	 *
	 * Two independent steps, always both applied when this is called (the
	 * caller is expected to have already confirmed is_reply_or_quote() —
	 * this method does not re-check it):
	 *
	 *   1. Subject: strip every leading "Re:"/"Fwd:"/"Fw:" prefix (looped,
	 *      so a doubly-prefixed "Re: Fwd: My piece" from a long thread
	 *      fully unwraps to "My piece", not just one layer) and remove
	 *      "[Agnosis]" anywhere in what's left, collapsing the resulting
	 *      whitespace.
	 *   2. Body: find the EARLIEST occurrence of any of the four
	 *      QUOTE_BODY_PATTERNS markers (a reply's "On … wrote:" line, a
	 *      reply/forward's "-----Original Message-----" separator, a
	 *      Gmail forward's own separator, or Apple Mail's "Begin forwarded
	 *      message:") and keep only what comes before it — the sender's
	 *      own comment, if any. When none of those markers actually appear
	 *      in the body (i.e. only the SUBJECT carried a reply/forward
	 *      signal — a "Re:"-prefixed subject on an otherwise clean,
	 *      unquoted body is a real, if unusual, case a bot-forwarded mail
	 *      client can produce), the entire body is kept as-is: there is
	 *      nothing to strip.
	 *
	 * The caller (Parser) is responsible for deciding whether the result is
	 * "enough" (non-empty extracted body, or a genuine attachment) — this
	 * method only extracts; it never itself decides accept-or-reject.
	 *
	 * @param string $subject Original message subject (not yet cleaned).
	 * @param string $body    Original plain-text body (not yet cleaned).
	 * @return array{subject: string, body: string}
	 */
	public static function extract_original_content( string $subject, string $body ): array {
		do {
			$before  = $subject;
			$subject = preg_replace( '/^\s*(re|fwd?|fw)\s*:\s*/i', '', $subject ) ?? $subject;
		} while ( $subject !== $before );

		$subject = str_ireplace( '[agnosis]', '', $subject );
		$subject = trim( (string) preg_replace( '/\s+/', ' ', $subject ) );

		$earliest_offset = null;
		foreach ( self::QUOTE_BODY_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $body, $matches, PREG_OFFSET_CAPTURE ) ) {
				$offset = $matches[0][1];
				if ( null === $earliest_offset || $offset < $earliest_offset ) {
					$earliest_offset = $offset;
				}
			}
		}

		$original_body = null === $earliest_offset ? $body : substr( $body, 0, $earliest_offset );

		return [
			'subject' => $subject,
			'body'    => trim( $original_body ),
		];
	}
}
