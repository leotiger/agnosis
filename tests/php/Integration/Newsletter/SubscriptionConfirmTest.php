<?php
/**
 * Integration tests — SubscriptionConfirm template_redirect shim.
 *
 * Since the §2a fix (mail-security scanners prefetching action links), a
 * plain GET only renders a confirm interstitial — it no longer confirms or
 * unsubscribes anything. The action is only taken on POST. wp_die() is
 * intercepted via the 'wp_die_handler' filter (thrown as DieCapture) so both
 * paths can be exercised end-to-end without killing the test process. The
 * routing logic it dispatches to (Subscriber::confirm()/unsubscribe(),
 * Tokens::verify_artist_unsubscribe_token()) has its own full coverage in
 * SubscriberTest and TokensTest.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\SubscriptionConfirm;
use Agnosis\Newsletter\Subscriber;
use Agnosis\Newsletter\Tokens;
use Agnosis\Tests\Integration\Support\DieCapture;

class SubscriptionConfirmTest extends \WP_UnitTestCase {

	private SubscriptionConfirm $confirm;

	protected function setUp(): void {
		parent::setUp();
		$this->confirm = new SubscriptionConfirm();

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
		unset( $_GET['agnosis_newsletter'], $_GET['action'], $_GET['type'], $_GET['token'], $_GET['uid'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_POST['agnosis_newsletter'], $_POST['action'], $_POST['type'], $_POST['token'], $_POST['uid'], $_POST['List-Unsubscribe'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_SERVER['REQUEST_METHOD'] );

		parent::tearDown();
	}

	public function test_handle_is_noop_when_agnosis_newsletter_absent(): void {
		global $wpdb;

		unset( $_GET['agnosis_newsletter'] );

		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_subscribers" );

		// Must return early (no exit) when the query var is absent.
		$this->confirm->handle();

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_subscribers" );

		$this->assertSame( $before, $after, 'handle() must not write to the DB when agnosis_newsletter is absent.' );
	}

	public function test_register_hooks_adds_template_redirect_action(): void {
		remove_all_actions( 'template_redirect' );

		$this->confirm->register_hooks();

		$this->assertGreaterThan( 0, has_action( 'template_redirect', [ $this->confirm, 'handle' ] ) );
	}

	// =========================================================================
	// handle() — GET renders the confirm interstitial, does not act (§2a)
	// =========================================================================

	public function test_handle_get_confirm_renders_interstitial_without_confirming(): void {
		$sub   = Subscriber::subscribe( 'pending-get@example.com' );
		$token = $sub['token'];

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_newsletter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['action']             = 'confirm'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['type']               = 'public'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']              = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Confirm your subscription', $e->body );
		}

		$row = Subscriber::find_by_token( $token );
		$this->assertSame( 'pending', $row['status'], 'GET alone must never confirm the subscription.' );
	}

	public function test_handle_post_confirm_confirms_subscription(): void {
		$sub   = Subscriber::subscribe( 'pending-post@example.com' );
		$token = $sub['token'];

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['agnosis_newsletter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['action']             = 'confirm'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['type']               = 'public'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']              = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the success page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}

		$row = Subscriber::find_by_token( $token );
		$this->assertSame( 'confirmed', $row['status'] );
	}

	public function test_handle_get_unsubscribe_public_renders_interstitial_without_unsubscribing(): void {
		$sub = Subscriber::subscribe( 'confirmed-get@example.com' );
		Subscriber::confirm( $sub['token'] );
		$token = $sub['token'];

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_newsletter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['action']             = 'unsubscribe'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['type']               = 'public'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']              = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Unsubscribe', $e->body );
		}

		$row = Subscriber::find_by_token( $token );
		$this->assertSame( 'confirmed', $row['status'], 'GET alone must never unsubscribe.' );
	}

	public function test_handle_post_unsubscribe_public_unsubscribes(): void {
		$sub = Subscriber::subscribe( 'confirmed-post@example.com' );
		Subscriber::confirm( $sub['token'] );
		$token = $sub['token'];

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['agnosis_newsletter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['action']             = 'unsubscribe'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['type']               = 'public'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']              = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the success page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}

		$row = Subscriber::find_by_token( $token );
		$this->assertSame( 'unsubscribed', $row['status'] );
	}

	public function test_handle_get_artist_unsubscribe_renders_interstitial_without_optout(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_userdata( $user_id )->add_role( 'agnosis_artist' );
		$token = Tokens::artist_unsubscribe_token( $user_id );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_newsletter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['action']             = 'unsubscribe'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['type']               = 'artist'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['uid']                = (string) $user_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']              = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Unsubscribe', $e->body );
		}

		$this->assertEmpty( get_user_meta( $user_id, '_agnosis_newsletter_optout', true ), 'GET alone must never opt an artist out.' );
	}

	public function test_handle_post_artist_unsubscribe_sets_optout(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_userdata( $user_id )->add_role( 'agnosis_artist' );
		$token = Tokens::artist_unsubscribe_token( $user_id );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['agnosis_newsletter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['action']             = 'unsubscribe'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['type']               = 'artist'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['uid']                = (string) $user_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']              = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the success page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}

		$this->assertSame( '1', get_user_meta( $user_id, '_agnosis_newsletter_optout', true ) );
	}

	// =========================================================================
	// RFC 8058 one-click unsubscribe (§2b) — bare POST, params from the query
	// string, no confirm interstitial.
	// =========================================================================

	public function test_one_click_post_unsubscribes_public_immediately_without_interstitial(): void {
		$sub = Subscriber::subscribe( 'one-click-public@example.com' );
		Subscriber::confirm( $sub['token'] );
		$token = $sub['token'];

		// The mail client POSTs to the exact URL from the List-Unsubscribe
		// header — action/type/token travel in the query string, not the body.
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_GET['agnosis_newsletter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['action']             = 'unsubscribe'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['type']               = 'public'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']              = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// The POST body carries only the RFC 8058 marker — no hidden form fields.
		$_POST['List-Unsubscribe'] = 'One-Click'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the success page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}

		$row = Subscriber::find_by_token( $token );
		$this->assertSame( 'unsubscribed', $row['status'], 'A one-click POST must act immediately, with no confirm step.' );
	}

	public function test_one_click_post_unsubscribes_artist_immediately(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_userdata( $user_id )->add_role( 'agnosis_artist' );
		$token = Tokens::artist_unsubscribe_token( $user_id );

		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_GET['agnosis_newsletter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['action']             = 'unsubscribe'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['type']               = 'artist'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['uid']                = (string) $user_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']              = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['List-Unsubscribe']  = 'One-Click'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the success page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}

		$this->assertSame( '1', get_user_meta( $user_id, '_agnosis_newsletter_optout', true ) );
	}

	public function test_one_click_post_with_tampered_token_renders_error_and_does_not_unsubscribe(): void {
		$sub = Subscriber::subscribe( 'one-click-tampered@example.com' );
		Subscriber::confirm( $sub['token'] );

		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_GET['agnosis_newsletter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['action']             = 'unsubscribe'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['type']               = 'public'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']              = 'not-a-real-token'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['List-Unsubscribe']  = 'One-Click'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}

		$row = Subscriber::find_by_token( $sub['token'] );
		$this->assertSame( 'confirmed', $row['status'], 'A tampered one-click token must not unsubscribe anyone.' );
	}

	public function test_our_own_confirm_form_post_is_not_mistaken_for_one_click(): void {
		// Our own confirm-page form POSTs agnosis_newsletter/action/type/token as
		// body fields and never sets List-Unsubscribe=One-Click — must still take
		// the normal POST path (handle_public()), not the one-click short-circuit.
		$sub = Subscriber::subscribe( 'not-one-click@example.com' );
		Subscriber::confirm( $sub['token'] );
		$token = $sub['token'];

		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST['agnosis_newsletter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['action']             = 'unsubscribe'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['type']               = 'public'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']              = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the success page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}

		$row = Subscriber::find_by_token( $token );
		$this->assertSame( 'unsubscribed', $row['status'] );
	}

	public function test_handle_get_artist_confirm_renders_error_immediately(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$token   = Tokens::artist_unsubscribe_token( $user_id );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_newsletter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['action']             = 'confirm'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['type']               = 'artist'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['uid']                = (string) $user_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']              = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			// "Nothing to confirm" is a guard clause, not an interstitial —
			// fires identically on GET and POST.
			$this->assertStringContainsString( 'automatically subscribed', $e->body );
		}
	}
}
