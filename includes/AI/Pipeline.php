<?php
/**
 * AI Pipeline orchestrator.
 *
 * Receives a parsed submission, runs each image through:
 *   1. Description pass  — configured description provider.
 *   2. Enhancement pass  — configured enhancement provider (may be null / different vendor).
 *
 * Provider selection is independent: an operator can use Anthropic for text
 * and OpenAI for image enhancement, or WordPress AI for both, etc.
 *
 * @package Agnosis\AI
 */

declare(strict_types=1);

namespace Agnosis\AI;

use Agnosis\AI\Providers\Anthropic;
use Agnosis\AI\Providers\OpenAI;
use Agnosis\AI\Providers\StabilityAI;
use Agnosis\AI\Providers\WordPressAI;

class Pipeline {

	private ProviderInterface  $description_provider;
	private ?ProviderInterface $enhancement_provider;
	private PromptConfig       $config;

	public function __construct() {
		$this->config               = PromptConfig::from_options();
		$this->description_provider = $this->resolve_description_provider();
		$this->enhancement_provider = $this->resolve_enhancement_provider();
	}

	/**
	 * Process all attachments in a submission.
	 *
	 * @param array<string, mixed> $submission Parsed submission from Parser.
	 * @return array<int, array<string, mixed>> One result per attachment.
	 */
	public function process( array $submission ): array {
		$results        = [];
		$artist_context = $this->build_artist_context( $submission );

		foreach ( $submission['attachments'] as $attachment ) {
			$results[] = $this->process_single(
				$attachment['data'],
				$attachment['mime'],
				$attachment['filename'],
				$artist_context
			);
		}

		return $results;
	}

	/**
	 * Build a structured context string from the full email submission.
	 *
	 * Includes the subject line (which often hints at a title) and the email
	 * body (the artist's own words). Both are passed to the AI so it can weigh
	 * them together with the image.
	 *
	 * @param array<string, mixed> $submission Parsed email submission.
	 */
	private function build_artist_context( array $submission ): string {
		$parts = [];

		$subject = trim( (string) ( $submission['subject'] ?? '' ) );
		if ( ! empty( $subject ) ) {
			$parts[] = 'Email subject: ' . $subject;
		}

		$body = trim( (string) ( $submission['description'] ?? '' ) );
		if ( ! empty( $body ) ) {
			$label   = ! empty( $subject ) ? "Artist's message:" : "Artist's note:";
			$parts[] = $label . "\n" . $body;
		}

		return implode( "\n\n", $parts );
	}

	// -------------------------------------------------------------------------

	/** @return array<string, mixed> */
	private function process_single(
		string $image_data,
		string $mime_type,
		string $filename,
		string $artist_prompt
	): array {
		// Step 1 — Describe.
		$description = $this->description_provider->describe( $image_data, $mime_type, $artist_prompt );

		// Step 2 — Enhance (skip if no provider or description failed).
		$enhanced_data = $image_data;
		$enhanced_mime = $mime_type;

		if ( null !== $this->enhancement_provider && $description->success ) {
			// Pass configured enhancement instructions; append description body as context.
			$instructions = $this->config->enhancement_instructions
				. ( $description->body ? "\n\n" . $description->body : '' );

			$enhancement = $this->enhancement_provider->enhance( $image_data, $mime_type, $instructions );

			if ( $enhancement->success && ! empty( $enhancement->image_data ) ) {
				$enhanced_data = $enhancement->image_data;
				$enhanced_mime = $enhancement->mime_type;
			}
		}

		return [
			'filename'       => $filename,
			'original_data'  => $image_data,
			'enhanced_data'  => $enhanced_data,
			'mime_type'      => $enhanced_mime,
			'title'          => $description->title,
			'excerpt'        => $description->excerpt,
			'body'           => $description->body,
			'tags'           => $description->tags,
			'alt_text'       => $description->alt_text,
			'description_ok' => $description->success,
			'error'          => $description->error,
		];
	}

	// -------------------------------------------------------------------------
	// Provider resolution
	// -------------------------------------------------------------------------

	private function resolve_description_provider(): ProviderInterface {
		$provider = get_option( 'agnosis_description_provider', 'openai' );

		switch ( $provider ) {
			case 'anthropic':
				$key   = (string) get_option( 'agnosis_anthropic_api_key', '' );
				$model = (string) get_option( 'agnosis_anthropic_model', 'claude-opus-4-8' );
				return new Anthropic( $key, $this->config, $model );

			case 'wp_ai':
				return new WordPressAI( $this->config );

			case 'openai':
			default:
				$key   = (string) get_option( 'agnosis_openai_api_key', '' );
				$model = (string) get_option( 'agnosis_openai_description_model', 'gpt-4o' );
				return new OpenAI( $key, $this->config, $model );
		}
	}

	private function resolve_enhancement_provider(): ?ProviderInterface {
		$provider = get_option( 'agnosis_enhancement_provider', 'auto' );

		if ( 'none' === $provider ) {
			return null;
		}

		if ( 'stability' === $provider ) {
			$key = (string) get_option( 'agnosis_stability_api_key', '' );
			if ( ! empty( $key ) ) {
				return new StabilityAI( $key );
			}
		}

		if ( 'wp_ai' === $provider ) {
			return new WordPressAI( $this->config );
		}

		if ( 'openai' === $provider ) {
			$key = (string) get_option( 'agnosis_openai_api_key', '' );
			if ( ! empty( $key ) ) {
				$image_model = (string) get_option( 'agnosis_openai_image_model', 'gpt-image-1' );
				return new OpenAI( $key, $this->config, 'gpt-4o', $image_model );
			}
		}

		// 'auto' — pick the best available provider from configured keys.
		$stability_key = (string) get_option( 'agnosis_stability_api_key', '' );
		if ( ! empty( $stability_key ) ) {
			return new StabilityAI( $stability_key );
		}

		$openai_key = (string) get_option( 'agnosis_openai_api_key', '' );
		if ( ! empty( $openai_key ) ) {
			$image_model = (string) get_option( 'agnosis_openai_image_model', 'gpt-image-1' );
			return new OpenAI( $openai_key, $this->config, 'gpt-4o', $image_model );
		}

		return null; // No enhancement — original image used.
	}
}
