<?php
/**
 * Integration tests — DepartureNotification::on_artist_left() (2026-07-08).
 *
 * This class had no test coverage at all before this pass. Scope here is
 * deliberately limited to on_artist_left() — the method just changed to add
 * an artist-facing removal-confirmation email alongside the pre-existing
 * admin notice — rather than a full audit of every method in this class
 * (ban/reinstatement/vote emails are untouched by this fix and remain
 * untested; a gap, but a pre-existing one, not introduced here).
 *
 * Before this fix, an artist who completed self-removal had no lasting
 * record that the deletion actually happened, beyond the result page shown
 * once at click-time (Departure::render_departure_result()) — this closes
 * that gap with an explicit email.
 *
 * Coverage:
 *   - Sends both an admin notice AND an artist confirmation email
 *   - Artist email goes to the correct address, with reassuring content
 *     ("permanently deleted" / "nothing ... is stored")
 *   - Artist email is skipped (admin notice still sent) when no email is supplied
 *   - Nothing is sent at all when the application row can't be found
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
}
