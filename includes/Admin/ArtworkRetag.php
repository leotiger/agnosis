<?php
/**
 * Re-tag button — TAG-REDESIGN.md T3(e): a meta box on the primary-language
 * artwork edit screen that re-runs the entire tag pipeline for that one
 * post (`Publishing\Retag::run()`, built service-layer-only in T2, now
 * complete since T3(c) generalized sibling propagation to tags). Mirrors
 * Admin\ArtworkMediumSync's own per-artwork button pattern exactly —
 * admin-post handler, nonce, capability, confirm dialog, redirect notice —
 * per that document's own instruction ("mirroring the pattern"): a separate
 * class rather than folding into ArtworkMediumSync, since Re-tag is a
 * structurally different AI-driven re-derivation (one classification call
 * from the post's own content), not a push-an-existing-assignment sync;
 * uses `manage_categories` rather than `edit_post` (TAG-REDESIGN.md §2
 * names this capability explicitly — Re-tag can raise new vocabulary
 * proposals, a curatorial action, not just an editorial one); and its
 * redirect notice reports matched/proposed/gated counts rather than a
 * sibling-sync count.
 *
 * Invariant 8 (TAG-REDESIGN.md §5): Re-tag is not a special path — this
 * class is nothing but a thin admin-UI wrapper around
 * `Publishing\Retag::run()`, the exact same method a real approval's
 * association pipeline and T4's optional legacy backfill script both call.
 * Every behavior visible here (the gate, the AI call, matching, proposal
 * creation, ID-based assignment) lives in that service or in
 * `Publishing\TagGate::associate()` it delegates to — never reimplemented.
 *
 * Gating mirrors the eligibility `Retag::run()` itself enforces
 * structurally (published, primary-language), so the button simply doesn't
 * render rather than ever needing to explain a `not_published`/
 * `not_primary_language` failure after the fact — the two failure reasons
 * still surfaced by `maybe_render_notice()` (`ai_call_failed`,
 * `no_candidates_returned`) are exactly the two `Retag::run()` can't
 * pre-empt from the edit screen (they depend on the AI call itself).
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Publishing\Retag;

class ArtworkRetag {

	private const META_BOX_ID = 'agnosis_artwork_retag';

	public function register_meta_box(): void {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Re-tag', 'agnosis' ),
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
		if ( 'publish' !== $post->post_status ) {
			esc_html_e( 'Available once this artwork is published.', 'agnosis' );
			return;
		}

		// Same primary-language check Retag::run() itself enforces
		// structurally (native_lang empty, or already equal to the resolved
		// primary code) — rendering the button only when it would actually
		// succeed, rather than after a redirect explains why it couldn't.
		$native_lang = (string) get_post_meta( $post->ID, '_agnosis_native_lang', true );
		if ( '' !== trim( $native_lang ) && $native_lang !== SubmissionTranslator::resolve_target_language() ) {
			esc_html_e( "This artwork's tags are derived from its primary-language original — nothing to re-tag from here.", 'agnosis' );
			return;
		}

		$url = wp_nonce_url(
			add_query_arg(
				[
					'action'  => 'agnosis_retag',
					'post_id' => $post->ID,
				],
				admin_url( 'admin-post.php' )
			),
			'agnosis_retag_' . $post->ID
		);
		?>
		<p><?php esc_html_e( 'Re-run tag detection for this artwork from its current title, excerpt, and content.', 'agnosis' ); ?></p>
		<p>
			<a
				href="<?php echo esc_url( $url ); ?>"
				class="button"
				onclick="return confirm( '<?php echo esc_js( __( 'Re-run tag detection for this artwork? Any pending tag proposals for it will be replaced, and matched tags are re-assigned immediately.', 'agnosis' ) ); ?>' );"
			><?php esc_html_e( 'Re-tag this artwork', 'agnosis' ); ?></a>
		</p>
		<?php
	}

	public function handle_retag(): void {
		$post_id = absint( wp_unslash( $_GET['post_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_retag_' . $post_id );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$result = ( new Retag() )->run( $post_id );

		$args = $result['success']
			? [
				'agnosis_retag_success'  => 1,
				'agnosis_retag_matched'  => $result['matched'],
				'agnosis_retag_proposed' => $result['proposed'],
				'agnosis_retag_gated'    => $result['gated'],
			]
			: [
				'agnosis_retag_success' => 0,
				'agnosis_retag_reason'  => $result['reason'],
			];

		$edit_link = get_edit_post_link( $post_id, 'raw' );
		wp_safe_redirect( add_query_arg( $args, $edit_link ? $edit_link : admin_url() ) );
		exit;
	}

	/**
	 * Renders the outcome notice after handle_retag()'s redirect back to
	 * the artwork edit screen it started from — matched/proposed/gated
	 * counts on success (TAG-REDESIGN.md §2's own phrasing: "reports
	 * matched/proposed/gated counts truthfully"), or a human-readable
	 * translation of Retag::run()'s machine-readable `reason` on failure.
	 */
	public function maybe_render_notice(): void {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only courtesy notice reflecting the redirect this same class just performed after its own nonce-checked action, no state change here.
		if ( 'post.php' !== $pagenow || ! isset( $_GET['agnosis_retag_success'] ) ) {
			return;
		}

		$success = '1' === (string) wp_unslash( $_GET['agnosis_retag_success'] );

		if ( $success ) {
			$matched  = isset( $_GET['agnosis_retag_matched'] ) ? (int) wp_unslash( $_GET['agnosis_retag_matched'] ) : 0;
			$proposed = isset( $_GET['agnosis_retag_proposed'] ) ? (int) wp_unslash( $_GET['agnosis_retag_proposed'] ) : 0;
			$gated    = isset( $_GET['agnosis_retag_gated'] ) ? (int) wp_unslash( $_GET['agnosis_retag_gated'] ) : 0;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$message = sprintf(
				/* translators: 1: existing tags matched and assigned, 2: new tag proposals raised, 3: candidates gated out or trimmed */
				esc_html__( 'Re-tag complete: %1$d tag(s) matched and assigned, %2$d new proposal(s) raised, %3$d candidate(s) gated out.', 'agnosis' ),
				$matched,
				$proposed,
				$gated
			);

			wp_admin_notice( $message, [ 'type' => 'success' ] );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only courtesy notice, see above.
		$reason = sanitize_key( wp_unslash( $_GET['agnosis_retag_reason'] ?? '' ) );

		wp_admin_notice( $this->reason_message( $reason ), [ 'type' => 'error' ] );
	}

	/** Human-readable translation of Retag::run()'s machine-readable `reason` codes. */
	private function reason_message( string $reason ): string {
		switch ( $reason ) {
			case 'not_found':
				return esc_html__( 'Re-tag failed: this post no longer exists.', 'agnosis' );
			case 'unsupported_post_type':
				return esc_html__( 'Re-tag failed: this post type does not support tags.', 'agnosis' );
			case 'not_published':
				return esc_html__( 'Re-tag failed: this artwork is not published yet.', 'agnosis' );
			case 'not_primary_language':
				return esc_html__( "Re-tag failed: this artwork's tags are derived from its primary-language original.", 'agnosis' );
			case 'ai_call_failed':
				return esc_html__( 'Re-tag failed: the AI tag detection call did not return a usable response. Try again in a moment.', 'agnosis' );
			case 'no_candidates_returned':
				return esc_html__( 'Re-tag failed: the AI call succeeded but proposed no tags for this content.', 'agnosis' );
			default:
				return esc_html__( 'Re-tag failed for an unknown reason.', 'agnosis' );
		}
	}
}
