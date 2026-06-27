<?php
/**
 * Integration tests — drain_pending() queue drain.
 *
 * Verifies that:
 *   - drain_pending() schedules a single event for every pending row
 *   - Rows with other statuses (processing, published, failed) are not scheduled
 *   - drain_pending() is a no-op when the queue is empty
 *   - Rows inserted via webhook (no IMAP UID) are drained just like IMAP rows
 *   - poll() calls drain_pending() even when IMAP is unconfigured
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Email\Inbox;

class QueueDrainTest extends \WP_UnitTestCase {

	private Inbox $inbox;

	protected function setUp(): void {
		parent::setUp();
		$this->inbox = new Inbox();
		// Ensure IMAP is not configured so poll() skips the IMAP path.
		delete_option( 'agnosis_imap_host' );
		delete_option( 'agnosis_imap_user' );
		delete_option( 'agnosis_imap_pass' );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$this->clear_queue();
		// Clear any scheduled single events we created.
		$this->clear_scheduled_events();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function insert_queue_row( string $uid, string $status = 'pending' ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_queue',
			[
				'message_uid' => $uid,
				'raw_email'   => '{}',
				'status'      => $status,
			],
			[ '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	private function clear_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agnosis_queue WHERE message_uid LIKE 'test-%'" );
	}

	private function clear_scheduled_events(): void {
		wp_clear_scheduled_hook( 'agnosis_publish_submission' );
	}

	/** Return all queue IDs scheduled via wp_schedule_single_event. */
	private function get_scheduled_ids(): array {
		$crons = _get_cron_array();
		$ids   = [];
		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks['agnosis_publish_submission'] ) ) {
				foreach ( $hooks['agnosis_publish_submission'] as $key => $data ) {
					$args = $data['args'] ?? [];
					if ( ! empty( $args ) ) {
						$ids[] = (int) $args[0];
					}
				}
			}
		}
		return $ids;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_drain_pending_schedules_event_for_pending_row(): void {
		$id = $this->insert_queue_row( 'test-uid-1', 'pending' );

		$this->inbox->drain_pending();

		$this->assertContains( $id, $this->get_scheduled_ids() );
	}

	public function test_drain_pending_noop_when_queue_empty(): void {
		$this->clear_queue();

		$this->inbox->drain_pending();

		$this->assertEmpty( $this->get_scheduled_ids() );
	}

	public function test_drain_pending_skips_non_pending_statuses(): void {
		$this->insert_queue_row( 'test-uid-processing', 'processing' );
		$this->insert_queue_row( 'test-uid-published', 'published' );
		$this->insert_queue_row( 'test-uid-failed', 'failed' );

		$this->inbox->drain_pending();

		$this->assertEmpty( $this->get_scheduled_ids() );
	}

	public function test_drain_pending_schedules_all_pending_rows(): void {
		$id_a = $this->insert_queue_row( 'test-uid-a', 'pending' );
		$id_b = $this->insert_queue_row( 'test-uid-b', 'pending' );
		$id_c = $this->insert_queue_row( 'test-uid-c', 'pending' );

		$this->inbox->drain_pending();

		$scheduled = $this->get_scheduled_ids();
		$this->assertContains( $id_a, $scheduled );
		$this->assertContains( $id_b, $scheduled );
		$this->assertContains( $id_c, $scheduled );
	}

	public function test_drain_pending_ignores_non_pending_alongside_pending(): void {
		$pending_id = $this->insert_queue_row( 'test-uid-pending', 'pending' );
		$failed_id  = $this->insert_queue_row( 'test-uid-failed-2', 'failed' );

		$this->inbox->drain_pending();

		$scheduled = $this->get_scheduled_ids();
		$this->assertContains( $pending_id, $scheduled );
		$this->assertNotContains( $failed_id, $scheduled );
	}

	public function test_poll_calls_drain_when_imap_not_configured(): void {
		$id = $this->insert_queue_row( 'test-uid-webhook', 'pending' );

		// IMAP is not configured (setUp deleted the options) — poll() should skip
		// the IMAP path but still call drain_pending().
		$this->inbox->poll();

		$this->assertContains( $id, $this->get_scheduled_ids() );
	}
}
