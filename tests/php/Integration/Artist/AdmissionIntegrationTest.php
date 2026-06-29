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

class AdmissionIntegrationTest extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Dynamic threshold: 0 % of active artists + minimum 2 → always 2 in tests.
		update_option( 'agnosis_admission_percent', 0 );
		update_option( 'agnosis_admission_minimum', 2 );
		update_option( 'agnosis_admission_window_days', 7 );
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

	/** Submit a valid application and return the application_id. */
	private function apply( string $email = 'applicant@example.com', string $name = 'Test Artist' ): int {
		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => $email,
			'display_name' => $name,
			'bio'          => 'I paint seascapes.',
			'statement'    => 'I want to share my work.',
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
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'applied', $response->get_data()['status'] );
	}

	public function test_apply_returns_application_id(): void {
		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'artist@example.com',
			'display_name' => 'Some Artist',
		] );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'application_id', $data );
		$this->assertGreaterThan( 0, $data['application_id'] );
	}

	public function test_apply_requires_email(): void {
		wp_set_current_user( 0 );
		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'display_name' => 'No Email',
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_apply_returns_409_when_email_already_has_wp_account(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'existing@example.com' ] );

		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'existing@example.com',
			'display_name' => 'Duplicate',
		] );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_apply_returns_409_when_application_already_pending(): void {
		$this->apply( 'pending@example.com' );

		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'pending@example.com',
			'display_name' => 'Duplicate',
		] );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_apply_returns_vouches_required(): void {
		update_option( 'agnosis_admission_minimum', 3 );
		update_option( 'agnosis_admission_percent', 0 );

		$response = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'artist3@example.com',
			'display_name' => 'Triple',
		] );

		$this->assertSame( 3, $response->get_data()['vouches_required'] );
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

	public function test_apply_falls_back_to_accept_language_header(): void {
		global $wpdb;

		$saved = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9,en;q=0.8';

		wp_set_current_user( 0 );
		$this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'de@example.com',
			'display_name' => 'Deutsch Artist',
			// no language param — should fall back to the header
		] );

		if ( null !== $saved ) {
			$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $saved;
		} else {
			unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT language FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
				'de@example.com'
			)
		);
		$this->assertSame( 'de', $stored );
	}

	public function test_apply_parses_complex_accept_language_header(): void {
		global $wpdb;

		// Primary tag "fr-CH" → first segment "fr".
		$saved = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-CH,fr;q=0.9,de;q=0.8,en;q=0.7';

		wp_set_current_user( 0 );
		$this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'fr@example.com',
			'display_name' => 'French Artist',
		] );

		if ( null !== $saved ) {
			$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $saved;
		} else {
			unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT language FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
				'fr@example.com'
			)
		);
		$this->assertSame( 'fr', $stored );
	}

	public function test_apply_stores_null_when_no_language_provided_and_no_header(): void {
		global $wpdb;

		$saved = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
		unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );

		wp_set_current_user( 0 );
		$this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'nolang@example.com',
			'display_name' => 'No Lang Artist',
		] );

		if ( null !== $saved ) {
			$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $saved;
		}

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

	public function test_admitted_artist_without_language_gets_no_locale_set(): void {
		update_option( 'agnosis_admission_minimum', 2 );
		update_option( 'agnosis_admission_percent', 0 );

		$saved = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
		unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );

		$artist1 = $this->create_artist();
		$artist2 = $this->create_artist();

		wp_set_current_user( 0 );
		$response       = $this->rest_post( '/agnosis/v1/admission/apply', [
			'email'        => 'nolocaleset@example.com',
			'display_name' => 'No Locale Artist',
			// no language param, no header
		] );
		$application_id = (int) $response->get_data()['application_id'];

		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist1 );
		$this->rest_post( "/agnosis/v1/admission/vouch/{$application_id}", [], $artist2 );

		if ( null !== $saved ) {
			$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $saved;
		}

		$user = get_user_by( 'email', 'nolocaleset@example.com' );
		$this->assertNotFalse( $user );

		// WP default when no locale meta: empty string.
		$locale = get_user_meta( $user->ID, 'locale', true );
		$this->assertSame( '', $locale, 'Locale meta must be empty when no language was captured.' );
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
