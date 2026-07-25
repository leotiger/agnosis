<?php
/**
 * Retag service — TAG-REDESIGN.md T2/§2's "Re-tag": re-runs the tag
 * acquisition + association pipeline for one already-published post,
 * always from that post's own primary-language content ("the content at
 * rest IS primary, so there is no language decision to make and no native
 * meta involved" — §2). One AI call proposing candidates from the post's
 * own title/excerpt/content (text-only, no image call, no translation),
 * written to `_agnosis_tag_candidates`, then `TagGate::associate()` — the
 * exact same gate → normalized match → assign-by-ID → proposal-rows logic
 * `ReviewEndpoints::finalize_tags()` v2 runs for a real approval.
 *
 * Service layer ONLY this phase (T2) — no admin UI. TAG-REDESIGN.md T3 (e)
 * adds a per-artwork meta-box button wired to run() (mirroring
 * ArtworkMediumSync's own per-artwork sync button), and T4's optional
 * legacy backfill script is explicitly "the T2 Retag service in a loop" —
 * both are required to call this exact method, never a parallel
 * reimplementation (invariant 8: "Re-tag is not a special path... a
 * behavior reachable via Re-tag but not via approval (or vice versa) is a
 * bug in one of them").
 *
 * CPT scope — a deliberate reading, recorded here since §2's own prose
 * describes Re-tag in terms of "an individual published artwork" (the
 * button's eventual home, mirroring ArtworkMediumSync) while T4's backfill
 * description says only "per published primary-language post" with no CPT
 * restriction. `TagGate::associate()`/`finalize_tags()` are already CPT-
 * agnostic (`post_tag` spans all three Agnosis CPTs — Artist\Profile's own
 * registration), so this service follows that precedent and accepts any of
 * the three; T3's button is what will actually restrict itself to artwork
 * edit screens, same as the surrounding UI-vs-service split this class
 * draws everywhere else.
 *
 * Propagation to siblings is now automatic (T3(c), shipped 2026-07-25):
 * run()'s own wp_set_object_terms() call (inside TagGate::associate())
 * fires the `set_object_terms` hook, which Compat\LinguaForge::
 * on_term_assignment_changed() now handles for both taxonomies — nothing
 * in this class calls sync code directly, invariant 4/8 stay intact.
 *
 * What run() still deliberately does NOT do:
 *   - Riding an Update through federation — the F-track hasn't started.
 *   - Any admin gating (capability, nonce, confirm dialog) — that belongs
 *     to T3's button. run() itself only refuses a post that is
 *     structurally ineligible (see below), returning a reason rather than
 *     throwing, so T4's backfill loop and tests can call it directly
 *     across a mixed batch without a UI layer in front and without one bad
 *     post aborting the run.
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\AI\CallCounter;
use Agnosis\AI\Pipeline;
use Agnosis\AI\PromptConfig;
use Agnosis\AI\SubmissionTranslator;
use Agnosis\Core\Logger;

class Retag {

	/** post_tag is registered against all three — see this class's own docblock. */
	private const POST_TYPES = [ 'agnosis_artwork', 'agnosis_biography', 'agnosis_event' ];

	/**
	 * Re-run the tag pipeline for one post.
	 *
	 * Eligibility (structural, not a UI gate — see class docblock): one of
	 * the three Agnosis CPTs, `publish` status, primary-language
	 * (`_agnosis_native_lang` empty, or already equal to the resolved
	 * primary code — the identical test T1's own intake gate and
	 * `translate_native_content_to_primary()`'s early-return both use). An
	 * ineligible post, or an AI call that yields no usable candidates, is a
	 * non-throwing no-op: `success => false` with a machine-readable
	 * `reason`, since T4's backfill loop is expected to sweep a mixed batch
	 * of posts and skip failures rather than abort on the first one.
	 *
	 * @return array{
	 *     success: bool,
	 *     reason: string,
	 *     matched: int,
	 *     proposed: int,
	 *     gated: int,
	 * }
	 */
	public function run( int $post_id ): array {
		$failure = [ 'success' => false, 'reason' => '', 'matched' => 0, 'proposed' => 0, 'gated' => 0 ];

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array_merge( $failure, [ 'reason' => 'not_found' ] );
		}
		if ( ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return array_merge( $failure, [ 'reason' => 'unsupported_post_type' ] );
		}
		if ( 'publish' !== $post->post_status ) {
			return array_merge( $failure, [ 'reason' => 'not_published' ] );
		}

		$native_lang = (string) get_post_meta( $post_id, '_agnosis_native_lang', true );
		if ( '' !== trim( $native_lang ) && $native_lang !== SubmissionTranslator::resolve_target_language() ) {
			return array_merge( $failure, [ 'reason' => 'not_primary_language' ] );
		}

		$candidates = $this->propose_candidates( $post );
		if ( null === $candidates ) {
			return array_merge( $failure, [ 'reason' => 'ai_call_failed' ] );
		}
		if ( empty( $candidates ) ) {
			return array_merge( $failure, [ 'reason' => 'no_candidates_returned' ] );
		}

		// §2: "CallCounter-recorded (retag)" — CallCounter's own docblock
		// scopes it to *translation* calls (deliberately excluding the
		// description/vision call), but TAG-REDESIGN.md explicitly directs
		// Retag's classification call to the same counter regardless, so an
		// operator's per-post AI-call tally includes it. Recorded only once
		// the AI call has actually succeeded — a structurally-ineligible
		// post above never reaches here, so it costs nothing to count.
		CallCounter::record( $post_id, 'retag' );

		update_post_meta( $post_id, '_agnosis_tag_candidates', wp_json_encode( $candidates, JSON_UNESCAPED_UNICODE ) );

		// Any stale pending proposal rows for THIS post are cleared as part
		// of TagGate::associate()'s own replace_proposals() call — "a
		// re-tag supersedes its own earlier candidates; vocabulary-level
		// proposals from OTHER posts are untouched" (§2). Same shared
		// implementation ReviewEndpoints::finalize_tags() v2 uses —
		// invariant 8.
		$result = TagGate::associate( $post_id, $candidates );

		Logger::info(
			sprintf(
				'Retag::run(#%d): %d matched (assigned by ID), %d new proposal(s) recorded, %d candidate(s) gated out or trimmed by cap.',
				$post_id,
				$result['matched'],
				$result['proposed'],
				$result['gated']
			),
			'review'
		);

		return array_merge( $result, [ 'success' => true, 'reason' => '' ] );
	}

	/**
	 * The one AI call — text-only, from the post's OWN title/excerpt/content
	 * (already primary-language; no translation, no image, no artist
	 * context). Mirrors `AI\Pipeline::classify_medium_from_text()`'s own
	 * chat()-plus-JSON-parse shape (that method's docblock explains the
	 * fenced-code-stripping/decode-tolerance reasoning this repeats), but
	 * proposes tags only — Retag never touches medium.
	 *
	 * @return array<string>|null Candidate tag strings, or null on an AI
	 *                             call/parse failure (distinct from a
	 *                             successful call that legitimately returned
	 *                             zero tags — see the empty-array check in
	 *                             run()).
	 */
	private function propose_candidates( \WP_Post $post ): ?array {
		$title   = trim( wp_strip_all_tags( $post->post_title ) );
		$excerpt = trim( wp_strip_all_tags( $post->post_excerpt ) );
		$body    = trim( wp_strip_all_tags( $post->post_content ) );

		if ( '' === $title && '' === $excerpt && '' === $body ) {
			return null;
		}

		$tag_count         = PromptConfig::from_options()->tag_count;
		$primary_language  = SubmissionTranslator::primary_language_name();
		$existing_tags     = PromptConfig::existing_tags();
		$existing_tags_line = ! empty( $existing_tags )
			? '- Existing tags already approved for this site — reuse one if it fits rather than inventing a near-duplicate; only propose something new for a genuinely different concept: ' . implode( ' | ', $existing_tags ) . "\n"
			: '';

		$prompt = "The following is a post already published on an art platform, written in {$primary_language}.\n\n"
			. "Title: {$title}\n"
			. "Excerpt: {$excerpt}\n"
			. "Content:\n---\n" . wp_trim_words( $body, 300, '' ) . "\n---\n\n"
			. "Propose 3–{$tag_count} descriptive tags for it, specifically in {$primary_language}.\n"
			. $existing_tags_line
			. 'Return ONLY a JSON array of lowercase tag strings. No markdown fences. No preamble.';

		$response = ( new Pipeline() )->chat( $prompt );
		if ( '' === trim( (string) $response ) ) {
			return null;
		}

		$json_str = trim( (string) preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $response ) ) );
		$decoded  = json_decode( $json_str, true );

		if ( ! is_array( $decoded ) ) {
			Logger::warning(
				sprintf( 'Retag::propose_candidates(#%d): AI response did not decode to a JSON array — treating as a failed call.', $post->ID ),
				'review'
			);
			return null;
		}

		return array_values( array_map( 'sanitize_text_field', array_filter( $decoded, 'is_string' ) ) );
	}
}
