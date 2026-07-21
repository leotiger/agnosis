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
	 * @param string $native_lang    The artist's own declared language (ISO
	 *                               639-1), resolved once by
	 *                               Pipeline::process() and threaded through
	 *                               to build PromptConfig::existing_tags_for_language() —
	 *                               '' when unknown, in which case the
	 *                               existing-tags nudge is simply omitted.
	 *
	 * @return DescriptionResult
	 */
	public function describe( string $image_data, string $mime_type, string $artist_prompt, string $native_lang = '' ): DescriptionResult;

	/**
	 * Slim, cheaper description pass for a secondary (non-primary) image in a
	 * multi-image gallery submission (fifth audit §4c).
	 *
	 * Pipeline::process() runs describe() (the full editorial prompt) on the
	 * first attachment whose description succeeds — that result alone supplies
	 * the published post's title/excerpt/body/medium (see
	 * Publishing\PostCreator::primary_result()). Every OTHER image's own
	 * title/excerpt/body/medium from a full describe() call is generated and
	 * then silently discarded; only its alt text, tags, and photo-quality
	 * assessment are ever actually used (accessibility alt attribute, the
	 * post's merged tag list, and the per-image quality-rejection gate,
	 * respectively). This method asks for exactly those three fields with a
	 * short instruction instead of the full ~600-word editorial prompt,
	 * cutting the bulk of prompt+completion tokens on multi-image submissions
	 * without changing anything actually published.
	 *
	 * Implementations MUST return '' for title/excerpt/body/medium — callers
	 * must never read those fields from a secondary result.
	 *
	 * @param string $image_data  Raw binary of the image.
	 * @param string $mime_type   e.g. 'image/jpeg'.
	 * @param string $native_lang Same as describe()'s own $native_lang — see there.
	 * @return DescriptionResult
	 */
	public function describe_secondary( string $image_data, string $mime_type, string $native_lang = '' ): DescriptionResult;

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
	 * @param string $prompt
	 * @param int    $min_tokens Optional floor for the response token budget,
	 *                           above whatever the implementation's own
	 *                           prompt-length-based sizing would otherwise
	 *                           produce. 0 (default) leaves that sizing
	 *                           untouched — every existing call site is
	 *                           unaffected.
	 *
	 *                           Added 2026-07-21: SubmissionTranslator::
	 *                           translate_to_languages() asks for ONE JSON
	 *                           object containing a full translated copy of
	 *                           the text per target language, in one chat()
	 *                           call. Every implementation's own budget
	 *                           formula sizes off the PROMPT's length, which
	 *                           barely grows with the number of target
	 *                           languages (just a few extra language codes in
	 *                           a list) even though the OUTPUT needs a full
	 *                           translation per language — on a site with
	 *                           several configured languages this silently
	 *                           truncated the response mid-JSON, and because
	 *                           the model writes keys in the same stable
	 *                           order every time, it was always the SAME
	 *                           (last-requested) language that came up
	 *                           short and got silently dropped. $min_tokens
	 *                           lets a caller that knows its output fans out
	 *                           across N independent copies raise the floor
	 *                           accordingly, without changing sizing for
	 *                           every other single-output call site.
	 */
	public function chat( string $prompt, int $min_tokens = 0 ): string;

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
