<?php
/**
 * The rhizome — this node's relationships with other Agnosis nodes and relays.
 *
 * Third unit extracted from Network\ActivityPub (sixteenth audit, Q-2, WP3 —
 * agnosis-audit/ACTIVITYPUB-SPLIT-ROADMAP.md). Two features that arrived
 * separately but are one concern, and are now filed as one:
 *
 * - **RN3 inbound relay-boost** (RHIZOME-NETWORK-ROADMAP.md §3/§5/§8): a trusted
 *   peer Announces something that is *not* this node's content, and this node
 *   amplifies it to its own followers. Trust matching, double idempotency, the
 *   `agnosis_rhizome_relay_log` and its retention sweep.
 * - **WP8 outbound relay subscription** (INTERACTION-SURFACE-ROADMAP.md Phase 3):
 *   sending a `Follow`/`Undo{Follow}` to a relay, so this node can be discovered
 *   by instances it has never met.
 *
 * Both are node-level and never per-artist — every activity built here is signed
 * as the node's own actor. That is what separates this class from `Interactions`
 * (WP4), where a boost belongs to the artist who made it.
 *
 * **Relaying amplifies reach; it never rewrites content.** `relay_announce_activity()`
 * forwards the peer's `object` field byte-for-byte and re-attributes only the
 * wrapper. Nothing in this class re-hosts, edits, or translates a peer's work.
 *
 * Depends on Identity (node actor URL, and resolving whether an Announce's object
 * is already local) and Delivery (inbox resolution and the send/queue path) —
 * both injected, never constructed here, so the layering stays acyclic by
 * construction:
 *
 *     Identity -> Delivery -> **Rhizome** / Interactions -> Replies -> Serialization
 *
 * `Rhizome` sits beside `Interactions` rather than above or below it: they share
 * a layer, they do not call each other, and the one primitive they both need
 * (`resolve_local_post_id()`) was moved *down* into Identity at WP3 rather than
 * routed sideways through the orchestrator.
 *
 * Behaviour is unchanged. Every method body is the one that stood in
 * ActivityPub.php; only `relay_trusted_announce()` widened to public, because the
 * orchestrator's `inbox()` dispatches to it from what is now a sibling class.
 *
 * @package Agnosis\Network\Federation
 */

declare(strict_types=1);

namespace Agnosis\Network\Federation;

class Rhizome {

	public function __construct(
		private Identity $identity,
		private Delivery $delivery
	) {}

	/**
	 * A trusted peer's Announce of something NOT already local to this node
	 * (§3) — the actual missing "relay" half §2 identified: previously
	 * discarded entirely once record_interaction() (above) found
	 * resolve_local_post_id() came back empty for it. Scoped to Announce
	 * only (§3, §7 non-goals) and always enqueued rather than attempted live
	 * (§5, ANSWERED) — a trusted peer's boost isn't this node's own content
	 * and isn't time-critical the way a live visitor action is.
	 *
	 * Deliberately a no-op when: ActivityPub federation itself is off; the
	 * activity carries no actor or resolvable object; the object DOES
	 * resolve locally (already fully handled by record_interaction() as an
	 * ordinary local interaction — relaying it again would just echo this
	 * node's own content back at its own followers); or the signing actor
	 * doesn't match any `trusted`, non-`blocked` `agnosis_nodes` row.
	 *
	 * Persists a record of what it relayed to `agnosis_rhizome_relay_log`
	 * (RHIZOME-NETWORK-ROADMAP.md §11b/§12, RN3b, 2026-07-30) — one row per
	 * recognized relay event, independent of whether this node currently
	 * has any local followers to actually receive it. RN3's own original
	 * §8 scope never called for this; §11b flagged it afterward as a
	 * required (not deferrable) follow-up, and NL2 (Digest::
	 * rhizome_activity_summary()) now reads it.
	 *
	 * Two idempotency checks, not one, and deliberately in this order
	 * (§13 F3, 2026-07-30 — the log-based one is the fix; the queue-based
	 * one was originally the only guard and is far weaker than §8 claimed):
	 * relay_already_queued() catches a redelivery that arrives while the
	 * previous relay's per-follower rows are still sitting unsent, and
	 * log_relay_activity()'s own UNIQUE relay_activity_id catches one that
	 * arrives after they've been delivered and deleted (dispatch_queue()
	 * removes a row on success, so the queue-based window is only as long
	 * as the next agnosis_ap_retry_deliveries tick — minutes, where an
	 * inbound Announce can legitimately be redelivered for days). The log
	 * check has to come SECOND because it writes: only reach it once the
	 * cheap read-only check has already passed.
	 *
	 * @param array<string, mixed> $body Raw Announce activity payload.
	 */
	public function relay_trusted_announce( array $body ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$actor_id = is_string( $body['actor'] ?? null ) ? $body['actor'] : '';
		if ( '' === $actor_id ) {
			return;
		}

		$object     = $body['object'] ?? '';
		$object_url = is_array( $object )
			? ( is_string( $object['id'] ?? null ) ? $object['id'] : '' )
			: ( is_string( $object ) ? $object : '' );

		if ( '' === $object_url || $this->identity->resolve_local_post_id( $object_url ) > 0 ) {
			return;
		}

		$peer = $this->matching_trusted_peer( $actor_id );
		if ( null === $peer ) {
			return;
		}

		$relay = $this->relay_announce_activity( $actor_id, $object );

		if ( $this->relay_already_queued( $relay['id'] ) ) {
			return;
		}

		if ( ! $this->log_relay_activity( $peer, $actor_id, $object_url, $relay['id'] ) ) {
			// Duplicate relay_activity_id — this exact peer boost was already
			// relayed and its queue rows have since been delivered and
			// cleared. Nothing more to do; re-queueing here is precisely the
			// double-delivery this check exists to prevent.
			return;
		}

		$this->enqueue_relay_to_followers( $relay );
	}

	/**
	 * The `trusted`, non-`blocked` agnosis_nodes row governing $actor_id, or
	 * null if none — checked by exact `actor_id` (`trust_scope = 'actor'`)
	 * or by host match against the row's own site `url` (`trust_scope =
	 * 'domain'`), per that row's own per-row choice (§4, ANSWERED). A plain
	 * `WHERE status = 'trusted'` full scan, not indexed further — this table
	 * is explicitly single-digit-peer/node-scale throughout this roadmap
	 * (§2, §5), the same assumption `deliver_to_followers()`'s own
	 * `agnosis_followers` queries already make.
	 *
	 * @return object{id: string, url: string, trust_scope: string, actor_id: string|null}|null
	 */
	private function matching_trusted_peer( string $actor_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-digit-peer-scale table (RHIZOME-NETWORK-ROADMAP.md §5); no caching layer for it exists.
		$peers = $wpdb->get_results( "SELECT id, url, trust_scope, actor_id FROM {$wpdb->prefix}agnosis_nodes WHERE status = 'trusted'" );

		$actor_host = (string) wp_parse_url( $actor_id, PHP_URL_HOST );

		foreach ( $peers as $peer ) {
			if ( 'actor' === $peer->trust_scope ) {
				if ( null !== $peer->actor_id && $peer->actor_id === $actor_id ) {
					return $peer;
				}
				continue;
			}

			// 'domain' scope.
			if ( '' !== $actor_host && $actor_host === (string) wp_parse_url( $peer->url, PHP_URL_HOST ) ) {
				return $peer;
			}
		}

		return null;
	}

	/**
	 * Build the re-wrapped Announce this node sends to its OWN followers
	 * when relaying a trusted peer's boost — same shape federate_boost()
	 * builds for a local artist's boost, attributed to this NODE's own
	 * actor instead (a node-level relay-boost, not any one artist's).
	 * `object` is forwarded exactly as the peer's own Announce carried it —
	 * §3's own "Vital clarification" applies here too: relaying amplifies
	 * *reach*, it never rewrites, re-hosts, or otherwise touches the
	 * content itself.
	 *
	 * `id` is deterministic — derived from the peer actor plus the object —
	 * so a redelivered/retried inbound Announce always resolves to the SAME
	 * relay activity id, which relay_already_queued() depends on for
	 * idempotency.
	 *
	 * @param array<string, mixed>|string $announce_object The peer's own Announce's `object` field, forwarded as-is.
	 * @return array<string, mixed>
	 */
	private function relay_announce_activity( string $peer_actor_id, $announce_object ): array {
		$node_actor = $this->identity->actor_url_for( 'node', 0 );
		$object_key = is_string( $announce_object )
			? $announce_object
			: ( is_string( $announce_object['id'] ?? null ) ? $announce_object['id'] : (string) wp_json_encode( $announce_object ) );

		return [
			'@context' => Identity::CONTEXT,
			'type'     => 'Announce',
			'id'       => $node_actor . '#relay-' . md5( $peer_actor_id . '|' . $object_key ),
			'actor'    => $node_actor,
			'object'   => $announce_object,
			'to'       => [ 'https://www.w3.org/ns/activitystreams#Public' ],
			'cc'       => array_values( array_unique( [ $node_actor . '/followers', $peer_actor_id ] ) ),
		];
	}

	/**
	 * Whether a relay Announce with this exact `id` is CURRENTLY SITTING in
	 * the delivery queue — cheap `LIKE` match against
	 * `agnosis_ap_delivery_queue.activity_json` (no dedicated index;
	 * acceptable at the single-digit-peer scale this table is already sized
	 * for, same as matching_trusted_peer() above).
	 *
	 * Only half of RN3's idempotency, and the shorter-lived half: a queue
	 * row is DELETED once its delivery succeeds (see dispatch_queue()), so
	 * this check stops seeing a relay a few minutes after it went out. It
	 * exists to stop a redelivery arriving mid-flight from doubling the
	 * pending per-follower rows; `log_relay_activity()`'s own UNIQUE
	 * `relay_activity_id` is what covers everything after that (§13 F3).
	 */
	private function relay_already_queued( string $relay_id ): bool {
		global $wpdb;

		// wp_json_encode() escapes forward slashes by default (no JSON_UNESCAPED_SLASHES
		// flag anywhere in this class) — a raw "id":"http://..." needle built from the
		// literal $relay_id would never match the stored "id":"http:\/\/..." bytes.
		// Encoding the id the same way it was encoded when written keeps this in sync.
		$needle = '"id":' . (string) wp_json_encode( $relay_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-digit-peer-scale table; LIKE is checking for one specific already-built activity id, not a user-facing search.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}agnosis_ap_delivery_queue WHERE owner_type = 'node' AND owner_id = 0 AND activity_json LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( $needle ) . '%'
		) );

		return null !== $existing;
	}

	/**
	 * Write one `agnosis_ap_delivery_queue` row per this node's own
	 * follower, for a relay Announce — always queued, never attempted live
	 * (§5, ANSWERED), unlike every other delivery in this class (deliver(),
	 * via deliver_to_followers()), which tries live delivery first and only
	 * falls back to the queue on failure. `next_attempt_at` is left at the
	 * column's own `DEFAULT CURRENT_TIMESTAMP` rather than
	 * `RETRY_INTERVALS[0]`'s delayed backoff — that backoff is FAILURE-retry
	 * timing, not appropriate for a delivery that was never attempted live
	 * at all — so the very next `agnosis_ap_retry_deliveries` cron tick
	 * picks these rows straight up.
	 *
	 * @param array<string, mixed> $activity
	 */
	private function enqueue_relay_to_followers( array $activity ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small, node-scale table (audit §3g note iii); no caching layer for it exists.
		$inbox_urls = $wpdb->get_col( "SELECT inbox_url FROM {$wpdb->prefix}agnosis_followers WHERE owner_type = 'node' AND owner_id = 0 ORDER BY id ASC" );

		if ( empty( $inbox_urls ) ) {
			return;
		}

		$body = wp_json_encode( $activity );
		if ( false === $body ) {
			return;
		}

		foreach ( $inbox_urls as $inbox_url ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() parameterizes every value.
			$wpdb->insert(
				$wpdb->prefix . 'agnosis_ap_delivery_queue',
				[
					'inbox_url'     => $inbox_url,
					'activity_type' => 'Announce',
					'activity_json' => $body,
					'owner_type'    => 'node',
					'owner_id'      => 0,
				],
				[ '%s', '%s', '%s', '%s', '%d' ]
			);
		}
	}

	/**
	 * Writes one `agnosis_rhizome_relay_log` row for a recognized relay
	 * event (RN3b, RHIZOME-NETWORK-ROADMAP.md §11b/§12) — called once trust
	 * and redelivery-idempotency have both already passed, regardless of
	 * whether this node currently has any local followers to actually fan
	 * the relay out to; this table answers "what happened on the rhizome,"
	 * not "what got delivered" (agnosis_ap_delivery_queue already answers
	 * that, per-inbox).
	 *
	 * `peer_url` is copied here rather than only referenced by
	 * `peer_node_id`, so a later admin removal of that peer
	 * (`RhizomeManager::handle_remove()`) doesn't orphan this row's own
	 * historical readability.
	 *
	 * `relay_activity_id` is the DURABLE half of RN3's idempotency, and this
	 * method's return value is what makes it load-bearing (§13 F3,
	 * 2026-07-30 — previously the return was discarded and the caller
	 * enqueued regardless, so the UNIQUE key rejected the log row and
	 * nothing else, leaving the whole guarantee resting on
	 * `relay_already_queued()`'s minutes-long window against a queue that
	 * deletes its rows on successful delivery).
	 *
	 * Checked with an explicit SELECT rather than by letting the UNIQUE key
	 * reject the INSERT, even though the latter is one query fewer. A
	 * redelivered Announce is ORDINARY, expected ActivityPub traffic, and a
	 * rejected insert makes `wpdb::print_error()` emit a "WordPress database
	 * error: Duplicate entry …" block — visible output on any site running
	 * `WP_DEBUG_DISPLAY`, and noise in the error log everywhere else. Routine
	 * behavior must not look like a fault. The UNIQUE key stays as the
	 * backstop for the genuine race (two inbox requests for the same
	 * Announce in flight at once, where both SELECTs miss), and the insert
	 * is wrapped in `suppress_errors()` so that rare loser also fails
	 * quietly — `$wpdb->insert()` returns false on a key violation rather
	 * than throwing, which is exactly the answer this method wants anyway.
	 *
	 * @param object{id: string, url: string, trust_scope: string, actor_id: string|null} $peer The trusted agnosis_nodes row that governed this relay.
	 * @return bool True when this is the first time this relay activity has been logged; false when it's a duplicate (or the write failed).
	 */
	private function log_relay_activity( object $peer, string $announcing_actor_id, string $object_url, string $relay_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed lookup on a UNIQUE key; single-digit-peer-scale table (RHIZOME-NETWORK-ROADMAP.md §5), no caching layer for it exists.
		$already = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}agnosis_rhizome_relay_log WHERE relay_activity_id = %s LIMIT 1",
			$relay_id
		) );

		if ( null !== $already ) {
			return false;
		}

		$was_suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() parameterizes every value; single-digit-peer-scale table (RHIZOME-NETWORK-ROADMAP.md §5).
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'agnosis_rhizome_relay_log',
			[
				'peer_node_id'        => (int) $peer->id,
				'peer_url'            => $peer->url,
				'announcing_actor_id' => $announcing_actor_id,
				'object_url'          => $object_url,
				'relay_activity_id'   => $relay_id,
			],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);

		$wpdb->suppress_errors( $was_suppressed );

		return false !== $inserted;
	}

	/**
	 * Retention sweep for `agnosis_rhizome_relay_log` — sixteenth audit, G-4
	 * (2026-07-31). Hooked to the existing daily `agnosis_cleanup_inbox`, the
	 * same cron `Logger::prune()` and `ContactForm::prune_old_messages()`
	 * already run on, rather than adding a fifteenth cron hook for one DELETE.
	 *
	 * RN3b's log records the *announcing remote actor's id* on every relayed
	 * peer boost — a direct identifier of a natural person in most cases — and
	 * shipped in 0.9.65 with no pruning, no cap and no retention statement,
	 * the only content- or identity-bearing table in the plugin without one
	 * (`agnosis_log` is pruned here, `agnosis_queue` at 7 days, contact
	 * messages at 90). It is also unbounded operationally: the roadmap sized
	 * the rhizome as single-digit-peer-scale, which bounds the number of
	 * PEERS, not the number of relays a busy peer generates.
	 *
	 * The default is deliberately 90 days rather than something tighter: the
	 * only reader is `Newsletter\Digest::rhizome_activity_summary()`, which
	 * only ever queries `relayed_at > $since` for one digest window, so 90
	 * days covers the default 30-day artist-digest cadence three times over
	 * and pruning past it costs the digest nothing.
	 */
	public function prune_relay_log(): void {
		global $wpdb;

		$days   = max( 1, (int) get_option( 'agnosis_relay_log_retention_days', 90 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily cron housekeeping on a custom table, not a per-request query.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}agnosis_rhizome_relay_log WHERE relayed_at < %s",
			$cutoff
		) );
	}

	/**
	 * Build the `Follow` activity a relay subscription is expressed as —
	 * shared by follow_relay() (sent as-is) and unfollow_relay() (wrapped
	 * in `Undo`, so the two can never disagree about which follow relationship
	 * is being ended).
	 *
	 * Signed as the NODE's own actor, never an artist's — relays are
	 * node-level and admin-only (§7 Q8), the one genuinely operator-facing
	 * decision in this roadmap. The activity id is deterministic (derived
	 * from the relay's own actor URL, not time-based), so it never needs to
	 * be stored anywhere separately — same reasoning as WP5's
	 * boost_announce_activity()'s own id scheme, and for the same result: at
	 * most one active Follow can exist per relay, so the same id always
	 * identifies "our current subscription to this relay".
	 *
	 * @return array<string, mixed>
	 */
	private function relay_follow_activity( string $relay_actor_url ): array {
		$node_actor = $this->identity->actor_url_for( 'node', 0 );

		return [
			'@context' => Identity::CONTEXT,
			'type'     => 'Follow',
			'id'       => $node_actor . '#follow-relay-' . md5( $relay_actor_url ),
			'actor'    => $node_actor,
			'object'   => $relay_actor_url,
		];
	}

	/**
	 * Subscribe to a relay (WP8) — Agnosis could already receive an inbound
	 * Follow (handle_follow()) but had no code path to send one; this is
	 * that path. A relay typically re-broadcasts every Follow it accepts to
	 * every OTHER subscriber, which is the entire point: it is the
	 * cheapest real discoverability mechanism that works with the
	 * fediverse exactly as deployed today, no FASP or new protocol needed.
	 *
	 * Best-effort and silent on failure to resolve the relay's own inbox —
	 * same tolerance federate_artist_reply()'s own resolve_inbox() call
	 * already has; an admin adding a malformed or unreachable relay URL
	 * gets no fatal error, just no subscription.
	 */
	public function follow_relay( string $relay_actor_url ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$inbox = $this->delivery->resolve_inbox( $relay_actor_url );
		if ( null === $inbox ) {
			return;
		}

		$this->delivery->deliver( $inbox, $this->relay_follow_activity( $relay_actor_url ), 'node', 0 );
	}

	/**
	 * Unsubscribe from a relay (WP8) — sent when an admin disables or
	 * removes a previously-added relay, so leaving is clean rather than
	 * just going quiet on our own end while the relay keeps us subscribed
	 * indefinitely.
	 */
	public function unfollow_relay( string $relay_actor_url ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$inbox = $this->delivery->resolve_inbox( $relay_actor_url );
		if ( null === $inbox ) {
			return;
		}

		$follow = $this->relay_follow_activity( $relay_actor_url );

		$this->delivery->deliver( $inbox, [
			'@context' => Identity::CONTEXT,
			'type'     => 'Undo',
			'id'       => $follow['id'] . '-undo',
			'actor'    => $follow['actor'],
			'object'   => $follow,
		], 'node', 0 );
	}
}
