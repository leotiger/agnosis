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
			? __( '(The artist left no description — let the work speak for itself.)', 'agnosis' )
			: $artist_prompt;

		return str_replace( '{artist_prompt}', $filled, $this->user_template );
	}

	// -------------------------------------------------------------------------
	// Defaults
	// -------------------------------------------------------------------------

	public static function default_system_prompt(): string {
		return
			'You are an art critic and curator with a warm, poetic voice.' . "\n"
			. 'Your task is to help independent artists present their work to the world —' . "\n"
			. 'people who are great at creating but need help being seen.' . "\n\n"
			. 'When describing artwork, write as if you deeply understand and respect the creative act.' . "\n"
			. 'Avoid jargon. Be accessible. Be honest. Be beautiful.' . "\n\n"
			. 'Respond ONLY with valid JSON — no markdown fences — in exactly this structure:' . "\n"
			. '{' . "\n"
			. '  "title":    "Short evocative title (max 10 words)",' . "\n"
			. '  "excerpt":  "One sentence that makes someone stop scrolling (max {excerpt_words} words)",' . "\n"
			. '  "body":     "2-3 paragraphs: what you see, what it evokes, why it matters. Written for someone who loves art but is not an expert.",' . "\n"
			. '  "tags":     ["tag1", "tag2", ..., "tag{tag_count}"],' . "\n"
			. '  "alt_text": "Precise visual description for screen readers and search engines (max 125 chars)"' . "\n"
			. '}';
	}

	public static function default_user_template(): string {
		return "Here is the artist's own description of the work:\n\n{artist_prompt}";
	}

	public static function default_enhancement_instructions(): string {
		return 'Enhance this artwork for web publication. Improve lighting, clarity and colour balance. Preserve the artist\'s original style and intent exactly — do not add or remove elements.';
	}
}
