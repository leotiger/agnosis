<?php
/**
 * Contract that every AI provider must satisfy.
 *
 * Agnosis uses two distinct AI operations:
 *   1. describe()  — read the image + artist prompt and produce publication-ready text.
 *   2. enhance()   — return an improved version of the image binary.
 *
 * Providers may implement one or both; the Pipeline decides which to call.
 *
 * @package Agnosis\AI
 */

declare(strict_types=1);

namespace Agnosis\AI;

interface ProviderInterface {

	/**
	 * Analyse an artwork image and generate publication-ready text.
	 *
	 * @param string $image_data  Raw binary of the image.
	 * @param string $mime_type   e.g. 'image/jpeg'.
	 * @param string $artist_prompt  The artist's own description / notes.
	 *
	 * @return DescriptionResult
	 */
	public function describe( string $image_data, string $mime_type, string $artist_prompt ): DescriptionResult;

	/**
	 * Enhance / upscale / clean the artwork image.
	 *
	 * @param string $image_data  Raw binary of the original image.
	 * @param string $mime_type   MIME type.
	 * @param string $instructions  Style or enhancement notes (from describe result or artist).
	 *
	 * @return EnhancementResult
	 */
	public function enhance( string $image_data, string $mime_type, string $instructions ): EnhancementResult;

	/**
	 * Return whether this provider supports image enhancement.
	 * Providers that are description-only (e.g. pure-language models) return false.
	 */
	public function supports_enhancement(): bool;

	/**
	 * Send a plain-text prompt and return the model's text response.
	 *
	 * Used for lightweight classification tasks (e.g. duplicate detection) that
	 * do not require image input. Implementations should use the cheapest/fastest
	 * model available (gpt-4o-mini, claude-haiku-4-5, etc.).
	 *
	 * Returns an empty string on failure — callers must treat '' as "no answer".
	 */
	public function chat( string $prompt ): string;
}
