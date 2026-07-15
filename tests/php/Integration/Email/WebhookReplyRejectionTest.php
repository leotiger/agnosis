<?php
/**
 * Integration tests — Webhook::handle()'s reply/forward extraction branch,
 * mirroring Inbox::process_messages()'s identical branch on the IMAP
 * transport.
 *
 * Originally (2026-07-15) any payload matching IntakeGates::is_reply_or_quote()
 * was rejected outright. Eighth audit §3c (2026-07-14) widened this: a match
 * now runs through IntakeGates::extract_original_content() first, which pulls
 * out whatever the sender actually wrote above the quoted/forwarded portion.
 * Only a payload that extracts to NOTHING (no original text, no attachment)
 * still reaches Parser::parse_webhook_payload()'s distinct 'looks_like_reply'
 * null-return path, firing 'agnosis_submission_looks_like_reply' so
 * Publishing\Notification::on_submission_looks_like_reply() can tell the
 * sender why. A payload extraction actually rescues is queued exactly like
 * any other genuine submission — see WebhookQueueIdempotencyTest for the
 * queueing mechanics this file doesn't re-cover.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Email\Webhook;
use WP_REST_Request;

class WebhookReplyRejectionTest extends \WP_UnitTestCase {

	private Webhook $webhook;

	/** @var string[] message_uid values inserted by this test, cleaned up in tearDown(). */
	private array $inserted_uids = [];

	protected function setUp(): void {
		parent::setUp();
		$this->webhook = new Webhook();
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->inserted_uids as $uid ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "DELETE FROM {$wpdb->prefix}agnosis_queue WHERE message_uid = %s", $uid )
			);
		}
		wp_clear_scheduled_hook( 'agnosis_publish_submission' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function submission_request( string $sender, string $subject, string $body ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( 'sender', $sender );
		$request->set_param( 'recipient', 'submit@example.com' );
		$request->set_param( 'subject', $subject );
		$request->set_param( 'stripped-text', $body );
		return $request;
	}

	private function count_reply_rejections( callable $run ): int {
		$count = 0;
		$cb    = function () use ( &$count ) {
			++$count;
		};
		add_action( 'agnosis_submission_looks_like_reply', $cb, 10, 0 );
		$run();
		remove_action( 'agnosis_submission_looks_like_reply', $cb, 10 );
		return $count;
	}

	/** Create a WP user with the agnosis_artist role and an 'admitted' application row. */
	private function create_admitted_artist( string $email ): void {
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
	}

	/** Read back the queued row's decoded raw_email JSON for the given queue id. */
	private function queued_submission( int $queue_id ): array {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT raw_email FROM {$wpdb->prefix}agnosis_queue WHERE id = %d",
			$queue_id
		) );
		return (array) json_decode( (string) $raw, true );
	}

	// =========================================================================
	// Extraction rescues a reply/forward that DOES carry original content —
	// queued exactly like a genuine submission, tagged extracted_from_reply.
	// =========================================================================

	public function test_re_prefixed_subject_with_real_body_is_extracted_and_queued(): void {
		$email = 'extracted-replier@example.com';
		$this->create_admitted_artist( $email );

		$request  = $this->submission_request( $email, 'Re: My new painting', 'Here it is again.' );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 202, $response->get_status(), 'A Re:-prefixed subject with real body text and no quote marker must be extracted and queued, not rejected.' );
		$this->inserted_uids[] = $this->find_uid_for_queue_id( (int) $response->get_data()['id'] );

		$submission = $this->queued_submission( (int) $response->get_data()['id'] );
		$this->assertSame( 'My new painting', $submission['subject'] );
		$this->assertSame( 'Here it is again.', $submission['description'] );
		$this->assertTrue( $submission['extracted_from_reply'] );
	}

	public function test_quoted_body_with_a_real_comment_above_is_extracted_and_queued(): void {
		$email = 'extracted-commenter@example.com';
		$this->create_admitted_artist( $email );

		$body     = "Here's another piece.\n\nOn 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Previous content.";
		$request  = $this->submission_request( $email, 'My new painting', $body );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 202, $response->get_status(), 'A comment written above a quoted reply must be extracted and queued.' );
		$this->inserted_uids[] = $this->find_uid_for_queue_id( (int) $response->get_data()['id'] );

		$submission = $this->queued_submission( (int) $response->get_data()['id'] );
		$this->assertSame( "Here's another piece.", $submission['description'] );
		$this->assertTrue( $submission['extracted_from_reply'] );
	}

	private function find_uid_for_queue_id( int $queue_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT message_uid FROM {$wpdb->prefix}agnosis_queue WHERE id = %d",
			$queue_id
		) );
	}

	// =========================================================================
	// Extraction finds NOTHING — still rejected as 'looks_like_reply', same
	// distinct reason and notification as before §3c.
	// =========================================================================

	public function test_re_prefixed_subject_with_nothing_but_a_quote_is_rejected_with_a_distinct_reason(): void {
		$body     = "On 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Previous content.";
		$request  = $this->submission_request( 'artist@example.com', 'Re: My new painting', $body );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 'skipped', $response->get_data()['status'] );
		$this->assertSame( 'looks_like_reply', $response->get_data()['reason'] );
	}

	public function test_quoted_body_with_nothing_above_it_is_rejected_with_a_distinct_reason(): void {
		$body     = "On 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Previous content.";
		$request  = $this->submission_request( 'artist@example.com', 'My new painting', $body );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 'looks_like_reply', $response->get_data()['reason'] );
	}

	public function test_genuine_submission_is_not_flagged_as_a_reply(): void {
		$request  = $this->submission_request( 'unknown-sender@example.com', 'My new painting', 'Here is my artwork.' );
		$response = $this->webhook->handle( $request );

		// Sender isn't an admitted artist and there's no attachment, so this
		// still ends up skipped — but for the ordinary reason, not as a reply.
		$this->assertNotSame( 'looks_like_reply', $response->get_data()['reason'] );
	}

	public function test_fires_the_shared_notification_action_when_sender_is_a_known_wp_user(): void {
		$email = 'known-replier@example.com';
		self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );

		$body    = "On 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Previous content.";
		$request = $this->submission_request( $email, 'Re: My new painting', $body );

		$fires = $this->count_reply_rejections( function () use ( $request ): void {
			$this->webhook->handle( $request );
		} );

		$this->assertSame( 1, $fires, 'A recognised WP user must trigger the reply-rejection notification action when extraction finds nothing.' );
	}

	public function test_does_not_fire_the_notification_action_when_sender_matches_no_wp_user(): void {
		$body    = "On 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Previous content.";
		$request = $this->submission_request( 'nobody-registered@example.com', 'Re: My new painting', $body );

		$fires = $this->count_reply_rejections( function () use ( $request ): void {
			$this->webhook->handle( $request );
		} );

		$this->assertSame( 0, $fires, 'No WP user to notify — the action must not fire for an unresolvable sender.' );
	}

	public function test_does_not_fire_the_notification_action_when_extraction_rescues_the_message(): void {
		$email = 'rescued-replier@example.com';
		self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );

		$request = $this->submission_request( $email, 'Re: My new painting', 'Here it is again.' );

		$fires = $this->count_reply_rejections( function () use ( $request ): void {
			$response = $this->webhook->handle( $request );
			if ( 202 === $response->get_status() ) {
				$this->inserted_uids[] = $this->find_uid_for_queue_id( (int) $response->get_data()['id'] );
			}
		} );

		$this->assertSame( 0, $fires, 'A message extraction rescues must not fire the rejection notification — nothing was rejected.' );
	}
}
