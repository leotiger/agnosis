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
	 * Collect every To:/Cc: address out of a webhook payload, lowercased and
	 * sanitized (fifth audit §5a). Shared by `Webhook::handle()` and
	 * `Parser::parse_webhook_payload()` — previously each defined its own,
	 * byte-identical copy of this exact parser (sixth audit §6).
	 *
	 * Mailgun's 'recipient' field is the single address its own routing
	 * matched, but 'To'/'Cc' carry the full raw header, which can list
	 * several addresses — e.g. a message to `community@` that also CCs a
	 * friend. Previously only 'recipient' (falling back to 'to') was ever
	 * checked, so intent was lost to header order.
	 *
	 * @param array<string, mixed> $payload Webhook POST payload.
	 * @return string[] Lowercased, sanitized email addresses.
	 */
	public static function recipient_addresses( array $payload ): array {
		$raw = [];
		foreach ( [ 'recipient', 'to', 'To', 'cc', 'Cc' ] as $key ) {
			if ( ! empty( $payload[ $key ] ) && is_string( $payload[ $key ] ) ) {
				$raw[] = $payload[ $key ];
			}
		}

		if ( empty( $raw ) ) {
			return [];
		}

		// Extract bare email addresses out of "Name <addr>, Name2 <addr2>" or a
		// plain comma-separated header string.
		preg_match_all( '/[^\s,<>"]+@[^\s,<>"]+/', implode( ',', $raw ), $matches );

		$addrs = array_map(
			static fn( $e ) => strtolower( sanitize_email( $e ) ),
			$matches[0]
		);

		return array_values( array_unique( array_filter( $addrs ) ) );
	}
}
