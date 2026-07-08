<?php
/**
 * Integration tests — Scheduler's AI-drafted intro proposal
 * (propose_intro_if_due(), called from prepare() for each enabled type).
 *
 * Roughly 24h before a newsletter's next issue is due, prepare() drafts an
 * intro from Digest::build_intro_context() via a Pipeline stub here (no real
 * AI calls), saves it to the same agnosis_newsletter_{type}_intro option the
 * admin edits by hand, and emails the admin a proposal — captured via
 * pre_wp_mail, same pattern SchedulerSendTestTest already uses.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\AI\Pipeline;
use Agnosis\Newsletter\Scheduler;

class SchedulerIntroProposalTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( 'agnosis_newsletter_artist_enabled', true );
		update_option( 'agnosis_newsletter_public_enabled', true );
		update_option( 'agnosis_newsletter_public_frequency_days', 30 );
		update_option( 'agnosis_newsletter_artist_frequency_days', 30 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** A Pipeline stub whose generate_newsletter_intro() always returns a fixed value — no real AI call. */
	private function stub_pipeline( ?string $intro ): Pipeline {
		return new class( $intro ) extends Pipeline {
			private ?string $fixed_intro;
			public function __construct( ?string $intro ) {
				$this->fixed_intro = $intro;
			}
			public function generate_newsletter_intro( string $type, string $site_name, array $context ): string {
				return $this->fixed_intro ?? '';
			}
		};
	}

	/** Insert a fake 'sent' issue row so last_sent_at()/next_due_at() can be controlled precisely. */
	private function insert_sent_issue( string $type, string $sent_at ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_newsletter_issues',
			[ 'newsletter_type' => $type, 'status' => 'sent', 'sent_at' => $sent_at ],
			[ '%s', '%s', '%s' ]
		);
	}

	/** Same pattern as SchedulerSendTestTest — intercepts wp_mail via pre_wp_mail without sending. */
	private function capture_mail( ?array &$captured ): callable {
		$filter = function ( $pre, array $atts ) use ( &$captured ) {
			$captured = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		return $filter;
	}

	/** frequency_days = 30, sent $days_ago days ago — puts next_due_at() $days_ago days short of 30 from now. */
	private function seed_last_sent( string $type, float $days_ago ): void {
		$this->insert_sent_issue( $type, gmdate( 'Y-m-d H:i:s', (int) ( time() - $days_ago * DAY_IN_SECONDS ) ) );
	}

	// =========================================================================
	// Within the ~24h lead window
	// =========================================================================

	public function test_drafts_and_saves_intro_when_next_issue_is_within_lead_window(): void {
		// Sent 29.5 days ago, 30-day cadence — due in ~12h, comfortably inside the window.
		$this->seed_last_sent( 'public', 29.5 );

		$scheduler = new Scheduler( $this->stub_pipeline( 'A new season of work has arrived.' ) );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$scheduler->prepare();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertSame( 'A new season of work has arrived.', get_option( 'agnosis_newsletter_public_intro' ) );
	}

	public function test_emails_the_admin_with_the_drafted_intro(): void {
		update_option( 'admin_email', 'owner@example.com' );
		$this->seed_last_sent( 'public', 29.5 );

		$scheduler = new Scheduler( $this->stub_pipeline( 'A new season of work has arrived.' ) );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$scheduler->prepare();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertSame( 'owner@example.com', $captured['to'] );
		$this->assertStringContainsString( 'A new season of work has arrived.', $captured['message'] );

		// 2026-07-08: this carried no From header at all until a follow-up audit
		// caught it — wp_mail() would have fallen through to WordPress's own
		// "WordPress <wordpress@$domain>" default, the same leftover issue fixed
		// everywhere else in 0.9.9.
		$from_header = null;
		foreach ( (array) $captured['headers'] as $header ) {
			if ( str_starts_with( $header, 'From:' ) ) {
				$from_header = $header;
			}
		}
		$this->assertNotNull( $from_header, 'A From header must be present.' );
		$this->assertStringNotContainsString( 'wordpress@', $from_header );
	}

	// =========================================================================
	// Outside the lead window
	// =========================================================================

	public function test_does_nothing_when_next_issue_is_far_in_the_future(): void {
		// Sent 1 day ago, 30-day cadence — due in ~29 days, well outside the window.
		$this->seed_last_sent( 'public', 1 );

		$scheduler = new Scheduler( $this->stub_pipeline( 'Should not appear.' ) );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$scheduler->prepare();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertSame( '', get_option( 'agnosis_newsletter_public_intro', '' ) );
		$this->assertNull( $captured );
	}

	public function test_does_nothing_for_a_newsletter_that_has_never_sent(): void {
		// No sent issue at all — never-sent newsletters have no established
		// cadence to look ~24h ahead of (their first issue is always "due now").
		$scheduler = new Scheduler( $this->stub_pipeline( 'Should not appear.' ) );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$scheduler->prepare();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertSame( '', get_option( 'agnosis_newsletter_artist_intro', '' ) );
	}

	// =========================================================================
	// Respecting the admin's own text
	// =========================================================================

	public function test_does_not_overwrite_an_intro_the_admin_already_wrote(): void {
		update_option( 'agnosis_newsletter_public_intro', "Admin's own note." );
		$this->seed_last_sent( 'public', 29.5 );

		$scheduler = new Scheduler( $this->stub_pipeline( 'AI draft would go here.' ) );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$scheduler->prepare();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertSame( "Admin's own note.", get_option( 'agnosis_newsletter_public_intro' ) );
		$this->assertNull( $captured, 'No proposal email is sent when the admin already has their own text.' );
	}

	// =========================================================================
	// Dedup — only proposed once per cycle
	// =========================================================================

	public function test_does_not_regenerate_or_re_email_on_a_second_run_within_the_same_cycle(): void {
		$this->seed_last_sent( 'public', 29.5 );
		$scheduler = new Scheduler( $this->stub_pipeline( 'First draft.' ) );

		$captured1 = null;
		$filter1   = $this->capture_mail( $captured1 );
		$scheduler->prepare();
		remove_filter( 'pre_wp_mail', $filter1, 10 );
		$this->assertNotNull( $captured1, 'First run should propose and email.' );

		// Admin edits the drafted intro in between runs.
		update_option( 'agnosis_newsletter_public_intro', 'Admin edited this.' );

		$captured2 = null;
		$filter2   = $this->capture_mail( $captured2 );
		$scheduler->prepare();
		remove_filter( 'pre_wp_mail', $filter2, 10 );

		$this->assertNull( $captured2, 'A second run within the same cycle must not re-propose or re-email.' );
		$this->assertSame( 'Admin edited this.', get_option( 'agnosis_newsletter_public_intro' ), 'The admin\'s edit must survive a second run.' );
	}

	// =========================================================================
	// AI failure / nothing to summarise
	// =========================================================================

	public function test_no_email_and_intro_left_untouched_when_ai_returns_nothing(): void {
		$this->seed_last_sent( 'public', 29.5 );
		$scheduler = new Scheduler( $this->stub_pipeline( '' ) ); // AI failure or nothing to summarise.

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$scheduler->prepare();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNull( $captured );
		$this->assertSame( '', get_option( 'agnosis_newsletter_public_intro', '' ) );
	}

	// =========================================================================
	// Disabled newsletter
	// =========================================================================

	public function test_skips_a_disabled_newsletter_entirely(): void {
		update_option( 'agnosis_newsletter_public_enabled', false );
		$this->seed_last_sent( 'public', 29.5 );

		$scheduler = new Scheduler( $this->stub_pipeline( 'Should not appear.' ) );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$scheduler->prepare();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNull( $captured );
	}

	// =========================================================================
	// Each type is independent
	// =========================================================================

	public function test_artist_and_public_proposals_are_independent(): void {
		$this->seed_last_sent( 'public', 29.5 ); // within window
		$this->seed_last_sent( 'artist', 1 );  // far in the future

		$scheduler = new Scheduler( $this->stub_pipeline( 'Shared draft text.' ) );
		$scheduler->prepare();

		$this->assertSame( 'Shared draft text.', get_option( 'agnosis_newsletter_public_intro' ) );
		$this->assertSame( '', get_option( 'agnosis_newsletter_artist_intro', '' ) );
	}

	// =========================================================================
	// agnosis_newsletter_intro_proposal_enabled — master on/off switch
	// =========================================================================

	public function test_does_nothing_when_the_feature_is_disabled(): void {
		// Deliberately 0, not false: update_option() compares the new value
		// against the old one with ===, and get_option() returns false for a
		// row that doesn't exist yet — passing false here would be seen as
		// "no change" against that not-found sentinel and silently skip the
		// write, leaving the option unset and the default (enabled) in effect.
		update_option( 'agnosis_newsletter_intro_proposal_enabled', 0 );
		$this->seed_last_sent( 'public', 29.5 ); // would otherwise be within window

		$scheduler = new Scheduler( $this->stub_pipeline( 'Should not appear.' ) );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$scheduler->prepare();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNull( $captured );
		$this->assertSame( '', get_option( 'agnosis_newsletter_public_intro', '' ) );
	}

	public function test_enabled_by_default(): void {
		// No option set at all — must behave as if explicitly enabled.
		$this->seed_last_sent( 'public', 29.5 );

		$scheduler = new Scheduler( $this->stub_pipeline( 'Drafted by default.' ) );
		$scheduler->prepare();

		$this->assertSame( 'Drafted by default.', get_option( 'agnosis_newsletter_public_intro' ) );
	}

	// =========================================================================
	// agnosis_newsletter_intro_proposal_lead_hours — configurable lead time
	// =========================================================================

	public function test_custom_lead_hours_widens_the_window(): void {
		update_option( 'agnosis_newsletter_intro_proposal_lead_hours', 72 ); // 3 days ahead
		// Sent 27.5 days ago, 30-day cadence — due in 2.5 days: inside a 72h
		// window but would have been outside the default 24h one.
		$this->seed_last_sent( 'public', 27.5 );

		$scheduler = new Scheduler( $this->stub_pipeline( 'Drafted with a wider window.' ) );
		$scheduler->prepare();

		$this->assertSame( 'Drafted with a wider window.', get_option( 'agnosis_newsletter_public_intro' ) );
	}

	public function test_custom_lead_hours_narrows_the_window(): void {
		update_option( 'agnosis_newsletter_intro_proposal_lead_hours', 6 ); // only 6h ahead
		// Sent 29.5 days ago, 30-day cadence — due in ~12h: inside the default
		// 24h window but outside a narrowed 6h one.
		$this->seed_last_sent( 'public', 29.5 );

		$scheduler = new Scheduler( $this->stub_pipeline( 'Should not appear.' ) );
		$scheduler->prepare();

		$this->assertSame( '', get_option( 'agnosis_newsletter_public_intro', '' ) );
	}

	public function test_lead_hours_defaults_to_24_when_unset(): void {
		// No option set — sent 29.5 days ago (due in ~12h) must still land
		// inside the window under the implicit default.
		$this->seed_last_sent( 'public', 29.5 );

		$scheduler = new Scheduler( $this->stub_pipeline( 'Default window applies.' ) );
		$scheduler->prepare();

		$this->assertSame( 'Default window applies.', get_option( 'agnosis_newsletter_public_intro' ) );
	}
}
