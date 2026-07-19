<?php
/**
 * On-demand "sync medium assignment to translated siblings" — both a
 * per-artwork meta box (agnosis_artwork edit screen) and a bulk sweep
 * (agnosis_artwork list screen).
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
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

use Agnosis\Compat\LinguaForge;

class ArtworkMediumSync {

	private const META_BOX_ID = 'agnosis_medium_sync';

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
