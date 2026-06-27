<?php
/**
 * Integration tests for Core\Logger.
 *
 * Logger writes to the agnosis_log DB table. Tests verify the full cycle:
 * write → read → count → clear, and the prune() time-based cleanup.
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\Logger;

class LoggerIntegrationTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Logger::clear(); // Start each test with an empty log table.
	}

	protected function tearDown(): void {
		Logger::clear();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Basic write + read
	// -------------------------------------------------------------------------

	public function test_info_writes_entry(): void {
		Logger::info( 'Test info message', 'test' );

		$entries = Logger::get_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'info',              $entries[0]['level'] );
		$this->assertSame( 'test',              $entries[0]['context'] );
		$this->assertSame( 'Test info message', $entries[0]['message'] );
	}

	public function test_warning_writes_entry_with_warning_level(): void {
		Logger::warning( 'Something odd happened', 'publisher' );

		$entries = Logger::get_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'warning', $entries[0]['level'] );
	}

	public function test_error_writes_entry_with_error_level(): void {
		Logger::error( 'Pipeline crashed', 'publisher' );

		$entries = Logger::get_entries();

		$this->assertSame( 'error', $entries[0]['level'] );
	}

	public function test_multiple_writes_are_all_stored(): void {
		Logger::info( 'First',  'a' );
		Logger::info( 'Second', 'b' );
		Logger::error( 'Third', 'c' );

		$this->assertCount( 3, Logger::get_entries() );
	}

	// -------------------------------------------------------------------------
	// Ordering — newest first
	// -------------------------------------------------------------------------

	public function test_get_entries_returns_newest_first(): void {
		Logger::info( 'Oldest', 'test' );
		Logger::info( 'Newest', 'test' );

		$entries = Logger::get_entries();

		$this->assertSame( 'Newest', $entries[0]['message'] );
		$this->assertSame( 'Oldest', $entries[1]['message'] );
	}

	// -------------------------------------------------------------------------
	// Pagination
	// -------------------------------------------------------------------------

	public function test_get_entries_respects_limit(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			Logger::info( "Message $i", 'test' );
		}

		$entries = Logger::get_entries( 3 );

		$this->assertCount( 3, $entries );
	}

	public function test_get_entries_respects_offset(): void {
		Logger::info( 'First',  'test' );
		Logger::info( 'Second', 'test' );
		Logger::info( 'Third',  'test' );

		// With newest-first ordering and offset 1, we skip the newest.
		$entries = Logger::get_entries( 10, 1 );

		$this->assertCount( 2, $entries );
	}

	// -------------------------------------------------------------------------
	// count()
	// -------------------------------------------------------------------------

	public function test_count_returns_zero_on_empty_table(): void {
		$this->assertSame( 0, Logger::count() );
	}

	public function test_count_increments_with_each_write(): void {
		$this->assertSame( 0, Logger::count() );

		Logger::info( 'One', 'test' );
		$this->assertSame( 1, Logger::count() );

		Logger::info( 'Two', 'test' );
		$this->assertSame( 2, Logger::count() );
	}

	// -------------------------------------------------------------------------
	// clear()
	// -------------------------------------------------------------------------

	public function test_clear_removes_all_entries(): void {
		Logger::info( 'Msg 1', 'test' );
		Logger::info( 'Msg 2', 'test' );

		Logger::clear();

		$this->assertSame( 0, Logger::count() );
		$this->assertEmpty( Logger::get_entries() );
	}

	// -------------------------------------------------------------------------
	// Context truncation
	// -------------------------------------------------------------------------

	public function test_context_is_truncated_to_64_characters(): void {
		$long_context = str_repeat( 'x', 100 );
		Logger::info( 'Test message', $long_context );

		$entries = Logger::get_entries();

		$this->assertSame( 64, strlen( $entries[0]['context'] ) );
	}

	// -------------------------------------------------------------------------
	// prune() — time-based cleanup
	// -------------------------------------------------------------------------

	public function test_prune_removes_old_entries(): void {
		global $wpdb;

		// Insert an artificially old entry directly into the DB.
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_log',
			[
				'level'      => 'info',
				'context'    => 'test',
				'message'    => 'Old entry',
				'created_at' => '2000-01-01 00:00:00', // very old
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		// Insert a recent entry normally.
		Logger::info( 'Recent entry', 'test' );

		$this->assertSame( 2, Logger::count() );

		Logger::prune( 30 ); // prune anything older than 30 days

		$entries = Logger::get_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'Recent entry', $entries[0]['message'] );
	}

	public function test_prune_keeps_recent_entries(): void {
		Logger::info( 'Recent 1', 'test' );
		Logger::info( 'Recent 2', 'test' );

		Logger::prune( 30 );

		$this->assertSame( 2, Logger::count() );
	}
}
