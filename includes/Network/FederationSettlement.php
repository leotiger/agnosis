<?php
/**
 * The tag-settlement federation trigger — TAG-REDESIGN.md F3 (§6c).
 *
 * Ulises's model: federate once tags are approved and propagated, not at
 * publish time, because hashtag-timeline discovery happens at delivery —
 * a Note that arrives tagless and only gains hashtags via a later Update
 * largely misses the moment of maximum freshness. So the Create is gated on
 * "tag state settles": the post is published AND carries zero pending tag
 * proposals (`Publishing\TagGate::PROPOSAL_META`) AND zero pending medium
 * proposals (`_agnosis_medium_proposal` — soundness review §8 gap 1:
 * hashtags include the medium term, so a pending medium proposal would
 * federate a Note missing it, defeating the gate's whole purpose).
 *
 * With a mature vocabulary most submissions settle immediately at approval
 * (every candidate matched the existing vocabulary, nothing to wait for).
 * An artwork that proposed a genuinely new tag/medium settles later, when
 * its last proposal resolves — approve, reject, AND TTL auto-expiry all
 * count as "resolved" (`Admin\TagProposals`/`Admin\MediumProposals` each
 * fire a `*_proposal_resolved` action at every one of their three resolve
 * call sites: approve, reject, sweep_expired), so with the proposal TTL
 * bounded, settlement is doubly bounded. A safety valve
 * (`sweep_timed_out()`, the `agnosis_federation_tag_wait_sweep` cron)
 * force-settles any published artwork still waiting past
 * `agnosis_federation_tag_wait` hours (default 24) with whatever tags it
 * has — a settled state that never arrives must not mean a post that never
 * federates; the eventual proposal resolution then rides the ordinary
 * `Update` path (ActivityPub::broadcast_update(), unaffected by this class).
 *
 * State lives on the PRIMARY post only (`STATE_META`: absent — pre-F3 or
 * never entered this flow — → `pending-tags` → `federated`), following the
 * same "one post-meta key on the primary" idiom as
 * `Compat\LinguaForge::PENDING_FANOUT_META` already uses for tracking LF's
 * own fan-out completion. `DELIVERED_META` (a JSON array of post ids
 * already Created — primary and/or siblings) makes every delivery
 * idempotent against retries and re-fired hooks, keyed by post id rather
 * than language code since the two are equivalent (one language sibling is
 * exactly one post).
 *
 * Per-sibling readiness (§6c): once the primary settles, `federate()`
 * sweeps every ALREADY-EXISTING sibling (`linguaforge_get_translations()`)
 * that's published — the native sibling almost always predates settlement
 * when proposals were pending, and a fan-out sibling may too. A sibling
 * that doesn't exist yet, or arrives later via Lingua Forge's own
 * translation pipeline, is covered by `on_translation_complete()`, hooked
 * on `linguaforge_translation_complete` at a priority AFTER
 * `Compat\LinguaForge::sync_translated_terms()` (so the sibling's own terms
 * are already synced — real hashtags, not a placeholder — by the time this
 * runs): it federates that one sibling immediately if the primary has
 * already settled (checked via `STATE_META`, which the cron timeout path
 * also flips to `federated`, so a sibling arriving after a timeout is
 * covered by the exact same check). Both arrival orders are normal, so
 * both paths are first-class and converge on the same `DELIVERED_META`
 * idempotency (soundness review §8 gap 3).
 *
 * `ActivityPub::broadcast()` itself needed no change (§F2 already made it
 * fully post-id-agnostic — no primary-only guard) — this class simply
 * fires a new `agnosis_federation_settled` action per post id once it's
 * actually ready, and `Core\Plugin` hooks `broadcast()` to THAT action
 * instead of directly to `agnosis_post_published` (which now only drives
 * Lingua Forge's own language-meta/translation-scheduling listeners, not
 * federation at all).
 *
 * Only `agnosis_artwork` federates (unchanged scope) — `maybe_settle()`
 * silently no-ops for biography/event posts rather than writing federation
 * state that could never lead anywhere, even though tag/medium proposals
 * themselves span all three CPTs.
 *
 * @package Agnosis\Network
 */

declare(strict_types=1);

namespace Agnosis\Network;

use Agnosis\Core\Logger;
use Agnosis\Publishing\TagGate;

class FederationSettlement {

	/**
	 * Federation state on the PRIMARY post: absent (pre-F3 / never entered
	 * this flow) | self::STATE_PENDING | self::STATE_FEDERATED.
	 */
	public const STATE_META = '_agnosis_federation_state';

	/** When STATE_META was last set to STATE_PENDING — the safety-valve cron's clock. */
	public const PENDING_SINCE_META = '_agnosis_federation_pending_since';

	/** JSON array of post ids (primary and/or siblings) already Created — idempotency. */
	public const DELIVERED_META = '_agnosis_federation_delivered';

	public const STATE_PENDING   = 'pending-tags';
	public const STATE_FEDERATED = 'federated';

	// -------------------------------------------------------------------------
	// Settlement check — called at approval and after every proposal resolve.
	// -------------------------------------------------------------------------

	/**
	 * Advance a primary post's federation state: settle-and-federate if its
	 * tags/medium are gate-clear, otherwise (re)confirm it's waiting.
	 * Idempotent — a no-op once already `federated`, safe to call from
	 * multiple triggers for the same post (approval, several proposal
	 * resolutions, a stray re-fire).
	 *
	 * Hooked directly as an action callback (`agnosis_tag_proposal_resolved`,
	 * `agnosis_medium_proposal_resolved`) as well as called explicitly from
	 * `ReviewEndpoints::finalize_publish()` — both pass a plain `$post_id`.
	 */
	public function maybe_settle( int $post_id ): void {
		if ( self::STATE_FEDERATED === (string) get_post_meta( $post_id, self::STATE_META, true ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'agnosis_artwork' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! self::is_tag_settled( $post_id ) ) {
			if ( self::STATE_PENDING !== (string) get_post_meta( $post_id, self::STATE_META, true ) ) {
				update_post_meta( $post_id, self::STATE_META, self::STATE_PENDING );
				update_post_meta( $post_id, self::PENDING_SINCE_META, time() );
			}
			return;
		}

		$this->federate( $post_id );
	}

	/**
	 * Zero pending tag proposals AND zero pending medium proposals (§6c;
	 * soundness review §8 gap 1). Post-type-agnostic by construction — the
	 * proposal meta keys themselves only ever exist on a post that actually
	 * carries one, so this is safe to call for any post id, though the only
	 * real caller (`maybe_settle()`) already restricts to `agnosis_artwork`.
	 */
	public static function is_tag_settled( int $post_id ): bool {
		if ( ! empty( get_post_meta( $post_id, TagGate::PROPOSAL_META, false ) ) ) {
			return false;
		}

		if ( '' !== (string) get_post_meta( $post_id, '_agnosis_medium_proposal', true ) ) {
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Federate — primary Create + sweep of already-existing ready siblings.
	// -------------------------------------------------------------------------

	/**
	 * Mark the primary settled, federate it, and federate every already-
	 * published sibling. Called once a post is confirmed tag-settled
	 * (`maybe_settle()`) or force-settled by the timeout sweep
	 * (`sweep_timed_out()`) — both paths converge here.
	 */
	private function federate( int $post_id ): void {
		update_post_meta( $post_id, self::STATE_META, self::STATE_FEDERATED );
		delete_post_meta( $post_id, self::PENDING_SINCE_META );

		$this->deliver_if_new( $post_id, $post_id );

		if ( ! function_exists( 'linguaforge_get_translations' ) ) {
			return;
		}

		foreach ( linguaforge_get_translations( $post_id ) as $sibling_id ) {
			$sibling_id = (int) $sibling_id;
			$sibling    = get_post( $sibling_id );
			if ( $sibling instanceof \WP_Post && 'publish' === $sibling->post_status ) {
				$this->deliver_if_new( $post_id, $sibling_id );
			}
		}
	}

	/**
	 * `linguaforge_translation_complete` listener — covers a sibling that
	 * doesn't exist yet at settlement time (created/translated afterward).
	 * Registered at a priority AFTER `Compat\LinguaForge::sync_translated_terms()`
	 * (same action) so the sibling's own tag/medium terms are already synced
	 * — real hashtags — by the time this runs.
	 *
	 * No-ops when the primary hasn't settled (or timed out) yet — the
	 * settlement sweep above, or a future call to this same listener once
	 * the primary DOES settle, will pick this sibling up then. This is the
	 * "arrives after settlement" half of soundness review §8 gap 3; the
	 * "already exists before settlement" half is `federate()`'s own sweep.
	 *
	 * @param int    $translated_id The newly-translated sibling post id.
	 * @param int    $source_id     The primary (source) post id.
	 * @param string $target_lang   Unused here — kept for hook signature parity.
	 */
	public function on_translation_complete( int $translated_id, int $source_id, string $target_lang ): void {
		unset( $target_lang );

		if ( self::STATE_FEDERATED !== (string) get_post_meta( $source_id, self::STATE_META, true ) ) {
			return;
		}

		$sibling = get_post( $translated_id );
		if ( $sibling instanceof \WP_Post && 'publish' === $sibling->post_status ) {
			$this->deliver_if_new( $source_id, $translated_id );
		}
	}

	/**
	 * Fire `agnosis_federation_settled` for $object_id (the primary or one
	 * sibling) exactly once per primary's DELIVERED_META — every other
	 * caller in this class already checked publish status; this is purely
	 * the idempotency gate, PLUS (TAG-REDESIGN.md F4) the rollout-valve
	 * check for a sibling specifically.
	 *
	 * `agnosis_federate_languages` (`all` | `primary-only`, default
	 * `primary-only`) is read live, at the moment of THIS delivery decision
	 * — never cached or snapshotted — so flipping the setting takes effect
	 * for the very next sibling that becomes ready, with no separate
	 * "apply the new setting" step. The primary itself is never gated by
	 * this option; only $object_id !== $primary_id (a sibling) is affected.
	 *
	 * This can never retroactively mass-deliver an existing backlog of
	 * already-existing siblings the moment the setting flips to `all`,
	 * because nothing calls this method (or federate()'s sweep it lives
	 * in) proactively across every existing post when the option changes —
	 * it only ever runs as a SIDE EFFECT of a real event: a primary
	 * settling for the first time (federate()), a sibling's own translation
	 * completing (on_translation_complete()), or the timeout sweep. An
	 * artwork whose primary already reached STATE_FEDERATED before the flip
	 * never has federate() run for it again (maybe_settle()'s own top
	 * guard), so its pre-existing, never-delivered siblings stay exactly
	 * that — never delivered — unless a genuinely new translation event
	 * happens for one of them afterward, at which point on_translation_complete()
	 * treats it like any other fresh sibling arrival under the CURRENT
	 * setting. That is new content, not backlog.
	 */
	private function deliver_if_new( int $primary_id, int $object_id ): void {
		if ( $object_id !== $primary_id && 'all' !== (string) get_option( 'agnosis_federate_languages', 'primary-only' ) ) {
			return;
		}

		$delivered = self::delivered_ids( $primary_id );
		if ( in_array( $object_id, $delivered, true ) ) {
			return;
		}

		$delivered[] = $object_id;
		update_post_meta( $primary_id, self::DELIVERED_META, wp_json_encode( $delivered ) );

		/**
		 * Fires once a post (primary or language sibling) is ready to
		 * federate its initial Create — TAG-REDESIGN.md F3. ActivityPub::
		 * broadcast() is the sole listener (Core\Plugin wiring).
		 *
		 * @param int $object_id The post id ready to federate.
		 */
		do_action( 'agnosis_federation_settled', $object_id );
	}

	/** @return int[] */
	private static function delivered_ids( int $primary_id ): array {
		$raw = (string) get_post_meta( $primary_id, self::DELIVERED_META, true );
		$ids = json_decode( $raw, true );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	// -------------------------------------------------------------------------
	// Safety-valve cron — agnosis_federation_tag_wait_sweep
	// -------------------------------------------------------------------------

	/**
	 * Idempotent scheduler — same `init`-hooked, `wp_next_scheduled()`-guarded
	 * pattern as `Admin\TagProposals::schedule_ttl_sweep()`. Hourly (rather
	 * than the proposal sweeps' daily) since `agnosis_federation_tag_wait`'s
	 * own unit is hours, not days — a daily cadence could let a post wait up
	 * to 47 hours against a 24-hour setting instead of the ~25 an hourly
	 * cadence bounds it to.
	 */
	public function schedule_fallback_sweep(): void {
		if ( ! wp_next_scheduled( 'agnosis_federation_tag_wait_sweep' ) ) {
			wp_schedule_event( time(), 'hourly', 'agnosis_federation_tag_wait_sweep' );
		}
	}

	/**
	 * Force-settle any published artwork still `pending-tags` past
	 * `agnosis_federation_tag_wait` hours (default 24) — federates with
	 * whatever tags/medium it currently has; a proposal that resolves later
	 * rides the ordinary Update path, unaffected by this class.
	 */
	public function sweep_timed_out(): void {
		$wait_hours = max( 1, (int) get_option( 'agnosis_federation_tag_wait', 24 ) );
		$cutoff     = time() - ( $wait_hours * HOUR_IN_SECONDS );

		$post_ids = get_posts( [
			'post_type'      => 'agnosis_artwork',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::STATE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- hourly cron, not a front-end query; bounded to posts actually mid-settlement.
			'meta_value'     => self::STATE_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );

		$swept = 0;
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$since   = (int) get_post_meta( $post_id, self::PENDING_SINCE_META, true );

			if ( $since > $cutoff ) {
				continue; // Not timed out yet.
			}

			$this->federate( $post_id );
			++$swept;

			Logger::info(
				sprintf(
					'FederationSettlement::sweep_timed_out(): post #%1$d force-settled after waiting past %2$d hour(s) with unresolved tag/medium proposals still pending.',
					$post_id,
					$wait_hours
				),
				'activitypub'
			);
		}

		if ( $swept > 0 ) {
			Logger::info(
				sprintf( 'FederationSettlement::sweep_timed_out(): %d post(s) force-settled.', $swept ),
				'activitypub'
			);
		}
	}
}
