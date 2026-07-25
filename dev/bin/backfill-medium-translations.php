<?php
/**
 * backfill-medium-translations.php — T5(a): one-shot backfill completing
 * every primary agnosis_medium term's trid group across every active
 * language, so the manual "Sync all translations" ritual becomes
 * genuinely obsolete for medium too (TAG-REDESIGN.md §4, T5(a)).
 *
 * Mechanism: T3(b)'s auto-translation queue, run once against every
 * EXISTING primary medium term instead of only newly-approved ones — no
 * separate implementation. Per incomplete term:
 * `Compat\LinguaForge::queue_translation_for_term()` queues every active
 * target language (safe to call even on a partially- or fully-translated
 * term — it just overwrites the pending-language marker with the full
 * list; the drain step below independently checks each language via
 * `find_term_by_trid()` before ever creating anything, so a language that
 * already has a linked sibling costs one lookup and nothing else), then
 * this script drains the queue ITSELF by repeatedly calling
 * `LinguaForge::drain_translation_queue()` (normally a `every_five_minutes`
 * cron tick, time-budgeted per call) until nothing `agnosis_medium` is left
 * pending — so one script run genuinely *completes* the backfill rather
 * than trickling across cron ticks over the following hours, matching §4's
 * "completing every trid group" wording.
 *
 * Existing translated medium terms KEEP their current slugs untouched —
 * the machine-slug rule (TAG-REDESIGN.md §1.3) applies to new inserts
 * only; this backfill only ever creates whatever's genuinely MISSING from
 * a term's group, never re-slugs or re-creates an already-existing
 * translation (that's exactly what the per-language find_term_by_trid()
 * check inside the drain step guarantees).
 *
 * Dry-run preview mirrors (read-only) the exact trid+language lookup
 * `LinguaForge::find_term_by_trid()` uses internally — that method and
 * `get_or_create_term_trid()` are both private, so this script can't call
 * them directly, but the same meta_query shape is public knowledge (both
 * meta keys it reads are public constants) and costs nothing to repeat for
 * an accurate "already complete" / "needs backfill" preview split without
 * queuing or writing anything.
 *
 * Dry-run by default — reports counts, calls no AI, changes nothing (does
 * not even write the queue marker). Pass "yes" to actually execute.
 *
 * Usage (from a wp-env shell, or `wp eval-file` from the host if wp-env's
 * wp is on PATH):
 *   wp eval-file dev/bin/backfill-medium-translations.php          # dry run (default)
 *   wp eval-file dev/bin/backfill-medium-translations.php yes       # execute
 *
 * @package Agnosis
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script needs a full WordPress bootstrap and must be run via WP-CLI:\n";
	echo "  wp eval-file dev/bin/backfill-medium-translations.php [yes]\n";
	exit( 1 );
}

use Agnosis\Compat\LinguaForge;

// Same guard queue_translation_for_term()/drain_translation_queue() apply
// themselves — backfilling translations makes no sense without LF active.
if ( ! function_exists( 'linguaforge_languages' ) ) {
	WP_CLI::error( 'Lingua Forge is not active — nothing to translate into.' );
}

$args    = $args ?? []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP-CLI eval-file convention.
$execute = in_array( 'yes', $args, true );

WP_CLI::log( $execute ? '=== backfill-medium-translations.php — EXECUTING ("yes" passed) ===' : '=== backfill-medium-translations.php — DRY RUN (pass "yes" to execute) ===' );
WP_CLI::log( '' );

// -----------------------------------------------------------------------
// 0 — Every PRIMARY agnosis_medium term (excludes already-translated
// siblings — same exclusion Publishing\TagGate::vocabulary_map() applies)
// and the active target-language list (all LF languages minus primary).
// -----------------------------------------------------------------------

$primary_lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
$targets      = array_values( array_filter(
	linguaforge_languages(),
	static function ( $lang ) use ( $primary_lang ) {
		return $lang !== $primary_lang;
	}
) );

if ( empty( $targets ) ) {
	WP_CLI::error( 'No target languages configured beyond the primary — nothing to backfill.' );
}

$primary_terms = get_terms( [
	'taxonomy'   => 'agnosis_medium',
	'hide_empty' => false,
	'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off WP-CLI backfill, not a hot path.
		[
			'key'     => LinguaForge::TRANSLATED_TERM_META,
			'compare' => 'NOT EXISTS',
		],
	],
] );
$primary_terms = is_wp_error( $primary_terms ) ? [] : $primary_terms;

WP_CLI::log( sprintf( 'Primary agnosis_medium terms found: %d', count( $primary_terms ) ) );
WP_CLI::log( sprintf( 'Active target language(s): %s', implode( ', ', $targets ) ) );
WP_CLI::log( '' );

// -----------------------------------------------------------------------
// 1 — Preview: which primary terms are already trid-complete across every
// target language, vs. which are missing at least one. Read-only.
// -----------------------------------------------------------------------

$complete_ids   = [];
$incomplete_ids = [];

foreach ( $primary_terms as $term ) {
	if ( ! $term instanceof \WP_Term ) {
		continue;
	}

	$trid    = (string) get_term_meta( $term->term_id, LinguaForge::TERM_TRID_META, true );
	$missing = false;

	if ( '' === $trid ) {
		$missing = true; // Never synced at all.
	} else {
		foreach ( $targets as $lang ) {
			$existing = get_terms( [
				'taxonomy'   => 'agnosis_medium',
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off WP-CLI backfill preview, not a hot path.
					'relation' => 'AND',
					[ 'key' => LinguaForge::TERM_TRID_META, 'value' => $trid ],
					[ 'key' => LinguaForge::TRANSLATED_TERM_META, 'value' => $lang ],
				],
			] );
			if ( is_wp_error( $existing ) || empty( $existing ) ) {
				$missing = true;
				break;
			}
		}
	}

	if ( $missing ) {
		$incomplete_ids[] = (int) $term->term_id;
	} else {
		$complete_ids[] = (int) $term->term_id;
	}
}

WP_CLI::log( sprintf( 'Already complete across every target language: %d', count( $complete_ids ) ) );
WP_CLI::log( sprintf( '%s: %d', $execute ? 'Queuing for backfill' : 'Would queue for backfill', count( $incomplete_ids ) ) );
WP_CLI::log( '' );

// -----------------------------------------------------------------------
// 2 — Queue every incomplete term, then drain synchronously until the
// agnosis_medium branch of the pending-translation queue is empty (or a
// safety cap is hit) — "one backfill run... completing every trid group"
// rather than trickling across multiple 5-minute cron ticks.
// -----------------------------------------------------------------------

if ( $execute && ! empty( $incomplete_ids ) ) {
	foreach ( $incomplete_ids as $term_id ) {
		LinguaForge::queue_translation_for_term( $term_id, 'agnosis_medium' );
	}

	$service    = new LinguaForge();
	$max_passes = 50; // Safety cap — each pass is time-budgeted (15s) on its own; a healthy queue drains in a small handful of passes.
	$passes     = 0;
	$remaining  = count( $incomplete_ids );

	do {
		$service->drain_translation_queue();
		++$passes;

		$still_pending = get_terms( [
			'taxonomy'   => 'agnosis_medium',
			'hide_empty' => false,
			'fields'     => 'ids',
			'meta_key'   => LinguaForge::TERM_PENDING_TRANSLATION_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off WP-CLI backfill loop, not a hot path.
		] );
		$remaining = is_wp_error( $still_pending ) ? 0 : count( $still_pending );

		WP_CLI::log( sprintf( '  pass %d: %d term(s) still pending.', $passes, $remaining ) );
	} while ( $remaining > 0 && $passes < $max_passes );

	WP_CLI::log( '' );
	if ( $remaining > 0 ) {
		WP_CLI::warning( sprintf(
			'%d term(s) still pending after %d passes — a genuine per-language insert failure is keeping them queued (logged individually by drain_translation_queue() itself). Safe to re-run this script later, or let the every_five_minutes cron continue draining them.',
			$remaining,
			$passes
		) );
	}
}

// -----------------------------------------------------------------------
// Summary.
// -----------------------------------------------------------------------

WP_CLI::log( '' );
if ( ! $execute ) {
	WP_CLI::log( 'Dry run only — no AI calls made, nothing was changed. Re-run with "yes" to execute:' );
	WP_CLI::log( '  wp eval-file dev/bin/backfill-medium-translations.php yes' );
} else {
	WP_CLI::success( 'Backfill complete. Re-run this script (with "yes") to verify idempotence — every term should now report as already complete (zero re-queued).' );
}
