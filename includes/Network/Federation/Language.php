<?php
/**
 * Language resolution — which language a post, and therefore its federated
 * object, actually speaks.
 *
 * Seventh unit, added at WP7 (sixteenth audit, Q-2 —
 * agnosis-audit/ACTIVITYPUB-SPLIT-ROADMAP.md §5f). It exists because these two
 * methods kept being pushed *downward*: three separate work packages found a
 * language helper shared across layers and, following §0c\'s "a shared need moves
 * down" rule, parked it in whatever bottom-layer class already existed —
 * `Identity`. That was defensible for URL-resolution primitives and wrong for
 * this: resolving an artwork\'s `_lf_lang` is not identity, and a class called
 * `Identity` holding it stops the name from describing the contents.
 *
 * Two members, not the three §5f first scoped. `is_primary_language_post()` was
 * the third; it walked the same chain to return a yes/no rather than a code, and
 * TAG-REDESIGN.md F2 deleted both its call sites when language siblings started
 * federating in their own right. It was confirmed dead and removed 2026-08-06.
 *
 * **Depends on nothing**, and sits at the bottom of the layering beside
 * `Identity` rather than beneath or above it — the two never call each other:
 *
 *     Identity / **Language** -> Delivery -> Interactions / Rhizome / Follows -> Replies -> Serialization
 *
 * The two methods answer deliberately different questions and are both kept:
 * `resolve_note_language()` always returns a usable code (falling back to the
 * configured primary language, then the site locale), which is what an AS2
 * `contentMap` key requires; `resolve_post_lf_lang()` returns `\'\'` for a post
 * with no `_lf_lang` at all, because "this IS the primary-language post" is
 * itself the signal its callers branch on. Collapsing them would lose that.
 *
 * @package Agnosis\Network\Federation
 */

declare(strict_types=1);

namespace Agnosis\Network\Federation;

use Agnosis\Compat\LinguaForge;

class Language {

	/**
	 * The BCP-47-ish language code a Note's `contentMap` should be keyed with
	 * (TAG-REDESIGN.md F1): the post's own `_lf_lang` meta when it has one,
	 * otherwise the configured primary language, otherwise the site locale.
	 *
	 * It was written (F1) as a deliberate sibling of the then-existing
	 * `is_primary_language_post()`, which walked the same chain but returned a
	 * yes/no rather than the code — F1 was scoped as the smallest possible
	 * change, so the two coexisted rather than one being refactored into the
	 * other. **F2 then removed both of that method's call sites** when siblings
	 * started federating in their own right, leaving it dead; it was deleted
	 * 2026-08-06 after the Q-2 split surfaced it. This is now the only
	 * implementation of that chain, which is where F1 would have arrived
	 * directly had the sibling design landed first.
	 */
	public function resolve_note_language( int $post_id ): string {
		$lf_lang = sanitize_key( (string) get_post_meta( $post_id, '_lf_lang', true ) );
		if ( '' !== $lf_lang ) {
			return $lf_lang;
		}

		$primary = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		if ( '' !== $primary ) {
			return $primary;
		}

		return LinguaForge::locale_to_lang( get_locale() );
	}

	/**
	 * This post's own LF language, or '' when it IS the site's primary-
	 * language post (no `_lf_lang` meta at all — the same convention already
	 * used elsewhere in this class, e.g. singular_activity_json()'s own
	 * `contentMap` resolution) — never a guess, always read straight off the
	 * post being viewed.
	 */
	public function resolve_post_lf_lang( int $post_id ): string {
		return sanitize_key( (string) get_post_meta( $post_id, '_lf_lang', true ) );
	}
}
