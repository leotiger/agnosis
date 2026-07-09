<?php
/**
 * Integration tests — OpenAI::describe() JSON parsing.
 *
 * All HTTP calls are intercepted via the pre_http_request filter so no real
 * network requests are made. Tests exercise the full path from wp_remote_post()
 * call to DescriptionResult, covering:
 *
 *   - Full happy-path JSON → all DescriptionResult fields populated
 *   - Photo quality score clamping (0–10 bounds)
 *   - Photo quality issues array mapped to sanitized strings
 *   - Empty API key → immediate failure
 *   - WP_Error network failure → failure result
 *   - Non-200 HTTP response → failure result
 *   - 200 response but missing choices path → failure result
 *   - Inner content not valid JSON → failure result
 *
 * @package Agnosis\Tests\Integration\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\AI;

use Agnosis\AI\DescriptionResult;
use Agnosis\AI\PromptConfig;
use Agnosis\AI\Providers\OpenAI;

class OpenAIDescribeTest extends \WP_UnitTestCase {

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

	private function make_provider( string $api_key = 'sk-test-key' ): OpenAI {
		return new OpenAI( $api_key, $this->make_config() );
	}

	/** Extract the system message content actually sent to OpenAI. */
	private function extract_sent_system_prompt( array $args ): string {
		$payload = json_decode( (string) $args['body'], true );
		return (string) ( $payload['messages'][0]['content'] ?? '' );
	}

	/**
	 * Register a pre_http_request filter that returns a canned response.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Raw body string.
	 */
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

	/** Return a WP_Error via the filter. */
	private function mock_http_error( string $message = 'Connection refused' ): void {
		$this->http_filter = function () use ( $message ) {
			return new \WP_Error( 'http_request_failed', $message );
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * Same as mock_http(), but also captures the outgoing request $args into
	 * $captured_args by reference — for asserting on what was actually sent.
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

	/** Extract and decode the base64 payload from OpenAI's data: URL image_url field. */
	private function extract_sent_image_data( array $args ): string {
		$payload  = json_decode( (string) $args['body'], true );
		$data_url = $payload['messages'][1]['content'][0]['image_url']['url'] ?? '';
		$b64      = (string) preg_replace( '/^data:[^;]+;base64,/', '', (string) $data_url );
		return (string) base64_decode( $b64, true );
	}

	/** Build a full OpenAI chat-completions response JSON. */
	private function make_openai_body( array $content_json ): string {
		return (string) wp_json_encode( [
			'choices' => [
				[
					'message' => [
						'content' => wp_json_encode( $content_json ),
					],
				],
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_empty_api_key_returns_failure(): void {
		$result = $this->make_provider( '' )->describe( 'imagedata', 'image/jpeg', 'A red painting' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'API key', $result->error );
	}

	public function test_wp_error_returns_failure(): void {
		$this->mock_http_error( 'Connection refused' );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'Connection refused', $result->error );
	}

	public function test_non_200_response_returns_failure(): void {
		$this->mock_http( 401, (string) wp_json_encode( [ 'error' => [ 'message' => 'Unauthorized' ] ] ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'OpenAI vision error', $result->error );
	}

	public function test_missing_choices_path_returns_failure(): void {
		// Response has no choices[0].message.content.
		$this->mock_http( 200, (string) wp_json_encode( [ 'choices' => [] ] ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'OpenAI vision error', $result->error );
	}

	public function test_inner_content_non_json_returns_failure(): void {
		$this->mock_http( 200, (string) wp_json_encode( [
			'choices' => [
				[
					'message' => [
						'content' => 'This is plain text, not JSON',
					],
				],
			],
		] ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'non-JSON', $result->error );
	}

	public function test_full_happy_path_populates_all_fields(): void {
		$content = [
			'title'         => 'Red on Red',
			'excerpt'       => 'A study in scarlet.',
			'body'          => '<p>Two layers of cadmium red.</p>',
			'tags'          => [ 'abstract', 'red', 'oil' ],
			'alt_text'      => 'A canvas of deep red hues.',
			'medium'        => 'Oil Painting',
			'photo_quality' => [
				'score'  => 8,
				'issues' => [],
			],
		];
		$this->mock_http( 200, $this->make_openai_body( $content ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', 'Red painting' );

		$this->assertTrue( $result->success );
		$this->assertSame( 'Red on Red', $result->title );
		$this->assertSame( 'A study in scarlet.', $result->excerpt );
		$this->assertSame( '<p>Two layers of cadmium red.</p>', $result->body );
		$this->assertSame( [ 'abstract', 'red', 'oil' ], $result->tags );
		$this->assertSame( 'A canvas of deep red hues.', $result->alt_text );
		$this->assertSame( 'Oil Painting', $result->medium );
		$this->assertSame( 8, $result->photo_quality_score );
		$this->assertSame( [], $result->photo_quality_issues );
	}

	public function test_photo_quality_score_and_issues_parsed(): void {
		$content = [
			'title'   => 'Dim Seascape',
			'excerpt' => 'Brooding waters.',
			'body'    => '<p>Waves.</p>',
			'tags'    => [ 'seascape' ],
			'alt_text' => 'Dark painting.',
			'medium'  => 'Oil Painting',
			'photo_quality' => [
				'score'  => 4,
				'issues' => [ 'too dark', 'motion blur' ],
			],
		];
		$this->mock_http( 200, $this->make_openai_body( $content ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertTrue( $result->success );
		$this->assertSame( 4, $result->photo_quality_score );
		$this->assertContains( 'too dark', $result->photo_quality_issues );
		$this->assertContains( 'motion blur', $result->photo_quality_issues );
	}

	public function test_quality_score_clamped_to_zero_minimum(): void {
		$content = [
			'title'    => 'T', 'excerpt' => 'E', 'body' => 'B',
			'tags'     => [], 'alt_text' => 'A', 'medium' => 'Photography',
			'photo_quality' => [ 'score' => -5, 'issues' => [] ],
		];
		$this->mock_http( 200, $this->make_openai_body( $content ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertSame( 0, $result->photo_quality_score );
	}

	public function test_quality_score_clamped_to_ten_maximum(): void {
		$content = [
			'title'    => 'T', 'excerpt' => 'E', 'body' => 'B',
			'tags'     => [], 'alt_text' => 'A', 'medium' => 'Photography',
			'photo_quality' => [ 'score' => 99, 'issues' => [] ],
		];
		$this->mock_http( 200, $this->make_openai_body( $content ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertSame( 10, $result->photo_quality_score );
	}

	public function test_missing_photo_quality_key_defaults_to_zero(): void {
		$content = [
			'title'   => 'T', 'excerpt' => 'E', 'body' => 'B',
			'tags'    => [], 'alt_text' => 'A', 'medium' => 'Photography',
			// No photo_quality key at all.
		];
		$this->mock_http( 200, $this->make_openai_body( $content ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertTrue( $result->success );
		$this->assertSame( 0, $result->photo_quality_score );
		$this->assertSame( [], $result->photo_quality_issues );
	}

	public function test_non_array_photo_quality_value_defaults_to_zero(): void {
		$content = [
			'title'   => 'T', 'excerpt' => 'E', 'body' => 'B',
			'tags'    => [], 'alt_text' => 'A', 'medium' => 'Photography',
			'photo_quality' => 'great', // Non-array — malformed.
		];
		$this->mock_http( 200, $this->make_openai_body( $content ) );

		$result = $this->make_provider()->describe( 'imagedata', 'image/jpeg', '' );

		$this->assertTrue( $result->success );
		$this->assertSame( 0, $result->photo_quality_score );
	}

	// =========================================================================
	// Vision-input downscaling (agnosis_ai_vision_max_width_px, added 2026-07-06)
	// See AnthropicDescribeTest's equivalent section for why this must stay
	// local to a single request — the same invariant applies here.
	// =========================================================================

	public function test_describe_sends_original_image_unchanged_when_downscale_disabled(): void {
		update_option( 'agnosis_ai_vision_max_width_px', 0 );

		$original = 'raw-unmodified-bytes';
		$captured = null;
		$this->mock_http_capturing( 200, $this->make_openai_body( [ 'title' => 'T', 'excerpt' => 'E', 'body' => 'B', 'tags' => [], 'alt_text' => 'A', 'medium' => '' ] ), $captured );

		$this->make_provider()->describe( $original, 'image/jpeg', '' );

		$this->assertSame( $original, $this->extract_sent_image_data( $captured ) );
	}

	// =========================================================================
	// Live medium vocabulary reaches the AI prompt (2026-07-08)
	// =========================================================================

	public function test_describe_sends_live_medium_vocabulary_including_admin_added_term(): void {
		// An admin has added a Medium term via Artwork → Mediums that isn't in
		// the built-in CANONICAL_MEDIUMS seed list — confirms describe() actually
		// calls resolved_system_prompt( PromptConfig::medium_terms() ), not the
		// no-argument (seed-only) form.
		wp_insert_term( 'Ceramics', 'agnosis_medium' );

		$config = new PromptConfig(
			system_prompt:            'Pick a medium from: {medium_list}',
			user_template:            '{artist_prompt}',
			enhancement_instructions: 'Enhance',
			tag_count:                5,
			excerpt_words:            30,
		);
		$provider = new OpenAI( 'sk-test-key', $config );

		$captured = null;
		$this->mock_http_capturing(
			200,
			$this->make_openai_body( [ 'title' => 'T', 'excerpt' => 'E', 'body' => 'B', 'tags' => [], 'alt_text' => 'A', 'medium' => 'Ceramics' ] ),
			$captured
		);

		$provider->describe( 'imagedata', 'image/jpeg', '' );

		$sent_system = $this->extract_sent_system_prompt( $captured );
		$this->assertStringContainsString( 'Ceramics', $sent_system );

		wp_delete_term( (int) get_term_by( 'name', 'Ceramics', 'agnosis_medium' )->term_id, 'agnosis_medium' );
	}

	public function test_describe_downscales_a_wide_image_before_sending(): void {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Requires Imagick.' );
		}

		update_option( 'agnosis_ai_vision_max_width_px', 400 );

		$original = $this->make_test_jpeg( 2000, 1000 );
		$captured = null;
		$this->mock_http_capturing( 200, $this->make_openai_body( [ 'title' => 'T', 'excerpt' => 'E', 'body' => 'B', 'tags' => [], 'alt_text' => 'A', 'medium' => '' ] ), $captured );

		$this->make_provider()->describe( $original, 'image/jpeg', '' );

		$sent = $this->extract_sent_image_data( $captured );
		$this->assertNotSame( $original, $sent, 'The image actually sent must be the downscaled copy, not the original.' );

		$img = new \Imagick();
		$img->readImageBlob( $sent );
		$this->assertSame( 400, $img->getImageWidth() );
		$img->destroy();
	}

	// =========================================================================
	// describe_secondary() — fifth audit §4c: slim pass for secondary gallery
	// images. Same HTTP shape as describe() but a fixed, much shorter system
	// prompt, no artist-context user message, and only alt_text/tags/
	// photo_quality in the response — title/excerpt/body/medium are never
	// populated since this image's own text is never published.
	// =========================================================================

	public function test_describe_secondary_empty_api_key_returns_failure(): void {
		$result = $this->make_provider( '' )->describe_secondary( 'imagedata', 'image/jpeg' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'API key', $result->error );
	}

	public function test_describe_secondary_wp_error_returns_failure(): void {
		$this->mock_http_error( 'Connection refused' );

		$result = $this->make_provider()->describe_secondary( 'imagedata', 'image/jpeg' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'Connection refused', $result->error );
	}

	public function test_describe_secondary_non_200_response_returns_failure(): void {
		$this->mock_http( 401, (string) wp_json_encode( [ 'error' => [ 'message' => 'Unauthorized' ] ] ) );

		$result = $this->make_provider()->describe_secondary( 'imagedata', 'image/jpeg' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'OpenAI vision error', $result->error );
	}

	public function test_describe_secondary_inner_content_non_json_returns_failure(): void {
		$this->mock_http( 200, (string) wp_json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'This is plain text, not JSON' ] ] ],
		] ) );

		$result = $this->make_provider()->describe_secondary( 'imagedata', 'image/jpeg' );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'non-JSON', $result->error );
	}

	public function test_describe_secondary_happy_path_populates_only_alt_text_tags_and_quality(): void {
		$content = [
			'alt_text'      => 'A canvas of deep red hues.',
			'tags'          => [ 'abstract', 'red' ],
			'photo_quality' => [ 'score' => 7, 'issues' => [] ],
		];
		$this->mock_http( 200, $this->make_openai_body( $content ) );

		$result = $this->make_provider()->describe_secondary( 'imagedata', 'image/jpeg' );

		$this->assertTrue( $result->success );
		$this->assertSame( '', $result->title );
		$this->assertSame( '', $result->excerpt );
		$this->assertSame( '', $result->body );
		$this->assertSame( '', $result->medium );
		$this->assertSame( 'A canvas of deep red hues.', $result->alt_text );
		$this->assertSame( [ 'abstract', 'red' ], $result->tags );
		$this->assertSame( 7, $result->photo_quality_score );
		$this->assertSame( [], $result->photo_quality_issues );
	}

	public function test_describe_secondary_quality_score_clamped_to_bounds(): void {
		$this->mock_http( 200, $this->make_openai_body( [
			'alt_text' => 'A', 'tags' => [], 'photo_quality' => [ 'score' => -9, 'issues' => [] ],
		] ) );

		$result = $this->make_provider()->describe_secondary( 'imagedata', 'image/jpeg' );

		$this->assertSame( 0, $result->photo_quality_score );
	}

	public function test_describe_secondary_sends_the_fixed_slim_system_prompt_and_lower_max_tokens(): void {
		$captured = null;
		$this->mock_http_capturing(
			200,
			$this->make_openai_body( [ 'alt_text' => 'A', 'tags' => [] ] ),
			$captured
		);

		$this->make_provider()->describe_secondary( 'imagedata', 'image/jpeg' );

		$payload = json_decode( (string) $captured['body'], true );
		$this->assertSame( PromptConfig::secondary_system_prompt(), $payload['messages'][0]['content'] ?? '' );
		$this->assertSame( 300, $payload['max_tokens'] ?? null, 'Secondary pass uses a much smaller max_tokens ceiling than the primary describe() call (1500).' );
	}

	public function test_describe_secondary_sends_no_artist_context_in_the_user_message(): void {
		// describe_secondary() has no $artist_prompt parameter at all (see
		// ProviderInterface::describe_secondary()) — the user message's content
		// array must contain only the image_url block, nothing else.
		$captured = null;
		$this->mock_http_capturing(
			200,
			$this->make_openai_body( [ 'alt_text' => 'A', 'tags' => [] ] ),
			$captured
		);

		$this->make_provider()->describe_secondary( 'imagedata', 'image/jpeg' );

		$payload = json_decode( (string) $captured['body'], true );
		$this->assertCount( 1, $payload['messages'][1]['content'] ?? [], 'Only one content block (the image) — no separate text block for artist context.' );
		$this->assertSame( 'image_url', $payload['messages'][1]['content'][0]['type'] ?? null );
	}
}
