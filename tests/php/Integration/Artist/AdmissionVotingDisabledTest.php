<?php
/**
 * Integration tests — agnosis_voting_disabled ("admin approval only") for
 * the admission/vouching flow (Admission.php, AdmissionNotification.php).
 *
 * Covers:
 *   - record_vote()/vouch() refuses while the setting is on (shared choke
 *     point for both the REST route and VouchConfirm's email-link vote).
 *   - Admission::admin_admit()/admin_reject() are unaffected — they were
 *     already a bypass before this setting existed and remain the intended
 *     path while voting is disabled.
 *   - check_expired_applications() (the daily auto-reject cron) stops acting
 *     on pending applications while the setting is on, so an application
 *     with no possible votes isn't silently rejected for want of them.
 *   - AdmissionNotification::on_application_received() skips the community
 *     vote blast and sends a single actionable admin email instead.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Admission;

class AdmissionVotingDisabledTest extends \WP_UnitTestCase {

	private Admission $admission;

	protected function setUp(): void {
		parent::setUp();
		$this->admission = new Admission();
		update_option( 'agnosis_admission_percent', 0 );
		update_option( 'agnosis_admission_minimum', 1 );
		update_option( 'agnosis_admission_window_days', 7 );
	}

	public function tearDown(): void {
		delete_option( 'agnosis_voting_disabled' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Create a WP user with the agnosis_artist role. */
	private function create_artist( string $email = '' ): int {
		$args = [ 'role' => 'subscriber' ];
		if ( $email ) {
			$args['user_email'] = $email;
		}
		$id   = self::factory()->user->create( $args );
		$user = get_user_by( 'id', $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	/** Insert a 'pending' application row directly and return its ID. */
	private function create_pending_application( string $email = 'applicant@example.com', string $applied_at = '' ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => $email,
				'display_name' => 'Test Applicant',
				'status'       => 'pending',
				'applied_at'   => '' !== $applied_at ? $applied_at : current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	private function application_status( int $application_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$application_id
			)
		);
	}

	// -------------------------------------------------------------------------
	// record_vote() / vouch() refusal
	// -------------------------------------------------------------------------

	public function test_record_vote_refuses_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		$voucher_id     = $this->create_artist( 'voter@example.com' );
		$application_id = $this->create_pending_application( 'target@example.com' );

		$result = $this->admission->record_vote( $voucher_id, $application_id, 'yes' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'agnosis_voting_disabled', $result->get_error_code() );
	}

	public function test_record_vote_works_normally_when_voting_enabled(): void {
		$voucher_id     = $this->create_artist( 'voter2@example.com' );
		$application_id = $this->create_pending_application( 'target2@example.com' );

		$result = $this->admission->record_vote( $voucher_id, $application_id, 'yes' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'recorded', $result->get_data()['status'] );
	}

	public function test_vouch_rest_route_refuses_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		$voucher_id     = $this->create_artist( 'voter3@example.com' );
		$application_id = $this->create_pending_application( 'target3@example.com' );

		wp_set_current_user( $voucher_id );
		$request = new \WP_REST_Request( 'POST', "/agnosis/v1/admission/vouch/{$application_id}" );
		$request->set_param( 'id', $application_id );
		$request->set_param( 'vote', 'yes' );

		$result = $this->admission->vouch( $request );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_no_vouch_is_recorded_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		global $wpdb;

		$voucher_id     = $this->create_artist( 'voter4@example.com' );
		$application_id = $this->create_pending_application( 'target4@example.com' );

		$this->admission->record_vote( $voucher_id, $application_id, 'yes' );

		$this->assertSame( 0, $this->admission->count_positive_vouches( $application_id ) );
	}

	// -------------------------------------------------------------------------
	// Admin override actions remain available
	// -------------------------------------------------------------------------

	public function test_admin_admit_still_works_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		$application_id = $this->create_pending_application( 'admitme@example.com' );

		$ok = $this->admission->admin_admit( $application_id );

		$this->assertTrue( $ok );
		$this->assertSame( 'admitted', $this->application_status( $application_id ) );
		$this->assertNotFalse( get_user_by( 'email', 'admitme@example.com' ) );
	}

	public function test_admin_reject_still_works_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		$application_id = $this->create_pending_application( 'rejectme@example.com' );

		$ok = $this->admission->admin_reject( $application_id );

		$this->assertTrue( $ok );
		$this->assertSame( 'rejected', $this->application_status( $application_id ) );
	}

	// -------------------------------------------------------------------------
	// check_expired_applications() — auto-reject cron
	// -------------------------------------------------------------------------

	public function test_expired_application_is_rejected_when_voting_enabled(): void {
		$application_id = $this->create_pending_application(
			'staleenabled@example.com',
			gmdate( 'Y-m-d H:i:s', strtotime( '-8 days' ) )
		);

		$this->admission->check_expired_applications();

		$this->assertSame( 'rejected', $this->application_status( $application_id ) );
	}

	public function test_expired_application_is_left_pending_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		$application_id = $this->create_pending_application(
			'stalewaiting@example.com',
			gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
		);

		$this->admission->check_expired_applications();

		$this->assertSame( 'pending', $this->application_status( $application_id ),
			'An application must wait indefinitely for an admin decision when community voting is disabled, not auto-reject for want of votes it can never receive.' );
	}

	// -------------------------------------------------------------------------
	// AdmissionNotification — vote blast skipped, admin action-needed email sent
	// -------------------------------------------------------------------------

	public function test_no_vote_blast_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		$this->create_artist( 'watcher@example.com' );

		$mails  = [];
		$filter = function ( $pre, array $atts ) use ( &$mails ): bool {
			$mails[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );

		$application_id = $this->create_pending_application( 'noblastadmin@example.com' );
		do_action( 'agnosis_artist_applied', $application_id, 'noblastadmin@example.com', 'No Blast Admin' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$to_artist = array_filter( $mails, fn( array $m ) => 'watcher@example.com' === $m['to'] );
		$this->assertEmpty( $to_artist, 'No artist should receive a vote-blast email while community voting is disabled.' );
	}

	public function test_admin_action_needed_email_sent_when_voting_disabled(): void {
		update_option( 'agnosis_voting_disabled', true );

		$mails  = [];
		$filter = function ( $pre, array $atts ) use ( &$mails ): bool {
			$mails[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );

		$application_id = $this->create_pending_application( 'needsadmin@example.com' );
		do_action( 'agnosis_artist_applied', $application_id, 'needsadmin@example.com', 'Needs Admin' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$admin_email = get_option( 'admin_email' );
		$to_admin    = array_values( array_filter( $mails, fn( array $m ) => $admin_email === $m['to'] ) );

		$this->assertNotEmpty( $to_admin, 'The admin must be emailed an actionable notice when community voting is disabled.' );
		$this->assertStringContainsString( 'awaiting your review', $to_admin[0]['subject'] );
	}
}
