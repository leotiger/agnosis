<?php
/**
 * Integration test — fourth audit §5c: Inbox::cleanup()'s daily cron now also
 * expires old Core\Debug dumps, not just IMAP messages / queue rows / log rows.
 *
 * No IMAP host/user/pass is configured in this test env, so cleanup_imap()'s
 * own is_configured() guard makes it a safe no-op here — cleanup() can be
 * called directly without a real mailbox, exactly as the pre-existing
 * cleanup_queue()/Logger::prune() behaviour already relies on in this suite.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Core\Debug;
use Agnosis\Email\Inbox;

class InboxCleanupDebugPruneTest extends \WP_UnitTestCase {

	private string $test_dir;

	protected function setUp(): void {
		parent::setUp();

		$this->test_dir = rtrim( sys_get_temp_dir(), '/' ) . '/agnosis-debug-cleanup-test-' . wp_generate_password( 8, false );
		add_filter( 'agnosis_debug_dir', [ $this, 'filter_debug_dir' ] );
	}

	protected function tearDown(): void {
		remove_filter( 'agnosis_debug_dir', [ $this, 'filter_debug_dir' ] );
		delete_option( 'agnosis_debug_retention_days' );

		if ( is_dir( $this->test_dir ) ) {
			foreach ( glob( $this->test_dir . '/*' ) ?: [] as $file ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test-only teardown of a throwaway temp dir.
			}
			@rmdir( $this->test_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test-only teardown of a throwaway temp dir.
		}

		parent::tearDown();
	}

	public function filter_debug_dir(): string {
		return $this->test_dir;
	}

	private function seed_file( string $name, int $age_days ): string {
		if ( ! is_dir( $this->test_dir ) ) {
			mkdir( $this->test_dir, 0777, true );
		}

		$path = $this->test_dir . '/' . $name;
		file_put_contents( $path, 'raw dump content' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture, not plugin runtime code.
		touch( $path, time() - ( $age_days * DAY_IN_SECONDS ) );

		return $path;
	}

	public function test_cleanup_expires_debug_dumps_older_than_default_retention(): void {
		$old = $this->seed_file( 'inbox-poll-old-11111111.txt', 20 );
		$new = $this->seed_file( 'inbox-poll-new-22222222.txt', 2 );

		( new Inbox() )->cleanup();

		$this->assertFileDoesNotExist( $old, 'Default retention is 14 days; a 20-day-old dump must be pruned.' );
		$this->assertFileExists( $new );
	}

	public function test_cleanup_honors_configured_debug_retention_days(): void {
		update_option( 'agnosis_debug_retention_days', 3 );
		$moderately_old = $this->seed_file( 'parser-attachments-5d-33333333.txt', 5 );

		( new Inbox() )->cleanup();

		$this->assertFileDoesNotExist(
			$moderately_old,
			'With retention configured to 3 days, a 5-day-old dump must be pruned even though it is well within the 14-day default.'
		);
	}

	// -------------------------------------------------------------------------
	// agnosis_imap_cleanup_days default alignment (audit §5e) — cleanup_queue()
	// previously fell back to 30 days when the option row was missing, while
	// Settings/Activator both document and seed 7. Regression coverage: seed a
	// queue row aged between the two (10 days — older than the aligned 7-day
	// default, younger than the old drifted 30-day one) with NO option row
	// present at all, so the fallback itself is what's under test.
	// -------------------------------------------------------------------------

	private function seed_queue_row( string $uid, string $status, int $age_days ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_queue',
			[
				'message_uid' => $uid,
				'raw_email'   => '{}',
				'status'      => $status,
				'created_at'  => gmdate( 'Y-m-d H:i:s', time() - $age_days * DAY_IN_SECONDS ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);
	}

	private function queue_row_exists( string $uid ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}agnosis_queue WHERE message_uid = %s", $uid )
		);
	}

	public function test_cleanup_queue_falls_back_to_seven_days_not_thirty_when_option_is_unset(): void {
		delete_option( 'agnosis_imap_cleanup_days' );

		$this->seed_queue_row( 'cleanup-drift-old-10d', 'published', 10 );
		$this->seed_queue_row( 'cleanup-drift-new-5d', 'published', 5 );

		( new Inbox() )->cleanup();

		$this->assertFalse(
			$this->queue_row_exists( 'cleanup-drift-old-10d' ),
			'With the option unset, the fallback must be 7 days (matching Settings/Activator) — a 10-day-old row must be pruned. Before the §5e fix, cleanup_queue() fell back to 30 days here and would have wrongly kept this row.'
		);
		$this->assertTrue(
			$this->queue_row_exists( 'cleanup-drift-new-5d' ),
			'A 5-day-old row is within the 7-day fallback and must survive.'
		);
	}
}
