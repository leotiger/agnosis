<?php
/**
 * Integration tests — fifth audit §3b/§5a: the admitted-artist gate on the
 * goodbye@ alias, and mark_alias_event()'s queue-row bookkeeping for every
 * goodbye@/community@ outcome that WebhookAliasAuthGateTest.php does not
 * already exercise (that file covers the SPF/DKIM auth gate and the
 * goodbye@ throttle/success paths; this file covers the admitted-artist
 * gate itself plus every other reason code in Webhook::ALIAS_REASONS).
 *
 * Covers, one test each:
 *   - goodbye_non_artist    (a registered but non-admitted WP user)
 *   - goodbye_no_membership (an admitted-role user with no admitted/banned
 *                             agnosis_applications row — Departure has
 *                             nothing to act on)
 *   - community_non_artist
 *   - community_auto_submitted (mail-loop guard — checked before the sender
 *                                is even resolved)
 *   - community_throttled
 *   - community_empty
 *   - community_too_long
 *   - community_handled     (genuine success, but zero other recipients —
 *                             no AI provider needed to reach this branch)
 *
 * Every scenario asserts the queue row mark_alias_event() writes: the
 * correct error text from ALIAS_REASONS, the correct status (the three
 * ALIAS_STATUSES overrides vs. the 'failed' default), and — for the
 * 'skipped' ones — the skip_reason recorded in the row's JSON meta.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Core\RateLimiter;
use Agnosis\Email\Webhook;
use WP_REST_Request;

class WebhookAliasEventCoverageTest extends \WP_UnitTestCase {

	private Webhook $webhook;

	private const GOODBYE_ADDR   = 'goodbye@example.com';
	private const COMMUNITY_ADDR = 'community@example.com';

	/** @var string[] message_uid values inserted by this test, cleaned up in tearDown(). */
	private array $inserted_uids = [];

	protected function setUp(): void {
		parent::setUp();
		$this->webhook = new Webhook();
		update_option( 'agnosis_email_goodbye', self::GOODBYE_ADDR );
		update_option( 'agnosis_email_community', self::COMMUNITY_ADDR );
	}

	protected function tearDown(): void {
		$this->clear_queue_rows();
		delete_option( 'agnosis_email_goodbye' );
		delete_option( 'agnosis_email_community' );
		delete_option( 'agnosis_community_broadcast_limit' );
		delete_option( 'agnosis_community_broadcast_max_chars' );
		RateLimiter::reset_sender( 'community_broadcast', 'community-artist@example.com', DAY_IN_SECONDS );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_admitted_artist( string $email ): int {
		global $wpdb;

		$user_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$user    = get_user_by( 'id', $user_id );
		$user->add_role( 'agnosis_artist' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => $email,
				'display_name' => 'Test Artist',
				'status'       => 'admitted',
				'wp_user_id'   => $user_id,
				'resolved_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%d', '%s' ]
		);

		return $user_id;
	}

	/** A registered WP user who is neither an agnosis_artist nor an admin. */
	private function create_non_artist_user( string $email ): int {
		return self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
	}

	/**
	 * An agnosis_artist-role user whose agnosis_applications row exists but is
	 * not 'admitted'/'banned' — Admission::is_admitted_artist() passes (it only
	 * checks the WP capability), but Departure::initiate_removal_for_user()
	 * has no active membership row to act on. Reproduces goodbye_no_membership.
	 */
	private function create_artist_role_without_active_application( string $email ): int {
		global $wpdb;

		$user_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$user    = get_user_by( 'id', $user_id );
		$user->add_role( 'agnosis_artist' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => $email,
				'display_name' => 'Pending Artist',
				'status'       => 'pending',
				'wp_user_id'   => $user_id,
			],
			[ '%s', '%s', '%s', '%d' ]
		);

		return $user_id;
	}

	private function goodbye_request( string $sender ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( 'sender', $sender );
		$request->set_param( 'recipient', self::GOODBYE_ADDR );
		return $request;
	}

	private function community_request( string $sender, string $subject = 'Hello everyone', string $body = 'Just checking in.' ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( 'sender', $sender );
		$request->set_param( 'recipient', self::COMMUNITY_ADDR );
		$request->set_param( 'subject', $subject );
		$request->set_param( 'stripped-text', $body );
		return $request;
	}

	private function queue_row_for_message_id( string $message_id ): ?object {
		global $wpdb;
		$uid                    = 'webhook-alias-' . md5( $message_id );
		$this->inserted_uids[]  = $uid;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT status, error, raw_email FROM {$wpdb->prefix}agnosis_queue WHERE message_uid = %s",
				$uid
			)
		);
	}

	private function clear_queue_rows(): void {
		global $wpdb;
		foreach ( $this->inserted_uids as $uid ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "DELETE FROM {$wpdb->prefix}agnosis_queue WHERE message_uid = %s", $uid )
			);
		}
		$this->inserted_uids = [];
	}

	// -------------------------------------------------------------------------
	// goodbye@ — admitted-artist gate
	// -------------------------------------------------------------------------

	public function test_goodbye_from_non_artist_is_rejected_and_recorded(): void {
		$sender      = 'non-artist-goodbye@example.com';
		$this->create_non_artist_user( $sender );
		$message_id  = '<goodbye-non-artist@example.com>';

		$request = $this->goodbye_request( $sender );
		$request->set_param( 'Message-Id', $message_id );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 'skipped', $response->get_data()['status'] );
		$this->assertSame( 'goodbye_non_artist', $response->get_data()['reason'] );

		$row = $this->queue_row_for_message_id( $message_id );
		$this->assertNotNull( $row, 'mark_alias_event() must record a queue row for this outcome, per fifth audit §5a admin visibility.' );
		$this->assertSame( 'failed', $row->status, 'goodbye_non_artist has no ALIAS_STATUSES override, so it must default to failed.' );
		$this->assertStringContainsString( 'non-artist sender', $row->error );
	}

	public function test_goodbye_with_role_but_no_active_membership_is_recorded_as_no_membership(): void {
		$sender     = 'pending-artist-goodbye@example.com';
		$this->create_artist_role_without_active_application( $sender );
		$message_id = '<goodbye-no-membership@example.com>';

		$request = $this->goodbye_request( $sender );
		$request->set_param( 'Message-Id', $message_id );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 'goodbye_received', $response->get_data()['status'], 'The response is still goodbye_received even when no membership was found — mirrors Inbox::handle_goodbye_email() distinguishing "handled" from "nothing to act on" without surfacing that distinction to the sender.' );

		$row = $this->queue_row_for_message_id( $message_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row->status, 'goodbye_no_membership has no ALIAS_STATUSES override, so it must default to failed.' );
		$this->assertStringContainsString( 'no active membership', $row->error );
	}

	// -------------------------------------------------------------------------
	// community@ — admitted-artist gate, mail-loop guard, throttling, content
	// -------------------------------------------------------------------------

	public function test_community_from_non_artist_is_rejected_and_recorded(): void {
		$sender     = 'non-artist-community@example.com';
		$this->create_non_artist_user( $sender );
		$message_id = '<community-non-artist@example.com>';

		$request = $this->community_request( $sender );
		$request->set_param( 'Message-Id', $message_id );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 'community_non_artist', $response->get_data()['reason'] );

		$row = $this->queue_row_for_message_id( $message_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row->status );
		$this->assertStringContainsString( 'non-artist sender', $row->error );
	}

	public function test_community_auto_submitted_is_rejected_before_sender_is_even_resolved(): void {
		// No WP user is created for this sender at all — the mail-loop guard
		// (fourth audit §3c) must trip before get_user_by() is ever called.
		$sender     = 'nonexistent-sender@example.com';
		$message_id = '<community-auto-submitted@example.com>';

		$request = $this->community_request( $sender );
		$request->set_param( 'Auto-Submitted', 'auto-replied' );
		$request->set_param( 'Message-Id', $message_id );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 'community_auto_submitted', $response->get_data()['reason'] );

		$row = $this->queue_row_for_message_id( $message_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row->status );
		$this->assertStringContainsString( 'automated response', $row->error );
	}

	public function test_community_throttled_sender_is_recorded(): void {
		$sender = 'community-artist@example.com';
		$this->create_admitted_artist( $sender );
		update_option( 'agnosis_community_broadcast_limit', 1 );

		// First message consumes the daily limit.
		$this->webhook->handle( $this->community_request( $sender, 'First', 'First message.' ) );

		$message_id = '<community-throttled@example.com>';
		$request    = $this->community_request( $sender, 'Second', 'Second message.' );
		$request->set_param( 'Message-Id', $message_id );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 'community_throttled', $response->get_data()['reason'] );

		$row = $this->queue_row_for_message_id( $message_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row->status, 'community_throttled has no ALIAS_STATUSES override, so it must default to failed.' );
		$this->assertStringContainsString( 'daily community broadcast limit', $row->error );
	}

	public function test_community_empty_message_is_recorded(): void {
		$sender = 'community-empty-artist@example.com';
		$this->create_admitted_artist( $sender );
		$message_id = '<community-empty@example.com>';

		$request = $this->community_request( $sender, '', '' );
		$request->set_param( 'Message-Id', $message_id );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 'community_empty', $response->get_data()['reason'] );

		$row = $this->queue_row_for_message_id( $message_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row->status );
		$this->assertStringContainsString( 'no subject or body', $row->error );
	}

	/**
	 * Audit §2c: previously an empty community broadcast was just dropped —
	 * recorded in the queue (asserted above) but the sender received no
	 * indication anything went wrong. Webhook::handle() now also triggers
	 * CommunityBroadcast::send_empty_bounce() on this path; this asserts the
	 * bounce email is actually sent, not just that the queue row is marked.
	 */
	public function test_community_empty_message_sends_a_bounce_to_the_sender(): void {
		$sender = 'community-empty-bounce@example.com';
		$this->create_admitted_artist( $sender );
		$message_id = '<community-empty-bounce@example.com>';

		$sent_mails  = [];
		$mail_filter = function ( $pre, array $atts ) use ( &$sent_mails ): bool {
			$sent_mails[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );

		$request = $this->community_request( $sender, '', '' );
		$request->set_param( 'Message-Id', $message_id );
		$this->webhook->handle( $request );

		remove_filter( 'pre_wp_mail', $mail_filter, 10 );

		$to_sender = array_values( array_filter( $sent_mails, fn( array $m ) => $m['to'] === $sender ) );
		$this->assertCount( 1, $to_sender, 'An empty community broadcast must bounce exactly one explanatory email back to the sender.' );
		$this->assertStringContainsString( 'no subject or message text', $to_sender[0]['message'] );
	}

	public function test_community_too_long_message_is_recorded_as_skipped(): void {
		$sender = 'community-toolong-artist@example.com';
		$this->create_admitted_artist( $sender );
		update_option( 'agnosis_community_broadcast_max_chars', 10 );
		$message_id = '<community-too-long@example.com>';

		$request = $this->community_request( $sender, 'A subject well over ten characters', 'And a body well over ten characters too.' );
		$request->set_param( 'Message-Id', $message_id );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 'community_too_long', $response->get_data()['reason'] );

		$row = $this->queue_row_for_message_id( $message_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'skipped', $row->status, 'community_too_long IS an ALIAS_STATUSES override — it is a handled outcome (bounced to sender), not a failure.' );
		$this->assertStringContainsString( 'skip_reason', $row->raw_email );
	}

	public function test_community_handled_with_no_other_recipients_is_recorded_as_skipped(): void {
		// Only artist on site — CommunityBroadcast::broadcast() has nobody else
		// to send to and returns 0 immediately, with no AI provider call needed
		// to reach this branch (get_users() with the sender excluded is empty).
		$sender = 'lone-community-artist@example.com';
		$this->create_admitted_artist( $sender );
		$message_id = '<community-handled@example.com>';

		$request = $this->community_request( $sender, 'Hello', 'Anyone out there?' );
		$request->set_param( 'Message-Id', $message_id );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 'community_broadcast', $response->get_data()['status'] );
		$this->assertSame( 0, $response->get_data()['sent'] );

		$row = $this->queue_row_for_message_id( $message_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'skipped', $row->status, 'community_handled IS an ALIAS_STATUSES override — a genuine (if recipient-less) success, not a failure.' );
		$this->assertStringContainsString( 'skip_reason', $row->raw_email );
	}

	// -------------------------------------------------------------------------
	// End-to-end CC scenario (fifth audit §5a) — every test above puts the
	// alias address in Mailgun's own single-routed 'recipient' field. None of
	// them prove IntakeGates::recipient_addresses()'s actual reason for
	// existing (sixth audit §6 — this parser moved here from a private
	// method on this class): that goodbye@/community@ still gets found
	// when it only appears in a
	// 'To'/'Cc' header alongside some other primary recipient. These two
	// tests close that gap by deliberately leaving 'recipient' pointing
	// somewhere else and only CC'ing the alias.
	// -------------------------------------------------------------------------

	public function test_goodbye_alias_is_matched_when_only_present_in_cc_not_recipient(): void {
		$sender = 'cc-goodbye-artist@example.com';
		$this->create_admitted_artist( $sender );
		$message_id = '<goodbye-via-cc@example.com>';

		$request = new WP_REST_Request();
		$request->set_param( 'sender', $sender );
		$request->set_param( 'recipient', 'someone-else@example.com' ); // Not the goodbye alias.
		$request->set_param( 'Cc', 'Gallery Desk <' . self::GOODBYE_ADDR . '>' );
		$request->set_param( 'Message-Id', $message_id );

		$response = $this->webhook->handle( $request );

		$this->assertSame( 'goodbye_received', $response->get_data()['status'], 'The goodbye alias must be matched from the Cc: header, not just Mailgun\'s recipient field.' );

		$row = $this->queue_row_for_message_id( $message_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'skipped', $row->status, 'goodbye_handled IS an ALIAS_STATUSES override — a genuine success, not a failure.' );
		$this->assertSame( 'goodbye_handled', json_decode( $row->raw_email, true )['skip_reason'] ?? null );
	}

	public function test_community_alias_is_matched_when_only_present_in_to_header_not_recipient(): void {
		$sender = 'cc-community-artist@example.com';
		$this->create_admitted_artist( $sender );
		$message_id = '<community-via-to-header@example.com>';

		$request = new WP_REST_Request();
		$request->set_param( 'sender', $sender );
		$request->set_param( 'recipient', 'someone-else@example.com' ); // Not the community alias.
		$request->set_param( 'To', 'Studio <someone-else@example.com>, Broadcast <' . self::COMMUNITY_ADDR . '>' );
		$request->set_param( 'subject', 'Hello everyone' );
		$request->set_param( 'stripped-text', 'Just checking in.' );
		$request->set_param( 'Message-Id', $message_id );

		$response = $this->webhook->handle( $request );

		$this->assertSame( 'community_broadcast', $response->get_data()['status'], 'The community alias must be matched out of a multi-address To: header, not just Mailgun\'s recipient field.' );

		$row = $this->queue_row_for_message_id( $message_id );
		$this->assertNotNull( $row, 'mark_alias_event() must still record this as a community event even though it was matched via the To: header rather than recipient.' );
	}
}
