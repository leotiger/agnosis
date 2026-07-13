<?php
/**
 * Integration tests for the self-hosted updater (Core\Updater).
 *
 * Modeled on the companion Lingua Forge plugin's own
 * tests/integration/UpdaterIntegrationTest.php, adapted for Agnosis's
 * instance-based (rather than static) service class convention.
 *
 * Covered here:
 *   is_allowed_download_host() — exact + subdomain matches accepted; spoofed
 *                                and unrelated hosts rejected
 *   verify_and_download()      — passthrough when $pre already set; false for a
 *                                non-Agnosis package; WP_Error for a blocked host;
 *                                SHA-256 mismatch → WP_Error (+ temp file gone);
 *                                empty SHA-256 → verification skipped; matching
 *                                SHA-256 → temp path returned; download WP_Error
 *                                propagated
 *   check_for_update()         — newer manifest → response entry injected;
 *                                not-newer → no_update entry; empty checked → bail
 *   build_update_object() /
 *   build_no_update_object()   — field mapping + defaults; no_update has empty package
 *
 * Strategy:
 *   • The manifest is primed directly into its transient cache
 *     (`agnosis_update_manifest`) so fetch_manifest() never makes a network
 *     call and every branch can be driven deterministically.
 *   • Package downloads are intercepted with the `pre_http_request` filter. When
 *     that filter short-circuits, WordPress does not stream a body to the temp
 *     file, so the downloaded file is empty — the SHA-256 "match" test therefore
 *     expects the hash of an empty string (documented inline).
 *   • Private methods are exercised via Reflection on a live instance.
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\Updater;
use ReflectionMethod;
use WP_UnitTestCase;

final class UpdaterTest extends WP_UnitTestCase {

	private const CACHE_KEY = 'agnosis_update_manifest';

	private Updater $updater;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// download_url() / wp_tempnam() and WP_Upgrader live in wp-admin includes,
		// which are not loaded by default in the test (front-end) context.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->updater = new Updater();
		delete_transient( self::CACHE_KEY );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_transient( self::CACHE_KEY );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** Prime the manifest cache so fetch_manifest() returns it without HTTP. */
	private function prime_manifest( array $fields ): void {
		set_transient( self::CACHE_KEY, (object) $fields, 3600 );
	}

	/** Invoke a private/protected instance method via Reflection. */
	private function call( string $method, array $args = [] ) {
		$ref = new ReflectionMethod( Updater::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( $this->updater, ...$args );
	}

	/** Install a pre_http_request stub returning a fixed response/WP_Error. */
	private function stub_http( $response ): void {
		add_filter( 'pre_http_request', static fn() => $response, 10, 3 );
	}

	private function http_200(): array {
		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '',
			'headers'  => [],
			'cookies'  => [],
		];
	}

	private function upgrader(): \WP_Upgrader {
		return new \WP_Upgrader();
	}

	// =========================================================================
	// is_allowed_download_host()
	// =========================================================================

	public function test_allowed_hosts_exact_match(): void {
		foreach ( [ 'agnosis.art', 'github.com', 'objects.githubusercontent.com' ] as $host ) {
			$this->assertTrue(
				$this->call( 'is_allowed_download_host', [ $host ] ),
				"$host must be allowed (exact match)."
			);
		}
	}

	public function test_allowed_hosts_subdomain_match(): void {
		foreach ( [ 'releases.github.com', 'www.agnosis.art', 'cdn.objects.githubusercontent.com' ] as $host ) {
			$this->assertTrue(
				$this->call( 'is_allowed_download_host', [ $host ] ),
				"$host must be allowed (subdomain match)."
			);
		}
	}

	public function test_disallowed_and_spoofed_hosts_rejected(): void {
		$rejected = [
			'evil.com',
			'',
			'notgithub.com',        // not a subdomain of github.com
			'github.com.evil.com',  // suffix-spoof attempt
			'agnosis.art.attacker.net',
			'githubXcom',
		];
		foreach ( $rejected as $host ) {
			$this->assertFalse(
				$this->call( 'is_allowed_download_host', [ $host ] ),
				"$host must be rejected."
			);
		}
	}

	// =========================================================================
	// verify_and_download() — guards
	// =========================================================================

	public function test_passthrough_when_pre_already_handled(): void {
		$result = $this->updater->verify_and_download( '/already/handled.zip', 'https://github.com/x.zip', $this->upgrader() );
		$this->assertSame( '/already/handled.zip', $result, 'A prior filter result must be respected.' );
	}

	public function test_returns_false_when_manifest_unavailable(): void {
		// Sentinel error object → fetch_manifest() returns false.
		$this->prime_manifest( [ 'error' => true ] );

		$result = $this->updater->verify_and_download( false, 'https://github.com/x.zip', $this->upgrader() );
		$this->assertFalse( $result );
	}

	public function test_returns_false_for_non_agnosis_package(): void {
		$this->prime_manifest(
			[
				'version'      => '9.9.9',
				'download_url' => 'https://github.com/leotiger/agnosis/releases/download/v9.9.9/agnosis.zip',
			]
		);

		// A different package URL must not be intercepted.
		$result = $this->updater->verify_and_download( false, 'https://github.com/someone/other.zip', $this->upgrader() );
		$this->assertFalse( $result );
	}

	// =========================================================================
	// verify_and_download() — host pinning (the security-critical path)
	// =========================================================================

	public function test_blocks_disallowed_download_host(): void {
		$package = 'https://evil.example/agnosis.zip';
		$this->prime_manifest( [ 'version' => '9.9.9', 'download_url' => $package ] );

		// Defensive: if the host check regressed and a download were attempted,
		// this stub would surface a *different* error code, failing the assertion.
		$this->stub_http( new \WP_Error( 'should_not_download', 'Download must not be attempted for a blocked host.' ) );

		$result = $this->updater->verify_and_download( false, $package, $this->upgrader() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_updater_host_blocked', $result->get_error_code() );
	}

	// =========================================================================
	// verify_and_download() — SHA-256 verification
	// =========================================================================

	public function test_empty_sha256_skips_verification_and_returns_temp_file(): void {
		$package = 'https://github.com/leotiger/agnosis/releases/download/v9.9.9/agnosis.zip';
		$this->prime_manifest( [ 'version' => '9.9.9', 'download_url' => $package, 'sha256' => '' ] );
		$this->stub_http( $this->http_200() );

		$result = $this->updater->verify_and_download( false, $package, $this->upgrader() );

		$this->assertIsString( $result, 'An allowed host with no SHA-256 must return the temp file path.' );
		$this->assertFileExists( $result );
		wp_delete_file( $result );
	}

	public function test_matching_sha256_returns_temp_file(): void {
		$package = 'https://github.com/leotiger/agnosis/releases/download/v9.9.9/agnosis.zip';

		// The pre_http_request short-circuit means WordPress does not stream a body
		// to the temp file, so the downloaded file is empty. The expected hash is
		// therefore the SHA-256 of an empty string — this asserts the *match* path,
		// not any particular payload.
		$empty_hash = hash( 'sha256', '' );
		$this->prime_manifest( [ 'version' => '9.9.9', 'download_url' => $package, 'sha256' => $empty_hash ] );
		$this->stub_http( $this->http_200() );

		$result = $this->updater->verify_and_download( false, $package, $this->upgrader() );

		$this->assertIsString( $result, 'A matching SHA-256 must return the temp file path.' );
		$this->assertFileExists( $result );
		wp_delete_file( $result );
	}

	public function test_mismatched_sha256_returns_error(): void {
		$package = 'https://github.com/leotiger/agnosis/releases/download/v9.9.9/agnosis.zip';
		$wrong   = str_repeat( 'a', 64 ); // valid-shape hex that will never match the empty file
		$this->prime_manifest( [ 'version' => '9.9.9', 'download_url' => $package, 'sha256' => $wrong ] );
		$this->stub_http( $this->http_200() );

		$result = $this->updater->verify_and_download( false, $package, $this->upgrader() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_updater_checksum_mismatch', $result->get_error_code() );
	}

	public function test_download_error_is_propagated(): void {
		$package = 'https://github.com/leotiger/agnosis/releases/download/v9.9.9/agnosis.zip';
		$this->prime_manifest( [ 'version' => '9.9.9', 'download_url' => $package, 'sha256' => '' ] );
		$this->stub_http( new \WP_Error( 'http_request_failed', 'Connection refused.' ) );

		$result = $this->updater->verify_and_download( false, $package, $this->upgrader() );

		$this->assertInstanceOf( \WP_Error::class, $result, 'A download failure must propagate as a WP_Error.' );
	}

	// =========================================================================
	// check_for_update() — version comparison
	// =========================================================================

	public function test_check_for_update_injects_response_when_newer(): void {
		$package = 'https://github.com/leotiger/agnosis/releases/download/v99.0.0/agnosis.zip';
		$this->prime_manifest(
			[
				'version'      => '99.0.0',
				'download_url' => $package,
				'details_url'  => 'https://agnosis.art',
			]
		);

		$transient           = new \stdClass();
		$transient->checked  = [ AGNOSIS_BASENAME => '2.0.0' ];
		$transient->response = [];

		$result = $this->updater->check_for_update( $transient );

		$this->assertArrayHasKey( AGNOSIS_BASENAME, $result->response );
		$this->assertSame( '99.0.0', $result->response[ AGNOSIS_BASENAME ]->new_version );
		$this->assertSame( $package, $result->response[ AGNOSIS_BASENAME ]->package );
	}

	public function test_check_for_update_marks_no_update_when_not_newer(): void {
		$this->prime_manifest(
			[
				'version'      => '0.0.1',
				'download_url' => 'https://github.com/leotiger/agnosis/releases/download/v0.0.1/agnosis.zip',
			]
		);

		$transient            = new \stdClass();
		$transient->checked   = [ AGNOSIS_BASENAME => '2.0.0' ];
		$transient->response  = [];
		$transient->no_update = [];

		$result = $this->updater->check_for_update( $transient );

		$this->assertArrayNotHasKey( AGNOSIS_BASENAME, $result->response );
		$this->assertArrayHasKey( AGNOSIS_BASENAME, $result->no_update );
	}

	public function test_check_for_update_bails_when_checked_empty(): void {
		$this->prime_manifest( [ 'version' => '99.0.0', 'download_url' => 'https://github.com/x.zip' ] );

		$transient           = new \stdClass();
		$transient->checked  = []; // WordPress hasn't populated the installed list yet.
		$transient->response = [];

		$result = $this->updater->check_for_update( $transient );

		$this->assertEmpty( $result->response, 'No update entry must be injected before WP has populated $checked.' );
	}

	// =========================================================================
	// plugin_info()
	// =========================================================================

	public function test_plugin_info_ignores_other_actions(): void {
		$result = $this->updater->plugin_info( false, 'query_plugins', (object) [ 'slug' => 'agnosis' ] );
		$this->assertFalse( $result );
	}

	public function test_plugin_info_ignores_other_slugs(): void {
		$result = $this->updater->plugin_info( false, 'plugin_information', (object) [ 'slug' => 'some-other-plugin' ] );
		$this->assertFalse( $result );
	}

	public function test_plugin_info_returns_populated_object_for_our_slug(): void {
		$this->prime_manifest(
			[
				'version'      => '9.9.9',
				'download_url' => 'https://github.com/leotiger/agnosis/releases/download/v9.9.9/agnosis.zip',
				'details_url'  => 'https://agnosis.art',
			]
		);

		$result = $this->updater->plugin_info( false, 'plugin_information', (object) [ 'slug' => 'agnosis' ] );

		$this->assertIsObject( $result );
		$this->assertSame( 'agnosis', $result->slug );
		$this->assertSame( '9.9.9', $result->version );
	}

	// =========================================================================
	// purge_cache() / purge_manifest_cache()
	// =========================================================================

	public function test_purge_cache_clears_transient_on_plugin_update(): void {
		$this->prime_manifest( [ 'version' => '9.9.9' ] );

		$this->updater->purge_cache( null, [ 'type' => 'plugin', 'action' => 'update' ] );

		$this->assertFalse( get_transient( self::CACHE_KEY ) );
	}

	public function test_purge_cache_ignores_unrelated_hook_extra(): void {
		$this->prime_manifest( [ 'version' => '9.9.9' ] );

		$this->updater->purge_cache( null, [ 'type' => 'theme', 'action' => 'update' ] );

		$this->assertNotFalse( get_transient( self::CACHE_KEY ), 'A theme update must not purge the plugin manifest cache.' );
	}

	public function test_purge_manifest_cache_clears_transient(): void {
		$this->prime_manifest( [ 'version' => '9.9.9' ] );

		$this->updater->purge_manifest_cache();

		$this->assertFalse( get_transient( self::CACHE_KEY ) );
	}

	// =========================================================================
	// build_update_object() / build_no_update_object()
	// =========================================================================

	public function test_build_update_object_maps_fields_and_defaults(): void {
		$manifest = (object) [
			'version'      => '9.9.9',
			'download_url' => 'https://github.com/x.zip',
			'details_url'  => 'https://agnosis.art',
		];

		$obj = $this->call( 'build_update_object', [ $manifest ] );

		$this->assertSame( 'agnosis', $obj->slug );
		$this->assertSame( AGNOSIS_BASENAME, $obj->plugin );
		$this->assertSame( '9.9.9', $obj->new_version );
		$this->assertSame( 'https://github.com/x.zip', $obj->package );
		$this->assertSame( AGNOSIS_MIN_WP, $obj->requires, 'requires must default to AGNOSIS_MIN_WP when the manifest omits it.' );
		$this->assertSame( AGNOSIS_MIN_PHP, $obj->requires_php );
	}

	public function test_build_no_update_object_has_empty_package(): void {
		$manifest = (object) [ 'version' => '2.3.2' ];

		$obj = $this->call( 'build_no_update_object', [ $manifest ] );

		$this->assertSame( '2.3.2', $obj->new_version );
		$this->assertSame( '', $obj->package, 'no_update entries must carry an empty package URL.' );
	}

	// =========================================================================
	// add_view_details_link()
	// =========================================================================

	public function test_add_view_details_link_ignores_other_plugins(): void {
		$links = [ 'Existing link' ];
		$result = $this->updater->add_view_details_link( $links, 'some-other-plugin/some-other-plugin.php' );
		$this->assertSame( $links, $result );
	}

	public function test_add_view_details_link_adds_details_and_plugin_site_links(): void {
		$result = $this->updater->add_view_details_link( [], AGNOSIS_BASENAME );

		$joined = implode( ' ', $result );
		$this->assertStringContainsString( 'open-plugin-details-modal', $joined );
		$this->assertStringContainsString( 'github.com/leotiger/agnosis', $joined );
	}

	public function test_add_view_details_link_does_not_duplicate_existing_links(): void {
		$existing = [
			'<a href="#" class="thickbox open-plugin-details-modal">View details</a>',
			'<a href="https://github.com/leotiger/agnosis">Visit plugin site</a>',
		];

		$result = $this->updater->add_view_details_link( $existing, AGNOSIS_BASENAME );

		$this->assertCount( 2, $result, 'Must not append duplicate links when both already exist.' );
	}
}
