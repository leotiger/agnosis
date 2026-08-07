<?php
/**
 * Integration tests — Admin\Dashboards\RhizomeManager (RN1,
 * RHIZOME-NETWORK-ROADMAP.md §4/§8, 2026-07-30).
 *
 * Covers the admin_post handlers: approve (including the
 * Node::resolve_peer_node_card() two-hop fetch it depends on), block,
 * unblock, remove, set_trust_scope, and the manual third-party add path
 * (including its own settings-toggle gate). Same RedirectCapture/DieCapture
 * interception pattern RelayManagerTest.php already established, since both
 * handle_*() methods call wp_safe_redirect()/wp_die() (which `exit`).
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\Dashboards\RhizomeManager;
use Agnosis\Tests\Integration\Support\DieCapture;
use Agnosis\Tests\Integration\Support\RedirectCapture;

class RhizomeManagerTest extends \WP_UnitTestCase {

	private const PEER_URL   = 'https://peer.example/';
	private const ACTOR_URL  = 'https://peer.example/wp-json/agnosis/v1/activitypub/actor';
	private const INBOX_URL  = 'https://peer.example/wp-json/agnosis/v1/activitypub/inbox';

	private RhizomeManager $manager;

	protected function setUp(): void {
		parent::setUp();
		$this->manager = new RhizomeManager();

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
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agnosis_nodes" );
		delete_option( 'agnosis_rhizome_allow_manual_trust' );
		unset( $_POST['peer_id'], $_POST['trust_scope'], $_POST['actor_url'], $_POST['inbox_url'], $_POST['label'], $_POST['description'], $_REQUEST['agnosis_nonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function insert_peer( string $status, array $overrides = [] ): int {
		global $wpdb;

		$data = array_merge( [
			'url'         => self::PEER_URL,
			'label'       => 'Peer Node',
			'description' => 'A test peer.',
			'trust_scope' => 'domain',
			'actor_id'    => null,
			'inbox_url'   => null,
			'status'      => $status,
			'last_seen'   => current_time( 'mysql' ),
		], $overrides );

		$wpdb->insert( $wpdb->prefix . 'agnosis_nodes', $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.

		return (int) $wpdb->insert_id;
	}

	/** @return array{status: string, trust_scope: string, actor_id: string|null, inbox_url: string|null, reciprocal: string, reciprocity_checked_at: string|null}|null */
	private function get_peer( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agnosis_nodes WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		// `$wpdb->get_row( …, ARRAY_A )` is typed array<mixed>|object|null, so the
		// is_array() guard below narrows away the object/null arms but not to the
		// declared column shape. The guard is the real runtime check; the @var is
		// this test asserting what it knows about a table the plugin itself creates
		// (0.9.68 — PHPStan 2.x).
		/** @var array{status: string, trust_scope: string, actor_id: string|null, inbox_url: string|null, reciprocal: string, reciprocity_checked_at: string|null} $row */
		return $row;
	}

	/** Mocks the two-hop .well-known -> node-card fetch Node::resolve_peer_node_card() makes. */
	private function mock_peer_node_card( bool $succeed = true ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( $succeed ) {
				if ( str_contains( $url, '.well-known/agnosis-node' ) ) {
					if ( ! $succeed ) {
						return [ 'response' => [ 'code' => 500, 'message' => '' ], 'headers' => [], 'body' => '', 'cookies' => [], 'filename' => '' ];
					}
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [ 'endpoint' => 'https://peer.example/wp-json/agnosis/v1/node' ] ),
						'cookies'  => [],
						'filename' => '',
					];
				}
				if ( 'https://peer.example/wp-json/agnosis/v1/node' === $url ) {
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [ 'actor' => self::ACTOR_URL, 'inbox' => self::INBOX_URL, 'label' => 'Peer Node' ] ),
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
	// handle_approve()
	// -------------------------------------------------------------------------

	public function test_handle_approve_resolves_node_card_and_marks_trusted(): void {
		$id = $this->insert_peer( 'pending' );
		$this->mock_peer_node_card( true );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']         = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_approve_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_approve();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'rhizome_ok=1', $e->url );
		}

		$row = $this->get_peer( $id );
		$this->assertSame( 'trusted', $row['status'] );
		$this->assertSame( self::ACTOR_URL, $row['actor_id'] );
		$this->assertSame( self::INBOX_URL, $row['inbox_url'] );
	}

	public function test_handle_approve_leaves_peer_pending_when_node_card_is_unreachable(): void {
		$id = $this->insert_peer( 'pending' );
		$this->mock_peer_node_card( false );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']         = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_approve_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_approve();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'rhizome_error=', $e->url );
		}

		$row = $this->get_peer( $id );
		$this->assertSame( 'pending', $row['status'], 'A failed resolution must never mark the row trusted.' );
		$this->assertNull( $row['actor_id'] );
	}

	public function test_handle_approve_rejects_users_without_manage_options(): void {
		$id = $this->insert_peer( 'pending' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST['peer_id']         = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_approve_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_approve();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}

		$this->assertSame( 'pending', $this->get_peer( $id )['status'] );
	}

	// -------------------------------------------------------------------------
	// handle_block() / handle_unblock()
	// -------------------------------------------------------------------------

	public function test_handle_block_marks_a_trusted_peer_blocked(): void {
		$id = $this->insert_peer( 'trusted', [ 'actor_id' => self::ACTOR_URL, 'inbox_url' => self::INBOX_URL ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']         = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_block_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_block();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( 'blocked', $this->get_peer( $id )['status'] );
	}

	public function test_handle_unblock_restores_trusted_status_and_keeps_resolved_identity(): void {
		$id = $this->insert_peer( 'blocked', [ 'actor_id' => self::ACTOR_URL, 'inbox_url' => self::INBOX_URL, 'trust_scope' => 'actor' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']         = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_unblock_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_unblock();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$row = $this->get_peer( $id );
		$this->assertSame( 'trusted', $row['status'] );
		$this->assertSame( self::ACTOR_URL, $row['actor_id'], 'Unblock must not disturb the previously-resolved actor identity.' );
		$this->assertSame( 'actor', $row['trust_scope'], 'Unblock must not disturb the previously-set trust scope.' );
	}

	public function test_handle_block_rejects_users_without_manage_options(): void {
		$id = $this->insert_peer( 'trusted' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST['peer_id']         = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_block_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_block();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}

		$this->assertSame( 'trusted', $this->get_peer( $id )['status'] );
	}

	// -------------------------------------------------------------------------
	// handle_remove()
	// -------------------------------------------------------------------------

	public function test_handle_remove_deletes_the_row(): void {
		$id = $this->insert_peer( 'blocked' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']         = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_remove_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_remove();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertNull( $this->get_peer( $id ) );
	}

	public function test_handle_remove_rejects_users_without_manage_options(): void {
		$id = $this->insert_peer( 'blocked' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST['peer_id']         = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_remove_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_remove();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}

		$this->assertNotNull( $this->get_peer( $id ) );
	}

	// -------------------------------------------------------------------------
	// handle_set_trust_scope()
	// -------------------------------------------------------------------------

	public function test_handle_set_trust_scope_updates_to_actor(): void {
		$id = $this->insert_peer( 'trusted', [ 'trust_scope' => 'domain' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']         = (string) $id;
		$_POST['trust_scope']     = 'actor';
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_set_trust_scope_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_set_trust_scope();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( 'actor', $this->get_peer( $id )['trust_scope'] );
	}

	public function test_handle_set_trust_scope_falls_back_to_domain_for_an_invalid_value(): void {
		$id = $this->insert_peer( 'trusted', [ 'trust_scope' => 'actor' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']         = (string) $id;
		$_POST['trust_scope']     = 'not-a-real-scope';
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_set_trust_scope_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_set_trust_scope();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( 'domain', $this->get_peer( $id )['trust_scope'] );
	}

	// -------------------------------------------------------------------------
	// handle_check_reciprocity() — RN4 (RHIZOME-NETWORK-ROADMAP.md §4/§8)
	// -------------------------------------------------------------------------

	public function test_handle_check_reciprocity_marks_mutual_when_peer_lists_this_node(): void {
		$id = $this->insert_peer( 'trusted' );
		add_filter( 'pre_http_request', static function () {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => (string) wp_json_encode( [ 'peers' => [ [ 'url' => home_url(), 'label' => '', 'status' => 'trusted', 'last_seen' => null ] ], 'count' => 1 ] ),
				'cookies'  => [],
				'filename' => '',
			];
		} );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']          = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_check_reciprocity_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_check_reciprocity();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'rhizome_ok=1', $e->url );
		}

		$row = $this->get_peer( $id );
		$this->assertSame( 'mutual', $row['reciprocal'] );
		$this->assertNotNull( $row['reciprocity_checked_at'] );
	}

	public function test_handle_check_reciprocity_marks_one_directional_when_peer_does_not_list_this_node(): void {
		$id = $this->insert_peer( 'trusted' );
		add_filter( 'pre_http_request', static function () {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'headers'  => [],
				'body'     => (string) wp_json_encode( [ 'peers' => [ [ 'url' => 'https://someone-else.example/', 'label' => '', 'status' => 'trusted', 'last_seen' => null ] ], 'count' => 1 ] ),
				'cookies'  => [],
				'filename' => '',
			];
		} );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']          = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_check_reciprocity_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_check_reciprocity();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( 'one_directional', $this->get_peer( $id )['reciprocal'] );
	}

	public function test_handle_check_reciprocity_marks_unknown_and_still_updates_checked_at_on_failure(): void {
		$id = $this->insert_peer( 'trusted' );
		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'http_request_failed', 'Could not resolve host' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']          = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_check_reciprocity_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_check_reciprocity();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'rhizome_error=reciprocity_unreachable', $e->url );
		}

		$row = $this->get_peer( $id );
		$this->assertSame( 'unknown', $row['reciprocal'] );
		$this->assertNotNull( $row['reciprocity_checked_at'], 'A failed check IS a check — checked_at must still be recorded, not left null.' );
	}

	public function test_handle_check_reciprocity_overwrites_a_stale_mutual_badge_to_unknown_on_a_failed_recheck(): void {
		$id = $this->insert_peer( 'trusted', [ 'reciprocal' => 'mutual', 'reciprocity_checked_at' => '2026-01-01 00:00:00' ] );
		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'http_request_failed', 'Could not resolve host' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']          = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_check_reciprocity_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_check_reciprocity();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( 'unknown', $this->get_peer( $id )['reciprocal'], 'A failed re-check must not leave a stale "mutual" badge in place.' );
	}

	public function test_handle_check_reciprocity_does_nothing_for_a_pending_peer(): void {
		$id = $this->insert_peer( 'pending' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['peer_id']          = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_check_reciprocity_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_check_reciprocity();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( 'unknown', $this->get_peer( $id )['reciprocal'] );
		$this->assertNull( $this->get_peer( $id )['reciprocity_checked_at'], 'A non-trusted peer must never be reciprocity-checked at all.' );
	}

	public function test_handle_check_reciprocity_rejects_users_without_manage_options(): void {
		$id = $this->insert_peer( 'trusted' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST['peer_id']          = (string) $id;
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_check_reciprocity_' . $id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_check_reciprocity();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}

		$this->assertSame( 'unknown', $this->get_peer( $id )['reciprocal'] );
	}

	// -------------------------------------------------------------------------
	// handle_add_manual()
	// -------------------------------------------------------------------------

	public function test_handle_add_manual_inserts_a_trusted_row_when_toggle_is_enabled(): void {
		update_option( 'agnosis_rhizome_allow_manual_trust', true );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['actor_url']       = 'https://mastodon.example/@curator';
		$_POST['inbox_url']       = 'https://mastodon.example/@curator/inbox';
		$_POST['label']           = 'Curator';
		$_POST['description']     = 'A trusted curator account.';
		$_POST['trust_scope']     = 'actor';
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_add_manual' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_add_manual();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'rhizome_ok=1', $e->url );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agnosis_nodes WHERE url = %s", 'https://mastodon.example/@curator' ), ARRAY_A );

		$this->assertNotNull( $row );
		$this->assertSame( 'trusted', $row['status'] );
		$this->assertSame( 'https://mastodon.example/@curator', $row['actor_id'] );
		$this->assertSame( 'https://mastodon.example/@curator/inbox', $row['inbox_url'] );
	}

	public function test_handle_add_manual_is_rejected_when_toggle_is_disabled(): void {
		// Toggle left at its default (off).
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['actor_url']       = 'https://mastodon.example/@curator';
		$_POST['inbox_url']       = 'https://mastodon.example/@curator/inbox';
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_add_manual' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_add_manual();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'rhizome_error=invalid_manual', $e->url );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_nodes" );
		$this->assertSame( 0, $count, 'Nothing must be inserted when the settings toggle is off, even with a valid POST.' );
	}

	public function test_handle_add_manual_rejects_malformed_urls(): void {
		update_option( 'agnosis_rhizome_allow_manual_trust', true );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['actor_url']       = 'not a url';
		$_POST['inbox_url']       = 'https://mastodon.example/@curator/inbox';
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_add_manual' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_add_manual();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'rhizome_error=invalid_manual', $e->url );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_nodes" );
		$this->assertSame( 0, $count );
	}

	public function test_handle_add_manual_rejects_users_without_manage_options(): void {
		update_option( 'agnosis_rhizome_allow_manual_trust', true );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST['actor_url']       = 'https://mastodon.example/@curator';
		$_POST['inbox_url']       = 'https://mastodon.example/@curator/inbox';
		$_REQUEST['agnosis_nonce'] = wp_create_nonce( 'agnosis_rhizome_add_manual' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->manager->handle_add_manual();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_nodes" );
		$this->assertSame( 0, $count );
	}
}
