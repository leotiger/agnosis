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
use Agnosis\AI\ProviderInterface;

class Anthropic implements ProviderInterface {

	private const API_URL = 'https://api.anthropic.com/v1/messages';
	private const MODEL   = 'claude-opus-4-8'; // vision-capable

	public function __construct( private readonly string $api_key ) {}

	public function describe( string $image_data, string $mime_type, string $artist_prompt ): DescriptionResult {
		if ( empty( $this->api_key ) ) {
			return DescriptionResult::failure( 'Anthropic API key not configured.' );
		}

		$image_b64 = base64_encode( $image_data );

		$system_prompt =
			'You are an art critic and curator with a warm, poetic voice.' . "\n"
			. 'Your task is to help independent artists present their work to the world —' . "\n"
			. 'people who are great at creating but need help being seen.' . "\n\n"
			. 'When describing artwork, write as if you deeply understand and respect the creative act.' . "\n"
			. 'Avoid jargon. Be accessible. Be honest. Be beautiful.' . "\n\n"
			. 'Always respond with valid JSON in exactly this structure:' . "\n"
			. '{' . "\n"
			. '  "title": "Short evocative title (max 10 words)",' . "\n"
			. '  "excerpt": "One sentence that makes someone stop scrolling (max 30 words)",' . "\n"
			. '  "body": "2-3 paragraphs. What you see. What it evokes. Why it matters. Written for someone who loves art but is not an expert.",' . "\n"
			. '  "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"],' . "\n"
			. '  "alt_text": "Precise visual description for screen readers and search engines (max 125 chars)"' . "\n"
			. '}';

		$user_content = "Here is the artist's own description of the work:\n\n";
		$user_content .= empty( $artist_prompt )
			? "(The artist left no description — let the work speak for itself.)"
			: $artist_prompt;

		$body = wp_json_encode( [
			'model'      => self::MODEL,
			'max_tokens' => 1024,
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

		return new DescriptionResult(
			title:    sanitize_text_field( $json['title']    ?? '' ),
			excerpt:  sanitize_text_field( $json['excerpt']  ?? '' ),
			body:     wp_kses_post( $json['body']     ?? '' ),
			tags:     array_map( 'sanitize_text_field', $json['tags']     ?? [] ),
			alt_text: sanitize_text_field( $json['alt_text'] ?? '' ),
			success:  true,
		);
	}

	public function enhance( string $image_data, string $mime_type, string $instructions ): EnhancementResult {
		// Claude does not produce enhanced images.
		return EnhancementResult::failure( 'Anthropic provider does not support image enhancement.' );
	}

	public function supports_enhancement(): bool {
		return false;
	}
}
