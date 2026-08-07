<?php
/**
 * Integration tests — Admin\Dashboards\RelayManager (interaction-surface
 * roadmap, Phase 3, WP8 "Relay support", §7 Q8).
 *
 * Covers the admin_post handlers only (option state transitions,
 * nonce/capability checks, and that the right ActivityPub::follow_relay()/
 * unfollow_relay() call fires on each transition); the federation payload
 * itself (Follow/Undo{Follow} shape, deterministic activity id) is covered by
 * Network\ActivityPubRelayTest.php.
 *
 * wp_safe_redirect()/wp_die() both call exit — intercepted via the same
 * RedirectCapture/DieCapture pattern SettingsTermTranslationCacheTest already
 * established, so these tests can assert on the outcome without killing the
 * test process.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\Dashboards\RelayManager;
use Agnosis\Tests\Integration\Support\DieCapture;
use Agnosis\Tests\Integration\Support\RedirectCapture;

class RelayManagerTest extends \WP_UnitTestCase {

	private const RELAY_URL       = 'https://relay.example/actor';
	private const RELAY_INBOX_URL = 'https://relay.example/inbox';

	private RelayManager $relay_manager;

	protected function setUp(): void {
		parent::setUp();
		$this->relay_manager = new RelayManager();
		update_option( 'agnosis_activitypub_enabled', true );

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
				// Only $message needs narrowing — wp_die() passes either a string
				// or a WP_Error. $title is already typed `string` by the signature
				// above, so the is_string() guard it used to carry was dead code
				// (0.9.68; AdmissionConfirmTest had already dropped its copy).
				$msg_str     = is_string( $message ) ? wp_strip_all_tags( $message ) : (string) $message->get_error_message();
				throw new DieCapture( $msg_str, $title, $http_status );
			};
		};
		add_filter( 'wp_die_handler',      $die_interceptor );
		add_filter( 'wp_die_ajax_handler', $die_interceptor );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_ap_relays' );
		delete_option( 'agnosis_activitypub_enabled' );
		unset( $_POST['relay_url'], $_REQUEST['_wpnonce'], $_REQUEST['agnosis_nonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Mocks both the GET actor-document fetch resolve_inbox() makes for
	 * RELAY_URL, and captures every POST delivery to RELAY_INBOX_URL — same
	 * shape as Network\ActivityPubRelayTest::mock_transport().
	 *
	 * @param array<int, array{url: string, body: array<string, mixed>|null}> &$deliveries
	 */
	private function mock_transport( array &$deliveries ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$deliveries ) {
				if ( strpos( $url, self::RELAY_INBOX_URL ) !== false ) {
					$deliveries[] = [ 'url' => $url, 'body' => json_decode( (string) ( $args['body'] ?? '' ), true ) ];
					return [
						'response' => [ 'code' => 202, 'message' => '' ],
						'headers'  => [],
						'body'     => '',
						'cookies'  => [],
						'filename' => '',
					];
				}
				if ( strpos( $url, self::RELAY_URL ) !== false ) {
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [ 'type' => 'Application', 'id' => self::RELAY_URL, 'inbox' => self::RELAY_INBOX_URL ] ),
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	// -------------------------------------------------------------------------
	// handle_add()
	// -------------------------------------------------------------------------

	public function test_handle_add_stores_the_relay_enabled_and_sends_a_follow(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['relay_url']  = self::RELAY_URL;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_add_relay' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$deliveries = [];
		$this->mock_transport( $deliveries );

		try {
			$this->relay_manager->handle_add();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$relays = get_option( 'agnosis_ap_relays' );
		$this->assertTrue( $relays[ self::RELAY_URL ] );
		$this->assertNotEmpty( $deliveries, 'Adding a relay must send it a Follow.' );
		$this->assertSame( 'Follow', $deliveries[0]['body']['type'] );
	}

	public function test_handle_add_rejects_users_without_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST['relay_url']  = self::RELAY_URL;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_add_relay' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->relay_manager->handle_add();
			$this->fail( 'Expected wp_die() for a user without manage_options.' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}

		$this->assertSame( [], get_option( 'agnosis_ap_relays', [] ) );
	}

	public function test_handle_add_is_a_noop_when_the_relay_is_already_present(): void {
		update_option( 'agnosis_ap_relays', [ self::RELAY_URL => false ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['relay_url']  = self::RELAY_URL;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_add_relay' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$deliveries = [];
		$this->mock_transport( $deliveries );

		try {
			$this->relay_manager->handle_add();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertFalse( get_option( 'agnosis_ap_relays' )[ self::RELAY_URL ], 'A re-submitted URL must not clobber its existing enabled/disabled state.' );
		$this->assertEmpty( $deliveries, 'An already-present relay must not be re-Followed.' );
	}

	public function test_handle_add_rejects_a_malformed_url(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['relay_url']  = 'not a url';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_add_relay' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->relay_manager->handle_add();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( [], get_option( 'agnosis_ap_relays', [] ) );
	}

	// -------------------------------------------------------------------------
	// handle_toggle()
	// -------------------------------------------------------------------------

	public function test_handle_toggle_disables_an_enabled_relay_and_sends_undo_follow(): void {
		update_option( 'agnosis_ap_relays', [ self::RELAY_URL => true ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['relay_url']         = self::RELAY_URL;
		$_REQUEST['agnosis_nonce']  = wp_create_nonce( 'agnosis_toggle_relay_' . md5( self::RELAY_URL ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$deliveries = [];
		$this->mock_transport( $deliveries );

		try {
			$this->relay_manager->handle_toggle();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertFalse( get_option( 'agnosis_ap_relays' )[ self::RELAY_URL ] );
		$this->assertNotEmpty( $deliveries );
		$this->assertSame( 'Undo', $deliveries[0]['body']['type'] );
	}

	public function test_handle_toggle_re_enables_a_disabled_relay_and_sends_follow(): void {
		update_option( 'agnosis_ap_relays', [ self::RELAY_URL => false ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['relay_url']        = self::RELAY_URL;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_toggle_relay_' . md5( self::RELAY_URL ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$deliveries = [];
		$this->mock_transport( $deliveries );

		try {
			$this->relay_manager->handle_toggle();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertTrue( get_option( 'agnosis_ap_relays' )[ self::RELAY_URL ] );
		$this->assertNotEmpty( $deliveries );
		$this->assertSame( 'Follow', $deliveries[0]['body']['type'] );
	}

	public function test_handle_toggle_rejects_users_without_manage_options(): void {
		update_option( 'agnosis_ap_relays', [ self::RELAY_URL => true ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST['relay_url']        = self::RELAY_URL;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_toggle_relay_' . md5( self::RELAY_URL ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->relay_manager->handle_toggle();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}

		$this->assertTrue( get_option( 'agnosis_ap_relays' )[ self::RELAY_URL ], 'State must be untouched — permission check runs before the toggle.' );
	}

	public function test_handle_toggle_rejects_a_nonce_for_a_different_relay_url(): void {
		update_option( 'agnosis_ap_relays', [ self::RELAY_URL => true, 'https://other.example/actor' => true ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['relay_url']        = self::RELAY_URL;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_toggle_relay_' . md5( 'https://other.example/actor' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->relay_manager->handle_toggle();
			$this->fail( 'Expected wp_die() for a nonce minted for a different relay.' );
		} catch ( DieCapture $e ) {
			$this->addToAssertionCount( 1 );
		}
	}

	// -------------------------------------------------------------------------
	// handle_remove()
	// -------------------------------------------------------------------------

	public function test_handle_remove_sends_undo_follow_then_deletes_an_enabled_relay(): void {
		update_option( 'agnosis_ap_relays', [ self::RELAY_URL => true ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['relay_url']        = self::RELAY_URL;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_remove_relay_' . md5( self::RELAY_URL ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$deliveries = [];
		$this->mock_transport( $deliveries );

		try {
			$this->relay_manager->handle_remove();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertArrayNotHasKey( self::RELAY_URL, get_option( 'agnosis_ap_relays' ) );
		$this->assertNotEmpty( $deliveries, 'Removing an enabled relay must send Undo{Follow} first, so it stops delivering to this node.' );
		$this->assertSame( 'Undo', $deliveries[0]['body']['type'] );
	}

	public function test_handle_remove_deletes_a_disabled_relay_without_sending_anything(): void {
		update_option( 'agnosis_ap_relays', [ self::RELAY_URL => false ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['relay_url']        = self::RELAY_URL;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_remove_relay_' . md5( self::RELAY_URL ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$deliveries = [];
		$this->mock_transport( $deliveries );

		try {
			$this->relay_manager->handle_remove();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertArrayNotHasKey( self::RELAY_URL, get_option( 'agnosis_ap_relays' ) );
		$this->assertEmpty( $deliveries, 'A relay that was already disabled has nothing to Undo.' );
	}

	public function test_handle_remove_rejects_users_without_manage_options(): void {
		update_option( 'agnosis_ap_relays', [ self::RELAY_URL => true ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST['relay_url']        = self::RELAY_URL;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_remove_relay_' . md5( self::RELAY_URL ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->relay_manager->handle_remove();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}

		$this->assertArrayHasKey( self::RELAY_URL, get_option( 'agnosis_ap_relays' ) );
	}
}
