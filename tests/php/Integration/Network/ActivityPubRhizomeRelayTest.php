<?php
/**
 * Integration tests — Network\ActivityPub, RN3 (inbound relay-boost,
 * RHIZOME-NETWORK-ROADMAP.md §3/§5/§8, 2026-07-30).
 *
 * Covers relay_trusted_announce() (triggered from inbox()'s Announce case)
 * and the private helpers it calls: matching_trusted_peer() (domain vs.
 * actor trust_scope matching), relay_announce_activity() (the re-wrapped
 * Announce's shape and deterministic id), relay_already_queued() (redelivery
 * idempotency), enqueue_relay_to_followers() (always-queue, never live —
 * §5, ANSWERED), and log_relay_activity() (RN3b, §11b/§12 — the
 * agnosis_rhizome_relay_log row written for every recognized relay event,
 * independent of whether any local follower exists to receive it).
 *
 * Same "call inbox() directly, no signature needed" pattern
 * ActivityPubTest.php's own Follow/Undo/Like tests already use —
 * verify_inbox_signature() is a separate REST permission_callback, never
 * invoked by calling inbox() directly, so $body['actor'] is trusted as-is
 * exactly the way inbox()'s own real request lifecycle already guarantees it
 * (see relay_trusted_announce()'s own docblock).
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\ActivityPub;

class ActivityPubRhizomeRelayTest extends \WP_UnitTestCase {

	private const PEER_ACTOR_URL   = 'https://trusted-peer.example/wp-json/agnosis/v1/activitypub/actor';
	private const OTHER_ACTOR_URL  = 'https://trusted-peer.example/wp-json/agnosis/v1/activitypub/actor/99';
	private const PEER_SITE_URL    = 'https://trusted-peer.example/';
	private const REMOTE_OBJECT_URL = 'https://trusted-peer.example/artwork/some-piece';
	private const FOLLOWER_INBOX_1 = 'https://follower-one.example/inbox';
	private const FOLLOWER_INBOX_2 = 'https://follower-two.example/inbox';

	protected function setUp(): void {
		parent::setUp();
		update_option( 'agnosis_activitypub_enabled', true );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_activitypub_enabled' );
		parent::tearDown();
		// agnosis_nodes / agnosis_followers / agnosis_ap_delivery_queue are
		// real custom tables — WP_UnitTestCase's per-test transaction
		// rollback clears them, same as ActivityPubTest.php's own note.
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function seed_trusted_peer( string $actor_id, string $trust_scope = 'domain', string $status = 'trusted' ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'agnosis_nodes', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
			'url'         => self::PEER_SITE_URL,
			'actor_id'    => $actor_id,
			'inbox_url'   => 'https://trusted-peer.example/wp-json/agnosis/v1/activitypub/inbox',
			'trust_scope' => $trust_scope,
			'status'      => $status,
		] );
		return (int) $wpdb->insert_id;
	}

	private function seed_local_follower( string $actor_id, string $inbox_url ): void {
		global $wpdb;
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
			$wpdb->prefix . 'agnosis_followers',
			[ 'owner_type' => 'node', 'owner_id' => 0, 'actor_id' => $actor_id, 'inbox_url' => $inbox_url ],
			[ '%s', '%d', '%s', '%s' ]
		);
	}

	private function make_local_artwork(): int {
		// @phpstan-ignore-next-line -- factory()->post->create() returns int|WP_Error; a bare artwork fixture never fails in practice (see feedback_phpstan_baseline_test_gotchas Rule 4).
		return self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
	}

	/** @return array<int, array<string, mixed>> */
	private function relay_queue_rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}agnosis_ap_delivery_queue WHERE owner_type = 'node' AND owner_id = 0 AND activity_type = 'Announce'",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/** @return array<int, array<string, mixed>> */
	private function relay_log_rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}agnosis_rhizome_relay_log", ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	private function build_announce_request( string $actor, string $object_url ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'Content-Type', 'application/activity+json' );
		$request->set_body( (string) wp_json_encode( [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'type'     => 'Announce',
			'id'       => $actor . '#announce-' . md5( $object_url ),
			'actor'    => $actor,
			'object'   => $object_url,
		] ) );
		return $request;
	}

	// -------------------------------------------------------------------------
	// Trust matching + enqueue
	// -------------------------------------------------------------------------

	public function test_announce_from_trusted_domain_peer_is_enqueued_for_every_local_follower(): void {
		$this->seed_trusted_peer( self::PEER_ACTOR_URL, 'domain' );
		$this->seed_local_follower( 'https://follower-one.example/actor', self::FOLLOWER_INBOX_1 );
		$this->seed_local_follower( 'https://follower-two.example/actor', self::FOLLOWER_INBOX_2 );

		$response = ( new ActivityPub() )->inbox( $this->build_announce_request( self::PEER_ACTOR_URL, self::REMOTE_OBJECT_URL ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 'accepted', $response->get_data()['status'] );

		$rows = $this->relay_queue_rows();
		$this->assertCount( 2, $rows, 'One queued row per local follower.' );

		$inboxes = array_column( $rows, 'inbox_url' );
		$this->assertContains( self::FOLLOWER_INBOX_1, $inboxes );
		$this->assertContains( self::FOLLOWER_INBOX_2, $inboxes );

		$activity = json_decode( $rows[0]['activity_json'], true );
		$this->assertSame( 'Announce', $activity['type'] );
		$this->assertSame( rest_url( 'agnosis/v1/activitypub/actor' ), $activity['actor'], 'The relay must be attributed to THIS node\'s own actor, not the original peer.' );
		$this->assertSame( self::REMOTE_OBJECT_URL, $activity['object'], 'The object must be forwarded exactly as the peer sent it — relaying amplifies reach, never rewrites content.' );
		$this->assertContains( self::PEER_ACTOR_URL, $activity['cc'], 'The original peer actor should be cc\'d for attribution.' );
	}

	public function test_announce_queues_immediately_pending_not_delayed_like_a_failed_retry(): void {
		$this->seed_trusted_peer( self::PEER_ACTOR_URL, 'domain' );
		$this->seed_local_follower( 'https://follower-one.example/actor', self::FOLLOWER_INBOX_1 );

		( new ActivityPub() )->inbox( $this->build_announce_request( self::PEER_ACTOR_URL, self::REMOTE_OBJECT_URL ) );

		$rows = $this->relay_queue_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'pending', $rows[0]['status'] );
		$this->assertLessThanOrEqual( time(), strtotime( $rows[0]['next_attempt_at'] . ' UTC' ), 'A relay must be immediately due, not delayed by the failure-retry backoff — it was never attempted live at all (§5, ANSWERED).' );
	}

	public function test_announce_from_actor_scoped_trust_matches_only_the_exact_actor(): void {
		$this->seed_trusted_peer( self::PEER_ACTOR_URL, 'actor' );
		$this->seed_local_follower( 'https://follower-one.example/actor', self::FOLLOWER_INBOX_1 );

		// A DIFFERENT actor on the same trusted domain — must not match under actor-scope trust.
		( new ActivityPub() )->inbox( $this->build_announce_request( self::OTHER_ACTOR_URL, self::REMOTE_OBJECT_URL ) );
		$this->assertCount( 0, $this->relay_queue_rows(), 'Actor-scoped trust must not extend to a different actor on the same domain.' );

		// The exact trusted actor — must match.
		( new ActivityPub() )->inbox( $this->build_announce_request( self::PEER_ACTOR_URL, self::REMOTE_OBJECT_URL ) );
		$this->assertCount( 1, $this->relay_queue_rows() );
	}

	public function test_announce_from_untrusted_actor_is_ignored(): void {
		$this->seed_local_follower( 'https://follower-one.example/actor', self::FOLLOWER_INBOX_1 );
		// No agnosis_nodes row at all for this actor.

		$response = ( new ActivityPub() )->inbox( $this->build_announce_request( 'https://unknown.example/actor', self::REMOTE_OBJECT_URL ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 'accepted', $response->get_data()['status'], 'inbox() must still accept the activity normally — trust is silent, not an error.' );
		$this->assertCount( 0, $this->relay_queue_rows() );
		$this->assertCount( 0, $this->relay_log_rows(), 'An untrusted actor\'s Announce must not be logged as a rhizome relay event either.' );
	}

	public function test_announce_from_a_blocked_peer_is_ignored(): void {
		$this->seed_trusted_peer( self::PEER_ACTOR_URL, 'domain', 'blocked' );
		$this->seed_local_follower( 'https://follower-one.example/actor', self::FOLLOWER_INBOX_1 );

		( new ActivityPub() )->inbox( $this->build_announce_request( self::PEER_ACTOR_URL, self::REMOTE_OBJECT_URL ) );

		$this->assertCount( 0, $this->relay_queue_rows(), 'A blocked peer must never relay, regardless of trust_scope.' );
		$this->assertCount( 0, $this->relay_log_rows(), 'A blocked peer\'s Announce must not be logged either.' );
	}

	public function test_announce_whose_object_resolves_to_a_local_artwork_is_not_relayed(): void {
		$this->seed_trusted_peer( self::PEER_ACTOR_URL, 'domain' );
		$this->seed_local_follower( 'https://follower-one.example/actor', self::FOLLOWER_INBOX_1 );

		$post_id      = $this->make_local_artwork();
		$local_object = (string) get_permalink( $post_id );

		( new ActivityPub() )->inbox( $this->build_announce_request( self::PEER_ACTOR_URL, $local_object ) );

		$this->assertCount( 0, $this->relay_queue_rows(), 'A boost of this node\'s OWN artwork is already fully handled by record_interaction() — relaying it again would just echo it back at its own followers.' );
		$this->assertCount( 0, $this->relay_log_rows(), 'A local-object boost is not a rhizome relay event and must not be logged as one.' );
	}

	public function test_announce_redelivery_is_not_relayed_twice(): void {
		$this->seed_trusted_peer( self::PEER_ACTOR_URL, 'domain' );
		$this->seed_local_follower( 'https://follower-one.example/actor', self::FOLLOWER_INBOX_1 );

		$request = $this->build_announce_request( self::PEER_ACTOR_URL, self::REMOTE_OBJECT_URL );

		( new ActivityPub() )->inbox( $request );
		( new ActivityPub() )->inbox( $request ); // Simulates a Mastodon-style at-least-once redelivery.

		$this->assertCount( 1, $this->relay_queue_rows(), 'A redelivered Announce must not queue a second round of per-follower rows.' );
		$this->assertCount( 1, $this->relay_log_rows(), 'A redelivered Announce must not write a second rhizome-relay-log row either — relay_activity_id\'s own UNIQUE key is a second, independent idempotency guard.' );
	}

	/**
	 * §13 F3 (2026-07-30). The regression this covers is the whole reason
	 * log_relay_activity() now has a return value: relay_already_queued()
	 * searches agnosis_ap_delivery_queue, and dispatch_queue() DELETES a row
	 * the moment its delivery succeeds — so a few minutes after a relay goes
	 * out, that check no longer recognizes it, while an inbound Announce can
	 * legitimately be redelivered for days. The test above only proves the
	 * mid-flight case (rows still pending); this one clears the queue first,
	 * exactly as a successful delivery would, and asserts the DURABLE guard
	 * — agnosis_rhizome_relay_log.relay_activity_id's own UNIQUE key — still
	 * holds. Before the fix this queued a full second round of per-follower
	 * rows and every follower saw the boost twice.
	 */
	public function test_redelivery_after_the_queue_has_drained_is_still_not_relayed_twice(): void {
		global $wpdb;

		$this->seed_trusted_peer( self::PEER_ACTOR_URL, 'domain' );
		$this->seed_local_follower( 'https://follower-one.example/actor', self::FOLLOWER_INBOX_1 );

		$request = $this->build_announce_request( self::PEER_ACTOR_URL, self::REMOTE_OBJECT_URL );

		( new ActivityPub() )->inbox( $request );
		$this->assertCount( 1, $this->relay_queue_rows(), 'Sanity: the first relay queued exactly one per-follower row.' );

		// Simulate the delivery actually succeeding — dispatch_queue() deletes
		// the row on success, which is precisely what erased the only guard
		// this used to have.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agnosis_ap_delivery_queue WHERE owner_type = 'node' AND owner_id = 0" );
		$this->assertCount( 0, $this->relay_queue_rows() );

		// A redelivered Announce is ordinary federation traffic, so recognizing
		// one must not surface as a database fault. Letting the UNIQUE key
		// reject the INSERT made wpdb::print_error() emit a "WordPress
		// database error: Duplicate entry …" block — printed output under
		// WP_DEBUG_DISPLAY, log noise everywhere else. log_relay_activity()
		// therefore SELECTs first and only falls back to the key (suppressed)
		// for the genuine concurrent race.
		ob_start();
		( new ActivityPub() )->inbox( $request );
		$printed = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'wpdberror', $printed, 'A routine redelivery must not print a WordPress database error — expected behavior must not look like a fault.' );
		$this->assertStringNotContainsString( 'Duplicate entry', $printed );

		$this->assertCount( 0, $this->relay_queue_rows(), 'A redelivery arriving after the queue drained must not re-queue the same relay — the delivery-queue check can no longer see it, so the relay log has to.' );
		$this->assertCount( 1, $this->relay_log_rows(), 'And the log must still hold exactly one row for this relay activity id.' );
	}

	public function test_announce_is_not_relayed_when_activitypub_federation_is_disabled(): void {
		update_option( 'agnosis_activitypub_enabled', false );
		$this->seed_trusted_peer( self::PEER_ACTOR_URL, 'domain' );
		$this->seed_local_follower( 'https://follower-one.example/actor', self::FOLLOWER_INBOX_1 );

		( new ActivityPub() )->inbox( $this->build_announce_request( self::PEER_ACTOR_URL, self::REMOTE_OBJECT_URL ) );

		$this->assertCount( 0, $this->relay_queue_rows() );
		$this->assertCount( 0, $this->relay_log_rows(), 'Nothing is recognized as a relay event at all when federation is off — the log must stay empty too.' );
	}

	public function test_announce_from_trusted_peer_with_no_local_followers_enqueues_nothing(): void {
		$this->seed_trusted_peer( self::PEER_ACTOR_URL, 'domain' );
		// No followers seeded at all.

		$response = ( new ActivityPub() )->inbox( $this->build_announce_request( self::PEER_ACTOR_URL, self::REMOTE_OBJECT_URL ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 'accepted', $response->get_data()['status'] );
		$this->assertCount( 0, $this->relay_queue_rows() );
		$this->assertCount( 1, $this->relay_log_rows(), 'A relay event is still logged even with zero local followers to fan out to — the log answers "what happened on the rhizome," not "what got delivered."' );
	}

	// -------------------------------------------------------------------------
	// RN3b — relay-activity log (RHIZOME-NETWORK-ROADMAP.md §11b/§12)
	// -------------------------------------------------------------------------

	public function test_recognized_relay_writes_a_log_row_with_the_correct_fields(): void {
		$peer_id = $this->seed_trusted_peer( self::PEER_ACTOR_URL, 'domain' );
		$this->seed_local_follower( 'https://follower-one.example/actor', self::FOLLOWER_INBOX_1 );

		( new ActivityPub() )->inbox( $this->build_announce_request( self::PEER_ACTOR_URL, self::REMOTE_OBJECT_URL ) );

		$log_rows = $this->relay_log_rows();
		$this->assertCount( 1, $log_rows );

		$queue_rows = $this->relay_queue_rows();
		$this->assertCount( 1, $queue_rows );
		$queued_activity = json_decode( $queue_rows[0]['activity_json'], true );

		$this->assertSame( $peer_id, (int) $log_rows[0]['peer_node_id'], 'peer_node_id must point at the agnosis_nodes row that governed this relay.' );
		$this->assertSame( self::PEER_SITE_URL, $log_rows[0]['peer_url'] );
		$this->assertSame( self::PEER_ACTOR_URL, $log_rows[0]['announcing_actor_id'], 'The actual announcing actor, not agnosis_nodes.actor_id (which can be NULL for a domain-scoped row).' );
		$this->assertSame( self::REMOTE_OBJECT_URL, $log_rows[0]['object_url'] );
		$this->assertSame( $queued_activity['id'], $log_rows[0]['relay_activity_id'], 'The logged id must be the exact same deterministic relay id the delivery queue rows carry.' );
	}

	public function test_actor_scoped_relay_log_records_the_announcing_actor_not_a_different_actor_on_the_same_domain(): void {
		$this->seed_trusted_peer( self::PEER_ACTOR_URL, 'actor' );
		$this->seed_local_follower( 'https://follower-one.example/actor', self::FOLLOWER_INBOX_1 );

		( new ActivityPub() )->inbox( $this->build_announce_request( self::PEER_ACTOR_URL, self::REMOTE_OBJECT_URL ) );

		$log_rows = $this->relay_log_rows();
		$this->assertCount( 1, $log_rows );
		$this->assertSame( self::PEER_ACTOR_URL, $log_rows[0]['announcing_actor_id'] );
	}
}
