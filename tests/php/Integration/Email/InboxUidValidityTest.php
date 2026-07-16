<?php
/**
 * Integration tests — IMAP UIDVALIDITY guard on the UID cursor (reliability
 * audit §5d).
 *
 * A UID is only guaranteed stable within one UIDVALIDITY generation for a
 * folder (RFC 3501 §2.3.1.1) — a mailbox rebuild, provider migration, or
 * folder recreation can reissue UIDs from 1 while UIDVALIDITY changes to
 * signal exactly that. Inbox::check_uidvalidity() (called at the top of
 * process_messages(), before the cursor is read) now detects that change and
 * resets `agnosis_imap_last_uid` to 0 so the next query rescans the
 * date-bounded retention window instead of silently matching nothing,
 * forever, via a stale `UID N+1:*`.
 *
 * Reuses TestableInbox (query_messages() override — no live IMAP connection
 * needed) from InboxUidCursorTest's support file. $folder is a PHPUnit mock
 * here (createMock(Folder::class)) with getStatus() stubbed per test, since
 * that's the one call this guard actually makes on it (query_messages()
 * itself is overridden and ignores $folder entirely).
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Vendor\Webklex\PHPIMAP\Folder;

class InboxUidValidityTest extends \WP_UnitTestCase {

	private TestableInbox $inbox;

	protected function setUp(): void {
		parent::setUp();

		$this->inbox = new TestableInbox();

		delete_option( 'agnosis_imap_last_uid' );
		delete_option( 'agnosis_imap_uidvalidity' );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_imap_last_uid' );
		delete_option( 'agnosis_imap_uidvalidity' );
		parent::tearDown();
	}

	/** A Folder mock whose getStatus() returns a fixed uidvalidity. */
	private function folder_with_uidvalidity( int $uidvalidity ): Folder {
		$folder = $this->createMock( Folder::class );
		$folder->method( 'getStatus' )->willReturn( [ 'uidvalidity' => $uidvalidity ] );
		return $folder;
	}

	// =========================================================================
	// First poll ever — nothing to compare against yet
	// =========================================================================

	public function test_first_poll_stores_uidvalidity_without_resetting_cursor(): void {
		update_option( 'agnosis_imap_last_uid', 50 ); // Pretend a cursor already exists but was never tracked against a UIDVALIDITY.

		$this->inbox->set_pending_messages( [] );
		$this->inbox->run_process_messages( $this->folder_with_uidvalidity( 111 ) );

		$this->assertSame( 111, (int) get_option( 'agnosis_imap_uidvalidity' ), 'The first poll must start tracking whatever UIDVALIDITY the server reports.' );
		$this->assertSame( 50, (int) get_option( 'agnosis_imap_last_uid' ), 'With nothing stored yet to compare against, the existing cursor must be left alone.' );
	}

	// =========================================================================
	// Matching UIDVALIDITY — no-op
	// =========================================================================

	public function test_matching_uidvalidity_leaves_the_cursor_untouched(): void {
		update_option( 'agnosis_imap_uidvalidity', 111 );
		update_option( 'agnosis_imap_last_uid', 250 );

		$this->inbox->set_pending_messages( [] );
		$this->inbox->run_process_messages( $this->folder_with_uidvalidity( 111 ) );

		$this->assertSame( 111, (int) get_option( 'agnosis_imap_uidvalidity' ) );
		$this->assertSame( 250, (int) get_option( 'agnosis_imap_last_uid' ), 'An unchanged UIDVALIDITY must never reset a perfectly good cursor.' );
		$this->assertSame( 250, $this->inbox->last_query_uid, 'query_messages() must still receive the persisted cursor, not a reset one.' );
	}

	// =========================================================================
	// Mismatched UIDVALIDITY — reset, and the reset lands in THIS poll
	// =========================================================================

	public function test_mismatched_uidvalidity_resets_the_cursor_to_zero(): void {
		update_option( 'agnosis_imap_uidvalidity', 111 );
		update_option( 'agnosis_imap_last_uid', 250 );

		$this->inbox->set_pending_messages( [] );
		$this->inbox->run_process_messages( $this->folder_with_uidvalidity( 222 ) );

		$this->assertSame( 222, (int) get_option( 'agnosis_imap_uidvalidity' ), 'The new UIDVALIDITY must be stored so future polls compare against it.' );
		$this->assertSame( 0, (int) get_option( 'agnosis_imap_last_uid' ), 'A UIDVALIDITY mismatch must reset the stale cursor.' );
	}

	public function test_mismatched_uidvalidity_reset_is_visible_to_query_messages_in_the_same_poll(): void {
		update_option( 'agnosis_imap_uidvalidity', 111 );
		update_option( 'agnosis_imap_last_uid', 250 );

		$this->inbox->set_pending_messages( [] );
		$this->inbox->run_process_messages( $this->folder_with_uidvalidity( 222 ) );

		// The whole point of running the check before the cursor is read:
		// this same poll must already rescan from the retention window
		// (query_messages()'s last_uid === 0 branch) rather than waiting
		// until the NEXT poll to notice the reset.
		$this->assertSame( 0, $this->inbox->last_query_uid, 'A UIDVALIDITY reset must be visible to query_messages() within the same poll that detected it.' );
	}

	public function test_cursor_advances_normally_on_the_poll_after_a_reset(): void {
		update_option( 'agnosis_imap_uidvalidity', 111 );
		update_option( 'agnosis_imap_last_uid', 250 );

		// The mailbox was rebuilt — UIDs restart from a low number.
		$this->inbox->set_pending_messages( [
			new FakeImapMessage( 5 ),
			new FakeImapMessage( 12 ),
		] );
		$this->inbox->run_process_messages( $this->folder_with_uidvalidity( 222 ) );

		$this->assertSame( 12, (int) get_option( 'agnosis_imap_last_uid' ), 'After the reset, the cursor must advance normally against the new UIDVALIDITY generation\'s own (lower) UIDs.' );
	}

	// =========================================================================
	// Defensive fallbacks
	// =========================================================================

	public function test_a_getstatus_failure_does_not_break_the_poll(): void {
		update_option( 'agnosis_imap_uidvalidity', 111 );
		update_option( 'agnosis_imap_last_uid', 250 );

		$folder = $this->createMock( Folder::class );
		$folder->method( 'getStatus' )->willThrowException( new \RuntimeException( 'STATUS command failed' ) );

		$this->inbox->set_pending_messages( [ new FakeImapMessage( 260 ) ] );
		$this->inbox->run_process_messages( $folder );

		// The UIDVALIDITY check is a diagnostic add-on, never a prerequisite
		// for polling — a failure reading it must not prevent the normal
		// cursor-advancement logic from running.
		$this->assertSame( 260, (int) get_option( 'agnosis_imap_last_uid' ) );
		$this->assertSame( 111, (int) get_option( 'agnosis_imap_uidvalidity' ), 'A failed STATUS read must not overwrite the previously stored UIDVALIDITY.' );
	}

	public function test_a_missing_uidvalidity_in_the_status_response_is_treated_as_nothing_to_compare(): void {
		update_option( 'agnosis_imap_uidvalidity', 111 );
		update_option( 'agnosis_imap_last_uid', 250 );

		$folder = $this->createMock( Folder::class );
		$folder->method( 'getStatus' )->willReturn( [] ); // No 'uidvalidity' key at all.

		$this->inbox->set_pending_messages( [] );
		$this->inbox->run_process_messages( $folder );

		$this->assertSame( 250, (int) get_option( 'agnosis_imap_last_uid' ), 'A STATUS response with no uidvalidity must never trigger a reset.' );
		$this->assertSame( 111, (int) get_option( 'agnosis_imap_uidvalidity' ) );
	}
}
