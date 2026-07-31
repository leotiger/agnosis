<?php
/**
 * Admin review queue for tag candidates that don't match the live post_tag
 * vocabulary at approval time — TAG-REDESIGN.md T2, "one term is created
 * only by: an admin on the Tags screen, a TagProposals approval, or the
 * trid translation sync" (invariant 1).
 *
 * Mirrors Admin\MediumProposals — same screen-integration pattern, same
 * Approve/Reject semantics — with two structural differences forced by how
 * tags differ from medium:
 *
 *   - Multi-value meta. A post can have several PENDING tag proposals at
 *     once (medium is single-value — a post has at most one proposed
 *     medium). `_agnosis_tag_proposal` is therefore a non-unique
 *     `add_post_meta()` row per name, not a single meta value — clearing
 *     ONE proposal must use `delete_post_meta( $post_id, KEY, $value )`'s
 *     3-arg form so it never touches this post's OTHER pending proposals.
 *   - Cross-CPT scope. `post_tag` is registered against all three Agnosis
 *     CPTs (agnosis_artwork/agnosis_biography/agnosis_event — see
 *     Artist\Profile::register_post_type()); `agnosis_medium` is
 *     agnosis_artwork-only. Every query here spans all three.
 *   - Normalized reuse-on-approve. Uses `Publishing\TagGate::vocabulary_map()`
 *     (TW-9: comparison-key normalization — trim/whitespace/NFC/lowercase)
 *     to check for an existing equivalent term before creating a new one,
 *     rather than MediumProposals' exact-string `get_term_by( 'name', ... )`
 *     — a deliberate improvement (T5 later brings medium up to the same
 *     standard). The admin LISTING below still groups by exact proposal
 *     string (same as MediumProposals) — two near-duplicate-casing rows can
 *     therefore both appear pending, but approving either resolves through
 *     the SAME normalized lookup, so the term is created only once either
 *     way; this is a display-only limitation, not a data-correctness one.
 *   - Assignment is ADDITIVE (`wp_set_object_terms( ..., true )`), not a
 *     replace. `ReviewEndpoints::finalize_tags()` already assigned this
 *     post's MATCHED tags by the time a proposal reaches approval; approving
 *     a proposal must add the newly-created term alongside those, never
 *     wipe them (medium's own `wp_set_object_terms()` call omits `$append`
 *     because a post only ever has one medium, so replace and add are
 *     equivalent there — not true for tags).
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

use Agnosis\Compat\LinguaForge;
use Agnosis\Core\Logger;
use Agnosis\Publishing\TagGate;

class TagProposals {

	private const META_KEY = TagGate::PROPOSAL_META;

	/** post_tag is registered against all three — see this class's own docblock. */
	private const POST_TYPES = [ 'agnosis_artwork', 'agnosis_biography', 'agnosis_event' ];

	// -------------------------------------------------------------------------
	// Display — Tags admin screen
	// -------------------------------------------------------------------------

	/**
	 * Renders the pending-proposals notice/table on the Tags taxonomy list
	 * screen only — a no-op everywhere else, same self-gating convention
	 * MediumProposals::maybe_render_notice() uses (admin_notices fires on
	 * every wp-admin page).
	 */
	public function maybe_render_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || 'edit-post_tag' !== $screen->id ) {
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
			esc_html__( 'AI-proposed tags awaiting review', 'agnosis' ) .
			'</strong></p><table class="widefat" style="max-width:640px;margin-bottom:1em;"><thead><tr><th>' .
			esc_html__( 'Proposed tag', 'agnosis' ) . '</th><th>' .
			esc_html__( 'Submissions', 'agnosis' ) . '</th><th>' .
			esc_html__( 'Actions', 'agnosis' ) . '</th></tr></thead><tbody>';

		foreach ( $proposals as $row ) {
			$proposal = (string) $row['proposal'];
			$count    = (int) $row['post_count'];
			$posts    = $row['posts'];

			$approve_url = wp_nonce_url(
				add_query_arg(
					[ 'action' => 'agnosis_approve_tag_proposal', 'proposal' => rawurlencode( $proposal ) ],
					admin_url( 'admin-post.php' )
				),
				'agnosis_tag_proposal_' . $proposal
			);
			$reject_url = wp_nonce_url(
				add_query_arg(
					[ 'action' => 'agnosis_reject_tag_proposal', 'proposal' => rawurlencode( $proposal ) ],
					admin_url( 'admin-post.php' )
				),
				'agnosis_tag_proposal_' . $proposal
			);

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
				wp_kses_post( implode( ', ', $post_links ) ),
				esc_url( $approve_url ),
				esc_html__( 'Approve', 'agnosis' ),
				esc_url( $reject_url ),
				esc_attr( (string) wp_json_encode( __( 'Reject this tag proposal for all matching submissions? This cannot be undone.', 'agnosis' ) ) ),
				esc_html__( 'Reject', 'agnosis' )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Distinct pending proposal values, each with how many posts (across all
	 * three Agnosis CPTs) currently carry it AND which ones specifically —
	 * same shape as MediumProposals::get_proposals(), grouped in PHP rather
	 * than a GROUP BY aggregate since each post's own id+title is needed
	 * regardless.
	 *
	 * @return array<int, array{proposal: string, post_count: int, posts: array<int, array{id: int, title: string}>}>
	 */
	private function get_proposals(): array {
		global $wpdb;

		$post_type_placeholders = implode( ', ', array_fill( 0, count( self::POST_TYPES ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-screen listing joined across postmeta/posts; no core API fits this shape.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS proposal, p.ID AS post_id, p.post_title AS post_title
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND p.post_type IN ($post_type_placeholders)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $post_type_placeholders is a fixed '%s, %s, %s' string built from count( self::POST_TYPES ), never user input; the real values are still bound via $wpdb->prepare()'s own args below.
				   . " AND pm.meta_value != ''
				 ORDER BY pm.meta_value ASC, p.post_title ASC",
				array_merge( [ self::META_KEY ], self::POST_TYPES )
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

		uasort(
			$grouped,
			static function ( array $a, array $b ): int {
				return ( $b['post_count'] <=> $a['post_count'] ) ?: strcmp( $a['proposal'], $b['proposal'] );
			}
		);

		return array_values( $grouped );
	}

	/**
	 * Post IDs (across all three Agnosis CPTs) currently carrying an exact
	 * proposal value — shared by both admin-post handlers below. A
	 * `meta_key`+`meta_value` query matches any row with that exact pair
	 * regardless of the meta's own uniqueness, so this works the same way
	 * against `_agnosis_tag_proposal`'s multiple-rows-per-post shape as
	 * MediumProposals::get_posts_with_proposal() does against medium's
	 * single-value meta.
	 *
	 * @return int[]
	 */
	private function get_posts_with_proposal( string $proposal ): array {
		return get_posts( [
			'post_type'      => self::POST_TYPES,
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
	 * Approve — create/reuse the term (normalized match — see class
	 * docblock), ADD it to every post currently carrying this exact
	 * proposal (append, never replace — see class docblock), clear only
	 * this specific proposal row on each.
	 */
	public function handle_approve(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed, decoded, and sanitized on the next line together (Plugin Check's static analysis doesn't trace sanitization through the intermediate rawurldecode() call; sanitize_text_field() is the outermost wrapper and this is genuinely safe).
		$proposal = sanitize_text_field( rawurldecode( wp_unslash( $_GET['proposal'] ?? '' ) ) );

		check_admin_referer( 'agnosis_tag_proposal_' . $proposal );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		[ $approved, $error ] = $this->approve_proposal( $proposal );

		if ( null !== $error ) {
			wp_safe_redirect(
				add_query_arg(
					[ 'taxonomy' => 'post_tag', 'agnosis_tag_proposal_error' => rawurlencode( $error ) ],
					admin_url( 'edit-tags.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'taxonomy' => 'post_tag', 'agnosis_tag_proposal_approved' => $approved ],
				admin_url( 'edit-tags.php' )
			)
		);
		exit;
	}

	/**
	 * Reject — clear only this specific proposal row on every post
	 * currently carrying it, without creating or assigning any term. Each
	 * post keeps whatever tags (if any) it already has, including any
	 * OTHER still-pending proposal it also carries.
	 */
	public function handle_reject(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed, decoded, and sanitized on the next line together (Plugin Check's static analysis doesn't trace sanitization through the intermediate rawurldecode() call; sanitize_text_field() is the outermost wrapper and this is genuinely safe).
		$proposal = sanitize_text_field( rawurldecode( wp_unslash( $_GET['proposal'] ?? '' ) ) );

		check_admin_referer( 'agnosis_tag_proposal_' . $proposal );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$rejected = $this->reject_proposal( $proposal );

		wp_safe_redirect(
			add_query_arg(
				[ 'taxonomy' => 'post_tag', 'agnosis_tag_proposal_rejected' => $rejected ],
				admin_url( 'edit-tags.php' )
			)
		);
		exit;
	}

	/**
	 * The actual approve logic, pulled out of handle_approve() so it can be
	 * exercised directly in a test without that method's terminal exit —
	 * same convention as MediumProposals::approve_proposal()'s own docblock
	 * explains.
	 *
	 * @return array{0: int, 1: string|null} [ posts approved, error message or null ].
	 */
	private function approve_proposal( string $proposal ): array {
		if ( '' === $proposal ) {
			return [ 0, null ];
		}

		$normalized = TagGate::normalize_for_match( $proposal );
		$vocabulary = TagGate::vocabulary_map();

		if ( isset( $vocabulary[ $normalized ] ) ) {
			// Reuse — same normalized-match tolerance TW-9 introduces
			// (an admin approving the same name twice, or a name that
			// happens to already match an existing term some other way,
			// must not create a duplicate).
			$term_id = $vocabulary[ $normalized ];
		} else {
			$inserted = wp_insert_term( $proposal, 'post_tag' );
			if ( is_wp_error( $inserted ) ) {
				return [ 0, $inserted->get_error_message() ];
			}
			$term_id = (int) $inserted['term_id'];
			// TAG-REDESIGN.md T3(b): a brand-new primary term entering the
			// vocabulary via approval queues background translation into
			// every active language — never for the reuse branch above,
			// since a reused term already has its own queue marker (or
			// predates the queue and is covered by the "Sync all
			// translations" backfill button).
			LinguaForge::queue_translation_for_term( $term_id, 'post_tag' );
		}

		$approved = 0;
		foreach ( $this->get_posts_with_proposal( $proposal ) as $post_id ) {
			// Append — never replace (class docblock: finalize_tags() may
			// already have assigned this post's MATCHED tags; approving one
			// proposal must add alongside those, not wipe them).
			wp_set_object_terms( (int) $post_id, $term_id, 'post_tag', true );
			// Clears only THIS proposal's row (and its timestamp-map entry),
			// never a sibling pending proposal the same post also carries
			// (class docblock's multi-value-meta note).
			TagGate::clear_proposal( (int) $post_id, $proposal );
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
			TagGate::clear_proposal( (int) $post_id, $proposal );
			++$rejected;
		}

		return $rejected;
	}

	// -------------------------------------------------------------------------
	// TTL sweep — WP-Cron
	// -------------------------------------------------------------------------

	/**
	 * Auto-reject any pending proposal older than `agnosis_proposal_ttl` days
	 * (default 7; TAG-REDESIGN.md T5(b) — shared with
	 * Admin\MediumProposals::sweep_expired(), renamed from the tag-only
	 * `agnosis_tag_proposal_ttl`, migrated by
	 * Activator::migrate_tag_proposal_ttl_option()) — "behaves exactly like
	 * a rejection" (T2's acceptance criteria): clears the row (and its
	 * timestamp-map entry) via the same TagGate::clear_proposal()
	 * reject_proposal() uses, creates or assigns nothing, and logs each
	 * sweep so an admin can see why a proposal that was pending yesterday is
	 * gone today.
	 *
	 * Scans every post carrying a `TagGate::PROPOSAL_CREATED_META` map rather
	 * than every post carrying a `PROPOSAL_META` row — the two are always in
	 * sync (both written/cleared together by finalize_tags()/clear_proposal()),
	 * and the map is what actually holds the per-name ages this method needs.
	 */
	public function sweep_expired(): void {
		$ttl_days = max( 1, (int) get_option( 'agnosis_proposal_ttl', 7 ) );
		$cutoff   = time() - ( $ttl_days * DAY_IN_SECONDS );

		$post_ids = get_posts( [
			'post_type'      => self::POST_TYPES,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => TagGate::PROPOSAL_CREATED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- daily cron, not a front-end query; bounded to posts actually carrying pending proposals.
		] );

		$swept = 0;
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$raw     = (string) get_post_meta( $post_id, TagGate::PROPOSAL_CREATED_META, true );
			$map     = json_decode( $raw, true );
			if ( ! is_array( $map ) ) {
				continue;
			}

			foreach ( $map as $name => $created_at ) {
				if ( (int) $created_at > $cutoff ) {
					continue;
				}

				TagGate::clear_proposal( $post_id, (string) $name );
				++$swept;

				Logger::info(
					sprintf(
						'TagProposals::sweep_expired(): proposal "%1$s" on post #%2$d expired (older than %3$d day(s)) — auto-rejected.',
						$name,
						$post_id,
						$ttl_days
					),
					'review'
				);
			}
		}

		if ( $swept > 0 ) {
			Logger::info(
				sprintf( 'TagProposals::sweep_expired(): %d expired proposal(s) auto-rejected.', $swept ),
				'review'
			);
		}
	}

	/**
	 * Courtesy notice after either admin-post handler's redirect — same
	 * convention as MediumProposals::maybe_render_action_result_notice().
	 */
	private function maybe_render_action_result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only courtesy notice reflecting the redirect this same class just performed after its own nonce-checked action, no state change here.
		if ( isset( $_GET['agnosis_tag_proposal_approved'] ) ) {
			$count = (int) $_GET['agnosis_tag_proposal_approved'];
			wp_admin_notice(
				sprintf(
					/* translators: %d: number of submissions the approved tag was assigned to */
					esc_html( _n( 'Tag proposal approved and assigned to %d submission.', 'Tag proposal approved and assigned to %d submissions.', $count, 'agnosis' ) ),
					$count
				),
				[ 'type' => 'success' ]
			);
			return;
		}

		if ( isset( $_GET['agnosis_tag_proposal_rejected'] ) ) {
			$count = (int) $_GET['agnosis_tag_proposal_rejected'];
			wp_admin_notice(
				sprintf(
					/* translators: %d: number of submissions the rejected proposal was cleared from */
					esc_html( _n( 'Tag proposal rejected for %d submission.', 'Tag proposal rejected for %d submissions.', $count, 'agnosis' ) ),
					$count
				),
				[ 'type' => 'success' ]
			);
			return;
		}

		if ( isset( $_GET['agnosis_tag_proposal_error'] ) ) {
			wp_admin_notice(
				sprintf(
					/* translators: %s: the underlying WP_Error message from wp_insert_term() */
					esc_html__( 'Could not approve this proposal: %s', 'agnosis' ),
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed, decoded, and escaped for output all in this one expression (same rawurldecode()-breaks-tracing note as handle_approve()/handle_reject() above); esc_html() is the outermost wrapper and this is genuinely safe.
					esc_html( rawurldecode( (string) wp_unslash( $_GET['agnosis_tag_proposal_error'] ) ) )
				),
				[ 'type' => 'error' ]
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
