<?php
/**
 * Prompt configuration value object.
 *
 * Holds the customizable prompt strings for the AI description pipeline.
 * Read from wp_options via from_options(); defaults give sensible
 * out-of-the-box behavior without any admin configuration.
 *
 * Placeholder tokens recognized in system_prompt:
 *   {tag_count}      — replaced with the configured number of tags.
 *   {excerpt_words}  — replaced with the configured excerpt word-limit.
 *   {medium_list}    — replaced with the live medium vocabulary (see medium_terms()).
 *   {existing_tags}  — replaced with existing tags in the artist's own
 *                      language (see existing_tags_for_language()), or an
 *                      empty string when none apply yet.
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
	 * inserts these agnosis_vendor_once (idempotently) so a fresh install starts with a sensible
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
		'Watercolor',
		'Drawing & Illustration',
		'Photography',
		'Digital Art',
		'Sculpture',
		'Printmaking',
		'Mixed Media',
		'Poetry',
		'Essay',
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
	 * Resolve {tag_count}, {excerpt_words}, {medium_list} and {existing_tags}
	 * tokens in the system prompt.
	 *
	 * $medium_terms/$existing_tags are injectable rather than looked up
	 * internally (via medium_terms()/existing_tags_for_language() below) so
	 * this stays a pure, WP-function-free value object — PromptConfigTest
	 * exercises it under plain PHPUnit with no WordPress loaded at all. Real
	 * callers (the OpenAI/Anthropic/WordPressAI providers) pass both
	 * explicitly; omitting $medium_terms falls back to the CANONICAL_MEDIUMS
	 * seed list (existing behavior, unchanged), and omitting $existing_tags
	 * simply renders that section as nothing — a fresh vocabulary with
	 * nothing yet to reuse is exactly what an empty list should produce, not
	 * an error.
	 *
	 * @param array<string>|null $medium_terms  Live medium vocabulary, or null for the seed default.
	 * @param array<string>      $existing_tags Existing tags in the artist's own language,
	 *                                          from PromptConfig::existing_tags_for_language() —
	 *                                          empty when unknown/none yet.
	 */
	public function resolved_system_prompt( ?array $medium_terms = null, array $existing_tags = [] ): string {
		$existing_tags_line = ! empty( $existing_tags )
			? 'Existing tags already in use for this language — reuse one if it fits rather than inventing a near-duplicate; only propose something new for a genuinely different concept: ' . implode( ' | ', $existing_tags )
			: '';

		return str_replace(
			[ '{tag_count}', '{excerpt_words}', '{medium_list}', '{existing_tags}' ],
			[ (string) $this->tag_count, (string) $this->excerpt_words, implode( ' | ', $medium_terms ?? self::CANONICAL_MEDIUMS ), $existing_tags_line ],
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

	/** Existing-tag candidates offered to the intake prompt — bounds prompt size on a large vocabulary. */
	private const EXISTING_TAGS_PROMPT_LIMIT = 150;

	/**
	 * Existing `post_tag` vocabulary already in use for a given language —
	 * the soft, cheap first line of defense against near-duplicate tag
	 * proliferation (see Compat\LinguaForge::resolve_primary_tags() for the
	 * hard, trid-gated second line, applied later at approval). Injected
	 * into the intake describe()/describe_secondary() prompts so the AI's
	 * own proposal already leans toward reusing a close match instead of
	 * inventing a near-duplicate, rather than relying entirely on
	 * after-the-fact reconciliation.
	 *
	 * Scoped to ONE language, not the whole site's tag vocabulary: tags are
	 * created in the artist's own native language at intake (the
	 * native-language pipeline — NATIVE-LANGUAGE-PIPELINE.md), so the only
	 * vocabulary relevant to THIS submission is whatever already exists in
	 * THAT language — showing a Catalan artist a list of English primary
	 * tags would be actively unhelpful, not just wasted tokens.
	 *
	 * A primary-language artist's own tags live in the unflagged "primary"
	 * bucket (no `TRANSLATED_TERM_META`) — same convention medium_terms()
	 * uses. A non-primary-language artist's tags are flagged with that meta
	 * set to their own language (Compat\LinguaForge::
	 * flag_newly_created_terms_by_post_language()) — so which query runs
	 * depends on whether $lang matches the site's configured primary.
	 *
	 * Calls the live WordPress term API (get_terms()), so — same reasoning
	 * medium_terms() itself documents — this is NOT called from
	 * resolved_system_prompt() itself; real callers resolve $lang and pass
	 * the result in explicitly.
	 *
	 * @param string $lang ISO 639-1 code — the artist's own declared
	 *                      language for this submission.
	 * @return array<string>
	 */
	public static function existing_tags_for_language( string $lang ): array {
		if ( '' === $lang || ! taxonomy_exists( 'post_tag' ) ) {
			return [];
		}

		$primary_lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );

		$meta_query = ( '' === $primary_lang || $lang === $primary_lang )
			? [ [ 'key' => \Agnosis\Compat\LinguaForge::TRANSLATED_TERM_META, 'compare' => 'NOT EXISTS' ] ]
			: [ [ 'key' => \Agnosis\Compat\LinguaForge::TRANSLATED_TERM_META, 'value' => $lang ] ];

		$terms = get_terms( [
			'taxonomy'   => 'post_tag',
			'fields'     => 'names',
			'hide_empty' => false,
			'number'     => self::EXISTING_TAGS_PROMPT_LIMIT,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one bounded lookup per submission's own describe() call, not a hot path.
		] );

		return is_wp_error( $terms ) || empty( $terms ) ? [] : $terms;
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
			. 'Do not alter the artwork itself — preserve all colors, textures, composition and artistic choices exactly. '
			. 'This is a correction of camera and photography problems only, not an artistic edit.' . "\n\n"
			. $this->enhancement_instructions;
	}

	public static function default_system_prompt(): string {
		return 'You are a curator and art writer for Agnosis — a free, federated publishing network for independent artists.' . "\n\n"

			. 'Agnosis exists for artists who are brilliant at creating but need help being seen. '
			. 'They submit their work by email; you turn it into a web publication.' . "\n\n"

			. 'The artist\'s email is their voice — treat it with respect.' . "\n"
			. '- Where they describe their intent, process or story: the body must be visibly grounded in those specific words, not just inspired by them in the abstract. Reuse their own images, phrases, themes or details rather than paraphrasing them into something generic — a reader who wrote that email should recognize it in what you wrote back. If they name a memory, a technique, a material or a feeling, that is what the body is about, not a passing mention before you move on to your own reading of the image.' . "\n"
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

			. '{existing_tags}' . "\n\n"

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
			. 'Analyze the attached image and the artist\'s words together. '
			. 'Let their email be the lens through which you interpret what you see. '
			. 'If they provided a subject line, it may hint at a title — feel free to build on it or depart from it entirely.' . "\n\n"
			. 'If the artist wrote something substantive above, the body you write must clearly and specifically reflect it — '
			. 'name the same things they named, in your own words, not a generic description that could belong to any similar '
			. 'artwork. Only when they left no meaningful text at all should the image alone carry the description.';
	}

	/**
	 * Fixed system prompt for the slim secondary-image description pass
	 * (fifth audit §4c) — used by ProviderInterface::describe_secondary()
	 * implementations. Deliberately NOT part of the admin-configurable
	 * system_prompt option above: this exists purely to cut AI cost on
	 * non-primary gallery images, and asks for only the three fields
	 * Publishing\PostCreator actually reads from a secondary result — alt
	 * text, tags, and a photo-quality assessment (see
	 * ProviderInterface::describe_secondary()'s docblock for the full
	 * accounting of what's discarded vs. published). Mirrors the fixed,
	 * hardcoded prompts Pipeline::process_audio_single()/
	 * describe_video_from_context() already use for their own non-customizable
	 * lean paths — same precedent, same reasoning: a one-off structured
	 * extraction doesn't need an admin-editable template.
	 *
	 * $existing_tags — same purpose and source as resolved_system_prompt()'s
	 * own parameter (PromptConfig::existing_tags_for_language()) — a
	 * secondary gallery image proposes tags too (see PostCreator's
	 * $all_tags merge across every image result), so it needs the same
	 * near-duplicate nudge the primary pass gets. No {token} substitution
	 * here since, unlike system_prompt, this string is never admin-editable —
	 * built directly with the resolved value rather than templated.
	 *
	 * @param array<string> $existing_tags Existing tags in the artist's own
	 *                                     language, or empty when unknown/none yet.
	 */
	public static function secondary_system_prompt( array $existing_tags = [] ): string {
		$existing_tags_line = ! empty( $existing_tags )
			? 'Existing tags already in use for this language — reuse one if it fits rather than inventing a near-duplicate; only propose something new for a genuinely different concept: ' . implode( ' | ', $existing_tags ) . "\n\n"
			: '';

		return 'You are assessing one image among several in a multi-image artwork gallery submission. '
			. "This image's own title, description, and editorial text will never be published — only its alt text, tags, and photographic quality matter here.\n\n"
			. 'Also assess the photographic quality of the submitted image — the quality of the photograph itself, not the artwork. '
			. 'Look for: blur or camera shake, underexposure or overexposure, poor white balance, distracting backgrounds, '
			. 'clipped highlights, heavy noise, or anything that obscures the artwork.' . "\n\n"
			. $existing_tags_line
			. 'Respond ONLY with valid JSON — no markdown fences, no preamble — in exactly this structure:' . "\n"
			. '{' . "\n"
			. '  "alt_text": "Factual visual description for screen readers. Max 125 chars. No interpretation — describe only what is visible.",' . "\n"
			. '  "tags":     ["tag1", "tag2", "..."] (3-6 descriptive lowercase tags),' . "\n"
			. '  "photo_quality": {' . "\n"
			. '    "score": <integer 1-10, where 1 = technically unusable, 10 = publication-perfect photograph>,' . "\n"
			. '    "issues": ["<concise description of photographic issue>"]' . "\n"
			. '  }' . "\n"
			. '}' . "\n\n"
			. 'photo_quality.issues must be an empty array [] when no issues are found. '
			. 'Score the photograph only — not the artistic merit of the work itself.';
	}

	public static function default_enhancement_instructions(): string {
		return 'Enhance this artwork for web and Fediverse publication.' . "\n"
			. 'Goals: improve overall clarity, color accuracy and tonal balance; reduce noise; sharpen edges where the artist clearly intended detail.' . "\n"
			. 'Hard constraints: preserve the artist\'s visual intent exactly. '
			. 'Do not add, remove or recompose any element. '
			. 'Do not "beautify" in ways that erase idiosyncrasies — the grain, the rough edge, the flat color may be the point.' . "\n"
			. 'Output at the original aspect ratio. Target sRGB color space.';
	}
}
