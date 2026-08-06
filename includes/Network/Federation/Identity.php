<?php
/**
 * Federation identity — who this node and its artists ARE on the Fediverse.
 *
 * First unit extracted from Network\ActivityPub (sixteenth audit, Q-2, WP1 —
 * see agnosis-audit/ACTIVITYPUB-SPLIT-ROADMAP.md). It owns the actor documents
 * (the node's own `Service` actor and each artist's `Person` actor), the URLs
 * and `@handle@host` strings that name them, the per-artist RSA keypair those
 * actors publish, and WebFinger — the discovery endpoint that turns a handle
 * back into an actor.
 *
 * **Widened at WP3, deliberately.** `resolve_local_owner()` and
 * `resolve_local_post_id()` moved down here from the orchestrator when `Rhizome`
 * was cut, because three sibling units (`Rhizome` now, `Interactions` at WP4,
 * `Replies` at WP5) all need to answer "does this remote URL name something of
 * ours?", and §0c's rule is that a shared need moves *down* rather than sideways
 * through the orchestrator. They belong here on their own merits too: this class
 * already owned `owner_for_post()` and `resolve_webfinger_subject()`, which map
 * between local entities and federation addresses in exactly the same way — these
 * two are that mapping read backwards. They were moved as a pair because
 * `resolve_local_post_id()`'s docblock names `resolve_local_owner()` as its mirror
 * image, and splitting a documented pair across two classes buys nothing.
 *
 * **This class depends on nothing else in the subsystem, and that is the point.**
 * It sits at the bottom of the layering (Identity -> Delivery -> Interactions ->
 * Replies -> Serialization) and was extracted first for exactly that reason:
 * every other unit needs it, it needs none of them. Verified by call graph
 * before the move — the thirteen methods below call each other and nothing else,
 * and the two added at WP3 kept that true: both use only WordPress core.
 *
 * Behaviour is unchanged. Every method body is the one that stood in
 * ActivityPub.php; only five visibilities widened (`owner_for_post()`,
 * `require_artist()`, `signing_key_for()` at WP1; `resolve_local_owner()` and
 * `resolve_local_post_id()` at WP3) because their callers now live in sibling
 * classes rather than the same file.
 *
 * @package Agnosis\Network\Federation
 */

declare(strict_types=1);

namespace Agnosis\Network\Federation;

use Agnosis\Artist\NotificationPreferences;
use Agnosis\Network\SubdomainRouter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

class Identity {

	/** AS2 context URI, shared with every other federation unit. */
	public const CONTEXT = 'https://www.w3.org/ns/activitystreams';

	public function actor( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$artist_id = $request->get_param( 'artist_id' );
		if ( null !== $artist_id ) {
			return $this->artist_actor( (int) $artist_id );
		}

		$node_url   = rest_url( 'agnosis/v1/activitypub/actor' );
		$public_key = get_option( 'agnosis_public_key', '' );

		return new WP_REST_Response( [
			'@context'          => [ self::CONTEXT, 'https://w3id.org/security/v1' ],
			'type'              => 'Service',
			'id'                => $node_url,
			'url'               => home_url(),
			'name'              => get_option( 'agnosis_node_label', get_bloginfo( 'name' ) ),
			'summary'           => get_bloginfo( 'description' ),
			'preferredUsername' => 'agnosis',
			'inbox'             => rest_url( 'agnosis/v1/activitypub/inbox' ),
			'outbox'            => rest_url( 'agnosis/v1/activitypub/outbox' ),
			'followers'         => rest_url( 'agnosis/v1/activitypub/followers' ),
			// Audit §3h note iii: harmless to advertise even with one actor per
			// node, and now genuinely useful — a remote server delivering the
			// same activity to both the node and one of its artists can use
			// this single endpoint instead of two round trips.
			'endpoints'         => [ 'sharedInbox' => rest_url( 'agnosis/v1/activitypub/inbox' ) ],
			// FEP-5feb (Interaction-surface roadmap, Phase 3, WP1): a compliant
			// search-indexing/FASP consumer must ignore an actor with neither
			// flag set, so these must be emitted, not merely implied by their
			// absence. The node itself isn't an artist — there is no per-artist
			// consent question here, just the operator's own node choosing to
			// run a public, federated, indexable presence — so this is always
			// true, unlike artist_actor()'s per-artist opt-out below.
			'discoverable'      => true,
			'indexable'         => true,
			'publicKey'         => [
				'id'           => $node_url . '#main-key',
				'owner'        => $node_url,
				'publicKeyPem' => $public_key,
			],
		], 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	/**
	 * Build one artist's own ActivityPub actor document (audit §3h).
	 *
	 * type `Person`, not the node's `Service` — an artist is a person, and
	 * this is the whole point of the feature: a fediverse user can follow
	 * one artist specifically, not just the node's undifferentiated firehose.
	 * 404s for anything that isn't a real, currently-admitted artist, so a
	 * random/departed user id doesn't leak account existence or resolve to a
	 * stale actor.
	 */
	private function artist_actor( int $user_id ): WP_REST_Response|WP_Error {
		$user = $this->require_artist( $user_id );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$actor_url = $this->actor_url_for( 'artist', $user_id );
		$keys      = $this->ensure_artist_key_pair( $user_id );

		// FEP-5feb (Interaction-surface roadmap, Phase 3, WP1) — per-artist,
		// account-wide consent (§7 Q7: this is an actor-level property, not a
		// per-artwork one — it governs everything this artist has ever
		// published, not just one piece). Default discoverable/indexable
		// (Ulises: "Default ON"); NotificationPreferences::is_discovery_opted_out()
		// is the single canonical read, shared with the mirrored checkbox on
		// the artwork/biography approval pages (Publishing\ReviewConfirm).
		$discoverable = ! NotificationPreferences::is_discovery_opted_out( $user_id );

		return new WP_REST_Response( [
			'@context'          => [ self::CONTEXT, 'https://w3id.org/security/v1' ],
			'type'              => 'Person',
			'id'                => $actor_url,
			'url'               => SubdomainRouter::url_for_artist( $user_id ),
			'name'              => $user->display_name,
			'preferredUsername' => $user->user_nicename,
			'inbox'             => $actor_url . '/inbox',
			'outbox'            => $actor_url . '/outbox',
			'followers'         => $actor_url . '/followers',
			'endpoints'         => [ 'sharedInbox' => rest_url( 'agnosis/v1/activitypub/inbox' ) ],
			'discoverable'      => $discoverable,
			'indexable'         => $discoverable,
			'publicKey'         => [
				'id'           => $actor_url . '#main-key',
				'owner'        => $actor_url,
				'publicKeyPem' => $keys['public'],
			],
		], 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	/**
	 * 404 unless $user_id is a real user currently holding the agnosis_artist
	 * role — shared guard for every per-artist AP route (actor/outbox/followers).
	 *
	 * @return WP_User|WP_Error
	 */
	public function require_artist( int $user_id ): WP_User|WP_Error {
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'agnosis_artist', (array) $user->roles, true ) ) {
			return new WP_Error( 'ap_actor_not_found', __( 'No such artist actor.', 'agnosis' ), [ 'status' => 404 ] );
		}
		return $user;
	}

	/**
	 * The actor id/URL for the node, or for one specific artist.
	 *
	 * Public (WP3, interaction-surface roadmap, Phase 3): the newsletter
	 * gateway (Newsletter\InteractionGateway) calls this directly to resolve
	 * an artist's real actor URL from a verified token's artist_id, without
	 * needing a logged-in session (there never is one — the no-login rule).
	 */
	public function actor_url_for( string $owner_type, int $owner_id ): string {
		return 'artist' === $owner_type && $owner_id > 0
			? rest_url( 'agnosis/v1/activitypub/actor/' . $owner_id )
			: rest_url( 'agnosis/v1/activitypub/actor' );
	}

	/**
	 * The Fediverse handle string ("nicename@host" / "agnosis@host") for the
	 * node or one specific artist — the display counterpart to
	 * actor_url_for() (which returns the machine id/URL, not something a
	 * person reads or types).
	 *
	 * Always resolves the base/apex domain (`agnosis_base_domain`, falling
	 * back to home_url()'s host exactly like SubdomainRouter::url_for_artist()
	 * does), never the CURRENT request's possibly-rewritten subdomain host —
	 * SubdomainRouter::rewrite_home() repoints home_url() (and everything
	 * derived from it, rest_url() included) at an artist's own subdomain for
	 * the whole request, but per-artist actors deliberately share the node's
	 * own host, not a subdomain (see resolve_webfinger_subject()'s own
	 * docblock: "@artistname@agnosis.art" is what a fediverse user actually
	 * follows). Calling this from a block rendered ON an artist's own
	 * subdomain page (agnosis/site-copyright, agnosis/follow-overlay) would
	 * otherwise silently print a handle that doesn't match what WebFinger
	 * resolves.
	 *
	 * Returns '' when $owner_type is 'artist' and $owner_id doesn't resolve
	 * to a real user.
	 */
	public function handle_for( string $owner_type, int $owner_id = 0 ): string {
		$base = (string) get_option( 'agnosis_base_domain', '' );
		$host = $base ?: (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( 'artist' === $owner_type ) {
			$user = get_userdata( $owner_id );
			if ( ! $user ) {
				return '';
			}
			$username = $user->user_nicename ?: $user->user_login;
			return $username . '@' . $host;
		}

		return 'agnosis@' . $host;
	}

	/**
	 * Which local actor a post's Create/Update/Delete is attributed to and
	 * delivered as (audit §3h). An artwork's post_author is the submitting
	 * artist's real WP user id (Publishing\PostCreator sets it directly, not
	 * a system/admin user), so this is a straightforward lookup — falls back
	 * to the node only for the edge case of a post with no real artist author
	 * (shouldn't happen in practice, but post_author does default to 1 when
	 * no artist_id resolved at submission time).
	 *
	 * @return array{type: string, id: int}
	 */
	public function owner_for_post( \WP_Post $post ): array {
		$author_id = (int) $post->post_author;
		if ( $author_id > 0 ) {
			$author = get_userdata( $author_id );
			if ( $author && in_array( 'agnosis_artist', (array) $author->roles, true ) ) {
				return [ 'type' => 'artist', 'id' => $author_id ];
			}
		}
		return [ 'type' => 'node', 'id' => 0 ];
	}

	/**
	 * Lazily generate (once) and return an artist's own RSA keypair for their
	 * personal ActivityPub actor (audit §3h). Stored in usermeta — one pair
	 * per artist, distinct from the single node-level pair
	 * `Network\Node::ensure_key_pair()` manages in options for the node's own
	 * identity. Mirrors that method's own WP_TESTS_DOMAIN guard: 2048-bit RSA
	 * generation reads from /dev/random, which blocks indefinitely under
	 * entropy starvation inside Docker test containers, so key generation is
	 * skipped entirely during tests — routes still register and work, they
	 * just expose an empty publicKeyPem unless a test explicitly seeds one.
	 *
	 * @return array{public: string, private: string}
	 */
	private function ensure_artist_key_pair( int $user_id ): array {
		$public  = (string) get_user_meta( $user_id, '_agnosis_ap_public_key', true );
		$private = (string) get_user_meta( $user_id, '_agnosis_ap_private_key', true );

		if ( '' !== $public && '' !== $private ) {
			return [ 'public' => $public, 'private' => $private ];
		}

		if ( defined( 'WP_TESTS_DOMAIN' ) || ! function_exists( 'openssl_pkey_new' ) ) {
			return [ 'public' => '', 'private' => '' ];
		}

		$key = openssl_pkey_new( [ 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		if ( false === $key ) {
			return [ 'public' => '', 'private' => '' ];
		}

		openssl_pkey_export( $key, $private );
		$details = openssl_pkey_get_details( $key );
		$public  = $details['key'] ?? '';

		if ( '' === $public ) {
			return [ 'public' => '', 'private' => '' ];
		}

		update_user_meta( $user_id, '_agnosis_ap_public_key', $public );
		update_user_meta( $user_id, '_agnosis_ap_private_key', $private );

		return [ 'public' => $public, 'private' => $private ];
	}

	/**
	 * WebFinger discovery (RFC 7033) — resolves `acct:agnosis@{host}` (the
	 * node) or `acct:{nicename}@{host}` for any admitted artist (audit §3h:
	 * per-artist actors use the SAME host as the node — the base domain, not
	 * an artist's own subdomain — so a handle like `@artistname@agnosis.art`
	 * is what a fediverse user actually follows, matching how a Mastodon
	 * instance's own users all share one host).
	 *
	 * Content-Type is `application/jrd+json` (audit §3h note i) — the spec's
	 * actual required type, not `application/json`; most servers tolerate the
	 * looser type, but conformance-checking ones and some client libraries
	 * don't. wp_send_json() can't be used for this response since it always
	 * sets its own `application/json` Content-Type internally after any
	 * header a caller sets first — send_jrd_json() replicates its shape with
	 * the correct type instead.
	 */
	public function handle_webfinger(): void {
		if ( ! get_query_var( 'agnosis_webfinger' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WebFinger is a public unauthenticated discovery endpoint; nonces are not applicable.
		$resource = sanitize_text_field( wp_unslash( $_GET['resource'] ?? '' ) );
		$result   = $this->resolve_webfinger_subject( $resource );

		if ( null === $result ) {
			$this->send_jrd_json( [ 'error' => 'not found' ], 404 );
		}

		$this->send_jrd_json( $result, 200 );
	}

	/**
	 * Resolve a WebFinger `resource` param to its JRD response body, or null
	 * when unresolvable. Split from handle_webfinger() so the resolution
	 * logic is directly testable without the exit — mirrors
	 * singular_activity_json()'s existing split from its own exit-wrapper
	 * (serve_artwork_activity_json()) elsewhere in this file.
	 *
	 * @return array{subject: string, links: array<int, array<string, string>>}|null
	 */
	public function resolve_webfinger_subject( string $webfinger_resource ): ?array {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! preg_match( '/^acct:([^@]+)@' . preg_quote( $host, '/' ) . '$/', $webfinger_resource, $matches ) ) {
			return null;
		}

		$username = $matches[1];

		if ( 'agnosis' === $username ) {
			return [
				'subject' => $webfinger_resource,
				'links'   => [
					[
						'rel'  => 'self',
						'type' => 'application/activity+json',
						'href' => rest_url( 'agnosis/v1/activitypub/actor' ),
					],
				],
			];
		}

		$user = get_user_by( 'slug', $username );
		if ( $user && in_array( 'agnosis_artist', (array) $user->roles, true ) ) {
			return [
				'subject' => $webfinger_resource,
				'links'   => [
					[
						'rel'  => 'self',
						'type' => 'application/activity+json',
						'href' => $this->actor_url_for( 'artist', $user->ID ),
					],
				],
			];
		}

		return null;
	}

	/**
	 * Send a WebFinger (JRD) response and end the request — see
	 * handle_webfinger()'s docblock for why this can't just be wp_send_json().
	 *
	 * @param array<string, mixed> $data
	 */
	private function send_jrd_json( array $data, int $status ): void {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/jrd+json; charset=' . get_option( 'blog_charset' ) );
		}
		status_header( $status );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- machine-readable JSON body built by wp_json_encode(); HTML escaping would corrupt it.
		echo wp_json_encode( $data );
		exit;
	}

	/**
	 * The signing key and keyId for one local actor (audit §3h). Every
	 * delivery must be signed by the private key of the actor it's
	 * ATTRIBUTED to — an artist's Create should carry that artist's own
	 * signature and keyId, not the node's impersonating them, both because
	 * it's spec-correct and because §3b's own verify_actor_binding() now
	 * enforces exactly this symmetry on OUR side against inbound activities;
	 * a stricter federation partner may check the same thing on activities
	 * we send.
	 *
	 * @return array{0: string, 1: string} [private key PEM, keyId URL].
	 */
	public function signing_key_for( string $owner_type, int $owner_id ): array {
		$actor_url = $this->actor_url_for( $owner_type, $owner_id );

		if ( 'artist' === $owner_type && $owner_id > 0 ) {
			$keys = $this->ensure_artist_key_pair( $owner_id );
			return [ $keys['private'], $actor_url . '#main-key' ];
		}

		return [ (string) get_option( 'agnosis_private_key', '' ), $actor_url . '#main-key' ];
	}

	// -------------------------------------------------------------------------
	// Outbound addressing — the permalink a post federates under
	// (moved down from Network\ActivityPub at WP4; see the class docblock)
	// -------------------------------------------------------------------------

	/**
	 * The object id a post federated under — its published permalink.
	 *
	 * At Delete time the post may already carry a non-publish status (and,
	 * on slug conflict, a `__trashed`-suffixed name), which would make
	 * get_permalink() fall back to a query-var URL that never matched the
	 * Note's id. Resolve via a publish-status clone with the clean slug so
	 * core's own permalink logic produces the id the object was Created with.
	 */
	public function object_id_for( \WP_Post $post ): string {
		$proxy              = clone $post;
		$proxy->post_status = 'publish';
		$proxy->post_name   = preg_replace( '/__trashed$/', '', $post->post_name );

		return (string) get_permalink( $proxy );
	}

	// -------------------------------------------------------------------------
	// Inbound resolution — turning a federation URL back into a local entity
	// (moved down from Network\ActivityPub at WP3; see the class docblock)
	// -------------------------------------------------------------------------

	/**
	 * Resolve which local actor (the node, or a specific artist) an inbound
	 * Follow/Undo's `object` field names (audit §3h). Deliberately reads the
	 * ACTIVITY's own claimed target rather than trusting the URL the request
	 * happened to arrive at — spec-correct (an actor's `object` is the
	 * authoritative target, which is exactly what makes sharedInbox work at
	 * all: multiple local actors can share one URL and still be addressed
	 * individually), and means the dedicated per-artist inbox route and the
	 * shared node/global inbox route can run identical logic.
	 *
	 * @return array{type: string, id: int}|null Null when $object_url matches no known local actor.
	 */
	public function resolve_local_owner( string $object_url ): ?array {
		if ( '' === $object_url ) {
			return null;
		}

		$object_url = untrailingslashit( $object_url );

		if ( untrailingslashit( rest_url( 'agnosis/v1/activitypub/actor' ) ) === $object_url ) {
			return [ 'type' => 'node', 'id' => 0 ];
		}

		$artist_prefix = untrailingslashit( rest_url( 'agnosis/v1/activitypub/actor' ) ) . '/';
		if ( str_starts_with( $object_url, $artist_prefix ) ) {
			$id = (int) substr( $object_url, strlen( $artist_prefix ) );
			if ( $id > 0 ) {
				return [ 'type' => 'artist', 'id' => $id ];
			}
		}

		return null;
	}

	/**
	 * Resolve a Like/Announce/Undo activity's `object` URL back to a local
	 * artwork post id — the mirror image of resolve_local_owner() (which
	 * resolves an ACTOR url) and of object_id_for() (which builds this same
	 * permalink FROM a post going the other direction).
	 *
	 * Interaction-surface roadmap, Phase 1 (2026-07-24). Tries core's own
	 * url_to_postid() first — it reverses whatever rewrite rules are in
	 * effect for the 'art' permalink structure, so this doesn't need to
	 * duplicate that logic under pretty permalinks.
	 *
	 * **Real bug, caught by a real PHPUnit run (2026-07-24) — not just a test
	 * artifact.** url_to_postid() unconditionally returns 0 whenever
	 * `$wp_rewrite->wp_rewrite_rules()` is empty (its own "not using rewrite
	 * rules... out of options" early return) — i.e. under PLAIN permalinks,
	 * where no rewrite rules exist at all, only its hardcoded `p=`/`page_id=`/
	 * `attachment_id=` numeric-query fallback works. `get_post_permalink()`
	 * (what get_permalink()/object_id_for() actually call for this non-
	 * builtin CPT) falls back, under plain permalinks, to
	 * `add_query_arg( $post_type->query_var, $post->post_name, '' )` — i.e.
	 * `?agnosis_artwork=<slug>`, keyed by the CPT's own query var and the
	 * post's SLUG, not `p=<id>` — a shape url_to_postid() was never going to
	 * resolve. A remote Like/Announce/Undo sent back against exactly the
	 * permalink Agnosis itself published therefore silently failed to
	 * resolve on any site running plain permalinks, with inbox() still
	 * returning 200 (verify_inbox_signature() and the rest of inbox()'s own
	 * error handling never surfaces this — it just looks identical to "not
	 * ours to count"). Caught because the test suite defaults to plain
	 * permalinks and asserted the actual row got written, not just that
	 * inbox() didn't error.
	 *
	 * Fix: when url_to_postid() comes back empty, parse the URL's own query
	 * string for the agnosis_artwork query var directly and resolve that
	 * slug via get_page_by_path() (the standard core idiom for "resolve a
	 * post by slug for a given post type," despite the page-flavored name —
	 * used the same way regardless of post type elsewhere in core). This
	 * mirrors the outbound direction's own dual-mode correctness
	 * (test_note_id_equals_permalink_under_plain_permalinks() /
	 * ..._under_pretty_permalinks() already hold post_to_note() to both
	 * permalink modes) — the reverse direction needed the same rigor and
	 * didn't have it.
	 *
	 * Returns 0 (never a WP_Error/exception) for anything that isn't
	 * recognizably a local agnosis_artwork post — a Like on some other local
	 * page, or a URL that resolves to nothing at all — so callers can treat
	 * "not ours to count" as a plain, silent no-op.
	 *
	 * @param string $object_url The activity's `object` field (a permalink).
	 * @return int Post ID, or 0 if it doesn't resolve to a local artwork.
	 */
	public function resolve_local_post_id( string $object_url ): int {
		if ( '' === $object_url ) {
			return 0;
		}

		$post_id = url_to_postid( $object_url );

		if ( $post_id <= 0 ) {
			$query = (string) wp_parse_url( $object_url, PHP_URL_QUERY );
			wp_parse_str( $query, $query_vars );
			// wp_parse_str() types each value as string|array (parse_str()'s own
			// PHP semantics allow "foo[]=1&foo[]=2" to produce an array) — a
			// blind (string) cast on an array both fails PHPStan (invalid cast)
			// and would be wrong at runtime too (PHP casts an array to the
			// literal string "Array", not an error, so get_page_by_path()
			// would silently look up a bogus "Array" slug instead of treating
			// this as the no-slug case it actually is). is_string() guards both.
			$raw_slug = $query_vars['agnosis_artwork'] ?? '';
			$slug     = is_string( $raw_slug ) ? $raw_slug : '';

			if ( '' !== $slug ) {
				$post    = get_page_by_path( $slug, OBJECT, 'agnosis_artwork' );
				$post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
			}
		}

		if ( $post_id <= 0 ) {
			return 0;
		}

		return 'agnosis_artwork' === get_post_type( $post_id ) ? $post_id : 0;
	}

	// -------------------------------------------------------------------------
	// NodeInfo (audit §3h note ii) — the third discovery document, moved here at
	// WP7 to sit beside the node actor and WebFinger
	// -------------------------------------------------------------------------

	/**
	 * /.well-known/nodeinfo — points at the versioned document below. Kept as
	 * its own tiny discovery doc per spec, mirroring WebFinger's own
	 * well-known-rewrite-rule pattern in register_routes().
	 */
	public function handle_nodeinfo_discovery(): void {
		if ( ! get_query_var( 'agnosis_nodeinfo' ) ) {
			return;
		}

		wp_send_json( [
			'links' => [
				[
					'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
					'href' => rest_url( 'agnosis/v1/activitypub/nodeinfo' ),
				],
			],
		], 200 );
	}

	/**
	 * The NodeInfo 2.0 document itself — static, cheap, and the thing that
	 * makes this node visible to the Fediverse's own observatories/census
	 * tools, which was the whole point of the audit's note (an Agnosis node
	 * was previously invisible to that ecosystem even while being a working
	 * federation participant). `usage.users.total` now genuinely means
	 * something once per-artist actors exist — each admitted artist is a
	 * distinct fediverse-followable "user", not just an internal role.
	 */
	public function nodeinfo(): WP_REST_Response {
		$counts       = count_users();
		$artist_count = (int) ( $counts['avail_roles']['agnosis_artist'] ?? 0 );

		return new WP_REST_Response( [
			'version'   => '2.0',
			'software'  => [
				'name'    => 'agnosis',
				'version' => defined( 'AGNOSIS_VERSION' ) ? AGNOSIS_VERSION : '0.0.0',
			],
			'protocols' => [ 'activitypub' ],
			'services'  => [ 'inbound' => [], 'outbound' => [] ],
			'openRegistrations' => false,
			'usage'     => [
				'users'      => [ 'total' => $artist_count ],
				'localPosts' => (int) wp_count_posts( 'agnosis_artwork' )->publish,
			],
			// NodeInfo requires an OBJECT for metadata, even when empty — a
			// PHP [] would serialize as JSON `[]`, not the `{}` the schema
			// expects.
			'metadata'  => new \stdClass(),
		], 200, [ 'Content-Type' => 'application/json; profile="http://nodeinfo.diaspora.software/ns/schema/2.0#"' ] );
	}
}
