<?php
/**
 * ActivityPub implementation.
 *
 * Implements a minimal ActivityPub actor for the Agnosis node, making
 * published artworks discoverable from Mastodon, Pixelfed, and the broader
 * Fediverse without any central server.
 *
 * Endpoints:
 *   GET  /agnosis/v1/activitypub/actor          — node Actor object
 *   GET  /agnosis/v1/activitypub/outbox         — ordered collection of Create activities
 *   POST /agnosis/v1/activitypub/inbox          — receive Follow / Announce / Like
 *   GET  /agnosis/v1/activitypub/followers      — follower list
 *   GET  /.well-known/webfinger?resource=acct:* — WebFinger discovery
 *
 * @package Agnosis\Network
 */

declare(strict_types=1);

namespace Agnosis\Network;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ActivityPub {

	private const CONTEXT = 'https://www.w3.org/ns/activitystreams';

	public function register_routes(): void {
		$args = [ 'permission_callback' => '__return_true' ];

		register_rest_route( 'agnosis/v1', '/activitypub/actor',     array_merge( $args, [ 'methods' => 'GET',  'callback' => [ $this, 'actor'     ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/outbox',    array_merge( $args, [ 'methods' => 'GET',  'callback' => [ $this, 'outbox'    ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/followers', array_merge( $args, [ 'methods' => 'GET',  'callback' => [ $this, 'followers' ] ] ) );
		register_rest_route( 'agnosis/v1', '/activitypub/inbox',     [
			'methods'             => 'POST',
			'callback'            => [ $this, 'inbox' ],
			'permission_callback' => '__return_true',
		] );

		// WebFinger.
		add_filter( 'query_vars', fn( $v ) => array_merge( $v, [ 'agnosis_webfinger' ] ) );
		add_rewrite_rule( '^\.well-known/webfinger$', 'index.php?agnosis_webfinger=1', 'top' );
		add_action( 'template_redirect', [ $this, 'handle_webfinger' ] );
	}

	// -------------------------------------------------------------------------
	// Actor
	// -------------------------------------------------------------------------

	public function actor(): WP_REST_Response {
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
			'publicKey'         => [
				'id'           => $node_url . '#main-key',
				'owner'        => $node_url,
				'publicKeyPem' => $public_key,
			],
		], 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	// -------------------------------------------------------------------------
	// Outbox — recent artworks as Create activities
	// -------------------------------------------------------------------------

	public function outbox( WP_REST_Request $request ): WP_REST_Response {
		$page  = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$limit = 20;

		$posts = get_posts( [
			'post_type'      => 'agnosis_artwork',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => ( $page - 1 ) * $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$items = array_map( [ $this, 'post_to_activity' ], $posts );
		$total = (int) wp_count_posts( 'agnosis_artwork' )->publish;

		return new WP_REST_Response( [
			'@context'     => self::CONTEXT,
			'type'         => 'OrderedCollectionPage',
			'id'           => rest_url( 'agnosis/v1/activitypub/outbox' ) . '?page=' . $page,
			'partOf'       => rest_url( 'agnosis/v1/activitypub/outbox' ),
			'totalItems'   => $total,
			'orderedItems' => $items,
		], 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	// -------------------------------------------------------------------------
	// Inbox — receive Follow, Like, Announce
	// -------------------------------------------------------------------------

	public function inbox( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		$type = $body['type'] ?? '';

		switch ( $type ) {
			case 'Follow':
				return $this->handle_follow( $body );
			case 'Like':
			case 'Announce':
				do_action( 'agnosis_activity_received', $body );
				return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
			case 'Undo':
				return $this->handle_undo( $body );
			default:
				return new WP_REST_Response( [ 'status' => 'ignored', 'type' => $type ], 200 );
		}
	}

	public function followers(): WP_REST_Response {
		$followers = get_option( 'agnosis_ap_followers', [] );
		return new WP_REST_Response( [
			'@context'   => self::CONTEXT,
			'type'       => 'OrderedCollection',
			'id'         => rest_url( 'agnosis/v1/activitypub/followers' ),
			'totalItems' => count( $followers ),
			'orderedItems' => array_values( $followers ),
		], 200, [ 'Content-Type' => 'application/activity+json' ] );
	}

	// -------------------------------------------------------------------------
	// Broadcast a new artwork to all followers
	// -------------------------------------------------------------------------

	public function broadcast( int $post_id ): void {
		if ( ! (bool) get_option( 'agnosis_activitypub_enabled', true ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'agnosis_artwork' ) {
			return;
		}

		$activity  = $this->post_to_activity( $post );
		$followers = get_option( 'agnosis_ap_followers', [] );

		foreach ( $followers as $follower_inbox ) {
			$this->deliver( $follower_inbox, $activity );
		}
	}

	// -------------------------------------------------------------------------
	// WebFinger
	// -------------------------------------------------------------------------

	public function handle_webfinger(): void {
		if ( ! get_query_var( 'agnosis_webfinger' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WebFinger is a public unauthenticated discovery endpoint; nonces are not applicable.
		$resource = sanitize_text_field( wp_unslash( $_GET['resource'] ?? '' ) );
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$expected = 'acct:agnosis@' . $host;

		if ( $resource !== $expected ) {
			wp_send_json( [ 'error' => 'not found' ], 404 );
		}

		wp_send_json( [
			'subject' => $expected,
			'links'   => [
				[
					'rel'  => 'self',
					'type' => 'application/activity+json',
					'href' => rest_url( 'agnosis/v1/activitypub/actor' ),
				],
			],
		], 200 );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/** @return array<string, mixed> */
	private function post_to_activity( \WP_Post $post ): array {
		$actor     = rest_url( 'agnosis/v1/activitypub/actor' );
		$object_id = home_url( '/art/' . $post->post_name );
		$image_url = get_the_post_thumbnail_url( $post->ID, 'large' ) ?: '';

		$note = [
			'@context'     => self::CONTEXT,
			'type'         => 'Note',
			'id'           => $object_id,
			'url'          => get_permalink( $post->ID ),
			'attributedTo' => $actor,
			'name'         => wp_strip_all_tags( $post->post_title ),
			'content'      => wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 ),
			'published'    => gmdate( 'c', (int) strtotime( $post->post_date_gmt ) ),
			'to'           => [ 'https://www.w3.org/ns/activitystreams#Public' ],
		];

		if ( $image_url ) {
			$note['attachment'] = [
				[
					'type'      => 'Image',
					'url'       => $image_url,
					'mediaType' => 'image/jpeg',
				],
			];
		}

		return [
			'@context'  => self::CONTEXT,
			'type'      => 'Create',
			'id'        => $object_id . '#create',
			'actor'     => $actor,
			'published' => $note['published'],
			'to'        => $note['to'],
			'object'    => $note,
		];
	}

	/** @param array<string, mixed> $body */
	private function handle_follow( array $body ): WP_REST_Response {
		$follower_id    = $body['actor'] ?? '';
		$follower_inbox = $this->resolve_inbox( $follower_id );

		if ( $follower_inbox ) {
			$followers                    = get_option( 'agnosis_ap_followers', [] );
			$followers[ $follower_id ]    = $follower_inbox;
			update_option( 'agnosis_ap_followers', $followers );

			// Send Accept.
			$this->deliver( $follower_inbox, [
				'@context' => self::CONTEXT,
				'type'     => 'Accept',
				'id'       => rest_url( 'agnosis/v1/activitypub/actor' ) . '#accept-' . uniqid(),
				'actor'    => rest_url( 'agnosis/v1/activitypub/actor' ),
				'object'   => $body,
			] );
		}

		return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
	}

	/** @param array<string, mixed> $body */
	private function handle_undo( array $body ): WP_REST_Response {
		$object = $body['object'] ?? [];
		if ( ( $object['type'] ?? '' ) === 'Follow' ) {
			$follower_id = $body['actor'] ?? '';
			$followers   = get_option( 'agnosis_ap_followers', [] );
			unset( $followers[ $follower_id ] );
			update_option( 'agnosis_ap_followers', $followers );
		}
		return new WP_REST_Response( [ 'status' => 'accepted' ], 200 );
	}

	/** @param array<string, mixed> $activity */
	private function deliver( string $inbox_url, array $activity ): void {
		$body = wp_json_encode( $activity );
		if ( false === $body ) {
			return;
		}
		$date       = gmdate( 'D, d M Y H:i:s \G\M\T' );
		$private_key = get_option( 'agnosis_private_key', '' );
		$key_id     = rest_url( 'agnosis/v1/activitypub/actor' ) . '#main-key';

		$signature = '';
		if ( $private_key && function_exists( 'openssl_sign' ) ) {
			$signing_string = "(request-target): post " . wp_parse_url( $inbox_url, PHP_URL_PATH ) . "\nhost: " . wp_parse_url( $inbox_url, PHP_URL_HOST ) . "\ndate: " . $date;
			openssl_sign( $signing_string, $raw_sig, $private_key, OPENSSL_ALGO_SHA256 );
			$signature = 'keyId="' . $key_id . '",algorithm="rsa-sha256",headers="(request-target) host date",signature="' . base64_encode( $raw_sig ) . '"';
		}

		wp_remote_post( $inbox_url, [
			'timeout'    => 15,
			'blocking'   => false, // Fire and forget.
			'headers'    => array_filter( [
				'Content-Type' => 'application/activity+json',
				'Accept'       => 'application/activity+json',
				'Date'         => $date,
				'Signature'    => $signature ?: null,
			] ),
			'body'       => $body,
		] );
	}

	private function resolve_inbox( string $actor_url ): ?string {
		if ( empty( $actor_url ) ) {
			return null;
		}
		$response = wp_remote_get( $actor_url, [
			'headers' => [ 'Accept' => 'application/activity+json' ],
			'timeout' => 10,
		] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $data['inbox'] ) ? esc_url_raw( $data['inbox'] ) : null;
	}
}
