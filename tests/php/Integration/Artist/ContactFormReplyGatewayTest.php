<?php
/**
 * Integration tests — Artist\ContactForm's reply-gateway thread model
 * (CONTACT-FORM-TRANSLATION-ROADMAP.md §3, CF3/CF5/CF6/CF7).
 *
 * Covers:
 *   - A fresh submission is its own thread root: thread_root_id/parent_id
 *     null, sender 'visitor', sender_lang/sender_lang_name captured via
 *     detect_and_translate(), reply_token_expires_at set, delivered_at
 *     stamped immediately (the root is always synchronous — see
 *     ContactForm::submit()'s own comment).
 *   - The reply-gateway link is symmetric: whoever did NOT write the row
 *     being replied to is the one allowed to reply to it (handle_contact_reply()).
 *   - An artist's reply is trusted outright (no moderation, never
 *     depth-limited) and left pending delivery (delivered_at NULL) for CF7's
 *     drain cron.
 *   - A visitor's follow-up gets AI moderation re-checked server-side AND
 *     the configured `agnosis_contact_reply_depth` re-checked server-side —
 *     never trusted from the rendered page.
 *   - drain_reply_queue() translates each pending row TOWARD the other
 *     party and emails them: an artist's reply via
 *     translate_to_language_name() (open-world target, the visitor's own
 *     detected language), a visitor's follow-up via the existing
 *     (site-language-constrained) translate_fields(), matching the
 *     always-resolvable artist language.
 *   - Token validation: unknown/invalid/expired links all die with a 400
 *     link error, same convention as ActivityPub's own reply gateway.
 *
 * Testing strategy mirrors ActivityPubReplyModerationTest: wp_die() is
 * intercepted via 'wp_die_handler'/'wp_die_ajax_handler' to capture the
 * message/status instead of outputting HTML and calling exit, and
 * $_SERVER['REQUEST_METHOD'] simulates GET vs. POST. Pipeline/
 * SubmissionTranslator are stubbed via the same anonymous-ContactForm-
 * subclass convention ContactFormTest already establishes.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\AI\Pipeline;
use Agnosis\AI\ProviderInterface;
use Agnosis\AI\SubmissionTranslator;
use Agnosis\Artist\ContactForm;
use Agnosis\Tests\Integration\Support\DieCapture;

class ContactFormReplyGatewayTest extends \WP_UnitTestCase {

	private int $artist_id;

	/** All wp_mail() calls captured during a test (keys: to, subject, message, headers). */
	private array $sent_mails = [];

	private ?\Closure $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();

		// Same reason as ContactFormTest::setUp() — pin a known language set
		// so resolve_language_name()-gated calls (translate_fields(), the
		// visitor->artist direction) don't silently no-op against an
		// unresolvable target code.
		add_filter( 'agnosis_translation_languages', [ $this, 'filter_test_language_names' ] );

		$this->artist_id = self::factory()->user->create( [
			'role'         => 'subscriber',
			'display_name' => 'Test Artist',
			'user_email'   => 'artist@example.com',
		] );
		$user = get_userdata( $this->artist_id );
		$user->add_role( 'agnosis_artist' );
		update_user_meta( $this->artist_id, 'locale', 'es_ES' );

		$die_interceptor = static function (): callable {
			return static function ( string|\WP_Error $message, string $title = '', array $args = [] ): never {
				$http_status = (int) ( $args['response'] ?? 200 );
				$msg_str     = is_string( $message ) ? wp_strip_all_tags( $message ) : (string) $message->get_error_message();
				throw new DieCapture( $msg_str, $title, $http_status );
			};
		};
		add_filter( 'wp_die_handler', $die_interceptor );
		add_filter( 'wp_die_ajax_handler', $die_interceptor );
	}

	protected function tearDown(): void {
		$this->remove_mail_capture();
		unset( $_GET['agnosis_contact_reply'], $_GET['token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_POST['agnosis_contact_reply'], $_POST['token'], $_POST['reply_message'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_SERVER['REQUEST_METHOD'] );
		delete_option( 'agnosis_contact_reply_depth' );
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $languages
	 * @return array<string, string>
	 */
	public function filter_test_language_names( array $languages ): array {
		return array_replace( $languages, [ 'en' => 'English', 'es' => 'Spanish' ] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Some tests here drain the reply queue more than once and want a fresh
	 * $sent_mails count for each drain — re-arming must first remove any
	 * filter callback already registered by a previous call in the SAME
	 * test, or both callbacks fire on every subsequent wp_mail() and each
	 * send gets double-counted (both closures independently append to the
	 * same $this->sent_mails array).
	 */
	private function start_mail_capture(): void {
		$this->remove_mail_capture();
		$this->sent_mails  = [];
		$this->mail_filter = function ( $pre, array $atts ): bool {
			$this->sent_mails[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $this->mail_filter, 10, 2 );
	}

	private function remove_mail_capture(): void {
		if ( $this->mail_filter ) {
			remove_filter( 'pre_wp_mail', $this->mail_filter, 10 );
			$this->mail_filter = null;
		}
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

	/**
	 * SubmissionTranslator wrapping a stub provider whose chat() always
	 * returns a response satisfying BOTH detect_and_translate()'s and
	 * translate_fields()'/translate_to_language_name()'s JSON shapes at
	 * once (language_code/language_name/translation covers the first;
	 * translation ALSO answers translate_to_language_name()'s single-key
	 * shape; message answers translate_fields()'s field-keyed shape) —
	 * simplest single stub that works for every translation call this
	 * thread model makes, since no single test here needs to distinguish
	 * between them by request content.
	 */
	private function stub_translator( string $translated_text, string $detected_code = 'es', string $detected_name = 'Spanish' ): SubmissionTranslator {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( (string) wp_json_encode( [
			'language_code' => $detected_code,
			'language_name' => $detected_name,
			'translation'   => $translated_text,
			'message'       => $translated_text,
		] ) );
		return new SubmissionTranslator( $provider );
	}

	/** Same "protected factory method, overridden in an anonymous subclass" convention as ContactFormTest::make_contact_form(). */
	private function make_contact_form( ?Pipeline $pipeline = null, ?SubmissionTranslator $translator = null, bool $translator_given = false ): ContactForm {
		return new class( $pipeline, $translator, $translator_given ) extends ContactForm {
			private ?Pipeline $fixed_pipeline;
			private ?SubmissionTranslator $fixed_translator;
			private bool $translator_given;
			public function __construct( ?Pipeline $pipeline, ?SubmissionTranslator $translator, bool $translator_given ) {
				$this->fixed_pipeline   = $pipeline;
				$this->fixed_translator = $translator;
				$this->translator_given = $translator_given;
			}
			protected function pipeline(): Pipeline {
				return $this->fixed_pipeline ?? parent::pipeline();
			}
			protected function submission_translator(): ?SubmissionTranslator {
				return $this->translator_given ? $this->fixed_translator : parent::submission_translator();
			}
		};
	}

	private function build_request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/contact/' . ( $params['artist_id'] ?? 0 ) );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/** Submit a fresh thread-root message and return its new row id. */
	private function submit_root( ContactForm $form, string $visitor_email = 'visitor@example.com', string $message = 'Hello, I love your work!' ): int {
		$form->submit( $this->build_request( [
			'artist_id' => $this->artist_id,
			'email'     => $visitor_email,
			'message'   => $message,
		] ) );
		$row = $this->row_for( $visitor_email );
		return null !== $row ? (int) $row->id : 0;
	}

	private function row_for( string $visitor_email ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion only.
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}agnosis_contact_messages WHERE visitor_email = %s ORDER BY id DESC LIMIT 1",
			$visitor_email
		) );
	}

	private function row_by_id( int $id ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion only.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agnosis_contact_messages WHERE id = %d", $id ) );
	}

	/** The most recent row replying to $parent_id, or null. */
	private function latest_child( int $parent_id ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion only.
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}agnosis_contact_messages WHERE parent_id = %d ORDER BY id DESC LIMIT 1",
			$parent_id
		) );
	}

	/** id of the most recent row replying to $parent_id, or 0. */
	private function latest_child_id( int $parent_id ): int {
		$row = $this->latest_child( $parent_id );
		return null !== $row ? (int) $row->id : 0;
	}

	/** How many rows currently reply to $parent_id. */
	private function child_count( int $parent_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion only.
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_contact_messages WHERE parent_id = %d",
			$parent_id
		) );
	}

	/** Submit the gateway POST and swallow the expected confirmation wp_die(), asserting it's a 200. */
	private function post_reply_and_expect_confirmation( ContactForm $form ): void {
		try {
			$form->handle_contact_reply();
			$this->fail( 'Expected a confirmation wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}
	}

	private function valid_token( int $message_id ): string {
		$ref = new \ReflectionMethod( ContactForm::class, 'contact_reply_token' );
		$ref->setAccessible( true );
		return (string) $ref->invoke( null, $message_id );
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

	// -------------------------------------------------------------------------
	// CF3 — thread root shape
	// -------------------------------------------------------------------------

	public function test_root_submission_is_its_own_thread_root_with_lang_and_expiry(): void {
		$this->start_mail_capture();
		$form = $this->make_contact_form( $this->stub_pipeline( true ), $this->stub_translator( 'Hola!' ), true );

		$id  = $this->submit_root( $form );
		$row = $this->row_by_id( $id );

		$this->assertNull( $row->thread_root_id );
		$this->assertNull( $row->parent_id );
		$this->assertSame( 'visitor', $row->sender );
		$this->assertSame( 'es', $row->sender_lang );
		$this->assertSame( 'Spanish', $row->sender_lang_name );
		$this->assertNotNull( $row->reply_token_expires_at );
		$this->assertNotNull( $row->delivered_at, 'The thread root is always synchronous — never left for the CF7 drain cron.' );
	}

	// -------------------------------------------------------------------------
	// Gateway — GET renders, no state change
	// -------------------------------------------------------------------------

	public function test_get_with_valid_token_renders_reply_page_without_acting(): void {
		$form = $this->make_contact_form( $this->stub_pipeline( true ), $this->stub_translator( 'Hola!' ), true );
		$id   = $this->submit_root( $form );

		$this->simulate_get( [
			'agnosis_contact_reply' => (string) $id,
			'token'                 => $this->valid_token( $id ),
		] );

		try {
			$form->handle_contact_reply();
			$this->fail( 'Expected the gateway page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Reply to this conversation', $e->body );
			$this->assertStringContainsString( 'Hello, I love your work!', $e->body );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion only.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_contact_messages" );
		$this->assertSame( 1, $count, 'GET must not create any new row.' );
	}

	public function test_get_with_invalid_token_dies_with_link_error(): void {
		$form = $this->make_contact_form( $this->stub_pipeline( true ) );
		$id   = $this->submit_root( $form );

		$this->simulate_get( [
			'agnosis_contact_reply' => (string) $id,
			'token'                 => 'not-the-real-token',
		] );

		try {
			$form->handle_contact_reply();
			$this->fail( 'Expected wp_die() for an invalid token.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}

	public function test_get_with_expired_token_dies_with_link_error(): void {
		global $wpdb;

		$form = $this->make_contact_form( $this->stub_pipeline( true ) );
		$id   = $this->submit_root( $form );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup only.
		$wpdb->update(
			$wpdb->prefix . 'agnosis_contact_messages',
			[ 'reply_token_expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ],
			[ 'id' => $id ]
		);

		$this->simulate_get( [
			'agnosis_contact_reply' => (string) $id,
			'token'                 => $this->valid_token( $id ),
		] );

		try {
			$form->handle_contact_reply();
			$this->fail( 'Expected wp_die() for an expired token.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
			$this->assertStringContainsString( 'expired', $e->body );
		}
	}

	// -------------------------------------------------------------------------
	// Artist reply — trusted outright, never depth-limited, left pending
	// -------------------------------------------------------------------------

	public function test_artist_reply_is_stored_pending_delivery_no_moderation(): void {
		$form = $this->make_contact_form( $this->stub_pipeline( false ) ); // Would reject if ever consulted.
		$id   = $this->submit_root( $form );

		$this->simulate_post( [
			'agnosis_contact_reply' => (string) $id,
			'token'                 => $this->valid_token( $id ),
			'reply_message'         => 'Thank you for reaching out!',
		] );

		$this->post_reply_and_expect_confirmation( $form );

		$reply = $this->latest_child( $id );

		$this->assertNotNull( $reply );
		$this->assertSame( 'artist', $reply->sender );
		$this->assertSame( 'sent', $reply->status, 'An artist reply is never moderated — trusted via the token link.' );
		$this->assertSame( (int) $id, (int) $reply->thread_root_id );
		$this->assertNull( $reply->delivered_at, 'A reply row awaits CF7 drain — never delivered synchronously.' );
	}

	// -------------------------------------------------------------------------
	// Visitor follow-up — moderated and depth-checked server-side
	// -------------------------------------------------------------------------

	public function test_visitor_followup_within_depth_is_accepted(): void {
		update_option( 'agnosis_contact_reply_depth', 2 );

		// A translator stub is required here (not just the pipeline) so the
		// thread ROOT's own sender_lang/sender_lang_name actually get
		// populated by translate_and_detect_for_artist() — without one,
		// submission_translator() falls back to the production
		// SubmissionTranslator::from_settings(), which returns null with no
		// AI provider configured in this test environment, and the assertion
		// below on the follow-up's carried-forward sender_lang would then
		// only ever see an empty value regardless of whether "carry forward,
		// don't re-detect" actually works.
		$form    = $this->make_contact_form( $this->stub_pipeline( true ), $this->stub_translator( 'Hola!' ), true );
		$root_id = $this->submit_root( $form );

		// Artist replies first (never gated) so there's an artist-authored
		// row the VISITOR can then reply to (see class docblock: the
		// replier is always the OTHER party from whoever wrote the row).
		$this->simulate_post( [
			'agnosis_contact_reply' => (string) $root_id,
			'token'                 => $this->valid_token( $root_id ),
			'reply_message'         => 'Thanks for reaching out!',
		] );
		$this->post_reply_and_expect_confirmation( $form );
		$artist_reply_id = $this->latest_child_id( $root_id );

		$this->simulate_post( [
			'agnosis_contact_reply' => (string) $artist_reply_id,
			'token'                 => $this->valid_token( $artist_reply_id ),
			'reply_message'         => 'One more question!',
		] );
		$this->post_reply_and_expect_confirmation( $form );

		$followup = $this->latest_child( $artist_reply_id );
		$this->assertNotNull( $followup );
		$this->assertSame( 'visitor', $followup->sender );
		$this->assertSame( 'sent', $followup->status );
		$this->assertSame( (int) $root_id, (int) $followup->thread_root_id );
		$this->assertSame( 'es', $followup->sender_lang, "The visitor's language is carried forward from the root, never re-detected." );
	}

	public function test_visitor_followup_rejected_by_moderation_is_stored_rejected_and_marked_delivered(): void {
		update_option( 'agnosis_contact_reply_depth', 2 );

		$accepting_form = $this->make_contact_form( $this->stub_pipeline( true ) );
		$root_id        = $this->submit_root( $accepting_form );

		$this->simulate_post( [
			'agnosis_contact_reply' => (string) $root_id,
			'token'                 => $this->valid_token( $root_id ),
			'reply_message'         => 'Thanks for reaching out!',
		] );
		$this->post_reply_and_expect_confirmation( $accepting_form );
		$artist_reply_id = $this->latest_child_id( $root_id );

		// Now use a REJECTING pipeline for the visitor's own follow-up.
		$rejecting_form = $this->make_contact_form( $this->stub_pipeline( false ) );
		$this->simulate_post( [
			'agnosis_contact_reply' => (string) $artist_reply_id,
			'token'                 => $this->valid_token( $artist_reply_id ),
			'reply_message'         => 'Buy cheap watches now!!!',
		] );

		try {
			$rejecting_form->handle_contact_reply();
			$this->fail( 'Expected a (deliberately identical) confirmation wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status, 'Same anti-oracle contract as submit() — a rejection must not be visibly distinguishable.' );
		}

		$followup = $this->latest_child( $artist_reply_id );
		$this->assertSame( 'rejected', $followup->status );
		$this->assertNotNull( $followup->delivered_at, 'A rejected reply is marked delivered at insert time — never queued for CF7.' );
	}

	public function test_visitor_cannot_reply_again_once_depth_exhausted(): void {
		update_option( 'agnosis_contact_reply_depth', 1 ); // Root only — no visitor follow-up at all.

		$form    = $this->make_contact_form( $this->stub_pipeline( true ) );
		$root_id = $this->submit_root( $form );

		// The artist is never gated — still allowed to reply once.
		$this->simulate_post( [
			'agnosis_contact_reply' => (string) $root_id,
			'token'                 => $this->valid_token( $root_id ),
			'reply_message'         => 'Thanks for reaching out!',
		] );
		$this->post_reply_and_expect_confirmation( $form );
		$artist_reply_id = $this->latest_child_id( $root_id );

		// GET must show the "limitation" notice, not the reply form.
		$this->simulate_get( [
			'agnosis_contact_reply' => (string) $artist_reply_id,
			'token'                 => $this->valid_token( $artist_reply_id ),
		] );
		try {
			$form->handle_contact_reply();
			$this->fail( 'Expected the "limitation" gateway page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'already used all of your replies', $e->body );
			$this->assertStringNotContainsString( '<form', $e->body );
		}

		// POST must be rejected server-side too — never trusted from the page.
		$this->simulate_post( [
			'agnosis_contact_reply' => (string) $artist_reply_id,
			'token'                 => $this->valid_token( $artist_reply_id ),
			'reply_message'         => 'One more question!',
		] );
		try {
			$form->handle_contact_reply();
			$this->fail( 'Expected a 400 wp_die() for an exhausted depth limit.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}

		$this->assertSame( 0, $this->child_count( $artist_reply_id ), 'The blocked follow-up must never be inserted.' );
	}

	// -------------------------------------------------------------------------
	// CF7 — drain/translate/deliver
	// -------------------------------------------------------------------------

	public function test_drain_translates_artist_reply_toward_visitor_and_emails_visitor(): void {
		update_option( 'agnosis_contact_reply_depth', 2 );

		$form    = $this->make_contact_form( $this->stub_pipeline( true ), $this->stub_translator( 'Hola!', 'es', 'Spanish' ), true );
		$root_id = $this->submit_root( $form, 'gateway-visitor@example.com' );

		$this->simulate_post( [
			'agnosis_contact_reply' => (string) $root_id,
			'token'                 => $this->valid_token( $root_id ),
			'reply_message'         => 'Thank you for your kind words!',
		] );
		$this->post_reply_and_expect_confirmation( $form );
		$artist_reply_id = $this->latest_child_id( $root_id );
		$this->assertNull( $this->row_by_id( $artist_reply_id )->delivered_at );

		$this->start_mail_capture();
		$form->drain_reply_queue();

		$delivered = $this->row_by_id( $artist_reply_id );
		$this->assertNotNull( $delivered->delivered_at );
		$this->assertSame( 'Hola!', $delivered->translated_message );

		$this->assertCount( 1, $this->sent_mails );
		$this->assertSame( 'gateway-visitor@example.com', $this->sent_mails[0]['to'] );
		$this->assertStringContainsString( 'Hola!', $this->sent_mails[0]['message'] );
	}

	public function test_drain_translates_visitor_followup_toward_artist_and_emails_artist(): void {
		update_option( 'agnosis_contact_reply_depth', 2 );

		$form    = $this->make_contact_form( $this->stub_pipeline( true ), $this->stub_translator( 'Una respuesta', 'es', 'Spanish' ), true );
		$root_id = $this->submit_root( $form, 'followup-visitor@example.com' );

		$this->simulate_post( [
			'agnosis_contact_reply' => (string) $root_id,
			'token'                 => $this->valid_token( $root_id ),
			'reply_message'         => 'Thanks for reaching out!',
		] );
		$this->post_reply_and_expect_confirmation( $form );
		$artist_reply_id = $this->latest_child_id( $root_id );
		// Drain the artist reply out of the way first so only the follow-up is pending below.
		$this->start_mail_capture();
		$form->drain_reply_queue();

		$this->simulate_post( [
			'agnosis_contact_reply' => (string) $artist_reply_id,
			'token'                 => $this->valid_token( $artist_reply_id ),
			'reply_message'         => 'One more question!',
		] );
		$this->post_reply_and_expect_confirmation( $form );
		$followup_id = $this->latest_child_id( $artist_reply_id );

		$this->start_mail_capture();
		$form->drain_reply_queue();

		$delivered = $this->row_by_id( $followup_id );
		$this->assertNotNull( $delivered->delivered_at );
		$this->assertSame( 'Una respuesta', $delivered->translated_message );

		$this->assertCount( 1, $this->sent_mails );
		$this->assertSame( 'artist@example.com', $this->sent_mails[0]['to'] );
	}
}
