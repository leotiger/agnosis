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
 * filter_checklist_by_language() (2026-07-20) closes a gap in all of that:
 * `agnosis_medium` has no meta_box_cb override, so both Quick Edit and the
 * classic edit-screen checklist meta box render every term in the flat
 * taxonomy via core's default wp_terms_checklist() — every language's
 * translated copy of every medium mixed into one list (reported live: a
 * Quick Edit panel showing 7 different-language "Watercolor" checkboxes at
 * once, none of them the English one, with no indication any of it was
 * translated). Scoping this is what TaxonomyLanguageFilter already does for
 * the Tags/Mediums admin term-LIST screens (edit-tags.php) — but that
 * class's own `get_terms_args` filter is only ever registered on
 * `load-edit-tags.php`, so it never touches the checklist an artwork's own
 * edit/Quick Edit screen renders. This isn't just cosmetic: with every
 * language mixed in, nothing stopped an editor from checking a WRONG-
 * language term directly onto a PRIMARY-language artwork, which
 * sync_medium_assignment_to_siblings()/sync_taxonomy() would then treat as
 * if it were genuine primary vocabulary — translating an already-translated
 * term instead of the real one, corrupting exactly the propagation this
 * class exists to keep correct. Scoping the checklist to the post's own
 * language closes that off at the source rather than trying to detect and
 * repair the corruption after the fact.
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
	 * Scopes the `agnosis_medium` checklist to the post's own language —
	 * core's `wp_terms_checklist()` (which both Quick Edit and the classic
	 * edit-screen checklist meta box render through, since this taxonomy has
	 * no `meta_box_cb` override) otherwise pulls in every language's
	 * translated copy of every term via a plain, unfiltered `get_terms()`.
	 * Same meta_query shape as `TaxonomyLanguageFilter::scope_by_language()`
	 * (that class's own docblock explains the underlying data model: every
	 * translated term is a sibling row in this SAME flat taxonomy, flagged
	 * with `LinguaForge::TRANSLATED_TERM_META` = the language it was
	 * translated into) — but that filter is only ever registered on
	 * `load-edit-tags.php`, the Tags/Mediums term-LIST screens, so it never
	 * reaches a checklist rendered on an artwork's own edit/Quick Edit
	 * screen. This is that missing piece, registered unconditionally (cheap
	 * no-op check on `$args['taxonomy']`) rather than scoped to one screen,
	 * so both Quick Edit and the classic meta box get it for free.
	 *
	 * Language priority mirrors Compat\LinguaForge::on_medium_terms_changed()'s
	 * own `_agnosis_native_lang`-then-`_lf_lang` fallback, for the same
	 * reason documented there: `_agnosis_native_lang` is written at intake
	 * (before a post is ever published), `_lf_lang` only once
	 * `agnosis_post_published` fires — reading only the latter would show the
	 * primary-language bucket to a native-language draft that hasn't
	 * published yet. Anything that resolves to "unknown" (new/unsaved post,
	 * meta not yet written, Lingua Forge's primary language unconfigured)
	 * falls back to the primary-language bucket — the safe default, since
	 * that's where a checked box actually needs to land for
	 * sync_medium_assignment_to_siblings() to treat it as genuine primary
	 * vocabulary rather than accidentally letting a translated-language term
	 * get checked directly onto what will become a primary-language artwork.
	 *
	 * @param array<string, mixed> $args    wp_terms_checklist()'s own args (includes 'taxonomy' as a single string, not an array).
	 * @param int                  $post_id Post the checklist is being rendered for — 0 on "Add New".
	 * @return array<string, mixed>
	 */
	public function filter_checklist_by_language( array $args, int $post_id ): array {
		if ( 'agnosis_medium' !== ( $args['taxonomy'] ?? '' ) || ! LinguaForge::is_active() ) {
			return $args;
		}

		$primary_lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		$native_lang  = sanitize_key( (string) get_post_meta( $post_id, '_agnosis_native_lang', true ) );
		$post_lang    = '' !== $native_lang ? $native_lang : sanitize_key( (string) get_post_meta( $post_id, '_lf_lang', true ) );

		$meta_query = ( '' === $post_lang || '' === $primary_lang || $post_lang === $primary_lang )
			? [ [ 'key' => LinguaForge::TRANSLATED_TERM_META, 'compare' => 'NOT EXISTS' ] ]
			: [ [ 'key' => LinguaForge::TRANSLATED_TERM_META, 'value' => $post_lang ] ];

		$args['meta_query'] = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- checklist rendering on a single-post admin screen, not a hot path.
			? array_merge( $args['meta_query'], $meta_query )
			: $meta_query;

		return $args;
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
