<?php
/**
 * Integration tests — Inbox::process_messages()'s recognised-recipient/BCC
 * gate (2026-07-14).
 *
 * A message whose To:/Cc: headers match none of our own configured addresses
 * (IntakeGates::known_addresses()) never arrived via a genuine direct send to
 * a known endpoint — it was BCC'd, or its real recipient was otherwise
 * stripped before we ever saw it. Policy: we don't accept BCC. Such a message
 * is deleted from the mailbox outright and never queued at all — no
 * mark_no_artwork() row, no "Unknown" endpoint label to explain.
 *
 * Uses TestableInbox (overrides query_messages()) and FakeAliasImapMessage
 * (which now also implements delete(), recording whether it was called via
 * its public $deleted flag) so this is exercised without a live IMAP
 * connection or touching a real mailbox.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Email\IntakeGates;
use Agnosis\Vendor\Webklex\PHPIMAP\Folder;

class InboxRecognizedRecipientGateTest extends \WP_UnitTestCase {

	private TestableInbox $inbox;
	private Folder $folder;

	protected function setUp(): void {
		parent::setUp();

		$this->inbox  = new TestableInbox();
		$this->folder = $this->createMock( Folder::class );

		delete_option( 'agnosis_imap_last_uid' );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_imap_last_uid' );
		delete_option( 'agnosis_email_submit' );
		delete_option( 'agnosis_email_bio' );
		delete_option( 'agnosis_email_event' );
		delete_option( 'agnosis_email_replace' );
		delete_option( 'agnosis_email_remove' );
		delete_option( 'agnosis_email_promote' );
		delete_option( 'agnosis_email_photo' );
		delete_option( 'agnosis_email_pure' );
		delete_option( 'agnosis_email_goodbye' );
		delete_option( 'agnosis_email_community' );
		delete_option( 'agnosis_imap_user' );
		parent::tearDown();
	}

	private function latest_queue_row_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue"
		);
	}

	// -------------------------------------------------------------------------
	// IntakeGates::known_addresses() / is_recognized_recipient()
	// -------------------------------------------------------------------------

	public function test_known_addresses_is_empty_when_nothing_configured(): void {
		$this->assertSame( [], IntakeGates::known_addresses() );
	}

	public function test_known_addresses_collects_every_configured_routing_option(): void {
		update_option( 'agnosis_email_submit', 'Submit@Example.com' );
		update_option( 'agnosis_email_goodbye', 'goodbye@example.com' );

		$known = IntakeGates::known_addresses();

		$this->assertContains( 'submit@example.com', $known, 'Addresses must be lowercased.' );
		$this->assertContains( 'goodbye@example.com', $known );
	}

	public function test_known_addresses_includes_imap_user_when_it_looks_like_an_email(): void {
		update_option( 'agnosis_imap_user', 'mailbox@example.com' );

		$this->assertContains( 'mailbox@example.com', IntakeGates::known_addresses() );
	}

	public function test_known_addresses_excludes_imap_user_when_it_is_not_an_email(): void {
		update_option( 'agnosis_imap_user', 'plain-username' );

		$this->assertNotContains( 'plain-username', IntakeGates::known_addresses() );
	}

	public function test_is_recognized_recipient_is_permissive_when_nothing_is_configured(): void {
		// Safety net: an unconfigured/still-being-set-up site has nothing to
		// validate against — must never reject in this state.
		$this->assertTrue( IntakeGates::is_recognized_recipient( [] ) );
		$this->assertTrue( IntakeGates::is_recognized_recipient( [ 'anything@example.com' ] ) );
	}

	public function test_is_recognized_recipient_true_on_a_matching_address(): void {
		update_option( 'agnosis_email_submit', 'submit@example.com' );

		$this->assertTrue( IntakeGates::is_recognized_recipient( [ 'submit@example.com' ] ) );
	}

	public function test_is_recognized_recipient_false_when_configured_but_no_match(): void {
		update_option( 'agnosis_email_submit', 'submit@example.com' );

		$this->assertFalse( IntakeGates::is_recognized_recipient( [ 'someone-else@example.com' ] ) );
		$this->assertFalse( IntakeGates::is_recognized_recipient( [] ) );
	}

	// -------------------------------------------------------------------------
	// Inbox::process_messages() — the actual gate, end-to-end
	// -------------------------------------------------------------------------

	public function test_unrecognized_recipient_is_deleted_and_never_queued(): void {
		update_option( 'agnosis_email_submit', 'submit@example.com' );

		$message = new FakeAliasImapMessage( 1, 'artist@example.com', 'someone-unrelated@example.com' );
		$this->inbox->set_pending_messages( [ $message ] );
		$this->inbox->run_process_messages( $this->folder );

		$this->assertTrue( $message->deleted, 'A message matching none of our configured addresses must be deleted.' );
		$this->assertSame( 0, $this->latest_queue_row_count(), 'An unrecognised-recipient message must never create a queue row.' );
	}

	public function test_recognized_recipient_is_not_deleted(): void {
		update_option( 'agnosis_email_submit', 'submit@example.com' );

		// Empty From: so it still falls through to the (harmless) unregistered_sender
		// skip further down the pipeline — the point here is only that it survives
		// the recognised-recipient gate itself, not the rest of the pipeline.
		$message = new FakeAliasImapMessage( 2, '', 'submit@example.com' );
		$this->inbox->set_pending_messages( [ $message ] );
		$this->inbox->run_process_messages( $this->folder );

		$this->assertFalse( $message->deleted, 'A message addressed to a recognised endpoint must not be deleted.' );
	}

	public function test_gate_is_permissive_when_no_addresses_are_configured_at_all(): void {
		// Nothing configured — the safety net must apply, not the strict check.
		$message = new FakeAliasImapMessage( 3, '', 'random@example.com' );
		$this->inbox->set_pending_messages( [ $message ] );
		$this->inbox->run_process_messages( $this->folder );

		$this->assertFalse( $message->deleted, 'An unconfigured site must never delete mail via this gate.' );
	}

	public function test_message_to_the_imap_users_own_address_is_recognized(): void {
		update_option( 'agnosis_imap_user', 'mailbox@example.com' );

		$message = new FakeAliasImapMessage( 4, '', 'mailbox@example.com' );
		$this->inbox->set_pending_messages( [ $message ] );
		$this->inbox->run_process_messages( $this->folder );

		$this->assertFalse( $message->deleted, 'Mail addressed directly to the IMAP mailbox account itself must be recognised.' );
	}
}
