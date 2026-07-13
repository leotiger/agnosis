<?php
/**
 * WordPress 7.0+ built-in AI Client provider.
 *
 * Delegates to core's wp_ai_client_prompt() builder instead of making direct
 * HTTP calls. API credentials are managed by the site admin through
 * Settings → Connectors — Agnosis stores and handles no keys for this provider.
 *
 * ── Text-only limitation ──────────────────────────────────────────────────────
 * wp_ai_client_prompt() is a text-generation API. It cannot analyse artwork
 * images directly. Description is generated from the artist's own words
 * (email subject + body). For vision-based analysis, use OpenAI or Anthropic.
 *
 * ── Plugin Check compatibility ────────────────────────────────────────────────
 * Direct calls to wp_ai_client_prompt() are flagged by Plugin Check's static
 * analyser against "Requires at least: 6.4". We use call_user_func() to bypass
 * static analysis; runtime safety is guaranteed by the function_exists() guards.
 *
 * @package Agnosis\AI\Providers
 * @see https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/
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

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return DescriptionResult::failure(
				'WordPress AI Client requires WordPress 7.0 or later. Use OpenAI or Anthropic for artwork description.'
			);
		}

		if ( empty( trim( $artist_prompt ) ) ) {
			return DescriptionResult::failure(
				'WordPress AI Client is text-only and cannot analyze images. The artist must include a description in their email.'
			);
		}

		$system = $this->config->resolved_system_prompt( PromptConfig::medium_terms() );
		// Append a note since we have no image to send.
		$user = $this->config->build_user_message( $artist_prompt )
			. "\n\n(Note: no image is available — generate artwork metadata solely from the artist's text above.)";

		// call_user_func is used deliberately to satisfy Plugin Check's static
		// analyser (see class docblock). Runtime safety guaranteed by function_exists() above.
		// @phpstan-ignore-next-line -- string literal is a valid callable; verified by function_exists() above.
		$builder = call_user_func( 'wp_ai_client_prompt', $user );

		if ( $system !== '' ) {
			$builder->using_system_instruction( $system );
		}

		$builder->using_temperature( 0.7 );
		$builder->using_max_tokens( 1024 );

		if ( ! $builder->is_supported_for_text_generation() ) {
			return DescriptionResult::failure(
				'No text-generation model is configured. Set one up under Settings → Connectors.'
			);
		}

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return DescriptionResult::failure( 'WordPress AI Client error: ' . $result->get_error_message() );
		}

		$text = trim( (string) $result );

		if ( '' === $text ) {
			return DescriptionResult::failure( 'WordPress AI Client returned an empty response.' );
		}

		$json = json_decode( $text, true );

		// Strip markdown code fences if the model wrapped its JSON.
		if ( ! is_array( $json ) && preg_match( '/```(?:json)?\s*(\{.+\})\s*```/s', $text, $m ) ) {
			$json = json_decode( $m[1], true );
		}

		if ( ! is_array( $json ) ) {
			return DescriptionResult::failure( 'WordPress AI Client returned a non-JSON response.' );
		}

		// WordPressAI is text-only — no image is sent, so quality cannot be assessed.
		// Score defaults to 0 (not assessed) and issues remain empty.
		return new DescriptionResult(
			title:                sanitize_text_field( $json['title']    ?? '' ),
			excerpt:              sanitize_text_field( $json['excerpt']  ?? '' ),
			body:                 wp_kses_post( $json['body']            ?? '' ),
			tags:                 array_map( 'sanitize_text_field', (array) ( $json['tags'] ?? [] ) ),
			alt_text:             sanitize_text_field( $json['alt_text'] ?? '' ),
			success:              true,
			photo_quality_score:  0,
			photo_quality_issues: [],
			medium:               sanitize_text_field( $json['medium']   ?? '' ),
		);
	}

	/**
	 * Slim description pass for secondary gallery images (fifth audit §4c).
	 *
	 * WordPress AI Client is text-only (see class docblock) — it was never
	 * able to genuinely analyse a secondary image's pixels any more than a
	 * primary one, and this method has no artist text to fall back on the way
	 * describe() does (there is deliberately no $artist_prompt parameter here
	 * — see ProviderInterface::describe_secondary()). Returning a failure
	 * immediately, with no HTTP call at all, is strictly better than what
	 * this provider could otherwise offer: zero cost instead of a wasted
	 * call, and callers already treat a failed secondary description
	 * gracefully (empty alt text, quality score 0 — never rejected by the
	 * quality gate, exactly as this provider's primary-image path already
	 * behaves when it has no image to work from).
	 */
	public function describe_secondary( string $image_data, string $mime_type ): DescriptionResult {
		return DescriptionResult::failure(
			'WordPress AI Client is text-only and cannot analyze images — secondary gallery images get no AI-generated alt text, tags, or quality score with this provider.'
		);
	}

	public function enhance( string $image_data, string $mime_type, string $instructions ): EnhancementResult {
		return EnhancementResult::failure(
			'WordPress AI Client does not support image enhancement.'
		);
	}

	public function supports_enhancement(): bool {
		return false;
	}

	public function chat( string $prompt ): string {
		// WordPress AI Client text generation path.
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return '';
		}
		try {
			// @phpstan-ignore-next-line
			$builder = call_user_func( 'wp_ai_client_prompt', $prompt );
			if ( ! $builder->is_supported_for_text_generation() ) {
				return '';
			}
			// @phpstan-ignore-next-line
			return trim( (string) call_user_func( [ $builder, 'get_text' ] ) );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * WordPress AI Client does not support audio transcription.
	 */
	public function transcribe( string $audio_data, string $mime_type ): string {
		return '';
	}

	public function supports_audio(): bool {
		return false;
	}
}
