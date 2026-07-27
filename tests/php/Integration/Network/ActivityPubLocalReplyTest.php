<?php
/**
 * Integration tests — Network\ActivityPub's local (visitor) reply flow.
 *
 * Interaction-surface roadmap, Phase 3, WP4 (2026-07-27), §4 Phase 3A. A
 * dedicated file (not more tests bolted onto the already-large
 * ActivityPubTest.php) since this is a genuinely separate REST write path —
 * submit_reply() — with its own gate/rate-limit/moderate sequence, modeled
 * line-for-line on Artist\ContactForm::submit() and tested the same way:
 * an anonymous ActivityPub subclass overriding the protected pipeline()
 * factory method to stub Pipeline::classify_text() without a real AI
 * provider (same convention ContactFormTest/EmbedPolicyTest already use for
 * the same class).
 *
 * Covers:
 *   - repliable_artwork()'s gates: unknown post, replies switched off
 *     per-artwork (REPLIES_DISABLED_META) and account-wide
 *     (_agnosis_replies_optout) — all distinct 403/404s, not the
 *     "identical response" discipline (that only covers the moderation
 *     step, see submit_reply()'s own docblock).
 *   - Moderation: an allowed message is stored (held) and notifies the
 *     artist; a rejected one gets the SAME REST response but is never
 *     stored and never emailed.
 *   - store_local_reply() writes REPLY_SOURCE_LANG_META from the page's own
 *     LF language at submission time (resolve_post_lf_lang()), left unset
 *     when the artwork has no _lf_lang meta (the site's own primary-
 *     language post).
 *   - notify_artist_of_reply()'s local-copy branch (intro line differs from
 *     the federated-reply copy; rest of the email is identical).
 *   - Rate limiting: the per-IP tier (rate_limit_reply(), the REST
 *     permission_callback) and the per-sender tier (inside submit_reply()
 *     itself).
 *   - add_reply_type_filters() — the admin Comments-screen dropdown filter.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\AI\Pipeline;
use Agnosis\Network\ActivityPub;

class ActivityPubLocalReplyTest extends \WP_UnitTestCase {

	/**
	 * All wp_mail() calls captured during a test (keys: to, subject, message, headers).
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $sent_mails = [];

	/** The pre_wp_mail filter closure registered for the current test. */
	private ?\Closure $mail_filter = null;

	protected function tearDown(): void {
		$this->remove_mail_capture();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function start_mail_capture(): void {
		$this->sent_mails  = [];
		$this->mail_filter = function ( $pre, array $atts ): bool {
			$this->sent_mails[] = $atts;
			return true; // Short-circuit — do not actually send.
		};
		add_filter( 'pre_wp_mail', $this->mail_filter, 10, 2 );
	}

	private function remove_mail_capture(): void {
		if ( $this->mail_filter ) {
			remove_filter( 'pre_wp_mail', $this->mail_filter, 10 );
			$this->mail_filter = null;
		}
	}

	/** Create a WP user with the agnosis_artist role and return their ID. */
	private function create_artist( string $email = 'artist@example.com' ): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => $email ] );
		$user = get_userdata( $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	private function create_artwork( int $artist_id ): int {
		return (int) self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist_id,
		] );
	}

	/** Pipeline stub whose classify_text() always returns a fixed verdict, no real AI call. */
	private function stub_pipeline( ?bool $verdict ): Pipeline {
		return new class( $verdict ) extends Pipeline {
			private ?bool $fixed_verdict;
			public function __construct( ?bool $verdict ) {
				$this->fixed_verdict = $verdict;
			}
			public function classify_text( string $text, array $disallowed_categories ): ?bool {
				return $this->fixed_verdict;
			}
		};
	}

	/** ActivityPub subclass letting a test pin the Pipeline verdict without a real AI provider. */
	private function make_activitypub( ?Pipeline $pipeline = null ): ActivityPub {
		return new class( $pipeline ) extends ActivityPub {
			private ?Pipeline $fixed_pipeline;
			public function __construct( ?Pipeline $pipeline ) {
				$this->fixed_pipeline = $pipeline;
			}
			protected function pipeline(): Pipeline {
				return $this->fixed_pipeline ?? parent::pipeline();
			}
		};
	}

	/** @param array<string, mixed> $params */
	private function build_request( int $post_id, array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', "/agnosis/v1/content/{$post_id}/replies" );
		$request->set_param( 'id', $post_id );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * All comments against $post_id — same is_array()/instanceof guard
	 * ActivityPubTest::reply_comments() already uses against get_comments()'s
	 * wider count-mode stub return type.
	 *
	 * @return \WP_Comment[]
	 */
	private function all_comments( int $post_id ): array {
		$comments = get_comments( [ 'post_id' => $post_id, 'status' => 'any' ] );
		if ( ! is_array( $comments ) ) {
			return [];
		}
		return array_values( array_filter( $comments, static fn( $comment ) => $comment instanceof \WP_Comment ) );
	}

	/** The single comment inserted against $post_id, or null. */
	private function first_comment( int $post_id ): ?\WP_Comment {
		return $this->all_comments( $post_id )[0] ?? null;
	}

	// -------------------------------------------------------------------------
	// repliable_artwork() gates
	// -------------------------------------------------------------------------

	public function test_submit_reply_to_unknown_post_returns_404(): void {
		$form     = $this->make_activitypub( $this->stub_pipeline( true ) );
		$response = $form->submit_reply( $this->build_request( 999999, [
			'email'   => 'visitor@example.com',
			'message' => 'Hello!',
		] ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_submit_reply_when_replies_disabled_on_artwork_returns_403(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, ActivityPub::REPLIES_DISABLED_META, '1' );

		$form     = $this->make_activitypub( $this->stub_pipeline( true ) );
		$response = $form->submit_reply( $this->build_request( $post_id, [
			'email'   => 'visitor@example.com',
			'message' => 'Hello!',
		] ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	public function test_submit_reply_when_artist_opted_out_account_wide_returns_403(): void {
		$artist_id = $this->create_artist();
		update_user_meta( $artist_id, '_agnosis_replies_optout', '1' );
		$post_id = $this->create_artwork( $artist_id );

		$form     = $this->make_activitypub( $this->stub_pipeline( true ) );
		$response = $form->submit_reply( $this->build_request( $post_id, [
			'email'   => 'visitor@example.com',
			'message' => 'Hello!',
		] ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------------
	// Moderation + storage
	// -------------------------------------------------------------------------

	public function test_allowed_reply_is_stored_held_and_notifies_artist(): void {
		$artist_id = $this->create_artist( 'artist@example.com' );
		$post_id   = $this->create_artwork( $artist_id );

		$this->start_mail_capture();
		$form     = $this->make_activitypub( $this->stub_pipeline( true ) );
		$response = $form->submit_reply( $this->build_request( $post_id, [
			'name'    => 'Visitor Name',
			'email'   => 'visitor@example.com',
			'message' => 'I love this piece!',
		] ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$comment = $this->first_comment( $post_id );
		$this->assertNotNull( $comment, 'An allowed reply must be stored.' );
		$this->assertSame( ActivityPub::LOCAL_REPLY_COMMENT_TYPE, $comment->comment_type );
		$this->assertSame( '0', $comment->comment_approved, 'A freshly-submitted reply must be held, not auto-approved.' );
		$this->assertSame( 'I love this piece!', $comment->comment_content );
		$this->assertSame( 'visitor@example.com', $comment->comment_author_email );
		$this->assertSame( 'Visitor Name', $comment->comment_author );

		$this->assertCount( 1, $this->sent_mails, 'The artist must be notified of a stored reply.' );
		$this->assertSame( 'artist@example.com', $this->sent_mails[0]['to'] );
	}

	public function test_rejected_reply_gets_identical_response_but_is_not_stored_or_emailed(): void {
		$artist_id = $this->create_artist( 'artist@example.com' );
		$post_id   = $this->create_artwork( $artist_id );

		$this->start_mail_capture();
		$allowed_form     = $this->make_activitypub( $this->stub_pipeline( true ) );
		$allowed_response = $allowed_form->submit_reply( $this->build_request( $post_id, [
			'email'   => 'allowed@example.com',
			'message' => 'A perfectly fine reply.',
		] ) );

		$rejected_form     = $this->make_activitypub( $this->stub_pipeline( false ) );
		$rejected_response = $rejected_form->submit_reply( $this->build_request( $post_id, [
			'email'   => 'rejected@example.com',
			'message' => 'Flagged content.',
		] ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $allowed_response );
		$this->assertInstanceOf( \WP_REST_Response::class, $rejected_response );
		$this->assertSame(
			$allowed_response->get_data(),
			$rejected_response->get_data(),
			'A rejected reply must return byte-for-byte the same response as an accepted one — the response must never be usable as a content-filter oracle.'
		);

		$this->assertNull(
			$this->find_comment_by_email( $post_id, 'rejected@example.com' ),
			'A rejected reply must never be stored.'
		);
		$this->assertCount( 1, $this->sent_mails, 'Only the allowed reply should have notified the artist.' );
	}

	public function test_notify_artist_of_reply_uses_local_intro_line_not_fediverse_wording(): void {
		$artist_id = $this->create_artist( 'artist@example.com' );
		$post_id   = $this->create_artwork( $artist_id );

		$this->start_mail_capture();
		$form = $this->make_activitypub( $this->stub_pipeline( true ) );
		$form->submit_reply( $this->build_request( $post_id, [
			'email'   => 'visitor@example.com',
			'message' => 'Nice work.',
		] ) );

		$this->assertCount( 1, $this->sent_mails );
		$this->assertStringContainsString( 'Someone left a reply on your artwork:', $this->sent_mails[0]['message'] );
		$this->assertStringNotContainsString( 'Fediverse', $this->sent_mails[0]['message'], 'A local reply email must not use the federated-reply wording.' );
	}

	// -------------------------------------------------------------------------
	// Source-language tagging (three-version translation model)
	// -------------------------------------------------------------------------

	public function test_stored_reply_records_source_lang_from_the_pages_own_lf_language(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'fr' );

		$form = $this->make_activitypub( $this->stub_pipeline( true ) );
		$form->submit_reply( $this->build_request( $post_id, [
			'email'   => 'visitor@example.com',
			'message' => 'Bonjour !',
		] ) );

		$comment = $this->first_comment( $post_id );
		$this->assertNotNull( $comment );
		$this->assertSame( 'fr', get_comment_meta( (int) $comment->comment_ID, '_agnosis_reply_source_lang', true ) );
		$this->assertSame( '1', get_comment_meta( (int) $comment->comment_ID, '_agnosis_reply_pending_translation', true ) );
	}

	public function test_stored_reply_leaves_source_lang_unset_on_the_sites_primary_language_post(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->create_artwork( $artist_id );
		// No _lf_lang meta at all — this IS the site's own primary-language post.

		$form = $this->make_activitypub( $this->stub_pipeline( true ) );
		$form->submit_reply( $this->build_request( $post_id, [
			'email'   => 'visitor@example.com',
			'message' => 'Hello!',
		] ) );

		$comment = $this->first_comment( $post_id );
		$this->assertNotNull( $comment );
		$this->assertSame( '', get_comment_meta( (int) $comment->comment_ID, '_agnosis_reply_source_lang', true ) );
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	public function test_ip_rate_limit_blocks_after_threshold(): void {
		$form = $this->make_activitypub( $this->stub_pipeline( true ) );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $form->rate_limit_reply(), "Request {$i} must still be within the per-IP limit." );
		}

		$blocked = $form->rate_limit_reply();
		$this->assertInstanceOf( \WP_Error::class, $blocked, 'The 6th request from the same IP within the window must be blocked.' );
		$this->assertSame( 429, $blocked->get_error_data()['status'] );
	}

	public function test_sender_rate_limit_blocks_repeated_email_across_artworks(): void {
		$form = $this->make_activitypub( $this->stub_pipeline( true ) );

		// A DIFFERENT artist/artwork per iteration — the tighter per-(artist,
		// sender) tier (limit 2/hour, see the next test) would otherwise trip
		// first and this test wouldn't actually be exercising the wider
		// per-sender tier (limit 5/hour, keyed only on the visitor's email,
		// shared across every artist) at all.
		for ( $i = 0; $i < 5; $i++ ) {
			$post_id  = $this->create_artwork( $this->create_artist( "artist{$i}@example.com" ) );
			$response = $form->submit_reply( $this->build_request( $post_id, [
				'email'   => 'frequent@example.com',
				'message' => "Reply number {$i}.",
			] ) );
			$this->assertInstanceOf( \WP_REST_Response::class, $response, "Reply {$i} must still be within the per-sender limit." );
		}

		$one_more_post_id = $this->create_artwork( $this->create_artist( 'artist-extra@example.com' ) );
		$blocked          = $form->submit_reply( $this->build_request( $one_more_post_id, [
			'email'   => 'frequent@example.com',
			'message' => 'One reply too many.',
		] ) );
		$this->assertInstanceOf( \WP_Error::class, $blocked, 'The per-sender tier must block a 6th reply from the same email within the window, even against a brand-new artist.' );
		$this->assertSame( 429, $blocked->get_error_data()['status'] );
	}

	public function test_per_artist_rate_limit_blocks_before_the_wider_sender_limit(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->create_artwork( $artist_id );
		$form      = $this->make_activitypub( $this->stub_pipeline( true ) );

		// REPLY_ARTIST_LIMIT_DEFAULT is 2 per hour — reusing ContactForm's own
		// agnosis_contact_artist_limit option/default, tighter than the 5/hour
		// wider per-sender tier, so this must trip first.
		for ( $i = 0; $i < 2; $i++ ) {
			$response = $form->submit_reply( $this->build_request( $post_id, [
				'email'   => 'same-visitor@example.com',
				'message' => "Reply number {$i}.",
			] ) );
			$this->assertInstanceOf( \WP_REST_Response::class, $response );
		}

		$blocked = $form->submit_reply( $this->build_request( $post_id, [
			'email'   => 'same-visitor@example.com',
			'message' => 'A third reply to the same artist.',
		] ) );
		$this->assertInstanceOf( \WP_Error::class, $blocked, 'The tighter per-(artist,sender) tier must block before the wider per-sender tier does.' );
		$this->assertSame( 429, $blocked->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------------
	// Admin comment-type filter
	// -------------------------------------------------------------------------

	public function test_add_reply_type_filters_adds_both_reply_types(): void {
		$types = ( new ActivityPub() )->add_reply_type_filters( [ 'comment' => 'Comments' ] );

		$this->assertArrayHasKey( 'comment', $types, 'Must not remove types it does not own.' );
		$this->assertArrayHasKey( ActivityPub::REPLY_COMMENT_TYPE, $types );
		$this->assertArrayHasKey( ActivityPub::LOCAL_REPLY_COMMENT_TYPE, $types );
	}

	/** First comment against $post_id whose author email matches, or null. */
	private function find_comment_by_email( int $post_id, string $email ): ?\WP_Comment {
		foreach ( $this->all_comments( $post_id ) as $comment ) {
			if ( $comment->comment_author_email === $email ) {
				return $comment;
			}
		}
		return null;
	}
}
