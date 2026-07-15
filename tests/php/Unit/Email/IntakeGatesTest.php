<?php
/**
 * Unit tests for Email\IntakeGates — the shared intake-gate helper extracted
 * to close the "third transport-parity episode" (sixth audit §6):
 * Webhook::webhook_recipient_addresses() and Parser::extract_recipient_addresses()
 * were byte-identical private copies of the same regex, and
 * Webhook::ALIAS_REASONS/ALIAS_STATUSES mirrored Inbox::SKIP_REASONS/
 * SKIP_STATUSES by hand-maintained convention. Both are now sourced from
 * this one class.
 *
 * Pure refactor — no behavior change. These tests cover recipient_addresses()
 * directly (previously covered indirectly, once inside WebhookSignatureTest/
 * WebhookAliasEventCoverageTest and once inside ParserTest, as two separate
 * private methods) and confirm the shared reason/status constants have the
 * exact content the two transports' own public constants are composed from.
 *
 * @package Agnosis\Tests\Unit\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Email;

use Agnosis\Email\Inbox;
use Agnosis\Email\IntakeGates;
use Agnosis\Email\Webhook;
use PHPUnit\Framework\TestCase;

class IntakeGatesTest extends TestCase {

	// =========================================================================
	// recipient_addresses()
	// =========================================================================

	public function test_prefers_recipient_field_when_present(): void {
		$addrs = IntakeGates::recipient_addresses( [ 'recipient' => 'community@example.com' ] );

		$this->assertSame( [ 'community@example.com' ], $addrs );
	}

	public function test_falls_back_to_to_field(): void {
		$addrs = IntakeGates::recipient_addresses( [ 'to' => 'goodbye@example.com' ] );

		$this->assertSame( [ 'goodbye@example.com' ], $addrs );
	}

	/**
	 * 2026-07-15: reverses the "fifth audit §5a" broadening this test used to
	 * cover — Cc: is no longer read at all, and only the FIRST address in
	 * 'To'/'To' counts, even when 'recipient' is also present (which always
	 * wins outright, since it's inherently Mailgun's already-disambiguated
	 * single routed address). No CC, no secondary To: recipient — see
	 * IntakeGates::recipient_addresses()'s own docblock.
	 */
	public function test_ignores_cc_and_any_secondary_to_address(): void {
		$addrs = IntakeGates::recipient_addresses( [
			'recipient' => 'primary@example.com',
			'To'        => 'primary@example.com, other@example.com',
			'Cc'        => 'cc-one@example.com',
		] );

		$this->assertSame( [ 'primary@example.com' ], $addrs );
	}

	public function test_takes_only_the_first_to_address_when_recipient_field_is_absent(): void {
		$addrs = IntakeGates::recipient_addresses( [ 'To' => 'first@example.com, second@example.com' ] );

		$this->assertSame( [ 'first@example.com' ], $addrs );
	}

	public function test_ignores_cc_entirely_when_recipient_and_to_are_both_absent(): void {
		$addrs = IntakeGates::recipient_addresses( [ 'Cc' => 'cc-only@example.com' ] );

		$this->assertSame( [], $addrs );
	}

	public function test_extracts_bare_address_out_of_display_name_format(): void {
		$addrs = IntakeGates::recipient_addresses( [ 'To' => 'A Friend <friend@example.com>' ] );

		$this->assertSame( [ 'friend@example.com' ], $addrs );
	}

	public function test_lowercases_addresses(): void {
		$addrs = IntakeGates::recipient_addresses( [ 'recipient' => 'Community@EXAMPLE.com' ] );

		$this->assertSame( [ 'community@example.com' ], $addrs );
	}

	public function test_deduplicates_an_address_appearing_in_multiple_fields(): void {
		$addrs = IntakeGates::recipient_addresses( [
			'recipient' => 'same@example.com',
			'to'        => 'same@example.com',
		] );

		$this->assertSame( [ 'same@example.com' ], $addrs );
	}

	public function test_returns_empty_array_when_no_recognised_fields_present(): void {
		$this->assertSame( [], IntakeGates::recipient_addresses( [] ) );
		$this->assertSame( [], IntakeGates::recipient_addresses( [ 'subject' => 'No recipient fields here' ] ) );
	}

	public function test_ignores_a_non_string_field_value(): void {
		// Some webhook providers could conceivably send an array/object for a
		// header field on a malformed payload — must not fatal, just skip it.
		$addrs = IntakeGates::recipient_addresses( [ 'to' => [ 'not', 'a', 'string' ] ] );

		$this->assertSame( [], $addrs );
	}

	// =========================================================================
	// SHARED_ALIAS_REASONS / SHARED_ALIAS_STATUSES
	// =========================================================================

	/**
	 * Every reason here must be a genuinely shared goodbye@/community@
	 * outcome both transports can reach — none of the IMAP-only submission-gate
	 * reasons (unregistered_sender, not_admitted, throttled, auth_failed,
	 * no_attachments) or IMAP-only bounce/DSN reasons (bounce_handled,
	 * bounce_unresolved) belong here; those stay transport-specific on Inbox.
	 */
	public function test_shared_alias_reasons_contains_exactly_the_ten_shared_keys(): void {
		$expected = [
			'goodbye_non_artist',
			'goodbye_handled',
			'goodbye_no_membership',
			'goodbye_throttled',
			'community_non_artist',
			'community_throttled',
			'community_empty',
			'community_auto_submitted',
			'community_too_long',
			'community_handled',
		];

		$this->assertSame( $expected, array_keys( IntakeGates::SHARED_ALIAS_REASONS ) );
	}

	public function test_shared_alias_statuses_marks_exactly_the_three_success_reasons_as_skipped(): void {
		$this->assertSame(
			[
				'goodbye_handled'    => 'skipped',
				'community_handled'  => 'skipped',
				'community_too_long' => 'skipped',
			],
			IntakeGates::SHARED_ALIAS_STATUSES
		);
	}

	/** Every status key must also exist as a reason key — no orphaned status override. */
	public function test_every_shared_status_key_has_a_corresponding_shared_reason(): void {
		foreach ( array_keys( IntakeGates::SHARED_ALIAS_STATUSES ) as $key ) {
			$this->assertArrayHasKey( $key, IntakeGates::SHARED_ALIAS_REASONS, "'$key' is in SHARED_ALIAS_STATUSES but missing from SHARED_ALIAS_REASONS." );
		}
	}

	// =========================================================================
	// Both transports' own constants are actually composed from the shared
	// source above (not just two coincidentally-identical arrays) — a
	// ReflectionClass read of each private/public const, guarding against a
	// future edit re-introducing a hand-maintained, driftable copy.
	// =========================================================================

	private function class_const( string $class, string $name ): array {
		$rc = new \ReflectionClass( $class );
		return $rc->getConstant( $name );
	}

	public function test_webhook_alias_reasons_is_exactly_the_shared_reasons(): void {
		$this->assertSame( IntakeGates::SHARED_ALIAS_REASONS, $this->class_const( Webhook::class, 'ALIAS_REASONS' ) );
	}

	public function test_webhook_alias_statuses_is_exactly_the_shared_statuses(): void {
		$this->assertSame( IntakeGates::SHARED_ALIAS_STATUSES, $this->class_const( Webhook::class, 'ALIAS_STATUSES' ) );
	}

	/**
	 * Inbox::SKIP_REASONS must contain every shared entry verbatim, plus its
	 * own six IMAP-only gate reasons (2026-07-15: 'looks_like_reply' joined
	 * the original five) and two bounce/DSN reasons — and, since the Inbox
	 * admin table's reason-filter dropdown iterates this constant in order,
	 * the key order must be unchanged from before this refactor: the six
	 * IMAP-only gate reasons first, then the ten shared ones, then the two
	 * bounce/DSN reasons.
	 */
	public function test_inbox_skip_reasons_contains_every_shared_reason_in_the_original_order(): void {
		$skip_reasons = $this->class_const( Inbox::class, 'SKIP_REASONS' );

		$expected_order = [
			'unregistered_sender',
			'not_admitted',
			'throttled',
			'auth_failed',
			'no_attachments',
			'looks_like_reply',
			'goodbye_non_artist',
			'goodbye_handled',
			'goodbye_no_membership',
			'goodbye_throttled',
			'community_non_artist',
			'community_throttled',
			'community_empty',
			'community_auto_submitted',
			'community_too_long',
			'community_handled',
			'bounce_handled',
			'bounce_unresolved',
		];
		$this->assertSame( $expected_order, array_keys( $skip_reasons ) );

		foreach ( IntakeGates::SHARED_ALIAS_REASONS as $key => $text ) {
			$this->assertSame( $text, $skip_reasons[ $key ], "Inbox::SKIP_REASONS['$key'] drifted from IntakeGates::SHARED_ALIAS_REASONS." );
		}
	}

	// =========================================================================
	// is_reply_or_quote()
	// =========================================================================

	public function test_reply_or_quote_matches_re_prefixed_subject(): void {
		$this->assertTrue( IntakeGates::is_reply_or_quote( 'Re: My new painting', 'Just finished this one.' ) );
	}

	public function test_reply_or_quote_matches_re_prefix_case_insensitively_and_with_extra_space(): void {
		$this->assertTrue( IntakeGates::is_reply_or_quote( 're :  status update', 'Body text.' ) );
	}

	public function test_reply_or_quote_matches_agnosis_bracket_anywhere_in_subject(): void {
		$this->assertTrue( IntakeGates::is_reply_or_quote( 'Fwd: [Agnosis] Your submission was received', 'Body text.' ) );
	}

	public function test_reply_or_quote_matches_on_date_name_wrote_attribution_line(): void {
		$body = "Sounds good!\n\nOn 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Original quoted content here.";
		$this->assertTrue( IntakeGates::is_reply_or_quote( 'My new piece', $body ) );
	}

	public function test_reply_or_quote_matches_outlook_original_message_separator(): void {
		$body = "See attached.\n\n-----Original Message-----\nFrom: someone@example.com\nSubject: Old thread";
		$this->assertTrue( IntakeGates::is_reply_or_quote( 'My new piece', $body ) );
	}

	// -------------------------------------------------------------------------
	// Forward detection (eighth audit §3b) — the policy was always named
	// "reply or forward" and framed that way in the 0.9.25 changelog, but
	// nothing actually matched a forward as such before this widening; only
	// Outlook's shared reply/forward separator happened to be caught
	// incidentally via the "original message" pattern above.
	// -------------------------------------------------------------------------

	public function test_reply_or_quote_matches_fwd_prefixed_subject(): void {
		$this->assertTrue( IntakeGates::is_reply_or_quote( 'Fwd: My new painting', 'Look what my friend sent me.' ) );
	}

	public function test_reply_or_quote_matches_fw_prefixed_subject(): void {
		$this->assertTrue( IntakeGates::is_reply_or_quote( 'FW: status update', 'Body text.' ) );
	}

	public function test_reply_or_quote_matches_fwd_prefix_case_insensitively_and_with_extra_space(): void {
		$this->assertTrue( IntakeGates::is_reply_or_quote( 'fwd :  My piece', 'Body text.' ) );
	}

	public function test_reply_or_quote_matches_gmail_forwarded_message_separator(): void {
		// A Gmail forward with no comment of the forwarder's own above the
		// separator — the exact gap the audit flagged: no "Re:"/"Fwd:"
		// subject, no "On … wrote:" line, nothing but the separator itself.
		$body = "---------- Forwarded message ---------\nFrom: Someone <someone@example.com>\nDate: Mon, Jul 13, 2026\nSubject: My piece\n\nOriginal content here.";
		$this->assertTrue( IntakeGates::is_reply_or_quote( 'My piece', $body ) );
	}

	public function test_reply_or_quote_matches_gmail_forwarded_message_separator_with_varying_hyphen_counts(): void {
		$body = "--- Forwarded message ------\nFrom: someone@example.com";
		$this->assertTrue( IntakeGates::is_reply_or_quote( 'My piece', $body ) );
	}

	public function test_reply_or_quote_matches_apple_mail_begin_forwarded_message(): void {
		$body = "Begin forwarded message:\n\nFrom: Someone <someone@example.com>\nSubject: My piece\n\nOriginal content here.";
		$this->assertTrue( IntakeGates::is_reply_or_quote( 'My piece', $body ) );
	}

	public function test_reply_or_quote_matches_apple_mail_begin_forwarded_message_case_insensitively(): void {
		$body = "Some comment.\n\nBEGIN FORWARDED MESSAGE:\n\nFrom: someone@example.com";
		$this->assertTrue( IntakeGates::is_reply_or_quote( 'My piece', $body ) );
	}

	public function test_reply_or_quote_returns_false_for_a_genuine_original_submission(): void {
		$this->assertFalse( IntakeGates::is_reply_or_quote( 'My new painting', 'Just finished this one, hope you like it.' ) );
	}

	public function test_reply_or_quote_does_not_false_positive_on_the_word_forward_alone(): void {
		// "forward" appearing in ordinary prose (no colon, no separator shape)
		// must not trip the new patterns — only the specific marker phrases do.
		$this->assertFalse( IntakeGates::is_reply_or_quote( 'Moving forward with new work', 'I look forward to sharing more soon.' ) );
	}

	// =========================================================================
	// extract_original_content() (eighth audit §3c — reply-above-quote/
	// forward extraction, option (c) chosen for the product tension between
	// the 0.9.22 Reply-To "just hit reply" feature and the 0.9.25 blanket
	// reply/forward rejection)
	// =========================================================================

	public function test_extract_strips_re_prefix_from_subject(): void {
		$result = IntakeGates::extract_original_content( 'Re: My new painting', 'Just finished this one.' );

		$this->assertSame( 'My new painting', $result['subject'] );
	}

	public function test_extract_strips_fwd_and_fw_prefixes_from_subject(): void {
		$this->assertSame( 'My piece', IntakeGates::extract_original_content( 'Fwd: My piece', 'x' )['subject'] );
		$this->assertSame( 'My piece', IntakeGates::extract_original_content( 'FW: My piece', 'x' )['subject'] );
	}

	public function test_extract_strips_doubly_stacked_prefixes_from_a_long_thread_subject(): void {
		$result = IntakeGates::extract_original_content( 'Re: Fwd: Re: My piece', 'x' );

		$this->assertSame( 'My piece', $result['subject'] );
	}

	public function test_extract_removes_agnosis_bracket_from_subject(): void {
		$result = IntakeGates::extract_original_content( 'Fwd: [Agnosis] Your submission was received', 'x' );

		$this->assertSame( 'Your submission was received', $result['subject'] );
	}

	public function test_extract_collapses_whitespace_left_behind_by_stripping(): void {
		$result = IntakeGates::extract_original_content( 'Re:   [Agnosis]   My   piece', 'x' );

		$this->assertSame( 'My piece', $result['subject'] );
	}

	public function test_extract_keeps_whole_body_when_no_quote_marker_is_present(): void {
		// Only the SUBJECT carried a reply signal — an unusual but real case
		// (some client/workflow combinations strip quoting on their own).
		$result = IntakeGates::extract_original_content( 'Re: My new painting', 'Here it is again, freshly reworked.' );

		$this->assertSame( 'Here it is again, freshly reworked.', $result['body'] );
	}

	public function test_extract_keeps_only_the_comment_above_an_on_wrote_attribution_line(): void {
		$body   = "Here's another one for you.\n\nOn 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Previous content.";
		$result = IntakeGates::extract_original_content( 'My new painting', $body );

		$this->assertSame( "Here's another one for you.", $result['body'] );
	}

	public function test_extract_keeps_only_the_comment_above_an_outlook_original_message_separator(): void {
		$body   = "See attached.\n\n-----Original Message-----\nFrom: someone@example.com";
		$result = IntakeGates::extract_original_content( 'My new painting', $body );

		$this->assertSame( 'See attached.', $result['body'] );
	}

	public function test_extract_keeps_only_the_comment_above_a_gmail_forward_separator(): void {
		$body   = "Thought you'd like this.\n\n---------- Forwarded message ---------\nFrom: someone@example.com";
		$result = IntakeGates::extract_original_content( 'Fwd: interesting piece', $body );

		$this->assertSame( "Thought you'd like this.", $result['body'] );
	}

	public function test_extract_keeps_only_the_comment_above_an_apple_mail_forward_marker(): void {
		$body   = "Check this out.\n\nBegin forwarded message:\n\nFrom: someone@example.com";
		$result = IntakeGates::extract_original_content( 'Fwd: interesting piece', $body );

		$this->assertSame( 'Check this out.', $result['body'] );
	}

	public function test_extract_returns_empty_body_when_the_quote_marker_is_at_the_very_start(): void {
		// Nothing above the marker at all — the "nothing survived extraction"
		// case Email\Parser is responsible for turning into a rejection.
		$body   = "On 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Previous content.";
		$result = IntakeGates::extract_original_content( 'Re: My new painting', $body );

		$this->assertSame( '', $result['body'] );
	}

	public function test_extract_uses_the_earliest_marker_when_a_body_contains_more_than_one(): void {
		// A quoted thread that itself contains an even-older quoted thread —
		// only the comment above the FIRST (earliest/outermost) marker is the
		// sender's own new content; anything after that first marker,
		// including a second marker further down, is all quoted history.
		$body   = "My new comment.\n\nOn 13 Jul 2026, at 18:57, A <a@example.com> wrote:\n\nAn older reply.\n\nOn 10 Jul 2026, at 09:00, B <b@example.com> wrote:\n\n> Original.";
		$result = IntakeGates::extract_original_content( 'Re: My new painting', $body );

		$this->assertSame( 'My new comment.', $result['body'] );
	}

	public function test_inbox_skip_statuses_contains_every_shared_status_plus_its_own_bounce_statuses(): void {
		$skip_statuses = $this->class_const( Inbox::class, 'SKIP_STATUSES' );

		$this->assertSame(
			[
				'goodbye_handled'    => 'skipped',
				'community_handled'  => 'skipped',
				'community_too_long' => 'skipped',
				'bounce_handled'     => 'skipped',
				'bounce_unresolved'  => 'skipped',
			],
			$skip_statuses
		);

		foreach ( IntakeGates::SHARED_ALIAS_STATUSES as $key => $status ) {
			$this->assertSame( $status, $skip_statuses[ $key ] );
		}
	}
}
