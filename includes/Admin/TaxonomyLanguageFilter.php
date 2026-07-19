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
				window.location.href = url.toString();
			} );
		} )();
		JS;
	}

	/**
	 * Restricts the query to the primary/admin-curated vocabulary by
	 * default, or to one specific target language's translated terms when
	 * `?agnosis_admin_lang=` is present.
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

		$url = wp_nonce_url(
			add_query_arg(
				[
					'action'   => 'agnosis_sync_term',
					'term_id'  => $term->term_id,
					'taxonomy' => $term->taxonomy,
				],
				admin_url( 'admin-post.php' )
			),
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
	 * summary.
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

		wp_safe_redirect(
			add_query_arg(
				[
					'taxonomy'             => $taxonomy,
					'agnosis_sync_created' => count( $result['created'] ),
					'agnosis_sync_skipped' => count( $result['skipped'] ),
				],
				admin_url( 'edit-tags.php' )
			)
		);
		exit;
	}

	/**
	 * admin-post handler for the "Sync all translations" button — runs the
	 * same per-term sync across every primary-language term on the given
	 * taxonomy in one pass (Compat\LinguaForge::sync_all_terms_across_languages()),
	 * then redirects back with an aggregate summary.
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
					'taxonomy'                 => $taxonomy,
					'agnosis_sync_all_terms'   => $result['terms'],
					'agnosis_sync_all_created' => $result['created'],
					'agnosis_sync_all_skipped' => $result['skipped'],
				],
				admin_url( 'edit-tags.php' )
			)
		);
		exit;
	}

	/**
	 * Renders a plain-language summary notice after either sync action's
	 * redirect — per-term ("X created, Y already up to date") or sync-all
	 * ("N terms processed: X created, Y already up to date"). No-ops
	 * silently when neither action's query args are present; this is purely
	 * a courtesy notice for the two actions above, not something any other
	 * flow needs to trigger.
	 */
	public function maybe_render_sync_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only courtesy notice reflecting the redirect this same class just performed after its own nonce-checked action, no state change here.
		if ( isset( $_GET['agnosis_sync_all_terms'], $_GET['agnosis_sync_all_created'], $_GET['agnosis_sync_all_skipped'] ) ) {
			$terms   = (int) $_GET['agnosis_sync_all_terms'];
			$created = (int) $_GET['agnosis_sync_all_created'];
			$skipped = (int) $_GET['agnosis_sync_all_skipped'];
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$message = sprintf(
				/* translators: 1: number of primary-language terms processed, 2: number of newly-created translated terms, 3: number of languages that already had one */
				esc_html__( 'Sync all complete: %1$d term(s) processed, %2$d translation(s) created, %3$d already up to date.', 'agnosis' ),
				$terms,
				$created,
				$skipped
			);

			wp_admin_notice( $message, [ 'type' => 'success' ] );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only courtesy notice reflecting the redirect this same class just performed after its own nonce-checked action, no state change here.
		if ( ! isset( $_GET['agnosis_sync_created'], $_GET['agnosis_sync_skipped'] ) ) {
			return;
		}

		$created = (int) $_GET['agnosis_sync_created'];
		$skipped = (int) $_GET['agnosis_sync_skipped'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: 1: number of newly-created translated terms, 2: number of languages that already had one */
			esc_html__( 'Sync complete: %1$d translation(s) created, %2$d language(s) already up to date.', 'agnosis' ),
			$created,
			$skipped
		);

		wp_admin_notice( $message, [ 'type' => 'success' ] );
	}
}
