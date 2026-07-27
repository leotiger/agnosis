<?php
/**
 * Integration tests — Network\ActivityPub, WP5 ("Boosts", interaction-surface
 * roadmap, Phase 3, §4 Phase 3E).
 *
 * Covers ActivityPub::write_boost() — the local (on-Agnosis) boost write
 * path reached only via Newsletter\InteractionGateway's 'boost' action
 * (WP7-style token gateway, see that class's own tests for the GET/POST
 * dispatch coverage) — and the Announce/Undo{Announce} federation it
 * triggers. Split into its own file following the precedent
 * ActivityPubLikesTest.php's own docblock already documents: new
 * interaction-surface work gets its own file rather than growing the
 * already-large ActivityPubTest.php further.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\ActivityPub;

class ActivityPubBoostTest extends \WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( 'agnosis_activitypub_enabled' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_artist(): int {
		// @phpstan-ignore-next-line -- factory()->user->create() returns int|WP_Error; a bare artist fixture never fails in practice (see feedback_phpstan_baseline_test_gotchas Rule 4).
		return self::factory()->user->create( [ 'role' => 'agnosis_artist' ] );
	}

	private function make_artwork( int $author_id ): int {
		// @phpstan-ignore-next-line -- see create_artist()'s own note.
		return self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $author_id,
		] );
	}

	private function boost_row_count( int $post_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only read of a small fixture table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d AND activity_type = 'announce'",
				$post_id
			)
		);
	}

	private function stored_boost_actor_id( int $post_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only read of a small fixture table.
		$actor_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT actor_id FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d AND activity_type = 'announce'",
				$post_id
			)
		);
		return null === $actor_id ? null : (string) $actor_id;
	}

	private function stored_boost_origin( int $post_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only read of a small fixture table.
		$origin = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT origin FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d AND activity_type = 'announce'",
				$post_id
			)
		);
		return null === $origin ? null : (string) $origin;
	}

	/** Seed one row in agnosis_followers scoped to a specific owner (audit §3h) — mirrors ActivityPubTest::seed_follower_for(), duplicated per this file's own "no cross-test-file dependency" convention. */
	private function seed_follower_for( string $owner_type, int $owner_id, string $actor_id, string $inbox_url ): void {
		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'agnosis_followers',
			[ 'owner_type' => $owner_type, 'owner_id' => $owner_id, 'actor_id' => $actor_id, 'inbox_url' => $inbox_url ],
			[ '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Capture every outbound POST this test's HTTP filter sees.
	 *
	 * @param array<int, array{url: string, body: array<string, mixed>|null}> &$deliveries
	 */
	private function mock_all_deliveries( array &$deliveries ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$deliveries ) {
				if ( 'POST' === ( $args['method'] ?? '' ) ) {
					$deliveries[] = [ 'url' => $url, 'body' => json_decode( (string) ( $args['body'] ?? '' ), true ) ];
					return [
						'response' => [ 'code' => 202, 'message' => '' ],
						'headers'  => [],
						'body'     => '',
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
	// Basic write path
	// -------------------------------------------------------------------------

	public function test_write_boost_records_an_announce_row_and_reports_boosted_true(): void {
		$owner     = $this->create_artist();
		$booster   = $this->create_artist();
		$post_id   = $this->make_artwork( $owner );

		$result = ( new ActivityPub() )->write_boost( $post_id, $booster, true );

		$this->assertTrue( $result['boosted'] );
		$this->assertSame( 1, $result['announce'] );
		$this->assertSame( 1, $this->boost_row_count( $post_id ) );
		$this->assertSame( 'local', $this->stored_boost_origin( $post_id ) );

		$expected_actor = ( new ActivityPub() )->actor_url_for( 'artist', $booster );
		$this->assertSame( $expected_actor, $this->stored_boost_actor_id( $post_id ) );
	}

	public function test_write_boost_is_idempotent_for_the_same_artist(): void {
		$owner   = $this->create_artist();
		$booster = $this->create_artist();
		$post_id = $this->make_artwork( $owner );

		$activitypub = new ActivityPub();
		$activitypub->write_boost( $post_id, $booster, true );
		$activitypub->write_boost( $post_id, $booster, true );

		$this->assertSame( 1, $this->boost_row_count( $post_id ), 'A repeat boost from the same artist must not double-count.' );
	}

	public function test_write_boost_false_removes_the_row(): void {
		$owner   = $this->create_artist();
		$booster = $this->create_artist();
		$post_id = $this->make_artwork( $owner );

		$activitypub = new ActivityPub();
		$activitypub->write_boost( $post_id, $booster, true );
		$result = $activitypub->write_boost( $post_id, $booster, false );

		$this->assertFalse( $result['boosted'] );
		$this->assertSame( 0, $result['announce'] );
		$this->assertSame( 0, $this->boost_row_count( $post_id ) );
	}

	public function test_write_boost_false_is_a_harmless_noop_when_never_boosted(): void {
		$owner   = $this->create_artist();
		$booster = $this->create_artist();
		$post_id = $this->make_artwork( $owner );

		$result = ( new ActivityPub() )->write_boost( $post_id, $booster, false );

		$this->assertFalse( $result['boosted'] );
		$this->assertSame( 0, $this->boost_row_count( $post_id ) );
	}

	public function test_self_boost_is_permitted(): void {
		$artist  = $this->create_artist();
		$post_id = $this->make_artwork( $artist );

		$result = ( new ActivityPub() )->write_boost( $post_id, $artist, true );

		$this->assertTrue( $result['boosted'], 'An artist boosting their own artwork must be permitted (§4 Phase 3E step 1).' );
		$this->assertSame( 1, $this->boost_row_count( $post_id ) );
	}

	public function test_write_boost_counts_multiple_distinct_boosters(): void {
		$owner    = $this->create_artist();
		$booster1 = $this->create_artist();
		$booster2 = $this->create_artist();
		$post_id  = $this->make_artwork( $owner );

		$activitypub = new ActivityPub();
		$activitypub->write_boost( $post_id, $booster1, true );
		$result = $activitypub->write_boost( $post_id, $booster2, true );

		$this->assertSame( 2, $result['announce'] );
	}

	public function test_write_boost_returns_false_for_a_nonexistent_post(): void {
		$booster = $this->create_artist();

		$result = ( new ActivityPub() )->write_boost( 999999999, $booster, true );

		$this->assertFalse( $result['boosted'] );
		$this->assertSame( 0, $result['announce'] );
	}

	// -------------------------------------------------------------------------
	// Federation: Announce on boost, Undo{Announce} on un-boost
	// -------------------------------------------------------------------------

	public function test_write_boost_federates_announce_to_the_boosters_followers(): void {
		$owner   = $this->create_artist();
		$booster = $this->create_artist();
		$post_id = $this->make_artwork( $owner );

		$booster_actor = ( new ActivityPub() )->actor_url_for( 'artist', $booster );
		$this->seed_follower_for( 'artist', $booster, 'https://mastodon.example/users/fan', 'https://mastodon.example/users/fan/inbox' );
		update_option( 'agnosis_activitypub_enabled', true );

		$deliveries = [];
		$this->mock_all_deliveries( $deliveries );

		( new ActivityPub() )->write_boost( $post_id, $booster, true );

		$this->assertNotEmpty( $deliveries, 'A boost must federate an Announce to the booster\'s own followers.' );
		$announce = $deliveries[0]['body'];
		$this->assertSame( 'Announce', $announce['type'] );
		$this->assertSame( $booster_actor, $announce['actor'] );
		$this->assertSame( get_permalink( $post_id ), $announce['object'] );
		$this->assertContains( $booster_actor . '/followers', $announce['cc'] );
	}

	public function test_boost_announce_cc_includes_the_boosted_artists_own_actor(): void {
		$owner   = $this->create_artist();
		$booster = $this->create_artist();
		$post_id = $this->make_artwork( $owner );

		$owner_actor = ( new ActivityPub() )->actor_url_for( 'artist', $owner );
		$this->seed_follower_for( 'artist', $booster, 'https://mastodon.example/users/fan', 'https://mastodon.example/users/fan/inbox' );
		update_option( 'agnosis_activitypub_enabled', true );

		$deliveries = [];
		$this->mock_all_deliveries( $deliveries );

		( new ActivityPub() )->write_boost( $post_id, $booster, true );

		$this->assertContains( $owner_actor, $deliveries[0]['body']['cc'], 'B (the boosted artist) must be addressed directly, even though B\'s own side of this is just a row (B is local).' );
	}

	public function test_write_boost_does_not_deliver_when_activitypub_disabled(): void {
		$owner   = $this->create_artist();
		$booster = $this->create_artist();
		$post_id = $this->make_artwork( $owner );

		$this->seed_follower_for( 'artist', $booster, 'https://mastodon.example/users/fan', 'https://mastodon.example/users/fan/inbox' );
		// Not `false`: update_option( $k, false ) is a silent no-op when the
		// option row doesn't exist yet (get_option() returns false for
		// "missing" too, so WP sees old === new and skips the write) — the
		// same gotcha already documented at
		// ActivityPubTest::test_singular_activity_json_declines_when_activitypub_disabled().
		// `0` persists reliably as falsy.
		update_option( 'agnosis_activitypub_enabled', 0 );

		$deliveries = [];
		$this->mock_all_deliveries( $deliveries );

		$result = ( new ActivityPub() )->write_boost( $post_id, $booster, true );

		$this->assertTrue( $result['boosted'], 'The local row is still written even when federation is globally disabled.' );
		$this->assertEmpty( $deliveries, 'No Announce should be federated while agnosis_activitypub_enabled is off.' );
	}

	public function test_unboost_federates_undo_announce_wrapping_the_same_activity_id(): void {
		$owner   = $this->create_artist();
		$booster = $this->create_artist();
		$post_id = $this->make_artwork( $owner );

		$this->seed_follower_for( 'artist', $booster, 'https://mastodon.example/users/fan', 'https://mastodon.example/users/fan/inbox' );
		update_option( 'agnosis_activitypub_enabled', true );

		$deliveries = [];
		$this->mock_all_deliveries( $deliveries );

		$activitypub = new ActivityPub();
		$activitypub->write_boost( $post_id, $booster, true );
		$announce_id = $deliveries[0]['body']['id'];

		$deliveries = []; // Only the Undo matters from here on.
		$activitypub->write_boost( $post_id, $booster, false );

		$this->assertNotEmpty( $deliveries, 'Un-boosting must federate an Undo{Announce}.' );
		$undo = $deliveries[0]['body'];
		$this->assertSame( 'Undo', $undo['type'] );
		$this->assertSame( 'Announce', $undo['object']['type'] );
		$this->assertSame( $announce_id, $undo['object']['id'], 'The Undo must wrap the SAME activity id the original Announce used.' );
	}

	public function test_unboost_does_not_deliver_when_activitypub_disabled(): void {
		$owner   = $this->create_artist();
		$booster = $this->create_artist();
		$post_id = $this->make_artwork( $owner );

		$this->seed_follower_for( 'artist', $booster, 'https://mastodon.example/users/fan', 'https://mastodon.example/users/fan/inbox' );
		update_option( 'agnosis_activitypub_enabled', true );

		$activitypub = new ActivityPub();
		$activitypub->write_boost( $post_id, $booster, true );

		update_option( 'agnosis_activitypub_enabled', 0 );

		$deliveries = [];
		$this->mock_all_deliveries( $deliveries );
		$activitypub->write_boost( $post_id, $booster, false );

		$this->assertEmpty( $deliveries );
	}
}
