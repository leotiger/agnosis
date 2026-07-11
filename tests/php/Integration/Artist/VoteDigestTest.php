<?php
/**
 * Integration tests — Artist\VoteDigest (security audit §5b/§4a).
 *
 * Covers:
 *   - send_daily() only mails artists in digest mode, never instant-mode ones.
 *   - A digest-mode artist with nothing open gets no email at all (no empty digest).
 *   - The digest lists every open ('pending') application the artist hasn't voted on.
 *   - An application the artist already voted YES/NO on is excluded.
 *   - A revoked vote makes the application reappear (NOT EXISTS re-derivation,
 *     not a tracked delta — see VoteDigest's own docblock).
 *   - A non-'pending' (e.g. 'admitted'/'rejected') application never appears.
 *   - The digest email contains working per-application yes/no vote links.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\VoteDigest;

class VoteDigestTest extends \WP_UnitTestCase {

	private VoteDigest $digest;

	/** All wp_mail() calls captured during a test (keys: to, subject, message, headers). */
	private array $sent_mails = [];

	/** The pre_wp_mail filter closure registered for the current test. */
	private ?\Closure $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->digest = new VoteDigest();
		update_option( 'agnosis_admission_window_days', 7 );
		$this->start_mail_capture();
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
			return true;
		};
		add_filter( 'pre_wp_mail', $this->mail_filter, 10, 2 );
	}

	private function remove_mail_capture(): void {
		if ( $this->mail_filter ) {
			remove_filter( 'pre_wp_mail', $this->mail_filter, 10 );
			$this->mail_filter = null;
		}
	}

	/** @return array<array<string,mixed>> */
	private function mails_to( string $email ): array {
		return array_values(
			array_filter( $this->sent_mails, fn( array $m ) => $m['to'] === $email )
		);
	}

	private function create_digest_artist( string $email ): int {
		$id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => $email ] );
		get_userdata( $id )->add_role( 'agnosis_artist' );
		update_user_meta( $id, '_agnosis_vote_email_mode', 'digest' );
		return $id;
	}

	private function create_instant_artist( string $email ): int {
		$id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => $email ] );
		get_userdata( $id )->add_role( 'agnosis_artist' );
		return $id;
	}

	private function insert_application( string $email, string $display_name = 'Test Applicant', string $status = 'pending' ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[ 'email' => $email, 'display_name' => $display_name, 'status' => $status ],
			[ '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	private function record_vouch( int $application_id, int $voucher_id, string $vote = 'yes', bool $revoked = false ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_application_vouches',
			[
				'application_id' => $application_id,
				'voucher_id'     => $voucher_id,
				'vote'           => $vote,
				'revoked_at'     => $revoked ? current_time( 'mysql' ) : null,
			],
			[ '%d', '%d', '%s', '%s' ]
		);
	}

	// =========================================================================
	// Recipient selection
	// =========================================================================

	public function test_only_digest_mode_artists_are_mailed(): void {
		$digest_artist  = $this->create_digest_artist( 'digest@example.com' );
		$instant_artist = $this->create_instant_artist( 'instant@example.com' );
		$this->insert_application( 'app1@example.com' );

		$this->digest->send_daily();

		$this->assertNotEmpty( $this->mails_to( 'digest@example.com' ) );
		$this->assertEmpty( $this->mails_to( 'instant@example.com' ), 'An instant-mode artist must never receive the daily digest.' );
	}

	public function test_digest_mode_artist_with_nothing_open_gets_no_email(): void {
		$this->create_digest_artist( 'nothing-open@example.com' );
		// No applications inserted at all.

		$this->digest->send_daily();

		$this->assertEmpty( $this->mails_to( 'nothing-open@example.com' ), 'No open applications must mean no digest email at all, not an empty one.' );
	}

	// =========================================================================
	// Application selection
	// =========================================================================

	public function test_pending_application_not_yet_voted_on_appears_in_digest(): void {
		$artist = $this->create_digest_artist( 'appears@example.com' );
		$this->insert_application( 'app2@example.com', 'Appearing Applicant' );

		$this->digest->send_daily();

		$body = $this->mails_to( 'appears@example.com' )[0]['message'];
		$this->assertStringContainsString( 'Appearing Applicant', $body );
	}

	public function test_already_voted_application_is_excluded(): void {
		$artist         = $this->create_digest_artist( 'voted@example.com' );
		$application_id = $this->insert_application( 'app3@example.com', 'Already Voted Applicant' );
		$this->record_vouch( $application_id, $artist, 'yes' );

		$this->digest->send_daily();

		$this->assertEmpty( $this->mails_to( 'voted@example.com' ), 'An application already voted on by this artist must not appear again.' );
	}

	public function test_revoked_vote_makes_application_reappear(): void {
		$artist         = $this->create_digest_artist( 'revoked@example.com' );
		$application_id = $this->insert_application( 'app4@example.com', 'Revoked Vote Applicant' );
		$this->record_vouch( $application_id, $artist, 'yes', revoked: true );

		$this->digest->send_daily();

		$body = $this->mails_to( 'revoked@example.com' )[0]['message'];
		$this->assertStringContainsString( 'Revoked Vote Applicant', $body, 'A revoked vote must make the application eligible again — see class docblock on re-derivation.' );
	}

	public function test_non_pending_application_never_appears(): void {
		$this->create_digest_artist( 'nonpending@example.com' );
		$this->insert_application( 'app5@example.com', 'Admitted Already', 'admitted' );

		$this->digest->send_daily();

		$this->assertEmpty( $this->mails_to( 'nonpending@example.com' ) );
	}

	public function test_another_artists_vote_does_not_exclude_it_for_this_artist(): void {
		$other_voter    = $this->create_instant_artist( 'other-voter@example.com' );
		$artist         = $this->create_digest_artist( 'still-open@example.com' );
		$application_id = $this->insert_application( 'app6@example.com', 'Partially Voted Applicant' );
		$this->record_vouch( $application_id, $other_voter, 'yes' );

		$this->digest->send_daily();

		$body = $this->mails_to( 'still-open@example.com' )[0]['message'];
		$this->assertStringContainsString( 'Partially Voted Applicant', $body, "Another artist's vote must not exclude this application for an artist who hasn't voted yet." );
	}

	// =========================================================================
	// Email content
	// =========================================================================

	public function test_digest_contains_working_vote_links_for_this_voter(): void {
		$artist         = $this->create_digest_artist( 'links@example.com' );
		$application_id = $this->insert_application( 'app7@example.com', 'Link Applicant' );

		$this->digest->send_daily();

		$body = $this->mails_to( 'links@example.com' )[0]['message'];
		$this->assertStringContainsString( 'agnosis_vouch=1', $body );
		$this->assertStringContainsString( 'vote=yes', $body );
		$this->assertStringContainsString( 'vote=no', $body );
		$this->assertStringContainsString( "voter={$artist}", $body );
		$this->assertStringContainsString( "app={$application_id}", $body );
	}

	public function test_digest_lists_multiple_open_applications_in_one_email(): void {
		$this->create_digest_artist( 'multi@example.com' );
		$this->insert_application( 'appA@example.com', 'Applicant A' );
		$this->insert_application( 'appB@example.com', 'Applicant B' );

		$this->digest->send_daily();

		$mails = $this->mails_to( 'multi@example.com' );
		$this->assertCount( 1, $mails, 'Every open application for one artist must be a single digest email, not one per application.' );
		$this->assertStringContainsString( 'Applicant A', $mails[0]['message'] );
		$this->assertStringContainsString( 'Applicant B', $mails[0]['message'] );
	}
}
