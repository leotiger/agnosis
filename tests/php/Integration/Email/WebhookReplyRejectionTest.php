<?php
/**
 * Integration tests — Webhook::handle()'s reply/quote rejection branch
 * (2026-07-15), mirroring Inbox::process_messages()'s identical branch on the
 * IMAP transport.
 *
 * A genuine submission payload whose subject/body matches
 * IntakeGates::is_reply_or_quote() never reaches Parser::parse_webhook_payload()'s
 * normal null-return path ("no usable attachment/text") — it's rejected for a
 * distinct reason ('looks_like_reply'), and when the sender resolves to a real
 * WP user, 'agnosis_submission_looks_like_reply' fires so
 * Publishing\Notification::on_submission_looks_like_reply() can tell them why.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Email\Webhook;
use WP_REST_Request;

class WebhookReplyRejectionTest extends \WP_UnitTestCase {

	private Webhook $webhook;

	protected function setUp(): void {
		parent::setUp();
		$this->webhook = new Webhook();
	}

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

	public function test_re_prefixed_subject_is_rejected_with_a_distinct_reason(): void {
		$request  = $this->submission_request( 'artist@example.com', 'Re: My new painting', 'Here it is again.' );
		$response = $this->webhook->handle( $request );

		$this->assertSame( 'skipped', $response->get_data()['status'] );
		$this->assertSame( 'looks_like_reply', $response->get_data()['reason'] );
	}

	public function test_quoted_body_is_rejected_with_a_distinct_reason(): void {
		$body     = "Here you go.\n\nOn 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Previous content.";
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

		$request = $this->submission_request( $email, 'Re: My new painting', 'Here it is again.' );

		$fires = $this->count_reply_rejections( function () use ( $request ): void {
			$this->webhook->handle( $request );
		} );

		$this->assertSame( 1, $fires, 'A recognised WP user must trigger the reply-rejection notification action.' );
	}

	public function test_does_not_fire_the_notification_action_when_sender_matches_no_wp_user(): void {
		$request = $this->submission_request( 'nobody-registered@example.com', 'Re: My new painting', 'Here it is again.' );

		$fires = $this->count_reply_rejections( function () use ( $request ): void {
			$this->webhook->handle( $request );
		} );

		$this->assertSame( 0, $fires, 'No WP user to notify — the action must not fire for an unresolvable sender.' );
	}
}
