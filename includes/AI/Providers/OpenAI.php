<?php
/**
 * OpenAI provider — description (GPT-4o Vision) + enhancement (gpt-image-1).
 *
 * @package Agnosis\AI\Providers
 */

declare(strict_types=1);

namespace Agnosis\AI\Providers;

use Agnosis\AI\DescriptionResult;
use Agnosis\AI\EnhancementResult;
use Agnosis\AI\ProviderInterface;

class OpenAI implements ProviderInterface {

	private const CHAT_URL    = 'https://api.openai.com/v1/chat/completions';
	private const IMAGE_URL   = 'https://api.openai.com/v1/images/edits';
	private const VISION_MODEL = 'gpt-4o';
	private const IMAGE_MODEL  = 'gpt-image-1';

	public function __construct( private readonly string $api_key ) {}

	// -------------------------------------------------------------------------
	// Description
	// -------------------------------------------------------------------------

	public function describe( string $image_data, string $mime_type, string $artist_prompt ): DescriptionResult {
		if ( empty( $this->api_key ) ) {
			return DescriptionResult::failure( 'OpenAI API key not configured.' );
		}

		$image_b64  = base64_encode( $image_data );
		$image_url  = 'data:' . $mime_type . ';base64,' . $image_b64;

		$system = <<<PROMPT
You are an art critic and curator with a warm, poetic voice.
Help independent artists present their work to the world.
Respond ONLY with valid JSON — no markdown fences.
{
  "title":    "<Short evocative title, max 10 words>",
  "excerpt":  "<One sentence that makes someone stop scrolling, max 30 words>",
  "body":     "<2-3 paragraphs: what you see, what it evokes, why it matters>",
  "tags":     ["tag1","tag2","tag3","tag4","tag5"],
  "alt_text": "<Visual description for screen readers, max 125 chars>"
}
PROMPT;

		$user_text = "Artist's notes: " . ( $artist_prompt ?: '(none provided)' );

		$body = wp_json_encode( [
			'model'           => self::VISION_MODEL,
			'max_tokens'      => 1024,
			'response_format' => [ 'type' => 'json_object' ],
			'messages'        => [
				[ 'role' => 'system', 'content' => $system ],
				[
					'role'    => 'user',
					'content' => [
						[ 'type' => 'image_url', 'image_url' => [ 'url' => $image_url, 'detail' => 'high' ] ],
						[ 'type' => 'text',      'text'      => $user_text ],
					],
				],
			],
		] );

		if ( false === $body ) {
			return DescriptionResult::failure( 'JSON encoding failed.' );
		}

		$response = wp_remote_post( self::CHAT_URL, [
			'timeout' => 60,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return DescriptionResult::failure( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || ! isset( $data['choices'][0]['message']['content'] ) ) {
			return DescriptionResult::failure( 'OpenAI vision error: ' . wp_json_encode( $data['error'] ?? $data ) );
		}

		$json = json_decode( $data['choices'][0]['message']['content'], true );

		if ( ! is_array( $json ) ) {
			return DescriptionResult::failure( 'OpenAI returned non-JSON content.' );
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

	// -------------------------------------------------------------------------
	// Enhancement
	// -------------------------------------------------------------------------

	public function enhance( string $image_data, string $mime_type, string $instructions ): EnhancementResult {
		if ( empty( $this->api_key ) ) {
			return EnhancementResult::failure( 'OpenAI API key not configured.' );
		}

		// gpt-image-1 edits endpoint expects multipart/form-data.
		$boundary = wp_generate_password( 24, false );
		$body     = $this->build_multipart( $boundary, $image_data, $mime_type, $instructions );

		$response = wp_remote_post( self::IMAGE_URL, [
			'timeout' => 120,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return EnhancementResult::failure( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $data['data'][0]['b64_json'] ) ) {
			return EnhancementResult::failure( 'OpenAI image edit error: ' . wp_json_encode( $data['error'] ?? $data ) );
		}

		$enhanced = base64_decode( $data['data'][0]['b64_json'] );

		return new EnhancementResult(
			image_data: $enhanced,
			mime_type:  'image/png', // gpt-image-1 always returns PNG
			success:    true,
		);
	}

	public function supports_enhancement(): bool {
		return true;
	}

	// -------------------------------------------------------------------------

	private function build_multipart( string $boundary, string $image_data, string $mime, string $prompt ): string {
		$eol  = "\r\n";
		$body = '';

		// image field
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="image"; filename="artwork.png"' . $eol;
		$body .= 'Content-Type: ' . $mime . $eol . $eol;
		$body .= $image_data . $eol;

		// prompt field
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="prompt"' . $eol . $eol;
		$body .= 'Enhance this artwork for web publication. Improve lighting, clarity and colour balance. ' . $prompt . $eol;

		// model field
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="model"' . $eol . $eol;
		$body .= self::IMAGE_MODEL . $eol;

		// size
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="size"' . $eol . $eol;
		$body .= '1024x1024' . $eol;

		// response format
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="response_format"' . $eol . $eol;
		$body .= 'b64_json' . $eol;

		$body .= '--' . $boundary . '--' . $eol;

		return $body;
	}
}
