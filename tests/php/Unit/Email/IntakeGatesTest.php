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

	public function test_collects_every_address_across_recipient_to_and_cc_fields(): void {
		$addrs = IntakeGates::recipient_addresses( [
			'recipient' => 'primary@example.com',
			'To'        => 'primary@example.com, other@example.com',
			'Cc'        => 'cc-one@example.com',
		] );

		sort( $addrs );
		$this->assertSame( [ 'cc-one@example.com', 'other@example.com', 'primary@example.com' ], $addrs );
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
	 * own five IMAP-only gate reasons and two bounce/DSN reasons — and, since
	 * the Inbox admin table's reason-filter dropdown iterates this constant
	 * in order, the key order must be unchanged from before this refactor:
	 * the five IMAP-only gate reasons first, then the ten shared ones, then
	 * the two bounce/DSN reasons.
	 */
	public function test_inbox_skip_reasons_contains_every_shared_reason_in_the_original_order(): void {
		$skip_reasons = $this->class_const( Inbox::class, 'SKIP_REASONS' );

		$expected_order = [
			'unregistered_sender',
			'not_admitted',
			'throttled',
			'auth_failed',
			'no_attachments',
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
