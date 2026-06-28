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
}
