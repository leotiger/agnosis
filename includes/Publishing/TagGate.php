<?php
/**
 * Tag candidate gate — normalization-for-comparison, junk rejection, and the
 * primary-vocabulary lookup shared by ReviewEndpoints::finalize_tags() v2
 * (association at approval) and Admin\TagProposals (approval-time term
 * reuse) — TAG-REDESIGN.md T2: "one static utility, reused by TagProposals'
 * matching."
 *
 * Carries forward two specific findings from the superseded
 * TAG-WORKFLOW-AUDIT.md, per TAG-REDESIGN.md §7's decision record ("its
 * TW-9 findings survive as the gate's normalization"):
 *   - TW-9: no name normalization anywhere meant a byte-exact PHP comparison
 *     missed "photography" when "Photography" already existed, and the 8+
 *     live "anthropology" spelling/case variants were the accumulated
 *     result. Fix: one normalize_for_match() every lookup in the tag paths
 *     goes through — comparison-key normalization ONLY, never used to
 *     rewrite a stored display name.
 *   - TW-14/TW-15: `_agnosis_native_tags`' `wp_json_encode()` (no
 *     JSON_UNESCAPED_UNICODE) plus a downstream unslash/stripslashes
 *     boundary produced live terms like "connexiu00f3" — a `ó` escape
 *     with its backslash stripped. T1 already fixed the encoding
 *     (JSON_UNESCAPED_UNICODE throughout); this gate additionally makes
 *     sure that exact residue shape can never become — or match — a real
 *     tag again.
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\AI\PromptConfig;

class TagGate {

	/**
	 * Meta key for a pending, unmatched tag proposal row — non-unique, one
	 * row per name (§3 — "the multi-value analogue of medium's single-value
	 * meta"). Written only by ReviewEndpoints::finalize_tags() (invariant 1);
	 * read and cleared by Admin\TagProposals and the TTL sweep cron. A single
	 * authoritative constant here (rather than each of those three call
	 * sites repeating the literal) because a typo mismatch between them would
	 * be a silent bug, not a loud one.
	 */
	public const PROPOSAL_META = '_agnosis_tag_proposal';

	/**
	 * Meta key for the companion creation-timestamp map — a single JSON
	 * object per post, `{name: unix timestamp}`, one entry per PROPOSAL_META
	 * row that post currently carries. wp_postmeta has no built-in per-row
	 * created-at column (unlike wp_posts), and PROPOSAL_META's own row VALUE
	 * must stay the bare tag name so cross-post exact-value matching keeps
	 * working (Admin\TagProposals::get_posts_with_proposal()) — a per-row
	 * JSON blob embedding a timestamp would make that same tag name compare
	 * unequal across two posts proposed at different times, breaking the
	 * whole "find every post carrying this proposal" query. Kept in sync by
	 * replace_proposals()/clear_proposal() below — nothing else should write
	 * either meta key directly.
	 */
	public const PROPOSAL_CREATED_META = '_agnosis_tag_proposal_created';

	/**
	 * Replace a post's ENTIRE pending-proposal set in one call — used by
	 * finalize_tags() (invariant 1's sole writer of PROPOSAL_META), which
	 * always recomputes the full set fresh on every approval/re-tag rather
	 * than adding incrementally. Clears whatever was there before (both the
	 * PROPOSAL_META rows and the timestamp map) and writes the new set, all
	 * sharing this one call's timestamp — every proposal surviving one
	 * finalize_tags() pass was, by definition, proposed at the same moment.
	 *
	 * @param array<string> $names Distinct proposal names to record — already
	 *                              gated/deduped by the caller.
	 */
	public static function replace_proposals( int $post_id, array $names ): void {
		delete_post_meta( $post_id, self::PROPOSAL_META );
		delete_post_meta( $post_id, self::PROPOSAL_CREATED_META );

		if ( empty( $names ) ) {
			return;
		}

		foreach ( $names as $name ) {
			add_post_meta( $post_id, self::PROPOSAL_META, $name, false );
		}

		update_post_meta(
			$post_id,
			self::PROPOSAL_CREATED_META,
			wp_json_encode( array_fill_keys( $names, time() ), JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Clear exactly ONE pending proposal (by name) from a post — used by
	 * Admin\TagProposals' approve/reject handlers and the TTL sweep, all of
	 * which resolve proposals one at a time. Removes both the PROPOSAL_META
	 * row (3-arg delete_post_meta() — never touches a sibling pending
	 * proposal the same post also carries) and that name's entry in the
	 * timestamp map, so the map never accumulates ghost entries for
	 * proposals that no longer exist.
	 *
	 * TAG-REDESIGN.md F3 (§6c): fires `agnosis_tag_proposal_resolved` with
	 * the post id — the single choke point all three TagProposals resolve
	 * paths (approve/reject/sweep_expired) already share, so
	 * `Network\FederationSettlement::maybe_settle()` (the sole listener,
	 * wired in Core\Plugin) only needs registering once here rather than at
	 * each of those three call sites individually. Fires even when this
	 * post still carries OTHER pending proposals — `maybe_settle()` re-checks
	 * settlement itself and simply no-ops if the post isn't actually clear
	 * yet, so an extra fire here costs nothing.
	 */
	public static function clear_proposal( int $post_id, string $name ): void {
		delete_post_meta( $post_id, self::PROPOSAL_META, $name );

		$raw = (string) get_post_meta( $post_id, self::PROPOSAL_CREATED_META, true );
		$map = '' !== $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $map ) ) {
			delete_post_meta( $post_id, self::PROPOSAL_CREATED_META );
		} else {
			unset( $map[ $name ] );
			if ( empty( $map ) ) {
				delete_post_meta( $post_id, self::PROPOSAL_CREATED_META );
			} else {
				update_post_meta( $post_id, self::PROPOSAL_CREATED_META, wp_json_encode( $map, JSON_UNESCAPED_UNICODE ) );
			}
		}

		do_action( 'agnosis_tag_proposal_resolved', $post_id );
	}

	/**
	 * Associate a gated candidate list onto a post — THE ONE shared
	 * implementation TAG-REDESIGN.md's invariant 8 requires when it says
	 * Re-tag "is not a special path: it calls the same acquisition, gate,
	 * association, and propagation code as a real approval." Originally
	 * `ReviewEndpoints::finalize_tags()` v2's own inline body; factored out
	 * here so `Publishing\Retag::run()` calls the identical logic rather
	 * than a parallel reimplementation.
	 *
	 * Gate → normalize + de-dup + junk-reject (in original order) → split
	 * into matched (existing term, assign by ID) vs. new (unmatched,
	 * becomes a proposal row) → cap the TOTAL accepted set at {tag_count},
	 * matched names taking precedence over new proposals when trimming (§2)
	 * — anything trimmed off the cap is dropped entirely, never becoming
	 * either an association or a proposal row → `wp_set_object_terms()` by
	 * ID only (invariant 3; empty $matched_ids is a valid, correct call —
	 * it clears a post whose every candidate was junk/proposal-only, not
	 * skipped) → `replace_proposals()` for whatever's left over (also
	 * replaces, not appends — the "assign by ID, replace not append" §2
	 * requires of Retag specifically, and exactly what an ordinary
	 * resubmission already needed since its AI re-derives tags fresh from
	 * the current content, same as it re-derives medium).
	 *
	 * Callers are responsible for the "no candidates at all" case (an
	 * absent/empty '_agnosis_tag_candidates', or — for Retag — an AI call
	 * that produced nothing): this method assumes $candidates is a
	 * genuinely non-empty list and will happily wp_set_object_terms() an
	 * empty $matched_ids if every entry gates out, which is the correct
	 * behavior once a caller has decided to invoke this at all.
	 *
	 * @param array<mixed> $candidates Raw candidate values (already
	 *                                 JSON-decoded by the caller) — non-string
	 *                                 entries are silently skipped.
	 * @return array{matched: int, proposed: int, gated: int} Counts for the
	 *                                 caller's own logging/UI — gated counts
	 *                                 both junk rejects and cap trims.
	 */
	public static function associate( int $post_id, array $candidates ): array {
		$vocabulary = self::vocabulary_map();
		$tag_count  = PromptConfig::from_options()->tag_count;

		$matched = []; // normalized => term_id
		$new     = []; // normalized => display text (original casing, trimmed)
		$seen    = [];

		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}
			$normalized = self::normalize_for_match( $candidate );
			if ( self::is_junk( $normalized ) || isset( $seen[ $normalized ] ) ) {
				continue;
			}
			$seen[ $normalized ] = true;

			if ( isset( $vocabulary[ $normalized ] ) ) {
				$matched[ $normalized ] = $vocabulary[ $normalized ];
			} else {
				$new[ $normalized ] = trim( $candidate );
			}
		}

		$matched_ids = array_values( $matched );
		if ( count( $matched_ids ) > $tag_count ) {
			$matched_ids = array_slice( $matched_ids, 0, $tag_count );
		}
		$remaining_budget = max( 0, $tag_count - count( $matched_ids ) );
		$new_kept          = array_slice( array_values( $new ), 0, $remaining_budget );

		wp_set_object_terms( $post_id, $matched_ids, 'post_tag' );
		self::replace_proposals( $post_id, $new_kept );

		return [
			'matched'  => count( $matched_ids ),
			'proposed' => count( $new_kept ),
			'gated'    => count( $candidates ) - count( $matched_ids ) - count( $new_kept ),
		];
	}

	/**
	 * Comparison-key normalization ONLY — never used to rewrite a stored
	 * display name; display casing always stays whatever the first
	 * creation used (TW-9's own explicit constraint).
	 *
	 * Steps: trim outer whitespace, collapse internal whitespace runs to a
	 * single space, strip trailing '.'/',' (an observed AI artifact class —
	 * TW-9), NFC-normalize via the `intl` extension's Normalizer when it's
	 * loaded (same soft-dependency, graceful-degradation convention as
	 * Core\DateFormatter's IntlDateFormatter use — passes through
	 * unnormalized when intl isn't available rather than erroring), then
	 * mb_strtolower.
	 */
	public static function normalize_for_match( string $name ): string {
		$name = trim( $name );
		$name = (string) preg_replace( '/\s+/u', ' ', $name );
		$name = rtrim( $name, '.,' );

		if ( class_exists( '\Normalizer' ) ) {
			try {
				$normalized = \Normalizer::normalize( $name, \Normalizer::FORM_C );
			} catch ( \Throwable $e ) {
				$normalized = false;
			}
			if ( is_string( $normalized ) && '' !== $normalized ) {
				$name = $normalized;
			}
		}

		return mb_strtolower( $name, 'UTF-8' );
	}

	/**
	 * True when $normalized_name (the OUTPUT of normalize_for_match() — call
	 * that first) is junk that must never become, or match, a real tag
	 * candidate. TAG-REDESIGN.md §2's own list:
	 *   - empty
	 *   - the literal string "array" (a PHP array-to-string-conversion
	 *     artifact seen live)
	 *   - a "mangling signature" (TW-14/TW-15 — a `uXXXX` hex run with its
	 *     escaping backslash stripped; the exact shape of the live
	 *     "connexiu00f3" corruption — no word in any real language
	 *     plausibly contains "u" immediately followed by four hex digits
	 *     as a coincidence)
	 *   - length < 2 characters
	 *   - a bare number that isn't a plausible 4-digit year (1000-2999 —
	 *     wide enough to admit any real artwork date without also admitting
	 *     "42" or a stray numeric fragment)
	 */
	public static function is_junk( string $normalized_name ): bool {
		if ( '' === $normalized_name ) {
			return true;
		}

		if ( 'array' === $normalized_name ) {
			return true;
		}

		if ( preg_match( '/u[0-9a-f]{4}/', $normalized_name ) ) {
			return true;
		}

		if ( mb_strlen( $normalized_name, 'UTF-8' ) < 2 ) {
			return true;
		}

		if ( preg_match( '/^[0-9]+$/', $normalized_name ) ) {
			$as_int = (int) $normalized_name;
			if ( $as_int < 1000 || $as_int > 2999 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The primary tag vocabulary as a normalize_for_match()-keyed map to
	 * term ID. Excludes terms flagged with `LinguaForge::TRANSLATED_TERM_META`
	 * — the same exclusion `PromptConfig::existing_tags()`/`medium_terms()`
	 * already apply (a translated-sibling term is not part of the primary
	 * vocabulary an association or a TagProposals reuse-check should ever
	 * match against).
	 *
	 * Two callers, one implementation: ReviewEndpoints::finalize_tags() v2
	 * (bulk-matching every gated candidate on one post) and
	 * Admin\TagProposals' own double-approval-tolerant reuse check ("does a
	 * term with this normalized name already exist among PRIMARY terms" —
	 * TW-9: a name-exact `get_term_by()`/`term_exists()` lookup would miss a
	 * case/whitespace/diacritic variant the way MediumProposals' current
	 * exact-match check still can).
	 *
	 * @return array<string, int> normalized name => term_id.
	 */
	public static function vocabulary_map( string $taxonomy = 'post_tag' ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}

		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'fields'     => 'id=>name',
			'hide_empty' => false,
			'meta_query' => [
				[
					'key'     => \Agnosis\Compat\LinguaForge::TRANSLATED_TERM_META,
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		$map = [];
		foreach ( $terms as $term_id => $name ) {
			// First-seen wins on a normalized collision among PRIMARY terms
			// themselves (e.g. two admin-created terms differing only in
			// case) — extremely rare and itself a pre-existing data-quality
			// question this lookup shouldn't silently resolve one way or
			// the other; recorded here only so behavior is deterministic.
			$normalized = self::normalize_for_match( (string) $name );
			if ( ! isset( $map[ $normalized ] ) ) {
				$map[ $normalized ] = (int) $term_id;
			}
		}

		return $map;
	}
}
