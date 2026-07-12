<?php
/**
 * Integration tests for Network\ActivityPub.
 *
 * Audit §3b: `resolve_inbox()` and `deliver()` fetch/post to actor and inbox
 * URLs that are entirely peer-supplied (an inbound Follow activity's "actor"
 * field, and the inbox URL advertised in that actor's own document). These
 * tests confirm both call sites use the "safe" wp_safe_remote_*() variants
 * by asserting `reject_unsafe_urls` is set on the outgoing request args —
 * that flag is what causes WP core to reject private/loopback/link-local/ULA
 * targets (and re-check on every redirect), so its presence is the
 * observable proof the SSRF guard is wired up. WP core's own test suite
 * already covers the IP-range rejection logic itself.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\ActivityPub;

class ActivityPubTest extends \WP_UnitTestCase {

	private const REMOTE_ACTOR_URL = 'https://mastodon.example/users/remoteartist';
	private const REMOTE_INBOX_URL = 'https://mastodon.example/users/remoteartist/inbox';

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'agnosis_ap_followers' );
	}

	/**
	 * Register a pre_http_request filter that records reject_unsafe_urls per
	 * URL and serves a minimal valid response for both the actor-document GET
	 * and the inbox POST.
	 *
	 * @param array<string, bool|null> &$seen_reject_flag Keyed by URL substring ('actor'/'inbox'), populated as the filter observes requests.
	 */
	private function mock_transport( array &$seen_reject_flag ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$seen_reject_flag ) {
				// Check the inbox URL first: REMOTE_INBOX_URL is
				// REMOTE_ACTOR_URL + '/inbox', so REMOTE_ACTOR_URL is a
				// literal prefix of it — checking the actor branch first
				// would wrongly match every inbox request too.
				if ( strpos( $url, self::REMOTE_INBOX_URL ) !== false ) {
					$seen_reject_flag['inbox'] = $args['reject_unsafe_urls'] ?? null;
					return [
						'response' => [ 'code' => 202, 'message' => 'Accepted' ],
						'headers'  => [],
						'body'     => '',
						'cookies'  => [],
						'filename' => '',
					];
				}
				if ( strpos( $url, self::REMOTE_ACTOR_URL ) !== false ) {
					$seen_reject_flag['actor'] = $args['reject_unsafe_urls'] ?? null;
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [
							'type'  => 'Person',
							'id'    => self::REMOTE_ACTOR_URL,
							'inbox' => self::REMOTE_INBOX_URL,
						] ),
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

	/**
	 * Build a Follow-activity inbox request. Content-Type must be an explicit
	 * JSON media type — WP_REST_Request::get_json_params() only parses the
	 * body when is_json_content_type() is true; without this header
	 * ActivityPub::inbox() sees an empty $body and silently falls through to
	 * the "ignored" branch, never reaching handle_follow().
	 */
	private function build_follow_request(): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'Content-Type', 'application/activity+json' );
		$request->set_body( (string) wp_json_encode( [ 'type' => 'Follow', 'actor' => self::REMOTE_ACTOR_URL ] ) );
		return $request;
	}

	public function test_handle_follow_resolves_inbox_with_reject_unsafe_urls(): void {
		$seen = [];
		$this->mock_transport( $seen );

		$activitypub = new ActivityPub();
		$response    = $activitypub->inbox( $this->build_follow_request() );

		$this->assertSame( 'accepted', $response->get_data()['status'], 'Sanity check: handle_follow() must actually have run.' );
		$this->assertTrue( $seen['actor'] ?? false, 'resolve_inbox() must fetch the actor document via wp_safe_remote_get() (reject_unsafe_urls => true).' );
	}

	public function test_handle_follow_sends_accept_with_reject_unsafe_urls(): void {
		$seen = [];
		$this->mock_transport( $seen );

		$activitypub = new ActivityPub();
		$response    = $activitypub->inbox( $this->build_follow_request() );

		$this->assertSame( 'accepted', $response->get_data()['status'], 'Sanity check: handle_follow() must actually have run.' );
		$this->assertTrue( $seen['inbox'] ?? false, 'deliver() must POST the Accept via wp_safe_remote_post() (reject_unsafe_urls => true).' );
	}

	public function test_handle_follow_stores_resolved_inbox_and_accepts(): void {
		$seen = [];
		$this->mock_transport( $seen );

		$activitypub = new ActivityPub();
		$response    = $activitypub->inbox( $this->build_follow_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'accepted', $response->get_data()['status'] );

		$followers = get_option( 'agnosis_ap_followers', [] );
		$this->assertSame( self::REMOTE_INBOX_URL, $followers[ self::REMOTE_ACTOR_URL ] ?? null );
	}

	public function test_broadcast_delivers_to_stored_followers_with_reject_unsafe_urls(): void {
		update_option( 'agnosis_ap_followers', [ self::REMOTE_ACTOR_URL => self::REMOTE_INBOX_URL ] );
		update_option( 'agnosis_activitypub_enabled', true );

		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		// Sanity-check every precondition broadcast() itself checks, so a
		// failure below points at the exact guard clause responsible instead
		// of leaving it to be re-diagnosed from a single assertion.
		$this->assertTrue( (bool) get_option( 'agnosis_activitypub_enabled' ), 'Precondition: agnosis_activitypub_enabled must be truthy.' );
		$post = get_post( $post_id );
		$this->assertNotNull( $post, 'Precondition: factory must have created the post.' );
		$this->assertSame( 'agnosis_artwork', $post->post_type, 'Precondition: post_type must be agnosis_artwork.' );
		$this->assertSame( [ self::REMOTE_ACTOR_URL => self::REMOTE_INBOX_URL ], get_option( 'agnosis_ap_followers' ), 'Precondition: followers option must round-trip as stored.' );

		$seen = [];
		$this->mock_transport( $seen );

		$activitypub = new ActivityPub();
		$activitypub->broadcast( $post_id );

		$this->assertTrue( $seen['inbox'] ?? false, 'broadcast() -> deliver() must POST via wp_safe_remote_post() (reject_unsafe_urls => true).' );
	}
}
