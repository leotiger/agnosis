<?php
/**
 * wipe-tags.php — one-time cleanup of the corrupted `post_tag` vocabulary
 * (2026-07-24 tag/trid incident — see PostCreator::write_post_meta()'s and
 * LinguaForge::resolve_primary_tags()'s own docblocks for the root cause).
 *
 * UNLIKE every other script in dev/bin/ (translate-missing.php,
 * fill-loco-gaps.php, translate-theme-missing.php — all explicitly "never
 * runs inside WordPress"), this one deliberately DOES need a full WP
 * bootstrap: it deletes real `post_tag` terms from the live site. Run it
 * with WP-CLI's eval-file, which loads WordPress first:
 *
 *   wp eval-file dev/bin/wipe-tags.php                  # dry run (default, safe)
 *   wp eval-file dev/bin/wipe-tags.php --yes             # actually deletes
 *   wp eval-file dev/bin/wipe-tags.php --yes --rebuild   # also re-tags already-published posts where that's free (see below)
 *
 * ONLY run this AFTER the 2026-07-24 tag-timing fix is deployed
 * (PostCreator::write_post_meta() no longer tags at intake;
 * ReviewEndpoints::finalize_tags() is the one place tags are ever assigned,
 * at approval). Wiping first and deploying the fix second just lets the
 * still-buggy pipeline re-corrupt a freshly emptied vocabulary the next
 * time anything is approved.
 *
 * WHAT THIS DELETES
 * -----------------
 * Every `post_tag` term, full stop — every language bucket (the corrupted
 * "primary" bucket, every Lingua Forge-translated one), the near-duplicate
 * spelling variants (e.g. the 8+ "anthropology" spellings seen live), the
 * literal "Array"/"connexiu00f3" mangled entries, and every genuinely-used
 * tag right along with them. There is no way to keep only the "good" ones —
 * the entire point of this cleanup is that which ones are good is exactly
 * what's unreliable right now. wp_delete_term() is used per term (not a raw
 * DELETE query), so term relationships, term meta, and object term caches
 * are all cleaned up the normal WordPress way.
 *
 * `agnosis_medium` is NOT touched — that taxonomy was never reported as
 * affected, and this script deliberately stays scoped to `post_tag`.
 *
 * WHAT SURVIVES UNTOUCHED
 * -----------------------
 * Every post's own `_agnosis_native_tags` postmeta cache (the artist's
 * native-language tag names, written at intake) is left exactly as-is.
 * Nothing needs to be re-submitted by an artist — the next time any post
 * goes through approval, ReviewEndpoints::finalize_tags() rebuilds its real
 * tags cleanly from that same cache, under the fixed pipeline.
 *
 * REBUILDING ALREADY-PUBLISHED POSTS (--rebuild)
 * ------------------------------------------------
 * finalize_tags() only ever runs at approval — wiping the taxonomy does NOT
 * by itself put tags back on already-PUBLISHED posts. Pass --rebuild to
 * additionally re-tag every currently-published post that has a non-empty
 * `_agnosis_native_tags` cache:
 *   - Artist's native language is empty or already matches the site's
 *     primary language: the cached names ARE the primary vocabulary —
 *     assigned directly, zero AI cost, zero risk.
 *   - Artist's native language differs from primary: resolved via ONE AI
 *     call per post (SubmissionTranslator::translate_fields(), scoped to
 *     just the 'tags' field — this never touches the post's own
 *     already-published title/excerpt/content), then reconciled through
 *     the exact same LinguaForge::resolve_primary_tags()/
 *     assign_resolved_primary_tags() path a real approval uses. A post
 *     whose translation fails (or whose every resolved candidate collides
 *     with an already-flagged native term) falls back to its native-
 *     language tags, correctly flagged as non-primary rather than left
 *     tagless — reported at the end so you know which ones need a second
 *     look, same as finalize_tags()'s own fallback at approval time.
 *   - `--rebuild` alone (no --yes) previews counts only — it never spends a
 *     real AI call or writes anything; only `--yes --rebuild` together
 *     actually calls out and assigns tags.
 *
 * @package Agnosis
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script needs a full WordPress bootstrap and must be run via WP-CLI:\n";
	echo "  wp eval-file dev/bin/wipe-tags.php            # dry run\n";
	echo "  wp eval-file dev/bin/wipe-tags.php --yes       # actually deletes\n";
	exit( 1 );
}

$args     = $args ?? []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP-CLI eval-file convention, matches how --assoc/positional args are normally read.
$assoc    = $assoc_args ?? []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
$confirm  = ! empty( $assoc['yes'] );
$rebuild  = ! empty( $assoc['rebuild'] );

WP_CLI::log( '' );
WP_CLI::log( $confirm ? 'Running for real — terms will be deleted.' : 'DRY RUN — pass --yes to actually delete anything.' );
WP_CLI::log( '' );

// -----------------------------------------------------------------------
// Step 1 — wipe every post_tag term.
// -----------------------------------------------------------------------

$term_ids = get_terms( [
	'taxonomy'   => 'post_tag',
	'hide_empty' => false,
	'fields'     => 'ids',
] );

if ( is_wp_error( $term_ids ) ) {
	WP_CLI::error( 'get_terms() failed: ' . $term_ids->get_error_message() );
}

WP_CLI::log( sprintf( 'Found %d post_tag term(s).', count( $term_ids ) ) );

$deleted = 0;
$failed  = [];

foreach ( $term_ids as $term_id ) {
	$term_id = (int) $term_id;

	if ( ! $confirm ) {
		continue;
	}

	$result = wp_delete_term( $term_id, 'post_tag' );
	if ( is_wp_error( $result ) || false === $result ) {
		$failed[] = $term_id;
		continue;
	}

	++$deleted;
}

if ( $confirm ) {
	WP_CLI::success( sprintf( 'Deleted %d post_tag term(s).', $deleted ) );
	if ( ! empty( $failed ) ) {
		WP_CLI::warning( sprintf( 'Failed to delete %d term(s): %s', count( $failed ), implode( ', ', $failed ) ) );
	}
} else {
	WP_CLI::log( sprintf( 'Would delete %d post_tag term(s). Re-run with --yes to actually delete them.', count( $term_ids ) ) );
}

// -----------------------------------------------------------------------
// Step 2 (optional, --rebuild) — re-tag already-published posts.
// -----------------------------------------------------------------------

if ( ! $rebuild ) {
	WP_CLI::log( '' );
	WP_CLI::log( 'Skipping rebuild pass (pass --rebuild to re-tag already-published posts from their cached native tags).' );
	exit( 0 );
}

WP_CLI::log( '' );
WP_CLI::log( 'Rebuild pass — re-tagging already-published posts from their _agnosis_native_tags cache…' );

$translator   = \Agnosis\AI\SubmissionTranslator::from_settings();
$primary_lang = $translator instanceof \Agnosis\AI\SubmissionTranslator ? $translator->resolve_target_language() : '';

$post_types = [ 'agnosis_artwork', 'agnosis_biography', 'agnosis_event' ];
$published  = get_posts( [
	'post_type'      => $post_types,
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
	'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-time maintenance script, not a hot path.
		[
			'key'     => '_agnosis_native_tags',
			'compare' => 'EXISTS',
		],
	],
] );

$retagged   = 0;
$ai_retagged = 0;
$ai_failed   = [];

foreach ( $published as $post_id ) {
	$post_id = (int) $post_id;

	$decoded = json_decode( (string) get_post_meta( $post_id, '_agnosis_native_tags', true ), true );
	$names   = is_array( $decoded )
		? array_values( array_filter( array_map( 'strval', $decoded ), static fn( $v ) => '' !== trim( $v ) ) )
		: [];

	if ( empty( $names ) ) {
		continue;
	}

	$native_lang = (string) get_post_meta( $post_id, '_agnosis_native_lang', true );

	if ( '' === $native_lang || '' === $primary_lang || $native_lang === $primary_lang ) {
		// Free case — these names already ARE the primary vocabulary.
		if ( $confirm ) {
			wp_set_post_tags( $post_id, $names );
		}
		++$retagged;
		continue;
	}

	// Cross-language case — needs the same one AI call
	// translate_native_content_to_primary() would spend at a real approval,
	// scoped here to JUST the 'tags' field (never touches this
	// already-published post's own title/excerpt/content). Skipped
	// entirely on a dry run so a --rebuild preview never spends real API
	// budget by itself — only --yes --rebuild actually calls out.
	if ( ! $confirm ) {
		++$ai_retagged; // Counted as "would attempt" for the dry-run summary below.
		continue;
	}

	if ( ! $translator instanceof \Agnosis\AI\SubmissionTranslator ) {
		$ai_failed[] = $post_id; // No AI provider configured — nothing to call.
		continue;
	}

	$field_instructions = [];
	$existing_primary    = \Agnosis\AI\PromptConfig::existing_tags_for_language( $primary_lang );
	if ( ! empty( $existing_primary ) ) {
		$field_instructions['tags'] = 'When translating, if a tag means the same as one of these already-existing tags, use its EXACT existing text instead of inventing new wording: '
			. implode( ' | ', $existing_primary );
	}

	$translated = $translator->translate_fields(
		[ 'tags' => implode( ' | ', $names ) ],
		$primary_lang,
		$field_instructions,
		$native_lang
	);

	$tags_translated = isset( $translated['tags'] ) && '' !== trim( $translated['tags'] );

	if ( $tags_translated ) {
		\Agnosis\AI\CallCounter::record( $post_id, 'tag_rebuild' );

		$resolved_names = array_values( array_filter( array_map( 'trim', explode( '|', $translated['tags'] ) ) ) );
		$primary_ids    = ( new \Agnosis\Compat\LinguaForge() )->resolve_primary_tags( [], $resolved_names );

		if ( ! empty( $primary_ids ) ) {
			( new \Agnosis\Compat\LinguaForge() )->assign_resolved_primary_tags( $post_id, $primary_ids );
			++$ai_retagged;
			continue;
		}
	}

	// Translation failed, or every resolved candidate collided with an
	// already-flagged native term (see LinguaForge::resolve_primary_tags()'s
	// own collision-fallback comment) — same fallback finalize_tags() uses
	// at a real approval: publish the native tags as-is, correctly flagged
	// as native-language rather than left tagless.
	wp_set_post_tags( $post_id, $names );
	$ai_failed[] = $post_id;
}

if ( $confirm ) {
	WP_CLI::success( sprintf( 'Re-tagged %d already-published post(s) directly (no AI call needed).', $retagged ) );
	WP_CLI::success( sprintf( 'Re-tagged %d already-published post(s) via a resolved AI translation call.', $ai_retagged ) );
	if ( ! empty( $ai_failed ) ) {
		WP_CLI::warning( sprintf(
			'%d post(s) fell back to native-language tags (flagged, not primary) — translation failed or no AI provider configured. Post IDs: %s',
			count( $ai_failed ),
			implode( ', ', $ai_failed )
		) );
	}
} else {
	WP_CLI::log( sprintf( 'Would re-tag %d already-published post(s) directly (no AI call needed).', $retagged ) );
	WP_CLI::log( sprintf( 'Would attempt an AI-resolved retag for %d already-published post(s) (only with --yes).', $ai_retagged ) );
}
