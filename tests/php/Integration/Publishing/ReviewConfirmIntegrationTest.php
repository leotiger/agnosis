<?php
/**
 * Integration tests for Publishing\ReviewConfirm.
 *
 * ReviewConfirm is the security boundary between the one-click email links
 * artists receive and the REST endpoints that change post state.  It runs on
 * 'template_redirect', reads query/post params, calls rest_do_request()
 * internally (token in POST body, never in a logged URL), then redirects to a
 * clean URL.
 *
 * Since the §2a fix (mail-security scanners prefetching action links), a
 * plain GET no longer executes anything: it renders a "confirm" interstitial
 * with a single POST button, and the action is only taken once that POST
 * arrives. This suite therefore exercises both halves — GET renders the
 * interstitial and leaves state untouched, POST performs the action — using
 * $_SERVER['REQUEST_METHOD'] to simulate each.
 *
 * Testing strategy
 * ────────────────
 * wp_safe_redirect() calls exit, so we intercept it with the 'wp_redirect'
 * filter and throw a RedirectCapture exception before exit fires.  This lets
 * us assert on the destination URL without killing the test process.
 *
 * Similarly, wp_die() is intercepted via 'wp_die_handler' to capture the
 * message and status code instead of outputting HTML. Both the confirm
 * interstitial and the result page go through wp_die(), so this same
 * interception covers both.
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
		unset( $_GET['agnosis_review'], $_GET['id'], $_GET['action'], $_GET['token'], $_GET['agnosis_result'], $_GET['agnosis_type'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset(
			$_POST['agnosis_review'], $_POST['id'], $_POST['action'], $_POST['token'],
			$_POST['title'], $_POST['excerpt'], $_POST['body'],
			$_POST['orig_title'], $_POST['orig_excerpt'], $_POST['orig_body']
		); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_SERVER['REQUEST_METHOD'] );

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Simulate the initial GET from the email link. */
	private function simulate_get( array $params ): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		foreach ( $params as $key => $value ) {
			$_GET[ $key ] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/** Simulate the confirm-button POST that actually performs the action. */
	private function simulate_post( array $params ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		foreach ( $params as $key => $value ) {
			$_POST[ $key ] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — guard clauses (identical on GET and POST)
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
		$this->simulate_get( [
			'agnosis_review' => '1',
			'action'         => 'approve',
			'token'          => self::VALID_TOKEN,
		] );

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
		$this->simulate_get( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'delete', // not in the allowed list.
			'token'          => self::VALID_TOKEN,
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringNotContainsString( 'agnosis_result', $e->url );
		}
	}

	public function test_handle_confirm_redirects_to_home_on_missing_token(): void {
		$this->simulate_get( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'approve',
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringNotContainsString( 'agnosis_result', $e->url );
		}
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — GET renders the confirm interstitial, does not act
	// -------------------------------------------------------------------------

	public function test_handle_confirm_get_renders_interstitial_without_acting(): void {
		$this->simulate_get( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'approve',
			'token'          => self::VALID_TOKEN,
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Publish this artwork?', $e->body );
		}

		// GET alone must never publish the post or consume the token — a mail
		// scanner prefetching the link must not trigger any state change.
		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
		$this->assertSame( self::VALID_TOKEN, get_post_meta( $this->post_id, '_agnosis_review_token', true ) );
	}

	public function test_handle_confirm_get_renders_interstitial_for_reject(): void {
		$this->simulate_get( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'reject',
			'token'          => self::VALID_TOKEN,
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Discard this submission?', $e->body );
		}

		$this->assertSame( 'draft', get_post_status( $this->post_id ), 'GET must not discard the submission.' );
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — POST approve happy path
	// -------------------------------------------------------------------------

	/**
	 * Unedited approve POST — title/excerpt/body match orig_* exactly, so this
	 * is the "artist left the form as-is" path: no translation call, routes to
	 * the plain POST /approve REST endpoint exactly as before the editable-form
	 * feature existed.
	 *
	 * @return array<string,string>
	 */
	private function unedited_approve_params(): array {
		return [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'approve',
			'token'          => self::VALID_TOKEN,
			'title'          => 'Shim Test Artwork',
			'excerpt'        => '',
			'body'           => '',
			'orig_title'     => 'Shim Test Artwork',
			'orig_excerpt'   => '',
			'orig_body'      => '',
		];
	}

	public function test_handle_confirm_approve_redirects_to_clean_url(): void {
		$this->simulate_post( $this->unedited_approve_params() );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=approve', $e->url );
			$this->assertStringNotContainsString( self::VALID_TOKEN, $e->url );
		}
	}

	public function test_handle_confirm_approve_publishes_the_post(): void {
		$this->simulate_post( $this->unedited_approve_params() );

		try {
			$this->confirm->handle_confirm();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 ); // redirect fired as expected.
		}

		$this->assertSame( 'publish', get_post_status( $this->post_id ) );
	}

	public function test_handle_confirm_approve_consumes_token(): void {
		$this->simulate_post( $this->unedited_approve_params() );

		try {
			$this->confirm->handle_confirm();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 ); // redirect fired as expected.
		}

		$this->assertEmpty( get_post_meta( $this->post_id, '_agnosis_review_token', true ) );
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — POST approve WITH final text edits
	// -------------------------------------------------------------------------

	public function test_handle_confirm_approve_with_edited_text_saves_and_publishes(): void {
		$this->simulate_post( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'approve',
			'token'          => self::VALID_TOKEN,
			'title'          => 'Shim Test Artwork',
			'excerpt'        => 'A short corrected description.',
			'body'           => 'The artist tightened up the wording right before publishing.',
			'orig_title'     => 'Shim Test Artwork',
			'orig_excerpt'   => '',
			'orig_body'      => '',
		] );

		try {
			$this->confirm->handle_confirm();
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=approve', $e->url );
		}

		$post = get_post( $this->post_id );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'A short corrected description.', $post->post_excerpt );
		$this->assertStringContainsString( 'tightened up the wording', $post->post_content );
		$this->assertEmpty( get_post_meta( $this->post_id, '_agnosis_review_token', true ) );
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — POST approve blank-submission safeguard
	// -------------------------------------------------------------------------

	public function test_handle_confirm_approve_blank_body_cancels_without_publishing(): void {
		$this->simulate_post( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'approve',
			'token'          => self::VALID_TOKEN,
			'title'          => 'Shim Test Artwork',
			'excerpt'        => '',
			'body'           => '', // Artist accidentally cleared the body.
			'orig_title'     => 'Shim Test Artwork',
			'orig_excerpt'   => '',
			'orig_body'      => 'Some original body text.',
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected the confirm interstitial to re-render (wp_die), not a redirect.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'cannot be empty', $e->body );
		}

		// Nothing changed: draft stays a draft, and — crucially — the token is
		// NOT consumed, so the same email link still works for a retry.
		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
		$this->assertSame( self::VALID_TOKEN, get_post_meta( $this->post_id, '_agnosis_review_token', true ) );
	}

	public function test_handle_confirm_approve_blank_title_cancels_without_publishing(): void {
		$this->simulate_post( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'approve',
			'token'          => self::VALID_TOKEN,
			'title'          => '   ', // Whitespace-only — must count as blank.
			'excerpt'        => '',
			'body'           => 'Body text is present.',
			'orig_title'     => 'Shim Test Artwork',
			'orig_excerpt'   => '',
			'orig_body'      => 'Body text is present.',
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected the confirm interstitial to re-render (wp_die), not a redirect.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}

		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
		$this->assertSame( self::VALID_TOKEN, get_post_meta( $this->post_id, '_agnosis_review_token', true ) );
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — POST reject
	// -------------------------------------------------------------------------

	public function test_handle_confirm_reject_redirects_to_clean_url(): void {
		$this->simulate_post( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'reject',
			'token'          => self::VALID_TOKEN,
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=reject', $e->url );
			$this->assertStringNotContainsString( self::VALID_TOKEN, $e->url );
		}
	}

	public function test_handle_confirm_reject_trashes_the_post(): void {
		$this->simulate_post( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'reject',
			'token'          => self::VALID_TOKEN,
		] );

		try {
			$this->confirm->handle_confirm();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 ); // redirect fired as expected.
		}

		$this->assertSame( 'trash', get_post_status( $this->post_id ) );
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — POST remove
	// -------------------------------------------------------------------------

	public function test_handle_confirm_remove_routes_to_removal_endpoint(): void {
		$removal_token = 'removal-test-token-xyz987';
		update_post_meta( $this->post_id, '_agnosis_removal_token',  $removal_token );
		update_post_meta( $this->post_id, '_agnosis_removal_expiry', time() + 86400 );

		// Removal endpoint acts on published posts.
		wp_update_post( [ 'ID' => $this->post_id, 'post_status' => 'publish' ] );

		$this->simulate_post( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'remove',
			'token'          => $removal_token,
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=remove', $e->url );
		}

		$this->assertSame( 'trash', get_post_status( $this->post_id ) );
	}

	public function test_handle_confirm_get_remove_renders_interstitial_without_trashing(): void {
		$removal_token = 'removal-test-token-xyz987';
		update_post_meta( $this->post_id, '_agnosis_removal_token',  $removal_token );
		update_post_meta( $this->post_id, '_agnosis_removal_expiry', time() + 86400 );

		wp_update_post( [ 'ID' => $this->post_id, 'post_status' => 'publish' ] );

		$this->simulate_get( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'remove',
			'token'          => $removal_token,
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Remove this artwork?', $e->body );
		}

		$this->assertSame( 'publish', get_post_status( $this->post_id ), 'GET must not remove the artwork.' );
	}

	// -------------------------------------------------------------------------
	// handle_confirm() — POST invalid / expired token
	// -------------------------------------------------------------------------

	public function test_handle_confirm_invalid_token_redirects_to_error(): void {
		$this->simulate_post( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'approve',
			'token'          => 'completely-wrong-token',
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=error', $e->url );
		}

		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
	}

	// -------------------------------------------------------------------------
	// Fourth audit §3a — GET with an invalid/expired token must not render the
	// draft's content and POST with an invalid token must not write meta or
	// spend an AI call before being rejected. Previously the only real token
	// check on the approve path was inside the final rest_do_request()
	// dispatch, well after both of those things could already happen.
	// -------------------------------------------------------------------------

	public function test_handle_confirm_get_approve_with_wrong_token_does_not_render_draft_content(): void {
		// Configure an AI provider + a mismatched artist locale so, pre-fix,
		// get_display_text() would actually attempt a back-translation HTTP call
		// on this GET. The pre_http_request filter fails the test outright if
		// any HTTP call happens at all — proving the "up to two unauthenticated
		// AI calls per prefetch" spend the audit found is closed, not just that
		// the rendered page happens to look different.
		update_option( 'agnosis_openai_api_key', 'test-key-not-real' );
		update_user_meta( $this->artist_id, 'locale', 'es_ES' );

		$http_calls = 0;
		$http_filter = function () use ( &$http_calls ) {
			++$http_calls;
			return new \WP_Error( 'test_unexpected_http_call', 'No AI HTTP call should happen before the token is verified.' );
		};
		add_filter( 'pre_http_request', $http_filter, 10, 3 );

		$this->simulate_get( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'approve',
			'token'          => 'guessed-wrong-token',
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected a redirect to the error page, not the approve form.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=error', $e->url );
		} catch ( DieCapture $e ) {
			$this->fail( 'A bad token must never reach the approve confirm form: ' . $e->body );
		} finally {
			remove_filter( 'pre_http_request', $http_filter, 10 );
			delete_option( 'agnosis_openai_api_key' );
		}

		$this->assertSame( 0, $http_calls, 'A guessed token must be rejected before any AI back-translation call is attempted.' );
		// The draft must remain untouched — this is purely a read-path leak
		// check, not a state-change check, but confirming it stayed a draft is
		// cheap insurance against any accidental side effect.
		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
	}

	public function test_handle_confirm_get_approve_with_expired_token_does_not_render_draft_content(): void {
		update_post_meta( $this->post_id, '_agnosis_review_expiry', time() - 1 );

		$this->simulate_get( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'approve',
			'token'          => self::VALID_TOKEN,
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected a redirect to the error page, not the approve form.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=error', $e->url );
		} catch ( DieCapture $e ) {
			$this->fail( 'An expired token must never reach the approve confirm form: ' . $e->body );
		}
	}

	public function test_handle_confirm_approve_edited_title_with_wrong_token_does_not_spend_ai_call_or_write_translated_title(): void {
		// Configure an AI provider so, pre-fix, the translated-title regeneration
		// branch would actually run and call translate_text() — without a
		// provider configured this branch already no-ops regardless of the
		// token, which would make this test pass for the wrong reason. The
		// pre_http_request filter below fails the test outright if any HTTP
		// call is attempted at all, rather than trying to mock a realistic
		// OpenAI response — the point being proven is that NO call happens
		// before the token is checked, not what happens if one did.
		update_option( 'agnosis_openai_api_key', 'test-key-not-real' );
		update_user_meta( $this->artist_id, 'locale', 'es_ES' );

		$http_calls = 0;
		$http_filter = function () use ( &$http_calls ) {
			++$http_calls;
			return new \WP_Error( 'test_unexpected_http_call', 'No AI HTTP call should happen before the token is verified.' );
		};
		add_filter( 'pre_http_request', $http_filter, 10, 3 );

		$this->simulate_post( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'approve',
			'token'          => 'completely-wrong-token',
			'title'          => 'A New Corrected Title', // differs from orig_title → "edited" branch
			'excerpt'        => '',
			'body'           => 'Body text is present.',
			'orig_title'     => 'Shim Test Artwork',
			'orig_excerpt'   => '',
			'orig_body'      => 'Body text is present.',
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=error', $e->url );
		} finally {
			remove_filter( 'pre_http_request', $http_filter, 10 );
			delete_option( 'agnosis_openai_api_key' );
		}

		$this->assertSame( 0, $http_calls, 'A forged token must be rejected before any AI HTTP call is attempted.' );
		$this->assertSame( 'draft', get_post_status( $this->post_id ) );
		$this->assertEmpty(
			get_post_meta( $this->post_id, '_agnosis_translated_title', true ),
			'A forged token must never trigger the translated-title write — it must be rejected before that branch runs at all.'
		);
	}

	public function test_handle_confirm_expired_token_redirects_to_error(): void {
		update_post_meta( $this->post_id, '_agnosis_review_expiry', time() - 1 );

		$this->simulate_post( [
			'agnosis_review' => '1',
			'id'             => (string) $this->post_id,
			'action'         => 'approve',
			'token'          => self::VALID_TOKEN,
		] );

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
		$_GET['agnosis_result'] = 'approve'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle_result();
			$this->fail( 'Expected wp_die.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}
	}

	public function test_handle_result_error_returns_400(): void {
		$_GET['agnosis_result'] = 'error'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle_result();
			$this->fail( 'Expected wp_die.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}

	public function test_handle_result_unknown_key_falls_back_to_error_page(): void {
		$_GET['agnosis_result'] = 'bogus'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle_result();
			$this->fail( 'Expected wp_die.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}

	public function test_handle_result_reject_returns_200(): void {
		$_GET['agnosis_result'] = 'reject'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle_result();
			$this->fail( 'Expected wp_die.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}
	}

	public function test_handle_result_approve_biography_shows_biography_wording(): void {
		$_GET['agnosis_result'] = 'approve'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['agnosis_type']   = 'agnosis_biography'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle_result();
			$this->fail( 'Expected wp_die.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Biography published', $e->body );
		}
	}

	// -------------------------------------------------------------------------
	// Non-artwork reviewable CPTs (2026-07-08) — the approve confirm form's
	// field set is CPT-aware (ReviewConfirm::APPROVE_FIELDS): biography has no
	// excerpt field, event has neither title nor excerpt editable. These tests
	// exercise that alongside the same ReviewEndpoints artwork-only gate fix
	// covered in ReviewEndpointsIntegrationTest.
	// -------------------------------------------------------------------------

	/** @return int Draft post ID of the given type, with a valid review token. */
	private function create_reviewable_draft( string $post_type, string $token ): int {
		$post_id = (int) wp_insert_post( [
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => 'Shim Test ' . $post_type,
			'post_content' => 'Original body text.', // body is always required — see the safeguard.
			'post_author'  => $this->artist_id,
		] );

		update_post_meta( $post_id, '_agnosis_review_token', $token );
		update_post_meta( $post_id, '_agnosis_review_expiry', time() + 86400 * 7 );

		return $post_id;
	}

	public function test_handle_confirm_get_renders_interstitial_for_biography(): void {
		$token   = 'bio-token-abc123456789';
		$post_id = $this->create_reviewable_draft( 'agnosis_biography', $token );

		$this->simulate_get( [
			'agnosis_review' => '1',
			'id'             => (string) $post_id,
			'action'         => 'approve',
			'token'          => $token,
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Publish this biography?', $e->body );
		}

		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	public function test_handle_confirm_approve_biography_unedited_publishes(): void {
		$token   = 'bio-token-abc123456789';
		$post_id = $this->create_reviewable_draft( 'agnosis_biography', $token );

		// Biography has no excerpt field (APPROVE_FIELDS) — only title/body are
		// part of the diff, so no excerpt/orig_excerpt is sent at all.
		$this->simulate_post( [
			'agnosis_review' => '1',
			'id'             => (string) $post_id,
			'action'         => 'approve',
			'token'          => $token,
			'title'          => 'Shim Test agnosis_biography',
			'body'           => 'Original body text.',
			'orig_title'     => 'Shim Test agnosis_biography',
			'orig_body'      => 'Original body text.',
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'agnosis_result=approve', $e->url );
			$this->assertStringContainsString( 'agnosis_type=agnosis_biography', $e->url );
		}

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	public function test_handle_confirm_approve_event_blank_body_cancels_without_publishing(): void {
		// Event has neither title nor excerpt editable (APPROVE_FIELDS) — the
		// blank-submission safeguard must still fire on body alone.
		$token   = 'event-token-abc123456789';
		$post_id = $this->create_reviewable_draft( 'agnosis_event', $token );

		$this->simulate_post( [
			'agnosis_review' => '1',
			'id'             => (string) $post_id,
			'action'         => 'approve',
			'token'          => $token,
			'body'           => '', // Cleared by mistake — no title field exists for events at all.
			'orig_body'      => 'Some original body text.',
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected the confirm interstitial to re-render (wp_die), not a redirect.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'cannot be empty', $e->body );
		}

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( $token, get_post_meta( $post_id, '_agnosis_review_token', true ) );
	}

	// -------------------------------------------------------------------------
	// Event timezone <select> (audit §2b) — previously a free-text input
	// validated server-side against DateTimeZone::listIdentifiers() but
	// silently discarded when invalid, with no indication to the artist that
	// what they typed didn't stick. Now a <select> of every real identifier.
	// -------------------------------------------------------------------------

	/**
	 * The GET-interstitial wp_die_handler interceptor set up in setUp() runs
	 * wp_strip_all_tags() on the body before capturing it (see the class
	 * docblock above and DeliverabilityTest's identical pattern) — so raw
	 * markup (the `<select>`/`<optgroup>`/`<option selected>` tags themselves)
	 * never survives into $e->body. timezone_options_html() is exercised
	 * directly via reflection below instead, where the real HTML is available;
	 * this GET test only confirms the field is actually wired into the real
	 * approve form.
	 */
	public function test_handle_confirm_get_event_approve_form_includes_the_timezone_field(): void {
		$token   = 'event-tz-token-abc123456789';
		$post_id = $this->create_reviewable_draft( 'agnosis_event', $token );
		update_post_meta( $post_id, '_agnosis_event_timezone', 'Europe/Madrid' );

		$this->simulate_get( [
			'agnosis_review' => '1',
			'id'             => (string) $post_id,
			'action'         => 'approve',
			'token'          => $token,
		] );

		try {
			$this->confirm->handle_confirm();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Timezone', $e->body );
			$this->assertStringContainsString( 'Europe/Madrid', $e->body );
		}
	}

	private function invoke_timezone_options_html( string $selected ): string {
		$ref = new \ReflectionMethod( ReviewConfirm::class, 'timezone_options_html' );
		$ref->setAccessible( true );
		return (string) $ref->invoke( $this->confirm, $selected );
	}

	public function test_timezone_options_html_groups_identifiers_by_region_and_offers_not_set(): void {
		$html = $this->invoke_timezone_options_html( '' );

		$this->assertStringContainsString( '<optgroup label="Europe">', $html );
		$this->assertStringContainsString( '<option value="Europe/Madrid">', $html );
		$this->assertStringContainsString( '— Not set —', $html, 'An unset baseline must offer an explicit "not set" option rather than defaulting to some arbitrary identifier.' );
	}

	public function test_timezone_options_html_marks_the_selected_identifier(): void {
		$html = $this->invoke_timezone_options_html( 'Europe/Madrid' );

		$this->assertStringContainsString( '<option value="Europe/Madrid" selected=\'selected\'>', $html );
		$this->assertStringNotContainsString( '<option value="Asia/Tokyo" selected', $html, 'Only the actually-selected identifier should carry the selected attribute.' );
	}

	public function test_handle_confirm_approve_event_persists_a_valid_selected_timezone(): void {
		$token   = 'event-tz-token-ghi123456789';
		$post_id = $this->create_reviewable_draft( 'agnosis_event', $token );

		$this->simulate_post( [
			'agnosis_review'  => '1',
			'id'              => (string) $post_id,
			'action'          => 'approve',
			'token'           => $token,
			'body'            => 'Original body text.',
			'orig_body'       => 'Original body text.',
			'event_timezone'  => 'Asia/Tokyo',
		] );

		try {
			$this->confirm->handle_confirm();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 ); // redirect fired as expected.
		}

		$this->assertSame( 'Asia/Tokyo', get_post_meta( $post_id, '_agnosis_event_timezone', true ) );
	}
}
