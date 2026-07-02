<?php
/**
 * Integration tests — Scheduler::send_test() (the "Send a test" tool on
 * Settings → Newsletter).
 *
 * Covers: validation, subject/body contents (TEST prefix + notice), and that
 * a test send never writes to agnosis_newsletter_issues or _queue — it is a
 * pure preview, safe to run at any time without affecting the real schedule.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\Scheduler;

class SchedulerSendTestTest extends \WP_UnitTestCase {

	private Scheduler $scheduler;

	protected function setUp(): void {
		parent::setUp();
		$this->scheduler = new Scheduler();
	}

	/**
	 * Intercept wp_mail via pre_wp_mail — same pattern as NotificationEmailTest.
	 *
	 * @param array<string, mixed>|null $captured Reference populated with the mail args.
	 */
	private function capture_mail( ?array &$captured ): callable {
		$filter = function ( $pre, array $atts ) use ( &$captured ) {
			$captured = $atts;
			return true; // Prevent actual sending.
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		return $filter;
	}

	private function table_counts(): array {
		global $wpdb;
		return [
			'issues' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_issues" ),
			'queue'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_queue" ),
		];
	}

	public function test_rejects_unknown_type(): void {
		$result = $this->scheduler->send_test( 'bogus', 'admin@example.com' );

		$this->assertIsString( $result );
	}

	public function test_rejects_invalid_email(): void {
		$result = $this->scheduler->send_test( 'public', 'not-an-email' );

		$this->assertIsString( $result );
	}

	public function test_sends_to_the_given_address(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$result = $this->scheduler->send_test( 'public', 'preview@example.com' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertTrue( $result );
		$this->assertNotNull( $captured );
		$this->assertSame( 'preview@example.com', $captured['to'] );
	}

	public function test_subject_is_prefixed_with_test(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->scheduler->send_test( 'artist', 'preview@example.com' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringStartsWith( '[TEST]', $captured['subject'] );
	}

	public function test_body_contains_test_notice(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->scheduler->send_test( 'public', 'preview@example.com' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( 'This is a TEST send', $captured['message'] );
	}

	public function test_body_includes_current_draft_intro(): void {
		update_option( 'agnosis_newsletter_public_intro', 'Hello from the draft intro!' );

		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->scheduler->send_test( 'public', 'preview@example.com' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( 'Hello from the draft intro!', $captured['message'] );
	}

	public function test_intro_option_is_not_cleared_by_a_test_send(): void {
		update_option( 'agnosis_newsletter_public_intro', 'Keep me around' );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$this->scheduler->send_test( 'public', 'preview@example.com' );
		remove_filter( 'pre_wp_mail', $filter, 10 );

		// Unlike a real send (Scheduler::prepare_type()), a test must be a
		// pure preview — the admin's draft intro must survive it untouched.
		$this->assertSame( 'Keep me around', get_option( 'agnosis_newsletter_public_intro' ) );
	}

	public function test_does_not_write_to_issues_or_queue_tables(): void {
		$before = $this->table_counts();

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$this->scheduler->send_test( 'artist', 'preview@example.com' );
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$after = $this->table_counts();

		$this->assertSame( $before, $after, 'send_test() must not touch the issues/queue tables.' );
	}

	public function test_does_not_affect_is_due_or_last_sent(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$this->scheduler->send_test( 'public', 'preview@example.com' );
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNull( $this->scheduler->last_sent_at( 'public' ) );
		$this->assertFalse( $this->scheduler->has_issue_in_flight( 'public' ) );
	}
}
