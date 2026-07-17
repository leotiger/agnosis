<?php
/**
 * Integration tests — audit §2b: Dashboards\MembersDashboard::handle_initiate_removal_vote()
 * (the admin "Open Vote" button on Settings → Community → Members).
 *
 * The handler resolves the application's subject WP user id with a raw SQL
 * query before calling Departure::admin_open_removal_vote(). That query
 * selected a `user_id` column, but `wp_agnosis_applications` has never had
 * one — the real column is `wp_user_id` (see Core\Activator's CREATE TABLE).
 * `$wpdb->get_var()` on a nonexistent column returns null (with a DB error
 * recorded, not a PHP exception), so `$user_id` was always 0 and the handler
 * always took its "not found" branch — every click of "Open Vote" silently
 * redirected with `vote_open_failed`, never actually opening a vote,
 * regardless of how valid the target application was.
 *
 * Exercised via the RedirectCapture/DieCapture pattern
 * SettingsRetryFailedNewsletterTest/DeliverabilityTest already established
 * for admin-post handlers that end in `wp_safe_redirect(); exit;`.
 *
 * MembersDashboard moved out of Admin\Settings in the 2026-07-17 god-class
 * refactor (AUDIT-1.0.0.md §4d) — same behavior, same hook
 * (admin_post_agnosis_initiate_removal_vote), new home.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\Dashboards\MembersDashboard;
use Agnosis\Tests\Integration\Support\DieCapture;
use Agnosis\Tests\Integration\Support\RedirectCapture;

class SettingsInitiateRemovalVoteTest extends \WP_UnitTestCase {

	private MembersDashboard $members_dashboard;

	protected function setUp(): void {
		parent::setUp();

		$this->members_dashboard = new MembersDashboard();

		add_filter(
			'wp_redirect',
			static function ( string $url, int $status ): never {
				throw new RedirectCapture( $url, $status );
			},
			10,
			2
		);

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
		unset( $_REQUEST['agnosis_nonce'], $_POST['application_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a WP user with the agnosis_artist role, insert an 'admitted'
	 * application row (with wp_user_id set), and return [ user_id, application_id ].
	 * Same shape as DepartureTest::create_admitted_artist().
	 *
	 * @return array{0: int, 1: int}
	 */
	private function create_admitted_artist( string $email ): array {
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

		return [ $user_id, (int) $wpdb->insert_id ];
	}

	private function submit( int $app_id ): void {
		$_POST['application_id']   = (string) $app_id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_vote_' . $app_id );

		$this->members_dashboard->handle_initiate_removal_vote();
	}

	// =========================================================================
	// Auth / nonce gates
	// =========================================================================

	public function test_handler_rejects_users_without_manage_options(): void {
		[ , $app_id ] = $this->create_admitted_artist( 'novote-perm@example.com' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$_POST['application_id']   = (string) $app_id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_vote_' . $app_id );

		try {
			$this->members_dashboard->handle_initiate_removal_vote();
			$this->fail( 'Expected wp_die() for a user without manage_options.' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}
	}

	public function test_handler_rejects_an_invalid_nonce(): void {
		[ , $app_id ] = $this->create_admitted_artist( 'novote-nonce@example.com' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST['application_id']   = (string) $app_id;
		$_REQUEST['agnosis_nonce'] = 'not-a-valid-nonce';

		try {
			$this->members_dashboard->handle_initiate_removal_vote();
			$this->fail( 'Expected wp_die() for an invalid nonce.' );
		} catch ( DieCapture $e ) {
			$this->addToAssertionCount( 1 ); // check_admin_referer() itself dies here.
		}
	}

	// =========================================================================
	// The actual §2b regression: a valid, admitted application must resolve
	// its subject user id and actually open a vote — not always fail.
	// =========================================================================

	public function test_handler_opens_a_vote_and_redirects_with_success(): void {
		global $wpdb;

		[ $subject_id, $app_id ] = $this->create_admitted_artist( 'vote-open@example.com' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		try {
			$this->submit( $app_id );
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_message=vote_opened', $e->url );
		}

		$status = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status FROM {$wpdb->prefix}agnosis_removal_requests
			  WHERE subject_user_id = %d ORDER BY id DESC LIMIT 1",
			$subject_id
		) );

		$this->assertSame( 'open', $status, 'A real removal request must have been opened for the resolved subject user — this is what the user_id/wp_user_id column bug always prevented.' );
	}

	public function test_handler_redirects_with_failure_for_an_unknown_application_id(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		try {
			$this->submit( 999999 );
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_message=vote_open_failed', $e->url );
		}
	}
}
