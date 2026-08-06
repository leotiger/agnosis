<?php
/**
 * Followers — who follows this node or one of its artists, and the button that
 * lets them start.
 *
 * Eighth unit, added at WP7 (sixteenth audit, Q-2 —
 * agnosis-audit/ACTIVITYPUB-SPLIT-ROADMAP.md §5i). The six-unit plan never named
 * it, and the reason is instructive: the follower relationship had no single
 * home to be moved *out of*. Its pieces were scattered across the orchestrator\'s
 * inbox dispatch (three separate `$wpdb` writes to `agnosis_followers`), a REST
 * endpoint, and a Gutenberg block — so nothing looked like a unit until five
 * other units had been removed and it was what remained.
 *
 * It owns the `agnosis_followers` table\'s whole lifecycle:
 *
 * - **In** — `accept_follow()` records an inbound `Follow` and returns the
 *   `Accept`, signed as whichever local actor was actually followed.
 * - **Out** — `undo_follow()` for a targeted `Undo{Follow}`, `forget_actor()`
 *   for a remote account that has self-deleted and must be dropped everywhere.
 * - **Read** — `followers()` serves the AS2 `OrderedCollection`, of actor IDs
 *   rather than inbox URLs (audit §2a): a consumer dereferencing an item expects
 *   an actor document, and an inbox URL only answers signed POSTs.
 * - **UI** — the `agnosis/follow-overlay` block: a Follow button and the
 *   remote-follow popover that takes a visitor\'s own instance domain.
 *
 * `Delivery` still reads `inbox_url` from this table directly, and that is
 * deliberate: fanning an activity out to followers is transport, it sits *below*
 * this class, and routing it back up through here would invert the layering.
 * This class owns who the followers *are*; Delivery owns getting bytes to them.
 *
 * The orchestrator keeps `handle_follow()`/`handle_undo()`/`handle_delete()` as
 * inbox dispatch — parsing the activity and deciding which unit it belongs to is
 * exactly the orchestrator\'s job — but their bodies now hand off here rather
 * than writing the table themselves.
 *
 * Depends on Identity and Delivery, both injected; sits on the same layer as
 * `Interactions` and `Rhizome` and calls neither:
 *
 *     Identity / Language -> Delivery -> Interactions / Rhizome / **Follows** -> Replies -> Serialization
 *
 * @package Agnosis\Network\Federation
 */

declare(strict_types=1);

namespace Agnosis\Network\Federation;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Follows {

	public function __construct(
		private Identity $identity,
		private Delivery $delivery
	) {}

	/**
	 * Accept an inbound `Follow` — record the follower and send the `Accept`.
	 *
	 * Extracted verbatim from the orchestrator's `handle_follow()` at WP7; that
	 * method is now the dispatch half only (parse the activity, hand it here,
	 * return 200). A Follow naming no recognizable local actor still defaults to
	 * the node rather than being dropped: AS2 requires `object`, but some
	 * senders are loose about it, and this matches the pre-§3h behaviour.
	 *
	 * @param array<string, mixed> $body Raw Follow activity.
	 */
	public function accept_follow( array $body ): void {
		global $wpdb;

		$follower_id    = $body['actor'] ?? '';
		$target         = is_string( $body['object'] ?? null ) ? $body['object'] : '';
		$owner          = $this->identity->resolve_local_owner( $target ) ?? [ 'type' => 'node', 'id' => 0 ];
		$follower_inbox = $this->delivery->resolve_inbox( $follower_id );

		if ( ! $follower_inbox ) {
			return;
		}

		// Upsert by (owner_type, owner_id, actor_id) — audit §3g note iii's
		// array-key-upsert-into-an-option is now a table UNIQUE KEY; audit
		// §3h added the owner columns so the same remote actor can follow
		// both the node and one or more individual artists independently.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->replace() parameterizes every value; small, node-scale table.
		$wpdb->replace(
			$wpdb->prefix . 'agnosis_followers',
			[ 'owner_type' => $owner['type'], 'owner_id' => $owner['id'], 'actor_id' => $follower_id, 'inbox_url' => $follower_inbox ],
			[ '%s', '%d', '%s', '%s' ]
		);

		$actor_url = $this->identity->actor_url_for( $owner['type'], $owner['id'] );

		// Send Accept — signed by (and attributed to) whichever local
		// actor was actually followed, not always the node.
		$this->delivery->deliver( $follower_inbox, [
			'@context' => Identity::CONTEXT,
			'type'     => 'Accept',
			'id'       => $actor_url . '#accept-' . uniqid(),
			'actor'    => $actor_url,
			'object'   => $body,
		], $owner['type'], $owner['id'] );
	}

	/**
	 * Drop one follower row for an inbound `Undo{Follow}` — a targeted unfollow
	 * of one specific local target, which is why it keys on the owner columns
	 * too, unlike `forget_actor()` below.
	 *
	 * Extracted verbatim from the orchestrator's `handle_undo()` at WP7.
	 *
	 * @param array<string, mixed> $body Raw Undo activity (the outer one).
	 */
	public function undo_follow( array $body ): void {
		global $wpdb;

		$object      = $body['object'] ?? [];
		$follower_id = $body['actor'] ?? '';
		$target      = is_string( $object['object'] ?? null ) ? $object['object'] : '';
		$owner       = $this->identity->resolve_local_owner( $target ) ?? [ 'type' => 'node', 'id' => 0 ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes every value; small, node-scale table.
		$wpdb->delete(
			$wpdb->prefix . 'agnosis_followers',
			[ 'owner_type' => $owner['type'], 'owner_id' => $owner['id'], 'actor_id' => $follower_id ],
			[ '%s', '%d', '%s' ]
		);
	}

	/**
	 * Forget a remote actor entirely, across every owner it followed — the
	 * response to a verified self-account-`Delete`.
	 *
	 * Deliberately unscoped by owner, unlike `undo_follow()`: the remote actor
	 * no longer exists at all, so the node's own follower list AND any
	 * per-artist list naming it are equally stale. Extracted verbatim from the
	 * orchestrator's `handle_delete()` at WP7; see that method for how the
	 * self-delete is established in the first place.
	 */
	public function forget_actor( string $actor_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes every value; small, node-scale table.
		$wpdb->delete(
			$wpdb->prefix . 'agnosis_followers',
			[ 'actor_id' => $actor_id ],
			[ '%s' ]
		);
	}

	public function followers( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$artist_id = $request->get_param( 'artist_id' );

		if ( null !== $artist_id ) {
			$not_found = $this->identity->require_artist( (int) $artist_id );
			if ( is_wp_error( $not_found ) ) {
				return $not_found;
			}
		}

		[ $owner_type, $owner_id ] = null !== $artist_id ? [ 'artist', (int) $artist_id ] : [ 'node', 0 ];

		global $wpdb;
		// Per AS2/ActivityPub, a followers collection's items are the
		// followers' actor IDs, not the delivery-plumbing inbox URLs — a
		// consumer that dereferences an item expects an actor document, and
		// an inbox URL only answers signed POSTs (audit §2a, AUDIT-1.0.0.md).
		// Delivery code (broadcast()/enqueue_delivery_retry() and friends)
		// keeps reading inbox_url internally; this is the one public-facing
		// read of this table that needed to change.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small, node-scale table (audit §3g note iii); parameterized via prepare().
		$actor_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT actor_id FROM {$wpdb->prefix}agnosis_followers WHERE owner_type = %s AND owner_id = %d ORDER BY id ASC",
			$owner_type,
			$owner_id
		) );

		$collection_id = null !== $artist_id
			? $this->identity->actor_url_for( 'artist', (int) $artist_id ) . '/followers'
			: rest_url( 'agnosis/v1/activitypub/followers' );

		return new WP_REST_Response( [
			'@context'   => Identity::CONTEXT,
			'type'       => 'OrderedCollection',
			'id'         => $collection_id,
			'totalItems' => count( $actor_ids ),
			'orderedItems' => $actor_ids,
		], 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	/**
	 * Register the agnosis/follow-overlay dynamic block — a "Follow" trigger
	 * that sits next to agnosis/reply-overlay's own "Reply" trigger, opening
	 * a native-Popover-API panel (same mechanism, no bespoke modal JS/CSS)
	 * with plain-language Fediverse/ActivityPub instructions, this artwork's
	 * artist's copyable @handle, and a "remote follow" form.
	 *
	 * There is no single URL a browser can open to complete a follow across
	 * two different, independent Fediverse servers — the follow has to be
	 * authorized FROM the visitor's own instance, not this one.
	 * `authorize_interaction` is the de-facto standard endpoint Mastodon
	 * (and most compatible software) exposes for exactly this: given
	 * `?uri=<actor URL>`, the visitor's own instance resolves it and shows
	 * them a normal in-app follow confirmation. The copyable handle is the
	 * fallback for any visitor whose own app doesn't support that redirect.
	 */
	public function register_follow_overlay_block(): void {
		register_block_type(
			\AGNOSIS_DIR . 'blocks/follow-overlay',
			[ 'render_callback' => [ $this, 'render_follow_overlay' ] ]
		);
	}

	/**
	 * Render callback for agnosis/follow-overlay. Renders nothing on a
	 * non-artwork post, or when the artwork's author account no longer
	 * resolves to a real (still-admitted) artist — same "empty string takes
	 * no space" convention as render_artwork_copyright().
	 *
	 * @param array<string, mixed> $attrs   Block attributes (unused).
	 * @param string               $content Inner block content (unused).
	 * @param \WP_Block            $block   Block instance (provides postId context).
	 */
	public function render_follow_overlay( array $attrs, string $content, \WP_Block $block ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );

		if ( ! $post || 'agnosis_artwork' !== $post->post_type ) {
			return '';
		}

		$artist_id = (int) $post->post_author;
		$handle    = $this->identity->handle_for( 'artist', $artist_id );

		if ( '' === $handle ) {
			return '';
		}

		$actor_url = $this->identity->actor_url_for( 'artist', $artist_id );

		wp_enqueue_style( 'agnosis-follow-overlay', \AGNOSIS_URL . 'blocks/follow-overlay/frontend.css', [], \AGNOSIS_VERSION );
		wp_enqueue_script( 'agnosis-follow-overlay', \AGNOSIS_URL . 'blocks/follow-overlay/frontend.js', [], \AGNOSIS_VERSION, [ 'in_footer' => true ] );
		wp_localize_script( 'agnosis-follow-overlay', 'agnosisFollowOverlay', [
			'actorUrl' => $actor_url,
			'i18n'     => [
				'invalidInstance' => __( 'Enter your Fediverse instance domain (e.g. mastodon.social).', 'agnosis' ),
				'copied'          => __( 'Copied!', 'agnosis' ),
			],
		] );

		$panel_id           = 'agnosis-follow-overlay-' . $post_id;
		$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'agnosis-follow-overlay' ] );

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() output is already escaped. ?>>
			<button
				type="button"
				class="agnosis-follow-overlay__trigger"
				popovertarget="<?php echo esc_attr( $panel_id ); ?>"
				popovertargetaction="show"
			>
				<?php esc_html_e( 'Follow', 'agnosis' ); ?>
			</button>

			<div id="<?php echo esc_attr( $panel_id ); ?>" class="agnosis-follow-overlay__panel" popover="auto">
				<button
					type="button"
					class="lf-icon-btn lf-popover-close"
					popovertarget="<?php echo esc_attr( $panel_id ); ?>"
					popovertargetaction="hide"
					aria-label="<?php esc_attr_e( 'Close', 'agnosis' ); ?>"
				>
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">
						<path d="M4 4l16 16M20 4 4 20"></path>
					</svg>
				</button>
				<div class="agnosis-follow-overlay__inner">
					<p class="agnosis-follow-overlay__intro">
						<?php esc_html_e( 'This artist publishes to the Fediverse — the open, decentralized social network behind Mastodon, Pixelfed, and other ActivityPub-based apps. Follow them from your own account on any of those, no Agnosis account needed.', 'agnosis' ); ?>
					</p>

					<p class="agnosis-follow-overlay__handle-label"><?php esc_html_e( 'Their handle:', 'agnosis' ); ?></p>
					<div class="agnosis-follow-overlay__handle-row">
						<code class="agnosis-follow-overlay__handle"><?php echo esc_html( '@' . $handle ); ?></code>
						<button type="button" class="agnosis-follow-overlay__copy" data-agnosis-copy-handle="<?php echo esc_attr( '@' . $handle ); ?>">
							<?php esc_html_e( 'Copy', 'agnosis' ); ?>
						</button>
					</div>
					<p class="agnosis-follow-overlay__hint"><?php esc_html_e( 'Paste it into the search bar of your own Fediverse app to follow directly.', 'agnosis' ); ?></p>

					<form class="agnosis-follow-overlay__form" data-agnosis-follow-form>
						<label class="agnosis-follow-overlay__form-label" for="<?php echo esc_attr( $panel_id ); ?>-instance">
							<?php esc_html_e( 'Or enter your instance to follow with one click:', 'agnosis' ); ?>
						</label>
						<div class="agnosis-follow-overlay__form-row">
							<input
								type="text"
								id="<?php echo esc_attr( $panel_id ); ?>-instance"
								name="instance"
								placeholder="<?php esc_attr_e( 'yourinstance.social', 'agnosis' ); ?>"
								autocomplete="off"
								autocapitalize="off"
								spellcheck="false"
							/>
							<button type="submit" class="agnosis-follow-overlay__form-submit"><?php esc_html_e( 'Follow', 'agnosis' ); ?></button>
						</div>
						<?php // Sixteenth audit, A-3: frontend.js writes the "enter your instance domain" validation error into this paragraph, so it must be a live region or a screen-reader user gets no feedback at all on a failed submit. The reply form's own status paragraph one method away already carries this; the newer block simply didn't inherit it. ?>
						<p class="agnosis-follow-overlay__form-status" data-agnosis-follow-status aria-live="polite"></p>
					</form>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
