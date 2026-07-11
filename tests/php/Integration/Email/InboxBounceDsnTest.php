<?php
/**
 * Integration tests — Inbox::is_bounce_dsn() / handle_bounce_dsn() (security
 * audit §5a).
 *
 * A hard bounce or spam complaint for mail this plugin sent to an artist
 * comes back into the IMAP mailbox as a DSN, not as a normal reply — before
 * this fix, process_messages() had no way to tell a DSN apart from an
 * ordinary unregistered-sender message, so the bounce signal was silently
 * discarded as an 'unregistered_sender' skip. These two gates run before the
 * auth check (a DSN's From is the receiving server's own mailer-daemon, which
 * will never pass SPF/DKIM as an admitted artist) — see both methods'
 * docblocks in Inbox.php.
 *
 * Both methods are private; reached via reflection with a FakeDsnImapMessage
 * double (plain `object`, not a real Webklex\PHPIMAP\Message — same reasoning
 * as FakeAliasImapMessage, see that file's docblock) since a real Message
 * requires a live IMAP connection to construct.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Email\Inbox;
use Agnosis\Newsletter\Subscriber;

class InboxBounceDsnTest extends \WP_UnitTestCase {

	private Inbox $inbox;

	protected function setUp(): void {
		parent::setUp();
		$this->inbox = new Inbox();
	}

	private function is_bounce_dsn( object $message ): bool {
		$ref = new \ReflectionMethod( Inbox::class, 'is_bounce_dsn' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->inbox, $message );
	}

	private function handle_bounce_dsn( object $message, string $from_email, string $uid ): void {
		$ref = new \ReflectionMethod( Inbox::class, 'handle_bounce_dsn' );
		$ref->setAccessible( true );
		$ref->invoke( $this->inbox, $message, $from_email, $uid );
	}

	private function latest_queue_row(): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$wpdb->prefix}agnosis_queue ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);

		return $row ?: null;
	}

	// -------------------------------------------------------------------------
	// is_bounce_dsn()
	// -------------------------------------------------------------------------

	public function test_multipart_report_content_type_is_recognized(): void {
		$message = new FakeDsnImapMessage( content_type: 'multipart/report; report-type=delivery-status' );
		$this->assertTrue( $this->is_bounce_dsn( $message ) );
	}

	public function test_x_failed_recipients_header_is_recognized(): void {
		$message = new FakeDsnImapMessage( failed_recipients_header: 'dead@example.com' );
		$this->assertTrue( $this->is_bounce_dsn( $message ) );
	}

	public function test_ordinary_message_is_not_a_dsn(): void {
		$message = new FakeDsnImapMessage( content_type: 'multipart/mixed; boundary=xyz' );
		$this->assertFalse( $this->is_bounce_dsn( $message ) );
	}

	public function test_blank_x_failed_recipients_header_does_not_count(): void {
		$message = new FakeDsnImapMessage( failed_recipients_header: '   ' );
		$this->assertFalse( $this->is_bounce_dsn( $message ) );
	}

	// -------------------------------------------------------------------------
	// handle_bounce_dsn() — address extraction
	// -------------------------------------------------------------------------

	public function test_extracts_recipient_from_x_failed_recipients_header(): void {
		Subscriber::subscribe( 'dead@example.com' );

		$message = new FakeDsnImapMessage( failed_recipients_header: 'dead@example.com' );
		$this->handle_bounce_dsn( $message, 'mailer-daemon@mx.example.com', 'uid-1' );

		$counts = Subscriber::counts();
		$this->assertSame( 1, $counts['bounced'] );

		$row = $this->latest_queue_row();
		$this->assertSame( 'skipped', $row['status'] );
		$data = json_decode( (string) $row['raw_email'], true );
		$this->assertSame( 'bounce_handled', $data['skip_reason'] ?? null );
	}

	public function test_extracts_multiple_recipients_from_x_failed_recipients_header(): void {
		Subscriber::subscribe( 'one@example.com' );
		Subscriber::subscribe( 'two@example.com' );

		$message = new FakeDsnImapMessage( failed_recipients_header: 'one@example.com, two@example.com' );
		$this->handle_bounce_dsn( $message, 'mailer-daemon@mx.example.com', 'uid-2' );

		$counts = Subscriber::counts();
		$this->assertSame( 2, $counts['bounced'] );
	}

	public function test_falls_back_to_final_recipient_body_when_no_header(): void {
		Subscriber::subscribe( 'body-parsed@example.com' );

		$body = "This is a delivery status notification.\n\n"
			. "Final-Recipient: rfc822; body-parsed@example.com\n"
			. "Action: failed\n"
			. "Status: 5.1.1\n";

		$message = new FakeDsnImapMessage(
			content_type: 'multipart/report; report-type=delivery-status',
			text_body: $body
		);
		$this->handle_bounce_dsn( $message, 'mailer-daemon@mx.example.com', 'uid-3' );

		$counts = Subscriber::counts();
		$this->assertSame( 1, $counts['bounced'] );
	}

	public function test_header_present_takes_precedence_over_body(): void {
		Subscriber::subscribe( 'header-wins@example.com' );
		Subscriber::subscribe( 'body-loses@example.com' );

		$body = "Final-Recipient: rfc822; body-loses@example.com\n";

		$message = new FakeDsnImapMessage(
			failed_recipients_header: 'header-wins@example.com',
			text_body: $body
		);
		$this->handle_bounce_dsn( $message, 'mailer-daemon@mx.example.com', 'uid-4' );

		$counts = Subscriber::counts();
		$this->assertSame( 1, $counts['bounced'] );

		global $wpdb;
		$body_loser_status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}agnosis_newsletter_subscribers WHERE email = %s",
				'body-loses@example.com'
			)
		);
		$this->assertSame( 'pending', $body_loser_status );
	}

	public function test_no_extractable_recipient_marks_bounce_unresolved(): void {
		$message = new FakeDsnImapMessage(
			content_type: 'multipart/report; report-type=feedback-report',
			text_body: 'This is a spam complaint with no machine-readable recipient.'
		);
		$this->handle_bounce_dsn( $message, 'abuse@example.com', 'uid-5' );

		$row  = $this->latest_queue_row();
		$data = json_decode( (string) $row['raw_email'], true );
		$this->assertSame( 'bounce_unresolved', $data['skip_reason'] ?? null );
	}

	public function test_dsn_for_admitted_artist_increments_bounce_counter(): void {
		global $wpdb;

		$user_id = self::factory()->user->create( [ 'user_email' => 'artist-bounced@example.com', 'role' => 'subscriber' ] );
		get_user_by( 'id', $user_id )->add_role( 'agnosis_artist' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => 'artist-bounced@example.com',
				'display_name' => 'Bounced Artist',
				'status'       => 'admitted',
				'wp_user_id'   => $user_id,
				'resolved_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%d', '%s' ]
		);

		$message = new FakeDsnImapMessage( failed_recipients_header: 'artist-bounced@example.com' );
		$this->handle_bounce_dsn( $message, 'mailer-daemon@mx.example.com', 'uid-6' );

		$this->assertSame( 1, (int) get_user_meta( $user_id, '_agnosis_bounce_count', true ) );
		$this->assertNotEmpty( get_user_meta( $user_id, '_agnosis_bounce_last_at', true ) );
	}
}
