<?php
/**
 * Integration tests — Artist\ContactForm (visitor-to-artist contact form).
 *
 * Covers:
 *   - contactable_artist() gating: an unknown user ID, a real user without
 *     the agnosis_artist role, and an opted-out artist all return the SAME
 *     404 — deliberately indistinguishable (see class docblock).
 *   - artist_accepts_contact() — the static helper ContactFormBlock and
 *     SubdomainNavigation both gate on before ever rendering the trigger/form.
 *   - Moderation: a false verdict stores a 'rejected' row and sends no email,
 *     but the REST response is byte-for-byte identical to an accepted
 *     message's — the response alone must never leak which branch was taken.
 *     A null (inconclusive) verdict is treated as ALLOW (fail open) — the
 *     opposite of EmbedPolicy's fail-closed contract, see ContactForm's own
 *     docblock for why.
 *   - Translation: with a resolvable artist locale and an available
 *     translator, the emailed body carries the translated text plus the
 *     original underneath; without either, the original is sent unchanged.
 *   - Reply-To carries the visitor's own email address.
 *   - The actually-registered REST route's `args` validation (email format,
 *     message length cap), exercised via rest_do_request() rather than a
 *     direct method call, so the wiring itself — not just the business
 *     logic — is covered.
 *
 * Pipeline/SubmissionTranslator are stubbed via an anonymous ContactForm
 * subclass overriding its protected pipeline()/submission_translator()
 * factory methods — same "protected factory method, overridden in an
 * anonymous subclass" convention EmbedPolicyTest uses for Pipeline itself.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\AI\Pipeline;
use Agnosis\AI\ProviderInterface;
use Agnosis\AI\SubmissionTranslator;
use Agnosis\Artist\ContactForm;

class ContactFormTest extends \WP_UnitTestCase {

	/** All wp_mail() calls captured during a test (keys: to, subject, message, headers). */
	private array $sent_mails = [];

	/** The pre_wp_mail filter closure registered for the current test. */
	private ?\Closure $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();

		// SubmissionTranslator::translate_fields()'s first step is resolving the
		// target code to a language NAME (resolve_language_name(), reading
		// language_names()) — with Lingua Forge inactive in this test process
		// (no linguaforge_languages() stub defined), that falls back to just
		// the site's own locale ('en'), so a target of 'es' would resolve to
		// null and translate_fields() would return [] before ever reaching the
		// stubbed provider's chat(). Pinning a known set here (same technique
		// AdmissionIntegrationTest already uses for the same reason) makes the
		// translation tests below exercise the actual stub instead of silently
		// short-circuiting on an unresolvable language code.
		add_filter( 'agnosis_translation_languages', [ $this, 'filter_test_language_names' ] );
	}

	protected function tearDown(): void {
		$this->remove_mail_capture();
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $languages
	 * @return array<string, string>
	 */
	public function filter_test_language_names( array $languages ): array {
		return array_replace( $languages, [
			'en' => 'English',
			'es' => 'Spanish',
			'fr' => 'French',
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function start_mail_capture(): void {
		$this->sent_mails = [];
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
	private function create_artist( string $email = 'artist@example.com', string $locale = '' ): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => $email ] );
		$user = get_userdata( $id );
		$user->add_role( 'agnosis_artist' );
		if ( '' !== $locale ) {
			update_user_meta( $id, 'locale', $locale );
		}
		return $id;
	}

	/** Row from agnosis_contact_messages for a given visitor email, most recent first. */
	private function latest_row_for( string $visitor_email ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion only.
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}agnosis_contact_messages WHERE visitor_email = %s ORDER BY id DESC LIMIT 1",
			$visitor_email
		) );
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

	/** SubmissionTranslator wrapping a stub provider whose chat() always returns a fixed translation. */
	private function stub_translator( string $translated_message ): SubmissionTranslator {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( (string) wp_json_encode( [ 'message' => $translated_message ] ) );
		return new SubmissionTranslator( $provider );
	}

	/**
	 * ContactForm subclass letting a test pin the Pipeline verdict and/or
	 * translator without any real AI provider configured. $translator_given
	 * distinguishes "no translator injected — fall back to production
	 * resolution" from "explicitly inject null" (an unresolvable provider),
	 * since both would otherwise collapse to the same nullable property.
	 */
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

	// -------------------------------------------------------------------------
	// contactable_artist() gating
	// -------------------------------------------------------------------------

	public function test_submit_to_unknown_artist_returns_404(): void {
		$form = $this->make_contact_form( $this->stub_pipeline( true ) );

		$response = $form->submit( $this->build_request( [
			'artist_id' => 999999,
			'email'     => 'visitor@example.com',
			'message'   => 'Hello!',
		] ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_submit_to_non_artist_user_returns_404(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$form    = $this->make_contact_form( $this->stub_pipeline( true ) );

		$response = $form->submit( $this->build_request( [
			'artist_id' => $user_id,
			'email'     => 'visitor@example.com',
			'message'   => 'Hello!',
		] ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_submit_to_opted_out_artist_returns_404_same_as_unknown_artist(): void {
		$artist_id = $this->create_artist();
		update_user_meta( $artist_id, '_agnosis_contact_optout', '1' );
		$form = $this->make_contact_form( $this->stub_pipeline( true ) );

		$response = $form->submit( $this->build_request( [
			'artist_id' => $artist_id,
			'email'     => 'visitor@example.com',
			'message'   => 'Hello!',
		] ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame(
			404,
			$response->get_error_data()['status'],
			'An opted-out artist must reject with the same status as an unknown one — the response must not leak opt-out state.'
		);
	}

	public function test_artist_accepts_contact_reflects_optout_toggle(): void {
		$artist_id = $this->create_artist();
		$this->assertTrue( ContactForm::artist_accepts_contact( $artist_id ) );

		update_user_meta( $artist_id, '_agnosis_contact_optout', '1' );
		$this->assertFalse( ContactForm::artist_accepts_contact( $artist_id ) );

		delete_user_meta( $artist_id, '_agnosis_contact_optout' );
		$this->assertTrue( ContactForm::artist_accepts_contact( $artist_id ) );
	}

	public function test_artist_accepts_contact_false_for_non_artist(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->assertFalse( ContactForm::artist_accepts_contact( $user_id ) );
	}

	// -------------------------------------------------------------------------
	// Moderation
	// -------------------------------------------------------------------------

	public function test_allowed_message_is_stored_sent_and_emailed(): void {
		$artist_id = $this->create_artist( 'artist@example.com' );

		$this->start_mail_capture();
		$form     = $this->make_contact_form( $this->stub_pipeline( true ) );
		$response = $form->submit( $this->build_request( [
			'artist_id' => $artist_id,
			'name'      => 'Visitor Name',
			'email'     => 'allowed-visitor@example.com',
			'message'   => 'I love your work!',
		] ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$row = $this->latest_row_for( 'allowed-visitor@example.com' );
		$this->assertNotNull( $row );
		$this->assertSame( 'sent', $row->status );
		$this->assertSame( (string) $artist_id, $row->artist_id );
		$this->assertNull( $row->rejection_reason );

		$this->assertCount( 1, $this->sent_mails );
		$this->assertSame( 'artist@example.com', $this->sent_mails[0]['to'] );
	}

	public function test_inconclusive_verdict_is_allowed_fail_open(): void {
		$artist_id = $this->create_artist();

		$this->start_mail_capture();
		$form = $this->make_contact_form( $this->stub_pipeline( null ) ); // Inconclusive/provider failure.
		$form->submit( $this->build_request( [
			'artist_id' => $artist_id,
			'email'     => 'inconclusive-visitor@example.com',
			'message'   => 'Hi there',
		] ) );

		$row = $this->latest_row_for( 'inconclusive-visitor@example.com' );
		$this->assertSame( 'sent', $row->status, 'Unlike EmbedPolicy, an inconclusive verdict must fail OPEN here (see class docblock).' );
		$this->assertCount( 1, $this->sent_mails );
	}

	public function test_rejected_message_is_stored_and_not_emailed_but_response_matches_an_allowed_one(): void {
		$artist_id = $this->create_artist();
		$this->start_mail_capture();

		$allowed_response = $this->make_contact_form( $this->stub_pipeline( true ) )->submit( $this->build_request( [
			'artist_id' => $artist_id,
			'email'     => 'genuine-visitor@example.com',
			'message'   => 'A genuine message.',
		] ) );

		$rejected_response = $this->make_contact_form( $this->stub_pipeline( false ) )->submit( $this->build_request( [
			'artist_id' => $artist_id,
			'email'     => 'spammy-visitor@example.com',
			'message'   => 'Buy cheap watches now!!!',
		] ) );

		// Identical shape/status regardless of accept/reject — the response
		// alone must never leak which branch was taken (class docblock).
		$this->assertSame( $allowed_response->get_status(), $rejected_response->get_status() );
		$this->assertSame( $allowed_response->get_data(), $rejected_response->get_data() );

		$row = $this->latest_row_for( 'spammy-visitor@example.com' );
		$this->assertSame( 'rejected', $row->status );
		$this->assertNotEmpty( $row->rejection_reason );

		// Only the allowed message was actually emailed.
		$this->assertCount( 1, $this->sent_mails );
	}

	// -------------------------------------------------------------------------
	// Translation
	// -------------------------------------------------------------------------

	public function test_message_is_translated_into_artists_own_locale_when_resolvable(): void {
		$artist_id = $this->create_artist( 'artist@example.com', 'es_ES' );

		$this->start_mail_capture();
		$form = $this->make_contact_form(
			$this->stub_pipeline( true ),
			$this->stub_translator( 'Hola, me encanta tu trabajo!' ),
			true
		);
		$form->submit( $this->build_request( [
			'artist_id' => $artist_id,
			'email'     => 'translated-visitor@example.com',
			'message'   => 'Hi, I love your work!',
		] ) );

		$row = $this->latest_row_for( 'translated-visitor@example.com' );
		$this->assertSame( 'Hola, me encanta tu trabajo!', $row->translated_message );

		$this->assertCount( 1, $this->sent_mails );
		$body = $this->sent_mails[0]['message'];
		$this->assertStringContainsString( 'Hola, me encanta tu trabajo!', $body );
		$this->assertStringContainsString( 'Hi, I love your work!', $body, 'Original text is preserved beneath the translation.' );
	}

	public function test_no_translation_when_artist_locale_unresolvable(): void {
		$artist_id = $this->create_artist( 'artist@example.com' ); // No locale meta set.

		$this->start_mail_capture();
		$form = $this->make_contact_form(
			$this->stub_pipeline( true ),
			$this->stub_translator( 'should never be used' ),
			true
		);
		$form->submit( $this->build_request( [
			'artist_id' => $artist_id,
			'email'     => 'untranslated-visitor@example.com',
			'message'   => 'Hi, I love your work!',
		] ) );

		$row = $this->latest_row_for( 'untranslated-visitor@example.com' );
		$this->assertNull( $row->translated_message );

		$body = $this->sent_mails[0]['message'];
		$this->assertStringContainsString( 'Hi, I love your work!', $body );
		$this->assertStringNotContainsString( 'should never be used', $body );
	}

	public function test_no_translation_when_no_provider_configured(): void {
		$artist_id = $this->create_artist( 'artist@example.com', 'fr_FR' );

		$this->start_mail_capture();
		// translator_given = false → falls back to production
		// submission_translator(), which returns null with no AI key configured.
		$form = $this->make_contact_form( $this->stub_pipeline( true ) );
		$form->submit( $this->build_request( [
			'artist_id' => $artist_id,
			'email'     => 'no-provider-visitor@example.com',
			'message'   => 'Hi, I love your work!',
		] ) );

		$row = $this->latest_row_for( 'no-provider-visitor@example.com' );
		$this->assertNull( $row->translated_message );
		$this->assertSame( 'sent', $row->status );
	}

	// -------------------------------------------------------------------------
	// Reply-To header
	// -------------------------------------------------------------------------

	public function test_reply_to_is_visitors_own_email(): void {
		$artist_id = $this->create_artist();

		$this->start_mail_capture();
		$form = $this->make_contact_form( $this->stub_pipeline( true ) );
		$form->submit( $this->build_request( [
			'artist_id' => $artist_id,
			'email'     => 'reply-to-visitor@example.com',
			'message'   => 'Hello!',
		] ) );

		$headers = (array) $this->sent_mails[0]['headers'];
		$found   = false;
		foreach ( $headers as $header ) {
			if ( is_string( $header ) && str_contains( $header, 'Reply-To: reply-to-visitor@example.com' ) ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, "Reply-To header must carry the visitor's own email address." );
	}

	// -------------------------------------------------------------------------
	// Full REST route wiring — args validation via the actually registered route
	// -------------------------------------------------------------------------

	public function test_registered_route_validates_email_format(): void {
		$artist_id = $this->create_artist();

		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/contact/' . $artist_id );
		$request->set_param( 'email', 'not-an-email' );
		$request->set_param( 'message', 'Hello there, this is a real message.' );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_registered_route_rejects_overlong_message(): void {
		$artist_id = $this->create_artist();

		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/contact/' . $artist_id );
		$request->set_param( 'email', 'route-visitor@example.com' );
		$request->set_param( 'message', str_repeat( 'a', 4001 ) );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_registered_route_succeeds_with_no_ai_configured(): void {
		$artist_id = $this->create_artist();

		$this->start_mail_capture();
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/contact/' . $artist_id );
		$request->set_param( 'email', 'wired-visitor@example.com' );
		$request->set_param( 'message', 'A genuine message with no AI provider configured.' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount(
			1,
			$this->sent_mails,
			'No AI configured: classify_text()/translate_fields() both no-op gracefully, message still sent as-is.'
		);
	}
}
