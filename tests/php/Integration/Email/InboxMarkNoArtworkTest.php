<?php
/**
 * Integration tests — Inbox::mark_no_artwork() (2026-07-08).
 *
 * Inbox.php has no other test coverage — its message-parsing paths depend on
 * a real Webklex\PHPIMAP\Message object that's impractical to fake (see
 * LinguaForgeCompatTest's docblock for the same reasoning applied elsewhere in
 * this codebase). mark_no_artwork() itself takes only plain scalar arguments
 * and touches no IMAP object at all, so it's fully testable in isolation via
 * reflection (it's private) — this is a narrow, targeted addition, not a
 * broader Inbox test suite.
 *
 * Coverage: the raw_email JSON blob now also carries a `skip_reason` key,
 * added so InboxPage::render_status_badge() can show something more specific
 * than a flat "Skipped" for e.g. a completed self-removal — but only for
 * reasons whose status is 'skipped' (self::SKIP_STATUSES), never for the
 * ordinary 'failed' gate-skip reasons, which don't need it.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Email\Inbox;

class InboxMarkNoArtworkTest extends \WP_UnitTestCase {

	private Inbox $inbox;

	protected function setUp(): void {
		parent::setUp();
		$this->inbox = new Inbox();
	}

	/** @param array<mixed> $args */
	private function mark_no_artwork( array $args ): void {
		$ref = new \ReflectionMethod( Inbox::class, 'mark_no_artwork' );
		$ref->setAccessible( true );
		$ref->invokeArgs( $this->inbox, $args );
	}

	/** Fetch the most recently inserted queue row's raw_email, decoded. */
	private function latest_raw_email(): array {
		global $wpdb;

		$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT raw_email FROM {$wpdb->prefix}agnosis_queue ORDER BY id DESC LIMIT 1"
		);

		return json_decode( (string) $raw, true ) ?: [];
	}

	private function latest_status(): string {
		global $wpdb;

		return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status FROM {$wpdb->prefix}agnosis_queue ORDER BY id DESC LIMIT 1"
		);
	}

	public function test_goodbye_handled_stores_skip_reason_and_skipped_status(): void {
		$this->mark_no_artwork( [ 'uid-1', 5, 'goodbye_handled', 'artist@example.com' ] );

		$this->assertSame( 'skipped', $this->latest_status() );
		$data = $this->latest_raw_email();
		$this->assertSame( 'goodbye_handled', $data['skip_reason'] ?? null );
		$this->assertSame( 'artist@example.com', $data['from'] ?? null );
	}

	public function test_community_handled_stores_skip_reason(): void {
		$this->mark_no_artwork( [ 'uid-2', 6, 'community_handled', 'sender@example.com' ] );

		$this->assertSame( 'skipped', $this->latest_status() );
		$data = $this->latest_raw_email();
		$this->assertSame( 'community_handled', $data['skip_reason'] ?? null );
	}

	public function test_ordinary_failed_reason_does_not_store_skip_reason(): void {
		$this->mark_no_artwork( [ 'uid-3', null, 'unregistered_sender', 'unknown@example.com' ] );

		$this->assertSame( 'failed', $this->latest_status() );
		$data = $this->latest_raw_email();
		$this->assertArrayNotHasKey( 'skip_reason', $data );
		// The sender address is still preserved either way.
		$this->assertSame( 'unknown@example.com', $data['from'] ?? null );
	}

	public function test_no_from_email_and_no_skip_reason_yields_empty_json(): void {
		$this->mark_no_artwork( [ 'uid-4', null, 'no_attachments', '' ] );

		$data = $this->latest_raw_email();
		$this->assertSame( [], $data );
	}
}
