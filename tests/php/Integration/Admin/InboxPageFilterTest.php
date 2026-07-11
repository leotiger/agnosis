<?php
/**
 * Integration tests — Inbox admin table's status/reason filter, pagination,
 * and spam aggregation (security/ops audit §4c).
 *
 * Every unregistered-sender message becomes its own 'failed' queue row
 * (correct — the state machine needs it to avoid re-fetching the same UID
 * forever), but a weekend of dictionary spam could mean hundreds of them,
 * pushing every real event off the table's single unpaginated page. Fixed by
 * (a) real pagination replacing the flat `LIMIT 100`, (b) a status/reason
 * filter (reason keyed off Inbox::SKIP_REASONS, made public specifically so
 * InboxPage can match against it instead of duplicating the prose), and (c)
 * collapsing 'unregistered_sender' rows out of the default (no reason
 * chosen) view into one summary count instead of listing each one.
 *
 * InboxPage::fetch_rows()/count_unregistered_sender_rows() are `protected`
 * specifically for this kind of direct testing — exercised here via
 * ReflectionMethod on a real InboxPage instance against the real
 * agnosis_queue table, rather than mocking $wpdb.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\InboxPage;
use Agnosis\Email\Inbox;

class InboxPageFilterTest extends \WP_UnitTestCase {

	private InboxPage $page;
	private \ReflectionMethod $fetch_rows;
	private \ReflectionMethod $count_unregistered;

	protected function setUp(): void {
		parent::setUp();

		$this->page = new InboxPage();

		$rc               = new \ReflectionClass( InboxPage::class );
		$this->fetch_rows = $rc->getMethod( 'fetch_rows' );
		$this->fetch_rows->setAccessible( true );

		$this->count_unregistered = $rc->getMethod( 'count_unregistered_sender_rows' );
		$this->count_unregistered->setAccessible( true );
	}

	protected function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agnosis_queue WHERE message_uid LIKE 'test-inboxfilter-%'" );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function insert_row( string $uid, string $status, string $error = '', ?int $artist_id = null ): int {
		global $wpdb;

		$data   = [
			'message_uid' => $uid,
			'status'      => $status,
			'raw_email'   => '{}',
			'error'       => '' !== $error ? $error : null,
		];
		$format = [ '%s', '%s', '%s', '%s' ];

		if ( null !== $artist_id ) {
			$data['artist_id'] = $artist_id;
			$format[]          = '%d';
		}

		$wpdb->insert( $wpdb->prefix . 'agnosis_queue', $data, $format );
		return (int) $wpdb->insert_id;
	}

	/** @return array{rows: array<int, object>, total: int} */
	private function fetch( string $status_filter, string $reason_filter, int $paged = 1 ): array {
		return $this->fetch_rows->invoke( $this->page, $status_filter, $reason_filter, $paged );
	}

	private function count_unregistered( string $status_filter ): int {
		return $this->count_unregistered->invoke( $this->page, $status_filter );
	}

	// =========================================================================
	// Spam aggregation — default view excludes unregistered_sender rows
	// =========================================================================

	public function test_default_view_excludes_unregistered_sender_rows(): void {
		$this->insert_row( 'test-inboxfilter-unreg-1', 'failed', Inbox::SKIP_REASONS['unregistered_sender'] );
		$this->insert_row( 'test-inboxfilter-notadmit-1', 'failed', Inbox::SKIP_REASONS['not_admitted'] );
		$this->insert_row( 'test-inboxfilter-pending-1', 'pending' );

		$result = $this->fetch( '', '', 1 );

		$this->assertSame( 2, $result['total'], 'Default view must exclude unregistered_sender rows from both the listing and its total count.' );
		foreach ( $result['rows'] as $row ) {
			$this->assertNotSame( Inbox::SKIP_REASONS['unregistered_sender'], $row->error );
		}
	}

	public function test_explicit_unregistered_sender_reason_filter_shows_the_rows(): void {
		$this->insert_row( 'test-inboxfilter-unreg-2', 'failed', Inbox::SKIP_REASONS['unregistered_sender'] );
		$this->insert_row( 'test-inboxfilter-notadmit-2', 'failed', Inbox::SKIP_REASONS['not_admitted'] );

		$result = $this->fetch( '', 'unregistered_sender', 1 );

		$this->assertSame( 1, $result['total'], 'Drilling into the reason explicitly must show exactly the rows the default view hid.' );
		$this->assertSame( Inbox::SKIP_REASONS['unregistered_sender'], $result['rows'][0]->error );
	}

	// =========================================================================
	// Status filter
	// =========================================================================

	public function test_status_filter_narrows_to_the_chosen_status(): void {
		$this->insert_row( 'test-inboxfilter-pending-2', 'pending' );
		$this->insert_row( 'test-inboxfilter-published-1', 'published' );
		$this->insert_row( 'test-inboxfilter-notadmit-3', 'failed', Inbox::SKIP_REASONS['not_admitted'] );

		$result = $this->fetch( 'pending', '', 1 );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'pending', $result['rows'][0]->status );
	}

	// =========================================================================
	// Reason filter translates its key into Inbox::SKIP_REASONS prose
	// =========================================================================

	public function test_reason_filter_matches_the_exact_skip_reasons_prose(): void {
		$this->insert_row( 'test-inboxfilter-throttled-1', 'failed', Inbox::SKIP_REASONS['throttled'] );
		$this->insert_row( 'test-inboxfilter-notadmit-4', 'failed', Inbox::SKIP_REASONS['not_admitted'] );

		$result = $this->fetch( '', 'throttled', 1 );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( Inbox::SKIP_REASONS['throttled'], $result['rows'][0]->error );
	}

	// =========================================================================
	// count_unregistered_sender_rows()
	// =========================================================================

	public function test_count_unregistered_sender_rows_counts_correctly(): void {
		$this->insert_row( 'test-inboxfilter-unreg-3', 'failed', Inbox::SKIP_REASONS['unregistered_sender'] );
		$this->insert_row( 'test-inboxfilter-unreg-4', 'failed', Inbox::SKIP_REASONS['unregistered_sender'] );
		$this->insert_row( 'test-inboxfilter-notadmit-5', 'failed', Inbox::SKIP_REASONS['not_admitted'] );

		$this->assertSame( 2, $this->count_unregistered( '' ) );
		$this->assertSame( 2, $this->count_unregistered( 'failed' ), 'unregistered_sender rows are always status=failed, so the "failed" filter must still see them.' );
		$this->assertSame( 0, $this->count_unregistered( 'pending' ), 'A status filter that can never contain unregistered_sender rows must short-circuit to 0 without a query.' );
	}

	// =========================================================================
	// Pagination
	// =========================================================================

	public function test_pagination_splits_rows_across_pages_without_overlap(): void {
		// PER_PAGE is 50 — insert enough rows to force a second page.
		$ids = [];
		for ( $i = 0; $i < 55; $i++ ) {
			$ids[] = $this->insert_row( sprintf( 'test-inboxfilter-page-%03d', $i ), 'pending' );
		}

		$page1 = $this->fetch( 'pending', '', 1 );
		$page2 = $this->fetch( 'pending', '', 2 );

		$this->assertSame( 55, $page1['total'] );
		$this->assertCount( 50, $page1['rows'], 'First page must return a full page (50 rows).' );
		$this->assertCount( 5, $page2['rows'], 'Second page must return the remaining 5 rows.' );

		$page1_ids = array_map( static fn( $r ) => (int) $r->id, $page1['rows'] );
		$page2_ids = array_map( static fn( $r ) => (int) $r->id, $page2['rows'] );

		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ), 'The two pages must never overlap.' );
		$this->assertEqualsCanonicalizing( $ids, array_merge( $page1_ids, $page2_ids ), 'Together, both pages must account for every inserted row.' );
	}
}
