<?php
/**
 * Integration tests for Publishing\ReviewConfirm.
 *
 * ReviewConfirm is the security boundary between the one-click email links
 * artists receive and the REST endpoints that change post state.  It runs on
 * 'template_redirect', reads query params, calls rest_do_request() internally
 * (token in POST body, never in a logged URL), then redirects to a clean URL.
 *
 * Testing strategy
 * ────────────────
 * wp_safe_redirect() calls exit, so we intercept it with the 'wp_redirect'
 * filter and throw a RedirectCapture exception before exit fires.  This lets
 * us assert on the destination URL without killing the test process.
 *
 * Similarly, wp_die() is intercepted via 'wp_die_handler' to capture the
 * message and status code instead of outputting HTML.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\ReviewConfirm;
use Agnosis\Tests\Integration\Support\DieCapture;
use Agnosis\Tests\Integration\Support\RedirectCapture;

class ReviewConfirmIntegrationTest extends \WP_UnitTestCase {

	private ReviewConfirm $confirm;

	/** @var int Artist WP user ID */
	private int $artist_id;

	/** @var int Draft artwork post ID */
	private int $post_id;

	private const VALID_TOKEN = 'integ-test-token-abc123456789';

	// -------------------------------------------------------------------------
	// Set-up / tear-down
	// -------------------------------------------------------------------------

	protected function setUp(): void {
		parent::setUp();

		$this->confirm   = new ReviewConfirm();
		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->post_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'draft',
			'post_title'  => 'Shim Test Artwork',
			'post_author' => $this->artist_id,
		] );

		update_post_meta( $this->post_id, '_agnosis_review_token',  self::VALID_TOKEN );
		update_post_meta( $this->post_id, '_agnosis_review_expiry', time() + 86400 * 7 );

		// Intercept wp_safe_redirect() — throw instead of calling exit.
		add_filter(
			'wp_redirect',
			static function ( string $url, int $status ): never {
				throw new RedirectCapture( $url, $status );
			},
			10,
			2
		);

		// Intercept wp_die() — throw instead of outputting HTML.
		// Both filters are hooked because wp_die() picks the handler based on the
		// DOING_AJAX constant: if it is defined (e.g. by a preceding test class),
		// it uses wp_die_ajax_handler; otherwise wp_die_handler.
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
		unset( $_GET['agnosis_review'], $_GET['id'], $_GET['action'], $_GET['token'], $_GET['agnosis_result'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — guard clauses
	// -------------------------------------------------------------------------

	/**
	 * When agnosis_review is absent the method must return immediately without
	 * redirecting — no RedirectCapture should be thrown.
	 */
	public function test_handle_confirm_is_a_no_op_without_agnosis_review_param(): void {
		$this->confirm->handle_confirm();
		$this->addToAssertionCount( 1 ); // reached this line = no redirect fired.
	}

	public function test_handle_confirm_redirects_to_home_on_missing_id(): void {
		$_GET['agnosis_review'] = '1';
		$_GET['action']         = 'approve';
		$_GET['token']          = self::VALID_TOKEN;

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			// Guard-clause bail-out: no result param, goes straight home.
			$this->assertStringNotContainsString( 'agnosis_result', $e->url );
			$this->assertStringContainsString( home_url( '/' ), $e->url );
		}
	}

	public function test_handle_confirm_redirects_to_home_on_invalid_action(): void {
		$_GET['agnosis_review'] = '1';
		$_GET['id']             = (string) $this->post_id;
		$_GET['action']         = 'delete'; // not in the allowed list.
		$_GET['token']          = self::VALID_TOKEN;

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringNotContainsString( 'agnosis_result', $e->url );
		}
	}

	public function test_handle_confirm_redirects_to_home_on_missing_token(): void {
		$_GET['agnosis_review'] = '1';
		$_GET['id']             = (string) $this->post_id;
		$_GET['action']         = 'approve';

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringNotContainsString( 'agnosis_result', $e->url );
		}
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — approve happy path
	// -------------------------------------------------------------------------

	public function test_handle_confirm_approve_redirects_to_clean_url(): void {
		$_GET['agnosis_review'] = '1';
		$_GET['id']             = (string) $this->post_id;
		$_GET['action']         = 'approve';
		$_GET['token']          = self::VALID_TOKEN;

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=approve', $e->url );
			$this->assertStringNotContainsString( self::VALID_TOKEN, $e->url );
		}
	}

	public function test_handle_confirm_approve_publishes_the_post(): void {
		$_GET['agnosis_review'] = '1';
		$_GET['id']             = (string) $this->post_id;
		$_GET['action']         = 'approve';
		$_GET['token']          = self::VALID_TOKEN;

		try {
			$this->confirm->handle_confirm();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 ); // redirect fired as expected.
		}

		$this->assertSame( 'publish', get_post_status( $this->post_id ) );
	}

	public function test_handle_confirm_approve_consumes_token(): void {
		$_GET['agnosis_review'] = '1';
		$_GET['id']             = (string) $this->post_id;
		$_GET['action']         = 'approve';
		$_GET['token']          = self::VALID_TOKEN;

		try {
			$this->confirm->handle_confirm();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 ); // redirect fired as expected.
		}

		$this->assertEmpty( get_post_meta( $this->post_id, '_agnosis_review_token', true ) );
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — reject
	// -------------------------------------------------------------------------

	public function test_handle_confirm_reject_redirects_to_clean_url(): void {
		$_GET['agnosis_review'] = '1';
		$_GET['id']             = (string) $this->post_id;
		$_GET['action']         = 'reject';
		$_GET['token']          = self::VALID_TOKEN;

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=reject', $e->url );
			$this->assertStringNotContainsString( self::VALID_TOKEN, $e->url );
		}
	}

	public function test_handle_confirm_reject_trashes_the_post(): void {
		$_GET['agnosis_review'] = '1';
		$_GET['id']             = (string) $this->post_id;
		$_GET['action']         = 'reject';
		$_GET['token']          = self::VALID_TOKEN;

		try {
			$this->confirm->handle_confirm();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 ); // redirect fired as expected.
		}

		$this->assertSame( 'trash', get_post_status( $this->post_id ) );
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — remove
	// -------------------------------------------------------------------------

	public function test_handle_confirm_remove_routes_to_removal_endpoint(): void {
		$removal_token = 'removal-test-token-xyz987';
		update_post_meta( $this->post_id, '_agnosis_removal_token',  $removal_token );
		update_post_meta( $this->post_id, '_agnosis_removal_expiry', time() + 86400 );

		// Removal endpoint acts on published posts.
		wp_update_post( [ 'ID' => $this->post_id, 'post_status' => 'publish' ] );

		$_GET['agnosis_review'] = '1';
		$_GET['id']             = (string) $this->post_id;
		$_GET['action']         = 'remove';
		$_GET['token']          = $removal_token;

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=remove', $e->url );
		}

		$this->assertSame( 'trash', get_post_status( $this->post_id ) );
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — invalid / expired token
	// -------------------------------------------------------------------------

	public function test_handle_confirm_invalid_token_redirects_to_error(): void {
		$_GET['agnosis_review'] = '1';
		$_GET['id']             = (string) $this->post_id;
		$_GET['action']         = 'approve';
		$_GET['token']          = 'completely-wrong-token';

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=error', $e->url );
		}

		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
	}

	public function test_handle_confirm_expired_token_redirects_to_error(): void {
		update_post_meta( $this->post_id, '_agnosis_review_expiry', time() - 1 );

		$_GET['agnosis_review'] = '1';
		$_GET['id']             = (string) $this->post_id;
		$_GET['action']         = 'approve';
		$_GET['token']          = self::VALID_TOKEN;

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=error', $e->url );
		}

		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
	}

	// -------------------------------------------------------------------------
	// handle_result()
	// -------------------------------------------------------------------------

	public function test_handle_result_is_a_no_op_without_agnosis_result_param(): void {
		$this->confirm->handle_result();
		$this->addToAssertionCount( 1 ); // reached this line = no wp_die fired.
	}

	public function test_handle_result_approve_returns_200(): void {
		$_GET['agnosis_result'] = 'approve';

		try {
			$this->confirm->handle_result();
			$this->fail( 'Expected wp_die.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}
	}

	public function test_handle_result_error_returns_400(): void {
		$_GET['agnosis_result'] = 'error';

		try {
			$this->confirm->handle_result();
			$this->fail( 'Expected wp_die.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}

	public function test_handle_result_unknown_key_falls_back_to_error_page(): void {
		$_GET['agnosis_result'] = 'bogus';

		try {
			$this->confirm->handle_result();
			$this->fail( 'Expected wp_die.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}

	public function test_handle_result_reject_returns_200(): void {
		$_GET['agnosis_result'] = 'reject';

		try {
			$this->confirm->handle_result();
			$this->fail( 'Expected wp_die.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}
	}
}
