<?php
/**
 * Integration tests — Artist\NotificationPreferences (security audit §5b/§4a).
 *
 * Mirrors VouchConfirmTest's DieCapture-based approach for exercising a
 * template_redirect/wp_die() handler end-to-end. Unlike VouchConfirm's GET
 * (render a confirm button) vs. POST (act) split, NotificationPreferences'
 * GET already renders the real settings form pre-filled with current values
 * — see class docblock for why that's safe against a mail-scanner prefetch —
 * so this suite also asserts the GET-rendered form reflects current state.
 *
 * Covers:
 *   - handle() no-op guard when agnosis_prefs is absent.
 *   - Invalid/tampered/empty token → error page, no side effects.
 *   - Valid token for a non-existent or non-artist user → error page.
 *   - GET renders the form, pre-filled with current preference state.
 *   - POST saves both preferences (mute_broadcasts + vote_mode) as user meta.
 *   - Omitting mute_broadcasts on POST clears the mute (checkbox unchecked).
 *   - An invalid vote_mode value falls back to 'instant' (meta cleared).
 *   - prefs_url() produces a token that round-trips through handle().
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\NotificationPreferences;
use Agnosis\Tests\Integration\Support\DieCapture;

class NotificationPreferencesTest extends \WP_UnitTestCase {

	private NotificationPreferences $prefs;

	protected function setUp(): void {
		parent::setUp();
		$this->prefs = new NotificationPreferences();

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
		unset( $_GET['agnosis_prefs'], $_GET['artist'], $_GET['token'], $_GET['mute_broadcasts'], $_GET['vote_mode'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_POST['agnosis_prefs'], $_POST['artist'], $_POST['token'], $_POST['mute_broadcasts'], $_POST['vote_mode'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_artist( string $email ): int {
		$id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => $email ] );
		get_userdata( $id )->add_role( 'agnosis_artist' );
		return $id;
	}

	private function valid_token( int $artist_id ): string {
		$url    = NotificationPreferences::prefs_url( $artist_id );
		$parsed = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );
		return $parsed['token'];
	}

	// =========================================================================
	// handle() — no-op guard
	// =========================================================================

	public function test_handle_is_noop_when_agnosis_prefs_absent(): void {
		unset( $_GET['agnosis_prefs'] );

		// Must return quietly — no wp_die(), no exception.
		$this->prefs->handle();
		$this->assertTrue( true );
	}

	// =========================================================================
	// handle() — token validation
	// =========================================================================

	public function test_handle_rejects_tampered_token(): void {
		$artist = $this->create_artist( 'tampered-prefs@example.com' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_prefs'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['artist']        = (string) $artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']         = 'not-the-real-token'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->prefs->handle();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
			$this->assertStringContainsString( 'tampered', $e->body );
		}
	}

	public function test_handle_rejects_empty_token(): void {
		$artist = $this->create_artist( 'empty-token@example.com' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_prefs'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['artist']        = (string) $artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']         = ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->prefs->handle();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}

	public function test_handle_rejects_valid_token_for_nonexistent_user(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_prefs'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['artist']        = '999999'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']         = $this->valid_token( 999999 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->prefs->handle();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}

	public function test_handle_rejects_valid_token_for_non_artist_user(): void {
		$non_artist = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'nonartist-prefs@example.com' ] );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_prefs'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['artist']        = (string) $non_artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']         = $this->valid_token( $non_artist ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->prefs->handle();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
			$this->assertStringContainsString( 'no longer valid', $e->body );
		}
	}

	// =========================================================================
	// handle() — GET renders the current-settings form
	// =========================================================================

	public function test_get_renders_form_with_defaults_unmuted_instant(): void {
		$artist = $this->create_artist( 'defaults@example.com' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_prefs'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['artist']        = (string) $artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']         = $this->valid_token( $artist ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->prefs->handle();
			$this->fail( 'Expected the form page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Notification preferences', $e->body );
		}
	}

	public function test_get_reflects_previously_saved_mute_and_digest_state(): void {
		$artist = $this->create_artist( 'previously-saved@example.com' );
		update_user_meta( $artist, '_agnosis_broadcast_optout', '1' );
		update_user_meta( $artist, '_agnosis_vote_email_mode', 'digest' );

		// setUp()'s interceptor runs $message through wp_strip_all_tags(), which
		// discards the checked/unchecked attribute this test needs to inspect.
		// wp_die() picks its handler from 'wp_die_handler' or 'wp_die_ajax_handler'
		// depending on request context (wp_doing_ajax()) — since this suite
		// can't rely on which one actually fires in the PHPUnit harness (only
		// this one test cares about raw markup; every other test here only
		// asserts plain-word substrings that survive stripping either way),
		// replace both outright with a raw-HTML variant scoped to this test.
		$raw_interceptor = static function (): callable {
			return static function ( string|\WP_Error $message, string $title = '', array $args = [] ): never {
				$http_status = (int) ( $args['response'] ?? 200 );
				$title_str   = is_string( $title ) ? $title : '';
				$msg_str     = is_string( $message ) ? $message : (string) $message->get_error_message();
				throw new DieCapture( $msg_str, $title_str, $http_status );
			};
		};
		remove_all_filters( 'wp_die_handler' );
		remove_all_filters( 'wp_die_ajax_handler' );
		add_filter( 'wp_die_handler', $raw_interceptor );
		add_filter( 'wp_die_ajax_handler', $raw_interceptor );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_prefs'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['artist']        = (string) $artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']         = $this->valid_token( $artist ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->prefs->handle();
		} catch ( DieCapture $e ) {
			$raw_html = $e->body;
		}

		// WP's checked() returns a leading-space-prefixed " checked='checked'"
		// (see __checked_selected_helper()), which stacks with render_form()'s
		// own literal space before the placeholder — hence the double space
		// below; this is real, correctly-escaped markup, not a typo.
		$this->assertStringContainsString( 'name="mute_broadcasts" value="1"  checked=\'checked\'', $raw_html );
		$this->assertStringContainsString( 'name="vote_mode" value="digest"  checked=\'checked\'', $raw_html );
	}

	// =========================================================================
	// handle() — POST saves preferences
	// =========================================================================

	public function test_post_saves_mute_and_digest_mode(): void {
		$artist = $this->create_artist( 'save-mute-digest@example.com' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['agnosis_prefs']    = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['artist']           = (string) $artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']            = $this->valid_token( $artist ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['mute_broadcasts']  = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['vote_mode']        = 'digest'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->prefs->handle();
			$this->fail( 'Expected the saved page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}

		$this->assertSame( '1', get_user_meta( $artist, '_agnosis_broadcast_optout', true ) );
		$this->assertSame( 'digest', get_user_meta( $artist, '_agnosis_vote_email_mode', true ) );
	}

	public function test_post_omitting_mute_broadcasts_clears_the_mute(): void {
		$artist = $this->create_artist( 'unmute@example.com' );
		update_user_meta( $artist, '_agnosis_broadcast_optout', '1' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['agnosis_prefs'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['artist']        = (string) $artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']         = $this->valid_token( $artist ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// mute_broadcasts deliberately absent — an unchecked checkbox submits nothing.
		$_POST['vote_mode'] = 'instant'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->prefs->handle();
		} catch ( DieCapture $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- expected: handle() always wp_die()s on a successful save, nothing to assert on the exception itself here.
		}

		$this->assertSame( '', get_user_meta( $artist, '_agnosis_broadcast_optout', true ) );
	}

	public function test_post_instant_vote_mode_clears_the_digest_meta(): void {
		$artist = $this->create_artist( 'back-to-instant@example.com' );
		update_user_meta( $artist, '_agnosis_vote_email_mode', 'digest' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['agnosis_prefs'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['artist']        = (string) $artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']         = $this->valid_token( $artist ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['vote_mode']     = 'instant'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->prefs->handle();
		} catch ( DieCapture $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- expected: handle() always wp_die()s on a successful save, nothing to assert on the exception itself here.
		}

		$this->assertSame( '', get_user_meta( $artist, '_agnosis_vote_email_mode', true ), "'instant' is the implicit default and must not be stored as a meta row." );
	}

	public function test_post_invalid_vote_mode_falls_back_to_instant(): void {
		$artist = $this->create_artist( 'invalid-mode@example.com' );
		update_user_meta( $artist, '_agnosis_vote_email_mode', 'digest' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['agnosis_prefs'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['artist']        = (string) $artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']         = $this->valid_token( $artist ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['vote_mode']     = 'never-ever'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- not a real option; audit explicitly rules out an "off" mode.

		try {
			$this->prefs->handle();
		} catch ( DieCapture $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- expected: handle() always wp_die()s on a successful save, nothing to assert on the exception itself here.
		}

		$this->assertSame( '', get_user_meta( $artist, '_agnosis_vote_email_mode', true ) );
	}

	// =========================================================================
	// prefs_url() round-trip
	// =========================================================================

	public function test_prefs_url_token_round_trips_through_handle(): void {
		$artist = $this->create_artist( 'roundtrip@example.com' );
		$url    = NotificationPreferences::prefs_url( $artist );

		$parsed = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_prefs'] = $parsed['agnosis_prefs']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['artist']        = $parsed['artist']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']         = $parsed['token']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->prefs->handle();
			$this->fail( 'Expected the form page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status, 'A token produced by prefs_url() must pass handle()\'s own verification.' );
		}
	}
}
