<?php
/**
 * On-demand "sync medium assignment to translated siblings" — both a
 * per-artwork meta box (agnosis_artwork edit screen) and a bulk sweep
 * (agnosis_artwork list screen) — plus scoping the Mediums checklist itself
 * (Quick Edit and the classic edit-screen checklist meta box, both of which
 * render via core's wp_terms_checklist()) to one language at a time.
 *
 * Compat\LinguaForge::on_medium_terms_changed() already re-propagates a
 * primary-language artwork's medium onto its already-translated siblings
 * AUTOMATICALLY, but only reactively — it fires on a save that actually
 * changes the medium. It does nothing for an artwork/sibling pair that was
 * ALREADY out of sync before that feature existed, or drifted for any
 * other reason. Requested directly as "a real sync button" after the
 * automatic-only version shipped and turned out not to cover that case —
 * the immediate one being the Catalan-testing data-integrity incident this
 * whole area exists to help clean up (a batch of artwork/sibling pairs
 * whose medium was never correctly propagated in the first place).
 *
 * Both actions call the SAME underlying methods
 * (Compat\LinguaForge::sync_medium_assignment_to_siblings() /
 * sync_all_medium_assignments()) that on_medium_terms_changed() itself now
 * delegates to — one shared implementation, three ways to trigger it
 * (automatic on edit, one artwork on demand, all artwork on demand).
 *
 * Language scoping for the Mediums checklist (register_edit_screen_scoping() /
 * register_list_screen_scoping(), 2026-07-20) closes a gap in all of that:
 * `agnosis_medium` has no meta_box_cb override, so both Quick Edit and the
 * classic edit-screen checklist meta box render every term in the flat
 * taxonomy via core's default wp_terms_checklist() — every language's
 * translated copy of every medium mixed into one list (reported live: a
 * Quick Edit panel showing 7 different-language "Watercolor" checkboxes at
 * once, none of them the English one, with no indication any of it was
 * translated). This isn't just cosmetic: with every language mixed in,
 * nothing stopped an editor from checking a WRONG-language term directly
 * onto a PRIMARY-language artwork, which
 * sync_medium_assignment_to_siblings()/sync_taxonomy() would then treat as
 * if it were genuine primary vocabulary — translating an already-translated
 * term instead of the real one, corrupting exactly the propagation this
 * class exists to keep correct.
 *
 * A first version of this fix hooked `wp_terms_checklist_args` directly
 * (like `TaxonomyLanguageFilter::scope_by_language()` hooks `get_terms_args`
 * for the Tags/Mediums term-LIST screens) — shipped in 0.9.41, and turned
 * out to do nothing at all, for two independent reasons found by reading
 * WordPress core's own source directly rather than assuming:
 *   1. `wp_terms_checklist()` (wp-admin/includes/template.php) applies the
 *      `wp_terms_checklist_args` filter, but then only reads the *walker*,
 *      *selected_cats*, *popular_cats*, and *checked_ontop* keys off the
 *      filtered result — the actual term list comes from a SEPARATE,
 *      hardcoded `get_terms( [ 'taxonomy' => $taxonomy, 'get' => 'all' ] )`
 *      call a few lines later that never sees anything this filter added
 *      (a `meta_query` included). The right hook is the lower-level
 *      `get_terms_args` (which that inner `get_terms()` call itself fires) —
 *      exactly what `TaxonomyLanguageFilter` already uses, just not scoped
 *      to reach this screen.
 *   2. Quick Edit specifically can't be scoped by "the post's own language"
 *      at all, regardless of which filter is used: `WP_Posts_List_Table::
 *      inline_edit()` (wp-admin/includes/class-wp-posts-list-table.php)
 *      renders the ENTIRE Quick Edit row — including this checklist — ONCE
 *      per page load, as a single hidden template shared by every row via
 *      JS, calling `wp_terms_checklist( 0, ... )` with a hardcoded post ID
 *      of 0. There is no per-row server round-trip, so there is no specific
 *      post to derive a language from at render time — a `$post_id`-based
 *      filter can only ever apply the SAME language to every row, forever.
 *      Quick Edit is instead scoped to the Artworks list's own active
 *      language filter (Lingua Forge's `lf_lang_filter` — the "EN" dropdown
 *      next to "All dates" in the toolbar, `restrict_manage_posts`): while
 *      that's set to one specific language, every visible row genuinely IS
 *      that language, so scoping the shared template to it is correct. Left
 *      unscoped when "All languages" is selected — showing every language
 *      mixed is the honest answer to a deliberately mixed view, rather than
 *      silently guessing one.
 * The classic single-artwork edit screen (post.php/post-new.php) doesn't
 * have problem 2 — `post_categories_meta_box()` passes the real `$post->ID`
 * — so it's scoped the original way, by that specific post's own language.
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

use Agnosis\Compat\LinguaForge;

class ArtworkMediumSync {

	private const META_BOX_ID = 'agnosis_medium_sync';

	// -------------------------------------------------------------------------
	// Mediums checklist language scoping (Quick Edit + edit-screen meta box)
	// -------------------------------------------------------------------------

	/**
	 * Registers checklist language scoping for the classic single-artwork
	 * edit screen (post.php/post-new.php) — hooked to `load-post.php` and
	 * `load-post-new.php` (screen-load actions, fire once, early, well
	 * before `post_categories_meta_box()` renders the checklist), the same
	 * "register only on the screen that needs it" pattern
	 * `TaxonomyLanguageFilter::maybe_register_scoping()` uses for
	 * `load-edit-tags.php`.
	 *
	 * `$_GET['post']` gives a real post ID on post.php; post-new.php has
	 * none yet, so resolve_post_language(0) falls back to the primary-
	 * language bucket — the safe default, since that's where a checked box
	 * actually needs to land for sync_medium_assignment_to_siblings() to
	 * treat it as genuine primary vocabulary rather than accidentally
	 * letting a translated-language term get checked directly onto what
	 * will become a primary-language artwork.
	 */
	public function register_edit_screen_scoping(): void {
		if ( ! LinguaForge::is_active() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection, no state change.
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		$this->register_scoping_for_language( $this->resolve_post_language( $post_id ) );
	}

	/**
	 * Registers checklist language scoping for the Artworks LIST screen
	 * (edit.php) — covers Quick Edit's checklist, which core renders ONCE
	 * per page load as a hidden template shared by every row
	 * (`WP_Posts_List_Table::inline_edit()` calls `wp_terms_checklist( 0,
	 * ... )` with a hardcoded post ID — see class docblock for why this
	 * means there is no real post to derive a language from here). Scoped
	 * instead to the list's own active language filter — Lingua Forge's
	 * `lf_lang_filter` (the "EN" dropdown next to "All dates",
	 * `restrict_manage_posts`), read with the exact same precedence Lingua
	 * Forge's own `Admin\Filters` class uses (URL param first, falling back
	 * to the user's last-selected value in user meta, since the dropdown's
	 * own selection persists that way across requests). Left unscoped
	 * (returns without registering) when "All languages" is selected — a
	 * deliberately mixed list doesn't have one language to guess.
	 *
	 * Hooked to `load-edit.php`, which fires for every post-type list
	 * screen; the `agnosis_artwork` check keeps this from doing anything on
	 * an unrelated post type's list (defense in depth — the taxonomy check
	 * inside register_scoping_for_language()'s own filter callback would
	 * already no-op there regardless, since no other post type uses
	 * `agnosis_medium`).
	 */
	public function register_list_screen_scoping(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen detection, no state change.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		if ( 'agnosis_artwork' !== $post_type || ! LinguaForge::is_active() ) {
			return;
		}

		$lang = isset( $_GET['lf_lang_filter'] ) && '' !== $_GET['lf_lang_filter']
			? sanitize_key( wp_unslash( $_GET['lf_lang_filter'] ) )
			: sanitize_key( (string) get_user_meta( get_current_user_id(), 'lf_lang_filter', true ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $lang ) {
			return;
		}

		$this->register_scoping_for_language( $lang );
	}

	/**
	 * Resolves a specific post's own language — same
	 * `_agnosis_native_lang`-then-`_lf_lang` priority
	 * Compat\LinguaForge::on_medium_terms_changed() already uses, for the
	 * same reason documented there: `_agnosis_native_lang` is written at
	 * intake (before a post is ever published), `_lf_lang` only once
	 * `agnosis_post_published` fires — reading only the latter would show
	 * the primary-language bucket to a native-language draft that hasn't
	 * published yet. `$post_id` of 0 (no post yet) resolves straight to the
	 * primary-language bucket.
	 */
	private function resolve_post_language( int $post_id ): string {
		if ( 0 === $post_id ) {
			return sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		}

		$native_lang = sanitize_key( (string) get_post_meta( $post_id, '_agnosis_native_lang', true ) );
		if ( '' !== $native_lang ) {
			return $native_lang;
		}

		$lf_lang = sanitize_key( (string) get_post_meta( $post_id, '_lf_lang', true ) );
		return '' !== $lf_lang ? $lf_lang : sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
	}

	/**
	 * Registers the `get_terms_args` filter that actually reaches
	 * `wp_terms_checklist()`'s inner `get_terms()` call (unlike
	 * `wp_terms_checklist_args`, see class docblock) — same meta_query shape
	 * `TaxonomyLanguageFilter::scope_by_language()` uses, scoped to one
	 * resolved language for the remainder of this request. Both
	 * register_edit_screen_scoping() and register_list_screen_scoping() feed
	 * this the same way, just with a different resolved `$lang`.
	 *
	 * @param string $lang Language code the checklist should be scoped to.
	 */
	private function register_scoping_for_language( string $lang ): void {
		$primary_lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );

		add_filter(
			'get_terms_args',
			static function ( array $args, array $taxonomies ) use ( $lang, $primary_lang ): array {
				if ( ! in_array( 'agnosis_medium', $taxonomies, true ) ) {
					return $args;
				}

				$meta_query = ( '' === $lang || '' === $primary_lang || $lang === $primary_lang )
					? [ [ 'key' => LinguaForge::TRANSLATED_TERM_META, 'compare' => 'NOT EXISTS' ] ]
					: [ [ 'key' => LinguaForge::TRANSLATED_TERM_META, 'value' => $lang ] ];

				$args['meta_query'] = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- checklist rendering on a single admin screen load, not a hot path.
					? array_merge( $args['meta_query'], $meta_query )
					: $meta_query;

				return $args;
			},
			10,
			2
		);
	}

	// -------------------------------------------------------------------------
	// Per-artwork (edit screen meta box)
	// -------------------------------------------------------------------------

	public function register_meta_box(): void {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Medium translations', 'agnosis' ),
			[ $this, 'render_meta_box' ],
			'agnosis_artwork',
			'side',
			'default'
		);
	}

	/**
	 * @param \WP_Post $post Current artwork post.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		if ( ! LinguaForge::is_active() ) {
			esc_html_e( 'Requires Lingua Forge.', 'agnosis' );
			return;
		}

		$primary_lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		$post_lang    = sanitize_key( (string) get_post_meta( $post->ID, '_lf_lang', true ) );

		if ( '' === $primary_lang || $post_lang !== $primary_lang ) {
			esc_html_e( "This artwork's medium is translated from its primary-language original — nothing to sync from here.", 'agnosis' );
			return;
		}

		$url = wp_nonce_url(
			add_query_arg(
				[
					'action'  => 'agnosis_sync_medium_assignment',
					'post_id' => $post->ID,
				],
				admin_url( 'admin-post.php' )
			),
			'agnosis_sync_medium_assignment_' . $post->ID
		);
		?>
		<p><?php esc_html_e( "Push this artwork's current medium onto every already-translated sibling post.", 'agnosis' ); ?></p>
		<p>
			<a href="<?php echo esc_url( $url ); ?>" class="button"><?php esc_html_e( 'Sync medium to translations', 'agnosis' ); ?></a>
		</p>
		<?php
	}

	public function handle_sync(): void {
		$post_id = absint( wp_unslash( $_GET['post_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_sync_medium_assignment_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$synced = ( new LinguaForge() )->sync_medium_assignment_to_siblings( $post_id );

		$edit_link = get_edit_post_link( $post_id, 'raw' );
		wp_safe_redirect(
			add_query_arg( [ 'agnosis_medium_synced' => $synced ], $edit_link ? $edit_link : admin_url() )
		);
		exit;
	}

	/**
	 * Renders the "pushed to N sibling(s)" notice after handle_sync()'s
	 * redirect back to the artwork edit screen it started from.
	 */
	public function maybe_render_single_notice(): void {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only courtesy notice reflecting the redirect this same class just performed after its own nonce-checked action, no state change here.
		if ( 'post.php' !== $pagenow || ! isset( $_GET['agnosis_medium_synced'] ) ) {
			return;
		}

		$synced = (int) $_GET['agnosis_medium_synced'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: %d: number of translated sibling posts the medium was pushed to */
			esc_html__( 'Medium pushed to %d translated sibling post(s).', 'agnosis' ),
			$synced
		);

		wp_admin_notice( $message, [ 'type' => $synced > 0 ? 'success' : 'info' ] );
	}

	// -------------------------------------------------------------------------
	// Bulk (artwork list screen)
	// -------------------------------------------------------------------------

	/**
	 * Renders the "Sync all medium assignments" button in the artwork list
	 * screen's filter area — reuses `restrict_manage_posts`, the same core
	 * hook Lingua Forge's own admin language-filter dropdown uses for post
	 * lists (unlike WP_Terms_List_Table, WP_Posts_List_Table fires this
	 * natively, no subclassing needed here the way the Tags/Mediums
	 * language filter required).
	 *
	 * @param string $post_type Current list screen's post type.
	 */
	public function render_bulk_sync_button( string $post_type ): void {
		if ( 'agnosis_artwork' !== $post_type || ! LinguaForge::is_active() || ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg( [ 'action' => 'agnosis_sync_all_medium_assignments' ], admin_url( 'admin-post.php' ) ),
			'agnosis_sync_all_medium_assignments'
		);
		?>
		<a
			href="<?php echo esc_url( $url ); ?>"
			class="button"
			onclick="return confirm( '<?php echo esc_js( __( 'Push every primary-language artwork\'s current medium onto its translated siblings? This can take a moment on a large catalog.', 'agnosis' ) ); ?>' );"
		><?php esc_html_e( 'Sync all medium assignments', 'agnosis' ); ?></a>
		<?php
	}

	public function handle_sync_all(): void {
		check_admin_referer( 'agnosis_sync_all_medium_assignments' );

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$result = ( new LinguaForge() )->sync_all_medium_assignments();

		wp_safe_redirect(
			add_query_arg(
				[
					'post_type'                        => 'agnosis_artwork',
					'agnosis_medium_sync_all_artworks' => $result['artworks'],
					'agnosis_medium_sync_all_synced'   => $result['synced'],
				],
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the "N artworks processed, M siblings synced" notice after
	 * handle_sync_all()'s redirect back to the artwork list screen.
	 */
	public function maybe_render_bulk_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only courtesy notice reflecting the redirect this same class just performed after its own nonce-checked action, no state change here.
		if ( ! isset( $_GET['agnosis_medium_sync_all_artworks'], $_GET['agnosis_medium_sync_all_synced'] ) ) {
			return;
		}

		$artworks = (int) $_GET['agnosis_medium_sync_all_artworks'];
		$synced   = (int) $_GET['agnosis_medium_sync_all_synced'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: 1: number of primary-language artworks processed, 2: number of translated sibling posts a medium was pushed to */
			esc_html__( 'Sync complete: %1$d primary-language artwork(s) processed, medium pushed to %2$d sibling post(s).', 'agnosis' ),
			$artworks,
			$synced
		);

		wp_admin_notice( $message, [ 'type' => 'success' ] );
	}
}
