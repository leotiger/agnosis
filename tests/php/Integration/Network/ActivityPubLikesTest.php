<?php
/**
 * Tests for the on-site like toggle (interaction-surface roadmap, Phase 3,
 * WP2, 2026-07-27) — ActivityPub::like_content()/unlike_content(),
 * like_identity()'s anonymous-salted-hash vs. artist-actor-URL split,
 * rotate_like_salt(), and render_interaction_counts()'s server-rendered
 * liked/not-liked initial state.
 *
 * Split into its own file (not ActivityPubTest.php) following the same
 * precedent WP0 set with ActivityPubReplyModerationTest.php — new
 * interaction-surface work gets its own file rather than growing the
 * already-large ActivityPubTest.php further.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\ActivityPub;

class ActivityPubLikesTest extends \WP_UnitTestCase {

	private int $post_id;

	protected function setUp(): void {
		parent::setUp();
		// Fixed rather than the real random default, so identity assertions
		// in this file are deterministic and independent of seed_options()'s
		// own wp_generate_password() value.
		update_option( 'agnosis_like_salt', 'test-fixed-salt' );

		// @phpstan-ignore-next-line -- factory()->post->create() returns int|WP_Error; a bare artwork fixture never fails in practice (nothing in this file's code paths reads post_author, so no explicit author is needed — see feedback_phpstan_baseline_test_gotchas Rule 4 for when one would be).
		$this->post_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
		] );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function simulate_visitor( string $ip, string $ua ): void {
		$_SERVER['REMOTE_ADDR']     = $ip;
		$_SERVER['HTTP_USER_AGENT'] = $ua;
	}

	private function like_request( int $post_id ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/content/' . $post_id . '/likes' );
		$request->set_param( 'id', $post_id );
		return $request;
	}

	private function unlike_request( int $post_id ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'DELETE', '/agnosis/v1/content/' . $post_id . '/likes' );
		$request->set_param( 'id', $post_id );
		return $request;
	}

	private function stored_actor_id( int $post_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only read of a small fixture table.
		$actor_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT actor_id FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d AND activity_type = 'like'",
				$post_id
			)
		);
		return null === $actor_id ? null : (string) $actor_id;
	}

	private function stored_origin( int $post_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only read of a small fixture table.
		$origin = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT origin FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d AND activity_type = 'like'",
				$post_id
			)
		);
		return null === $origin ? null : (string) $origin;
	}

	/**
	 * render_interaction_counts() calls get_block_wrapper_attributes(),
	 * which reads core's WP_Block_Supports::$block_to_render internally —
	 * only ever populated by a genuine WP_Block::render() call. Calling the
	 * render_callback directly with a mocked \WP_Block trips a "Trying to
	 * access array offset on null" error deep in WP core, per
	 * ActivityPubTest.php's own render_interaction_counts_via_block()
	 * docblock (2026-07-24 finding) — same helper duplicated here rather
	 * than shared, since that one is private to its own class.
	 */
	private function render_interaction_counts_via_block( int $post_id ): string {
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'agnosis/interaction-counts' ) ) {
			( new ActivityPub() )->register_interaction_counts_block();
		}

		$block = new \WP_Block(
			[
				'blockName'    => 'agnosis/interaction-counts',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[ 'postId' => $post_id ]
		);

		return $block->render();
	}

	// -------------------------------------------------------------------------
	// Basic toggle
	// -------------------------------------------------------------------------

	public function test_like_content_records_a_local_like_and_reports_liked_true(): void {
		$this->simulate_visitor( '203.0.113.10', 'TestAgent/1.0' );

		$response = ( new ActivityPub() )->like_content( $this->like_request( $this->post_id ) );

		// @phpstan-ignore-next-line -- like_content() returns WP_REST_Response|WP_Error; no phpstan-phpunit extension installed, so assertInstanceOf()-based narrowing doesn't help (feedback_phpstan_baseline_test_gotchas Rule 5). The test itself proves this is a real WP_REST_Response.
		$this->assertSame( 200, $response->get_status() );
		// @phpstan-ignore-next-line
		$data = $response->get_data();
		$this->assertTrue( $data['liked'] );
		$this->assertSame( 1, $data['like'] );
	}

	public function test_like_content_is_idempotent_for_the_same_visitor(): void {
		$this->simulate_visitor( '203.0.113.11', 'TestAgent/1.0' );

		$activitypub = new ActivityPub();
		$activitypub->like_content( $this->like_request( $this->post_id ) );
		$response = $activitypub->like_content( $this->like_request( $this->post_id ) );

		// @phpstan-ignore-next-line
		$this->assertSame( 1, $response->get_data()['like'], 'A repeat like from the same visitor must not double-count.' );
	}

	public function test_unlike_content_removes_the_visitors_own_like(): void {
		$this->simulate_visitor( '203.0.113.12', 'TestAgent/1.0' );

		$activitypub = new ActivityPub();
		$activitypub->like_content( $this->like_request( $this->post_id ) );
		$response = $activitypub->unlike_content( $this->unlike_request( $this->post_id ) );

		// @phpstan-ignore-next-line
		$data = $response->get_data();
		$this->assertFalse( $data['liked'] );
		$this->assertSame( 0, $data['like'] );
	}

	public function test_unlike_content_is_a_harmless_noop_when_never_liked(): void {
		$this->simulate_visitor( '203.0.113.13', 'TestAgent/1.0' );

		$response = ( new ActivityPub() )->unlike_content( $this->unlike_request( $this->post_id ) );

		// @phpstan-ignore-next-line
		$this->assertSame( 200, $response->get_status() );
		// @phpstan-ignore-next-line
		$this->assertFalse( $response->get_data()['liked'] );
		// @phpstan-ignore-next-line
		$this->assertSame( 0, $response->get_data()['like'] );
	}

	public function test_distinct_anonymous_visitors_each_count_independently(): void {
		$activitypub = new ActivityPub();

		$this->simulate_visitor( '203.0.113.20', 'TestAgent/1.0' );
		$activitypub->like_content( $this->like_request( $this->post_id ) );

		$this->simulate_visitor( '203.0.113.21', 'TestAgent/1.0' );
		$response = $activitypub->like_content( $this->like_request( $this->post_id ) );

		// @phpstan-ignore-next-line
		$this->assertSame( 2, $response->get_data()['like'] );
	}

	public function test_like_content_rejects_a_non_artwork_post(): void {
		$this->simulate_visitor( '203.0.113.30', 'TestAgent/1.0' );
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );

		// @phpstan-ignore-next-line -- $page_id is int|WP_Error (see setUp()'s own comment); like_request() takes a strictly-typed int. Don't cast — a cast trades this error for "Cannot cast int|WP_Error to int" instead.
		$response = ( new ActivityPub() )->like_content( $this->like_request( $page_id ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		// assertInstanceOf() above genuinely narrows $response to WP_Error for
		// PHPStan here (a real composer analyse run flagged an `@phpstan-
		// ignore-next-line` on these two lines as unmatched — no error was
		// there to suppress — correcting feedback_phpstan_baseline_test_gotchas
		// Rule 5's blanket claim that assertInstanceOf() narrowing never
		// helps in this codebase; it does, at least for this pattern).
		$this->assertSame( 'agnosis_like_not_found', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] ?? null );
	}

	// -------------------------------------------------------------------------
	// Identity — §7 Q5
	// -------------------------------------------------------------------------

	public function test_logged_in_artist_likes_under_their_own_actor_url(): void {
		$artist_id = self::factory()->user->create( [ 'role' => 'agnosis_artist' ] );
		// @phpstan-ignore-next-line -- $artist_id is int|WP_Error (factory()->user->create()); wp_set_current_user() takes int|WP_User. Don't cast — see the identical note above.
		wp_set_current_user( $artist_id );

		( new ActivityPub() )->like_content( $this->like_request( $this->post_id ) );

		$ref = new \ReflectionMethod( ActivityPub::class, 'actor_url_for' );
		$ref->setAccessible( true );
		$expected = $ref->invoke( new ActivityPub(), 'artist', $artist_id );

		$this->assertSame( $expected, $this->stored_actor_id( $this->post_id ) );
	}

	public function test_local_likes_are_recorded_with_origin_local(): void {
		$this->simulate_visitor( '203.0.113.40', 'TestAgent/1.0' );

		( new ActivityPub() )->like_content( $this->like_request( $this->post_id ) );

		$this->assertSame( 'local', $this->stored_origin( $this->post_id ) );
	}

	public function test_anonymous_identity_is_a_hash_not_the_raw_ip_or_ua(): void {
		$this->simulate_visitor( '203.0.113.41', 'TestAgent/1.0' );

		( new ActivityPub() )->like_content( $this->like_request( $this->post_id ) );

		$actor_id = $this->stored_actor_id( $this->post_id );
		$this->assertStringStartsWith( 'anon:', (string) $actor_id );
		$this->assertStringNotContainsString( '203.0.113.41', (string) $actor_id );
		$this->assertStringNotContainsString( 'TestAgent', (string) $actor_id );
	}

	// -------------------------------------------------------------------------
	// Salt rotation — §7 Q5: "no previous salt retained"
	// -------------------------------------------------------------------------

	public function test_rotate_like_salt_changes_the_option_value(): void {
		update_option( 'agnosis_like_salt', 'before-rotation' );

		( new ActivityPub() )->rotate_like_salt();

		$this->assertNotSame( 'before-rotation', get_option( 'agnosis_like_salt' ) );
	}

	public function test_rotated_salt_orphans_the_same_visitors_earlier_like(): void {
		$this->simulate_visitor( '203.0.113.50', 'TestAgent/1.0' );

		$activitypub = new ActivityPub();
		$activitypub->like_content( $this->like_request( $this->post_id ) );

		// Rotate — the previous salt is discarded entirely, so this exact
		// same visitor now hashes to a different actor_id. This is the
		// deliberate trade-off §7 Q5 accepts, not a bug: a rotating,
		// nothing-retained salt is what makes the anonymous identity
		// privacy-preserving in the first place.
		update_option( 'agnosis_like_salt', 'a-different-salt-entirely' );

		$response = $activitypub->unlike_content( $this->unlike_request( $this->post_id ) );

		// @phpstan-ignore-next-line
		$this->assertFalse( $response->get_data()['liked'], 'Sanity check: the new hash never had a like to begin with.' );
		// @phpstan-ignore-next-line
		$this->assertSame( 1, $response->get_data()['like'], "The original like row must survive — it wasn't this (new) identity's to remove." );
	}

	// -------------------------------------------------------------------------
	// Render callback — server-rendered initial liked state
	// -------------------------------------------------------------------------

	public function test_render_interaction_counts_reflects_the_current_visitors_liked_state(): void {
		$this->simulate_visitor( '203.0.113.60', 'TestAgent/1.0' );

		( new ActivityPub() )->like_content( $this->like_request( $this->post_id ) );

		$html = $this->render_interaction_counts_via_block( $this->post_id );

		$this->assertStringContainsString( 'aria-pressed="true"', $html );
		$this->assertStringContainsString( 'data-agnosis-like-post-id="' . $this->post_id . '"', $html );
	}

	public function test_render_interaction_counts_shows_not_liked_for_a_fresh_visitor(): void {
		$this->simulate_visitor( '203.0.113.61', 'TestAgent/1.0' );

		$html = $this->render_interaction_counts_via_block( $this->post_id );

		$this->assertStringContainsString( 'aria-pressed="false"', $html );
	}

	// -------------------------------------------------------------------------
	// Rate limit — abuse prevention only, per §5 (no gate/limit "as a feature")
	// -------------------------------------------------------------------------

	public function test_rate_limit_like_returns_wp_error_once_exceeded(): void {
		$this->simulate_visitor( '203.0.113.70', 'TestAgent/1.0' );

		$activitypub = new ActivityPub();
		$last        = true;
		for ( $i = 0; $i < 25; $i++ ) {
			$last = $activitypub->rate_limit_like();
		}

		$this->assertInstanceOf( \WP_Error::class, $last );
	}
}
