<?php
/**
 * Admin review queue for AI-proposed medium categories that don't match the
 * live `agnosis_medium` vocabulary (2026-07-21).
 *
 * Background: every AI classification path that assigns a medium
 * (describe()'s vision call, the audio/video branches, and the pure@ lane's
 * classify_medium_from_image()/classify_medium_from_text()) ultimately funnels
 * through PostCreator::write_post_meta() (and, for the native-language
 * pipeline, ReviewEndpoints::finalize_publish()'s two re-check sites at
 * approval time) — all three call sites used to silently drop a non-matching
 * medium value entirely: the AI's answer simply vanished, with no record it
 * ever happened, let alone what it was. An admin had no way to notice a
 * pattern like "the AI keeps proposing 'Short Story'" and decide whether that
 * should become a real Artwork → Mediums term.
 *
 * All three call sites now record a non-matching value as `_agnosis_medium_proposal`
 * post meta instead of discarding it. This class surfaces those proposals,
 * grouped by distinct value with a post count, directly on the existing
 * Artwork → Mediums admin screen (edit-tags.php?taxonomy=agnosis_medium) —
 * same integration point TaxonomyLanguageFilter already uses for that screen,
 * rather than a whole new top-level admin page for what's fundamentally the
 * same taxonomy's own review queue.
 *
 *   - Approve: creates the term if it doesn't already exist (or reuses it if
 *     it does — an admin approving the same proposed name twice, from two
 *     separate batches, must not error or create a duplicate), assigns it to
 *     every post currently carrying that exact proposal value, and clears the
 *     meta on each.
 *   - Reject: clears the meta on every post carrying that value, without
 *     creating or assigning any term. The post keeps whatever medium (if any)
 *     it already had — rejecting a proposal is not the same as removing an
 *     existing assignment.
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

class MediumProposals {

	private const META_KEY = '_agnosis_medium_proposal';

	// -------------------------------------------------------------------------
	// Display — Artwork → Mediums admin screen
	// -------------------------------------------------------------------------

	/**
	 * Renders the pending-proposals notice/table on the agnosis_medium
	 * taxonomy list screen only — a no-op everywhere else, including every
	 * OTHER admin_notices consumer, since this reads get_current_screen()
	 * itself rather than being conditionally hooked (matches ArtworkMediumSync's
	 * own maybe_render_*_notice() convention for the same reason: admin_notices
	 * fires on every wp-admin page, so the callback has to self-gate).
	 */
	public function maybe_render_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || 'edit-agnosis_medium' !== $screen->id ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$this->maybe_render_action_result_notice();

		$proposals = $this->get_proposals();
		if ( empty( $proposals ) ) {
			return;
		}

		echo '<div class="notice notice-info"><p><strong>' .
			esc_html__( 'AI-proposed medium categories awaiting review', 'agnosis' ) .
			'</strong></p><table class="widefat" style="max-width:640px;margin-bottom:1em;"><thead><tr><th>' .
			esc_html__( 'Proposed medium', 'agnosis' ) . '</th><th>' .
			esc_html__( 'Submissions', 'agnosis' ) . '</th><th>' .
			esc_html__( 'Actions', 'agnosis' ) . '</th></tr></thead><tbody>';

		foreach ( $proposals as $row ) {
			$proposal = (string) $row['proposal'];
			$count    = (int) $row['post_count'];
			$posts    = $row['posts'];

			$approve_url = wp_nonce_url(
				add_query_arg(
					[ 'action' => 'agnosis_approve_medium_proposal', 'proposal' => rawurlencode( $proposal ) ],
					admin_url( 'admin-post.php' )
				),
				'agnosis_medium_proposal_' . $proposal
			);
			$reject_url = wp_nonce_url(
				add_query_arg(
					[ 'action' => 'agnosis_reject_medium_proposal', 'proposal' => rawurlencode( $proposal ) ],
					admin_url( 'admin-post.php' )
				),
				'agnosis_medium_proposal_' . $proposal
			);

			// 2026-07-21: a bare count told the admin THAT 3 submissions
			// proposed "Short Story" but not WHICH ones — no way to sanity-check
			// the proposal against the actual submissions before approving it
			// for all of them at once. Each submission's own title now links
			// straight to its edit screen.
			$post_links = [];
			foreach ( $posts as $post ) {
				$edit_link = get_edit_post_link( (int) $post['id'], 'raw' );
				$title     = '' !== trim( (string) $post['title'] ) ? (string) $post['title'] : __( '(no title)', 'agnosis' );

				$post_links[] = $edit_link
					? sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html( $title ) )
					: esc_html( $title );
			}

			printf(
				'<tr><td>%1$s</td><td>%2$s<br>%3$s</td><td><a href="%4$s" class="button button-primary">%5$s</a> <a href="%6$s" class="button" onclick="return confirm(%7$s);">%8$s</a></td></tr>',
				esc_html( $proposal ),
				esc_html(
					sprintf(
						/* translators: %d: number of submissions carrying this exact proposal */
						_n( '%d submission', '%d submissions', $count, 'agnosis' ),
						$count
					)
				),
				// Each title/URL is already individually escaped above via
				// esc_html()/esc_url() when building $post_links — wp_kses_post()
				// here is defense-in-depth (restricts to the safe <a> markup
				// this method itself produces) and satisfies the sniff, which
				// can't see that the pieces were escaped earlier.
				wp_kses_post( implode( ', ', $post_links ) ),
				esc_url( $approve_url ),
				esc_html__( 'Approve', 'agnosis' ),
				esc_url( $reject_url ),
				// esc_attr() (not just wp_json_encode()) — this lands inside a
				// double-quoted HTML attribute, and wp_json_encode() itself
				// produces a double-quoted JS string; without esc_attr() those
				// inner quotes would prematurely close the onclick="..."
				// attribute instead of staying valid, safely-nested JS.
				esc_attr( (string) wp_json_encode( __( 'Reject this proposal for all matching submissions? This cannot be undone.', 'agnosis' ) ) ),
				esc_html__( 'Reject', 'agnosis' )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Distinct pending proposal values, each with how many agnosis_artwork
	 * posts currently carry it AND which ones specifically (2026-07-21: a bare
	 * count didn't let the admin see WHICH submissions a proposal covers
	 * before approving/rejecting it for all of them at once) — a plain
	 * per-post query grouped in PHP rather than get_posts()/a GROUP BY
	 * aggregate, since displaying each post's own id+title needs the
	 * ungrouped rows anyway.
	 *
	 * @return array<int, array{proposal: string, post_count: int, posts: array<int, array{id: int, title: string}>}>
	 */
	private function get_proposals(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-screen listing joined across postmeta/posts; no core API fits this shape.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS proposal, p.ID AS post_id, p.post_title AS post_title
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND p.post_type = 'agnosis_artwork'
				   AND pm.meta_value != ''
				 ORDER BY pm.meta_value ASC, p.post_title ASC",
				self::META_KEY
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$grouped = [];
		foreach ( $rows as $row ) {
			$proposal = (string) $row['proposal'];

			if ( ! isset( $grouped[ $proposal ] ) ) {
				$grouped[ $proposal ] = [ 'proposal' => $proposal, 'post_count' => 0, 'posts' => [] ];
			}

			++$grouped[ $proposal ]['post_count'];
			$grouped[ $proposal ]['posts'][] = [ 'id' => (int) $row['post_id'], 'title' => (string) $row['post_title'] ];
		}

		// Same ordering the old GROUP BY query produced: most-proposed value
		// first, alphabetical among ties.
		uasort(
			$grouped,
			static function ( array $a, array $b ): int {
				return ( $b['post_count'] <=> $a['post_count'] ) ?: strcmp( $a['proposal'], $b['proposal'] );
			}
		);

		return array_values( $grouped );
	}

	/**
	 * Post IDs currently carrying an exact proposal value — shared by both
	 * admin-post handlers below.
	 *
	 * @return int[]
	 */
	private function get_posts_with_proposal( string $proposal ): array {
		return get_posts( [
			'post_type'      => 'agnosis_artwork',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin-triggered, bounded review action, not a front-end query.
			'meta_value'     => $proposal, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
	}

	// -------------------------------------------------------------------------
	// admin_post handlers
	// -------------------------------------------------------------------------

	/**
	 * Approve — create/reuse the term, assign it to every post currently
	 * carrying this exact proposal, clear the meta on each.
	 */
	public function handle_approve(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- unslashed + decoded on the next line together.
		$proposal = sanitize_text_field( rawurldecode( wp_unslash( $_GET['proposal'] ?? '' ) ) );

		check_admin_referer( 'agnosis_medium_proposal_' . $proposal );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		[ $approved, $error ] = $this->approve_proposal( $proposal );

		if ( null !== $error ) {
			wp_safe_redirect(
				add_query_arg(
					[ 'taxonomy' => 'agnosis_medium', 'agnosis_medium_proposal_error' => rawurlencode( $error ) ],
					admin_url( 'edit-tags.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'taxonomy' => 'agnosis_medium', 'agnosis_medium_proposal_approved' => $approved ],
				admin_url( 'edit-tags.php' )
			)
		);
		exit;
	}

	/**
	 * Reject — clear the meta on every post currently carrying this exact
	 * proposal, without creating or assigning any term.
	 */
	public function handle_reject(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- unslashed + decoded on the next line together.
		$proposal = sanitize_text_field( rawurldecode( wp_unslash( $_GET['proposal'] ?? '' ) ) );

		check_admin_referer( 'agnosis_medium_proposal_' . $proposal );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$rejected = $this->reject_proposal( $proposal );

		wp_safe_redirect(
			add_query_arg(
				[ 'taxonomy' => 'agnosis_medium', 'agnosis_medium_proposal_rejected' => $rejected ],
				admin_url( 'edit-tags.php' )
			)
		);
		exit;
	}

	/**
	 * The actual approve logic, pulled out of handle_approve() so it can be
	 * exercised directly in a test without that method's terminal exit;
	 * (both admin_post handlers in this class end their request the same way
	 * every other admin-post handler in this codebase does — see
	 * QueueController/TaxonomyLanguageFilter — which makes them unsuitable to
	 * call directly from a PHPUnit process).
	 *
	 * @return array{0: int, 1: string|null} [ posts approved, error message or null ].
	 */
	private function approve_proposal( string $proposal ): array {
		if ( '' === $proposal ) {
			return [ 0, null ];
		}

		if ( ! get_term_by( 'name', $proposal, 'agnosis_medium' ) ) {
			$inserted = wp_insert_term( $proposal, 'agnosis_medium' );
			if ( is_wp_error( $inserted ) ) {
				return [ 0, $inserted->get_error_message() ];
			}
		}

		$approved = 0;
		foreach ( $this->get_posts_with_proposal( $proposal ) as $post_id ) {
			wp_set_object_terms( (int) $post_id, $proposal, 'agnosis_medium' );
			delete_post_meta( (int) $post_id, self::META_KEY );
			++$approved;
		}

		return [ $approved, null ];
	}

	/**
	 * The actual reject logic — see approve_proposal()'s docblock for why
	 * this is pulled out of handle_reject() the same way.
	 */
	private function reject_proposal( string $proposal ): int {
		if ( '' === $proposal ) {
			return 0;
		}

		$rejected = 0;
		foreach ( $this->get_posts_with_proposal( $proposal ) as $post_id ) {
			delete_post_meta( (int) $post_id, self::META_KEY );
			++$rejected;
		}

		return $rejected;
	}

	/**
	 * Courtesy notice after either admin-post handler's redirect — plain
	 * "N submission(s) approved/rejected" summary, same convention as
	 * TaxonomyLanguageFilter::maybe_render_sync_notice().
	 */
	private function maybe_render_action_result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only courtesy notice reflecting the redirect this same class just performed after its own nonce-checked action, no state change here.
		if ( isset( $_GET['agnosis_medium_proposal_approved'] ) ) {
			$count = (int) $_GET['agnosis_medium_proposal_approved'];
			wp_admin_notice(
				sprintf(
					/* translators: %d: number of submissions the approved medium was assigned to */
					esc_html( _n( 'Medium proposal approved and assigned to %d submission.', 'Medium proposal approved and assigned to %d submissions.', $count, 'agnosis' ) ),
					$count
				),
				[ 'type' => 'success' ]
			);
			return;
		}

		if ( isset( $_GET['agnosis_medium_proposal_rejected'] ) ) {
			$count = (int) $_GET['agnosis_medium_proposal_rejected'];
			wp_admin_notice(
				sprintf(
					/* translators: %d: number of submissions the rejected proposal was cleared from */
					esc_html( _n( 'Medium proposal rejected for %d submission.', 'Medium proposal rejected for %d submissions.', $count, 'agnosis' ) ),
					$count
				),
				[ 'type' => 'success' ]
			);
			return;
		}

		if ( isset( $_GET['agnosis_medium_proposal_error'] ) ) {
			wp_admin_notice(
				sprintf(
					/* translators: %s: the underlying WP_Error message from wp_insert_term() */
					esc_html__( 'Could not approve this proposal: %s', 'agnosis' ),
					esc_html( rawurldecode( (string) $_GET['agnosis_medium_proposal_error'] ) )
				),
				[ 'type' => 'error' ]
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
