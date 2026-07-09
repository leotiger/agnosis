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

	/**
	 * Default medium taxonomy terms, seeded into `agnosis_medium` on activation.
	 *
	 * This is a SEED list only, not the live vocabulary — Activator::seed_medium_terms()
	 * inserts these once (idempotently) so a fresh install starts with a sensible
	 * set, and medium_terms() below (not this constant) is what the AI prompt and
	 * PostCreator's hallucination guard actually read at runtime. An admin can
	 * freely add, rename, or remove terms under Artwork → Mediums afterwards —
	 * same as WordPress Categories — and every change is picked up immediately,
	 * with no code change or deploy. Kept here (rather than only in Activator)
	 * since it's still the fallback medium_terms() itself uses when the taxonomy
	 * is unregistered or has no terms at all.
	 *
	 * @var array<string>
	 */
	public const CANONICAL_MEDIUMS = [
		'Oil Painting',
		'Watercolour',
		'Drawing & Illustration',
		'Photography',
		'Digital Art',
		'Sculpture',
		'Printmaking',
		'Mixed Media',
	];

	public function __construct(
		public readonly string $system_prompt,
		public readonly string $user_template,
		public readonly string $enhancement_instructions,
		public readonly int $tag_count,
		public readonly int $excerpt_words,
		public readonly int $quality_threshold = 7,
		public readonly int $quality_rejection_threshold = 3,
	) {}

	/** Build a PromptConfig from saved wp_options, falling back to defaults. */
	public static function from_options(): self {
		return new self(
			system_prompt:                    (string) get_option( 'agnosis_prompt_system',                self::default_system_prompt() ),
			user_template:                    (string) get_option( 'agnosis_prompt_user_template',         self::default_user_template() ),
			enhancement_instructions:         (string) get_option( 'agnosis_prompt_enhancement',           self::default_enhancement_instructions() ),
			tag_count:                        (int) get_option( 'agnosis_prompt_tag_count',             5 ),
			excerpt_words:                    (int) get_option( 'agnosis_prompt_excerpt_words',         30 ),
			quality_threshold:                (int) get_option( 'agnosis_quality_threshold',            7 ),
			quality_rejection_threshold:      (int) get_option( 'agnosis_quality_rejection_threshold',  3 ),
		);
	}

	/**
	 * Resolve {tag_count}, {excerpt_words} and {medium_list} tokens in the system prompt.
	 *
	 * $medium_terms is injectable rather than looked up internally (via
	 * medium_terms() below) so this stays a pure, WP-function-free value object —
	 * PromptConfigTest exercises it under plain PHPUnit with no WordPress loaded
	 * at all. Real callers (the OpenAI/Anthropic/WordPressAI providers) pass
	 * PromptConfig::medium_terms() explicitly; omitting it falls back to the
	 * CANONICAL_MEDIUMS seed list, which is what every existing caller/test that
	 * doesn't pass this argument continues to see.
	 *
	 * @param array<string>|null $medium_terms Live medium vocabulary, or null for the seed default.
	 */
	public function resolved_system_prompt( ?array $medium_terms = null ): string {
		return str_replace(
			[ '{tag_count}', '{excerpt_words}', '{medium_list}' ],
			[ (string) $this->tag_count, (string) $this->excerpt_words, implode( ' | ', $medium_terms ?? self::CANONICAL_MEDIUMS ) ],
			$this->system_prompt
		);
	}

	/**
	 * Live, current medium vocabulary — the term names an admin can add,
	 * rename, or remove under Artwork → Mediums, exactly like WordPress
	 * Categories. This — not the CANONICAL_MEDIUMS constant — is the source of
	 * truth every real caller should read: the AI prompt's {medium_list} and
	 * PostCreator::write_post_meta()'s hallucination guard both need to accept
	 * whatever the admin has actually configured, not just the eight seed terms.
	 *
	 * Falls back to CANONICAL_MEDIUMS when the taxonomy isn't registered yet
	 * (e.g. mid-activation, before Profile::register_taxonomy() has run on
	 * `init`) or has no terms at all — never leaves the AI prompt or the
	 * validation guard looking at an empty vocabulary.
	 *
	 * Calls the live WordPress term API (get_terms()), so this is NOT called
	 * from resolved_system_prompt() itself — see that method's docblock for why.
	 *
	 * Excludes terms flagged with `LinguaForge::TRANSLATED_TERM_META` — names
	 * `Compat\LinguaForge::sync_taxonomy()` itself auto-created while assigning
	 * a translated medium term to a translated post's sibling, not ones an
	 * admin curated (fourth audit §4c). Left in, these would otherwise let a
	 * term translated into one language get offered as vocabulary — and
	 * potentially AI-assigned — to artwork in a completely different language,
	 * and would clutter Artwork → Mediums with every term times every
	 * translation pass.
	 *
	 * @return array<string>
	 */
	public static function medium_terms(): array {
		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			return self::CANONICAL_MEDIUMS;
		}

		$terms = get_terms( [
			'taxonomy'   => 'agnosis_medium',
			'fields'     => 'names',
			'hide_empty' => false,
			'meta_query' => [
				[
					'key'     => \Agnosis\Compat\LinguaForge::TRANSLATED_TERM_META,
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return self::CANONICAL_MEDIUMS;
		}

		return $terms;
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

	/**
	 * Build issue-targeted enhancement instructions.
	 *
	 * Prepends a focused correction block (listing only the detected photographic
	 * issues) to the base enhancement instructions. When no issues are detected
	 * the base instructions are returned unchanged.
	 *
	 * @param array<string> $issues Detected photographic issues from the quality assessment.
	 */
	public function build_targeted_enhancement_instructions( array $issues ): string {
		if ( empty( $issues ) ) {
			return $this->enhancement_instructions;
		}

		$issue_list = implode( "\n", array_map( fn( string $i ) => '- ' . $i, $issues ) );

		return 'The following photographic/technical issues were detected in this image:' . "\n"
			. $issue_list . "\n\n"
			. 'Correct ONLY these specific issues to improve the visibility of the artwork. '
			. 'Do not alter the artwork itself — preserve all colours, textures, composition and artistic choices exactly. '
			. 'This is a correction of camera and photography problems only, not an artistic edit.' . "\n\n"
			. $this->enhancement_instructions;
	}

	public static function default_system_prompt(): string {
		return 'You are a curator and art writer for Agnosis — a free, federated publishing network for independent artists.' . "\n\n"

			. 'Agnosis exists for artists who are brilliant at creating but need help being seen. '
			. 'They submit their work by email; you turn it into a web publication.' . "\n\n"

			. 'The artist\'s email is their voice — treat it with respect.' . "\n"
			. '- Where they describe their intent, process or story: honour those words and let them anchor your writing.' . "\n"
			. '- Where they say nothing, or very little: let the image speak, and write from what you see.' . "\n"
			. '- Never invent biographical details not present in the email.' . "\n"
			. '- Ignore mail-client footers (e.g. "Sent from my iPhone"), email signatures, and any other text unrelated to the submission itself.' . "\n\n"

			. 'Voice and tone:' . "\n"
			. '- Warm, clear and honest. Write for someone who loves looking at things but has no art-school background.' . "\n"
			. '- Avoid jargon, curatorial clichés ("liminal", "interrogates", "invites the viewer to…") and hollow superlatives.' . "\n"
			. '- Be specific. A good sentence names something particular about this work, not artwork in general.' . "\n\n"

			. 'Also assess the photographic quality of the submitted image — the quality of the photograph itself, not the artwork. '
			. 'Look for: blur or camera shake, underexposure or overexposure, poor white balance, distracting backgrounds, '
			. 'clipped highlights, heavy noise, or anything that obscures the artwork.' . "\n\n"

			. 'Respond ONLY with valid JSON — no markdown fences, no preamble — in exactly this structure:' . "\n"
			. '{' . "\n"
			. '  "title":    "Short evocative title, max 10 words, no full stop",' . "\n"
			. '  "excerpt":  "One sentence that earns a second look (max {excerpt_words} words)",' . "\n"
			. '  "body":     "2–3 paragraphs. What you see. What it evokes. Why it matters. End with something that stays with the reader.",' . "\n"
			. '  "tags":     ["tag1", "tag2", ..., "tag{tag_count}"],' . "\n"
			. '  "alt_text": "Factual visual description for screen readers. Max 125 chars. No interpretation — describe only what is visible.",' . "\n"
			. '  "medium":   "Pick exactly one from: {medium_list}",' . "\n"
			. '  "photo_quality": {' . "\n"
			. '    "score": <integer 1–10, where 1 = technically unusable, 10 = publication-perfect photograph>,' . "\n"
			. '    "issues": ["<concise description of photographic issue>"]' . "\n"
			. '  }' . "\n"
			. '}' . "\n\n"
			. 'photo_quality.issues must be an empty array [] when no issues are found. '
			. 'Score the photograph only — not the artistic merit of the work itself.';
	}

	public static function default_user_template(): string {
		return 'The artist submitted this image via email.' . "\n\n"
			. '{artist_prompt}' . "\n\n"
			. 'Analyse the attached image and the artist\'s words together. '
			. 'Let their email be the lens through which you interpret what you see. '
			. 'If they provided a subject line, it may hint at a title — feel free to build on it or depart from it entirely.';
	}

	public static function default_enhancement_instructions(): string {
		return 'Enhance this artwork for web and Fediverse publication.' . "\n"
			. 'Goals: improve overall clarity, colour accuracy and tonal balance; reduce noise; sharpen edges where the artist clearly intended detail.' . "\n"
			. 'Hard constraints: preserve the artist\'s visual intent exactly. '
			. 'Do not add, remove or recompose any element. '
			. 'Do not "beautify" in ways that erase idiosyncrasies — the grain, the rough edge, the flat colour may be the point.' . "\n"
			. 'Output at the original aspect ratio. Target sRGB colour space.';
	}
}
