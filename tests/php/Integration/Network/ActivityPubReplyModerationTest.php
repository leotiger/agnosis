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
 * moderation link under the same GET-renders/POST-acts split.
 *
 * WP7 (§4 Phase 3A, "the reply gateway page", 2026-07-27) consolidated the
 * previous two per-action links (Approve/Reject, each with its own
 * action-specific token) into ONE action-agnostic token/link reaching ONE
 * page: original + translated reply content, an Approve and a Reject button
 * in the same form, an optional "write your own reply" textarea, and — only
 * when the underlying artwork is actually federated and
 * agnosis_activitypub_enabled is on — a federate checkbox that writes
 * ActivityPub::REPLY_FEDERATE_REQUESTED_META. This file's coverage was
 * updated alongside that redesign: the old "an approve token can't be
 * replayed as a reject token" test no longer applies (there is only one
 * token now, by design — see reply_gateway_url()'s own docblock), and new
 * tests cover the gateway page's own additions.
 *
 * WP6 (federating artist replies outward, same day) is what actually reads
 * that flag now — see ActivityPubFederateReplyTest.php for the delivery
 * coverage (Create{Note}/Delete{Note}, the dereferenceable reply endpoint,
 * repliesCount). This file's own federate-checkbox tests below were
 * extended to also assert ActivityPub::REPLY_FEDERATED_META, so the two
 * files agree on the actual end-to-end outcome, not just the request flag.
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
use Agnosis\Network\FederationSettlement;
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
		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Test Artist', 'user_email' => 'artist@example.com' ] );

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
		unset( $_GET['agnosis_reply'], $_GET['token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_POST['agnosis_reply'], $_POST['token'], $_POST['reply_action'], $_POST['artist_reply'], $_POST['federate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
	 * Simulate the gateway page's POST that actually performs the artist's decision.
	 *
	 * @param array<string, string> $params
	 */
	private function simulate_post( array $params ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		foreach ( $params as $key => $value ) {
			$_POST[ $key ] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/** The real HMAC token for a comment id, via Reflection (private static). */
	private function valid_token( int $comment_id = 0 ): string {
		$ref = new \ReflectionMethod( ActivityPub::class, 'reply_gateway_token' );
		$ref->setAccessible( true );
		return (string) $ref->invoke( null, $comment_id ?: $this->comment_id );
	}

	private function comment_status( int $comment_id = 0 ): string {
		return (string) wp_get_comment_status( $comment_id ?: $this->comment_id );
	}

	/** Mark $this->post_id as federated (FederationSettlement::is_federated() true for itself). */
	private function make_post_federated(): void {
		update_post_meta( $this->post_id, FederationSettlement::STATE_META, FederationSettlement::STATE_FEDERATED );
	}

	/** The artist-authored reply comment (child of $this->comment_id), or null if none was stored. */
	private function artist_reply_comment(): ?\WP_Comment {
		$comments = get_comments( [ 'post_id' => $this->post_id, 'parent' => $this->comment_id, 'status' => 'any' ] );
		if ( ! is_array( $comments ) || empty( $comments ) ) {
			return null;
		}
		$comment = $comments[0];
		return $comment instanceof \WP_Comment ? $comment : null;
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
			'token'         => $this->valid_token(),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected wp_die() for a missing comment id.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}

	// -------------------------------------------------------------------------
	// GET renders the gateway page — no state change (the WP0 fix, WP7 page)
	// -------------------------------------------------------------------------

	public function test_get_with_valid_token_renders_gateway_page_without_acting(): void {
		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the gateway page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Review this reply', $e->body );
			$this->assertStringContainsString( 'Approve', $e->body );
			$this->assertStringContainsString( 'Reject', $e->body );
			$this->assertStringContainsString( 'Lovely piece!', $e->body, 'The original reply content must be shown.' );
			$this->assertStringContainsString( 'Write your own reply', $e->body, 'The optional artist-reply textarea must be present.' );
		}

		// The core of the WP0 fix: a mail scanner's GET prefetch must be
		// harmless — the comment must still be exactly as held as before.
		$this->assertSame( 'unapproved', $this->comment_status() );
	}

	public function test_get_shows_translated_content_when_available(): void {
		update_comment_meta( $this->comment_id, '_agnosis_reply_translated_content', 'Bonita pieza!' );

		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the gateway page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Bonita pieza!', $e->body );
			$this->assertStringNotContainsString( 'Translation pending', $e->body );
		}
	}

	public function test_get_shows_translation_pending_when_not_yet_translated(): void {
		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the gateway page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Translation pending', $e->body );
		}
	}

	public function test_get_shows_federate_checkbox_when_federated_and_enabled(): void {
		update_option( 'agnosis_activitypub_enabled', true );
		$this->make_post_federated();

		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the gateway page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Also post my reply to the Fediverse', $e->body );
		}
	}

	public function test_get_hides_federate_checkbox_when_not_federated(): void {
		update_option( 'agnosis_activitypub_enabled', true );
		// Deliberately NOT calling make_post_federated() — the artwork has
		// never actually federated, so there is nothing to offer to also
		// send outward.

		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the gateway page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringNotContainsString( 'Also post my reply to the Fediverse', $e->body );
		}
	}

	public function test_get_hides_federate_checkbox_when_activitypub_disabled(): void {
		// Not `false`: update_option( $k, false ) is a silent no-op when the
		// option row doesn't exist yet (get_option() returns false for
		// "missing", old === new, nothing is written) — reply_gateway_federate_offered()'s
		// own get_option( ..., true ) would then still see the default
		// `true`. `0` persists as '0', which casts falsy the same way the
		// settings form's own unchecked value would (same convention
		// ActivityPubTest::test_singular_activity_json_declines_when_activitypub_disabled()
		// already documents).
		update_option( 'agnosis_activitypub_enabled', 0 );
		$this->make_post_federated();

		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the gateway page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringNotContainsString( 'Also post my reply to the Fediverse', $e->body );
		}

		delete_option( 'agnosis_activitypub_enabled' );
	}

	public function test_get_with_invalid_token_dies_with_link_error_and_does_not_act(): void {
		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
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
			'token'         => $this->valid_token(),
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
		// the WP0 fix shipped has no expiry meta at all — must not be
		// treated as already-expired (same convention as
		// ReviewEndpoints::verify_token() for _agnosis_review_expiry).
		$this->assertSame( '', get_comment_meta( $this->comment_id, self::EXPIRY_META_KEY, true ) );

		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the gateway page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}
	}

	public function test_get_for_a_deleted_comment_dies_with_404(): void {
		$token = $this->valid_token();
		wp_delete_comment( $this->comment_id, true );

		$this->simulate_get( [
			'agnosis_reply' => (string) $this->comment_id,
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
	// POST performs the artist's decision
	// -------------------------------------------------------------------------

	public function test_post_approve_with_valid_token_approves_and_shows_success(): void {
		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
			'reply_action'  => 'approve',
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
			'token'         => $this->valid_token(),
			'reply_action'  => 'reject',
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
			'token'         => 'not-the-real-token',
			'reply_action'  => 'approve',
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
			'token'         => $this->valid_token(),
			'reply_action'  => 'approve',
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected wp_die() for an expired token.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
		$this->assertSame( 'unapproved', $this->comment_status() );
	}

	public function test_post_with_invalid_reply_action_dies_with_link_error_and_does_not_act(): void {
		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
			'reply_action'  => 'delete', // Not in the allowed list.
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected wp_die() for an invalid reply_action.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
		$this->assertSame( 'unapproved', $this->comment_status() );
	}

	// -------------------------------------------------------------------------
	// The artist's own optional reply (WP7)
	// -------------------------------------------------------------------------

	public function test_post_approve_with_artist_reply_text_stores_an_artist_authored_reply(): void {
		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
			'reply_action'  => 'approve',
			'artist_reply'  => 'Thank you so much!',
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the result page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Your reply has been posted', $e->body );
		}

		$reply = $this->artist_reply_comment();
		$this->assertNotNull( $reply, 'The artist-authored reply must be stored.' );
		$this->assertSame( ActivityPub::LOCAL_REPLY_COMMENT_TYPE, $reply->comment_type, 'An artist reply from the gateway page is an ORDINARY WP4 reply (WP6\'s own decision), not a new comment type.' );
		$this->assertSame( 'approved', wp_get_comment_status( (int) $reply->comment_ID ), 'The artist\'s own reply on their own artwork needs no moderation.' );
		$this->assertSame( (string) $this->artist_id, $reply->user_id );
		$this->assertSame( 'Test Artist', $reply->comment_author );
		$this->assertSame( 'artist@example.com', $reply->comment_author_email );
		$this->assertSame( 'Thank you so much!', $reply->comment_content );
	}

	public function test_post_reject_with_artist_reply_text_still_stores_the_artists_own_reply(): void {
		// Rejecting the original held reply (spam, off-topic, etc.) and the
		// artist choosing to post their own separate reply are independent
		// decisions on this page — an artist may want to publicly respond
		// even while discarding what prompted it.
		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
			'reply_action'  => 'reject',
			'artist_reply'  => 'For context, here is my own note.',
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the result page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'Your reply has been posted', $e->body );
		}

		$this->assertSame( 'trash', $this->comment_status() );
		$this->assertNotNull( $this->artist_reply_comment() );
	}

	public function test_post_with_blank_artist_reply_stores_nothing_extra(): void {
		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
			'reply_action'  => 'approve',
			'artist_reply'  => '   ',
		] );

		try {
			$this->ap->handle_reply_moderation();
			$this->fail( 'Expected the result page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringNotContainsString( 'Your reply has been posted', $e->body );
		}

		$this->assertNull( $this->artist_reply_comment() );
	}

	public function test_post_federate_checkbox_ticked_and_offered_actually_federates(): void {
		update_option( 'agnosis_activitypub_enabled', true );
		$this->make_post_federated();

		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
			'reply_action'  => 'approve',
			'artist_reply'  => 'Thanks!',
			'federate'      => '1',
		] );

		try {
			$this->ap->handle_reply_moderation();
		} catch ( DieCapture $e ) {
			$this->addToAssertionCount( 1 ); // Expected exit path — the real assertions are below.
		}

		$reply = $this->artist_reply_comment();
		$this->assertNotNull( $reply );
		$this->assertSame( '1', get_comment_meta( (int) $reply->comment_ID, ActivityPub::REPLY_FEDERATE_REQUESTED_META, true ) );
		// WP6: the request flag above is no longer inert — store_artist_gateway_reply()
		// triggers federate_artist_reply() inline, which sets this outcome flag.
		// See ActivityPubFederateReplyTest.php for the actual Create{Note}
		// delivery coverage.
		$this->assertSame( '1', get_comment_meta( (int) $reply->comment_ID, '_agnosis_reply_federated', true ) );
	}

	public function test_post_federate_flag_not_written_when_checkbox_left_unticked(): void {
		update_option( 'agnosis_activitypub_enabled', true );
		$this->make_post_federated();

		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
			'reply_action'  => 'approve',
			'artist_reply'  => 'Thanks!',
			// No 'federate' key at all — same as an unchecked HTML checkbox.
		] );

		try {
			$this->ap->handle_reply_moderation();
		} catch ( DieCapture $e ) {
			$this->addToAssertionCount( 1 ); // Expected exit path — the real assertions are below.
		}

		$reply = $this->artist_reply_comment();
		$this->assertNotNull( $reply );
		$this->assertSame( '', get_comment_meta( (int) $reply->comment_ID, ActivityPub::REPLY_FEDERATE_REQUESTED_META, true ) );
		$this->assertSame( '', get_comment_meta( (int) $reply->comment_ID, '_agnosis_reply_federated', true ), 'No request => store_artist_gateway_reply() must never call federate_artist_reply().' );
	}

	public function test_post_federate_flag_not_written_when_not_actually_offered_even_if_submitted(): void {
		// The artwork was never federated (make_post_federated() not called)
		// — reply_gateway_federate_offered() must be re-checked server-side
		// in the POST handler, not just trusted from a crafted 'federate=1'
		// the client-rendered form would never actually have offered.
		update_option( 'agnosis_activitypub_enabled', true );

		$this->simulate_post( [
			'agnosis_reply' => (string) $this->comment_id,
			'token'         => $this->valid_token(),
			'reply_action'  => 'approve',
			'artist_reply'  => 'Thanks!',
			'federate'      => '1',
		] );

		try {
			$this->ap->handle_reply_moderation();
		} catch ( DieCapture $e ) {
			$this->addToAssertionCount( 1 ); // Expected exit path — the real assertions are below.
		}

		$reply = $this->artist_reply_comment();
		$this->assertNotNull( $reply );
		$this->assertSame( '', get_comment_meta( (int) $reply->comment_ID, ActivityPub::REPLY_FEDERATE_REQUESTED_META, true ) );
	}
}
