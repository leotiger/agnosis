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

	// -------------------------------------------------------------------------
	// §13 F5 (2026-07-30) — deferred placeholders must never survive a preview.
	//
	// The artist digest carries three markers that are only ever resolved per
	// recipient, in QueueProcessor::send_one(): {{AGNOSIS_LIKE:<id>}} and
	// {{AGNOSIS_BOOST:<id>}} (WP2/WP5, since 0.9.59) and, as of this release,
	// {{AGNOSIS_INTERACTION_SUMMARY}} (NL1). A test send has no recipient row
	// at all and never reaches that stage, so all three were being emailed as
	// literal text. send_test() now neutralizes them the same way
	// Newsletter\Archive already does for its own no-recipient rendering.
	// -------------------------------------------------------------------------

	/** @return string[] */
	private function placeholder_fragments(): array {
		return [ '{{AGNOSIS_LIKE', '{{AGNOSIS_BOOST', '{{AGNOSIS_INTERACTION_SUMMARY' ];
	}

	public function test_artist_preview_body_contains_no_raw_placeholders(): void {
		// A published artwork is what makes build_artist() emit the per-post
		// like/boost placeholders in the first place — without one, only the
		// interaction-summary marker would be present and this would pass for
		// the wrong reason.
		$artist_id = self::factory()->user->create( [ 'role' => 'agnosis_artist' ] );
		self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist_id,
			'post_title'  => 'A Piece To Boost',
		] );

		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->scheduler->send_test( 'artist', 'preview@example.com' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		foreach ( $this->placeholder_fragments() as $fragment ) {
			$this->assertStringNotContainsString(
				$fragment,
				$captured['message'],
				"A test send must never email the raw {$fragment}…}} marker — it has no recipient to resolve it against."
			);
		}
	}

	public function test_public_preview_body_contains_no_raw_placeholders(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->scheduler->send_test( 'public', 'preview@example.com' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		foreach ( $this->placeholder_fragments() as $fragment ) {
			$this->assertStringNotContainsString( $fragment, $captured['message'] );
		}
	}
}
