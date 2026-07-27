<?php
/**
 * Integration tests — Newsletter\InteractionGateway (interaction-surface
 * roadmap, Phase 3, WP3, 2026-07-27): the no-login gateway an artist or
 * public-newsletter subscriber lands on after clicking the emailed
 * `{{AGNOSIS_LIKE:<post_id>}}` link.
 *
 * Covers, in order:
 *   - substitute_links()/inert() — placeholder → real link / stripped-to-nothing
 *   - token()/verify() (via Reflection — both private static) — round-trip,
 *     tamper, and expiry
 *   - handle_confirm() — the same GET-renders/POST-acts split (§7a) as every
 *     other emailed-action-link shim, exercised via the 'wp_redirect' and
 *     'wp_die_handler' interception pattern ReviewConfirmIntegrationTest/
 *     VouchConfirmTest already establish (wp_safe_redirect()/wp_die() both
 *     call exit — intercept before that fires rather than killing the test
 *     process).
 *   - handle_result() — the minimal thank-you page / no-op guard
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Network\ActivityPub;
use Agnosis\Newsletter\InteractionGateway;
use Agnosis\Tests\Integration\Support\DieCapture;
use Agnosis\Tests\Integration\Support\RedirectCapture;

class InteractionGatewayTest extends \WP_UnitTestCase {

	private InteractionGateway $gateway;
	private \ReflectionMethod $token_method;
	private \ReflectionMethod $verify_method;

	protected function setUp(): void {
		parent::setUp();

		$this->gateway = new InteractionGateway();

		$rc = new \ReflectionClass( InteractionGateway::class );
		$this->token_method = $rc->getMethod( 'token' );
		$this->token_method->setAccessible( true );
		$this->verify_method = $rc->getMethod( 'verify' );
		$this->verify_method->setAccessible( true );

		// Intercept wp_safe_redirect() — throw instead of calling exit.
		add_filter(
			'wp_redirect',
			static function ( string $url, int $status ): never {
				throw new RedirectCapture( $url, $status );
			},
			10,
			2
		);

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
		unset( $_GET['agnosis_interaction'], $_GET['post'], $_GET['artist'], $_GET['do'], $_GET['expires'], $_GET['token'], $_GET['agnosis_interaction_result'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_POST['agnosis_interaction'], $_POST['post'], $_POST['artist'], $_POST['do'], $_POST['expires'], $_POST['token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function call_token( int $post_id, int $artist_id, string $action, int $expires ): string {
		return (string) $this->token_method->invoke( null, $post_id, $artist_id, $action, $expires );
	}

	private function call_verify( int $post_id, int $artist_id, string $action, int $expires, string $token ): bool {
		return (bool) $this->verify_method->invoke( null, $post_id, $artist_id, $action, $expires, $token );
	}

	private function create_artist(): int {
		// @phpstan-ignore-next-line -- factory()->user->create() returns int|WP_Error; a bare artist fixture never fails in practice (see feedback_phpstan_baseline_test_gotchas Rule 4).
		return self::factory()->user->create( [ 'role' => 'agnosis_artist' ] );
	}

	private function make_artwork(): int {
		// @phpstan-ignore-next-line -- see create_artist()'s own note; nothing here reads post_author.
		return self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
		] );
	}

	/** @param array<string, string> $params */
	private function simulate_get( array $params ): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		foreach ( $params as $key => $value ) {
			$_GET[ $key ] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/** @param array<string, string> $params */
	private function simulate_post( array $params ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		foreach ( $params as $key => $value ) {
			$_POST[ $key ] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	private function simulate_visitor( string $ip, string $ua ): void {
		$_SERVER['REMOTE_ADDR']     = $ip;
		$_SERVER['HTTP_USER_AGENT'] = $ua;
	}

	private function like_row_count( int $post_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only read of a small fixture table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d AND activity_type = 'like'",
				$post_id
			)
		);
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

	/**
	 * @return array<string, string>
	 */
	private function query_from_link( string $html ): array {
		preg_match( '/href="([^"]+)"/', $html, $matches );
		$url = html_entity_decode( $matches[1] ?? '' );

		$raw_query = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $raw_query );

		// Every value substitute_links()/build_url() actually emits is a flat
		// scalar (post/artist/do/expires/token) — parse_str()'s own return type
		// is wider (it supports PHP's bracket array syntax in a query string,
		// which nothing here ever produces), so normalize explicitly rather
		// than widening this helper's return type to match parse_str()'s
		// worst case.
		$query = [];
		foreach ( $raw_query as $key => $value ) {
			$query[ (string) $key ] = is_array( $value ) ? '' : (string) $value;
		}
		return $query;
	}

	// =========================================================================
	// substitute_links() / inert()
	// =========================================================================

	public function test_substitute_links_replaces_placeholder_with_a_real_link(): void {
		$html = 'before {{AGNOSIS_LIKE:42}} after';

		$result = InteractionGateway::substitute_links( $html, 0 );

		$this->assertStringNotContainsString( '{{AGNOSIS_LIKE:', $result );
		$this->assertStringContainsString( 'before', $result );
		$this->assertStringContainsString( 'after', $result );
		$this->assertStringContainsString( '<a href=', $result );

		$query = $this->query_from_link( $result );
		$this->assertSame( '42', $query['post'] ?? null );
		$this->assertSame( '0', $query['artist'] ?? null );
		$this->assertSame( 'like', $query['do'] ?? null );
		$this->assertArrayHasKey( 'expires', $query );
		$this->assertArrayHasKey( 'token', $query );
	}

	public function test_substitute_links_keys_the_link_on_the_given_recipient_artist_id(): void {
		$result = InteractionGateway::substitute_links( '{{AGNOSIS_LIKE:7}}', 99 );

		$query = $this->query_from_link( $result );
		$this->assertSame( '99', $query['artist'] ?? null );
	}

	public function test_substitute_links_handles_multiple_placeholders_independently(): void {
		$result = InteractionGateway::substitute_links(
			'{{AGNOSIS_LIKE:1}} ... {{AGNOSIS_LIKE:2}}',
			5
		);

		$this->assertStringNotContainsString( '{{AGNOSIS_LIKE:', $result );
		$this->assertSame( 2, substr_count( $result, '<a href=' ) );
	}

	public function test_inert_strips_the_placeholder_entirely(): void {
		$result = InteractionGateway::inert( 'x {{AGNOSIS_LIKE:5}} y' );

		$this->assertStringNotContainsString( '{{AGNOSIS_LIKE:', $result );
		$this->assertStringNotContainsString( '<a ', $result );
		$this->assertStringContainsString( 'x', $result );
		$this->assertStringContainsString( 'y', $result );
	}

	// =========================================================================
	// substitute_boost_links() (WP5)
	// =========================================================================

	public function test_substitute_boost_links_replaces_placeholder_with_a_real_link_for_a_real_artist(): void {
		$result = InteractionGateway::substitute_boost_links( 'before {{AGNOSIS_BOOST:42}} after', 7 );

		$this->assertStringNotContainsString( '{{AGNOSIS_BOOST:', $result );
		$this->assertStringContainsString( '<a href=', $result );

		$query = $this->query_from_link( $result );
		$this->assertSame( '42', $query['post'] ?? null );
		$this->assertSame( '7', $query['artist'] ?? null );
		$this->assertSame( 'boost', $query['do'] ?? null );
	}

	public function test_substitute_boost_links_strips_placeholder_for_a_zero_artist_id(): void {
		// Defensive path — a boost placeholder is only ever embedded in the
		// artist digest (Digest::build_artist()), whose recipients always
		// have a real id, so this guards against a link that could only
		// ever fail server-side rather than emit a dead one.
		$result = InteractionGateway::substitute_boost_links( 'x {{AGNOSIS_BOOST:5}} y', 0 );

		$this->assertStringNotContainsString( '{{AGNOSIS_BOOST:', $result );
		$this->assertStringNotContainsString( '<a ', $result );
		$this->assertStringContainsString( 'x', $result );
		$this->assertStringContainsString( 'y', $result );
	}

	public function test_inert_strips_the_boost_placeholder_too(): void {
		$result = InteractionGateway::inert( 'x {{AGNOSIS_BOOST:5}} y' );

		$this->assertStringNotContainsString( '{{AGNOSIS_BOOST:', $result );
		$this->assertStringNotContainsString( '<a ', $result );
	}

	// =========================================================================
	// token() / verify() — Reflection-exposed private static methods
	// =========================================================================

	public function test_token_from_substitute_links_passes_verify(): void {
		$result = InteractionGateway::substitute_links( '{{AGNOSIS_LIKE:15}}', 3 );
		$query  = $this->query_from_link( $result );

		$this->assertTrue(
			$this->call_verify( (int) $query['post'], (int) $query['artist'], $query['do'], (int) $query['expires'], $query['token'] )
		);
	}

	public function test_verify_rejects_a_tampered_token(): void {
		$expires = time() + 100;
		$token   = $this->call_token( 1, 0, 'like', $expires );
		$flipped = substr( $token, 0, -1 ) . ( $token[-1] === 'a' ? 'b' : 'a' );

		$this->assertFalse( $this->call_verify( 1, 0, 'like', $expires, $flipped ) );
	}

	public function test_verify_rejects_an_expired_token(): void {
		$expires = time() - 10;
		$token   = $this->call_token( 1, 0, 'like', $expires );

		$this->assertFalse( $this->call_verify( 1, 0, 'like', $expires, $token ) );
	}

	public function test_verify_rejects_a_mismatched_post_id(): void {
		$expires = time() + 100;
		$token   = $this->call_token( 1, 0, 'like', $expires );

		$this->assertFalse( $this->call_verify( 2, 0, 'like', $expires, $token ) );
	}

	public function test_verify_rejects_a_mismatched_artist_id(): void {
		$expires = time() + 100;
		$token   = $this->call_token( 1, 5, 'like', $expires );

		$this->assertFalse( $this->call_verify( 1, 6, 'like', $expires, $token ) );
	}

	// =========================================================================
	// handle_confirm() — no-op guard
	// =========================================================================

	public function test_handle_confirm_is_noop_when_query_key_absent(): void {
		$this->simulate_get( [] );

		// No exception expected — handle_confirm() must return quietly.
		$this->gateway->handle_confirm();
		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// handle_confirm() — GET renders the confirm interstitial, no state change (§7a)
	// =========================================================================

	public function test_handle_confirm_get_renders_interstitial_without_recording_a_like(): void {
		$post_id = $this->make_artwork();
		$expires = time() + DAY_IN_SECONDS;
		$token   = $this->call_token( $post_id, 0, 'like', $expires );

		$this->simulate_get( [
			'agnosis_interaction' => '1',
			'post'                => (string) $post_id,
			'artist'              => '0',
			'do'                  => 'like',
			'expires'             => (string) $expires,
			'token'               => $token,
		] );

		try {
			$this->gateway->handle_confirm();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Like', $e->body );
		}

		$this->assertSame( 0, $this->like_row_count( $post_id ), 'GET alone must never record a like — only the confirm POST may.' );
	}

	// =========================================================================
	// handle_confirm() — POST records the like (§7a)
	// =========================================================================

	public function test_handle_confirm_post_records_like_for_an_anonymous_visitor(): void {
		$post_id = $this->make_artwork();
		$expires = time() + DAY_IN_SECONDS;
		$token   = $this->call_token( $post_id, 0, 'like', $expires );

		$this->simulate_visitor( '203.0.113.80', 'GatewayTest/1.0' );
		$this->simulate_post( [
			'agnosis_interaction' => '1',
			'post'                => (string) $post_id,
			'artist'              => '0',
			'do'                  => 'like',
			'expires'             => (string) $expires,
			'token'               => $token,
		] );

		try {
			$this->gateway->handle_confirm();
			$this->fail( 'Expected a redirect to the result page.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_interaction_result=liked', $e->url );
		}

		$this->assertSame( 1, $this->like_row_count( $post_id ) );
	}

	public function test_handle_confirm_post_records_like_under_the_artists_own_actor_url(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->make_artwork();
		$expires   = time() + DAY_IN_SECONDS;
		$token     = $this->call_token( $post_id, $artist_id, 'like', $expires );

		$this->simulate_post( [
			'agnosis_interaction' => '1',
			'post'                => (string) $post_id,
			'artist'              => (string) $artist_id,
			'do'                  => 'like',
			'expires'             => (string) $expires,
			'token'               => $token,
		] );

		try {
			$this->gateway->handle_confirm();
			$this->fail( 'Expected a redirect to the result page.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_interaction_result=liked', $e->url );
		}

		$expected_actor = ( new ActivityPub() )->actor_url_for( 'artist', $artist_id );
		$this->assertSame( $expected_actor, $this->stored_actor_id( $post_id ) );
	}

	// =========================================================================
	// handle_confirm() — invalid input paths
	// =========================================================================

	public function test_handle_confirm_redirects_home_for_a_disallowed_action(): void {
		$post_id = $this->make_artwork();

		// 'delete' has never been, and never will be, a real action here —
		// this guard runs before token verification, so the token value
		// doesn't matter.
		$this->simulate_get( [
			'agnosis_interaction' => '1',
			'post'                => (string) $post_id,
			'artist'              => '0',
			'do'                  => 'delete',
			'expires'             => (string) ( time() + 100 ),
			'token'               => 'irrelevant',
		] );

		try {
			$this->gateway->handle_confirm();
			$this->fail( 'Expected a redirect home.' );
		} catch ( RedirectCapture $e ) {
			$this->assertSame( untrailingslashit( home_url( '/' ) ), untrailingslashit( $e->url ) );
		}

		$this->assertSame( 0, $this->like_row_count( $post_id ) );
	}

	public function test_handle_confirm_rejects_a_tampered_token_and_records_nothing(): void {
		$post_id = $this->make_artwork();

		$this->simulate_get( [
			'agnosis_interaction' => '1',
			'post'                => (string) $post_id,
			'artist'              => '0',
			'do'                  => 'like',
			'expires'             => (string) ( time() + DAY_IN_SECONDS ),
			'token'               => 'not-the-real-token',
		] );

		try {
			$this->gateway->handle_confirm();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}

		$this->assertSame( 0, $this->like_row_count( $post_id ) );
	}

	public function test_handle_confirm_rejects_a_nonexistent_artwork(): void {
		$missing_post_id = 999999999;
		$expires         = time() + DAY_IN_SECONDS;
		$token           = $this->call_token( $missing_post_id, 0, 'like', $expires );

		$this->simulate_get( [
			'agnosis_interaction' => '1',
			'post'                => (string) $missing_post_id,
			'artist'              => '0',
			'do'                  => 'like',
			'expires'             => (string) $expires,
			'token'               => $token,
		] );

		try {
			$this->gateway->handle_confirm();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
			$this->assertStringContainsString( 'could not be found', $e->body );
		}
	}

	// =========================================================================
	// handle_confirm() — boost (WP5)
	// =========================================================================

	public function test_handle_confirm_get_renders_boost_interstitial_without_recording_a_boost(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->make_artwork();
		$expires   = time() + DAY_IN_SECONDS;
		$token     = $this->call_token( $post_id, $artist_id, 'boost', $expires );

		$this->simulate_get( [
			'agnosis_interaction' => '1',
			'post'                => (string) $post_id,
			'artist'              => (string) $artist_id,
			'do'                  => 'boost',
			'expires'             => (string) $expires,
			'token'               => $token,
		] );

		try {
			$this->gateway->handle_confirm();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Boost', $e->body );
		}

		$this->assertSame( 0, $this->boost_row_count( $post_id ), 'GET alone must never record a boost — only the confirm POST may.' );
	}

	public function test_handle_confirm_post_records_boost_under_the_artists_own_actor_url(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->make_artwork();
		$expires   = time() + DAY_IN_SECONDS;
		$token     = $this->call_token( $post_id, $artist_id, 'boost', $expires );

		$this->simulate_post( [
			'agnosis_interaction' => '1',
			'post'                => (string) $post_id,
			'artist'              => (string) $artist_id,
			'do'                  => 'boost',
			'expires'             => (string) $expires,
			'token'               => $token,
		] );

		try {
			$this->gateway->handle_confirm();
			$this->fail( 'Expected a redirect to the result page.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_interaction_result=boosted', $e->url );
		}

		$this->assertSame( 1, $this->boost_row_count( $post_id ) );
		$expected_actor = ( new ActivityPub() )->actor_url_for( 'artist', $artist_id );
		$this->assertSame( $expected_actor, $this->stored_boost_actor_id( $post_id ) );
	}

	public function test_handle_confirm_post_self_boost_is_permitted(): void {
		$artist_id = $this->create_artist();
		// @phpstan-ignore-next-line -- factory()->post->create() returns int|WP_Error; cast to int so every downstream use below (call_token(), boost_row_count()) sees a concrete int rather than re-triggering the same union on each call site — same pattern this file's own make_artwork() already uses.
		$post_id = (int) self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist_id,
		] );
		$expires = time() + DAY_IN_SECONDS;
		$token   = $this->call_token( $post_id, $artist_id, 'boost', $expires );

		$this->simulate_post( [
			'agnosis_interaction' => '1',
			'post'                => (string) $post_id,
			'artist'              => (string) $artist_id,
			'do'                  => 'boost',
			'expires'             => (string) $expires,
			'token'               => $token,
		] );

		try {
			$this->gateway->handle_confirm();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 ); // Expected exit path — the real assertion is below.
		}

		$this->assertSame( 1, $this->boost_row_count( $post_id ), 'An artist boosting their own artwork must be permitted (§4 Phase 3E step 1).' );
	}

	public function test_handle_confirm_rejects_a_boost_with_no_real_artist_id(): void {
		$post_id = $this->make_artwork();
		$expires = time() + DAY_IN_SECONDS;
		// artist_id = 0 with do=boost should never legitimately occur (the
		// audience rule means only the artist digest ever offers this
		// action) but must fail cleanly if it's ever crafted or corrupted,
		// rather than silently falling through to like_identity()'s
		// anonymous-visitor resolution.
		$token = $this->call_token( $post_id, 0, 'boost', $expires );

		$this->simulate_get( [
			'agnosis_interaction' => '1',
			'post'                => (string) $post_id,
			'artist'              => '0',
			'do'                  => 'boost',
			'expires'             => (string) $expires,
			'token'               => $token,
		] );

		try {
			$this->gateway->handle_confirm();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}

		$this->assertSame( 0, $this->boost_row_count( $post_id ) );
	}

	// =========================================================================
	// handle_result()
	// =========================================================================

	public function test_handle_result_renders_a_thank_you_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_interaction_result'] = 'liked';

		try {
			$this->gateway->handle_result();
			$this->fail( 'Expected the thank-you page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Liked', $e->body );
		}
	}

	public function test_handle_result_renders_a_boosted_thank_you_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_interaction_result'] = 'boosted';

		try {
			$this->gateway->handle_result();
			$this->fail( 'Expected the thank-you page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Boosted', $e->body );
		}
	}

	public function test_handle_result_is_noop_for_any_other_value(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_interaction_result'] = 'something-else';

		$this->gateway->handle_result();
		$this->addToAssertionCount( 1 );
	}
}
