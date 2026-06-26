<?php
/**
 * WordPress AI Services provider — description only.
 *
 * Delegates to whichever AI service the site administrator has configured
 * via the "AI Services" plugin (wordpress.org/plugins/ai-services).
 * Requires a service with multimodal capability (image understanding).
 *
 * Enhancement is not supported — image generation APIs are not yet part of
 * the AI Services abstraction layer.
 *
 * @package Agnosis\AI\Providers
 * @see https://wordpress.org/plugins/ai-services/
 */

declare(strict_types=1);

namespace Agnosis\AI\Providers;

use Agnosis\AI\DescriptionResult;
use Agnosis\AI\EnhancementResult;
use Agnosis\AI\PromptConfig;
use Agnosis\AI\ProviderInterface;

class WordPressAI implements ProviderInterface {

	public function __construct( private readonly PromptConfig $config ) {}

	public function describe( string $image_data, string $mime_type, string $artist_prompt ): DescriptionResult {
		if ( ! function_exists( 'ai_services' ) ) {
			return DescriptionResult::failure(
				'WordPress AI Services plugin is not active. Install it from wordpress.org/plugins/ai-services and configure at least one provider.'
			);
		}

		// Resolve a service that can handle multimodal (vision) requests.
		try {
			$service = ai_services()->get_available_service( [
				'capabilities' => [ 'text_generation', 'multimodal' ],
			] );
		} catch ( \Throwable $e ) {
			return DescriptionResult::failure( 'WordPress AI Services error: ' . $e->getMessage() );
		}

		if ( ! $service ) {
			return DescriptionResult::failure(
				'No AI service with multimodal capability found. Configure one under Settings → AI Services.'
			);
		}

		// Build content parts: image first, then the artist note.
		$parts = [
			[
				'type'      => 'inline_data',
				'mime_type' => $mime_type,
				'data'      => base64_encode( $image_data ),
			],
			[
				'type' => 'text',
				'text' => $this->config->build_user_message( $artist_prompt ),
			],
		];

		try {
			$model = $service->get_model( [
				'feature'            => 'agnosis-description',
				'system_instruction' => $this->config->resolved_system_prompt(),
			] );

			$response = $model->generate_text( $parts );
		} catch ( \Throwable $e ) {
			return DescriptionResult::failure( 'WordPress AI generation failed: ' . $e->getMessage() );
		}

		$text = $this->extract_text( $response );

		if ( null === $text || '' === $text ) {
			return DescriptionResult::failure( 'WordPress AI returned an empty response.' );
		}

		$json = json_decode( $text, true );

		// Some models wrap JSON in markdown code fences — strip them.
		if ( ! is_array( $json ) && preg_match( '/```(?:json)?\s*(\{.+\})\s*```/s', $text, $m ) ) {
			$json = json_decode( $m[1], true );
		}

		if ( ! is_array( $json ) ) {
			return DescriptionResult::failure( 'WordPress AI returned a non-JSON response.' );
		}

		return new DescriptionResult(
			title:    sanitize_text_field( $json['title']    ?? '' ),
			excerpt:  sanitize_text_field( $json['excerpt']  ?? '' ),
			body:     wp_kses_post( $json['body']     ?? '' ),
			tags:     array_map( 'sanitize_text_field', (array) ( $json['tags'] ?? [] ) ),
			alt_text: sanitize_text_field( $json['alt_text'] ?? '' ),
			success:  true,
		);
	}

	public function enhance( string $image_data, string $mime_type, string $instructions ): EnhancementResult {
		return EnhancementResult::failure(
			'WordPress AI Services provider does not support image enhancement.'
		);
	}

	public function supports_enhancement(): bool {
		return false;
	}

	// -------------------------------------------------------------------------

	/**
	 * Extract plain text from whatever shape the AI Services response object has.
	 * The plugin's response shape may differ across versions, so we try several
	 * accessor patterns before giving up.
	 *
	 * @param mixed $response Raw response from generate_text().
	 */
	private function extract_text( mixed $response ): ?string {
		if ( is_string( $response ) ) {
			return $response;
		}

		// AI Services v0.5+ Candidates object.
		if ( is_object( $response ) ) {
			if ( method_exists( $response, 'get_text' ) ) {
				return (string) $response->get_text();
			}
			if ( method_exists( $response, 'to_array' ) ) {
				$arr = $response->to_array();
				return $arr[0]['content']['parts'][0]['text'] ?? null;
			}
		}

		// Plain array response.
		if ( is_array( $response ) ) {
			return $response['text']
				?? $response[0]['text']
				?? $response['candidates'][0]['content']['parts'][0]['text']
				?? null;
		}

		return null;
	}
}
