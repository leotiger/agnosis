<?php
/**
 * Anthropic Claude provider — description only.
 *
 * Uses claude-opus-4 (vision) to analyse artwork and produce
 * publication-ready title, excerpt, body, tags and alt text.
 * Claude does not perform image enhancement; supports_enhancement() → false.
 *
 * 2026-07-24 fix: describe()/describe_secondary() now ask Claude for its
 * answer via forced tool use (function calling) instead of raw JSON in a
 * text block. Reported live: with agnosis_anthropic_model set to
 * claude-sonnet-5, a real Catalan artwork submission came back with every
 * AI field empty (title still showed on the review page only because
 * Publishing\PostCreator falls back to the artist's own submitted subject
 * line there regardless of AI success — the body has no such fallback,
 * see build_post_content()'s own 2026-07-24 fix) — the identical submission
 * via OpenAI's gpt-4o worked. Root cause is the same class of bug already
 * hit and fixed once in this codebase for dev/bin/translate-missing.php's
 * own Anthropic call (see that file's call_anthropic() docblock): asking a
 * model to hand-write valid JSON inside a text block, then parsing it with
 * json_decode(), depends on the model perfectly escaping every embedded
 * quote/newline itself — a long, rich prose body (multiple paragraphs,
 * natural punctuation) is exactly the shape most likely to trip that up,
 * and Sonnet-class models have proven more prone to it in this codebase's
 * own history than Haiku or Opus. OpenAI's equivalent call never had this
 * failure mode because 'response_format' => ['type' => 'json_object']
 * makes the API itself guarantee syntactically valid JSON — Anthropic's
 * equivalent guarantee is tool use: the API validates the response against
 * an explicit input_schema and hands back an already-decoded array, so
 * there is no raw-text JSON parsing on our end at all, and therefore no
 * escaping failure mode to hit regardless of how the model chooses to
 * write the body. max_tokens for describe() also rises 1500 → 2500 here as
 * a second, independent defense — a full title/excerpt/multi-paragraph
 * body/tags/alt_text/medium response in a language that tokenizes less
 * efficiently than English (Catalan among them) can plausibly approach the
 * old ceiling on its own, and a truncated tool-use response would still
 * fail to decode same as truncated raw text would.
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
	// Audit §5c: was inlined directly in chat() as a literal, with no
	// operator lever — unlike $model above (agnosis_anthropic_model,
	// configurable since it was introduced). Now just the constructor
	// default; Pipeline/SubmissionTranslator both pass the actual configured
	// agnosis_vendor_value (agnosis_anthropic_text_model option) explicitly.
	private const DEFAULT_TEXT_MODEL = 'claude-haiku-4-5-20251001';

	// Tool-use names (2026-07-24 fix — see class docblock). Both describe()
	// and describe_secondary() force Claude to answer through exactly one
	// of these, so the response is a schema-validated, already-decoded
	// array (Messages API content block: type "tool_use", input => array),
	// never a raw text block that needs json_decode() on our end at all.
	private const DESCRIPTION_TOOL_NAME           = 'submit_artwork_description';
	private const SECONDARY_DESCRIPTION_TOOL_NAME = 'submit_secondary_image_description';

	public function __construct(
		private readonly string $api_key,
		private readonly PromptConfig $config,
		private readonly string $model = self::DEFAULT_MODEL,
		private readonly string $text_model = self::DEFAULT_TEXT_MODEL,
	) {}

	public function describe( string $image_data, string $mime_type, string $artist_prompt, string $native_lang = '' ): DescriptionResult {
		if ( empty( $this->api_key ) ) {
			return DescriptionResult::failure( 'Anthropic API key not configured.' );
		}

		// Downscaled copy for THIS request only — never reassign $image_data
		// itself, since callers (Pipeline::process_single()) reuse that same
		// variable for image enhancement and for the actual published file.
		// See MediaAdapter::maybe_downscale_for_vision()'s doc.
		$vision_image_data = MediaAdapter::maybe_downscale_for_vision( $image_data, $mime_type );

		$image_b64    = base64_encode( $vision_image_data );
		$system_prompt = $this->config->resolved_system_prompt( PromptConfig::medium_terms(), PromptConfig::existing_tags_for_language( $native_lang ) );
		$user_content  = $this->config->build_user_message( $artist_prompt );

		$body = wp_json_encode( [
			'model'      => $this->model,
			// 2026-07-24: was 1500 — see this file's class docblock for the
			// full incident. Raised as a second, independent defense against
			// truncation now that tool use has closed off the escaping
			// failure mode.
			'max_tokens' => 2500,
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
			'tools'       => [ self::description_tool_schema() ],
			'tool_choice' => [ 'type' => 'tool', 'name' => self::DESCRIPTION_TOOL_NAME ],
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

		if ( $code !== 200 || empty( $data['content'] ) ) {
			return DescriptionResult::failure( 'Anthropic API error: ' . $raw );
		}

		$json = self::extract_tool_input( (array) $data['content'], self::DESCRIPTION_TOOL_NAME );

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

	/**
	 * Slim description pass for secondary gallery images (fifth audit §4c) —
	 * see ProviderInterface::describe_secondary()'s docblock for the full
	 * rationale. Same vision call shape as describe() — the image itself is
	 * still sent at full resolution, only the system prompt is fixed and much
	 * shorter, no artist-context user message is sent, and max_tokens is cut
	 * to match the tiny JSON response this asks for.
	 */
	public function describe_secondary( string $image_data, string $mime_type, string $native_lang = '' ): DescriptionResult {
		if ( empty( $this->api_key ) ) {
			return DescriptionResult::failure( 'Anthropic API key not configured.' );
		}

		$vision_image_data = MediaAdapter::maybe_downscale_for_vision( $image_data, $mime_type );
		$image_b64         = base64_encode( $vision_image_data );

		$body = wp_json_encode( [
			'model'      => $this->model,
			'max_tokens' => 300,
			'system'     => PromptConfig::secondary_system_prompt( PromptConfig::existing_tags_for_language( $native_lang ) ),
			'messages'   => [
				[
					'role'    => 'user',
					'content' => [
						[
							'type'   => 'image',
							'source' => [
								'type'       => 'base64',
								'media_type' => $mime_type,
								'data'       => $image_b64,
							],
						],
					],
				],
			],
			'tools'       => [ self::secondary_description_tool_schema() ],
			'tool_choice' => [ 'type' => 'tool', 'name' => self::SECONDARY_DESCRIPTION_TOOL_NAME ],
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

		if ( $code !== 200 || empty( $data['content'] ) ) {
			return DescriptionResult::failure( 'Anthropic API error: ' . $raw );
		}

		$json = self::extract_tool_input( (array) $data['content'], self::SECONDARY_DESCRIPTION_TOOL_NAME );

		if ( ! is_array( $json ) ) {
			return DescriptionResult::failure( 'Claude returned non-JSON response.' );
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

	public function enhance( string $image_data, string $mime_type, string $instructions ): EnhancementResult {
		// Claude does not produce enhanced images.
		return EnhancementResult::failure( 'Anthropic provider does not support image enhancement.' );
	}

	public function supports_enhancement(): bool {
		return false;
	}

	public function chat( string $prompt, int $min_tokens = 0 ): string {
		if ( empty( $this->api_key ) ) {
			return '';
		}

		$body = wp_json_encode( [
			'model'      => $this->text_model, // audit §5c: operator-configurable, was a hardcoded literal
			// Sized from the prompt itself rather than a flat cap — see
			// OpenAI::chat()'s own comment for the full rationale (audit
			// §5b): a flat 1024 truncated long translate_fields() JSON
			// responses (a long biography body) mid-object, so the parse
			// failed and the caller silently fell back to untranslated text
			// on a call that was still billed in full.
			//
			// 2026-07-21: $min_tokens raises the estimate for callers whose
			// output fans out into several independent copies (one per
			// target language) rather than scaling with prompt length, and
			// the ceiling (was a hardcoded 8192) is now the
			// agnosis_ai_max_response_tokens site setting — see
			// ProviderInterface::chat()'s docblock and OpenAI::chat()'s own
			// comment for the full incident.
			'max_tokens' => max(
				1024,
				min(
					max( 1024, (int) get_option( 'agnosis_ai_max_response_tokens', 8192 ) ),
					max( (int) ceil( strlen( $prompt ) / 4 * 1.5 ), $min_tokens )
				)
			),
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

	// -------------------------------------------------------------------------
	// Tool-use helpers (2026-07-24 fix — see class docblock)
	// -------------------------------------------------------------------------

	/**
	 * input_schema for describe()'s forced tool call — mirrors the JSON
	 * structure PromptConfig::default_system_prompt() describes, so the
	 * schema and the (admin-editable) prompt text stay in sync in spirit,
	 * even though the schema itself is not admin-configurable. photo_quality
	 * is intentionally NOT in `required` — a text-only or otherwise
	 * unassessable submission legitimately has nothing to report there, and
	 * the caller already treats a missing key as score 0 / issues [].
	 *
	 * @return array<string, mixed>
	 */
	private static function description_tool_schema(): array {
		return [
			'name'         => self::DESCRIPTION_TOOL_NAME,
			'description'  => 'Submit the structured artwork description: title, excerpt, body, tags, alt text, medium and photo quality assessment.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'title'    => [ 'type' => 'string' ],
					'excerpt'  => [ 'type' => 'string' ],
					'body'     => [ 'type' => 'string' ],
					'tags'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'alt_text' => [ 'type' => 'string' ],
					'medium'   => [ 'type' => 'string' ],
					'photo_quality' => [
						'type'       => 'object',
						'properties' => [
							'score'  => [ 'type' => 'integer' ],
							'issues' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						],
					],
				],
				'required' => [ 'title', 'excerpt', 'body', 'tags', 'alt_text', 'medium' ],
			],
		];
	}

	/**
	 * input_schema for describe_secondary()'s forced tool call — same three
	 * fields the slim secondary pass has always asked for (see
	 * ProviderInterface::describe_secondary()'s docblock); title/excerpt/
	 * body/medium are never part of this schema since PostCreator never
	 * reads them from a secondary result.
	 *
	 * @return array<string, mixed>
	 */
	private static function secondary_description_tool_schema(): array {
		return [
			'name'         => self::SECONDARY_DESCRIPTION_TOOL_NAME,
			'description'  => 'Submit the alt text, tags and photo quality assessment for this secondary gallery image.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'alt_text' => [ 'type' => 'string' ],
					'tags'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'photo_quality' => [
						'type'       => 'object',
						'properties' => [
							'score'  => [ 'type' => 'integer' ],
							'issues' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						],
					],
				],
				'required' => [ 'alt_text', 'tags' ],
			],
		];
	}

	/**
	 * Find the named tool_use content block in a Messages API response and
	 * return its already-decoded `input` array — or null if that block
	 * isn't present at all (e.g. the model replied with plain text instead
	 * of calling the forced tool, or an unrelated tool name). Callers treat
	 * null the same as "non-JSON response" — see describe()/
	 * describe_secondary()'s own failure paths.
	 *
	 * @param array<int, mixed> $content Decoded response['content'] blocks.
	 * @return array<string, mixed>|null
	 */
	private static function extract_tool_input( array $content, string $tool_name ): ?array {
		foreach ( $content as $block ) {
			if ( is_array( $block )
				&& ( $block['type'] ?? null ) === 'tool_use'
				&& ( $block['name'] ?? null ) === $tool_name
				&& is_array( $block['input'] ?? null )
			) {
				return $block['input'];
			}
		}
		return null;
	}
}
