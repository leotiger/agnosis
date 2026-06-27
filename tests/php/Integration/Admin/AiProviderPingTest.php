<?php
/**
 * Integration tests — Settings::handle_test_ai() / ping_provider().
 *
 * Uses the pre_http_request filter to mock wp_remote_post() without hitting
 * live APIs. wp_send_json_* calls wp_die() which the WP test framework
 * converts to WPDieException — output is captured and inspected.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\Settings;

class AiProviderPingTest extends \WP_UnitTestCase {

	private Settings $settings;
	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = new Settings();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$_POST = [];
		remove_all_filters( 'pre_http_request' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Build a minimal well-formed wp_remote_post() response array. */
	private function make_http_response( int $code, array $body = [] ): array {
		return [
			'response' => [ 'code' => $code, 'message' => 'OK' ],
			'body'     => wp_json_encode( $body ),
			'headers'  => new \Requests_Utility_CaseInsensitiveDictionary( [] ),
			'cookies'  => [],
			'filename' => null,
		];
	}

	/** Set up $_POST for the AJAX handler and return the captured JSON output. */
	private function call_handle( string $provider ): string {
		$_POST['nonce']    = wp_create_nonce( 'agnosis_test_ai' );
		$_POST['provider'] = $provider;

		ob_start();
		try {
			$this->settings->handle_test_ai();
		} catch ( \WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_send_json_* always calls wp_die(); the exception is the expected exit path.
			unset( $e );
		}
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// OpenAI
	// -------------------------------------------------------------------------

	public function test_openai_success(): void {
		update_option( 'agnosis_openai_api_key', 'sk-test' );
		add_filter( 'pre_http_request', fn() => $this->make_http_response( 200 ), 10, 3 );

		$output = $this->call_handle( 'openai' );

		$this->assertStringContainsString( '"success":true', $output );
		$this->assertStringContainsString( 'OpenAI connection successful', $output );
	}

	public function test_openai_wp_error(): void {
		update_option( 'agnosis_openai_api_key', 'sk-test' );
		add_filter( 'pre_http_request', fn() => new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ), 10, 3 );

		$output = $this->call_handle( 'openai' );

		$this->assertStringContainsString( '"success":false', $output );
		$this->assertStringContainsString( 'Could not resolve host', $output );
	}

	public function test_openai_non_200_with_error_key(): void {
		update_option( 'agnosis_openai_api_key', 'sk-test' );
		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 401, [ 'error' => [ 'message' => 'Incorrect API key provided.' ] ] ),
			10, 3
		);

		$output = $this->call_handle( 'openai' );

		$this->assertStringContainsString( '"success":false', $output );
		$this->assertStringContainsString( 'Incorrect API key provided.', $output );
	}

	public function test_openai_non_200_without_error_key(): void {
		update_option( 'agnosis_openai_api_key', 'sk-test' );
		add_filter( 'pre_http_request', fn() => $this->make_http_response( 503, [] ), 10, 3 );

		$output = $this->call_handle( 'openai' );

		$this->assertStringContainsString( '"success":false', $output );
		$this->assertStringContainsString( 'HTTP 503', $output );
	}

	public function test_openai_missing_key(): void {
		update_option( 'agnosis_openai_api_key', '' );

		$output = $this->call_handle( 'openai' );

		$this->assertStringContainsString( '"success":false', $output );
		$this->assertStringContainsString( 'not configured', $output );
	}

	// -------------------------------------------------------------------------
	// Anthropic
	// -------------------------------------------------------------------------

	public function test_anthropic_success(): void {
		update_option( 'agnosis_anthropic_api_key', 'sk-ant-test' );
		add_filter( 'pre_http_request', fn() => $this->make_http_response( 200 ), 10, 3 );

		$output = $this->call_handle( 'anthropic' );

		$this->assertStringContainsString( '"success":true', $output );
		$this->assertStringContainsString( 'Anthropic connection successful', $output );
	}

	public function test_anthropic_non_200(): void {
		update_option( 'agnosis_anthropic_api_key', 'sk-ant-test' );
		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 401, [ 'error' => [ 'message' => 'Invalid API key.' ] ] ),
			10, 3
		);

		$output = $this->call_handle( 'anthropic' );

		$this->assertStringContainsString( '"success":false', $output );
		$this->assertStringContainsString( 'Invalid API key.', $output );
	}

	// -------------------------------------------------------------------------
	// Unknown provider
	// -------------------------------------------------------------------------

	public function test_unknown_provider_returns_error(): void {
		$output = $this->call_handle( 'nonexistent_provider' );

		$this->assertStringContainsString( '"success":false', $output );
		$this->assertStringContainsString( 'Unknown provider', $output );
	}
}
