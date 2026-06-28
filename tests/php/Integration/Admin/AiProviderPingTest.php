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
		// wp_die() branches on the DOING_AJAX *constant* (not the filter) to pick
		// its die handler. Define it once so the ajax-die path is always taken and
		// wp_send_json_* calls wp_die() rather than native die().
		defined( 'DOING_AJAX' ) || define( 'DOING_AJAX', true );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$_POST    = [];
		$_REQUEST = [];
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
			'headers'  => [],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/** Set up $_POST and $_REQUEST for the AJAX handler and return the captured JSON output. */
	private function call_handle( string $provider ): string {
		$nonce                = wp_create_nonce( 'agnosis_test_ai' );
		$_POST['nonce']       = $nonce;
		$_POST['provider']    = $provider;
		// check_ajax_referer() reads $_REQUEST, which is a one-time copy made at
		// script start and does not reflect later $_POST mutations in CLI mode.
		$_REQUEST['nonce']    = $nonce;
		$_REQUEST['provider'] = $provider;

		// Hook both die-handler filters so WPDieException is thrown regardless of
		// which branch wp_die() takes (handler vs ajax-handler).
		add_filter( 'wp_die_handler',      [ $this, 'get_wp_die_handler' ], 1 );
		add_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ], 1 );

		$raw = '';
		ob_start(
			static function ( string $buffer ) use ( &$raw ): string {
				$raw = $buffer;
				return ''; // suppress output; content is in $raw.
			}
		);
		try {
			$this->settings->handle_test_ai();
		} catch ( \WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_send_json_* always calls wp_die(); the exception is the expected exit path.
			unset( $e );
		}
		ob_end_clean();

		remove_filter( 'wp_die_handler',      [ $this, 'get_wp_die_handler' ], 1 );
		remove_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ], 1 );

		return $raw;
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
