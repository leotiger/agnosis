<?php
/**
 * Integration tests — OpenAI::chat()'s max_tokens sizing (audit §5b) and
 * text_model configurability (audit §5c).
 *
 * §5b: chat() previously hardcoded max_tokens => 1024, which was enough for
 * a short reply but too small for SubmissionTranslator::translate_fields()'s
 * JSON-envelope batch translation of a long biography body — the response
 * hit the cap mid-JSON, json_decode() failed, and the caller silently fell
 * back to the untranslated original on a call that was still billed in full.
 * max_tokens is now sized from the prompt itself (floor 1024, cap 8192).
 *
 * §5c: chat() also hardcoded its model ('gpt-4o-mini') as a literal, with no
 * operator lever — unlike the vision/image models, which have been
 * configurable since they were introduced. The constructor now accepts a
 * $text_model param (still defaulting to 'gpt-4o-mini'), which chat() sends
 * instead of the literal.
 *
 * All HTTP calls are intercepted via the pre_http_request filter so no real
 * network requests are made — same harness as OpenAIDescribeTest.php.
 *
 * @package Agnosis\Tests\Integration\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\AI;

use Agnosis\AI\PromptConfig;
use Agnosis\AI\Providers\OpenAI;

class OpenAIChatTest extends \WP_UnitTestCase {

	/** @var callable|null */
	private $http_filter = null;

	protected function tearDown(): void {
		if ( $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}
		parent::tearDown();
	}

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

	private function make_provider( string $api_key = 'sk-test-key', ?string $text_model = null ): OpenAI {
		return null === $text_model
			? new OpenAI( $api_key, $this->make_config() )
			: new OpenAI( $api_key, $this->make_config(), text_model: $text_model );
	}

	/**
	 * Registers a pre_http_request filter that captures the outgoing $args
	 * into $captured by reference and returns a canned successful response.
	 *
	 * @param array<string, mixed>|null $captured
	 */
	private function mock_http_capturing( ?array &$captured ): void {
		$this->http_filter = function ( $preempt, $args ) use ( &$captured ) {
			$captured = $args;
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => wp_json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'ok' ] ] ] ] ),
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	private function sent_max_tokens( array $args ): ?int {
		$payload = json_decode( (string) $args['body'], true );
		return $payload['max_tokens'] ?? null;
	}

	private function sent_model( array $args ): ?string {
		$payload = json_decode( (string) $args['body'], true );
		return $payload['model'] ?? null;
	}

	public function test_short_prompt_uses_the_1024_floor(): void {
		$captured = null;
		$this->mock_http_capturing( $captured );

		$this->make_provider()->chat( 'Translate to Spanish.' );

		$this->assertSame( 1024, $this->sent_max_tokens( $captured ) );
	}

	public function test_long_prompt_raises_max_tokens_above_the_old_flat_cap(): void {
		// ~4000 chars, roughly a long biography body wrapped in the
		// translate_fields() JSON-envelope prompt — the exact case that used
		// to truncate mid-JSON under the old flat 1024 ceiling.
		$long_prompt = str_repeat( 'Lorem ipsum dolor sit amet. ', 150 );

		$captured = null;
		$this->mock_http_capturing( $captured );

		$this->make_provider()->chat( $long_prompt );

		$this->assertGreaterThan( 1024, $this->sent_max_tokens( $captured ) );
	}

	public function test_max_tokens_is_capped_at_8192_for_a_very_long_prompt(): void {
		$very_long_prompt = str_repeat( 'Lorem ipsum dolor sit amet. ', 5000 );

		$captured = null;
		$this->mock_http_capturing( $captured );

		$this->make_provider()->chat( $very_long_prompt );

		$this->assertSame( 8192, $this->sent_max_tokens( $captured ) );
	}

	// audit §5c
	public function test_chat_uses_the_default_text_model_when_none_configured(): void {
		$captured = null;
		$this->mock_http_capturing( $captured );

		$this->make_provider()->chat( 'Translate to Spanish.' );

		$this->assertSame( 'gpt-4o-mini', $this->sent_model( $captured ) );
	}

	public function test_chat_uses_the_configured_text_model(): void {
		$captured = null;
		$this->mock_http_capturing( $captured );

		$this->make_provider( text_model: 'gpt-5-nano' )->chat( 'Translate to Spanish.' );

		$this->assertSame( 'gpt-5-nano', $this->sent_model( $captured ) );
	}
}
