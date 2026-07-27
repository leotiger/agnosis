<?php
/**
 * Integration tests — Network\ActivityPub, WP8 ("Relay support",
 * interaction-surface roadmap, Phase 3, §4 Phase 3F item 2, §7 Q8).
 *
 * Covers the outbound Follow/Undo{Follow} mechanism follow_relay() and
 * unfollow_relay() add on top of the inbound-only Follow handling this class
 * already had — relay_follow_activity()'s deterministic id scheme, the
 * agnosis_activitypub_enabled gate, and the resolve-inbox-then-deliver path,
 * signed as the node's own actor (never an artist's — relays are node-level,
 * §7 Q8). Admin\Dashboards\RelayManagerTest.php covers the admin-post
 * handlers (option state transitions, nonce/capability checks) that call
 * these two methods; this file only covers what they federate.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\ActivityPub;

class ActivityPubRelayTest extends \WP_UnitTestCase {

	private const RELAY_ACTOR_URL = 'https://relay.example/actor';
	private const RELAY_INBOX_URL = 'https://relay.example/inbox';

	protected function tearDown(): void {
		delete_option( 'agnosis_activitypub_enabled' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Mocks both the GET actor-document fetch resolve_inbox() makes for
	 * RELAY_ACTOR_URL, and captures every POST delivery to RELAY_INBOX_URL —
	 * mirrors ActivityPubFederateReplyTest::mock_transport(), scoped to this
	 * file's own single fixture relay.
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
				if ( strpos( $url, self::RELAY_ACTOR_URL ) !== false ) {
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [ 'type' => 'Application', 'id' => self::RELAY_ACTOR_URL, 'inbox' => self::RELAY_INBOX_URL ] ),
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

	/** A relay actor document that has no resolvable inbox — the malformed/unreachable case follow_relay()/unfollow_relay() must silently tolerate. */
	private function mock_unresolvable_transport(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) {
				if ( strpos( $url, self::RELAY_ACTOR_URL ) !== false ) {
					return new \WP_Error( 'http_request_failed', 'Could not resolve host.' );
				}
				return $preempt;
			},
			10,
			3
		);
	}

	// -------------------------------------------------------------------------
	// follow_relay()
	// -------------------------------------------------------------------------

	public function test_follow_relay_delivers_a_follow_signed_as_the_node_actor(): void {
		update_option( 'agnosis_activitypub_enabled', true );

		$deliveries = [];
		$this->mock_transport( $deliveries );

		$activitypub = new ActivityPub();
		$activitypub->follow_relay( self::RELAY_ACTOR_URL );

		$this->assertCount( 1, $deliveries );
		$follow      = $deliveries[0]['body'];
		$node_actor  = $activitypub->actor_url_for( 'node', 0 );
		$this->assertSame( 'Follow', $follow['type'] );
		$this->assertSame( $node_actor, $follow['actor'], 'A relay subscription must be signed as the node\'s own actor, never an artist\'s (§7 Q8).' );
		$this->assertSame( self::RELAY_ACTOR_URL, $follow['object'] );
	}

	public function test_follow_relay_activity_id_is_deterministic(): void {
		update_option( 'agnosis_activitypub_enabled', true );

		$deliveries = [];
		$this->mock_transport( $deliveries );

		$activitypub = new ActivityPub();
		$activitypub->follow_relay( self::RELAY_ACTOR_URL );
		$first_id = $deliveries[0]['body']['id'];

		$deliveries = [];
		$activitypub->follow_relay( self::RELAY_ACTOR_URL );
		$this->assertNotEmpty( $deliveries, 'A second follow_relay() call must still deliver a Follow.' );
		$second_id = $deliveries[0]['body']['id'];

		$this->assertSame( $first_id, $second_id, 'The same relay must always produce the same Follow activity id, so a later Undo can reference it without separate storage.' );
	}

	public function test_follow_relay_does_not_deliver_when_activitypub_disabled(): void {
		update_option( 'agnosis_activitypub_enabled', 0 );

		$deliveries = [];
		$this->mock_transport( $deliveries );

		( new ActivityPub() )->follow_relay( self::RELAY_ACTOR_URL );

		$this->assertEmpty( $deliveries );
	}

	public function test_follow_relay_is_a_silent_noop_when_the_relay_actor_is_unresolvable(): void {
		update_option( 'agnosis_activitypub_enabled', true );
		$this->mock_unresolvable_transport();

		// Must not throw/fatal — the whole point of this test.
		( new ActivityPub() )->follow_relay( self::RELAY_ACTOR_URL );
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// unfollow_relay()
	// -------------------------------------------------------------------------

	public function test_unfollow_relay_delivers_undo_follow_wrapping_the_same_activity_id(): void {
		update_option( 'agnosis_activitypub_enabled', true );

		$deliveries = [];
		$this->mock_transport( $deliveries );

		$activitypub = new ActivityPub();
		$activitypub->follow_relay( self::RELAY_ACTOR_URL );
		$follow_id = $deliveries[0]['body']['id'];

		$deliveries = []; // Only the Undo matters from here on.
		$activitypub->unfollow_relay( self::RELAY_ACTOR_URL );

		$this->assertCount( 1, $deliveries );
		$this->assertNotEmpty( $deliveries );
		$undo = $deliveries[0]['body'];
		$this->assertSame( 'Undo', $undo['type'] );
		$this->assertSame( 'Follow', $undo['object']['type'] );
		$this->assertSame( $follow_id, $undo['object']['id'], 'The Undo must wrap the SAME Follow activity id follow_relay() used.' );
	}

	public function test_unfollow_relay_does_not_deliver_when_activitypub_disabled(): void {
		update_option( 'agnosis_activitypub_enabled', 0 );

		$deliveries = [];
		$this->mock_transport( $deliveries );

		( new ActivityPub() )->unfollow_relay( self::RELAY_ACTOR_URL );

		$this->assertEmpty( $deliveries );
	}

	public function test_unfollow_relay_is_a_silent_noop_when_the_relay_actor_is_unresolvable(): void {
		update_option( 'agnosis_activitypub_enabled', true );
		$this->mock_unresolvable_transport();

		( new ActivityPub() )->unfollow_relay( self::RELAY_ACTOR_URL );
		$this->assertTrue( true );
	}
}
