<?php
/**
 * Integration tests — CommunityCapNotification (2026-07-15, audit-adjacent
 * finding, not a numbered audit item — see CHANGELOG.md 0.9.29).
 *
 * This class had no test coverage at all before this pass — no dedicated
 * test file existed (CommunityCapVoteIntegrationTest.php and
 * CommunityCapIntegrationTest.php exercise the voting mechanics but assert
 * nothing about wp_mail()). It was also plain text end to end; every method
 * is now HTML via the shared Core\EmailTemplate shell.
 *
 * Coverage:
 *   - on_vote_opened() emails every active artist, is HTML, and mentions the
 *     proposed cap and closing date
 *   - on_vote_opened() honours a "no limit" cap label when proposed_cap is 0
 *   - on_vote_passed() / on_vote_failed() notify the admin, are HTML, and
 *     mention the outcome
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\CommunityCapNotification;

class CommunityCapNotificationTest extends \WP_UnitTestCase {

	private CommunityCapNotification $notification;

	/** All wp_mail() calls captured during a test (keys: to, subject, message, headers). */
	private array $sent_mails = [];

	private ?\Closure $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->notification = new CommunityCapNotification();
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

	private function create_artist( string $email ): int {
		$user_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		get_userdata( $user_id )->add_role( 'agnosis_artist' );
		return $user_id;
	}

	/** Insert a minimal agnosis_cap_proposals row and return its ID. */
	private function insert_cap_proposal( int $proposed_cap ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_cap_proposals',
			[
				'proposed_cap' => $proposed_cap,
				'status'       => 'open',
				'opened_at'    => current_time( 'mysql' ),
				'closes_at'    => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	// -------------------------------------------------------------------------
	// on_vote_opened()
	// -------------------------------------------------------------------------

	public function test_vote_opened_emails_active_artists_and_is_html(): void {
		$this->create_artist( 'capvoter@example.com' );
		$proposal_id = $this->insert_cap_proposal( 50 );

		$this->start_mail_capture();
		$this->notification->on_vote_opened( $proposal_id, gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ) );

		$mail = $this->mails_to( 'capvoter@example.com' )[0];
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $mail['headers'] ) );
		$this->assertStringContainsString( '<!DOCTYPE html>', $mail['message'] );
		$this->assertStringContainsString( '50', $mail['message'] );
	}

	public function test_vote_opened_uses_no_limit_label_when_proposed_cap_is_zero(): void {
		$this->create_artist( 'nolimit@example.com' );
		$proposal_id = $this->insert_cap_proposal( 0 );

		$this->start_mail_capture();
		$this->notification->on_vote_opened( $proposal_id, gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ) );

		$mail = $this->mails_to( 'nolimit@example.com' )[0];
		$this->assertStringContainsString( 'no limit', $mail['message'] );
	}

	public function test_vote_opened_sends_nothing_when_there_are_no_artists(): void {
		$proposal_id = $this->insert_cap_proposal( 50 );

		$this->start_mail_capture();
		$this->notification->on_vote_opened( $proposal_id, gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ) );

		$this->assertCount( 0, $this->sent_mails );
	}

	// -------------------------------------------------------------------------
	// on_vote_passed() / on_vote_failed()
	// -------------------------------------------------------------------------

	public function test_vote_passed_notifies_admin_with_the_new_cap_and_is_html(): void {
		$this->start_mail_capture();
		$this->notification->on_vote_passed( 1, 75 );

		$mail = $this->mails_to( get_option( 'admin_email' ) )[0];
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $mail['headers'] ) );
		$this->assertStringContainsString( '<!DOCTYPE html>', $mail['message'] );
		$this->assertStringContainsString( '75', $mail['message'] );
		$this->assertStringContainsString( 'now in effect', $mail['message'] );
	}

	public function test_vote_passed_uses_no_limit_label_when_new_cap_is_zero(): void {
		$this->start_mail_capture();
		$this->notification->on_vote_passed( 1, 0 );

		$mail = $this->mails_to( get_option( 'admin_email' ) )[0];
		$this->assertStringContainsString( 'no limit', $mail['message'] );
	}

	public function test_vote_failed_notifies_admin_and_is_html(): void {
		$this->start_mail_capture();
		$this->notification->on_vote_failed( 1, 50 );

		$mail = $this->mails_to( get_option( 'admin_email' ) )[0];
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $mail['headers'] ) );
		$this->assertStringContainsString( '<!DOCTYPE html>', $mail['message'] );
		$this->assertStringContainsString( 'cap is unchanged', $mail['message'] );
	}
}
