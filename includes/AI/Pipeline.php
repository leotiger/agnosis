<?php
/**
 * AI Pipeline orchestrator.
 *
 * Receives a parsed submission, runs each image through:
 *   1. Description pass  (Claude preferred; falls back to OpenAI)
 *   2. Enhancement pass  (OpenAI GPT-4o / DALL-E 3 or Stability AI)
 *
 * Returns a structured result ready for PostCreator.
 *
 * @package Agnosis\AI
 */

declare(strict_types=1);

namespace Agnosis\AI;

use Agnosis\AI\Providers\Anthropic;
use Agnosis\AI\Providers\OpenAI;
use Agnosis\AI\Providers\StabilityAI;

class Pipeline {

	private ProviderInterface $description_provider;
	private ?ProviderInterface $enhancement_provider;

	public function __construct() {
		$this->description_provider  = $this->resolve_description_provider();
		$this->enhancement_provider  = $this->resolve_enhancement_provider();
	}

	/**
	 * Process all attachments in a submission.
	 *
	 * @param array<string, mixed> $submission  Parsed submission from Parser.
	 * @return array<int, array<string, mixed>>  One result per attachment.
	 */
	public function process( array $submission ): array {
		$results      = [];
		$artist_prompt = $submission['description'] ?? '';

		foreach ( $submission['attachments'] as $attachment ) {
			$result = $this->process_single(
				$attachment['data'],
				$attachment['mime'],
				$attachment['filename'],
				$artist_prompt
			);
			$results[] = $result;
		}

		return $results;
	}

	// -------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function process_single(
		string $image_data,
		string $mime_type,
		string $filename,
		string $artist_prompt
	): array {
		// Step 1 — Describe.
		$description = $this->description_provider->describe( $image_data, $mime_type, $artist_prompt );

		// Step 2 — Enhance (if provider available and description succeeded).
		$enhanced_data = $image_data; // fallback: original
		$enhanced_mime = $mime_type;

		if ( null !== $this->enhancement_provider && $description->success ) {
			$instructions = $description->body ?: $artist_prompt;
			$enhancement  = $this->enhancement_provider->enhance( $image_data, $mime_type, $instructions );

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
		$provider = get_option( 'agnosis_ai_provider', 'openai' );

		// Prefer Anthropic for description if key is set.
		if ( $provider === 'anthropic' || ! empty( get_option( 'agnosis_anthropic_api_key' ) ) ) {
			$key = get_option( 'agnosis_anthropic_api_key', '' );
			if ( ! empty( $key ) ) {
				return new Anthropic( $key );
			}
		}

		// Fallback to OpenAI.
		$key = get_option( 'agnosis_openai_api_key', '' );
		return new OpenAI( $key );
	}

	private function resolve_enhancement_provider(): ?ProviderInterface {
		// Stability AI — best for image enhancement.
		$stability_key = get_option( 'agnosis_stability_api_key', '' );
		if ( ! empty( $stability_key ) ) {
			return new StabilityAI( $stability_key );
		}

		// Fallback: OpenAI GPT-4o image edit.
		$openai_key = get_option( 'agnosis_openai_api_key', '' );
		if ( ! empty( $openai_key ) ) {
			$provider = new OpenAI( $openai_key );
			if ( $provider->supports_enhancement() ) {
				return $provider;
			}
		}

		return null; // No enhancement available; original image is used.
	}
}
