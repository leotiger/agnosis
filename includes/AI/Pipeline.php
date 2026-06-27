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
use Agnosis\AI\Providers\WordPressAI;

class Pipeline {

	private ProviderInterface $description_provider;
	private ?ProviderInterface $enhancement_provider;
	private PromptConfig $config;

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
		// Step 1 — Describe (also assesses photo quality as part of the same vision call).
		$description = $this->description_provider->describe( $image_data, $mime_type, $artist_prompt );

		$quality_score  = $description->photo_quality_score;
		$quality_issues = $description->photo_quality_issues;

		// Step 2 — Conditionally enhance.
		// Enhancement only runs when:
		//   • A provider is configured.
		//   • Description succeeded (we need the body for contextual instructions).
		//   • The photo quality score is below the configured threshold (default 7).
		//     Score 0 means the provider could not assess quality (e.g. text-only) — skip.
		$threshold     = $this->config->quality_threshold;
		$needs_enhance = null !== $this->enhancement_provider
			&& $description->success
			&& $quality_score > 0
			&& $quality_score < $threshold;

		$enhanced_data = $image_data;
		$enhanced_mime = $mime_type;
		$enhanced      = false;

		if ( $needs_enhance ) {
			// Build instructions targeted at the specific issues detected, with the
			// base enhancement constraints always present as the final section.
			$instructions = $this->config->build_targeted_enhancement_instructions( $quality_issues );

			// Append the AI-generated body as additional visual context for the enhancer.
			if ( $description->body ) {
				$instructions .= "\n\n" . $description->body;
			}

			$enhancement = $this->enhancement_provider->enhance( $image_data, $mime_type, $instructions );

			if ( $enhancement->success && ! empty( $enhancement->image_data ) ) {
				$enhanced_data = $enhancement->image_data;
				$enhanced_mime = $enhancement->mime_type;
				$enhanced      = true;
			}
		}

		return [
			'filename'             => $filename,
			'original_data'        => $image_data,
			'enhanced_data'        => $enhanced_data,
			'mime_type'            => $enhanced_mime,
			'title'                => $description->title,
			'excerpt'              => $description->excerpt,
			'body'                 => $description->body,
			'tags'                 => $description->tags,
			'alt_text'             => $description->alt_text,
			'description_ok'       => $description->success,
			'error'                => $description->error,
			'photo_quality_score'  => $quality_score,
			'photo_quality_issues' => $quality_issues,
			'enhanced'             => $enhanced,
		];
	}

	/**
	 * Plain-text chat — delegates to the description provider's cheap text model.
	 *
	 * Used for lightweight classification (e.g. duplicate detection) that does
	 * not require image input. Returns '' on failure.
	 */
	public function chat( string $prompt ): string {
		return $this->description_provider->chat( $prompt );
	}

	/**
	 * Polish a block of text — fix spelling and grammar, make minimal
	 * improvements without changing meaning or adding content.
	 *
	 * Returns the improved text, or the original unchanged if the call fails.
	 */
	public function polish( string $text ): string {
		if ( empty( trim( $text ) ) ) {
			return $text;
		}
		$prompt   = "Fix spelling and grammar in the following text. Make only minimal improvements — do not change the meaning, tone, or add any content. Return only the corrected text, nothing else.\n\n" . $text;
		$polished = $this->description_provider->chat( $prompt );
		return $polished ?: $text;
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

		if ( 'openai' === $provider ) {
			$key = (string) get_option( 'agnosis_openai_api_key', '' );
			if ( ! empty( $key ) ) {
				$image_model = (string) get_option( 'agnosis_openai_image_model', 'gpt-image-1' );
				return new OpenAI( $key, $this->config, 'gpt-4o', $image_model );
			}
		}

		// 'auto' — use OpenAI if a key is configured; otherwise no enhancement.
		$openai_key = (string) get_option( 'agnosis_openai_api_key', '' );
		if ( ! empty( $openai_key ) ) {
			$image_model = (string) get_option( 'agnosis_openai_image_model', 'gpt-image-1' );
			return new OpenAI( $openai_key, $this->config, 'gpt-4o', $image_model );
		}

		return null; // No enhancement — original image used.
	}
}
