<?php
/**
 * Integration tests — artist admission / vouching system.
 *
 * Tests the full apply → vouch → admit flow. No WP account is created
 * before the community approves the application. apply() is unauthenticated;
 * vouch() targets an application_id; maybe_admit() creates the WP user.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Tests\Integration\Support\FakeLinguaForge;

// Guarded — see JoinPageTest's docblock for why this is safe to define here
// too (PHP constants can't be undefined once set; only matters for tests that
// pass a non-empty $lang into JoinPage::resolve_success_url(), which nothing
// else in this file does apart from the redirect_url tests below).
if ( ! defined( 'LINGUAFORGE_FILE' ) ) {
	define( 'LINGUAFORGE_FILE', __FILE__ );
}
if ( ! defined( 'LINGUAFORGE_VERSION' ) ) {
	define( 'LINGUAFORGE_VERSION', '1.0.0-test' );
}

class AdmissionIntegrationTest extends \WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( 'agnosis_join_success_url' );
		FakeLinguaForge::reset();
		parent::tearDown();
	}

	public function setUp(): void {
		parent::setUp();
		// Dynamic threshold: 0 % of active artists + minimum 2 → always 2 in tests.
		update_option( 'agnosis_admission_percent', 0 );
		update_option( 'agnosis_admission_minimum', 2 );
		update_option( 'agnosis_admission_window_days', 7 );

		// Admission::apply() only keeps a submitted `language` param when it's one
		// SubmissionTranslator::language_names() reports as active — which, since
		// that reads from Lingua Forge, is otherwise whatever (if anything) some
		// unrelated test in this same process happened to leave on
		// LinguaForgeCompatTest::$lf_languages. Pin a known, stable set here via
		// the same `agnosis_translation_languages` filter operators use, so this
		// class's assertions about specific codes ('es', 'de', 'fr') don't depend
		// on Lingua Forge compat test execution order. Reverted automatically by
		// WP_UnitTestCase's hook-snapshot teardown, same as everywhere else.
		add_filter( 'agnosis_translation_languages', [ $this, 'filter_test_language_names' ] );
	}

	/**
	 * @param array<string, string> $languages
	 * @return array<string, string>
	 */
	public function filter_test_language_names( array $languages ): array {
		return array_replace( $languages, [
			'en' => 'English',
			'es' => 'Spanish',
			'de' => 'German',
			'fr' => 'French',
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Create a WP user with the agnosis_artist role. */
	private function create_artist(): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_user_by( 'id', $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	private function rest_post( string $route, array $params = [], ?int $user_id = null ): \WP_REST_Response|\WP_Error {
		wp_set_current_user( $user_id ?? 0 );
		$request = new \WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function rest_get( string $route, ?int $user_id = null ): \WP_REST_Response|\WP_Error {
		wp_set_current_user( $user_id ?? 0 );
		return rest_do_request( new \WP_REST_Request( 'GET', $route ) );
	}

	/**
	 * Submit a valid application and return the application_id.
	 *
	 * Always includes a recognized `language` — apply() now rejects (400) any
	 * request that omits it or sends one Lingua Forge isn't configured for
	 * (see test_apply_requires_language() and friends below), so every other
	 * test in this file that just needs a normal, successful application uses
	 * this default rather than repeating 'language' => 'en' everywhere.
	 */
	private function apply( string $email = 'applicant@example.com', string $name = 'Test Artist', string $language = 'en' ): int {
		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => $email,
			'display_name' => $name,
			'bio'          => 'I paint seascapes.',
			'statement'    => 'I want to share my work.',
			'language'     => $language,
		] );
		return (int) ( $response->get_data()['application_id'] ?? 0 );
	}

	// -------------------------------------------------------------------------
	// Apply endpoint
	// -------------------------------------------------------------------------

	public function test_apply_succeeds_without_authentication(): void {
		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'new@example.com',
			'display_name' => 'New Artist',
			'language'     => 'en',
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'applied', $response->get_data()['status'] );
	}

	public function test_apply_returns_application_id(): void {
		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'artist@example.com',
			'display_name' => 'Some Artist',
			'language'     => 'en',
		] );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'application_id', $data );
		$this->assertGreaterThan( 0, $data['application_id'] );
	}

	public function test_apply_requires_email(): void {
		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'display_name' => 'No Email',
			'language'     => 'en',
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_apply_requires_language(): void {
		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'nolang-required@example.com',
			'display_name' => 'No Language',
			// 'language' omitted entirely.
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_apply_rejects_unrecognized_language(): void {
		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'badlang@example.com',
			'display_name' => 'Bad Language',
			// 'xx' is not in the language_names() map filtered in setUp().
			'language'     => 'xx',
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_apply_returns_409_when_email_already_has_wp_account(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'existing@example.com' ] );

		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'existing@example.com',
			'display_name' => 'Duplicate',
			'language'     => 'en',
		] );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_apply_returns_409_when_application_already_pending(): void {
		$this->apply( 'pending@example.com' );

		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'pending@example.com',
			'display_name' => 'Duplicate',
			'language'     => 'en',
		] );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_apply_returns_vouches_required(): void {
		update_option( 'agnosis_admission_minimum', 3 );
		update_option( 'agnosis_admission_percent', 0 );

		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'artist3@example.com',
			'display_name' => 'Triple',
			'language'     => 'en',
		] );

		$this->assertSame( 3, $response->get_data()['vouches_required'] );
	}

	// -------------------------------------------------------------------------
	// Post-apply redirect (Settings → Community → "After applying, send
	// artists to") — resolved against the submitted `language`, not just the
	// configured page's own permalink. See JoinPage::resolve_success_url().
	// -------------------------------------------------------------------------

	public function test_apply_response_omits_redirect_url_when_unconfigured(): void {
		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'noredirect@example.com',
			'display_name' => 'No Redirect',
			'language'     => 'en',
		] );

		$this->assertArrayNotHasKey( 'redirect_url', $response->get_data() );
	}

	public function test_apply_response_includes_configured_pages_permalink(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'agnosis_join_success_url', $page_id );

		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'redirect@example.com',
			'display_name' => 'Redirect Artist',
			'language'     => 'en',
		] );

		$this->assertSame( get_permalink( $page_id ), $response->get_data()['redirect_url'] );
	}

	public function test_apply_response_redirects_to_translation_matching_submitted_language(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		$es_page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'agnosis_join_success_url', $page_id );
		FakeLinguaForge::link( $page_id, 'es', $es_page );

		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'translated@example.com',
			'display_name' => 'Translated Artist',
			'language'     => 'es',
		] );

		$this->assertSame( get_permalink( $es_page ), $response->get_data()['redirect_url'] );
	}

	public function test_apply_response_falls_back_to_source_page_when_no_translation_for_submitted_language(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'agnosis_join_success_url', $page_id );
		// FakeLinguaForge has no 'de' translation registered for this page.

		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'untranslated@example.com',
			'display_name' => 'Untranslated Artist',
			'language'     => 'de',
		] );

		$this->assertSame( get_permalink( $page_id ), $response->get_data()['redirect_url'] );
	}

	// -------------------------------------------------------------------------
	// Vouch endpoint
	// -------------------------------------------------------------------------

	public function test_vouch_returns_403_when_voucher_is_not_artist(): void {
		$non_artist     = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$application_id = $this->apply( 'target@example.com' );

		$response = $this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $non_artist );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_vouch_returns_404_for_unknown_application(): void {
		$artist = $this->create_artist();

		$response = $this->rest_post( '/agnosis/v1/admission/vouch/99999', [], $artist );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_vouch_returns_201_on_success(): void {
		$artist         = $this->create_artist();
		$application_id = $this->apply( 'vouched@example.com' );

		$response = $this->rest_post(
			"/agnosis/v1/admission/vouch/{$application_id}",
			[ 'message' => 'Great portfolio!' ],
			$artist
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'recorded', $data['status'] );
		$this->assertSame( 'yes', $data['vote'] );
		$this->assertSame( 1, $data['vouches_received'] );
	}

	public function test_vouch_can_be_changed_to_no(): void {
		$artist         = $this->create_artist();
		$application_id = $this->apply( 'dup@example.com' );

		// Vote 'yes' then change to 'no' — ON DUPLICATE KEY UPDATE overwrites.
		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [ 'vote' => 'yes' ], $artist );
		$response = $this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [ 'vote' => 'no' ], $artist );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'recorded', $data['status'] );
		$this->assertSame( 'no', $data['vote'] );
		// Count only positive votes — the changed vote should not count.
		$this->assertSame( 0, $data['vouches_received'] );
	}

	// -------------------------------------------------------------------------
	// Status endpoint
	// -------------------------------------------------------------------------

	public function test_status_requires_admin(): void {
		$application_id = $this->apply( 'stat@example.com' );
		$non_admin      = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$response = $this->rest_get( "/agnosis/v1/admission/status/{$application_id}", $non_admin );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_status_returns_401_for_anonymous(): void {
		$application_id = $this->apply( 'anon@example.com' );

		$response = $this->rest_get( "/agnosis/v1/admission/status/{$application_id}" );

		$this->assertSame( 403, $response->get_status() ); // require_admin returns 403 for unauthenticated too
	}

	public function test_status_returns_application_fields(): void {
		$admin          = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$application_id = $this->apply( 'fields@example.com', 'Field Artist' );

		$response = $this->rest_get( "/agnosis/v1/admission/status/{$application_id}", $admin );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $application_id, $data['id'] );
		$this->assertSame( 'fields@example.com', $data['email'] );
		$this->assertSame( 'Field Artist', $data['display_name'] );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertNull( $data['wp_user_id'] );
		$this->assertSame( 0, $data['vouches_received'] );
	}

	public function test_status_returns_404_for_unknown_application(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$response = $this->rest_get( '/agnosis/v1/admission/status/99999', $admin );

		$this->assertSame( 404, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Full admission flow
	// -------------------------------------------------------------------------

	public function test_applicant_is_admitted_after_required_vouches(): void {
		$artist1        = $this->create_artist();
		$artist2        = $this->create_artist();
		$application_id = $this->apply( 'admit@example.com', 'Future Artist' );

		// First vouch — not enough yet.
		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist1 );
		$this->assertFalse( get_user_by( 'email', 'admit@example.com' ) );

		// Second vouch — threshold reached → WP user created.
		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist2 );

		$new_user = get_user_by( 'email', 'admit@example.com' );
		$this->assertNotFalse( $new_user );
		$this->assertTrue( user_can( $new_user->ID, 'agnosis_artist' ) );
	}

	public function test_admitted_user_gets_display_name(): void {
		$artist1        = $this->create_artist();
		$artist2        = $this->create_artist();
		$application_id = $this->apply( 'named@example.com', 'Display Name Test' );

		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist1 );
		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist2 );

		$new_user = get_user_by( 'email', 'named@example.com' );
		$this->assertSame( 'Display Name Test', $new_user->display_name );
	}

	public function test_application_status_becomes_admitted_after_admission(): void {
		global $wpdb;

		$artist1        = $this->create_artist();
		$artist2        = $this->create_artist();
		$admin          = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$application_id = $this->apply( 'status@example.com' );

		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist1 );
		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist2 );

		$response = $this->rest_get( "/agnosis/v1/admission/status/{$application_id}", $admin );
		$data     = $response->get_data();

		$this->assertSame( 'admitted', $data['status'] );
		$this->assertNotNull( $data['wp_user_id'] );
		$this->assertNotNull( $data['resolved_at'] );
	}

	public function test_vouch_threshold_is_configurable(): void {
		update_option( 'agnosis_admission_minimum', 1 );
		update_option( 'agnosis_admission_percent', 0 );

		$artist         = $this->create_artist();
		$application_id = $this->apply( 'one@example.com' );

		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist );

		$new_user = get_user_by( 'email', 'one@example.com' );
		$this->assertNotFalse( $new_user );
		$this->assertTrue( user_can( $new_user->ID, 'agnosis_artist' ) );
	}

	public function test_no_wp_user_created_before_admission(): void {
		$artist         = $this->create_artist();
		$application_id = $this->apply( 'waiting@example.com' );

		// Only one vouch — below the threshold of 2.
		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist );

		$this->assertFalse( get_user_by( 'email', 'waiting@example.com' ) );
	}

	// -------------------------------------------------------------------------
	// Language capture
	// -------------------------------------------------------------------------

	public function test_apply_stores_language_param_in_db(): void {
		global $wpdb;

		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'lang@example.com',
			'display_name' => 'Lang Artist',
			'language'     => 'es',
		] );

		$this->assertSame( 201, $response->get_status() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT language FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
				'lang@example.com'
			)
		);
		$this->assertSame( 'es', $stored );
	}

	/**
	 * Admission::apply() must never guess a language from the browser's
	 * Accept-Language header — even a clean, unambiguous one. Guessing risks
	 * recording a language this WP instance doesn't actually support (per
	 * Lingua Forge's configuration), which only surfaces later as a broken,
	 * silently-untranslated experience for the artist. Language is now
	 * required outright (see test_apply_requires_language()), so the header
	 * being present alongside a missing 'language' param must still be
	 * rejected — not rescued by falling back to the header.  The header is
	 * present here specifically to prove it's ignored, not consulted.
	 */
	public function test_apply_does_not_use_accept_language_header(): void {
		global $wpdb;

		$saved = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9,en;q=0.8';

		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'de@example.com',
			'display_name' => 'Deutsch Artist',
			// no language param, header present — must still be rejected, not rescued.
		] );

		if ( null !== $saved ) {
			$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $saved;
		} else {
			unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
		}

		$this->assertSame( 400, $response->get_status(), 'A missing language param must be rejected, not filled in from the Accept-Language header.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT language FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
				'de@example.com'
			)
		);
		$this->assertNull( $stored, 'No application row should be created at all when language is rejected.' );
	}

	/**
	 * Same guarantee as above, against a multi-tag header with quality values —
	 * confirms there's no partial parsing logic left to exercise, complex or not.
	 */
	public function test_apply_ignores_complex_accept_language_header(): void {
		global $wpdb;

		$saved = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-CH,fr;q=0.9,de;q=0.8,en;q=0.7';

		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'fr@example.com',
			'display_name' => 'French Artist',
		] );

		if ( null !== $saved ) {
			$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $saved;
		} else {
			unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
		}

		$this->assertSame( 400, $response->get_status() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT language FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
				'fr@example.com'
			)
		);
		$this->assertNull( $stored, 'Accept-Language header must never be used to guess a language.' );
	}

	public function test_apply_rejects_when_no_language_provided_and_no_header(): void {
		global $wpdb;

		$saved = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
		unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );

		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'nolang@example.com',
			'display_name' => 'No Lang Artist',
		] );

		if ( null !== $saved ) {
			$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $saved;
		}

		$this->assertSame( 400, $response->get_status() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT language FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
				'nolang@example.com'
			)
		);
		$this->assertNull( $stored );
	}

	// -------------------------------------------------------------------------
	// WP user locale set on admission
	// -------------------------------------------------------------------------

	public function test_admitted_artist_gets_wp_locale_set(): void {
		update_option( 'agnosis_admission_minimum', 2 );
		update_option( 'agnosis_admission_percent', 0 );

		$artist1 = $this->create_artist();
		$artist2 = $this->create_artist();

		wp_set_current_user( 0 );
		$response       = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'locale@example.com',
			'display_name' => 'Locale Artist',
			'language'     => 'es',
		] );
		$application_id = (int) $response->get_data()['application_id'];

		// Reach the vouch threshold — triggers maybe_admit() → wp_update_user with locale.
		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist1 );
		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist2 );

		$user = get_user_by( 'email', 'locale@example.com' );
		$this->assertNotFalse( $user, 'User must be created on admission.' );

		$locale = get_user_meta( $user->ID, 'locale', true );
		$this->assertSame( 'es_ES', $locale, 'WP locale must be mapped from the ISO code on admission.' );
	}

	/**
	 * Language is now required at apply() time (see test_apply_requires_language()),
	 * so "admit an artist who never supplied a language" is no longer a reachable
	 * state via the normal flow — apply() rejects the request before an
	 * application row (and therefore any later WP account) can ever exist.
	 * This replaces the old test that verified admission still worked with an
	 * empty locale; that path is gone by design now.
	 */
	public function test_apply_without_language_never_reaches_admission(): void {
		update_option( 'agnosis_admission_minimum', 2 );
		update_option( 'agnosis_admission_percent', 0 );

		$artist1 = $this->create_artist();
		$artist2 = $this->create_artist();

		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'nolocaleset@example.com',
			'display_name' => 'No Locale Artist',
			// no language param
		] );

		$this->assertSame( 400, $response->get_status() );

		$application_id = (int) ( $response->get_data()['application_id'] ?? 0 );
		$this->assertSame( 0, $application_id, 'A rejected apply() must not produce an application_id.' );

		// Vouching against a non-existent application is a 404, not a path to admission.
		$vouch_response = $this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist1 );
		$this->assertSame( 404, $vouch_response->get_status() );

		$this->assertFalse( get_user_by( 'email', 'nolocaleset@example.com' ), 'No WP account should ever be created from a rejected application.' );
	}

	// -------------------------------------------------------------------------
	// Admission::iso_to_wp_locale()
	// -------------------------------------------------------------------------

	public function test_iso_to_wp_locale_maps_known_codes(): void {
		$this->assertSame( 'es_ES', \Agnosis\Artist\Admission::iso_to_wp_locale( 'es' ) );
		$this->assertSame( 'fr_FR', \Agnosis\Artist\Admission::iso_to_wp_locale( 'fr' ) );
		$this->assertSame( 'de_DE', \Agnosis\Artist\Admission::iso_to_wp_locale( 'de' ) );
		$this->assertSame( 'zh_CN', \Agnosis\Artist\Admission::iso_to_wp_locale( 'zh' ) );
		$this->assertSame( 'zh_TW', \Agnosis\Artist\Admission::iso_to_wp_locale( 'zh-tw' ) );
		$this->assertSame( 'ja',    \Agnosis\Artist\Admission::iso_to_wp_locale( 'ja' ) );
		$this->assertSame( 'en_US', \Agnosis\Artist\Admission::iso_to_wp_locale( 'en' ) );
	}

	public function test_iso_to_wp_locale_returns_unknown_code_unchanged(): void {
		$this->assertSame( 'xx',  \Agnosis\Artist\Admission::iso_to_wp_locale( 'xx' ) );
		$this->assertSame( 'tok', \Agnosis\Artist\Admission::iso_to_wp_locale( 'tok' ) );
	}
}
