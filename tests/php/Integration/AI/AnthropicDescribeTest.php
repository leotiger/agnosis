<?php
/**
 * Integration tests — Anthropic::describe() JSON parsing.
 *
 * All HTTP calls are intercepted via the pre_http_request filter so no real
 * network requests are made. Tests exercise the full path from wp_remote_post()
 * to DescriptionResult, covering:
 *
 *   - Full happy-path JSON → all DescriptionResult fields populated
 *   - Photo quality score and issues extraction
 *   - Score clamping to [0, 10]
 *   - Empty API key → immediate failure
 *   - WP_Error network failure → failure result
 *   - Non-200 HTTP response → failure result
 *   - 200 response but missing content[0].text path → failure result
 *   - Inner text not valid JSON → failure result
 *   - enhance() always returns failure (Anthropic does not support enhancement)
 *
 * @package Agnosis\Tests\Integration\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\AI;

use Agnosis\AI\PromptConfig;
use Agnosis\AI\Providers\Anthropic;

class AnthropicDescribeTest extends \WP_UnitTestCase {

	/** @var callable|null */
	private $http_filter = null;

	protected function tearDown(): void {
		delete_option( 'agnosis_ai_vision_max_width_px' );
		if ( $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_config(): PromptConfig {
		return new PromptConfig(
			system_prompt:                   'You are a curator.',
			user_template:                   '{artist_prompt}',
			enhancement_instructions:        'Enhance this.',
			tag_count:                       5,
			excerpt_words:                   30,
			quality_threshold:               7,
			quality_rejection_threshold:     3,
		);
	}

	private function make_provider( string $api_key = 'sk-ant-test' ): Anthropic {
		return new Anthropic( $api_key, $this->make_config() );
	}

	private function mock_http( int $code, string $body ): void {
		$this->http_filter = function () use ( $code, $body ) {
			return [
				'response' => [ 'code' => $code, 'message' => 'OK' ],
				'body'     => $body,
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	private function mock_http_error( string $message = 'Connection refused' ): void {
		$this->http_filter = function () use ( $message ) {
			return new \WP_Error( 'http_request_failed', $message );
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * Same as mock_http(), but also captures the outgoing request $args into
	 * $captured_args by reference — for asserting on what was actually sent
	 * (e.g. the base64 image payload), not just the parsed response.
	 *
	 * @param array<string, mixed>|null $captured_args
	 */
	private function mock_http_capturing( int $code, string $body, ?array &$captured_args ): void {
		$this->http_filter = function ( $preempt, $args ) use ( $code, $body, &$captured_args ) {
			$captured_args = $args;
			return [
				'response' => [ 'code' => $code, 'message' => 'OK' ],
				'body'     => $body,
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * Build a real, decodable JPEG blob of the given size using Imagick itself.
	 *
	 * 2026-07-06: this suite now runs PHPUnit inside wp-env's `tests-wordpress`
	 * container (Debian, `wordpress:php8.3-apache`) rather than the Alpine
	 * `tests-cli` sidecar — see dev/composer.json's `test:integration` script.
	 * `tests-cli`'s Imagick build registers zero coders at all
	 * (`Imagick::queryFormats()` returns an empty array — a documented Alpine
	 * issue, github.com/Imagick/imagick#328), so no format, JPEG or otherwise,
	 * ever worked there. `tests-wordpress` has a fully functional Imagick
	 * (261 registered formats, confirmed 2026-07-06), so plain JPEG is fine
	 * again.
	 */
	private function make_test_jpeg( int $width, int $height ): string {
		$img = new \Imagick();
		$img->newImage( $width, $height, new \ImagickPixel( 'blue' ) );
		$img->setImageFormat( 'jpeg' );
		$data = $img->getImageBlob();
		$img->destroy();
		return $data;
	}

	private function extract_sent_image_data( array $args ): string {
		$payload = json_decode( (string) $args['body'], true );
		$b64     = $payload['messages'][0]['content'][0]['source']['data'] ?? '';
		return (string) base64_decode( (string) $b64, true );
	}

	/** Build an Anthropic messages response where content[0].text is the given JSON. */
	private function make_anthropic_body( array $content_json ): string {
		return (string) wp_json_encode( [
			'content' => [
				[
					'type' => 'text',
					'text' => wp_json_encode( $content_json ),
				],
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_empty_api_key_returns_failure(): void {
		$result = $this->make_provider( '' )->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'API key', $result->error );
	}

	public function test_wp_error_returns_failure(): void {
		$this->mock_http_error( 'SSL error' );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'SSL error', $result->error );
	}

	public function test_non_200_response_returns_failure(): void {
		$this->mock_http( 429, '{"error":{"type":"rate_limit_error"}}' );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'Anthropic API error', $result->error );
	}

	public function test_missing_content_path_returns_failure(): void {
		$this->mock_http( 200, (string) wp_json_encode( [ 'content' => [] ] ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'Anthropic API error', $result->error );
	}

	public function test_inner_text_non_json_returns_failure(): void {
		$this->mock_http( 200, (string) wp_json_encode( [
			'content' => [
				[ 'type' => 'text', 'text' => 'Sorry, I cannot help with that.' ],
			],
		] ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'non-JSON', $result->error );
	}

	public function test_full_happy_path_populates_all_fields(): void {
		$content = [
			'title'   => 'Harbour at Dawn',
			'excerpt' => 'Light on still water.',
			'body'    => '<p>A serene harbour scene.</p>',
			'tags'    => [ 'seascape', 'watercolour', 'dawn' ],
			'alt_text' => 'A calm harbour at sunrise painted in watercolour.',
			'medium'  => 'Watercolour',
			'photo_quality' => [
				'score'  => 9,
				'issues' => [],
			],
		];
		$this->mock_http( 200, $this->make_anthropic_body( $content ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', 'Harbour painting' );

		$this->assertTrue( $result->success );
		$this->assertSame( 'Harbour at Dawn', $result->title );
		$this->assertSame( 'Light on still water.', $result->excerpt );
		$this->assertSame( '<p>A serene harbour scene.</p>', $result->body );
		$this->assertSame( [ 'seascape', 'watercolour', 'dawn' ], $result->tags );
		$this->assertSame( 'A calm harbour at sunrise painted in watercolour.', $result->alt_text );
		$this->assertSame( 'Watercolour', $result->medium );
		$this->assertSame( 9, $result->photo_quality_score );
		$this->assertSame( [], $result->photo_quality_issues );
	}

	public function test_photo_quality_score_and_issues_parsed(): void {
		$content = [
			'title'   => 'Blurry Night', 'excerpt' => 'E', 'body' => 'B',
			'tags'    => [], 'alt_text' => 'A', 'medium' => 'Photography',
			'photo_quality' => [
				'score'  => 2,
				'issues' => [ 'motion blur', 'underexposed' ],
			],
		];
		$this->mock_http( 200, $this->make_anthropic_body( $content ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertTrue( $result->success );
		$this->assertSame( 2, $result->photo_quality_score );
		$this->assertContains( 'motion blur', $result->photo_quality_issues );
		$this->assertContains( 'underexposed', $result->photo_quality_issues );
	}

	public function test_quality_score_clamped_to_zero_minimum(): void {
		$content = [
			'title'   => 'T', 'excerpt' => 'E', 'body' => 'B',
			'tags'    => [], 'alt_text' => 'A', 'medium' => 'Photography',
			'photo_quality' => [ 'score' => -3, 'issues' => [] ],
		];
		$this->mock_http( 200, $this->make_anthropic_body( $content ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertSame( 0, $result->photo_quality_score );
	}

	public function test_quality_score_clamped_to_ten_maximum(): void {
		$content = [
			'title'   => 'T', 'excerpt' => 'E', 'body' => 'B',
			'tags'    => [], 'alt_text' => 'A', 'medium' => 'Photography',
			'photo_quality' => [ 'score' => 100, 'issues' => [] ],
		];
		$this->mock_http( 200, $this->make_anthropic_body( $content ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertSame( 10, $result->photo_quality_score );
	}

	public function test_missing_photo_quality_key_defaults_to_zero(): void {
		$content = [
			'title'   => 'T', 'excerpt' => 'E', 'body' => 'B',
			'tags'    => [], 'alt_text' => 'A', 'medium' => 'Photography',
		];
		$this->mock_http( 200, $this->make_anthropic_body( $content ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertTrue( $result->success );
		$this->assertSame( 0, $result->photo_quality_score );
		$this->assertSame( [], $result->photo_quality_issues );
	}

	public function test_enhance_always_returns_failure(): void {
		// No HTTP mock needed — Anthropic::enhance() short-circuits without a network call.
		$result = $this->make_provider()->enhance( 'imagedata', 'image/jpeg', 'Fix blur' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'does not support', $result->error );
	}

	public function test_supports_enhancement_returns_false(): void {
		$this->assertFalse( $this->make_provider()->supports_enhancement() );
	}

	// =========================================================================
	// Vision-input downscaling (agnosis_ai_vision_max_width_px, added 2026-07-06)
	//
	// The critical invariant these guard: describe()'s downscale must be
	// entirely local to this one request — it must never be observable
	// anywhere outside the outgoing HTTP body, since Pipeline::process_single()
	// reuses the exact same $image_data it passes to describe() for image
	// enhancement and for the actual published file (Pipeline's
	// 'original_data'). describe() only ever returns a DescriptionResult
	// (text/metadata, no image bytes), so that invariant holds by
	// construction — these tests cover the resize behavior itself.
	// =========================================================================

	public function test_describe_sends_original_image_unchanged_when_downscale_disabled(): void {
		update_option( 'agnosis_ai_vision_max_width_px', 0 );

		$original = 'raw-unmodified-bytes';
		$captured = null;
		$this->mock_http_capturing( 200, $this->make_anthropic_body( [ 'title' => 'T', 'excerpt' => 'E', 'body' => 'B', 'tags' => [], 'alt_text' => 'A', 'medium' => '' ] ), $captured );

		$this->make_provider()->describe( $original, 'image/jpeg', '' );

		$this->assertSame( $original, $this->extract_sent_image_data( $captured ) );
	}

	public function test_describe_downscales_a_wide_image_before_sending(): void {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Requires Imagick.' );
		}

		update_option( 'agnosis_ai_vision_max_width_px', 400 );

		$original = $this->make_test_jpeg( 2000, 1000 );
		$captured = null;
		$this->mock_http_capturing( 200, $this->make_anthropic_body( [ 'title' => 'T', 'excerpt' => 'E', 'body' => 'B', 'tags' => [], 'alt_text' => 'A', 'medium' => '' ] ), $captured );

		$this->make_provider()->describe( $original, 'image/jpeg', '' );

		$sent = $this->extract_sent_image_data( $captured );
		$this->assertNotSame( $original, $sent, 'The image actually sent must be the downscaled copy, not the original.' );

		$img = new \Imagick();
		$img->readImageBlob( $sent );
		$this->assertSame( 400, $img->getImageWidth() );
		$img->destroy();
	}
}
