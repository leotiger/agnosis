<?php
/**
 * Integration tests — the "wiring claim" that a configured Settings →
 * Branding → Accent color actually reaches a rendered email, per sender
 * class.
 *
 * AUDIT-0.9.29.md §2a's test-coverage note (repeated as deferred-test debt
 * in AUDIT-1.0.0.md §4d, closed here): a feature's Settings surface gets
 * tested (Admin\EmailBrandingColorFieldsTest), the consuming shell class
 * gets tested (Core\EmailTemplateTest — accent()/button()/render() in
 * isolation), and the wiring claim itself — "every email" actually uses
 * whatever's configured — goes untested. Every sender class below calls
 * Core\EmailTemplate::accent() (directly, or indirectly through
 * EmailTemplate::button() with no color override) somewhere in its actual
 * send path; each test here configures one distinctive, non-default accent
 * color and asserts that exact value survives into the sender's real,
 * captured output. This is deliberately not a test of accent()/button()
 * themselves (EmailTemplateTest already owns that) — it is one assertion
 * per sender class that the plumbing between Settings and that class's own
 * send path is actually connected.
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Artist\AdmissionNotification;
use Agnosis\Artist\CommunityBroadcast;
use Agnosis\Artist\DepartureNotification;
use Agnosis\Artist\Invitation;
use Agnosis\Artist\VoteDigest;
use Agnosis\Core\RateLimiter;
use Agnosis\Newsletter\Mailer;
use Agnosis\Publishing\Notification;

class EmailBrandingAccentWiringTest extends \WP_UnitTestCase {

	/** A distinctive, non-default accent color no template would ever emit by coincidence. */
	private const ACCENT = '#ab12cd';

	protected function setUp(): void {
		parent::setUp();
		update_option( 'agnosis_email_accent', self::ACCENT );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_email_accent' );
		delete_option( 'agnosis_email_community' );
		delete_option( 'agnosis_admission_window_days' );
		delete_option( 'agnosis_admission_percent' );
		delete_option( 'agnosis_admission_minimum' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/** @param array<string, mixed>|null $captured */
	private function capture_mail( ?array &$captured ): callable {
		$filter = function ( $pre, array $atts ) use ( &$captured ) {
			$captured = $atts;
			return true; // Short-circuit — do not actually send.
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		return $filter;
	}

	private function create_artist( string $email, string $display_name = '' ): int {
		$args = [ 'role' => 'subscriber', 'user_email' => $email ];
		if ( '' !== $display_name ) {
			$args['display_name'] = $display_name;
		}
		$id   = self::factory()->user->create( $args );
		$user = get_userdata( $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	// =========================================================================
	// Newsletter\Mailer — public digest's "View it online" link
	// =========================================================================

	public function test_mailer_public_digest_view_online_link_uses_configured_accent(): void {
		$html = Mailer::build_email( 'public', '', '<p>digest</p>', 'https://example.com/unsub', 'https://example.com/newsletter/1/' );

		$this->assertStringContainsString( self::ACCENT, $html );
	}

	// =========================================================================
	// Newsletter\Subscription — public subscribe confirmation email
	// =========================================================================

	public function test_newsletter_subscribe_confirmation_email_uses_configured_accent(): void {
		RateLimiter::reset( 'newsletter_subscribe', RateLimiter::client_ip(), 300 );

		$captured = null;
		$filter   = $this->capture_mail( $captured );

		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/newsletter/subscribe' );
		$request->set_param( 'email', 'accent-check@example.com' );
		rest_do_request( $request );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( self::ACCENT, $captured['message'] );
	}

	// =========================================================================
	// Artist\AdmissionNotification — "confirm your application" email
	// =========================================================================

	public function test_admission_confirm_email_uses_configured_accent(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		( new AdmissionNotification() )->on_application_unverified( 1, 'applicant@example.com', 'Applicant', 'tok123' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( self::ACCENT, $captured['message'] );
	}

	// =========================================================================
	// Artist\CommunityBroadcast — artist-to-artist message body accent border
	// =========================================================================

	public function test_community_broadcast_uses_configured_accent(): void {
		update_option( 'agnosis_email_community', 'community@agnosis.test' );

		$sender_id = $this->create_artist( 'sender@example.com', 'Sender' );
		$this->create_artist( 'recipient@example.com', 'Recipient' );

		$captured = null;
		$filter   = $this->capture_mail( $captured );

		( new CommunityBroadcast() )->broadcast( $sender_id, 'Subject', 'A message body.' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( self::ACCENT, $captured['message'] );
	}

	// =========================================================================
	// Artist\VoteDigest — daily digest's vote buttons
	// =========================================================================

	private function insert_pending_application( string $email ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[ 'email' => $email, 'display_name' => 'Test Applicant', 'status' => 'pending' ],
			[ '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	public function test_vote_digest_uses_configured_accent(): void {
		update_option( 'agnosis_admission_window_days', 7 );

		$voter_id = $this->create_artist( 'voter@example.com', 'Voter' );
		update_user_meta( $voter_id, '_agnosis_vote_email_mode', 'digest' );
		$this->insert_pending_application( 'applicant2@example.com' );

		$captured = null;
		$filter   = $this->capture_mail( $captured );

		( new VoteDigest() )->send_daily();

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( self::ACCENT, $captured['message'] );
	}

	// =========================================================================
	// Artist\DepartureNotification — removal-vote-opened email's "Vote NO" button
	// =========================================================================

	private function insert_removal_request( int $subject_user_id ): int {
		global $wpdb;
		$wpdb->insert(
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

	public function test_departure_vote_opened_uses_configured_accent(): void {
		$subject_id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'subject@example.com' ] );
		$this->create_artist( 'voter2@example.com', 'Voter Two' );
		$request_id = $this->insert_removal_request( $subject_id );

		$captured = null;
		$filter   = $this->capture_mail( $captured );

		( new DepartureNotification() )->on_vote_opened( $request_id, gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( self::ACCENT, $captured['message'] );
	}

	// =========================================================================
	// Artist\Invitation — "you're invited" email
	// =========================================================================

	public function test_invitation_email_uses_configured_accent(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		( new Invitation() )->send( 'prospect@example.com', '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( self::ACCENT, $captured['message'] );
	}

	// =========================================================================
	// Publishing\Notification — removal-requested email
	// =========================================================================

	public function test_notification_removal_email_uses_configured_accent(): void {
		$artist_id = $this->create_artist( 'removal-accent@example.com', 'Removal Artist' );
		$post_id   = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist_id,
			'post_title'  => 'Artwork To Remove',
		] );
		update_post_meta( $post_id, '_agnosis_removal_token', bin2hex( random_bytes( 16 ) ) );

		$captured = null;
		$filter   = $this->capture_mail( $captured );

		( new Notification() )->on_removal_requested( $post_id, $artist_id );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( self::ACCENT, $captured['message'] );
	}
}
