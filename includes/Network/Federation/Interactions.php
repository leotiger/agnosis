<?php
/**
 * Likes and boosts — what the world does *to* an artwork, in both directions.
 *
 * Fourth unit extracted from Network\ActivityPub (sixteenth audit, Q-2, WP4 —
 * agnosis-audit/ACTIVITYPUB-SPLIT-ROADMAP.md). One class for what arrived as
 * three separate roadmap phases, because they are three views of one table:
 *
 * - **Inbound** (Phase 1): a remote actor Likes or Announces one of our
 *   artworks; `record_interaction()`/`undo_interaction()` write and unwrite the
 *   `agnosis_interactions` row.
 * - **On-site** (Phase 3 WP2/WP5): a visitor likes from the artwork page, or an
 *   admitted artist boosts — the same table, written locally, and for a boost
 *   also federated outward as an `Announce`.
 * - **Read** (Phase 1 + NL1): `interaction_counts()` for one artwork all-time,
 *   `personal_interaction_counts()` for one artist across a digest window, and
 *   the `agnosis/interaction-counts` block that renders the former.
 *
 * **A visitor like is pseudonymous by construction.** `like_identity()` derives
 * an actor id from a rotating salt (`rotate_like_salt()`), so the stored
 * `actor_id` is not reversible to an IP or a person, and rotation deliberately
 * retains no previous salt.
 *
 * §3 ranked `Counts -> Likes` as an upward edge; §2a corrected that — counts,
 * likes and boosts were always one unit, so the edge is intra-class and
 * disappears here rather than needing a delegator (the ranking was wrong, not
 * the code).
 *
 * Depends on Identity (actor URLs, post ownership, and the object id a boost
 * Announces) and Delivery (fan-out of a boost to the artist's followers) — both
 * injected, so the layering stays acyclic by construction:
 *
 *     Identity -> Delivery -> **Interactions** / Rhizome -> Replies -> Serialization
 *
 * Nothing here reads a reply. `post_to_note()` reads *these* counts, which is
 * why Serialization sits above this class and not beside it (§2a).
 *
 * Behaviour is unchanged. Every method body is the one that stood in
 * ActivityPub.php; only `record_interaction()` and `undo_interaction()` widened
 * to public, because the orchestrator's `inbox()` and `handle_undo()` dispatch
 * to them from what is now a sibling class.
 *
 * @package Agnosis\Network\Federation
 */

declare(strict_types=1);

namespace Agnosis\Network\Federation;

use Agnosis\Core\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Interactions {

	public function __construct(
		private Identity $identity,
		private Delivery $delivery
	) {}

	/**
	 * On-site like toggle rate limit (interaction-surface roadmap, Phase 3,
	 * WP2, §5) — pure abuse prevention, not a product feature (Ulises: no
	 * artist-level gate and no rate limit "as a feature" on likes at all).
	 * Generous relative to admission_apply's 5/60 since a visitor
	 * legitimately liking/unliking a few artworks in a minute while browsing
	 * is completely ordinary, unlike submitting an admission application.
	 */
	private const LIKE_RATE_LIMIT   = 20;
	private const LIKE_RATE_WINDOW  = 60;

	/**
	 * Like/boost counts for one artwork (interaction-surface roadmap, Phase
	 * 1). Used both by the on-site agnosis/interaction-counts display block
	 * and by post_to_note()'s outbound likesCount/sharesCount.
	 *
	 * @param int $post_id Artwork post id.
	 * @return array{like: int, announce: int}
	 */
	public function interaction_counts( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only aggregate over a small, per-artwork-scale table; $post_id is an int cast, not user input reaching SQL directly.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT activity_type, COUNT(*) AS c FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d GROUP BY activity_type",
				$post_id
			)
		);

		$counts = [ 'like' => 0, 'announce' => 0 ];
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->activity_type ] ) ) {
				$counts[ $row->activity_type ] = (int) $row->c;
			}
		}

		return $counts;
	}

	/**
	 * Register the agnosis/interaction-counts dynamic block.
	 *
	 * block.json lives in blocks/interaction-counts/ relative to the plugin
	 * root — same directory-registration shape as agnosis/artwork-copyright
	 * (Artist\Profile::register_blocks()), chosen over a bare
	 * register_block_type() call so an admin gets real Color/Typography
	 * Inspector controls for what is otherwise a plain server-rendered string.
	 */
	public function register_interaction_counts_block(): void {
		register_block_type(
			\AGNOSIS_DIR . 'blocks/interaction-counts',
			[ 'render_callback' => [ $this, 'render_interaction_counts' ] ]
		);
	}

	/**
	 * Render callback for the agnosis/interaction-counts block.
	 *
	 * Ulises's design intent (agnosis-audit/INTERACTION-SURFACE-ROADMAP.md
	 * §3/§5): likes shown inline, small, never competing visually with the
	 * artwork; boosts counted AND displayed independently from likes, not
	 * combined into one number. Renders nothing — not an empty element — on a
	 * non-artwork post, but ALWAYS renders both counts on an artwork, even
	 * "♥ 0 like · ⟲ 0 boosts" — an earlier version of this method hid the
	 * whole line at zero-of-both to avoid a "permanent fixture", but that
	 * left the "Universe:" label above it (agnosis-theme's
	 * templates/single.html) dangling with nothing after it on every artwork
	 * that hadn't yet been liked/boosted, which reads as a broken layout
	 * rather than "nothing to show" (caught 2026-07-25 on a live front-end
	 * check). Always-show is the corrected, deliberate call.
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 * @return string HTML output or empty string when not applicable.
	 */
	public function render_interaction_counts( array $attrs, string $content, \WP_Block $block ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );

		if ( ! $post || 'agnosis_artwork' !== $post->post_type ) {
			return '';
		}

		$counts = $this->interaction_counts( $post_id );

		// Interaction-surface roadmap, Phase 3, WP2 (2026-07-27) — the like
		// half of this block is now a real toggle, not just a display. The
		// visitor's own liked/not-liked state is computed here, server-side,
		// at render time, using the exact same like_identity() the REST
		// toggle itself hashes against — so the button's initial state is
		// already correct on first paint, no separate fetch needed just to
		// learn it (frontend.js only has to call the REST endpoint on an
		// actual click). Boosts stay exactly as they were: plain text, no
		// interactivity — WP2 is on-site LIKES only (boosting is WP5, per the
		// roadmap's own dependency note), and the display deliberately
		// resists growing into a toolbar.
		$liked = $this->has_liked( $post_id, $this->like_identity() );

		wp_enqueue_style( 'agnosis-interaction-counts', \AGNOSIS_URL . 'blocks/interaction-counts/frontend.css', [], \AGNOSIS_VERSION );
		wp_enqueue_script( 'agnosis-interaction-counts', \AGNOSIS_URL . 'blocks/interaction-counts/frontend.js', [], \AGNOSIS_VERSION, [ 'in_footer' => true ] );
		wp_localize_script( 'agnosis-interaction-counts', 'agnosisInteractionCounts', [
			'apiUrlBase' => rest_url( 'agnosis/v1/content/' ),
			// A logged-in artist's own session has auth cookies, which makes
			// WordPress's cookie-auth REST layer require a valid nonce on any
			// write request regardless of this route's own permission_callback
			// (rest_cookie_check_errors() runs before route dispatch) — same
			// reason blocks/content-editor/frontend.js carries one. A fully
			// anonymous visitor has no auth cookie, so the check never
			// triggers and this nonce is simply unused for them.
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'i18n'       => [
				// translators: %d: number of likes.
				'like'  => __( '♥ %d like', 'agnosis' ),
				// translators: %d: number of likes.
				'likes' => __( '♥ %d likes', 'agnosis' ),
				'error' => __( 'Could not update like.', 'agnosis' ),
			],
		] );

		$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'agnosis-interaction-counts' ] );

		$parts   = [];
		$parts[] = sprintf(
			'<button type="button" class="agnosis-interaction-counts__likes" data-agnosis-like-post-id="%1$s" aria-pressed="%2$s"><span class="agnosis-interaction-counts__likes-text">%3$s</span></button>',
			esc_attr( (string) $post_id ),
			esc_attr( $liked ? 'true' : 'false' ),
			esc_html(
				sprintf(
					/* translators: %d: number of likes. */
					_n( '♥ %d like', '♥ %d likes', $counts['like'], 'agnosis' ),
					$counts['like']
				)
			)
		);
		$parts[] = sprintf(
			'<span class="agnosis-interaction-counts__boosts">%s</span>',
			esc_html(
				sprintf(
					/* translators: %d: number of boosts. */
					_n( '⟲ %d boost', '⟲ %d boosts', $counts['announce'], 'agnosis' ),
					$counts['announce']
				)
			)
		);

		return sprintf(
			'<p %s>%s</p>',
			$wrapper_attributes,
			implode( ' · ', $parts )
		);
	}

	/**
	 * Persist an inbound Like/Announce (interaction-surface roadmap, Phase 1,
	 * 2026-07-24). Before this, inbox() acknowledged both activity types with
	 * a 200 and discarded them — see the class docblock history and
	 * COMPETITIVE-ANALYSIS.md §3b for why this was a deliberately deferred gap
	 * through fifteen audits, not an oversight.
	 *
	 * Ulises's answer on boosts (agnosis-audit/INTERACTION-SURFACE-
	 * ROADMAP.md §5): counted and displayed independently from likes, not
	 * combined into one number — hence a plain `activity_type` column rather
	 * than folding Announce into the same bucket as Like.
	 *
	 * Deliberately silent (no error response) when the activity doesn't
	 * resolve to a local artwork or carries no actor — a Like/Announce for
	 * something Agnosis doesn't recognize, or a malformed delivery, is simply
	 * not counted; inbox() already returns 200 regardless, matching how every
	 * other "not applicable here" activity in this class is handled (e.g. the
	 * `Move` case).
	 *
	 * Upserts by the table's own (post_id, activity_type, actor_id) unique
	 * key via $wpdb->replace() — same idempotent-on-redelivery pattern
	 * handle_follow() already uses for agnosis_followers, so a re-delivered
	 * or re-Liked activity refreshes received_at rather than double-counting.
	 * No artist-level or rate-limit gate here — Ulises's answer (§5): likes
	 * and boosts are always allowed, no approval workflow, no restriction.
	 *
	 * @param array<string, mixed> $body Raw activity payload.
	 * @param string                $type 'like' or 'announce'.
	 */
	public function record_interaction( array $body, string $type ): void {
		$object = $body['object'] ?? '';
		if ( is_array( $object ) ) {
			$object_url = is_string( $object['id'] ?? null ) ? $object['id'] : '';
		} else {
			$object_url = is_string( $object ) ? $object : '';
		}

		$post_id = $this->identity->resolve_local_post_id( $object_url );
		if ( ! $post_id ) {
			return;
		}

		$actor_id = is_string( $body['actor'] ?? null ) ? $body['actor'] : '';
		if ( '' === $actor_id ) {
			return;
		}

		$activity_id = is_string( $body['id'] ?? null ) ? $body['id'] : null;

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->replace() parameterizes every value; small, per-artwork-scale table.
		$wpdb->replace(
			$wpdb->prefix . 'agnosis_interactions',
			[
				'post_id'       => $post_id,
				'activity_type' => $type,
				'actor_id'      => $actor_id,
				'object_id'     => $activity_id,
			],
			[ '%d', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Delete a previously-recorded Like/Announce on Undo (interaction-surface
	 * roadmap, Phase 1). Mirrors handle_undo()'s existing Undo{Follow}
	 * handling — same "read the nested activity's own object" shape, since
	 * AS2's Undo{Like}/Undo{Announce} wraps the original activity in `object`
	 * rather than naming the target directly the way Undo{Follow} does.
	 *
	 * Without this, a like/boost count could only ever grow — see the
	 * roadmap doc's §2 assessment for why this was called out as a gap the
	 * moment Phase 1 started persisting anything at all.
	 *
	 * @param array<string, mixed> $body Undo activity payload.
	 * @param string                $type 'like' or 'announce' (the nested
	 *                                    object's own AS2 type, lowercased).
	 */
	public function undo_interaction( array $body, string $type ): void {
		$inner = $body['object'] ?? [];
		if ( ! is_array( $inner ) ) {
			return;
		}

		$inner_object = $inner['object'] ?? '';
		$object_url   = is_array( $inner_object )
			? ( is_string( $inner_object['id'] ?? null ) ? $inner_object['id'] : '' )
			: ( is_string( $inner_object ) ? $inner_object : '' );

		$post_id = $this->identity->resolve_local_post_id( $object_url );
		if ( ! $post_id ) {
			return;
		}

		// The outer Undo's own `actor` is who's undoing — matches AS2 (an
		// Undo must be signed by the same actor as the activity it undoes),
		// same assumption resolve_inbox_signature()'s actor-binding check
		// already enforces before inbox() is ever reached.
		$actor_id = is_string( $body['actor'] ?? null ) ? $body['actor'] : '';
		if ( '' === $actor_id ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes every value; small, per-artwork-scale table.
		$wpdb->delete(
			$wpdb->prefix . 'agnosis_interactions',
			[ 'post_id' => $post_id, 'activity_type' => $type, 'actor_id' => $actor_id ],
			[ '%d', '%s', '%s' ]
		);
	}

	/**
	 * Aggregate like/boost counts across ALL of one artist's own artwork,
	 * since a given digest window — NL1 (RHIZOME-NETWORK-ROADMAP.md §11a,
	 * 2026-07-30). Unlike interaction_counts() above (one post, all time),
	 * this is one artist across every post they own, scoped to a time
	 * window — the per-recipient personalization
	 * Newsletter\Digest::build_artist()'s shared, once-per-locale render
	 * can't compute itself (see that class's own
	 * `{{AGNOSIS_INTERACTION_SUMMARY}}` placeholder note); resolved later,
	 * per recipient, at actual send time
	 * (Digest::substitute_interaction_summary(), called from
	 * QueueProcessor::send_one()).
	 *
	 * @return array{like: int, announce: int}
	 */
	public function personal_interaction_counts( int $artist_id, string $since ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only aggregate, one query per artist recipient at send time; small per-artist interaction volume.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.activity_type, COUNT(*) AS c
				 FROM {$wpdb->prefix}agnosis_interactions i
				 INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id
				 WHERE p.post_author = %d AND i.received_at > %s
				 GROUP BY i.activity_type",
				$artist_id,
				$since
			)
		);

		$counts = [ 'like' => 0, 'announce' => 0 ];
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->activity_type ] ) ) {
				$counts[ $row->activity_type ] = (int) $row->c;
			}
		}

		return $counts;
	}

	// -------------------------------------------------------------------------
	// On-site likes (interaction-surface roadmap, Phase 3, WP2, 2026-07-27)
	// -------------------------------------------------------------------------

	/** REST `permission_callback` for the likes toggle routes — coarse per-IP gate, same convention as every other public-write route in this codebase. */
	public function rate_limit_like(): bool|WP_Error {
		return RateLimiter::check( 'agnosis_like_toggle', self::LIKE_RATE_LIMIT, self::LIKE_RATE_WINDOW );
	}

	/**
	 * Which identity a same-origin, unauthenticated "like" is recorded under
	 * (§7 Q5). A logged-in artist's ordinary front-end/wp-admin session (not
	 * to be confused with the no-login rule for artist-facing ACTION LINKS —
	 * that's a different thing) likes under their own real actor URL, so
	 * their on-site like and a fediverse Like of the same artwork by the same
	 * person naturally share one actor_id. Every other visitor gets a
	 * same-day rotating salted hash of IP+UA: dedups repeat likes from the
	 * same visitor within one day, stores nothing identifiable, and
	 * deliberately does NOT support unliking after the salt rotates —
	 * rotate_like_salt() overwriting the option with nothing retained is the
	 * whole point of the rotation; a low-stakes, no-login feature accepts
	 * that trade rather than retaining anything to avoid it.
	 *
	 * Public (WP3, interaction-surface roadmap, Phase 3): the newsletter
	 * gateway (Newsletter\InteractionGateway) reuses this exact same
	 * resolution for a PUBLIC-newsletter subscriber's like click — they have
	 * no actor either, so their click is identified exactly like any other
	 * anonymous on-site visitor, resolved fresh from whatever request is
	 * actually making the click, not from anything encoded in the emailed
	 * link's token.
	 */
	public function like_identity(): string {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( in_array( 'agnosis_artist', (array) $user->roles, true ) ) {
				return $this->identity->actor_url_for( 'artist', $user->ID );
			}
		}

		$salt = (string) get_option( 'agnosis_like_salt', '' );
		$ip   = RateLimiter::client_ip();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HTTP_USER_AGENT is hashed below, never stored or output raw.
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		return 'anon:' . hash( 'sha256', $salt . '|' . $ip . '|' . $ua );
	}

	/** Does $actor_id already have a recorded 'like' row on $post_id? */
	private function has_liked( int $post_id, string $actor_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row existence check; $wpdb->prepare() parameterizes both values.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d AND activity_type = 'like' AND actor_id = %s LIMIT 1",
				$post_id,
				$actor_id
			)
		);

		return null !== $found;
	}

	/**
	 * Shared response shape for like_content()/unlike_content() — the current toggle state plus the refreshed like count.
	 *
	 * @return array{liked: bool, like: int}
	 */
	private function like_response( int $post_id, string $actor_id ): array {
		return [
			'liked' => $this->has_liked( $post_id, $actor_id ),
			'like'  => $this->interaction_counts( $post_id )['like'],
		];
	}

	/**
	 * Is $post_id a real agnosis_artwork? Shared guard for both like routes,
	 * and (WP3, public) for the newsletter gateway's confirm page, which
	 * needs the same 404 semantics before it ever renders a token-authenticated
	 * "Like this artwork?" confirm page.
	 */
	public function likeable_artwork( int $post_id ): \WP_Post|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'agnosis_artwork' !== $post->post_type ) {
			return new WP_Error( 'agnosis_like_not_found', __( 'No such artwork.', 'agnosis' ), [ 'status' => 404 ] );
		}
		return $post;
	}

	/**
	 * POST /agnosis/v1/content/{id}/likes — record a like from the current
	 * visitor/artist identity (like_identity()). Idempotent on repeat calls
	 * from the same identity, same $wpdb->replace() pattern record_interaction()
	 * already uses for a redelivered remote Like — a double-click just
	 * refreshes received_at rather than erroring or double-counting.
	 */
	public function like_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$post    = $this->likeable_artwork( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return new WP_REST_Response( $this->write_like( $post_id, $this->like_identity(), true ), 200 );
	}

	/**
	 * DELETE /agnosis/v1/content/{id}/likes — remove the current visitor/
	 * artist identity's own like, if any. A no-op (not an error) when that
	 * identity never had one — same "undo of something already undone is
	 * fine" tolerance undo_interaction() already has for a re-delivered
	 * Undo{Like}.
	 */
	public function unlike_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$post    = $this->likeable_artwork( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return new WP_REST_Response( $this->write_like( $post_id, $this->like_identity(), false ), 200 );
	}

	/**
	 * Record or remove a like under an EXPLICITLY given actor_id.
	 *
	 * Public — used by like_content()/unlike_content() above (which resolve
	 * $actor_id themselves via like_identity(), reading the CURRENT request)
	 * AND by the newsletter gateway (Newsletter\InteractionGateway, WP3),
	 * which already knows the acting identity from a verified emailed-link
	 * token and must NOT re-resolve it from the current request — the
	 * artist clicking that link is, by design (the no-login rule), not
	 * logged in, so like_identity()'s own is_user_logged_in() branch would
	 * never fire for them anyway.
	 *
	 * @return array{liked: bool, like: int}
	 */
	public function write_like( int $post_id, string $actor_id, bool $liked ): array {
		global $wpdb;

		if ( $liked ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->replace() parameterizes every value; small, per-artwork-scale table.
			$wpdb->replace(
				$wpdb->prefix . 'agnosis_interactions',
				[
					'post_id'       => $post_id,
					'activity_type' => 'like',
					'actor_id'      => $actor_id,
					'origin'        => 'local',
				],
				[ '%d', '%s', '%s', '%s' ]
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes every value; small, per-artwork-scale table.
			$wpdb->delete(
				$wpdb->prefix . 'agnosis_interactions',
				[ 'post_id' => $post_id, 'activity_type' => 'like', 'actor_id' => $actor_id ],
				[ '%d', '%s', '%s' ]
			);
		}

		return $this->like_response( $post_id, $actor_id );
	}

	/**
	 * agnosis_rotate_like_salt cron callback (daily) — overwrites the salt
	 * used by like_identity()'s anonymous-visitor hash. Unconditional
	 * update_option(), not add_option(): the whole point is that the
	 * previous value is gone once this runs, not preserved if already set
	 * (§7 Q5's explicit "no previous salt retained").
	 */
	public function rotate_like_salt(): void {
		update_option( 'agnosis_like_salt', wp_generate_password( 32, false ) );
	}

	// -------------------------------------------------------------------------
	// Boosts (interaction-surface roadmap, Phase 3, WP5, 2026-07-27)
	// -------------------------------------------------------------------------

	/** Does $actor_id already have a recorded 'announce' (boost) row on $post_id? */
	private function has_boosted( int $post_id, string $actor_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row existence check; $wpdb->prepare() parameterizes both values.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}agnosis_interactions WHERE post_id = %d AND activity_type = 'announce' AND actor_id = %s LIMIT 1",
				$post_id,
				$actor_id
			)
		);

		return null !== $found;
	}

	/**
	 * Record or remove a LOCAL boost under an explicit booster artist id
	 * (§4 Phase 3E). Unlike write_like() — which accepts a pre-resolved
	 * $actor_id string usable by either an artist OR an anonymous visitor —
	 * a boost is only ever performable by an Agnosis artist (§4 3E step 1: a
	 * boost is republication under a real identity, unlike a like, so
	 * anonymous boosting was never on the table). This takes the artist's
	 * own real WP user id directly and resolves their actor from it, rather
	 * than accepting an arbitrary caller-supplied actor string.
	 *
	 * Self-boost is deliberately permitted (§4 3E step 1: re-surfacing one's
	 * own older work to one's own followers is legitimate, and Mastodon
	 * itself allows it) — no special-case guard here for $artist_id being
	 * the artwork's own author.
	 *
	 * Reached only via the artist-newsletter gateway link today
	 * (Newsletter\InteractionGateway) — there is no on-site UI for this (the
	 * on-site interaction-counts boost count is deliberately not a button,
	 * unlike the like heart; Ulises never asked for one, and none is built
	 * here — see agnosis-audit/INTERACTION-SURFACE-ROADMAP.md WP5's own
	 * note on this being a known, disclosed gap rather than a silent one).
	 *
	 * @return array{boosted: bool, announce: int}
	 */
	public function write_boost( int $post_id, int $artist_id, bool $boosted ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return [ 'boosted' => false, 'announce' => 0 ];
		}

		$actor_id = $this->identity->actor_url_for( 'artist', $artist_id );

		global $wpdb;
		if ( $boosted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->replace() parameterizes every value; small, per-artwork-scale table.
			$wpdb->replace(
				$wpdb->prefix . 'agnosis_interactions',
				[
					'post_id'       => $post_id,
					'activity_type' => 'announce',
					'actor_id'      => $actor_id,
					'origin'        => 'local',
				],
				[ '%d', '%s', '%s', '%s' ]
			);
			$this->federate_boost( $post, $artist_id );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes every value; small, per-artwork-scale table.
			$wpdb->delete(
				$wpdb->prefix . 'agnosis_interactions',
				[ 'post_id' => $post_id, 'activity_type' => 'announce', 'actor_id' => $actor_id ],
				[ '%d', '%s', '%s' ]
			);
			$this->federate_unboost( $post, $artist_id );
		}

		return [
			'boosted'  => $this->has_boosted( $post_id, $actor_id ),
			'announce' => $this->interaction_counts( $post_id )['announce'],
		];
	}

	/**
	 * Federate `Announce` for a local boost (§4 Phase 3E step 2): `{ type:
	 * 'Announce', actor: <booster's actor>, object: <artwork's own object
	 * id>, to: [Public], cc: [ booster's followers, the artwork owner's
	 * actor ] }`, delivered to the booster's own followers (union with node
	 * followers, same as any other artist-attributed activity). The
	 * boosted artist (B) is local, so B's own side of this is simply the
	 * `agnosis_interactions` row write_boost() already made — no separate
	 * delivery to B is needed or attempted (mirrors the roadmap's own "B is
	 * local, so B's side is just a row").
	 *
	 * The activity id is deterministic (`<object_id>#announce-<artist_id>`),
	 * not time-based — at most one active boost can exist per (post,
	 * booster) pair (the interactions table's own unique key), so the same
	 * id naturally identifies "the current boost by this artist of this
	 * artwork" for federate_unboost()'s Undo to reference.
	 */
	private function federate_boost( \WP_Post $post, int $artist_id ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$this->delivery->deliver_to_followers( $this->boost_announce_activity( $post, $artist_id ), 'artist', $artist_id );
	}

	/** Federate `Undo{Announce}` for a local un-boost (§4 Phase 3E step 4) — the local mirror of the inbound path undo_interaction() already implements for a remote Undo{Announce}. */
	private function federate_unboost( \WP_Post $post, int $artist_id ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$announce = $this->boost_announce_activity( $post, $artist_id );

		$this->delivery->deliver_to_followers( [
			'@context' => Identity::CONTEXT,
			'type'     => 'Undo',
			'id'       => $announce['id'] . '-undo',
			'actor'    => $announce['actor'],
			'to'       => $announce['to'],
			'cc'       => $announce['cc'],
			'object'   => $announce,
		], 'artist', $artist_id );
	}

	/**
	 * Build the `Announce` activity shape shared by federate_boost() (sent
	 * as-is) and federate_unboost() (wrapped in `Undo`, so the two can
	 * never disagree about what's being un-boosted).
	 *
	 * @return array<string, mixed>
	 */
	private function boost_announce_activity( \WP_Post $post, int $artist_id ): array {
		$actor_url   = $this->identity->actor_url_for( 'artist', $artist_id );
		$owner       = $this->identity->owner_for_post( $post );
		$owner_actor = $this->identity->actor_url_for( $owner['type'], $owner['id'] );
		$object_id   = $this->identity->object_id_for( $post );

		return [
			'@context' => Identity::CONTEXT,
			'type'     => 'Announce',
			'id'       => $object_id . '#announce-' . $artist_id,
			'actor'    => $actor_url,
			'object'   => $object_id,
			'to'       => [ 'https://www.w3.org/ns/activitystreams#Public' ],
			'cc'       => array_values( array_unique( [ $actor_url . '/followers', $owner_actor ] ) ),
		];
	}
}
