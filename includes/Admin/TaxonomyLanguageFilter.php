<?php
/**
 * Language filter + on-demand medium-translation sync for the Tags/Mediums
 * wp-admin taxonomy screens (edit-tags.php).
 *
 * Every AI-translated term Lingua Forge creates lives in the SAME flat
 * taxonomy as the admin-curated original (Compat\LinguaForge::sync_taxonomy(),
 * flagged with TRANSLATED_TERM_META) — on an 18-language site that means the
 * Tags/Mediums admin screens mix every language's translated copy into one
 * list (746 tags, 38 mediums observed live), making the screen effectively
 * unusable for finding or managing the admin-curated vocabulary itself.
 *
 * This class does three independent things, all applying to BOTH
 * `agnosis_medium` and `post_tag`:
 *   1. Scopes the term list to one language at a time — the primary/admin-
 *      curated vocabulary by default, or any other configured language via a
 *      new dropdown (LanguageAwareTermsListTable).
 *   2. Adds a "Sync translations" row action on a primary-language term,
 *      which calls Compat\LinguaForge::sync_term_across_languages() to
 *      create any missing translated copy of just that one term on demand
 *      — see that method's own docblock for why this exists alongside the
 *      AI-fan-out's own automatic sync.
 *   3. Adds a one-click "Sync all translations" button next to the language
 *      dropdown, which calls Compat\LinguaForge::sync_all_terms_across_languages()
 *      to run the same per-term sync across every primary-language term on
 *      the current taxonomy in one pass — for filling in a whole backlog of
 *      missing translations at once, rather than clicking the row action
 *      one term at a time.
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

use Agnosis\Compat\LinguaForge;

class TaxonomyLanguageFilter {

	private const TARGET_TAXONOMIES = [ 'agnosis_medium', 'post_tag' ];

	/**
	 * `?agnosis_admin_lang=` sentinel meaning "don't scope by language at
	 * all" — the escape hatch for a term whose TRANSLATED_TERM_META names a
	 * language that's since been removed from Lingua Forge's configuration:
	 * without this, such a term is excluded from the default view (it has
	 * the meta) AND from every configured language's view (the meta value
	 * matches none of them), making it unreachable from any dropdown
	 * selection (audit AUDIT-0.9.38.md §2d). Not a real language code, so it
	 * can never collide with one.
	 */
	public const ALL_LANGUAGES_VALUE = 'all';

	// -------------------------------------------------------------------------
	// Language filter (both taxonomies)
	// -------------------------------------------------------------------------

	/**
	 * Swaps in LanguageAwareTermsListTable for the two admin term-list
	 * screens this filter targets. No-ops (returns the original class name
	 * unchanged) everywhere else, including every OTHER use of
	 * WP_Terms_List_Table (categories, link categories, any other custom
	 * taxonomy) — this never touches those.
	 *
	 * @param string               $class_name The list table class WP is about to instantiate.
	 * @param array<string, mixed> $args       _get_list_table()'s own arguments, including a resolved WP_Screen.
	 * @return string
	 */
	public function maybe_swap_list_table_class( string $class_name, array $args ): string {
		if ( 'WP_Terms_List_Table' !== $class_name ) {
			return $class_name;
		}

		$screen   = $args['screen'] ?? null;
		$taxonomy = ( $screen instanceof \WP_Screen ) ? (string) $screen->taxonomy : '';

		if ( ! in_array( $taxonomy, self::TARGET_TAXONOMIES, true ) || ! LinguaForge::is_active() ) {
			return $class_name;
		}

		return LanguageAwareTermsListTable::class;
	}

	/**
	 * Registers the actual term-scoping filter, but ONLY while genuinely on
	 * one of the two target admin screens (`load-edit-tags.php`) — never
	 * added globally. `get_terms_args` fires for every get_terms() call
	 * site-wide (front-end filter pills, REST, nav menus…); scoping the
	 * registration itself to this one request, rather than trying to make
	 * the filter callback figure out "am I on the right screen" from inside
	 * a generic hook, is what keeps this from ever touching an unrelated
	 * query — front-end filter pills, REST calls, nav menus, and any other
	 * get_terms() call anywhere else in the request lifecycle are completely
	 * unaffected, since this filter is simply never registered for them.
	 */
	public function maybe_register_scoping(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen detection, no state change.
		$taxonomy = isset( $_GET['taxonomy'] )
			? sanitize_key( wp_unslash( $_GET['taxonomy'] ) )
			: 'post_tag'; // edit-tags.php's own default when no ?taxonomy= is present.
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $taxonomy, self::TARGET_TAXONOMIES, true ) || ! LinguaForge::is_active() ) {
			return;
		}

		add_filter( 'get_terms_args', [ $this, 'scope_by_language' ], 10, 2 );

		// The language dropdown (LanguageAwareTermsListTable::extra_tablenav())
		// used to navigate via an inline onchange="" attribute on the <select>
		// itself; on Ulises's install that attribute never fired at all (no
		// reload, no URL change on selection — confirmed live, not a query bug).
		// Every OTHER inline handler in this codebase (onsubmit="return
		// confirm(...)" on several admin pages) fires fine, so this isn't a
		// blanket CSP block on inline handlers — something specific to this
		// one attribute/element wasn't reaching the browser's event dispatch.
		// A real enqueued script with addEventListener sidesteps whatever that
		// was rather than chasing it further, and is the more robust pattern
		// regardless of the exact cause.
		wp_add_inline_script( 'common', $this->admin_lang_filter_js() );
	}

	/**
	 * JS for the language dropdown: on 'change', rewrites the current URL's
	 * `agnosis_admin_lang` param (clearing `paged` so a language switch
	 * doesn't leave you on a now-invalid page number) and navigates there.
	 * Attached to 'common' — a handle already loaded on every wp-admin
	 * screen, including edit-tags.php — via wp_add_inline_script() rather
	 * than a dedicated file, matching this codebase's existing convention
	 * for small admin-only scripts (see Admin\Settings::enqueue_assets()).
	 *
	 * Also clears every `agnosis_sync_*`/`agnosis_sync_all_*` query arg
	 * (2026-07-19, live report): those params are the redirect this same
	 * class performs after a "Sync translations"/"Sync all translations"
	 * click, read back by `maybe_render_sync_notice()` to show the one-time
	 * courtesy notice. Left in the URL, they used to survive a language
	 * switch — the notice would keep re-rendering on every subsequent
	 * language view, showing that ONE original run's result regardless of
	 * which language was actually now being looked at. Reported live as
	 * three screenshots (German/Spanish/Portuguese) all showing the exact
	 * same "10 term(s) processed…" text despite genuinely different
	 * per-language term counts — the notice was stale, not lying about a
	 * fresh check for each language, and it made a real underlying bug
	 * (fixed the same day in LinguaForge::insert_translated_term()'s
	 * collision handling) look even more confusing than it already was.
	 */
	private function admin_lang_filter_js(): string {
		return <<<'JS'
		( function () {
			document.addEventListener( 'change', function ( event ) {
				var select = event.target;
				if ( ! select || 'agnosis-admin-lang' !== select.id ) {
					return;
				}
				var url = new URL( window.location.href );
				if ( select.value ) {
					url.searchParams.set( 'agnosis_admin_lang', select.value );
				} else {
					url.searchParams.delete( 'agnosis_admin_lang' );
				}
				url.searchParams.delete( 'paged' );
				[
					'agnosis_sync_created',
					'agnosis_sync_needs_translation',
					'agnosis_sync_skipped',
					'agnosis_sync_failed',
					'agnosis_sync_all_terms',
					'agnosis_sync_all_total',
					'agnosis_sync_all_created',
					'agnosis_sync_all_needs_translation',
					'agnosis_sync_all_skipped',
					'agnosis_sync_all_failed',
					'agnosis_sync_all_timed_out'
				].forEach( function ( param ) {
					url.searchParams.delete( param );
				} );
				window.location.href = url.toString();
			} );
		} )();
		JS;
	}

	/**
	 * Restricts the query to the primary/admin-curated vocabulary by
	 * default, or to one specific target language's translated terms when
	 * `?agnosis_admin_lang=` is present — or applies no language scoping at
	 * all when it's ALL_LANGUAGES_VALUE (audit §2d's orphaned-term escape
	 * hatch: a term flagged for a language no longer in Lingua Forge's
	 * configuration is otherwise unreachable from any other selection).
	 *
	 * @param array<string, mixed> $args       get_terms()'s own args.
	 * @param string[]             $taxonomies Taxonomies the query is scoped to.
	 * @return array<string, mixed>
	 */
	public function scope_by_language( array $args, array $taxonomies ): array {
		if ( empty( array_intersect( self::TARGET_TAXONOMIES, $taxonomies ) ) ) {
			return $args;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display filter, no state change.
		$selected = isset( $_GET['agnosis_admin_lang'] )
			? sanitize_key( wp_unslash( $_GET['agnosis_admin_lang'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( self::ALL_LANGUAGES_VALUE === $selected ) {
			return $args;
		}

		$meta_query = '' === $selected
			? [ [ 'key' => LinguaForge::TRANSLATED_TERM_META, 'compare' => 'NOT EXISTS' ] ]
			: [ [ 'key' => LinguaForge::TRANSLATED_TERM_META, 'value' => $selected ] ];

		$args['meta_query'] = isset( $args['meta_query'] ) && is_array( $args['meta_query'] )
			? array_merge( $args['meta_query'], $meta_query )
			: $meta_query;

		return $args;
	}

	// -------------------------------------------------------------------------
	// "Sync translations" row action (both taxonomies)
	// -------------------------------------------------------------------------

	/**
	 * Adds a "Sync translations" row action to a primary-language term on
	 * either target taxonomy — never shown on an already-translated term
	 * itself (nothing to sync FROM one of those), nor on a taxonomy this
	 * class doesn't target (a defensive check; in practice this callback is
	 * only ever hooked to `agnosis_medium_row_actions`/`post_tag_row_actions`
	 * in the first place, see Core\Plugin).
	 *
	 * Carries the current `paged` value into the action URL, when present,
	 * so handle_sync_term()'s redirect can return the operator to the same
	 * page instead of always bouncing to page 1 (audit §2d/§2e(iii)).
	 *
	 * @param string[] $actions Existing row actions (Edit, Quick Edit, Delete, View).
	 * @param \WP_Term $term    The term this row belongs to.
	 * @return string[]
	 */
	public function add_sync_row_action( array $actions, \WP_Term $term ): array {
		if ( ! in_array( $term->taxonomy, self::TARGET_TAXONOMIES, true ) || ! LinguaForge::is_active() ) {
			return $actions;
		}

		if ( get_term_meta( $term->term_id, LinguaForge::TRANSLATED_TERM_META, true ) ) {
			return $actions;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return $actions;
		}

		$action_args = [
			'action'   => 'agnosis_sync_term',
			'term_id'  => $term->term_id,
			'taxonomy' => $term->taxonomy,
		];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, mirrors the row's own current page into the row action's URL; not itself an input driving any action.
		$paged = absint( wp_unslash( $_GET['paged'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $paged > 1 ) {
			$action_args['paged'] = $paged;
		}

		$url = wp_nonce_url(
			add_query_arg( $action_args, admin_url( 'admin-post.php' ) ),
			'agnosis_sync_term_' . $term->term_id
		);

		$actions['agnosis-sync'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Sync translations', 'agnosis' )
		);

		return $actions;
	}

	/**
	 * Builds the "Sync all translations" button URL for the given taxonomy —
	 * shared by LanguageAwareTermsListTable::extra_tablenav() (the only
	 * caller), pulled out here rather than duplicated since both the button
	 * and its admin-post handler need to agree on the same nonce action.
	 * Static: WP core instantiates LanguageAwareTermsListTable itself (via
	 * `wp_list_table_class_name`) with a fixed constructor signature, so
	 * that class has no way to hold a TaxonomyLanguageFilter instance to
	 * call this on.
	 */
	public static function sync_all_url( string $taxonomy ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => 'agnosis_sync_all_terms',
					'taxonomy' => $taxonomy,
				],
				admin_url( 'admin-post.php' )
			),
			'agnosis_sync_all_terms_' . $taxonomy
		);
	}

	// -------------------------------------------------------------------------
	// admin-post handlers
	// -------------------------------------------------------------------------

	/**
	 * admin-post handler for the per-term "Sync translations" row action —
	 * creates any missing translated copy of this one term across every
	 * configured language, then redirects back with a plain-language
	 * summary. `agnosis_sync_needs_translation` (2026-07-19) counts
	 * languages that got a real term but only as an untranslated fallback
	 * placeholder (AI translation unavailable/failed — see
	 * `LinguaForge::insert_fallback_translated_term()`'s own docblock);
	 * `agnosis_sync_failed` (audit §2b/§2c) is now reserved for a genuine
	 * DB-level insert failure, which the fallback path makes rare.
	 *
	 * Carries `paged` through the redirect when the request that triggered
	 * this action had one (audit §2d/§2e(iii)): the row action only ever
	 * appears on the primary-language view, so `agnosis_admin_lang` has
	 * nothing to preserve here, but without `paged` an operator working
	 * through page 3 of a long primary vocabulary was silently bounced back
	 * to page 1 after every single sync click.
	 */
	public function handle_sync_term(): void {
		$term_id = absint( wp_unslash( $_GET['term_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_sync_term_' . $term_id );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$taxonomy = sanitize_key( wp_unslash( $_GET['taxonomy'] ?? '' ) );
		if ( ! in_array( $taxonomy, self::TARGET_TAXONOMIES, true ) ) {
			wp_die( esc_html__( 'Invalid taxonomy.', 'agnosis' ) );
		}

		$result = ( new LinguaForge() )->sync_term_across_languages( $term_id, $taxonomy );

		$redirect_args = [
			'taxonomy'                      => $taxonomy,
			'agnosis_sync_created'          => count( $result['created'] ),
			'agnosis_sync_needs_translation' => count( $result['needs_translation'] ),
			'agnosis_sync_skipped'          => count( $result['skipped'] ),
			'agnosis_sync_failed'           => count( $result['failed'] ),
		];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only pagination state carried through a redirect this method already nonce-checked above; not itself an input driving any action.
		$paged = absint( wp_unslash( $_GET['paged'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $paged > 1 ) {
			$redirect_args['paged'] = $paged;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'edit-tags.php' ) ) );
		exit;
	}

	/**
	 * admin-post handler for the "Sync all translations" button — runs the
	 * same per-term sync across every primary-language term on the given
	 * taxonomy in one pass (Compat\LinguaForge::sync_all_terms_across_languages()),
	 * then redirects back with an aggregate summary.
	 *
	 * The underlying sync is time-bounded (LinguaForge::SYNC_ALL_TIME_BUDGET_SECONDS,
	 * audit §2a) rather than guaranteed to finish every term in one request —
	 * `total`/`timed_out` are carried through the redirect alongside the
	 * existing counts so `maybe_render_sync_notice()` can tell the operator
	 * "X of Y done, click again" instead of a bare, possibly-incomplete
	 * "complete" message. `agnosis_sync_all_failed` (audit §2b/§2c) is
	 * carried through the same way — languages a translation could not be
	 * produced for at all, previously absorbed silently into neither
	 * `created` nor `skipped`. `agnosis_sync_all_needs_translation`
	 * (2026-07-19) is carried the same way again: languages that got a real,
	 * trid-linked term but only as an untranslated fallback placeholder
	 * because AI translation was unavailable or failed — never blank/missing
	 * anymore, but still needing a hand edit (see `LinguaForge::
	 * insert_fallback_translated_term()`'s own docblock for why this was
	 * added: `failed` alone used to mean the term simply didn't exist at
	 * all, and a live report found that leaving a real percentage of every
	 * language's vocabulary — German 9/10, Italian 8/10, Portuguese 6/10 on
	 * one run — permanently missing).
	 */
	public function handle_sync_all_terms(): void {
		$taxonomy = sanitize_key( wp_unslash( $_GET['taxonomy'] ?? '' ) );

		check_admin_referer( 'agnosis_sync_all_terms_' . $taxonomy );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		if ( ! in_array( $taxonomy, self::TARGET_TAXONOMIES, true ) ) {
			wp_die( esc_html__( 'Invalid taxonomy.', 'agnosis' ) );
		}

		$result = ( new LinguaForge() )->sync_all_terms_across_languages( $taxonomy );

		wp_safe_redirect(
			add_query_arg(
				[
					'taxonomy'                          => $taxonomy,
					'agnosis_sync_all_terms'            => $result['terms'],
					'agnosis_sync_all_total'            => $result['total'],
					'agnosis_sync_all_created'          => $result['created'],
					'agnosis_sync_all_needs_translation' => $result['needs_translation'],
					'agnosis_sync_all_skipped'          => $result['skipped'],
					'agnosis_sync_all_failed'           => $result['failed'],
					'agnosis_sync_all_timed_out'        => $result['timed_out'] ? '1' : '0',
				],
				admin_url( 'edit-tags.php' )
			)
		);
		exit;
	}

	/**
	 * Renders a plain-language summary notice after either sync action's
	 * redirect — per-term ("X created, Y already up to date") or sync-all,
	 * which itself now has two possible outcomes since the underlying sync
	 * is time-bounded (audit §2a): either every eligible term was reached
	 * ("N terms processed: X created, Y already up to date") or the request
	 * hit its time budget partway through, in which case the notice says so
	 * explicitly and tells the operator to click the button again — the sync
	 * is idempotent and resumable, so re-clicking picks up from where this
	 * request stopped rather than redoing any work. No-ops silently when
	 * none of the sync actions' query args are present; this is purely a
	 * courtesy notice for the actions above, not something any other flow
	 * needs to trigger.
	 *
	 * Both branches now also surface a `failed` count (audit §2c, closed
	 * alongside §2b): a translation that couldn't be produced at all — a
	 * genuine DB-level insert failure — used to be silently absorbed into
	 * neither `created` nor `skipped`, so an operator whose AI key had
	 * expired saw "0 created, 0 already up to date" with no way to tell that
	 * apart from "nothing to do." Any non-zero `failed` escalates the notice
	 * to a warning even when the run otherwise completed.
	 *
	 * A `needs_translation` count (2026-07-19) is now surfaced separately
	 * from `failed`: since `LinguaForge::insert_fallback_translated_term()`
	 * was added, "the AI couldn't translate this" no longer means "no term
	 * exists at all" — it means a real, trid-linked term was created using
	 * the untranslated source name as a placeholder, which the operator can
	 * find and hand-correct directly in this same list (its Description
	 * column carries a plain-language note). `failed` is now reserved for
	 * the genuinely rare case even the disambiguated fallback insert itself
	 * couldn't complete. The old "check the AI provider configuration"
	 * wording is dropped from the `needs_translation` case specifically —
	 * that phrasing was actively misleading here; a config problem isn't
	 * usually why one specific short label failed to translate.
	 */
	public function maybe_render_sync_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only courtesy notice reflecting the redirect this same class just performed after its own nonce-checked action, no state change here.
		if ( isset( $_GET['agnosis_sync_all_terms'], $_GET['agnosis_sync_all_created'], $_GET['agnosis_sync_all_skipped'] ) ) {
			$terms             = (int) $_GET['agnosis_sync_all_terms'];
			$total             = isset( $_GET['agnosis_sync_all_total'] ) ? (int) $_GET['agnosis_sync_all_total'] : $terms;
			$created           = (int) $_GET['agnosis_sync_all_created'];
			$needs_translation = isset( $_GET['agnosis_sync_all_needs_translation'] ) ? (int) $_GET['agnosis_sync_all_needs_translation'] : 0;
			$skipped           = (int) $_GET['agnosis_sync_all_skipped'];
			$failed            = isset( $_GET['agnosis_sync_all_failed'] ) ? (int) $_GET['agnosis_sync_all_failed'] : 0;
			$timed_out         = isset( $_GET['agnosis_sync_all_timed_out'] ) && '1' === $_GET['agnosis_sync_all_timed_out'];
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( $timed_out ) {
				$message = sprintf(
					/* translators: 1: number of primary-language terms processed so far, 2: total number of eligible terms, 3: number of newly-created translated terms, 4: number of terms created as untranslated placeholders needing a hand edit, 5: number of languages that already had one, 6: number of translations that could not be created at all */
					esc_html__( '%1$d of %2$d term(s) done (%3$d translation(s) created, %4$d created as placeholders needing translation, %5$d already up to date, %6$d failed) — this ran out of time before reaching every term. Click "Sync all translations" again to continue; it picks up where this stopped.', 'agnosis' ),
					$terms,
					$total,
					$created,
					$needs_translation,
					$skipped,
					$failed
				);

				wp_admin_notice( $message, [ 'type' => 'warning' ] );
				return;
			}

			if ( $failed > 0 ) {
				$message = sprintf(
					/* translators: 1: number of primary-language terms processed, 2: number of newly-created translated terms, 3: number of terms created as untranslated placeholders needing a hand edit, 4: number of languages that already had one, 5: number of translations that could not be created at all */
					esc_html__( 'Sync all complete: %1$d term(s) processed, %2$d translation(s) created, %3$d created as placeholders needing translation, %4$d already up to date, %5$d failed outright — check the AI provider configuration and run it again for those.', 'agnosis' ),
					$terms,
					$created,
					$needs_translation,
					$skipped,
					$failed
				);
			} elseif ( $needs_translation > 0 ) {
				$message = sprintf(
					/* translators: 1: number of primary-language terms processed, 2: number of newly-created translated terms, 3: number of terms created as untranslated placeholders needing a hand edit, 4: number of languages that already had one */
					esc_html__( 'Sync all complete: %1$d term(s) processed, %2$d translation(s) created, %3$d created as untranslated placeholders (AI translation wasn\'t available for these) — edit them in this list to add the correct name, %4$d already up to date.', 'agnosis' ),
					$terms,
					$created,
					$needs_translation,
					$skipped
				);
			} else {
				$message = sprintf(
					/* translators: 1: number of primary-language terms processed, 2: number of newly-created translated terms, 3: number of languages that already had one */
					esc_html__( 'Sync all complete: %1$d term(s) processed, %2$d translation(s) created, %3$d already up to date.', 'agnosis' ),
					$terms,
					$created,
					$skipped
				);
			}

			wp_admin_notice( $message, [ 'type' => ( $failed > 0 || $needs_translation > 0 ) ? 'warning' : 'success' ] );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only courtesy notice reflecting the redirect this same class just performed after its own nonce-checked action, no state change here.
		if ( ! isset( $_GET['agnosis_sync_created'], $_GET['agnosis_sync_skipped'] ) ) {
			return;
		}

		$created           = (int) $_GET['agnosis_sync_created'];
		$needs_translation = isset( $_GET['agnosis_sync_needs_translation'] ) ? (int) $_GET['agnosis_sync_needs_translation'] : 0;
		$skipped           = (int) $_GET['agnosis_sync_skipped'];
		$failed            = isset( $_GET['agnosis_sync_failed'] ) ? (int) $_GET['agnosis_sync_failed'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $failed > 0 ) {
			$message = sprintf(
				/* translators: 1: number of newly-created translated terms, 2: number of terms created as untranslated placeholders needing a hand edit, 3: number of languages that already had one, 4: number of languages the translation could not be created for at all */
				esc_html__( 'Sync complete: %1$d translation(s) created, %2$d created as placeholders needing translation, %3$d language(s) already up to date, %4$d failed outright — check the AI provider configuration and try again.', 'agnosis' ),
				$created,
				$needs_translation,
				$skipped,
				$failed
			);
		} elseif ( $needs_translation > 0 ) {
			$message = sprintf(
				/* translators: 1: number of newly-created translated terms, 2: number of terms created as untranslated placeholders needing a hand edit, 3: number of languages that already had one */
				esc_html__( 'Sync complete: %1$d translation(s) created, %2$d created as untranslated placeholders (AI translation wasn\'t available for these) — edit them in this list to add the correct name, %3$d language(s) already up to date.', 'agnosis' ),
				$created,
				$needs_translation,
				$skipped
			);
		} else {
			$message = sprintf(
				/* translators: 1: number of newly-created translated terms, 2: number of languages that already had one */
				esc_html__( 'Sync complete: %1$d translation(s) created, %2$d language(s) already up to date.', 'agnosis' ),
				$created,
				$skipped
			);
		}

		wp_admin_notice( $message, [ 'type' => ( $failed > 0 || $needs_translation > 0 ) ? 'warning' : 'success' ] );
	}
}
