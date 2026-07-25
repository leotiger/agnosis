<?php
/**
 * backfill-tag-candidates.php — T4 optional backfill: run the tag pipeline
 * once against every published, primary-language Agnosis post left tagless
 * by the T0 fresh restart (or never tagged at all), so legacy posts can
 * catch up to the new workflow without waiting for an admin to click
 * Re-tag on each one individually (TAG-REDESIGN.md §4, T4).
 *
 * "The T2 Retag service in a loop" — no separate implementation: per post
 * this calls exactly `(new Publishing\Retag())->run( $post_id )`, the
 * identical acquisition → gate → associate pipeline one Re-tag click runs
 * (invariant 8, TAG-REDESIGN.md §5: Re-tag is not a special path, and
 * neither is this backfill). Propagation to already-translated siblings and
 * auto-translation of any newly-approved vocabulary happen exactly the way
 * they do for a live approval or a manual Re-tag click — as side effects of
 * `Retag::run()`'s own `wp_set_object_terms()` call firing the
 * `set_object_terms` hook (`Compat\LinguaForge::on_term_assignment_changed()`,
 * T3(c)) and, later, of a real `Admin\TagProposals` approval queuing
 * translation for any name this backfill left as a proposal row
 * (T3(b)) — nothing in this script calls sync/queue code directly.
 * `AI\CallCounter` recording also happens automatically inside
 * `Retag::run()` itself; this script does not record it a second time.
 *
 * Running this, and when, is Ulises's budget call (§4: "the model is
 * complete without it; legacy posts can also just be re-tagged manually
 * over time") — one real AI call per eligible post it actually processes.
 *
 * Scope: all three Agnosis CPTs. `post_tag` is registered on all three, and
 * `Publishing\Retag`'s own class docblock already settled this exact
 * reading for T4's "per published primary-language post" wording (no CPT
 * restriction stated there, unlike §2's artwork-only framing of the button).
 *
 * Resumable — skips (without an AI call) any post that already has:
 *   - a non-empty `_agnosis_tag_candidates` meta value, or
 *   - at least one `post_tag` term already assigned.
 * Re-running the script is therefore a no-op once every eligible post has
 * been through it once (or been tagged some other way in the meantime).
 *
 * Structural (non-AI) eligibility mirrors `Retag::run()`'s own
 * primary-language gate exactly — re-read here so dry-run can report
 * accurately without calling run() at all (no AI spend on a preview).
 * `Retag::run()` re-checks the identical condition itself regardless of
 * what this script decided, so this preview can never drift into actually
 * processing a post run() would refuse.
 *
 * Dry-run by default — reports counts, calls no AI, changes nothing. Pass
 * "yes" to actually execute.
 *
 * Usage (from a wp-env shell, or `wp eval-file` from the host if wp-env's wp
 * is on PATH):
 *   wp eval-file dev/bin/backfill-tag-candidates.php          # dry run (default)
 *   wp eval-file dev/bin/backfill-tag-candidates.php yes       # execute
 *
 * @package Agnosis
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script needs a full WordPress bootstrap and must be run via WP-CLI:\n";
	echo "  wp eval-file dev/bin/backfill-tag-candidates.php [yes]\n";
	exit( 1 );
}

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Publishing\Retag;

// post_tag spans all three Agnosis CPTs — Publishing\Retag's own class docblock.
const AGNOSIS_BACKFILL_TAG_POST_TYPES = [ 'agnosis_artwork', 'agnosis_biography', 'agnosis_event' ];

$args    = $args ?? []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP-CLI eval-file convention.
$execute = in_array( 'yes', $args, true );

WP_CLI::log( $execute ? '=== backfill-tag-candidates.php — EXECUTING ("yes" passed) ===' : '=== backfill-tag-candidates.php — DRY RUN (pass "yes" to execute) ===' );
WP_CLI::log( '' );

// -----------------------------------------------------------------------
// 0 — Candidate posts: every published post across the three Agnosis CPTs.
// Primary-language / already-done filtering happens per-post below — see
// this file's own docblock for why that isn't folded into this query.
// -----------------------------------------------------------------------

$post_ids = get_posts( [
	'post_type'      => AGNOSIS_BACKFILL_TAG_POST_TYPES,
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'fields'         => 'ids',
] );

WP_CLI::log( sprintf( 'Published posts found across %s: %d', implode( ', ', AGNOSIS_BACKFILL_TAG_POST_TYPES ), count( $post_ids ) ) );

$primary_lang = SubmissionTranslator::resolve_target_language();

$skipped_not_primary  = 0;
$skipped_already_done = 0;
$eligible_ids         = [];

foreach ( $post_ids as $post_id ) {
	$post_id = (int) $post_id;

	// Same primary-language test Retag::run() itself performs.
	$native_lang = (string) get_post_meta( $post_id, '_agnosis_native_lang', true );
	if ( '' !== trim( $native_lang ) && $native_lang !== $primary_lang ) {
		++$skipped_not_primary;
		continue;
	}

	$has_candidates = '' !== trim( (string) get_post_meta( $post_id, '_agnosis_tag_candidates', true ) );

	$existing_tags = wp_get_post_terms( $post_id, 'post_tag', [ 'fields' => 'ids' ] );
	$has_tags      = is_array( $existing_tags ) && ! empty( $existing_tags );

	if ( $has_candidates || $has_tags ) {
		++$skipped_already_done;
		continue;
	}

	$eligible_ids[] = $post_id;
}

WP_CLI::log( sprintf( 'Skipped — not primary-language: %d', $skipped_not_primary ) );
WP_CLI::log( sprintf( 'Skipped — already has candidates or tags: %d', $skipped_already_done ) );
WP_CLI::log( sprintf( '%s: %d', $execute ? 'Processing' : 'Would process', count( $eligible_ids ) ) );
WP_CLI::log( '' );

// -----------------------------------------------------------------------
// 1 — Execute: one Retag::run() call per eligible post. Dry run stops here.
// -----------------------------------------------------------------------

if ( $execute ) {
	$service = new Retag();

	$succeeded      = 0;
	$total_matched  = 0;
	$total_proposed = 0;
	$total_gated    = 0;
	$failed         = [];

	foreach ( $eligible_ids as $post_id ) {
		$result = $service->run( $post_id );

		if ( $result['success'] ) {
			++$succeeded;
			$total_matched  += $result['matched'];
			$total_proposed += $result['proposed'];
			$total_gated    += $result['gated'];
			WP_CLI::log( sprintf(
				'  #%d: %d matched, %d proposed, %d gated.',
				$post_id,
				$result['matched'],
				$result['proposed'],
				$result['gated']
			) );
		} else {
			$failed[] = [
				'post_id' => $post_id,
				'reason'  => $result['reason'],
			];
			WP_CLI::warning( sprintf( '  #%d: failed — %s', $post_id, $result['reason'] ) );
		}
	}

	WP_CLI::log( '' );
	WP_CLI::log( sprintf(
		'Processed %d post(s) successfully (%d matched, %d proposed, %d gated in total); %d failed.',
		$succeeded,
		$total_matched,
		$total_proposed,
		$total_gated,
		count( $failed )
	) );
}

// -----------------------------------------------------------------------
// Summary.
// -----------------------------------------------------------------------

WP_CLI::log( '' );
if ( ! $execute ) {
	WP_CLI::log( 'Dry run only — no AI calls made, nothing was changed. Re-run with "yes" to execute:' );
	WP_CLI::log( '  wp eval-file dev/bin/backfill-tag-candidates.php yes' );
} else {
	WP_CLI::success( 'Backfill complete. Re-run this script (with "yes") to verify idempotence — every post processed above should now be skipped as "already has candidates or tags" (zero re-processed).' );
}
