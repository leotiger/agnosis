<?php
/**
 * Integration tests — Network\ActivityPub::handle_reply_moderation().
 *
 * WP0 (agnosis-audit/INTERACTION-SURFACE-ROADMAP.md §7a/§8): before this fix,
 * this handler acted straight on GET — the instant a corporate mail-security
 * scanner (Outlook SafeLinks, Mimecast, Proofpoint, etc.) prefetched the
 * Approve/Reject link from the notification email, the held reply was
 * silently approved or trashed before the artist ever saw the message.
 * `Publishing\ReviewConfirm` solved exactly this for review/removal links in
 * July; this suite is the regression coverage for bringing the reply-
 * moderation link under the same GET-renders/POST-acts split, reusing that
 * class's shared `render_action_confirm_page()` interstitial — plus the
 * expiry this fix adds to the previously-immortal token
 * (`agnosis_review_token_expiry_days`, same option every other stateless
 * emailed action link in the plugin already honours).
 *
 * Per the roadmap doc's own note: "`handle_reply_moderation()` ... is the one
 * piece of Phase 2 without direct test coverage — flagged for a fast-follow,
 * not silently skipped." This file is that fast-follow.
 *
 * Testing strategy mirrors ReviewConfirmIntegrationTest: wp_die() is
 * intercepted via 'wp_die_handler'/'wp_die_ajax_handler' to capture the
 * message/status instead of outputting HTML and calling exit, and
 * $_SERVER['REQUEST_METHOD'] simulates GET vs. POST.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\ActivityPub;
use Agnosis\Tests\Integration\Support\DieCapture;

class ActivityPubReplyModerationTest extends \WP_UnitTestCase {

	private const EXPIRY_META_KEY = '_agnosis_reply_moderation_expiry';

	private ActivityPub $ap;

	/** @var int Artist WP user ID (comment_post_ID's post_author). */
	private int $artist_id;

	/** @var int Published artwork post ID the reply is attached to. */
	private int $post_id;

	/** @var int The held (comment_approved = 0) federated-reply comment. */
	private int $comment_id;

	protected function setUp(): void {
		parent::setUp();

		$this->ap = new ActivityPub();

		// @phpstan-ignore-next-line -- WP_UnitTest_Factory_For_User::create() is typed int|WP_Error but never fails for this fixture's fixed, valid args (same accepted pattern as every other *Test.php in this suite that assigns a factory-created user id straight to an int-typed property).
		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// @phpstan-ignore-next-line -- $this->artist_id is a real int by the time control reaches here; the int|WP_Error union only exists because of factory()->create()'s own return type above, not anything actually wrong with this array.
		$this->post_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_title'  => 'Reply Moderation Test Artwork',
			'post_author' => $this->artist_id,
		] );

		$this->comment_id = (int) wp_insert_comment( [
			'comment_post_ID'      => $this->post_id,
			'comment_content'      => 'Lovely piece!',
			'comment_author'       => 'Remote Fan',
			'comment_author_email' => '',
			'comment_approved'     => 0,
			'comment_type'         => ActivityPub::REPLY_COMMENT_TYPE,
		] );

		// Intercept wp_die() — throw instead of outputting HTML/calling exit.
		// Both filters are hooked because wp_die() picks the handler based on
		// the DOING_AJAX constant.
		$die_interceptor = static function (): callable {
			return static function ( string|\WP_Error $message, string $title = '', array $args = [] ): never {
				$http_status = (int) ( $args['response'] ?? 200 );
				$msg_str     = is_string( $message ) ? wp_strip_all_tags( $message ) : (string) $message->get_error_message();
				throw new DieCapture( $msg_str, $title, $http_status );
			};
		};
		add_filter( 'wp_die_handler',      $die_interceptor );
		add_filter( 'wp_die_ajax_handler', $die_interceptor );
	}

	protected function tearDown(): void {
		unset( $_GET['agnosis_reply'], $_GET['action'], $_GET['token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_POST['agnosis_reply'], $_POST['action'], $_POST['token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Simulate the initial GET from the email link.
	 *
	 * @param array<string, string> $params
	 */
	private function simulate_get( array $params ): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		foreach ( $params as $key => $value ) {
			$_GET[ $key ] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Simulate the confirm-button POST that actually performs the action.
	 *
	 * @param array<string, string> $params
	 */
	private function simulate_post( array $params ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		foreach ( $params as $key => $value ) {
			$_POST[ $key ] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/** The real HMAC token for $this->comment_id + $action, via Reflection (private static). */
	private function valid_token( string $action, int $comment_id = 0 ): string {
		$ref = new \ReflectionMethod( ActivityPub::class, 'reply_moderation_token' );
		$ref->setAccessible( true );
		return (string) $ref->invoke( null, $comment_id ?: $this->comment_id, $action );
	}

	private function comment_status(): string {
		return (string) wp_get_comment_status( $this->comment_id );
	}

	// -------------------------------------------------------------------------
	// Guard clauses
	// -------------------------------------------------------------------------

	public function test_is_a_noop_without_agnosis_reply_param(): void {
		$this->ap->handle_reply_moderation();
		$this->addToAssertionCount( 1 ); // Reaching this line = no wp_die(), no state change.
		$this->assertSame( 'unapproved', $this->comment_status() );
	}

	public function test_get_missing_comment_id_dies_with_link_error(): void {
		$this->simulate_get( [
			'agnosis_reply' => '0',
			'action'        => 'approve',
			'token'         => $this->valid_token( 'approve' ),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected wp_die() for a missing comment id.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}

	public function test_get_invalid_action_dies_with_link_error(): void {
		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'delete', // not in the allowed list.
			'token'         => $this->valid_token( 'approve' ),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected wp_die() for an invalid action.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
		$this->assertSame( 'unapproved', $this->comment_status() );
	}

	// -------------------------------------------------------------------------
	// GET renders the confirm interstitial — no state change (the WP0 fix)
	// -------------------------------------------------------------------------

	public function test_get_with_valid_token_renders_approve_interstitial_without_acting(): void {
		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'approve',
			'token'         => $this->valid_token( 'approve' ),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Approve this reply?', $e->body );
		}

		// The core of the WP0 fix: a mail scanner's GET prefetch must be
		// harmless — the comment must still be exactly as held as before.
		$this->assertSame( 'unapproved', $this->comment_status() );
	}

	public function test_get_with_valid_token_renders_reject_interstitial_without_acting(): void {
		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'reject',
			'token'         => $this->valid_token( 'reject' ),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Reject this reply?', $e->body );
		}

		$this->assertSame( 'unapproved', $this->comment_status() );
	}

	public function test_get_with_invalid_token_dies_with_link_error_and_does_not_act(): void {
		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'approve',
			'token'         => 'not-the-real-token',
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected wp_die() for an invalid token.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
		$this->assertSame( 'unapproved', $this->comment_status() );
	}

	public function test_get_with_expired_token_dies_with_link_error_and_does_not_act(): void {
		update_comment_meta( $this->comment_id, self::EXPIRY_META_KEY, time() - 100 );

		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'approve',
			'token'         => $this->valid_token( 'approve' ),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected wp_die() for an expired token.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
		$this->assertSame( 'unapproved', $this->comment_status() );
	}

	public function test_get_with_no_expiry_meta_still_works(): void {
		// Backward compat: a comment that got its notification email before
		// this fix shipped has no expiry meta at all — must not be treated
		// as already-expired (same convention as ReviewEndpoints::verify_token()
		// for _agnosis_review_expiry).
		$this->assertSame( '', get_comment_meta( $this->comment_id, self::EXPIRY_META_KEY, true ) );

		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'approve',
			'token'         => $this->valid_token( 'approve' ),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}
	}

	public function test_get_for_a_deleted_comment_dies_with_404(): void {
		$token = $this->valid_token( 'approve' );
		wp_delete_comment( $this->comment_id, true );

		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'approve',
			'token'         => $token,
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected wp_die() for a comment that no longer exists.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 404, $e->http_status );
		}
	}

	// -------------------------------------------------------------------------
	// POST performs the action
	// -------------------------------------------------------------------------

	public function test_post_approve_with_valid_token_approves_and_shows_success(): void {
		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'approve',
			'token'         => $this->valid_token( 'approve' ),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the result page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'now appears on your artwork', $e->body );
		}

		$this->assertSame( 'approved', $this->comment_status() );
	}

	public function test_post_reject_with_valid_token_trashes_comment(): void {
		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'reject',
			'token'         => $this->valid_token( 'reject' ),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the result page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'will not be shown', $e->body );
		}

		$this->assertSame( 'trash', $this->comment_status() );
	}

	public function test_post_with_invalid_token_does_not_change_comment_status(): void {
		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'approve',
			'token'         => 'not-the-real-token',
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected wp_die() for an invalid token.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
		$this->assertSame( 'unapproved', $this->comment_status() );
	}

	public function test_post_with_expired_token_does_not_change_comment_status(): void {
		update_comment_meta( $this->comment_id, self::EXPIRY_META_KEY, time() - 100 );

		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'approve',
			'token'         => $this->valid_token( 'approve' ),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected wp_die() for an expired token.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
		$this->assertSame( 'unapproved', $this->comment_status() );
	}

	/**
	 * A token minted for one action must not be accepted for the other — the
	 * HMAC includes the action itself precisely so an artist's Reject link
	 * (say, forwarded or logged somewhere) can't be replayed as an Approve.
	 */
	public function test_a_reject_token_is_not_accepted_for_the_approve_action(): void {
		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'action'        => 'approve',
			'token'         => $this->valid_token( 'reject' ),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected wp_die() for a mismatched action/token pair.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
		$this->assertSame( 'unapproved', $this->comment_status() );
	}
}
