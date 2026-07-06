<?php
/**
 * Integration tests — QueueProcessor (batch sending of a prepared issue).
 *
 * Uses Scheduler::send_now() to set up a realistic issue + queue (rather than
 * hand-inserting rows) so these tests exercise the real fan-out shape, then
 * drives QueueProcessor::process() directly while intercepting wp_mail via
 * pre_wp_mail (same pattern as NotificationEmailTest / SchedulerSendTestTest).
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\Archive;
use Agnosis\Newsletter\QueueProcessor;
use Agnosis\Newsletter\Scheduler;
use Agnosis\Newsletter\Subscriber;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;

class QueueProcessorTest extends \WP_UnitTestCase {

	private QueueProcessor $processor;
	private Scheduler $scheduler;

	protected function setUp(): void {
		parent::setUp();
		$this->processor = new QueueProcessor();
		$this->scheduler  = new Scheduler();
		update_option( 'agnosis_newsletter_batch_size', 20 );
		FakeLinguaForge::reset();
	}

	protected function tearDown(): void {
		FakeLinguaForge::reset();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_artist(): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_userdata( $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	private function create_confirmed_subscriber( string $email ): void {
		$result = Subscriber::subscribe( $email );
		Subscriber::confirm( $result['token'] );
	}

	private function latest_issue( string $type ): object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_newsletter_issues WHERE newsletter_type = %s ORDER BY id DESC LIMIT 1",
				$type
			)
		);
	}

	private function queue_status_counts( int $issue_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) as total FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE issue_id = %d GROUP BY status",
				$issue_id
			),
			ARRAY_A
		);
		$counts = [ 'pending' => 0, 'sent' => 0, 'failed' => 0 ];
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Intercept wp_mail via pre_wp_mail, recording every call and letting the
	 * caller decide success/failure per-address.
	 *
	 * @param array<int, array<string, mixed>> $calls     Reference, appended to on every call.
	 * @param callable|null                     $result_for fn(array $atts): bool — defaults to always-success.
	 */
	private function capture_mail( array &$calls, ?callable $result_for = null ): callable {
		$filter = function ( $pre, array $atts ) use ( &$calls, $result_for ) {
			$calls[] = $atts;
			return $result_for ? $result_for( $atts ) : true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		return $filter;
	}

	// =========================================================================
	// Batch size
	// =========================================================================

	public function test_process_sends_no_more_than_the_configured_batch_size(): void {
		update_option( 'agnosis_newsletter_batch_size', 2 );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_confirmed_subscriber( "sub{$i}@example.com" );
		}
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertCount( 2, $calls, 'Only batch_size recipients should be emailed per tick.' );

		$counts = $this->queue_status_counts( (int) $issue->id );
		$this->assertSame( 2, $counts['sent'] );
		$this->assertSame( 3, $counts['pending'], 'The rest must remain pending for the next tick.' );
	}

	public function test_process_drains_a_small_issue_in_one_tick(): void {
		$this->create_confirmed_subscriber( 'only@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertCount( 1, $calls );
		$counts = $this->queue_status_counts( (int) $issue->id );
		$this->assertSame( 1, $counts['sent'] );
		$this->assertSame( 0, $counts['pending'] );
	}

	// =========================================================================
	// Success / failure marking, bounded retry (audit §3d)
	// =========================================================================

	private function queue_row( int $issue_id ): object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE issue_id = %d LIMIT 1",
				$issue_id
			)
		);
	}

	public function test_failed_send_stays_pending_and_increments_attempts(): void {
		$this->create_confirmed_subscriber( 'bounces@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		$calls  = [];
		$filter = $this->capture_mail( $calls, fn( array $atts ) => false ); // simulate wp_mail() failure
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$counts = $this->queue_status_counts( (int) $issue->id );
		$this->assertSame( 0, $counts['failed'], 'A single failure must not be terminal — it should be retried.' );
		$this->assertSame( 1, $counts['pending'] );

		$row = $this->queue_row( (int) $issue->id );
		$this->assertSame( 1, (int) $row->attempts );
	}

	public function test_row_is_retried_on_the_next_tick_after_a_failure(): void {
		$this->create_confirmed_subscriber( 'bounces@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		$attempts_seen = 0;
		$calls_unused  = [];
		$filter        = $this->capture_mail(
			$calls_unused,
			function ( array $atts ) use ( &$attempts_seen ) {
				$attempts_seen++;
				return false;
			}
		);
		$this->processor->process(); // attempt 1 — fails, stays pending
		$this->processor->process(); // attempt 2 — fails, stays pending
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertSame( 2, $attempts_seen, 'wp_mail() must be retried on a later tick, not skipped once pending.' );

		$row = $this->queue_row( (int) $issue->id );
		$this->assertSame( 'pending', $row->status );
		$this->assertSame( 2, (int) $row->attempts );
	}

	public function test_row_becomes_terminally_failed_once_max_attempts_exhausted(): void {
		$this->create_confirmed_subscriber( 'bounces@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		$calls  = [];
		$filter = $this->capture_mail( $calls, fn( array $atts ) => false );
		// QueueProcessor::MAX_ATTEMPTS is 3 — three failing ticks must exhaust it.
		$this->processor->process();
		$this->processor->process();
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$row = $this->queue_row( (int) $issue->id );
		$this->assertSame( 'failed', $row->status );
		$this->assertSame( 3, (int) $row->attempts );
		$this->assertNotEmpty( $row->resolved_at, 'resolved_at should be stamped as the final-failure time.' );

		$counts = $this->queue_status_counts( (int) $issue->id );
		$this->assertSame( 1, $counts['failed'] );
		$this->assertSame( 0, $counts['pending'] );
	}

	public function test_row_succeeding_after_a_prior_failure_is_marked_sent(): void {
		$this->create_confirmed_subscriber( 'flaky@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		$calls_a     = [];
		$fail_filter = $this->capture_mail( $calls_a, fn( array $atts ) => false );
		$this->processor->process(); // attempt 1 — fails, stays pending
		remove_filter( 'pre_wp_mail', $fail_filter, 10 );

		$calls_b   = [];
		$ok_filter = $this->capture_mail( $calls_b ); // defaults to success
		$this->processor->process(); // attempt 2 — succeeds
		remove_filter( 'pre_wp_mail', $ok_filter, 10 );

		$row = $this->queue_row( (int) $issue->id );
		$this->assertSame( 'sent', $row->status );
	}

	// =========================================================================
	// Issue completion
	// =========================================================================

	public function test_issue_marked_sent_once_queue_fully_drains(): void {
		$this->create_confirmed_subscriber( 'a@example.com' );
		$this->create_confirmed_subscriber( 'b@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );
		$this->assertSame( 'sending', $issue->status );

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$issue_after = $this->latest_issue( 'public' );
		$this->assertSame( 'sent', $issue_after->status );
		$this->assertNotEmpty( $issue_after->sent_at );
		$this->assertSame( 2, (int) $issue_after->recipient_count );
	}

	public function test_issue_stays_sending_while_recipients_remain_pending(): void {
		update_option( 'agnosis_newsletter_batch_size', 1 );
		$this->create_confirmed_subscriber( 'a@example.com' );
		$this->create_confirmed_subscriber( 'b@example.com' );
		$this->scheduler->send_now( 'public' );

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process(); // only 1 of 2 sent this tick
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$issue = $this->latest_issue( 'public' );
		$this->assertSame( 'sending', $issue->status, 'Issue must not be marked sent while recipients remain pending.' );
	}

	/**
	 * Regression test: an issue whose every recipient's send fails must still
	 * end up 'sent' (with recipient_count 0) once the queue drains — not stuck
	 * in 'sending' forever. Previously only issues with at least one
	 * successfully-sent row were reconciled, so an issue where every wp_mail()
	 * call failed became permanently invisible to future ticks (its rows were
	 * already 'failed', not 'pending') and stayed "Sending…" on the admin
	 * dashboard with "Send Now" disabled indefinitely.
	 */
	public function test_issue_is_completed_even_when_every_recipient_fails(): void {
		$this->create_confirmed_subscriber( 'bounces@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		$calls  = [];
		$filter = $this->capture_mail( $calls, fn( array $atts ) => false ); // every send fails
		// The row stays 'pending' (bounded retry, audit §3d) until MAX_ATTEMPTS
		// ticks have failed — only then does it become terminal and unblock
		// issue reconciliation.
		$this->processor->process();
		$this->processor->process();
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$issue_after = $this->latest_issue( 'public' );
		$this->assertSame( 'sent', $issue_after->status, 'A fully-failed issue must still be reconciled to a terminal state.' );
		$this->assertSame( 0, (int) $issue_after->recipient_count );
	}

	/**
	 * The reconciliation pass must also resolve an issue that was already
	 * stuck before this tick — e.g. one whose rows all failed in a previous
	 * cron run and were therefore no longer 'pending' at all, so process()
	 * would otherwise never look at it again (nothing to send).
	 */
	public function test_already_stuck_issue_is_reconciled_even_with_nothing_pending(): void {
		global $wpdb;

		$this->create_confirmed_subscriber( 'stuck@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		// Simulate the row having already failed in an earlier tick, before
		// this fix existed — nothing left 'pending' for process() to send.
		$wpdb->update(
			$wpdb->prefix . 'agnosis_newsletter_queue',
			[ 'status' => 'failed' ],
			[ 'issue_id' => $issue->id ],
			[ '%s' ],
			[ '%d' ]
		);
		$this->assertSame( 'sending', $this->latest_issue( 'public' )->status );

		$this->processor->process(); // no pending rows anywhere, nothing to send

		$issue_after = $this->latest_issue( 'public' );
		$this->assertSame( 'sent', $issue_after->status, 'A previously-stuck issue must be reconciled even when process() finds nothing pending to send.' );
	}

	// =========================================================================
	// Uncaught exceptions during send (regression, fixed 2026-07-06)
	// =========================================================================

	/**
	 * Regression test: a single recipient's send_one() throwing (a bad locale
	 * render, a branding/image error building that particular email, etc.)
	 * previously killed the whole batch loop before reconcile_sending_issues()
	 * at the end of process() was ever reached — leaving every currently-
	 * 'sending' issue, not just the one whose recipient errored, permanently
	 * stuck ("Sending…" with Send Now disabled on the admin dashboard), since
	 * the exact same failure recurred on every subsequent cron tick before
	 * reconcile ever ran again. send_one() is now called inside a try/catch,
	 * so a throw is treated exactly like a normal wp_mail() failure (retried,
	 * bounded) and never blocks the rest of the batch or reconciliation.
	 */
	public function test_one_recipient_throwing_does_not_abort_the_rest_of_the_batch(): void {
		$this->create_confirmed_subscriber( 'throws@example.com' );
		$this->create_confirmed_subscriber( 'ok@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		$filter = function ( $pre, array $atts ) {
			if ( 'throws@example.com' === $atts['to'] ) {
				throw new \RuntimeException( 'simulated failure building this recipient\'s email' );
			}
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT recipient_email, status, attempts FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE issue_id = %d",
				$issue->id
			),
			ARRAY_A
		);
		$by_email = array_column( $rows, null, 'recipient_email' );

		$this->assertSame( 'sent', $by_email['ok@example.com']['status'], 'A throwing sibling recipient must not prevent this one from sending.' );
		$this->assertSame( 'pending', $by_email['throws@example.com']['status'], 'A thrown exception must be treated as a retryable failure, not lost.' );
		$this->assertSame( 1, (int) $by_email['throws@example.com']['attempts'] );
	}

	/**
	 * Once the throwing recipient exhausts MAX_ATTEMPTS and becomes terminally
	 * 'failed', the issue must still be reconciled to 'sent' — reconcile_sending_issues()
	 * must never be skipped just because every tick's loop encountered a throw.
	 */
	public function test_issue_completes_after_a_persistently_throwing_recipient_exhausts_retries(): void {
		$this->create_confirmed_subscriber( 'throws@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		$filter = function () {
			throw new \RuntimeException( 'simulated persistent failure' );
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		$this->processor->process();
		$this->processor->process();
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$issue_after = $this->latest_issue( 'public' );
		$this->assertSame( 'sent', $issue_after->status, 'The issue must still be reconciled even though every tick threw.' );

		$row = $this->queue_row( (int) $issue->id );
		$this->assertSame( 'failed', $row->status );
		$this->assertSame( 3, (int) $row->attempts );
	}

	// =========================================================================
	// Orphaned rows (issue deleted mid-send)
	// =========================================================================

	public function test_orphaned_queue_row_is_marked_failed_without_sending(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'agnosis_newsletter_queue',
			[
				'issue_id'          => 999999, // no such issue
				'recipient_email'   => 'orphan@example.com',
				'recipient_type'    => 'public',
				'unsubscribe_token' => 'abc123',
				'status'            => 'pending',
			],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertCount( 0, $calls, 'An orphaned row must not trigger wp_mail() at all.' );

		$status = $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE recipient_email = 'orphan@example.com'" );
		$this->assertSame( 'failed', $status );
	}

	// =========================================================================
	// Per-locale content selection
	// =========================================================================

	/** See DigestTest::link_as_translations() — same FakeLinguaForge approach. */
	private function link_as_translations( int $original_id, string $original_lang, int $translated_id, string $translated_lang ): void {
		update_post_meta( $original_id, '_lf_lang', $original_lang );
		update_post_meta( $translated_id, '_lf_lang', $translated_lang );
		FakeLinguaForge::link( $original_id, $translated_lang, $translated_id );
	}

	public function test_process_sends_each_recipient_the_content_for_their_own_locale(): void {
		$original_id   = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_title'  => 'English Piece',
			'post_author' => self::factory()->user->create(),
		] );
		$translated_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_title'  => 'Pieza en Español',
			'post_author' => self::factory()->user->create(),
		] );
		$this->link_as_translations( $original_id, 'en', $translated_id, 'es' );

		$result = Subscriber::subscribe( 'es@example.com', 'es_ES' );
		Subscriber::confirm( $result['token'] );
		$this->create_confirmed_subscriber( 'default@example.com' );

		$this->scheduler->send_now( 'public' );

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$by_email = [];
		foreach ( $calls as $call ) {
			$by_email[ $call['to'] ] = $call['message'];
		}

		$this->assertStringContainsString( 'Pieza en Español', $by_email['es@example.com'] );
		$this->assertStringNotContainsString( 'Pieza en Español', $by_email['default@example.com'] );
	}

	public function test_process_falls_back_to_base_content_when_row_locale_missing_from_map(): void {
		global $wpdb;

		$this->create_confirmed_subscriber( 'a@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		// Simulate a row whose locale was never recorded in the issue's map
		// (e.g. data from before this feature existed) — must not error or send
		// an empty body, just fall back to the issue's base intro/digest_html.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_newsletter_queue',
			[ 'locale' => 'xx_XX' ],
			[ 'issue_id' => $issue->id ],
			[ '%s' ],
			[ '%d' ]
		);

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertCount( 1, $calls );
		$this->assertStringContainsString( (string) $issue->digest_html, $calls[0]['message'] );
	}

	// =========================================================================
	// Headers
	// =========================================================================

	public function test_sent_mail_includes_list_unsubscribe_header(): void {
		$this->create_confirmed_subscriber( 'a@example.com' );
		$this->scheduler->send_now( 'public' );

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$headers = implode( "\n", (array) $calls[0]['headers'] );
		$this->assertStringContainsString( 'List-Unsubscribe:', $headers );
	}

	/**
	 * RFC 8058 one-click unsubscribe (audit §2b) — Gmail/Yahoo bulk-sender
	 * rules and the Gmail/Outlook "Unsubscribe" UI affordance both key off
	 * this header pair being present together.
	 */
	public function test_sent_mail_includes_rfc_8058_list_unsubscribe_post_header(): void {
		$this->create_confirmed_subscriber( 'a@example.com' );
		$this->scheduler->send_now( 'public' );

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$headers = implode( "\n", (array) $calls[0]['headers'] );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $headers );
	}

	public function test_artist_unsubscribe_url_includes_uid(): void {
		$artist_id = $this->create_artist();
		$this->scheduler->send_now( 'artist' );

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$headers = implode( "\n", (array) $calls[0]['headers'] );
		$this->assertStringContainsString( 'uid=' . $artist_id, $headers );
	}

	public function test_public_unsubscribe_url_has_no_uid_param(): void {
		$this->create_confirmed_subscriber( 'a@example.com' );
		$this->scheduler->send_now( 'public' );

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$headers = implode( "\n", (array) $calls[0]['headers'] );
		$this->assertStringNotContainsString( 'uid=', $headers );
	}

	// =========================================================================
	// "View in browser" link (Newsletter\Archive, added 2026-07-06)
	// =========================================================================

	public function test_public_issue_email_includes_view_online_link(): void {
		$this->create_confirmed_subscriber( 'a@example.com' );
		$this->scheduler->send_now( 'public' );
		$issue = $this->latest_issue( 'public' );

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( 'View it online', $calls[0]['message'] );
		$this->assertStringContainsString( Archive::issue_permalink( (int) $issue->id ), $calls[0]['message'] );
	}

	/**
	 * The artist newsletter's content is community-internal (open votes,
	 * new-member names — see Digest::build_artist()) and Newsletter\Archive
	 * only ever serves newsletter_type='public' issues, so an artist-type
	 * send must never point recipients at a "view online" link at all.
	 */
	public function test_artist_issue_email_omits_view_online_link(): void {
		$this->create_artist();
		$this->scheduler->send_now( 'artist' );

		$calls  = [];
		$filter = $this->capture_mail( $calls );
		$this->processor->process();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringNotContainsString( 'View it online', $calls[0]['message'] );
		$this->assertStringNotContainsString( '/newsletter/', $calls[0]['message'] );
	}
}
