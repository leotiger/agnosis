<?php
/**
 * Contract that every AI provider must satisfy.
 *
 * Agnosis uses distinct AI operations per media type:
 *   1. describe()    — read the image + artist prompt and produce publication-ready text.
 *   2. enhance()     — return an improved version of the image binary.
 *   3. transcribe()  — convert audio binary to a text transcript.
 *   4. chat()        — lightweight text-in / text-out for classification and description tasks.
 *
 * Providers may implement one or more of these operations. The Pipeline checks
 * supports_enhancement() and supports_audio() before calling enhance()/transcribe().
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
	 * Used for lightweight classification tasks (e.g. duplicate detection) and
	 * audio description that does not require image input. Implementations should
	 * use the cheapest/fastest model available (gpt-4o-mini, claude-haiku-4-5, etc.).
	 *
	 * Returns an empty string on failure — callers must treat '' as "no answer".
	 */
	public function chat( string $prompt ): string;

	/**
	 * Transcribe audio binary to plain text using a speech-to-text model.
	 *
	 * Providers that do not support audio should return an empty string.
	 * Callers should check supports_audio() before calling this method.
	 *
	 * @param string $audio_data  Raw binary of the audio file.
	 * @param string $mime_type   e.g. 'audio/mpeg', 'audio/wav', 'audio/ogg'.
	 * @return string             Transcript text, or '' on failure / unsupported.
	 */
	public function transcribe( string $audio_data, string $mime_type ): string;

	/**
	 * Return whether this provider can transcribe audio via transcribe().
	 */
	public function supports_audio(): bool;
}
