<?php
/**
 * fresh-tags.php — T0 demolition: wipe every tag-related record left over
 * from the removed tag workflow (TAG-REDESIGN.md, T0 — "remove all legacy
 * tag machinery, wipe tag data"; Ulises's own framing: "First step: remove
 * everything related to tags — a fresh restart.").
 *
 * Deletes:
 *   1. Every `post_tag` term (wp_delete_term() — cascades term-relationships
 *      and term meta for each one; any trid/TRANSLATED_TERM_META meta on a
 *      post_tag term goes with it, nothing left orphaned).
 *   2. All `_agnosis_native_tags` post meta — the removed intake-time
 *      cache. Belt-and-braces: no code path writes this key any more after
 *      T0's code removal, so this step only ever clears what a pre-T0
 *      build already wrote before this deploy.
 *   3. Only the `post_tag` branch of the shared `agnosis_term_translations`
 *      option cache (Compat\LinguaForge::TERM_TRANSLATIONS_OPTION,
 *      hardcoded here as that constant is private to that class) —
 *      `agnosis_medium`'s branch is left untouched; that cache is healthy
 *      and already paid for in real AI translation calls (TAG-REDESIGN.md
 *      §1, §3).
 *
 * Deliberately NO rebuild pass. The site is tagless after this runs — that
 * IS the fresh restart TAG-REDESIGN.md's ratified model calls for. Tag
 * acquisition and association return in T1/T2.
 *
 * Dry-run by default — reports counts, changes nothing. Pass "yes" to
 * actually execute. Idempotent: run it twice with "yes" and the second
 * run's counts must all read zero.
 *
 * Version-guarded functionally, not by a hardcoded version-number
 * threshold: refuses to run against a plugin build that still has the OLD
 * tag-association code loaded — Compat\LinguaForge::resolve_primary_tags()
 * still existing is the tell. Running the wipe against that build would
 * just let the old writer immediately repopulate what this script just
 * deleted — exactly the "wiping while old writers are live just
 * repopulates corruption" failure TAG-REDESIGN.md's own T0 roadmap entry
 * warns about. A version-number check would need a specific number from
 * Ulises's own version bump, which had not happened yet at the point this
 * script was written; checking the removed method's actual absence is
 * self-verifying regardless of what version number this eventually ships
 * under.
 *
 * Usage (from a wp-env shell, or `wp eval-file` from the host if wp-env's wp
 * is on PATH):
 *   wp eval-file dev/bin/fresh-tags.php          # dry run (default)
 *   wp eval-file dev/bin/fresh-tags.php yes       # execute
 *
 * @package Agnosis
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script needs a full WordPress bootstrap and must be run via WP-CLI:\n";
	echo "  wp eval-file dev/bin/fresh-tags.php [yes]\n";
	exit( 1 );
}

use Agnosis\Compat\LinguaForge;

const AGNOSIS_FRESH_TAGS_TRANSLATIONS_OPTION = 'agnosis_term_translations'; // Mirrors LinguaForge::TERM_TRANSLATIONS_OPTION (private to that class).
const AGNOSIS_FRESH_TAGS_NATIVE_TAGS_META    = '_agnosis_native_tags';

$args    = $args ?? []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP-CLI eval-file convention.
$execute = in_array( 'yes', $args, true );

WP_CLI::log( $execute ? '=== fresh-tags.php — EXECUTING ("yes" passed) ===' : '=== fresh-tags.php — DRY RUN (pass "yes" to execute) ===' );
WP_CLI::log( '' );

// -----------------------------------------------------------------------
// 0 — Version guard. See this file's own docblock for why this checks the
// removed method's absence rather than a version-number threshold.
// -----------------------------------------------------------------------

if ( method_exists( LinguaForge::class, 'resolve_primary_tags' ) ) {
	WP_CLI::error(
		'Refusing to run: Compat\LinguaForge::resolve_primary_tags() still exists on this build — the OLD ' .
		'tag-association code (TAG-REDESIGN.md §3) is still loaded. Wiping tag data now would just let that ' .
		'code immediately repopulate it. Deploy the T0 code removal first, then re-run this script.'
	);
}

// -----------------------------------------------------------------------
// 1 — post_tag terms.
// -----------------------------------------------------------------------

$term_ids = get_terms( [
	'taxonomy'   => 'post_tag',
	'fields'     => 'ids',
	'hide_empty' => false,
] );
$term_ids = is_wp_error( $term_ids ) ? [] : array_map( 'intval', $term_ids );

WP_CLI::log( sprintf( 'post_tag terms found: %d', count( $term_ids ) ) );

if ( $execute ) {
	$deleted_terms = 0;
	$failed_terms  = [];
	foreach ( $term_ids as $term_id ) {
		$result = wp_delete_term( $term_id, 'post_tag' );
		if ( true === $result ) {
			++$deleted_terms;
		} else {
			$failed_terms[] = $term_id;
		}
	}
	WP_CLI::log( sprintf( '  deleted: %d', $deleted_terms ) );
	if ( ! empty( $failed_terms ) ) {
		WP_CLI::warning( sprintf( '  failed to delete term_id(s): %s', implode( ', ', $failed_terms ) ) );
	}
}

// -----------------------------------------------------------------------
// 2 — `_agnosis_native_tags` post meta.
// -----------------------------------------------------------------------

global $wpdb;
$native_tags_post_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
		AGNOSIS_FRESH_TAGS_NATIVE_TAGS_META
	)
);
$native_tags_post_ids = array_map( 'intval', $native_tags_post_ids );

WP_CLI::log( sprintf( 'Posts carrying %s meta: %d', AGNOSIS_FRESH_TAGS_NATIVE_TAGS_META, count( $native_tags_post_ids ) ) );

if ( $execute ) {
	$deleted_meta = 0;
	foreach ( $native_tags_post_ids as $post_id ) {
		if ( delete_post_meta( $post_id, AGNOSIS_FRESH_TAGS_NATIVE_TAGS_META ) ) {
			++$deleted_meta;
		}
	}
	WP_CLI::log( sprintf( '  cleared: %d', $deleted_meta ) );
}

// -----------------------------------------------------------------------
// 3 — post_tag branch of the shared term-translation cache. agnosis_medium's
// branch is read and reported for verification, never touched.
// -----------------------------------------------------------------------

$cache = get_option( AGNOSIS_FRESH_TAGS_TRANSLATIONS_OPTION, [] );
$cache = is_array( $cache ) ? $cache : [];

$post_tag_entries = isset( $cache['post_tag'] ) && is_array( $cache['post_tag'] ) ? count( $cache['post_tag'] ) : 0;
$medium_entries   = isset( $cache['agnosis_medium'] ) && is_array( $cache['agnosis_medium'] ) ? count( $cache['agnosis_medium'] ) : 0;

WP_CLI::log( sprintf(
	'%s cache — post_tag entries: %d, agnosis_medium entries (untouched either way): %d',
	AGNOSIS_FRESH_TAGS_TRANSLATIONS_OPTION,
	$post_tag_entries,
	$medium_entries
) );

if ( $execute && $post_tag_entries > 0 ) {
	unset( $cache['post_tag'] );
	update_option( AGNOSIS_FRESH_TAGS_TRANSLATIONS_OPTION, $cache, false );
	WP_CLI::log( '  post_tag branch cleared.' );
}

// -----------------------------------------------------------------------
// Summary.
// -----------------------------------------------------------------------

WP_CLI::log( '' );
if ( ! $execute ) {
	WP_CLI::log( 'Dry run only — nothing was changed. Re-run with "yes" to execute:' );
	WP_CLI::log( '  wp eval-file dev/bin/fresh-tags.php yes' );
} else {
	WP_CLI::success( 'Fresh restart complete. Re-run this script (with "yes") to verify idempotence — every count above should now read zero (agnosis_medium excepted, which is never touched).' );
}
