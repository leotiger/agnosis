<?php
/**
 * Integration tests — Scheduler (issue prep, recipient fan-out, due-date logic).
 *
 * send_test() (the preview tool) has its own suite in SchedulerSendTestTest.
 * This suite covers the real send path: send_now() / prepare() building an
 * actual issue row and fanning it out into the queue table.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\Scheduler;
use Agnosis\Newsletter\Subscriber;

class SchedulerTest extends \WP_UnitTestCase {

	private Scheduler $scheduler;

	protected function setUp(): void {
		parent::setUp();
		$this->scheduler = new Scheduler();

		update_option( 'agnosis_newsletter_artist_enabled', true );
		update_option( 'agnosis_newsletter_public_enabled', true );
		update_option( 'agnosis_newsletter_artist_frequency_days', 30 );
		update_option( 'agnosis_newsletter_public_frequency_days', 30 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_artist( bool $opted_out = false ): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_userdata( $id );
		$user->add_role( 'agnosis_artist' );
		if ( $opted_out ) {
			update_user_meta( $id, '_agnosis_newsletter_optout', '1' );
		}
		return $id;
	}

	private function create_confirmed_subscriber( string $email ): void {
		$result = Subscriber::subscribe( $email );
		Subscriber::confirm( $result['token'] );
	}

	/** Insert a fake 'sent' issue row to control last_sent_at() in tests. */
	private function insert_sent_issue( string $type, string $sent_at ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_newsletter_issues',
			[
				'newsletter_type' => $type,
				'status'          => 'sent',
				'sent_at'         => $sent_at,
			],
			[ '%s', '%s', '%s' ]
		);
	}

	private function get_latest_issue( string $type ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_newsletter_issues WHERE newsletter_type = %s ORDER BY id DESC LIMIT 1",
				$type
			)
		);
	}

	private function queue_count_for_latest_issue( string $type ): int {
		global $wpdb;
		$issue = $this->get_latest_issue( $type );
		if ( ! $issue ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE issue_id = %d",
				$issue->id
			)
		);
	}

	// =========================================================================
	// send_now() — validation
	// =========================================================================

	public function test_send_now_rejects_unknown_type(): void {
		$result = $this->scheduler->send_now( 'bogus' );

		$this->assertIsString( $result );
	}

	public function test_send_now_refuses_when_issue_already_in_flight(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_newsletter_issues',
			[ 'newsletter_type' => 'public', 'status' => 'sending' ],
			[ '%s', '%s' ]
		);

		$result = $this->scheduler->send_now( 'public' );

		$this->assertIsString( $result, 'send_now() must refuse to start a second issue while one is sending.' );
	}

	// =========================================================================
	// send_now() — artist recipient fan-out
	// =========================================================================

	public function test_send_now_artist_queues_admitted_non_optout_artists(): void {
		$this->create_artist( false );
		$this->create_artist( false );

		$result = $this->scheduler->send_now( 'artist' );

		$this->assertTrue( $result );
		$this->assertSame( 2, $this->queue_count_for_latest_issue( 'artist' ) );
	}

	public function test_send_now_artist_excludes_opted_out_artists(): void {
		$this->create_artist( false );
		$this->create_artist( true ); // opted out

		$this->scheduler->send_now( 'artist' );

		$this->assertSame( 1, $this->queue_count_for_latest_issue( 'artist' ) );
	}

	public function test_send_now_artist_with_zero_recipients_marks_issue_sent_immediately(): void {
		// No artists exist at all.
		$result = $this->scheduler->send_now( 'artist' );

		$this->assertTrue( $result );
		$issue = $this->get_latest_issue( 'artist' );
		$this->assertSame( 'sent', $issue->status );
		$this->assertSame( 0, $this->queue_count_for_latest_issue( 'artist' ) );
	}

	// =========================================================================
	// send_now() — public recipient fan-out
	// =========================================================================

	public function test_send_now_public_queues_only_confirmed_subscribers(): void {
		$this->create_confirmed_subscriber( 'confirmed@example.com' );
		Subscriber::subscribe( 'pending@example.com' ); // stays pending — must be excluded

		$this->scheduler->send_now( 'public' );

		$this->assertSame( 1, $this->queue_count_for_latest_issue( 'public' ) );
	}

	public function test_send_now_public_excludes_unsubscribed(): void {
		$this->create_confirmed_subscriber( 'staying@example.com' );

		$left = Subscriber::subscribe( 'left@example.com' );
		Subscriber::confirm( $left['token'] );
		Subscriber::unsubscribe( $left['token'] );

		$this->scheduler->send_now( 'public' );

		$this->assertSame( 1, $this->queue_count_for_latest_issue( 'public' ) );
	}

	// =========================================================================
	// send_now() — intro handling
	// =========================================================================

	public function test_send_now_stores_current_intro_on_the_issue(): void {
		update_option( 'agnosis_newsletter_public_intro', 'This month...' );
		$this->create_confirmed_subscriber( 'a@example.com' );

		$this->scheduler->send_now( 'public' );

		$issue = $this->get_latest_issue( 'public' );
		$this->assertSame( 'This month...', $issue->intro );
	}

	public function test_send_now_clears_intro_option_after_queuing_with_recipients(): void {
		update_option( 'agnosis_newsletter_public_intro', 'One-shot note' );
		$this->create_confirmed_subscriber( 'a@example.com' );

		$this->scheduler->send_now( 'public' );

		$this->assertSame( '', get_option( 'agnosis_newsletter_public_intro' ) );
	}

	// =========================================================================
	// Per-recipient locale grouping
	// =========================================================================

	private function queue_locale( int $issue_id, string $recipient_email ): ?string {
		global $wpdb;
		$locale = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT locale FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE issue_id = %d AND recipient_email = %s",
				$issue_id,
				$recipient_email
			)
		);
		return null === $locale ? null : (string) $locale;
	}

	public function test_send_now_public_recipient_locale_flows_to_queue_row(): void {
		$result = Subscriber::subscribe( 'es@example.com', 'es_ES' );
		Subscriber::confirm( $result['token'] );
		$this->create_confirmed_subscriber( 'default@example.com' ); // no locale captured

		$this->scheduler->send_now( 'public' );

		$issue = $this->get_latest_issue( 'public' );
		$this->assertSame( 'es_ES', $this->queue_locale( (int) $issue->id, 'es@example.com' ) );
		$this->assertSame( get_locale(), $this->queue_locale( (int) $issue->id, 'default@example.com' ) );
	}

	public function test_send_now_artist_recipient_locale_from_user_meta(): void {
		$id = $this->create_artist();
		update_user_meta( $id, 'locale', 'de_DE' );

		$this->scheduler->send_now( 'artist' );

		global $wpdb;
		$issue  = $this->get_latest_issue( 'artist' );
		$locale = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT locale FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE issue_id = %d AND recipient_id = %d",
				$issue->id,
				$id
			)
		);

		$this->assertSame( 'de_DE', $locale );
	}

	public function test_send_now_artist_recipient_falls_back_to_site_locale_when_unset(): void {
		$id = $this->create_artist(); // no 'locale' user meta at all

		$this->scheduler->send_now( 'artist' );

		global $wpdb;
		$issue  = $this->get_latest_issue( 'artist' );
		$locale = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT locale FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE issue_id = %d AND recipient_id = %d",
				$issue->id,
				$id
			)
		);

		$this->assertSame( get_locale(), $locale );
	}

	public function test_send_now_stores_locale_content_map_keyed_by_locale(): void {
		$result = Subscriber::subscribe( 'fr@example.com', 'fr_FR' );
		Subscriber::confirm( $result['token'] );
		$this->create_confirmed_subscriber( 'default@example.com' );

		$this->scheduler->send_now( 'public' );

		$issue = $this->get_latest_issue( 'public' );
		$map   = json_decode( (string) $issue->locale_content, true );

		$this->assertIsArray( $map );
		$this->assertArrayHasKey( 'fr_FR', $map );
		$this->assertArrayHasKey( get_locale(), $map );
		$this->assertArrayHasKey( 'intro', $map[ get_locale() ] );
		$this->assertArrayHasKey( 'digest_html', $map[ get_locale() ] );

		// The issue's base intro/digest_html columns mirror the default-locale entry.
		$this->assertSame( $issue->intro, $map[ get_locale() ]['intro'] );
		$this->assertSame( $issue->digest_html, $map[ get_locale() ]['digest_html'] );
	}

	public function test_send_now_default_locale_group_present_even_with_zero_recipients(): void {
		// No recipients at all — prepare_type() must still compute a base render.
		$this->scheduler->send_now( 'public' );

		$issue = $this->get_latest_issue( 'public' );
		$map   = json_decode( (string) $issue->locale_content, true );

		$this->assertArrayHasKey( get_locale(), $map );
	}

	public function test_send_now_intro_stays_untranslated_without_an_ai_provider_configured(): void {
		// Ensure no AI provider is configured, matching a fresh install.
		delete_option( 'agnosis_openai_api_key' );
		delete_option( 'agnosis_anthropic_api_key' );
		update_option( 'agnosis_ai_provider', 'openai' );
		update_option( 'agnosis_newsletter_public_intro', 'Hello everyone' );

		$result = Subscriber::subscribe( 'fr@example.com', 'fr_FR' );
		Subscriber::confirm( $result['token'] );

		$this->scheduler->send_now( 'public' );

		$issue = $this->get_latest_issue( 'public' );
		$map   = json_decode( (string) $issue->locale_content, true );

		$this->assertSame(
			'Hello everyone',
			$map['fr_FR']['intro'],
			'Without a configured AI provider, a non-default-locale intro must pass through unmodified rather than block the send.'
		);
	}

	// =========================================================================
	// prepare() — enable flags + due-date logic
	// =========================================================================

	public function test_prepare_skips_disabled_newsletter(): void {
		update_option( 'agnosis_newsletter_public_enabled', false );

		$this->scheduler->prepare();

		$this->assertNull( $this->get_latest_issue( 'public' ) );
	}

	public function test_prepare_creates_first_issue_when_never_sent(): void {
		$this->create_artist();

		$this->scheduler->prepare();

		$this->assertNotNull( $this->get_latest_issue( 'artist' ), 'A newsletter that has never sent must always be due.' );
	}

	public function test_prepare_does_not_recreate_issue_before_frequency_elapsed(): void {
		update_option( 'agnosis_newsletter_public_frequency_days', 30 );
		$this->insert_sent_issue( 'public', gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) );

		$this->scheduler->prepare();

		// Only the one seeded row should exist — prepare() must not have added another.
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_issues WHERE newsletter_type = 'public'" );
		$this->assertSame( 1, $count );
	}

	public function test_prepare_creates_new_issue_after_frequency_elapsed(): void {
		update_option( 'agnosis_newsletter_public_frequency_days', 30 );
		$this->insert_sent_issue( 'public', gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) );
		$this->create_confirmed_subscriber( 'a@example.com' );

		$this->scheduler->prepare();

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_issues WHERE newsletter_type = 'public'" );
		$this->assertSame( 2, $count, 'A second issue must be created once the frequency window has elapsed.' );
	}

	// =========================================================================
	// last_sent_at() / has_issue_in_flight()
	// =========================================================================

	public function test_last_sent_at_returns_null_when_never_sent(): void {
		$this->assertNull( $this->scheduler->last_sent_at( 'artist' ) );
	}

	public function test_last_sent_at_returns_most_recent_sent_timestamp(): void {
		$this->insert_sent_issue( 'artist', '2026-01-01 00:00:00' );
		$this->insert_sent_issue( 'artist', '2026-03-01 00:00:00' );

		$this->assertSame( '2026-03-01 00:00:00', $this->scheduler->last_sent_at( 'artist' ) );
	}

	public function test_has_issue_in_flight_true_only_while_sending(): void {
		$this->assertFalse( $this->scheduler->has_issue_in_flight( 'public' ) );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_newsletter_issues',
			[ 'newsletter_type' => 'public', 'status' => 'sending' ],
			[ '%s', '%s' ]
		);

		$this->assertTrue( $this->scheduler->has_issue_in_flight( 'public' ) );
	}
}
