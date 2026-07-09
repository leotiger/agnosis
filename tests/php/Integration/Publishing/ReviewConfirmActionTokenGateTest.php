<?php
/**
 * Integration tests — ReviewConfirm::require_valid_action_token() (fifth
 * audit §2e): the shared token gate for the GET reject/remove confirm page.
 *
 * Mirrors require_valid_token()'s approve-only gate but dispatches to the
 * token store the given $action actually uses: 'remove' checks
 * RemovalEndpoints::verify_token() (_agnosis_removal_token/_expiry), anything
 * else (e.g. 'reject') checks ReviewEndpoints::verify_token()
 * (_agnosis_review_token/_expiry) — the same store approve already uses.
 *
 * Before this file, no test anywhere referenced this method at all — the
 * only previously-possible coverage was an incidental "valid token happens
 * to pass through" side effect of some other flow, never the actual
 * dispatch-by-action-type logic, and never the rejection path at all.
 *
 * On failure this method calls redirect_result(), which does
 * `wp_safe_redirect(...); exit;` — a raw `exit` can't be caught by PHPUnit
 * directly. The `expect_redirect()` helper below intercepts the *`wp_redirect`
 * filter* (which wp_safe_redirect() applies internally, BEFORE the header()
 * call and the subsequent `exit;` statement) and throws a plain \Exception
 * from inside that filter callback — the exception unwinds the call stack
 * immediately, so the `exit;` line is never reached, and the test can catch
 * it and assert on the captured redirect URL. Same idea as WP core's own
 * WPDieException trick for wp_die(), applied manually here since
 * redirect_result() uses a bare `exit` rather than wp_die().
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\ReviewConfirm;

class ReviewConfirmActionTokenGateTest extends \WP_UnitTestCase {

	private ReviewConfirm $confirm;
	private \ReflectionMethod $require_valid_action_token;

	protected function setUp(): void {
		parent::setUp();
		$this->confirm = new ReviewConfirm();

		$ref = new \ReflectionMethod( ReviewConfirm::class, 'require_valid_action_token' );
		$ref->setAccessible( true );
		$this->require_valid_action_token = $ref;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_post_with_removal_token( string $token, int $expiry = 0 ): int {
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_agnosis_removal_token', $token );
		update_post_meta( $post_id, '_agnosis_removal_expiry', $expiry ?: ( time() + DAY_IN_SECONDS ) );
		return $post_id;
	}

	private function create_post_with_review_token( string $token, int $expiry = 0 ): int {
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'draft' ] );
		update_post_meta( $post_id, '_agnosis_review_token', $token );
		update_post_meta( $post_id, '_agnosis_review_expiry', $expiry ?: ( time() + DAY_IN_SECONDS ) );
		return $post_id;
	}

	/**
	 * Invoke $run() expecting require_valid_action_token()'s failure branch
	 * (redirect_result('error', ...) then exit) to fire. Returns the redirect
	 * target URL captured just before the (never-reached) exit.
	 */
	private function expect_redirect( callable $run ): string {
		$captured = null;
		$filter   = function ( $location ) use ( &$captured ) {
			$captured = $location;
			throw new \RuntimeException( 'agnosis-test-redirect-short-circuit' );
		};
		add_filter( 'wp_redirect', $filter, 10, 1 );

		try {
			$run();
			$this->fail( 'Expected require_valid_action_token() to redirect (and exit) for an invalid/expired token, but it returned normally.' );
		} catch ( \RuntimeException $e ) {
			if ( 'agnosis-test-redirect-short-circuit' !== $e->getMessage() ) {
				throw $e; // A genuinely different exception — do not swallow it.
			}
		} finally {
			remove_filter( 'wp_redirect', $filter, 10 );
		}

		$this->assertNotNull( $captured, 'The wp_redirect filter must have fired before the test could catch anything.' );
		return (string) $captured;
	}

	/** Invoke require_valid_action_token(), asserting it returns normally (no redirect/exit). */
	private function assert_passes_through( int $id, string $action, string $token, string $post_type = 'agnosis_artwork' ): void {
		$filter = function () {
			$this->fail( 'require_valid_action_token() must not redirect for a valid token.' );
		};
		add_filter( 'wp_redirect', $filter, 10, 1 );
		$this->require_valid_action_token->invoke( $this->confirm, $id, $action, $token, $post_type );
		remove_filter( 'wp_redirect', $filter, 10 );
		// Reaching here (no exception, no fail() triggered above) is the pass condition.
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// action = 'remove' — dispatches to RemovalEndpoints::verify_token()
	// -------------------------------------------------------------------------

	public function test_valid_removal_token_passes_through_silently(): void {
		$post_id = $this->create_post_with_removal_token( 'the-real-token' );

		$this->assert_passes_through( $post_id, 'remove', 'the-real-token' );
	}

	public function test_invalid_removal_token_redirects_to_error(): void {
		$post_id = $this->create_post_with_removal_token( 'the-real-token' );

		$url = $this->expect_redirect( function () use ( $post_id ) {
			$this->require_valid_action_token->invoke( $this->confirm, $post_id, 'remove', 'a-wrong-token', 'agnosis_artwork' );
		} );

		$this->assertStringContainsString( 'agnosis_result=error', $url );
		$this->assertStringContainsString( 'agnosis_type=agnosis_artwork', $url );
	}

	public function test_expired_removal_token_redirects_to_error(): void {
		$post_id = $this->create_post_with_removal_token( 'the-real-token', time() - 100 );

		$url = $this->expect_redirect( function () use ( $post_id ) {
			$this->require_valid_action_token->invoke( $this->confirm, $post_id, 'remove', 'the-real-token', 'agnosis_artwork' );
		} );

		$this->assertStringContainsString( 'agnosis_result=error', $url );
	}

	public function test_missing_removal_token_redirects_to_error(): void {
		// A real published post, but no _agnosis_removal_token meta at all —
		// e.g. a stale/replayed link for a request that was never actually made.
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		$url = $this->expect_redirect( function () use ( $post_id ) {
			$this->require_valid_action_token->invoke( $this->confirm, $post_id, 'remove', 'anything', 'agnosis_artwork' );
		} );

		$this->assertStringContainsString( 'agnosis_result=error', $url );
	}

	// -------------------------------------------------------------------------
	// action != 'remove' (e.g. 'reject') — dispatches to
	// ReviewEndpoints::verify_token(), the SAME store approve already uses
	// -------------------------------------------------------------------------

	public function test_valid_review_token_passes_through_for_reject_action(): void {
		$post_id = $this->create_post_with_review_token( 'the-real-review-token' );

		$this->assert_passes_through( $post_id, 'reject', 'the-real-review-token' );
	}

	public function test_invalid_review_token_redirects_to_error_for_reject_action(): void {
		$post_id = $this->create_post_with_review_token( 'the-real-review-token' );

		$url = $this->expect_redirect( function () use ( $post_id ) {
			$this->require_valid_action_token->invoke( $this->confirm, $post_id, 'reject', 'a-wrong-token', 'agnosis_artwork' );
		} );

		$this->assertStringContainsString( 'agnosis_result=error', $url );
	}

	public function test_expired_review_token_redirects_to_error_for_reject_action(): void {
		$post_id = $this->create_post_with_review_token( 'the-real-review-token', time() - 100 );

		$url = $this->expect_redirect( function () use ( $post_id ) {
			$this->require_valid_action_token->invoke( $this->confirm, $post_id, 'reject', 'the-real-review-token', 'agnosis_artwork' );
		} );

		$this->assertStringContainsString( 'agnosis_result=error', $url );
	}

	// -------------------------------------------------------------------------
	// Dispatch is action-specific — a token valid in ONE store must not be
	// accepted for the OTHER action. This is the crux of §2e: before this
	// method's dispatch logic, there was no reject/remove-aware gate at all.
	// -------------------------------------------------------------------------

	public function test_a_valid_removal_token_is_rejected_when_action_is_reject(): void {
		// This post has a valid REMOVAL token but no review token at all —
		// requesting the 'reject' action must check the review store, find
		// nothing, and fail, even though the removal token would have been fine.
		$post_id = $this->create_post_with_removal_token( 'shared-looking-token' );

		$url = $this->expect_redirect( function () use ( $post_id ) {
			$this->require_valid_action_token->invoke( $this->confirm, $post_id, 'reject', 'shared-looking-token', 'agnosis_artwork' );
		} );

		$this->assertStringContainsString( 'agnosis_result=error', $url );
	}

	public function test_a_valid_review_token_is_rejected_when_action_is_remove(): void {
		// Same idea, reversed: a valid REVIEW token must not satisfy the
		// 'remove' action's removal-token check.
		$post_id = $this->create_post_with_review_token( 'shared-looking-token' );

		$url = $this->expect_redirect( function () use ( $post_id ) {
			$this->require_valid_action_token->invoke( $this->confirm, $post_id, 'remove', 'shared-looking-token', 'agnosis_artwork' );
		} );

		$this->assertStringContainsString( 'agnosis_result=error', $url );
	}
}
