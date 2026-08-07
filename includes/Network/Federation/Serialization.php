<?php
/**
 * Serialization — turning this node's own content into ActivityStreams, and
 * pushing the result out when it changes.
 *
 * Sixth and final unit extracted from Network\ActivityPub (sixteenth audit,
 * Q-2, WP6 — agnosis-audit/ACTIVITYPUB-SPLIT-ROADMAP.md). It owns one question:
 * *what does an artwork look like as an AS2 document, and who needs to be told
 * when that changes?* Four groups of methods answer it:
 *
 * - **The Note builder** — `post_to_note()` and its content helpers: the
 *   description, hashtags (both as a `tag` array and as `#Name` text, because
 *   Mastodon indexes from the content), the language-switch line, the
 *   `sensitive`/`summary` pair, and the `contentMap` key.
 * - **Lifecycle** — `Create` on publish, `Update` on edit or thumbnail change,
 *   `Delete` + `Tombstone` on removal, and the bounded tombstone registry that
 *   keeps a deleted artwork's id dereferenceable.
 * - **Outbox and content negotiation** — the collection of recent Creates, and
 *   serving the same document from an artwork's own permalink when the request
 *   asks for `application/activity+json`.
 * - **Broadcast** — handing a freshly-built activity to Delivery.
 *
 * **This class sits at the TOP of the layering, and that is §2a's whole point.**
 * An AS2 Note carries `likesCount`, `sharesCount` and `repliesCount`, so
 * building one reads from `Interactions` and `Replies`. The original plan filed
 * `post_to_note()` under the delivery kernel; had that stood, `Delivery` would
 * have depended on `Replies` and the layering would have inverted. Serialization
 * is the consumer of every other unit, never their dependency:
 *
 *     Identity -> Delivery -> Interactions / Rhizome -> Replies -> **Serialization**
 *
 * Its counterpart `reply_to_note()` is deliberately *not* here — WP5 found it
 * depends only on reply helpers, so it lives in `Replies` (§5g). The asymmetry
 * is real: an artwork's Note aggregates the whole subsystem; a reply's Note
 * does not.
 *
 * All four collaborators are injected, so a cycle is unwritable: this class can
 * be handed things constructed before it, and nothing constructed after it
 * exists to hand back.
 *
 * Behaviour is unchanged. Every method body is the one that stood in
 * ActivityPub.php. The call graph found **zero calls from the orchestrator into
 * this unit** — which is what "top of the layering" means in practice — so the
 * only widening is `post_to_note()`, and that is not for a caller at all: three
 * test files reach it through `ReflectionMethod` on the orchestrator (§5e).
 *
 * @package Agnosis\Network\Federation
 */

declare(strict_types=1);

namespace Agnosis\Network\Federation;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Artist\ContentEditor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Serialization {

	public function __construct(
		private Identity $identity,
		private Delivery $delivery,
		private Interactions $interactions,
		private Replies $replies,
		private Language $language
	) {}

	/**
	 * GET /agnosis/v1/activitypub/outbox — root discovery when called with no
	 * `page` param, a specific page's items when called with one.
	 *
	 * Audit §3d: this used to always return an `OrderedCollectionPage` — even
	 * at the root, and with no `first`/`next`/`prev` links — so a
	 * spec-conformant consumer (Mastodon's profile backfill, fedi
	 * crawlers/archive tools) GETting the root to discover pagination saw a
	 * page of a collection that was never itself served, with page 2+
	 * unreachable except by guessing the query param. The root now serves an
	 * `OrderedCollection` naming `first`; a paged request gets the existing
	 * page shape plus `next` (while more items remain) and `prev` (beyond
	 * page 1).
	 */
	public function outbox( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$artist_id = $request->get_param( 'artist_id' );

		if ( null !== $artist_id ) {
			$not_found = $this->identity->require_artist( (int) $artist_id );
			if ( is_wp_error( $not_found ) ) {
				return $not_found;
			}
		}

		$base = null !== $artist_id
			? $this->identity->actor_url_for( 'artist', (int) $artist_id ) . '/outbox'
			: rest_url( 'agnosis/v1/activitypub/outbox' );

		// Audit §3h: a per-artist outbox counts only THAT artist's own
		// published artworks. count_user_posts() with $public_only handles
		// this directly; wp_count_posts() has no author filter at all, which
		// is why the node-level (unscoped) branch keeps using it.
		$total = null !== $artist_id
			? (int) count_user_posts( (int) $artist_id, 'agnosis_artwork', true )
			: (int) wp_count_posts( 'agnosis_artwork' )->publish;

		$requested_page = $request->get_param( 'page' );

		if ( null === $requested_page ) {
			return new WP_REST_Response( [
				'@context'   => Identity::CONTEXT,
				'type'       => 'OrderedCollection',
				'id'         => $base,
				'totalItems' => $total,
				'first'      => $base . '?page=1',
			], 200, [ 'Content-Type' => 'application/activity+json' ] );
		}

		$page  = max( 1, (int) $requested_page );
		$limit = 20;

		$query_args = [
			'post_type'      => 'agnosis_artwork',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => ( $page - 1 ) * $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];
		if ( null !== $artist_id ) {
			$query_args['author'] = (int) $artist_id;
		}

		$posts = get_posts( $query_args );
		$items = array_map( [ $this, 'post_to_activity' ], $posts );

		$page_activity = [
			'@context'     => Identity::CONTEXT,
			'type'         => 'OrderedCollectionPage',
			'id'           => $base . '?page=' . $page,
			'partOf'       => $base,
			'totalItems'   => $total,
			'orderedItems' => $items,
		];

		if ( ( $page * $limit ) < $total ) {
			$page_activity['next'] = $base . '?page=' . ( $page + 1 );
		}

		if ( $page > 1 ) {
			$page_activity['prev'] = $base . '?page=' . ( $page - 1 );
		}

		return new WP_REST_Response( $page_activity, 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	public function broadcast( int $post_id ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'agnosis_artwork' ) {
			return;
		}

		// A (re)published artwork dereferences again — its slug must not
		// shadow a stale tombstone (audit §3e).
		$this->clear_tombstone( $post->post_name );

		$owner = $this->identity->owner_for_post( $post );
		$this->delivery->deliver_to_followers( $this->post_to_activity( $post ), $owner['type'], $owner['id'] );
	}

	/**
	 * transition_post_status handler: federate an artwork leaving `publish`.
	 *
	 * Covers trash (the community removal-vote flow's RemovalEndpoints path
	 * ends in wp_trash_post()), unpublish/draft, and any other transition out
	 * of publish. Transitions INTO publish clear a stale tombstone for the
	 * slug so a restored or re-slugged artwork dereferences again.
	 */
	public function federate_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'agnosis_artwork' !== $post->post_type || $new_status === $old_status ) {
			return;
		}

		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			$this->federate_delete( $post );
		} elseif ( 'publish' === $new_status ) {
			$this->clear_tombstone( $post->post_name );
		}
	}

	/**
	 * before_delete_post handler: federate a force-deleted published artwork.
	 *
	 * wp_delete_post() (e.g. Departure's force_delete of a leaving/banned
	 * artist's works) never fires transition_post_status, so the trash-path
	 * hook alone would miss it. A post force-deleted FROM trash was already
	 * tombstoned at trash time and is skipped by the status guard.
	 */
	public function federate_force_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post && 'agnosis_artwork' === $post->post_type && 'publish' === $post->post_status ) {
			$this->federate_delete( $post );
		}
	}

	/**
	 * post_updated handler: federate a meaningful edit of a published artwork.
	 *
	 * "Meaningful" = title, content, or excerpt changed (ContentEditor's
	 * title/text edits land here via wp_update_post()). Both sides must be
	 * `publish` — that also keeps the wp_trash_post()-internal update from
	 * double-firing next to the Delete.
	 */
	public function federate_update( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		unset( $post_id );

		if ( 'agnosis_artwork' !== $post_after->post_type ) {
			return;
		}
		if ( 'publish' !== $post_after->post_status || 'publish' !== $post_before->post_status ) {
			return;
		}
		if ( $post_after->post_title === $post_before->post_title
			&& $post_after->post_content === $post_before->post_content
			&& $post_after->post_excerpt === $post_before->post_excerpt ) {
			return;
		}

		$this->broadcast_update( $post_after );
	}

	/**
	 * updated_post_meta / added_post_meta handler: a replaced or newly set
	 * featured image on a published artwork is a meaningful edit too —
	 * ContentEditor's photo replacement goes through set_post_thumbnail(),
	 * which never fires post_updated.
	 */
	public function federate_thumbnail_update( int $meta_id, int $post_id, string $meta_key ): void {
		unset( $meta_id );

		if ( '_thumbnail_id' !== $meta_key ) {
			return;
		}

		$post = get_post( $post_id );
		if ( $post && 'agnosis_artwork' === $post->post_type && 'publish' === $post->post_status ) {
			$this->broadcast_update( $post );
		}
	}

	/**
	 * template_redirect handler: serve the Note JSON when an ActivityPub
	 * consumer dereferences an artwork's object id.
	 *
	 * Wired on template_redirect (frontend requests), so it fires in every
	 * permalink mode — pretty (/art/<slug>) and plain (?agnosis_artwork=<slug>)
	 * alike. A live artwork serves its Note (200); a tombstoned slug serves
	 * the Tombstone with HTTP 410 (audit §3e), so remote servers get the
	 * fediverse-normative "this object is gone, drop your copy" signal when
	 * they re-fetch.
	 */
	public function serve_artwork_activity_json(): void {
		$json = $this->singular_activity_json();
		if ( null !== $json ) {
			$this->emit_activity_json( $json, 200 );
		}

		$tombstone = $this->tombstone_activity_json();
		if ( null !== $tombstone ) {
			$this->emit_activity_json( $tombstone, 410 );
		}
	}

	/**
	 * Send an ActivityStreams JSON response and end the request.
	 *
	 * @param string $json   Pre-encoded payload.
	 * @param int    $status HTTP status code (200 for a Note, 410 for a Tombstone).
	 */
	private function emit_activity_json( string $json, int $status ): void {
		status_header( $status );
		header( 'Content-Type: application/activity+json; charset=' . get_option( 'blog_charset' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- machine-readable JSON body built by wp_json_encode(); HTML escaping would corrupt it.
		echo $json;
		exit;
	}

	/**
	 * Decide whether the current main query should be answered with the
	 * artwork's Note JSON, and build it if so.
	 *
	 * Split from serve_artwork_activity_json() so the guard-and-build logic
	 * is testable without the exit. Returns null when any guard declines:
	 * not an artwork singular, ActivityPub disabled, not published, or the
	 * Accept header doesn't name an ActivityStreams media type (Mastodon
	 * sends "application/activity+json, application/ld+json;
	 * profile=\"https://www.w3.org/ns/activitystreams\"" when
	 * dereferencing).
	 *
	 * @return string|null JSON payload, or null to let the theme render HTML.
	 */
	public function singular_activity_json(): ?string {
		if ( ! is_singular( 'agnosis_artwork' ) ) {
			return null;
		}

		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return null;
		}

		if ( ! $this->accept_is_activitystreams() ) {
			return null;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		$json = wp_json_encode( $this->post_to_note( $post ) );

		return false === $json ? null : $json;
	}

	/**
	 * Build the Tombstone JSON when an AP consumer dereferences a deleted
	 * artwork's object id (audit §3e).
	 *
	 * A live artwork at the slug is singular_activity_json()'s case; this one
	 * fires when the main query found nothing (trashed, unpublished, or
	 * deleted) but the requested artwork slug is in the tombstone registry.
	 * Browsers (no AS2 Accept) keep the theme's ordinary 404.
	 *
	 * @return string|null JSON payload (serve with HTTP 410), or null.
	 */
	public function tombstone_activity_json(): ?string {
		if ( is_singular( 'agnosis_artwork' ) ) {
			return null;
		}

		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return null;
		}

		if ( ! $this->accept_is_activitystreams() ) {
			return null;
		}

		$slug = (string) get_query_var( 'agnosis_artwork' );
		if ( '' === $slug ) {
			return null;
		}

		$tombstones = get_option( 'agnosis_ap_tombstones', [] );
		if ( ! isset( $tombstones[ $slug ]['id'], $tombstones[ $slug ]['deleted'] ) ) {
			return null;
		}

		$json = wp_json_encode( [
			'@context'   => Identity::CONTEXT,
			'type'       => 'Tombstone',
			'id'         => $tombstones[ $slug ]['id'],
			'formerType' => 'Note',
			'deleted'    => $tombstones[ $slug ]['deleted'],
		] );

		return false === $json ? null : $json;
	}

	/** Does the request's Accept header name an ActivityStreams media type? */
	private function accept_is_activitystreams(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a request header on a public GET; nonces are not applicable.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';

		return str_contains( $accept, 'application/activity+json' ) || str_contains( $accept, 'application/ld+json' );
	}

	/**
	 * Federate `Delete { object: Tombstone }` for a post leaving publish, and
	 * record the tombstone so the object id serves 410 thereafter.
	 *
	 * The tombstone is recorded even when there are currently no followers:
	 * a third server that ever saw the object (via a boost, or §3c
	 * dereferencing) can still learn it's gone when it re-fetches.
	 *
	 * TAG-REDESIGN.md F2: no longer primary-only. A language sibling was
	 * never remotely Created (that stays primary-only per F2's "no pushes
	 * yet" scope), but its own permalink is dereferenceable (§3c content
	 * negotiation has no language guard), so it still needs its own
	 * tombstone recorded here — otherwise deleting a sibling would silently
	 * leave its now-stale Note dereferenceable at 200 instead of 410.
	 */
	private function federate_delete( \WP_Post $post ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$object_id = $this->identity->object_id_for( $post );
		$deleted   = gmdate( 'c' );

		$this->record_tombstone( preg_replace( '/__trashed$/', '', $post->post_name ), $object_id, $deleted );

		$owner = $this->identity->owner_for_post( $post );

		$this->delivery->deliver_to_followers( [
			'@context' => Identity::CONTEXT,
			'type'     => 'Delete',
			'id'       => $object_id . '#delete',
			'actor'    => $this->identity->actor_url_for( $owner['type'], $owner['id'] ),
			'to'       => [ 'https://www.w3.org/ns/activitystreams#Public' ],
			'object'   => [
				'type'       => 'Tombstone',
				'id'         => $object_id,
				'formerType' => 'Note',
				'deleted'    => $deleted,
			],
		], $owner['type'], $owner['id'] );
	}

	/**
	 * Federate `Update` with the refreshed Note.
	 *
	 * Deduplicated per post per request: a single editorial save can touch
	 * the post row AND the thumbnail meta (two hooks), but one refreshed
	 * Note says everything.
	 *
	 * TAG-REDESIGN.md F2: no longer primary-only. A sibling's Note is
	 * already dereferenceable (§3c has no language guard), so an editorial
	 * change to a sibling needs its own Update pushed too — only Create
	 * stays primary-only under F2 ("no pushes yet" for the initial publish).
	 */
	private function broadcast_update( \WP_Post $post ): void {
		static $sent = [];

		if ( isset( $sent[ $post->ID ] ) ) {
			return;
		}

		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$sent[ $post->ID ] = true;

		$note  = $this->post_to_note( $post );
		$owner = $this->identity->owner_for_post( $post );

		$this->delivery->deliver_to_followers( [
			'@context'  => Identity::CONTEXT,
			'type'      => 'Update',
			'id'        => $note['id'] . '#update-' . time(),
			'actor'     => $note['attributedTo'],
			'published' => gmdate( 'c' ),
			'to'        => $note['to'],
			'object'    => $note,
		], $owner['type'], $owner['id'] );
	}

	/**
	 * Record a slug in the tombstone registry (bounded, autoload=false).
	 *
	 * @param string $slug      Artwork slug (clean, without `__trashed`).
	 * @param string $object_id The object id the artwork federated under.
	 * @param string $deleted   ISO 8601 deletion timestamp.
	 */
	private function record_tombstone( string $slug, string $object_id, string $deleted ): void {
		$tombstones = get_option( 'agnosis_ap_tombstones', [] );

		$tombstones[ $slug ] = [
			'id'      => $object_id,
			'deleted' => $deleted,
		];

		if ( count( $tombstones ) > Replies::TOMBSTONE_CAP ) {
			uasort( $tombstones, static fn( array $a, array $b ) => strcmp( $a['deleted'], $b['deleted'] ) );
			$tombstones = array_slice( $tombstones, -Replies::TOMBSTONE_CAP, null, true );
		}

		update_option( 'agnosis_ap_tombstones', $tombstones, false );
	}

	/** Remove a slug from the tombstone registry (idempotent). */
	private function clear_tombstone( string $slug ): void {
		$tombstones = get_option( 'agnosis_ap_tombstones', [] );

		if ( isset( $tombstones[ $slug ] ) ) {
			unset( $tombstones[ $slug ] );
			update_option( 'agnosis_ap_tombstones', $tombstones, false );
		}
	}

	/**
	 * Build the artwork's Note object.
	 *
	 * The Note's `id` is minted from get_permalink() so that id === url in
	 * every permalink mode (audit §3c): the old hardcoded `/art/<slug>` id
	 * 404'd outright on plain-permalink sites (where the real URL is
	 * `?agnosis_artwork=<slug>`), and even on pretty-permalink sites the two
	 * fields could only agree by construction, not by guarantee. The AP spec
	 * expects an object's id to dereference to the object — served by
	 * serve_artwork_activity_json() via content negotiation on the same URL.
	 *
	 * Audit §3f enrichment pass: the featured image now carries real alt
	 * text and its actual MIME type instead of a hardcoded one; `content` is
	 * the artist's full AI-written description instead of a flat 50-word
	 * truncation; post_tag/agnosis_medium terms become both a `tag` array
	 * and matching `#Name` strings appended to `content` (Mastodon indexes
	 * hashtags from the content text itself, not the `tag` array); and
	 * `sensitive`/`summary` are set when either the artist or the operator
	 * has flagged the piece — see is_post_sensitive().
	 *
	 * @return array<string, mixed>
	 */
	public function post_to_note( \WP_Post $post ): array {
		// Audit §3h: attributed to the artist's own actor when the post has a
		// real artist author, falling back to the node otherwise (see
		// owner_for_post()'s docblock for when that fallback applies).
		$owner        = $this->identity->owner_for_post( $post );
		$actor        = $this->identity->actor_url_for( $owner['type'], $owner['id'] );
		// Cast, matching Identity::object_id_for() — get_permalink() is typed
		// string|false, and this value lands in the Note's `id` AND `url`
		// (below), both of which are serialized straight to JSON. Without the
		// cast a failed lookup would emit `"id": false`, which is not a valid
		// AS2 object id and would be rejected by the receiving server rather
		// than degrading. The sibling method one class away has always cast;
		// this one did not, and only surfaced because a test indexed the shape
		// (0.9.68).
		$object_id    = (string) get_permalink( $post->ID );
		// get_post_thumbnail_id() is typed int|false; normalize to int (0 =
		// none) so it satisfies get_post_mime_type()/get_post_meta()'s int
		// parameter below without a separate is-int guard at each call site.
		$thumbnail_id = (int) get_post_thumbnail_id( $post->ID );
		$image_url    = $thumbnail_id > 0 ? ( get_the_post_thumbnail_url( $post->ID, 'agnosis-artwork' ) ?: '' ) : '';

		[ $hashtags, $hashtag_text ] = $this->build_hashtags( $post->ID );

		$content = $this->build_note_content( $post );
		if ( '' !== $hashtag_text ) {
			$content .= '<p>' . $hashtag_text . '</p>';
		}

		$language_switch = $this->build_language_switch_line( $post->ID );
		if ( '' !== $language_switch ) {
			$content .= '<p>' . $language_switch . '</p>';
		}

		$note = [
			'@context'     => Identity::CONTEXT,
			'type'         => 'Note',
			'id'           => $object_id,
			'url'          => $object_id,
			'attributedTo' => $actor,
			'name'         => wp_strip_all_tags( $post->post_title ),
			'content'      => $content,
			// TAG-REDESIGN.md F1 — the language-unknown gap: this Note
			// otherwise carries no language hint at all, so a Mastodon
			// follower's per-followed-account language filter can't act on
			// it. `contentMap` is the mechanism Mastodon actually reads to
			// infer a Note's language. Always a single entry: each language
			// sibling is its own separate post with its own separate Note
			// (F2 makes a sibling's Note dereferenceable/lifecycle-correct
			// in its own right, via resolve_note_language()'s own _lf_lang
			// lookup for that post) — there is no single Note that
			// aggregates every language's content into one contentMap.
			'contentMap'   => [ $this->language->resolve_note_language( $post->ID ) => $content ],
			'published'    => gmdate( 'c', (int) strtotime( $post->post_date_gmt ) ),
			'to'           => [ 'https://www.w3.org/ns/activitystreams#Public' ],
		];

		if ( [] !== $hashtags ) {
			$note['tag'] = $hashtags;
		}

		// Interaction-surface roadmap, Phase 1 (2026-07-24) — cosmetic parity
		// item 5: a remote server that fetched this Note directly (rather
		// than relying on its own locally-computed count) has something to
		// show. Not required for Likes/Announces to actually work — Mastodon
		// computes its own count independently of what's reported here — so
		// this is deliberately best-effort and never blocks building the Note
		// if interaction_counts() finds nothing (0/0 on a brand-new artwork).
		$counts              = $this->interactions->interaction_counts( $post->ID );
		$note['likesCount']  = $counts['like'];
		$note['sharesCount'] = $counts['announce'];
		// Interaction-surface roadmap, Phase 3, WP6 — same best-effort cosmetic
		// parity as likesCount/sharesCount above, now that replies themselves
		// can be genuinely federated (an artist's own reply, when they've
		// opted in via the gateway checkbox). reply_count() already counts
		// both federated and local approved replies; a remote server has no
		// way to enumerate the local-only ones anyway, so `replies` itself
		// stays a bare totalItems rather than a real paged Collection.
		$note['repliesCount'] = $this->replies->reply_count( $post->ID );
		$note['replies']      = [
			'type'       => 'Collection',
			'totalItems' => $note['repliesCount'],
		];

		if ( $this->is_post_sensitive( $post->ID ) ) {
			$note['sensitive'] = true;
			$note['summary']   = __( 'Sensitive content', 'agnosis' );
		}

		if ( $image_url ) {
			$attachment = [
				'type'      => 'Image',
				'url'       => $image_url,
				'mediaType' => get_post_mime_type( $thumbnail_id ) ?: 'image/jpeg',
			];

			$alt_text = trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) );
			if ( '' !== $alt_text ) {
				$attachment['name'] = $alt_text;
			}

			$note['attachment'] = [ $attachment ];
		}

		return $note;
	}

	/**
	 * Extract just the freeform text portions of the post's content — the
	 * AI-written description, which build_post_content() (Publishing\PostCreator)
	 * inserts as raw HTML paragraphs, not wrapped in a Gutenberg block, next
	 * to real wp:gallery/wp:image/wp:video/wp:audio/wp:embed blocks for any
	 * attached media (the image is already covered separately via
	 * `attachment`, and video/audio/embeds aren't meaningful in a Note, so
	 * both are deliberately excluded here). Falls back to the previous
	 * 50-word truncated summary only if no freeform text is found at all —
	 * defensive; every current artwork post has some (audit §3f: artists'
	 * carefully AI-written descriptions were previously arriving amputated
	 * mid-sentence at a flat 50-word cap, when AP `content` is HTML and can
	 * carry the whole thing).
	 */
	private function build_note_content( \WP_Post $post ): string {
		$html = '';

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( null === $block['blockName'] ) {
				$html .= $block['innerHTML'];
			}
		}

		$html = trim( $html );

		return '' !== $html ? $html : wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 );
	}

	/**
	 * Build the Note's `tag` array (AS2 Hashtag objects) from the artwork's
	 * post_tag + agnosis_medium terms, plus the matching space-joined
	 * `#Name` text to append to `content` — audit §3f. Term names become
	 * CamelCase hashtags (each word capitalized, no separators): the
	 * community-recommended form, since screen readers announce capitalized
	 * words separately instead of running one long lowercase string
	 * together.
	 *
	 * @return array{0: array<int, array<string, string>>, 1: string}
	 */
	private function build_hashtags( int $post_id ): array {
		// wp_get_post_tags()/wp_get_post_terms() are typed to allow WP_Error
		// (an invalid taxonomy or post id) even though neither can realistically
		// happen here — post_tag and agnosis_medium always exist, $post_id is
		// always a real post — but the return type must still be narrowed
		// before array_merge()/foreach will accept it.
		$terms = wp_get_post_tags( $post_id );
		if ( is_wp_error( $terms ) ) {
			$terms = [];
		}

		if ( taxonomy_exists( 'agnosis_medium' ) ) {
			$medium_terms = wp_get_post_terms( $post_id, 'agnosis_medium' );
			if ( ! is_wp_error( $medium_terms ) ) {
				$terms = array_merge( $terms, $medium_terms );
			}
		}

		$tags = [];
		$seen = [];

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$name = $this->hashtag_name( $term->name );
			if ( '' === $name || isset( $seen[ strtolower( $name ) ] ) ) {
				continue;
			}
			$seen[ strtolower( $name ) ] = true;

			$link   = get_term_link( $term );
			$tags[] = [
				'type' => 'Hashtag',
				'name' => '#' . $name,
				'href' => is_wp_error( $link ) ? home_url( '/' ) : $link,
			];
		}

		return [ $tags, implode( ' ', array_column( $tags, 'name' ) ) ];
	}

	/**
	 * Convert a taxonomy term name into a bare CamelCase hashtag word: every
	 * run of letters/digits capitalized and concatenated, everything else
	 * (spaces, punctuation) stripped — a hashtag can't contain whitespace.
	 */
	private function hashtag_name( string $term_name ): string {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $term_name, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $words ) {
			return '';
		}

		return implode( '', array_map( static fn( string $word ): string => ucfirst( mb_strtolower( $word ) ), $words ) );
	}

	/**
	 * A compact "Also available in: X, Y" line linking every OTHER published
	 * language sibling of $post_id — TAG-REDESIGN.md F4 (§6b): "each sibling
	 * Note linking its translations informally in content, matching what the
	 * theme's language badge already does for HTML readers" (see
	 * `Network\SubdomainNavigation::render_language_badge()` for that HTML
	 * equivalent — same native-name source, `AI\SubmissionTranslator::
	 * language_names()`).
	 *
	 * Deliberately built for EVERY Note this method is called for, not only
	 * a sibling's own — under `agnosis_federate_languages`'s default
	 * `primary-only`, a sibling's Note is dereferenceable (F2) but never
	 * actively federated (F3/F4), so the PRIMARY Note is the only one an AP
	 * reader ever actually sees; without this line ALSO appearing there, the
	 * feature would be invisible in the default configuration. This is a
	 * pure content addition — it has no bearing on whether a sibling itself
	 * gets pushed (that's `Network\FederationSettlement`'s own
	 * `agnosis_federate_languages` gate, unrelated to what any one Note's
	 * `content` says).
	 *
	 * Returns '' (nothing appended) when Lingua Forge isn't active or no
	 * OTHER language currently has a published sibling for this post.
	 */
	private function build_language_switch_line( int $post_id ): string {
		if ( ! function_exists( 'linguaforge_get_translations' ) ) {
			return '';
		}

		$language_names = SubmissionTranslator::language_names();
		$links          = [];

		foreach ( linguaforge_get_translations( $post_id ) as $lang => $sibling_id ) {
			$sibling = get_post( (int) $sibling_id );
			if ( ! $sibling instanceof \WP_Post || 'publish' !== $sibling->post_status ) {
				continue;
			}

			$native_name = $language_names[ $lang ] ?? strtoupper( (string) $lang );
			$links[]     = sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_permalink( $sibling ) ), esc_html( $native_name ) );
		}

		if ( [] === $links ) {
			return '';
		}

		return sprintf(
			/* translators: %s: comma-separated, already-linked list of language names, e.g. "Deutsch, Español" */
			esc_html__( 'Also available in: %s', 'agnosis' ),
			implode( ', ', $links )
		);
	}

	/**
	 * Whether the artwork should federate with AS2 `sensitive: true` + a
	 * content-warning `summary` (audit §3f — filed by the audit as a product
	 * call, not a defect, since nothing in Agnosis previously had any concept
	 * of "sensitive" at all). Two independent levers, either is enough:
	 *
	 *   - Artist\ContentEditor::save_sensitive() — an artist flags a specific
	 *     piece via `_agnosis_sensitive` post meta.
	 *   - Artist\Profile's agnosis_medium term-meta checkbox — an operator
	 *     flags a whole medium (e.g. one used for explicit work) via
	 *     `_agnosis_medium_sensitive`, so every artwork under it federates
	 *     with a warning without the artist needing to flag each piece.
	 */
	private function is_post_sensitive( int $post_id ): bool {
		if ( get_post_meta( $post_id, '_agnosis_sensitive', true ) ) {
			return true;
		}

		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			return false;
		}

		$medium_terms = wp_get_post_terms( $post_id, 'agnosis_medium' );
		if ( is_wp_error( $medium_terms ) ) {
			return false;
		}

		foreach ( $medium_terms as $term ) {
			if ( $term instanceof \WP_Term && get_term_meta( $term->term_id, '_agnosis_medium_sensitive', true ) ) {
				return true;
			}
		}

		return false;
	}

	/** @return array<string, mixed> */
	private function post_to_activity( \WP_Post $post ): array {
		$note = $this->post_to_note( $post );

		return [
			'@context'  => Identity::CONTEXT,
			'type'      => 'Create',
			'id'        => $note['id'] . '#create',
			'actor'     => $note['attributedTo'],
			'published' => $note['published'],
			'to'        => $note['to'],
			'object'    => $note,
		];
	}
}
