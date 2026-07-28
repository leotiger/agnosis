<?php
/**
 * Integration tests — Network\ActivityPub, WP6 ("federating artist replies
 * outward", interaction-surface roadmap, Phase 3, §4 Phase 3B).
 *
 * WP7 (ActivityPubReplyModerationTest.php) already covers capturing the
 * artist's federate-request flag (REPLY_FEDERATE_REQUESTED_META) on the
 * gateway page; that flag was deliberately inert at the time. This file
 * covers what WP6 adds on top of it: actually delivering a Create{Note} for
 * an artist's own reply once requested, a dereferenceable AS2 id for the
 * result (serve_reply_activity_json()), repliesCount/replies on the
 * artwork's own Note, and Delete{Note} federation when a federated reply is
 * later removed.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\ActivityPub;
use Agnosis\Network\FederationSettlement;

class ActivityPubFederateReplyTest extends \WP_UnitTestCase {

	/** The federated-inbound parent reply's own remote actor (mock_transport() resolves its inbox via a GET, mirroring ActivityPubTest::mock_transport()). */
	private const REMOTE_ACTOR_URL = 'https://mastodon.example/users/remotefan';
	private const REMOTE_INBOX_URL = 'https://mastodon.example/users/remotefan/inbox';

	/** A distinct artist follower — proves the followers-broadcast path independently of the direct-to-parent-actor path. */
	private const OTHER_ACTOR_URL = 'https://mastodon.example/users/artistfollower';
	private const OTHER_INBOX_URL = 'https://mastodon.example/users/artistfollower/inbox';

	private int $artist_id;
	private int $post_id;

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'agnosis_ap_tombstones' );
		delete_option( 'agnosis_ap_reply_tombstones' );
		update_option( 'agnosis_activitypub_enabled', true );

		// @phpstan-ignore-next-line -- WP_UnitTest_Factory_For_User::create() is typed int|WP_Error but never fails for this fixture's fixed, valid args (same accepted pattern as every other *Test.php in this suite that assigns a factory-created user id straight to an int-typed property).
		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Test Artist', 'user_email' => 'artist@example.com' ] );
		get_user_by( 'id', $this->artist_id )->add_role( 'agnosis_artist' );

		// @phpstan-ignore-next-line -- $this->post_id is a real int by the time control reaches here; the int|WP_Error union only exists because of wp_insert_post()'s own return type.
		$this->post_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_title'  => 'WP6 Federate Reply Test Artwork',
			'post_author' => $this->artist_id,
		] );

		// Both gates reply_gateway_federate_offered() checks must pass for
		// any of this file's federate_requested => true scenarios to mean
		// anything.
		update_post_meta( $this->post_id, FederationSettlement::STATE_META, FederationSettlement::STATE_FEDERATED );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Invoke a private/protected ActivityPub method by name.
	 *
	 * @param array<int, mixed> $args
	 */
	private function invoke( string $method, array $args = [] ): mixed {
		$ref = new \ReflectionMethod( ActivityPub::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( new ActivityPub(), $args );
	}

	/** Insert a held-then-approved federated-INBOUND parent reply, with the same identity metas handle_create_reply() would have stored. */
	private function insert_federated_parent(): int {
		$comment_id = (int) wp_insert_comment( [
			'comment_post_ID'  => $this->post_id,
			'comment_content'  => 'A remote fan says hello.',
			'comment_author'   => 'Remote Fan',
			'comment_approved' => 1,
			'comment_type'     => ActivityPub::REPLY_COMMENT_TYPE,
		] );
		update_comment_meta( $comment_id, '_agnosis_reply_activity_id', 'https://mastodon.example/statuses/12345' );
		update_comment_meta( $comment_id, '_agnosis_reply_actor', self::REMOTE_ACTOR_URL );
		return $comment_id;
	}

	/** Insert an approved LOCAL (site visitor) reply — never has a federated identity of its own. */
	private function insert_local_visitor_parent(): int {
		return (int) wp_insert_comment( [
			'comment_post_ID'  => $this->post_id,
			'comment_content'  => 'A site visitor says hi.',
			'comment_author'   => 'Visitor',
			'comment_approved' => 1,
			'comment_type'     => ActivityPub::LOCAL_REPLY_COMMENT_TYPE,
		] );
	}

	/** The most recently inserted child comment of $parent_comment_id (the artist's own reply, once store_artist_gateway_reply() has run). */
	private function latest_child_comment( int $parent_comment_id ): ?\WP_Comment {
		$comments = get_comments( [
			'post_id' => $this->post_id,
			'parent'  => $parent_comment_id,
			'status'  => 'any',
			'orderby' => 'comment_ID',
			'order'   => 'DESC',
			'number'  => 1,
		] );
		return ( is_array( $comments ) && ! empty( $comments ) && $comments[0] instanceof \WP_Comment ) ? $comments[0] : null;
	}

	private function seed_artist_follower( string $actor_id, string $inbox_url ): void {
		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'agnosis_followers',
			[ 'owner_type' => 'artist', 'owner_id' => $this->artist_id, 'actor_id' => $actor_id, 'inbox_url' => $inbox_url ],
			[ '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Mocks both the GET actor-document fetch resolve_inbox() makes for
	 * REMOTE_ACTOR_URL, and captures every POST delivery to either
	 * REMOTE_INBOX_URL or OTHER_INBOX_URL — mirrors ActivityPubTest's own
	 * mock_transport()/mock_all_deliveries(), combined, scoped to this
	 * file's own two fixture actors so nothing else can accidentally hit
	 * the real network.
	 *
	 * @param array<int, array{url: string, body: array<string, mixed>|null}> &$deliveries
	 */
	private function mock_transport( array &$deliveries ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$deliveries ) {
				if ( strpos( $url, self::REMOTE_INBOX_URL ) !== false || strpos( $url, self::OTHER_INBOX_URL ) !== false ) {
					$deliveries[] = [ 'url' => $url, 'body' => json_decode( (string) ( $args['body'] ?? '' ), true ) ];
					return [
						'response' => [ 'code' => 202, 'message' => '' ],
						'headers'  => [],
						'body'     => '',
						'cookies'  => [],
						'filename' => '',
					];
				}
				if ( strpos( $url, self::REMOTE_ACTOR_URL ) !== false ) {
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [ 'type' => 'Person', 'id' => self::REMOTE_ACTOR_URL, 'inbox' => self::REMOTE_INBOX_URL ] ),
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

	private function get_reply_route( int $comment_id ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'GET', '/agnosis/v1/activitypub/replies/' . $comment_id );
		$request->set_param( 'id', (string) $comment_id );
		return ( new ActivityPub() )->serve_reply_activity_json( $request );
	}

	// -------------------------------------------------------------------------
	// post_to_note(): repliesCount / replies
	// -------------------------------------------------------------------------

	public function test_note_reports_replies_count_and_collection(): void {
		wp_insert_comment( [ 'comment_post_ID' => $this->post_id, 'comment_content' => 'a', 'comment_approved' => 1, 'comment_type' => ActivityPub::LOCAL_REPLY_COMMENT_TYPE ] );
		wp_insert_comment( [ 'comment_post_ID' => $this->post_id, 'comment_content' => 'b', 'comment_approved' => 1, 'comment_type' => ActivityPub::REPLY_COMMENT_TYPE ] );
		// Held — must not count.
		wp_insert_comment( [ 'comment_post_ID' => $this->post_id, 'comment_content' => 'c', 'comment_approved' => 0, 'comment_type' => ActivityPub::LOCAL_REPLY_COMMENT_TYPE ] );

		$note = $this->invoke( 'post_to_note', [ get_post( $this->post_id ) ] );

		$this->assertSame( 2, $note['repliesCount'] );
		$this->assertSame( [ 'type' => 'Collection', 'totalItems' => 2 ], $note['replies'] );
	}

	public function test_note_reports_zero_replies_for_a_fresh_artwork(): void {
		$note = $this->invoke( 'post_to_note', [ get_post( $this->post_id ) ] );

		$this->assertSame( 0, $note['repliesCount'] );
		$this->assertSame( [ 'type' => 'Collection', 'totalItems' => 0 ], $note['replies'] );
	}

	// -------------------------------------------------------------------------
	// reply_to_note(): inReplyTo / cc / Mention resolution
	// -------------------------------------------------------------------------

	public function test_reply_note_in_reply_to_targets_the_parents_remote_activity_id(): void {
		$parent_id = $this->insert_federated_parent();
		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thanks!', false ] );
		$reply = $this->latest_child_comment( $parent_id );

		$note = $this->invoke( 'reply_to_note', [ $reply ] );

		$this->assertSame( 'https://mastodon.example/statuses/12345', $note['inReplyTo'] );
		$this->assertSame( [ self::REMOTE_ACTOR_URL ], $note['cc'] );
		$this->assertSame( self::REMOTE_ACTOR_URL, $note['tag'][0]['href'] );
	}

	public function test_reply_note_in_reply_to_falls_back_to_the_artwork_for_a_visitor_parent(): void {
		$parent_id = $this->insert_local_visitor_parent();
		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thanks!', false ] );
		$reply = $this->latest_child_comment( $parent_id );

		$note = $this->invoke( 'reply_to_note', [ $reply ] );

		$this->assertSame( (string) get_permalink( $this->post_id ), $note['inReplyTo'] );
		$this->assertArrayNotHasKey( 'cc', $note );
	}

	public function test_reply_note_in_reply_to_targets_a_previously_federated_artist_reply(): void {
		$parent_id = $this->insert_local_visitor_parent();

		$deliveries = [];
		$this->mock_transport( $deliveries );
		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'First reply', true ] );
		$first_reply = $this->latest_child_comment( $parent_id );
		$this->assertNotNull( $first_reply );
		// WP13 §13.4: federate_artist_reply() no longer fires synchronously —
		// drain the queue so REPLY_FEDERATED_META is actually set on
		// $first_reply before it's used as a "previously federated" parent below.
		$this->invoke( 'drain_reply_translation_queue' );

		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, (int) $first_reply->comment_ID, 'Second reply', false ] );
		$second_reply = $this->latest_child_comment( (int) $first_reply->comment_ID );
		$this->assertNotNull( $second_reply );

		$note         = $this->invoke( 'reply_to_note', [ $second_reply ] );
		$expected_id  = $this->invoke( 'reply_object_id_for', [ (int) $first_reply->comment_ID ] );

		$this->assertSame( $expected_id, $note['inReplyTo'], 'A reply-to-a-reply must target the first reply\'s OWN dereferenceable id, not the original parent or the artwork.' );
	}

	// -------------------------------------------------------------------------
	// store_artist_gateway_reply() -> federate_artist_reply(): outbound Create
	// -------------------------------------------------------------------------

	public function test_federate_requested_reply_sets_federated_meta(): void {
		$parent_id = $this->insert_local_visitor_parent();

		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thanks!', true ] );
		// WP13 §13.4: federate_artist_reply() now fires from the drain step, not synchronously.
		$this->invoke( 'drain_reply_translation_queue' );
		$reply = $this->latest_child_comment( $parent_id );

		$this->assertNotNull( $reply );
		$this->assertSame( '1', get_comment_meta( (int) $reply->comment_ID, '_agnosis_reply_federated', true ) );
	}

	public function test_federate_not_requested_reply_leaves_federated_meta_unset(): void {
		$parent_id = $this->insert_local_visitor_parent();

		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thanks!', false ] );
		$this->invoke( 'drain_reply_translation_queue' );
		$reply = $this->latest_child_comment( $parent_id );

		$this->assertNotNull( $reply );
		$this->assertSame( '', get_comment_meta( (int) $reply->comment_ID, '_agnosis_reply_federated', true ) );
	}

	public function test_federated_reply_delivers_create_to_followers_and_to_the_parent_actor(): void {
		$parent_id = $this->insert_federated_parent();
		$this->seed_artist_follower( self::OTHER_ACTOR_URL, self::OTHER_INBOX_URL );

		$deliveries = [];
		$this->mock_transport( $deliveries );

		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thank you!', true ] );
		// WP13 §13.4: federation is dispatched from the drain step now.
		$this->invoke( 'drain_reply_translation_queue' );

		$urls = array_column( $deliveries, 'url' );
		$this->assertContains( self::OTHER_INBOX_URL, $urls, 'Must broadcast to the artist\'s own followers.' );
		$this->assertContains( self::REMOTE_INBOX_URL, $urls, 'Must ALSO deliver directly to the actor being replied to — a follower-list broadcast alone would never reach them.' );

		foreach ( $deliveries as $delivery ) {
			$this->assertSame( 'Create', $delivery['body']['type'] ?? null );
			$this->assertSame( 'Note', $delivery['body']['object']['type'] ?? null );
			$this->assertSame( 'Thank you!', trim( wp_strip_all_tags( (string) ( $delivery['body']['object']['content'] ?? '' ) ) ) );
		}
	}

	public function test_federated_reply_to_a_visitor_parent_only_broadcasts_no_direct_delivery(): void {
		$parent_id = $this->insert_local_visitor_parent();
		$this->seed_artist_follower( self::OTHER_ACTOR_URL, self::OTHER_INBOX_URL );

		$deliveries = [];
		$this->mock_transport( $deliveries );

		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thank you!', true ] );
		// WP13 §13.4: federation is dispatched from the drain step now.
		$this->invoke( 'drain_reply_translation_queue' );

		$urls = array_column( $deliveries, 'url' );
		$this->assertContains( self::OTHER_INBOX_URL, $urls );
		$this->assertNotContains( self::REMOTE_INBOX_URL, $urls, 'A visitor reply has no remote actor of its own to direct-deliver to.' );
	}

	// -------------------------------------------------------------------------
	// serve_reply_activity_json(): the dereferenceable id
	// -------------------------------------------------------------------------

	public function test_serve_reply_activity_json_returns_the_note_for_a_federated_reply(): void {
		$parent_id  = $this->insert_federated_parent();
		$deliveries = [];
		$this->mock_transport( $deliveries );
		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thanks!', true ] );
		// WP13 §13.4: federation is dispatched from the drain step now.
		$this->invoke( 'drain_reply_translation_queue' );
		$reply = $this->latest_child_comment( $parent_id );

		$response = $this->get_reply_route( (int) $reply->comment_ID );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Note', $data['type'] );
		$this->assertSame( 'Thanks!', trim( wp_strip_all_tags( $data['content'] ) ) );
	}

	public function test_serve_reply_activity_json_404s_for_a_nonexistent_comment(): void {
		$response = $this->get_reply_route( 999999999 );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'agnosis_reply_not_found', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] ?? null );
	}

	public function test_serve_reply_activity_json_404s_for_an_ordinary_visitor_reply(): void {
		$parent_id = $this->insert_local_visitor_parent();

		$response = $this->get_reply_route( $parent_id );

		$this->assertInstanceOf( \WP_Error::class, $response );
	}

	public function test_serve_reply_activity_json_404s_for_an_artist_reply_never_federated(): void {
		$parent_id = $this->insert_local_visitor_parent();
		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thanks!', false ] );
		$this->invoke( 'drain_reply_translation_queue' );
		$reply = $this->latest_child_comment( $parent_id );

		$response = $this->get_reply_route( (int) $reply->comment_ID );

		$this->assertInstanceOf( \WP_Error::class, $response );
	}

	// -------------------------------------------------------------------------
	// Removal: Delete{Note} + tombstone
	// -------------------------------------------------------------------------

	public function test_trashing_a_federated_reply_delivers_delete_and_tombstones_it(): void {
		$parent_id = $this->insert_local_visitor_parent();
		$this->seed_artist_follower( self::OTHER_ACTOR_URL, self::OTHER_INBOX_URL );

		$deliveries = [];
		$this->mock_transport( $deliveries );
		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thanks!', true ] );
		// WP13 §13.4: federation is dispatched from the drain step now.
		$this->invoke( 'drain_reply_translation_queue' );
		$reply      = $this->latest_child_comment( $parent_id );
		$comment_id = (int) $reply->comment_ID;

		$deliveries = []; // Only the Delete matters from here on.
		wp_trash_comment( $comment_id );

		$this->assertSame( '', get_comment_meta( $comment_id, '_agnosis_reply_federated', true ), 'REPLY_FEDERATED_META must be cleared once removed.' );

		$delete = null;
		foreach ( $deliveries as $delivery ) {
			if ( 'Delete' === ( $delivery['body']['type'] ?? null ) ) {
				$delete = $delivery['body'];
				break;
			}
		}
		$this->assertNotNull( $delete, 'A Delete{Tombstone} must have been federated when the reply was trashed.' );
		$this->assertSame( 'Tombstone', $delete['object']['type'] );

		$response = $this->get_reply_route( $comment_id );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 410, $response->get_status(), 'A removed federated reply must serve 410, not 404 — a remote server needs to be able to tell "gone" from "never existed".' );
	}

	public function test_trashing_a_reply_that_was_never_federated_delivers_nothing(): void {
		$parent_id = $this->insert_local_visitor_parent();
		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thanks!', false ] );
		$this->invoke( 'drain_reply_translation_queue' );
		$reply      = $this->latest_child_comment( $parent_id );
		$comment_id = (int) $reply->comment_ID;

		$deliveries = [];
		$this->mock_transport( $deliveries );

		wp_trash_comment( $comment_id );

		$this->assertEmpty( $deliveries, 'Trashing a reply that was never federated must not federate a Delete for it.' );
	}

	public function test_hard_deleting_a_federated_reply_also_delivers_delete(): void {
		$parent_id = $this->insert_local_visitor_parent();
		$this->seed_artist_follower( self::OTHER_ACTOR_URL, self::OTHER_INBOX_URL );

		$deliveries = [];
		$this->mock_transport( $deliveries );
		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thanks!', true ] );
		// WP13 §13.4: federation is dispatched from the drain step now.
		$this->invoke( 'drain_reply_translation_queue' );
		$reply      = $this->latest_child_comment( $parent_id );
		$comment_id = (int) $reply->comment_ID;

		$deliveries = [];
		wp_delete_comment( $comment_id, true ); // Force delete — bypasses trash, and 'transition_comment_status' entirely.

		$delete = null;
		foreach ( $deliveries as $delivery ) {
			if ( 'Delete' === ( $delivery['body']['type'] ?? null ) ) {
				$delete = $delivery['body'];
				break;
			}
		}
		$this->assertNotNull( $delete, 'A hard/force delete must ALSO federate a Delete — it never fires transition_comment_status, which is why delete_comment is hooked separately.' );
	}

	public function test_untrashing_a_federated_reply_does_not_federate_delete(): void {
		$parent_id = $this->insert_local_visitor_parent();

		$deliveries = [];
		$this->mock_transport( $deliveries );
		$this->invoke( 'store_artist_gateway_reply', [ $this->post_id, $parent_id, 'Thanks!', true ] );
		// WP13 §13.4: federation is dispatched from the drain step now.
		$this->invoke( 'drain_reply_translation_queue' );
		$reply      = $this->latest_child_comment( $parent_id );
		$comment_id = (int) $reply->comment_ID;

		wp_trash_comment( $comment_id );
		$deliveries = []; // Only interested in what untrashing itself does.
		wp_untrash_comment( $comment_id );

		$this->assertEmpty( $deliveries, 'Coming OUT of trash must not itself federate anything — only entering trash (or a hard delete) counts as removal.' );
	}
}
