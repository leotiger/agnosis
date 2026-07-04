<?php
/**
 * Integration tests — RemovalVoteConfirm template_redirect vote handler.
 *
 * RemovalVoteConfirm is the email-link shim for the community removal-vote
 * flow (security audit §2e — DepartureNotification::removal_vote_url() built
 * links pointing at a "RemovalVoteConfirm shim" that never actually existed,
 * so every removal-vote email link was silently dead). It mirrors
 * VouchConfirm's admission-vote pattern exactly, including the §2a GET/POST
 * split: GET renders a confirm interstitial and never records a vote, POST
 * records it via Departure::record_vote_on_request(). wp_die() is intercepted
 * via the 'wp_die_handler' filter (thrown as DieCapture) so both paths can be
 * exercised end-to-end without killing the test process.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Departure;
use Agnosis\Artist\DepartureNotification;
use Agnosis\Artist\RemovalVoteConfirm;
use Agnosis\Tests\Integration\Support\DieCapture;

class RemovalVoteConfirmTest extends \WP_UnitTestCase {

	private RemovalVoteConfirm $confirm;
	private Departure $departure;

	protected function setUp(): void {
		parent::setUp();

		$this->departure = new Departure();
		$this->confirm   = new RemovalVoteConfirm( $this->departure );

		// Intercept wp_die() — throw instead of outputting HTML/exiting.
		$die_interceptor = static function (): callable {
			return static function ( string|\WP_Error $message, string $title = '', array $args = [] ): never {
				$http_status = (int) ( $args['response'] ?? 200 );
				$title_str   = is_string( $title ) ? $title : '';
				$msg_str     = is_string( $message ) ? wp_strip_all_tags( $message ) : (string) $message->get_error_message();
				throw new DieCapture( $msg_str, $title_str, $http_status );
			};
		};
		add_filter( 'wp_die_handler',      $die_interceptor );
		add_filter( 'wp_die_ajax_handler', $die_interceptor );
	}

	protected function tearDown(): void {
		unset( $_GET['agnosis_removal_vote'], $_GET['rid'], $_GET['vid'], $_GET['vote'], $_GET['token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_POST['agnosis_removal_vote'], $_POST['rid'], $_POST['vid'], $_POST['vote'], $_POST['token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_SERVER['REQUEST_METHOD'] );

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_artist( string $email ): int {
		$id   = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$user = get_userdata( $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	/** Insert an 'open' removal request for $subject_id and return its row ID. */
	private function create_open_removal_request( int $subject_id ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_removal_requests',
			[
				'subject_user_id' => $subject_id,
				'status'          => 'open',
				'opened_at'       => current_time( 'mysql' ),
				'closes_at'       => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	private function make_token( int $voter_id, int $request_id, string $vote ): string {
		return hash_hmac( 'sha256', "{$voter_id}|{$request_id}|{$vote}", wp_salt( 'auth' ) );
	}

	// =========================================================================
	// handle() — no-op guard
	// =========================================================================

	public function test_handle_is_noop_when_agnosis_removal_vote_absent(): void {
		global $wpdb;

		unset( $_GET['agnosis_removal_vote'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_removal_votes" );

		$this->confirm->handle();

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_removal_votes" );

		$this->assertSame( $before, $after, 'handle() must not write to DB when agnosis_removal_vote is absent.' );
	}

	public function test_register_hooks_adds_template_redirect_action(): void {
		remove_all_actions( 'template_redirect' );

		$this->confirm->register_hooks();

		$this->assertGreaterThan( 0, has_action( 'template_redirect', [ $this->confirm, 'handle' ] ) );
	}

	// =========================================================================
	// handle() — GET renders the confirm interstitial, does not record (§2a)
	// =========================================================================

	public function test_handle_get_renders_interstitial_without_recording_vote(): void {
		$subject_id = $this->create_artist( 'subject@example.com' );
		$voter_id   = $this->create_artist( 'voter@example.com' );
		$request_id = $this->create_open_removal_request( $subject_id );
		$token      = $this->make_token( $voter_id, $request_id, 'yes' );

		$_SERVER['REQUEST_METHOD']    = 'GET';
		$_GET['agnosis_removal_vote'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['rid']                  = (string) $request_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['vid']                  = (string) $voter_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['vote']                 = 'yes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']                = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'remove this artist', $e->body );
		}

		global $wpdb;
		$votes = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_removal_votes WHERE request_id = %d",
			$request_id
		) );
		$this->assertSame( 0, $votes, 'GET alone must never record a removal vote.' );
	}

	public function test_handle_post_records_vote_and_renders_success(): void {
		$subject_id = $this->create_artist( 'subject2@example.com' );
		$voter_id   = $this->create_artist( 'voter2@example.com' );
		$request_id = $this->create_open_removal_request( $subject_id );
		$token      = $this->make_token( $voter_id, $request_id, 'yes' );

		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['agnosis_removal_vote'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['rid']                  = (string) $request_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['vid']                  = (string) $voter_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['vote']                 = 'yes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']                = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the success page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}

		global $wpdb;
		$votes = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_removal_votes WHERE request_id = %d AND voter_id = %d AND vote = 'yes'",
			$request_id,
			$voter_id
		) );
		$this->assertSame( 1, $votes, 'POST with a valid token must record the vote.' );
	}

	public function test_handle_get_with_tampered_token_renders_error_and_does_not_record(): void {
		$subject_id = $this->create_artist( 'subject3@example.com' );
		$voter_id   = $this->create_artist( 'voter3@example.com' );
		$request_id = $this->create_open_removal_request( $subject_id );

		$_SERVER['REQUEST_METHOD']    = 'GET';
		$_GET['agnosis_removal_vote'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['rid']                  = (string) $request_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['vid']                  = (string) $voter_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['vote']                 = 'yes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']                = 'not-the-real-token'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
			$this->assertStringContainsString( 'tampered', $e->body );
		}

		global $wpdb;
		$votes = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_removal_votes WHERE request_id = %d",
			$request_id
		) );
		$this->assertSame( 0, $votes );
	}

	public function test_handle_post_self_vote_renders_error(): void {
		$subject_id = $this->create_artist( 'subject4@example.com' );
		$request_id = $this->create_open_removal_request( $subject_id );
		$token      = $this->make_token( $subject_id, $request_id, 'yes' );

		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['agnosis_removal_vote'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['rid']                  = (string) $request_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['vid']                  = (string) $subject_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['vote']                 = 'yes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']                = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}

	// =========================================================================
	// Token ↔ removal_vote_url() round-trip
	// =========================================================================

	public function test_vote_url_token_is_accepted_by_handle(): void {
		$subject_id = $this->create_artist( 'subject5@example.com' );
		$voter_id   = $this->create_artist( 'voter5@example.com' );
		$request_id = $this->create_open_removal_request( $subject_id );

		$url    = DepartureNotification::removal_vote_url( $request_id, $voter_id, 'yes' );
		$parsed = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );

		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['agnosis_removal_vote'] = $parsed['agnosis_removal_vote']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['rid']                  = $parsed['rid']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['vid']                  = $parsed['vid']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['vote']                 = $parsed['vote']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']                = $parsed['token']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the success page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status, 'A token produced by removal_vote_url() must be accepted.' );
		}
	}
}
