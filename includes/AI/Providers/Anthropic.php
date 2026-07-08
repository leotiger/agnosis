<?php
/**
 * Anthropic Claude provider — description only.
 *
 * Uses claude-opus-4 (vision) to analyse artwork and produce
 * publication-ready title, excerpt, body, tags and alt text.
 * Claude does not perform image enhancement; supports_enhancement() → false.
 *
 * @package Agnosis\AI\Providers
 */

declare(strict_types=1);

namespace Agnosis\AI\Providers;

use Agnosis\AI\DescriptionResult;
use Agnosis\AI\EnhancementResult;
use Agnosis\AI\MediaAdapter;
use Agnosis\AI\PromptConfig;
use Agnosis\AI\ProviderInterface;

class Anthropic implements ProviderInterface {

	private const API_URL       = 'https://api.anthropic.com/v1/messages';
	private const DEFAULT_MODEL = 'claude-opus-4-8'; // vision-capable

	public function __construct(
		private readonly string $api_key,
		private readonly PromptConfig $config,
		private readonly string $model = self::DEFAULT_MODEL,
	) {}

	public function describe( string $image_data, string $mime_type, string $artist_prompt ): DescriptionResult {
		if ( empty( $this->api_key ) ) {
			return DescriptionResult::failure( 'Anthropic API key not configured.' );
		}

		// Downscaled copy for THIS request only — never reassign $image_data
		// itself, since callers (Pipeline::process_single()) reuse that same
		// variable for image enhancement and for the actual published file.
		// See MediaAdapter::maybe_downscale_for_vision()'s doc.
		$vision_image_data = MediaAdapter::maybe_downscale_for_vision( $image_data, $mime_type );

		$image_b64    = base64_encode( $vision_image_data );
		$system_prompt = $this->config->resolved_system_prompt( PromptConfig::medium_terms() );
		$user_content  = $this->config->build_user_message( $artist_prompt );

		$body = wp_json_encode( [
			'model'      => $this->model,
			'max_tokens' => 1500,
			'system'     => $system_prompt,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => [
						[
							'type'  => 'image',
							'source' => [
								'type'       => 'base64',
								'media_type' => $mime_type,
								'data'       => $image_b64,
							],
						],
						[
							'type' => 'text',
							'text' => $user_content,
						],
					],
				],
			],
		] );

		if ( false === $body ) {
			return DescriptionResult::failure( 'JSON encoding failed.' );
		}

		$response = wp_remote_post( self::API_URL, [
			'timeout' => 60,
			'headers' => [
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return DescriptionResult::failure( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code !== 200 || ! isset( $data['content'][0]['text'] ) ) {
			return DescriptionResult::failure( 'Anthropic API error: ' . $raw );
		}

		$json = json_decode( $data['content'][0]['text'], true );

		if ( ! is_array( $json ) ) {
			return DescriptionResult::failure( 'Claude returned non-JSON response.' );
		}

		$quality        = is_array( $json['photo_quality'] ?? null ) ? $json['photo_quality'] : [];
		$quality_score  = max( 0, min( 10, (int) ( $quality['score'] ?? 0 ) ) );
		$quality_issues = array_map( 'sanitize_text_field', (array) ( $quality['issues'] ?? [] ) );

		return new DescriptionResult(
			title:                sanitize_text_field( $json['title']    ?? '' ),
			excerpt:              sanitize_text_field( $json['excerpt']  ?? '' ),
			body:                 wp_kses_post( $json['body']            ?? '' ),
			tags:                 array_map( 'sanitize_text_field', (array) ( $json['tags'] ?? [] ) ),
			alt_text:             sanitize_text_field( $json['alt_text'] ?? '' ),
			success:              true,
			photo_quality_score:  $quality_score,
			photo_quality_issues: $quality_issues,
			medium:               sanitize_text_field( $json['medium']   ?? '' ),
		);
	}

	public function enhance( string $image_data, string $mime_type, string $instructions ): EnhancementResult {
		// Claude does not produce enhanced images.
		return EnhancementResult::failure( 'Anthropic provider does not support image enhancement.' );
	}

	public function supports_enhancement(): bool {
		return false;
	}

	public function chat( string $prompt ): string {
		if ( empty( $this->api_key ) ) {
			return '';
		}

		$body = wp_json_encode( [
			'model'      => 'claude-haiku-4-5-20251001', // cheapest/fastest model
			'max_tokens' => 1024,
			'messages'   => [
				[ 'role' => 'user', 'content' => $prompt ],
			],
		] );

		if ( false === $body ) {
			return '';
		}

		$response = wp_remote_post( self::API_URL, [
			'timeout' => 30,
			'headers' => [
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return trim( (string) ( $data['content'][0]['text'] ?? '' ) );
	}

	/**
	 * Anthropic does not support audio transcription.
	 * Use the OpenAI provider's Whisper integration for audio files.
	 */
	public function transcribe( string $audio_data, string $mime_type ): string {
		return '';
	}

	public function supports_audio(): bool {
		return false;
	}
}
