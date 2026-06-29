<?php
/**
 * Integration tests for Inbox UID cursor + headers-first poll (§2b).
 *
 * These tests do NOT require a live IMAP connection. Instead they use
 * TestableInbox (which overrides query_messages()) and FakeImapMessage stubs,
 * making cursor-advancement logic fully exercisable in CI.
 *
 * Covers:
 *   - Default cursor starts at 0 (no option set).
 *   - After processing messages the cursor advances to the highest UID seen.
 *   - On subsequent polls the cursor is not re-updated if no new messages arrive.
 *   - Skipped messages (no registered sender) still advance the cursor.
 *   - query_messages() receives last_uid=0 on first run and N>0 on subsequent runs.
 *   - Cursor never regresses below its current value.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Webklex\PHPIMAP\Folder;

class InboxUidCursorTest extends \WP_UnitTestCase {

	private TestableInbox $inbox;

	/** Dummy folder — never used because query_messages() is overridden. */
	private Folder $folder;

	protected function setUp(): void {
		parent::setUp();

		$this->inbox  = new TestableInbox();
		$this->folder = $this->createMock( Folder::class );

		// Ensure the cursor starts clean for every test.
		delete_option( 'agnosis_imap_last_uid' );
	}

	protected function tearDown(): void {
		parent::tearDown();
		delete_option( 'agnosis_imap_last_uid' );
	}

	// -------------------------------------------------------------------------
	// Cursor default
	// -------------------------------------------------------------------------

	public function test_cursor_defaults_to_zero(): void {
		$this->assertSame( 0, (int) get_option( 'agnosis_imap_last_uid', 0 ) );
	}

	// -------------------------------------------------------------------------
	// First-run: query_messages receives last_uid = 0
	// -------------------------------------------------------------------------

	public function test_first_run_passes_zero_last_uid_to_query_messages(): void {
		$this->inbox->set_pending_messages( [] );
		$this->inbox->run_process_messages( $this->folder );

		$this->assertSame( 0, $this->inbox->last_query_uid, 'First run must pass last_uid=0 to query_messages().' );
	}

	// -------------------------------------------------------------------------
	// Cursor advancement
	// -------------------------------------------------------------------------

	public function test_cursor_advances_to_highest_uid_after_poll(): void {
		$this->inbox->set_pending_messages( [
			new FakeImapMessage( 100 ),
			new FakeImapMessage( 120 ),
			new FakeImapMessage( 105 ),
		] );

		$this->inbox->run_process_messages( $this->folder );

		$this->assertSame(
			120,
			(int) get_option( 'agnosis_imap_last_uid' ),
			'Cursor must advance to the highest UID seen (120), not insertion order.'
		);
	}

	public function test_cursor_advances_even_for_skipped_messages(): void {
		// Messages with no registered sender are skipped at gate-1.
		// The cursor must still advance because we saw their UIDs.
		$this->inbox->set_pending_messages( [
			new FakeImapMessage( 200 ),
			new FakeImapMessage( 210 ),
		] );

		$this->inbox->run_process_messages( $this->folder );

		$this->assertSame(
			210,
			(int) get_option( 'agnosis_imap_last_uid' ),
			'Cursor must advance even when all messages are skipped at gate-1.'
		);
	}

	// -------------------------------------------------------------------------
	// Subsequent run: query_messages receives the persisted cursor
	// -------------------------------------------------------------------------

	public function test_subsequent_run_passes_persisted_cursor_to_query_messages(): void {
		update_option( 'agnosis_imap_last_uid', 300 );

		$this->inbox->set_pending_messages( [] );
		$this->inbox->run_process_messages( $this->folder );

		$this->assertSame(
			300,
			$this->inbox->last_query_uid,
			'Subsequent run must pass the persisted cursor to query_messages().'
		);
	}

	// -------------------------------------------------------------------------
	// No update when no new messages
	// -------------------------------------------------------------------------

	public function test_cursor_not_updated_when_no_new_messages(): void {
		update_option( 'agnosis_imap_last_uid', 400 );

		$this->inbox->set_pending_messages( [] );
		$this->inbox->run_process_messages( $this->folder );

		$this->assertSame(
			400,
			(int) get_option( 'agnosis_imap_last_uid' ),
			'Cursor must not be updated when no new messages are returned.'
		);
	}

	// -------------------------------------------------------------------------
	// Cursor does not regress
	// -------------------------------------------------------------------------

	public function test_cursor_never_regresses(): void {
		update_option( 'agnosis_imap_last_uid', 500 );

		// A message with a lower UID than the stored cursor should not happen in
		// practice (the server honours UID N+1:*) but the code must not regress
		// if it ever does.
		$this->inbox->set_pending_messages( [ new FakeImapMessage( 450 ) ] );
		$this->inbox->run_process_messages( $this->folder );

		$this->assertSame(
			500,
			(int) get_option( 'agnosis_imap_last_uid' ),
			'Cursor must never regress below its current value.'
		);
	}
}
