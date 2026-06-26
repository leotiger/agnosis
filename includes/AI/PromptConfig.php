<?php
/**
 * Prompt configuration value object.
 *
 * Holds the customisable prompt strings for the AI description pipeline.
 * Read from wp_options via from_options(); defaults give sensible
 * out-of-the-box behaviour without any admin configuration.
 *
 * Placeholder tokens recognised in system_prompt:
 *   {tag_count}      — replaced with the configured number of tags.
 *   {excerpt_words}  — replaced with the configured excerpt word-limit.
 *
 * Placeholder token in user_template:
 *   {artist_prompt}  — replaced with the artist's own description, or a
 *                      fallback message when the artist left it blank.
 *
 * @package Agnosis\AI
 */

declare(strict_types=1);

namespace Agnosis\AI;

class PromptConfig {

	public function __construct(
		public readonly string $system_prompt,
		public readonly string $user_template,
		public readonly string $enhancement_instructions,
		public readonly int    $tag_count,
		public readonly int    $excerpt_words,
	) {}

	/** Build a PromptConfig from saved wp_options, falling back to defaults. */
	public static function from_options(): self {
		return new self(
			system_prompt:            (string) get_option( 'agnosis_prompt_system',            self::default_system_prompt() ),
			user_template:            (string) get_option( 'agnosis_prompt_user_template',     self::default_user_template() ),
			enhancement_instructions: (string) get_option( 'agnosis_prompt_enhancement',       self::default_enhancement_instructions() ),
			tag_count:                (int)    get_option( 'agnosis_prompt_tag_count',         5 ),
			excerpt_words:            (int)    get_option( 'agnosis_prompt_excerpt_words',     30 ),
		);
	}

	/**
	 * Resolve {tag_count} and {excerpt_words} tokens in the system prompt.
	 */
	public function resolved_system_prompt(): string {
		return str_replace(
			[ '{tag_count}', '{excerpt_words}' ],
			[ (string) $this->tag_count, (string) $this->excerpt_words ],
			$this->system_prompt
		);
	}

	/**
	 * Interpolate {artist_prompt} in the user template.
	 * Falls back to a sensible message when the artist left the description blank.
	 */
	public function build_user_message( string $artist_prompt ): string {
		$filled = empty( $artist_prompt )
			? __( 'The artist sent no subject line or message with this submission — let the image speak entirely for itself.', 'agnosis' )
			: $artist_prompt;

		return str_replace( '{artist_prompt}', $filled, $this->user_template );
	}

	// -------------------------------------------------------------------------
	// Defaults
	// -------------------------------------------------------------------------

	public static function default_system_prompt(): string {
		return
			'You are a curator and art writer for Agnosis — a free, federated publishing network for independent artists.' . "\n\n"

			. 'Agnosis exists for artists who are brilliant at creating but need help being seen. '
			. 'They submit their work by email; you turn it into a web publication.' . "\n\n"

			. 'The artist\'s email is their voice — treat it with respect.' . "\n"
			. '- Where they describe their intent, process or story: honour those words and let them anchor your writing.' . "\n"
			. '- Where they say nothing, or very little: let the image speak, and write from what you see.' . "\n"
			. '- Never invent biographical details not present in the email.' . "\n\n"

			. 'Voice and tone:' . "\n"
			. '- Warm, clear and honest. Write for someone who loves looking at things but has no art-school background.' . "\n"
			. '- Avoid jargon, curatorial clichés ("liminal", "interrogates", "invites the viewer to…") and hollow superlatives.' . "\n"
			. '- Be specific. A good sentence names something particular about this work, not artwork in general.' . "\n\n"

			. 'Respond ONLY with valid JSON — no markdown fences, no preamble — in exactly this structure:' . "\n"
			. '{' . "\n"
			. '  "title":    "Short evocative title, max 10 words, no full stop",' . "\n"
			. '  "excerpt":  "One sentence that earns a second look (max {excerpt_words} words)",' . "\n"
			. '  "body":     "2–3 paragraphs. What you see. What it evokes. Why it matters. End with something that stays with the reader.",' . "\n"
			. '  "tags":     ["tag1", "tag2", ..., "tag{tag_count}"],' . "\n"
			. '  "alt_text": "Factual visual description for screen readers. Max 125 chars. No interpretation — describe only what is visible."' . "\n"
			. '}';
	}

	public static function default_user_template(): string {
		return
			'The artist submitted this image via email.' . "\n\n"
			. '{artist_prompt}' . "\n\n"
			. 'Analyse the attached image and the artist\'s words together. '
			. 'Let their email be the lens through which you interpret what you see. '
			. 'If they provided a subject line, it may hint at a title — feel free to build on it or depart from it entirely.';
	}

	public static function default_enhancement_instructions(): string {
		return
			'Enhance this artwork for web and Fediverse publication.' . "\n"
			. 'Goals: improve overall clarity, colour accuracy and tonal balance; reduce noise; sharpen edges where the artist clearly intended detail.' . "\n"
			. 'Hard constraints: preserve the artist\'s visual intent exactly. '
			. 'Do not add, remove or recompose any element. '
			. 'Do not "beautify" in ways that erase idiosyncrasies — the grain, the rough edge, the flat colour may be the point.' . "\n"
			. 'Output at the original aspect ratio. Target sRGB colour space.';
	}
}
