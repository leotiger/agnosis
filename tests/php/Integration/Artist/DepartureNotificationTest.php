<?php
/**
 * Integration tests — DepartureNotification (2026-07-08, extended 2026-07-15).
 *
 * on_artist_left() coverage (2026-07-08): before that fix, an artist who
 * completed self-removal had no lasting record that the deletion actually
 * happened, beyond the result page shown once at click-time
 * (Departure::render_departure_result()) — closed that gap with an explicit
 * confirmation email alongside the pre-existing admin notice.
 *
 * Ban/reinstatement/vote coverage (2026-07-15, audit-adjacent finding, not a
 * numbered audit item — see CHANGELOG.md 0.9.29): this whole class was plain
 * text end to end and these methods had no test coverage at all. Now that
 * every method is built on the shared Core\EmailTemplate shell, the tests
 * below close that gap for on_confirmation_requested(), on_artist_banned()
 * (both temporary and indefinite), on_artist_reinstated(), on_vote_opened()
 * (including subject exclusion), on_vote_passed(), and on_vote_failed().
 *
 * Coverage:
 *   - Sends both an admin notice AND an artist confirmation email
 *   - Artist email goes to the correct address, with reassuring content
 *     ("permanently deleted" / "nothing ... is stored")
 *   - Artist email is skipped (admin notice still sent) when no email is supplied
 *   - Nothing is sent at all when the application row can't be found
 *   - Every email in this class is HTML (Content-Type + DOCTYPE) and carries
 *     its expected content
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\DepartureNotification;

class DepartureNotificationTest extends \WP_UnitTestCase {

	private DepartureNotification $notification;

	/** All wp_mail() calls captured during a test (keys: to, subject, message, headers). */
	private array $sent_mails = [];

	private ?\Closure $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->notification = new DepartureNotification();
	}

	protected function tearDown(): void {
		$this->remove_mail_capture();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function start_mail_capture(): void {
		$this->sent_mails  = [];
		$this->mail_filter = function ( $pre, array $atts ): bool {
			$this->sent_mails[] = $atts;
			return true; // Short-circuit — do not actually send.
		};
		add_filter( 'pre_wp_mail', $this->mail_filter, 10, 2 );
	}

	private function remove_mail_capture(): void {
		if ( $this->mail_filter ) {
			remove_filter( 'pre_wp_mail', $this->mail_filter, 10 );
			$this->mail_filter = null;
		}
	}

	/** @return array<int, array<string, mixed>> Captured mails addressed to $to, in send order. */
	private function mails_to( string $to ): array {
		return array_values( array_filter( $this->sent_mails, static fn( array $mail ): bool => $to === $mail['to'] ) );
	}

	/** Insert a minimal agnosis_applications row (status irrelevant here — already 'left' by the time this fires) and return its ID. */
	private function insert_application_row( string $display_name = 'Departed Artist' ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => 'unused@example.com',
				'display_name' => $display_name,
				'status'       => 'left',
				'resolved_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	// -------------------------------------------------------------------------
	// on_artist_left()
	// -------------------------------------------------------------------------

	public function test_sends_both_admin_notice_and_artist_confirmation(): void {
		$app_id = $this->insert_application_row();

		$this->start_mail_capture();
		$this->notification->on_artist_left( 42, $app_id, 'departed@example.com', '' );

		$this->assertCount( 2, $this->sent_mails );

		$recipients = array_column( $this->sent_mails, 'to' );
		$this->assertContains( get_option( 'admin_email' ), $recipients );
		$this->assertContains( 'departed@example.com', $recipients );
	}

	public function test_artist_email_content_confirms_permanent_deletion(): void {
		$app_id = $this->insert_application_row( 'Cal Talaia' );

		$this->start_mail_capture();
		$this->notification->on_artist_left( 42, $app_id, 'cal@example.com', '' );

		$artist_mail = null;
		foreach ( $this->sent_mails as $mail ) {
			if ( 'cal@example.com' === $mail['to'] ) {
				$artist_mail = $mail;
			}
		}

		$this->assertNotNull( $artist_mail, 'An email addressed to the artist must be sent.' );
		$this->assertStringContainsString( 'Cal Talaia', $artist_mail['message'] );
		$this->assertStringContainsString( 'permanently deleted', $artist_mail['message'] );
		$this->assertStringContainsString( 'Nothing tied to you', $artist_mail['message'] );
	}

	public function test_artist_email_skipped_when_no_email_supplied(): void {
		$app_id = $this->insert_application_row();

		$this->start_mail_capture();
		$this->notification->on_artist_left( 42, $app_id, '', '' );

		// Admin notice still goes out — only the artist-facing email is conditional.
		$this->assertCount( 1, $this->sent_mails );
		$this->assertSame( get_option( 'admin_email' ), $this->sent_mails[0]['to'] );
	}

	public function test_nothing_sent_when_application_row_not_found(): void {
		$this->start_mail_capture();
		$this->notification->on_artist_left( 42, 999999, 'ghost@example.com', '' );

		$this->assertCount( 0, $this->sent_mails );
	}

	public function test_artist_email_respects_locale(): void {
		$app_id = $this->insert_application_row();

		$captured_locale   = null;
		$this->mail_filter = function ( $pre, array $atts ) use ( &$captured_locale ): bool {
			$this->sent_mails[] = $atts;
			if ( 'localized@example.com' === $atts['to'] ) {
				$captured_locale = determine_locale();
			}
			return true;
		};
		add_filter( 'pre_wp_mail', $this->mail_filter, 10, 2 );

		$this->notification->on_artist_left( 42, $app_id, 'localized@example.com', 'es_ES' );

		$this->assertSame( 'es_ES', $captured_locale, 'The artist email must be sent while switched to their own locale.' );

		// determine_locale() must not leak past the call — restore_current_locale()
		// is expected to run after the mail is sent.
		$this->assertNotSame( 'es_ES', determine_locale() );
	}

	// -------------------------------------------------------------------------
	// Audit-adjacent finding, not a numbered audit item (2026-07-15, see
	// CHANGELOG.md 0.9.29): this whole class was plain text end to end — every
	// method below (previously entirely untested per this file's own opening
	// docblock) is now HTML via the shared Core\EmailTemplate shell. These
	// tests cover the newly-HTML content directly, closing that pre-existing
	// gap for the methods this pass actually touched.
	// -------------------------------------------------------------------------

	/** Create a user and return their ID — helper for the tests below. */
	private function create_user( string $email ): int {
		return self::factory()->user->create( [ 'user_email' => $email ] );
	}

	public function test_confirmation_requested_email_is_html_and_contains_the_confirm_link(): void {
		$user_id = $this->create_user( 'confirm@example.com' );

		$this->start_mail_capture();
		$this->notification->on_confirmation_requested( $user_id, 'abc123token' );

		$mail = $this->mails_to( 'confirm@example.com' )[0];
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $mail['headers'] ) );
		$this->assertStringContainsString( '<!DOCTYPE html>', $mail['message'] );
		$this->assertStringContainsString( 'abc123token', $mail['message'] );
		$this->assertStringContainsString( 'Confirm removal', $mail['message'] );
	}

	public function test_banned_email_indefinite_mentions_suspension_with_no_end_date(): void {
		$user_id = $this->create_user( 'banned@example.com' );

		$this->start_mail_capture();
		$this->notification->on_artist_banned( $user_id, 1, null );

		$mail = $this->mails_to( 'banned@example.com' )[0];
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $mail['headers'] ) );
		$this->assertStringContainsString( 'has been suspended', $mail['message'] );
		$this->assertStringNotContainsString( 'automatically reinstated', $mail['message'], 'An indefinite ban must not claim an automatic reinstatement date.' );
	}

	public function test_banned_email_temporary_mentions_the_reinstatement_date(): void {
		$user_id      = $this->create_user( 'bannedtemp@example.com' );
		$banned_until = gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) );

		$this->start_mail_capture();
		$this->notification->on_artist_banned( $user_id, 1, $banned_until );

		$mail = $this->mails_to( 'bannedtemp@example.com' )[0];
		$this->assertStringContainsString( 'temporarily suspended', $mail['message'] );
		$this->assertStringContainsString( 'automatically reinstated', $mail['message'] );
	}

	public function test_reinstated_email_confirms_membership_restored(): void {
		$user_id = $this->create_user( 'reinstated@example.com' );

		$this->start_mail_capture();
		$this->notification->on_artist_reinstated( $user_id );

		$mail = $this->mails_to( 'reinstated@example.com' )[0];
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $mail['headers'] ) );
		$this->assertStringContainsString( 'reinstated', $mail['message'] );
	}

	/** Insert a minimal agnosis_removal_requests row and return its ID. */
	private function insert_removal_request( int $subject_user_id ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_removal_requests',
			[
				'subject_user_id' => $subject_user_id,
				'status'          => 'open',
				'opened_at'       => current_time( 'mysql' ),
				'closes_at'       => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	public function test_vote_opened_email_contains_both_vote_links_and_is_html(): void {
		$subject_id = $this->create_user( 'subject@example.com' );
		$voter_id   = self::factory()->user->create( [ 'user_email' => 'voter@example.com', 'role' => 'subscriber' ] );
		get_userdata( $voter_id )->add_role( 'agnosis_artist' );

		$request_id = $this->insert_removal_request( $subject_id );

		$this->start_mail_capture();
		$this->notification->on_vote_opened( $request_id, gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ) );

		$mail = $this->mails_to( 'voter@example.com' )[0];
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $mail['headers'] ) );
		$this->assertStringContainsString( 'agnosis_removal_vote=1', $mail['message'] );
		$this->assertStringContainsString( 'vote=yes', $mail['message'] );
		$this->assertStringContainsString( 'vote=no', $mail['message'] );
		$this->assertStringContainsString( 'Vote YES (remove)', $mail['message'] );
		$this->assertStringContainsString( 'Vote NO (keep)', $mail['message'] );
	}

	public function test_vote_opened_excludes_the_subject_of_their_own_removal_vote(): void {
		$subject_id = self::factory()->user->create( [ 'user_email' => 'excludedsubject@example.com', 'role' => 'subscriber' ] );
		get_userdata( $subject_id )->add_role( 'agnosis_artist' );

		$request_id = $this->insert_removal_request( $subject_id );

		$this->start_mail_capture();
		$this->notification->on_vote_opened( $request_id, gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ) );

		$this->assertEmpty( $this->mails_to( 'excludedsubject@example.com' ), 'The subject of a removal vote must never receive their own vote email.' );
	}

	public function test_vote_passed_admin_email_confirms_deletion(): void {
		$this->start_mail_capture();
		$this->notification->on_vote_passed( 42, 1 );

		$mail = $this->mails_to( get_option( 'admin_email' ) )[0];
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $mail['headers'] ) );
		$this->assertStringContainsString( 'permanently deleted', $mail['message'] );
	}

	public function test_vote_failed_admin_email_confirms_membership_remains_active(): void {
		$this->start_mail_capture();
		$this->notification->on_vote_failed( 42, 1 );

		$mail = $this->mails_to( get_option( 'admin_email' ) )[0];
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $mail['headers'] ) );
		$this->assertStringContainsString( 'remains active', $mail['message'] );
	}
}
