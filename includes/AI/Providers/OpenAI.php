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
use Agnosis\AI\MediaAdapter;
use Agnosis\AI\PromptConfig;
use Agnosis\AI\ProviderInterface;

class OpenAI implements ProviderInterface {

	private const CHAT_URL             = 'https://api.openai.com/v1/chat/completions';
	private const IMAGE_URL            = 'https://api.openai.com/v1/images/edits';
	private const AUDIO_URL            = 'https://api.openai.com/v1/audio/transcriptions';
	private const DEFAULT_VISION_MODEL = 'gpt-4o';
	private const DEFAULT_IMAGE_MODEL  = 'gpt-image-1';
	// Audit §5c: was inlined directly in chat() as a literal, with no
	// operator lever — unlike the vision/image models above, which have been
	// configurable (Settings → AI Providers) since they were introduced. Now
	// just the constructor default; Pipeline/SubmissionTranslator both pass
	// the actual configured agnosis_vendor_value (agnosis_openai_text_model option) explicitly.
	private const DEFAULT_TEXT_MODEL   = 'gpt-4o-mini';
	private const WHISPER_MODEL        = 'whisper-1';

	public function __construct(
		private readonly string $api_key,
		private readonly PromptConfig $config,
		private readonly string $vision_model = self::DEFAULT_VISION_MODEL,
		private readonly string $image_model = self::DEFAULT_IMAGE_MODEL,
		private readonly string $text_model = self::DEFAULT_TEXT_MODEL,
	) {}

	// -------------------------------------------------------------------------
	// Description
	// -------------------------------------------------------------------------

	public function describe( string $image_data, string $mime_type, string $artist_prompt ): DescriptionResult {
		if ( empty( $this->api_key ) ) {
			return DescriptionResult::failure( 'OpenAI API key not configured.' );
		}

		// Downscaled copy for THIS request only — never reassign $image_data
		// itself, since callers (Pipeline::process_single()) reuse that same
		// variable for image enhancement and for the actual published file.
		// See MediaAdapter::maybe_downscale_for_vision()'s doc.
		$vision_image_data = MediaAdapter::maybe_downscale_for_vision( $image_data, $mime_type );

		$image_b64  = base64_encode( $vision_image_data );
		$image_url  = 'data:' . $mime_type . ';base64,' . $image_b64;

		$system    = $this->config->resolved_system_prompt( PromptConfig::medium_terms() );
		$user_text = $this->config->build_user_message( $artist_prompt );

		$body = wp_json_encode( [
			'model'           => $this->vision_model,
			'max_tokens'      => 1500,
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

	/**
	 * Slim description pass for secondary gallery images (fifth audit §4c) —
	 * see ProviderInterface::describe_secondary()'s docblock for the full
	 * rationale. Same vision call shape as describe() (still 'detail' =>
	 * 'high' — the audit's own cost model is explicit that image tokens
	 * dominate and stay; only the text side shrinks), but a fixed, much
	 * shorter system prompt and no artist-context user message, and a far
	 * lower max_tokens ceiling since the JSON response itself is a few dozen
	 * tokens instead of a full title/excerpt/body.
	 */
	public function describe_secondary( string $image_data, string $mime_type ): DescriptionResult {
		if ( empty( $this->api_key ) ) {
			return DescriptionResult::failure( 'OpenAI API key not configured.' );
		}

		$vision_image_data = MediaAdapter::maybe_downscale_for_vision( $image_data, $mime_type );
		$image_b64         = base64_encode( $vision_image_data );
		$image_url         = 'data:' . $mime_type . ';base64,' . $image_b64;

		$body = wp_json_encode( [
			'model'           => $this->vision_model,
			'max_tokens'      => 300,
			'response_format' => [ 'type' => 'json_object' ],
			'messages'        => [
				[ 'role' => 'system', 'content' => PromptConfig::secondary_system_prompt() ],
				[
					'role'    => 'user',
					'content' => [
						[ 'type' => 'image_url', 'image_url' => [ 'url' => $image_url, 'detail' => 'high' ] ],
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

		$quality        = is_array( $json['photo_quality'] ?? null ) ? $json['photo_quality'] : [];
		$quality_score  = max( 0, min( 10, (int) ( $quality['score'] ?? 0 ) ) );
		$quality_issues = array_map( 'sanitize_text_field', (array) ( $quality['issues'] ?? [] ) );

		return new DescriptionResult(
			title:                '',
			excerpt:              '',
			body:                 '',
			tags:                 array_map( 'sanitize_text_field', (array) ( $json['tags'] ?? [] ) ),
			alt_text:             sanitize_text_field( $json['alt_text'] ?? '' ),
			success:              true,
			photo_quality_score:  $quality_score,
			photo_quality_issues: $quality_issues,
			medium:               '',
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

	public function chat( string $prompt ): string {
		if ( empty( $this->api_key ) ) {
			return '';
		}

		$body = wp_json_encode( [
			'model'      => $this->text_model, // audit §5c: operator-configurable, was a hardcoded literal
			// Sized from the prompt itself rather than a flat cap (audit §5b) —
			// a flat 1024 was enough for a short chat reply but not for
			// SubmissionTranslator::translate_fields()'s JSON-envelope batch
			// translation of a long biography body: once translated content
			// approached ~700 words, the response hit the cap mid-JSON, the
			// parse failed, and the caller silently fell back to publishing
			// the untranslated original — a paid call that still produced no
			// usable output. Output tokens are billed as used, not reserved,
			// so sizing generously costs nothing when the ceiling isn't
			// needed. ~4 chars/token is the standard rough estimate; the 1.5x
			// multiplier leaves room for language expansion (some target
			// languages render longer than the English source) plus the JSON
			// envelope's own key/quote/brace overhead. Floored at the old
			// 1024 (a short reply never needed more) and capped at 8192
			// against a runaway prompt.
			'max_tokens' => max( 1024, min( 8192, (int) ceil( strlen( $prompt ) / 4 * 1.5 ) ) ),
			'messages'   => [
				[ 'role' => 'user', 'content' => $prompt ],
			],
		] );

		if ( false === $body ) {
			return '';
		}

		$response = wp_remote_post( self::CHAT_URL, [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return trim( (string) ( $data['choices'][0]['message']['content'] ?? '' ) );
	}

	// -------------------------------------------------------------------------
	// Audio
	// -------------------------------------------------------------------------

	/**
	 * Transcribe audio binary to text using OpenAI Whisper.
	 *
	 * Uses multipart/form-data upload — the same pattern as the image edits endpoint.
	 * Supported audio formats: flac, mp3, mp4, mpeg, mpga, m4a, ogg, wav, webm.
	 *
	 * @param string $audio_data  Raw binary of the audio file.
	 * @param string $mime_type   e.g. 'audio/mpeg'.
	 * @return string             Transcript text, or '' on failure.
	 */
	public function transcribe( string $audio_data, string $mime_type ): string {
		if ( empty( $this->api_key ) ) {
			return '';
		}

		// Derive a sensible filename extension from the MIME type.
		$ext_map   = [
			'audio/mpeg'  => 'mp3',
			'audio/mp4'   => 'm4a',
			'audio/ogg'   => 'ogg',
			'audio/wav'   => 'wav',
			'audio/webm'  => 'webm',
			'audio/flac'  => 'flac',
			'audio/x-m4a' => 'm4a',
		];
		$ext       = $ext_map[ $mime_type ] ?? 'mp3';
		$filename  = 'audio.' . $ext;

		$boundary = wp_generate_password( 24, false );
		$eol      = "\r\n";
		$body     = '';

		// file field
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
		$body .= 'Content-Type: ' . $mime_type . $eol . $eol;
		$body .= $audio_data . $eol;

		// model field
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="model"' . $eol . $eol;
		$body .= self::WHISPER_MODEL . $eol;

		// response_format — plain text is cheapest and simplest
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="response_format"' . $eol . $eol;
		$body .= 'text' . $eol;

		$body .= '--' . $boundary . '--' . $eol;

		$response = wp_remote_post( self::AUDIO_URL, [
			'timeout' => 120,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return '';
		}

		return trim( wp_remote_retrieve_body( $response ) );
	}

	public function supports_audio(): bool {
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
		$body .= $prompt . $eol;

		// model field
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="model"' . $eol . $eol;
		$body .= $this->image_model . $eol;

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
