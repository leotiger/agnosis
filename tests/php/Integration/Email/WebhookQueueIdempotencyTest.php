<?php
/**
 * Integration tests — fifth audit §3a: Webhook::handle()'s deterministic
 * queue UID and INSERT IGNORE idempotency for the normal artwork/bio/event
 * pipeline (as opposed to WebhookReplayProtectionTest, which covers the
 * signature-verification layer's own replay memory, and
 * WebhookAliasAuthGateTest, which never reaches this code path at all).
 *
 * Before this fix, the queue UID embedded time() (`'webhook-' . time() . '-'
 * . md5(...)`), so an ESP retry of an already-accepted request — or an
 * outright replay that slipped past HMAC/freshness checks, or simply arrived
 * a second later — never collided with agnosis_queue's own
 * `UNIQUE KEY uq_message_uid`: each call produced a fresh row and a fresh AI
 * pipeline spend. The fix derives the UID from the message's own Message-Id
 * (falling back to a hash of the parsed submission when absent), matching
 * Email\Inbox::enqueue()'s already-idempotent IMAP-side pattern.
 *
 * `handle()` accepts a plain array-backed WP_REST_Request and does not call
 * verify_signature() itself (that's a separate REST permission_callback), so
 * these tests call `->handle($request)` directly, exactly as
 * WebhookAliasAuthGateTest.php already does for its own scenarios.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Core\RateLimiter;
use Agnosis\Email\Webhook;
use WP_REST_Request;

class WebhookQueueIdempotencyTest extends \WP_UnitTestCase {

	private Webhook $webhook;

	private const ARTIST_EMAIL = 'queue-idempotency-artist@example.com';

	/** @var string[] message_uid values inserted by this test, cleaned up in tearDown(). */
	private array $inserted_uids = [];

	/** @var string[] tmp attachment file paths created by this test, cleaned up in tearDown(). */
	private array $tmp_files = [];

	protected function setUp(): void {
		parent::setUp();
		$this->webhook = new Webhook();
	}

	protected function tearDown(): void {
		$this->clear_queue_rows();
		$this->clear_tmp_files();
		wp_clear_scheduled_hook( 'agnosis_publish_submission' );
		RateLimiter::reset_sender( 'email_intake', self::ARTIST_EMAIL, HOUR_IN_SECONDS );
		unset( $_FILES['attachment-1'] );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Create a WP user with the agnosis_artist role and an 'admitted' application row. */
	private function create_admitted_artist( string $email = self::ARTIST_EMAIL ): int {
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

	/** Stage a fake image attachment under $_FILES['attachment-1'], mirroring ParserTest.php's pattern. */
	private function stage_attachment(): void {
		$tmp               = tempnam( sys_get_temp_dir(), 'agnosis_test_' );
		$this->tmp_files[] = $tmp;
		file_put_contents( $tmp, str_repeat( 'x', 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$_FILES['attachment-1'] = [
			'name'     => 'artwork.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 100,
		];
	}

	/** Build a valid, image-bearing submission request with an explicit Message-Id. */
	private function submission_request( string $message_id, string $subject = 'Seascape at dawn' ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( 'sender', self::ARTIST_EMAIL );
		$request->set_param( 'subject', $subject );
		$request->set_param( 'stripped-text', 'Painted at sunrise.' );
		$request->set_param( 'attachment-count', 1 );
		$request->set_param( 'Message-Id', $message_id );
		return $request;
	}

	private function queue_row_count_for( string $uid ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue WHERE message_uid = %s",
				$uid
			)
		);
	}

	private function expect_uid_cleanup( string $uid ): void {
		$this->inserted_uids[] = $uid;
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

	private function clear_tmp_files(): void {
		foreach ( $this->tmp_files as $tmp ) {
			if ( file_exists( $tmp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_exists
				unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			}
		}
		$this->tmp_files = [];
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_uid_is_deterministic_from_message_id_not_time(): void {
		$this->create_admitted_artist();
		$message_id = '<determinism-check@example.com>';
		$uid        = 'webhook-' . md5( $message_id );
		$this->expect_uid_cleanup( $uid );

		$this->stage_attachment();
		$first = $this->webhook->handle( $this->submission_request( $message_id ) );

		// A real ESP retry (or a slow-second replay) arrives with an identical
		// Message-Id but at a later wall-clock time — the old time()-embedding
		// UID would differ here; the fix must not.
		sleep( 1 );
		$this->stage_attachment();
		$second = $this->webhook->handle( $this->submission_request( $message_id ) );

		$this->assertSame( 202, $first->get_status() );
		$this->assertSame( $first->get_data()['id'], $second->get_data()['id'], 'The same Message-Id must resolve to the same queue row on a retried/replayed request, not a fresh insert.' );
		$this->assertSame( 1, $this->queue_row_count_for( $uid ), 'INSERT IGNORE must dedupe on the unique message_uid — only one row may exist for this Message-Id.' );
	}

	public function test_retried_request_does_not_create_a_second_queue_row(): void {
		$this->create_admitted_artist();
		$message_id = '<retry-check@example.com>';
		$uid        = 'webhook-' . md5( $message_id );
		$this->expect_uid_cleanup( $uid );

		$this->stage_attachment();
		$this->webhook->handle( $this->submission_request( $message_id ) );
		$this->stage_attachment();
		$this->webhook->handle( $this->submission_request( $message_id ) );
		$this->stage_attachment();
		$this->webhook->handle( $this->submission_request( $message_id ) );

		$this->assertSame( 1, $this->queue_row_count_for( $uid ), 'Three identical retries of the same message must still leave exactly one queue row.' );
	}

	public function test_second_call_still_returns_queued_status_not_an_error(): void {
		$this->create_admitted_artist();
		$message_id = '<status-check@example.com>';
		$uid        = 'webhook-' . md5( $message_id );
		$this->expect_uid_cleanup( $uid );

		$this->stage_attachment();
		$this->webhook->handle( $this->submission_request( $message_id ) );
		$this->stage_attachment();
		$second = $this->webhook->handle( $this->submission_request( $message_id ) );

		// The de-duplicated retry is not an error condition from the ESP's point
		// of view — it must still see a normal 202/queued acknowledgement so it
		// stops retrying, not a failure that would provoke yet another retry.
		$this->assertSame( 202, $second->get_status() );
		$this->assertSame( 'queued', $second->get_data()['status'] );
	}

	public function test_different_message_ids_produce_different_uids_and_both_rows_persist(): void {
		$this->create_admitted_artist();
		$message_id_a = '<distinct-a@example.com>';
		$message_id_b = '<distinct-b@example.com>';
		$uid_a        = 'webhook-' . md5( $message_id_a );
		$uid_b        = 'webhook-' . md5( $message_id_b );
		$this->expect_uid_cleanup( $uid_a );
		$this->expect_uid_cleanup( $uid_b );

		$this->stage_attachment();
		$first = $this->webhook->handle( $this->submission_request( $message_id_a ) );
		$this->stage_attachment();
		$second = $this->webhook->handle( $this->submission_request( $message_id_b ) );

		$this->assertNotSame( $first->get_data()['id'], $second->get_data()['id'], 'Two genuinely distinct messages must not be treated as replays of one another.' );
		$this->assertSame( 1, $this->queue_row_count_for( $uid_a ) );
		$this->assertSame( 1, $this->queue_row_count_for( $uid_b ) );
	}

	public function test_missing_message_id_falls_back_to_submission_hash_and_still_dedupes(): void {
		$this->create_admitted_artist();

		// No Message-Id at all — falls back to md5(wp_json_encode($submission)).
		// Building the same request twice (same sender/subject/description/
		// attachments) must produce the same fallback hash both times.
		$build = function (): WP_REST_Request {
			$request = new WP_REST_Request();
			$request->set_param( 'sender', self::ARTIST_EMAIL );
			$request->set_param( 'subject', 'No Message-Id here' );
			$request->set_param( 'stripped-text', 'Painted at sunrise.' );
			$request->set_param( 'attachment-count', 1 );
			return $request;
		};

		$this->stage_attachment();
		$first = $this->webhook->handle( $build() );
		$this->stage_attachment();
		$second = $this->webhook->handle( $build() );

		// Recover the uid actually used so it gets cleaned up: same artist_id
		// and both calls returned a real queue id, so read it straight from
		// the row instead of recomputing the submission hash by hand here.
		global $wpdb;
		$uid = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT message_uid FROM {$wpdb->prefix}agnosis_queue WHERE id = %d", (int) $first->get_data()['id'] )
		);
		$this->expect_uid_cleanup( $uid );

		$this->assertSame( $first->get_data()['id'], $second->get_data()['id'], 'Two payloads with no Message-Id but identical content must still resolve to the same fallback-hash queue row.' );
		$this->assertSame( 1, $this->queue_row_count_for( $uid ) );
	}
}
