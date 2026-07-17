<?php
/**
 * Integration tests for Network\ActivityPub.
 *
 * Audit §3b: `resolve_inbox()` and `deliver()` fetch/post to actor and inbox
 * URLs that are entirely peer-supplied (an inbound Follow activity's "actor"
 * field, and the inbox URL advertised in that actor's own document). These
 * tests confirm both call sites use the "safe" wp_safe_remote_*() variants
 * by asserting `reject_unsafe_urls` is set on the outgoing request args —
 * that flag is what causes WP core to reject private/loopback/link-local/ULA
 * targets (and re-check on every redirect), so its presence is the
 * observable proof the SSRF guard is wired up. WP core's own test suite
 * already covers the IP-range rejection logic itself.
 *
 * Audit §3a: outbound deliveries must carry a `Digest` header covering the
 * body, and the HTTP Signature must cover exactly
 * `(request-target) host date digest` — Mastodon rejects any inbox POST whose
 * signature doesn't cover a Digest. The §3a tests pin the Digest value, the
 * exact signed-header list, and the full signing-string composition (by
 * verifying the emitted signature against the corresponding public key over a
 * reconstructed signing string), plus the new failure visibility: deliveries
 * are blocking and any non-2xx response is logged to Settings → Logs. The
 * ninth audit's test-coverage note asked for precisely this pin so the
 * signed-header list can't silently regress.
 *
 * Audit §3d: the outbox root (no `page` param) must be an `OrderedCollection`
 * naming `first`, with no inline items; a paged request keeps the existing
 * `OrderedCollectionPage` shape plus `next` while more items remain and
 * `prev` beyond page 1. Also updates published_artwork_note() — the shared
 * helper §3c/§3e/etc.'s tests use to read a Note back out of the outbox —
 * to request page 1 explicitly, since the root itself no longer inlines
 * `orderedItems`.
 *
 * Audit §3f: the Note enrichment pass — real attachment alt text and MIME
 * type (was hardcoded `image/jpeg`, no `name` at all), a `tag` array of AS2
 * Hashtag objects built from post_tag + agnosis_medium terms with matching
 * `#Name` strings appended to `content` (Mastodon indexes hashtags from the
 * content text, not the `tag` array), the artist's full AI-written
 * description in `content` instead of a flat 50-word truncation, and the
 * `sensitive`/`summary` lever (either the artist's own `_agnosis_sensitive`
 * post meta or the operator's per-medium `_agnosis_medium_sensitive` term
 * meta is enough — see is_post_sensitive()) — via a new private `note_for()`
 * helper that calls the private `post_to_note()` directly through
 * Reflection, since that's the unit actually under test.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Core\Logger;
use Agnosis\Network\ActivityPub;

class ActivityPubTest extends \WP_UnitTestCase {

	private const REMOTE_ACTOR_URL = 'https://mastodon.example/users/remoteartist';
	private const REMOTE_INBOX_URL = 'https://mastodon.example/users/remoteartist/inbox';

	/** A second, distinct remote follower — audit §3h tests need two independent followers to prove per-artist scoping and union delivery. */
	private const OTHER_ACTOR_URL = 'https://mastodon.example/users/otherfan';
	private const OTHER_INBOX_URL = 'https://mastodon.example/users/otherfan/inbox';

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'agnosis_private_key' );
		delete_option( 'agnosis_ap_tombstones' );
		// agnosis_followers / agnosis_ap_delivery_queue are real custom
		// tables, not options — WP_UnitTestCase's per-test transaction
		// rollback clears them the same way it already does for
		// agnosis_nodes, so no explicit cleanup is needed here.
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_ACCEPT'] );
		parent::tearDown();
	}

	/** Seed one row in the agnosis_followers table (audit §3g note iii). */
	private function seed_follower( string $actor_id, string $inbox_url ): void {
		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'agnosis_followers',
			[ 'actor_id' => $actor_id, 'inbox_url' => $inbox_url ],
			[ '%s', '%s' ]
		);
	}

	/** Read back one follower's stored inbox url, or null if not present. */
	private function stored_follower_inbox( string $actor_id ): ?string {
		global $wpdb;
		$inbox = $wpdb->get_var( $wpdb->prepare(
			"SELECT inbox_url FROM {$wpdb->prefix}agnosis_followers WHERE actor_id = %s",
			$actor_id
		) );
		return null === $inbox ? null : (string) $inbox;
	}

	/** Seed one row in agnosis_followers scoped to a specific owner (audit §3h). */
	private function seed_follower_for( string $owner_type, int $owner_id, string $actor_id, string $inbox_url ): void {
		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'agnosis_followers',
			[ 'owner_type' => $owner_type, 'owner_id' => $owner_id, 'actor_id' => $actor_id, 'inbox_url' => $inbox_url ],
			[ '%s', '%d', '%s', '%s' ]
		);
	}

	/** Create an admitted artist (agnosis_artist role) and return their user id — mirrors ContentEditorTest::create_artist(). */
	private function create_artist(): int {
		$id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_user_by( 'id', $id )->add_role( 'agnosis_artist' );
		return $id;
	}

	/**
	 * Generate a fresh RSA keypair and store it as one artist's own AP
	 * keypair (audit §3h) — the artist-usermeta equivalent of
	 * install_signing_key() below. Returns the public PEM for verification.
	 */
	private function install_artist_signing_key( int $user_id ): string {
		$key = openssl_pkey_new( [
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		] );
		$this->assertNotFalse( $key, 'Precondition: openssl must be able to mint an RSA keypair.' );

		openssl_pkey_export( $key, $private_pem );
		update_user_meta( $user_id, '_agnosis_ap_private_key', $private_pem );

		$public_pem = openssl_pkey_get_details( $key )['key'];
		update_user_meta( $user_id, '_agnosis_ap_public_key', $public_pem );

		return $public_pem;
	}

	/** Capture every outbound POST this test's HTTP filter sees, regardless of destination (audit §3h — union delivery needs to watch more than one inbox at once). */
	private function mock_all_deliveries( array &$deliveries ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$deliveries ) {
				if ( 'POST' === ( $args['method'] ?? '' ) ) {
					$deliveries[] = [
						'url'     => $url,
						'body'    => json_decode( (string) ( $args['body'] ?? '' ), true ),
						'headers' => $args['headers'] ?? [],
					];
					return [
						'response' => [ 'code' => 202, 'message' => '' ],
						'headers'  => [],
						'body'     => '',
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Register a pre_http_request filter that records reject_unsafe_urls per
	 * URL and serves a minimal valid response for both the actor-document GET
	 * and the inbox POST.
	 *
	 * @param array<string, bool|null> &$seen_reject_flag Keyed by URL substring ('actor'/'inbox'), populated as the filter observes requests.
	 */
	private function mock_transport( array &$seen_reject_flag ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$seen_reject_flag ) {
				// Check the inbox URL first: REMOTE_INBOX_URL is
				// REMOTE_ACTOR_URL + '/inbox', so REMOTE_ACTOR_URL is a
				// literal prefix of it — checking the actor branch first
				// would wrongly match every inbox request too.
				if ( strpos( $url, self::REMOTE_INBOX_URL ) !== false ) {
					$seen_reject_flag['inbox'] = $args['reject_unsafe_urls'] ?? null;
					return [
						'response' => [ 'code' => 202, 'message' => 'Accepted' ],
						'headers'  => [],
						'body'     => '',
						'cookies'  => [],
						'filename' => '',
					];
				}
				if ( strpos( $url, self::REMOTE_ACTOR_URL ) !== false ) {
					$seen_reject_flag['actor'] = $args['reject_unsafe_urls'] ?? null;
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [
							'type'  => 'Person',
							'id'    => self::REMOTE_ACTOR_URL,
							'inbox' => self::REMOTE_INBOX_URL,
						] ),
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Build a Follow-activity inbox request. Content-Type must be an explicit
	 * JSON media type — WP_REST_Request::get_json_params() only parses the
	 * body when is_json_content_type() is true; without this header
	 * ActivityPub::inbox() sees an empty $body and silently falls through to
	 * the "ignored" branch, never reaching handle_follow().
	 */
	private function build_follow_request(): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'Content-Type', 'application/activity+json' );
		$request->set_body( (string) wp_json_encode( [ 'type' => 'Follow', 'actor' => self::REMOTE_ACTOR_URL ] ) );
		return $request;
	}

	public function test_handle_follow_resolves_inbox_with_reject_unsafe_urls(): void {
		$seen = [];
		$this->mock_transport( $seen );

		$activitypub = new ActivityPub();
		$response    = $activitypub->inbox( $this->build_follow_request() );

		$this->assertSame( 'accepted', $response->get_data()['status'], 'Sanity check: handle_follow() must actually have run.' );
		$this->assertTrue( $seen['actor'] ?? false, 'resolve_inbox() must fetch the actor document via wp_safe_remote_get() (reject_unsafe_urls => true).' );
	}

	public function test_handle_follow_sends_accept_with_reject_unsafe_urls(): void {
		$seen = [];
		$this->mock_transport( $seen );

		$activitypub = new ActivityPub();
		$response    = $activitypub->inbox( $this->build_follow_request() );

		$this->assertSame( 'accepted', $response->get_data()['status'], 'Sanity check: handle_follow() must actually have run.' );
		$this->assertTrue( $seen['inbox'] ?? false, 'deliver() must POST the Accept via wp_safe_remote_post() (reject_unsafe_urls => true).' );
	}

	public function test_handle_follow_stores_resolved_inbox_and_accepts(): void {
		$seen = [];
		$this->mock_transport( $seen );

		$activitypub = new ActivityPub();
		$response    = $activitypub->inbox( $this->build_follow_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'accepted', $response->get_data()['status'] );

		$this->assertSame( self::REMOTE_INBOX_URL, $this->stored_follower_inbox( self::REMOTE_ACTOR_URL ) );
	}

	public function test_broadcast_delivers_to_stored_followers_with_reject_unsafe_urls(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		update_option( 'agnosis_activitypub_enabled', true );

		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		// Sanity-check every precondition broadcast() itself checks, so a
		// failure below points at the exact guard clause responsible instead
		// of leaving it to be re-diagnosed from a single assertion.
		$this->assertTrue( (bool) get_option( 'agnosis_activitypub_enabled' ), 'Precondition: agnosis_activitypub_enabled must be truthy.' );
		$post = get_post( $post_id );
		$this->assertNotNull( $post, 'Precondition: factory must have created the post.' );
		$this->assertSame( 'agnosis_artwork', $post->post_type, 'Precondition: post_type must be agnosis_artwork.' );
		$this->assertSame( self::REMOTE_INBOX_URL, $this->stored_follower_inbox( self::REMOTE_ACTOR_URL ), 'Precondition: seeded follower must round-trip as stored.' );

		$seen = [];
		$this->mock_transport( $seen );

		$activitypub = new ActivityPub();
		$activitypub->broadcast( $post_id );

		$this->assertTrue( $seen['inbox'] ?? false, 'broadcast() -> deliver() must POST via wp_safe_remote_post() (reject_unsafe_urls => true).' );
	}

	// -------------------------------------------------------------------------
	// Audit §3a — outbound Digest + signed-header pin + failure logging
	// -------------------------------------------------------------------------

	/**
	 * Register a pre_http_request filter that captures the full request args
	 * of the inbox POST and answers with the given status code.
	 *
	 * @param array<string, mixed> &$captured Populated with the inbox request's $args.
	 * @param int                  $code      HTTP status code to respond with.
	 */
	private function mock_inbox_capture( array &$captured, int $code = 202 ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$captured, $code ) {
				if ( strpos( $url, self::REMOTE_INBOX_URL ) !== false ) {
					$captured = $args;
					return [
						'response' => [ 'code' => $code, 'message' => '' ],
						'headers'  => [],
						'body'     => 401 === $code ? 'Mastodon requires the Digest header to be signed when doing a POST request' : '',
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Generate a fresh RSA keypair, store the private half where deliver()
	 * reads it, and return the public PEM for verification.
	 */
	private function install_signing_key(): string {
		$key = openssl_pkey_new( [
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		] );
		$this->assertNotFalse( $key, 'Precondition: openssl must be able to mint an RSA keypair.' );

		openssl_pkey_export( $key, $private_pem );
		update_option( 'agnosis_private_key', $private_pem );

		return openssl_pkey_get_details( $key )['key'];
	}

	/** Publish an artwork to a single stored follower and return the captured inbox request args. */
	private function broadcast_and_capture( int $code = 202 ): array {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		update_option( 'agnosis_activitypub_enabled', true );

		$post_id  = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		$captured = [];
		$this->mock_inbox_capture( $captured, $code );

		( new ActivityPub() )->broadcast( $post_id );

		$this->assertNotEmpty( $captured, 'Sanity check: the inbox POST must actually have fired.' );
		return $captured;
	}

	public function test_deliver_sends_digest_header_matching_body(): void {
		$captured = $this->broadcast_and_capture();

		$expected = 'SHA-256=' . base64_encode( hash( 'sha256', (string) $captured['body'], true ) );
		$this->assertSame( $expected, $captured['headers']['Digest'] ?? null, 'deliver() must send a Digest header that is the SHA-256 of the exact body sent.' );
	}

	public function test_deliver_is_blocking_so_failures_are_observable(): void {
		$captured = $this->broadcast_and_capture();

		$this->assertNotFalse( $captured['blocking'] ?? true, "deliver() must not pass 'blocking' => false — non-2xx responses can only be logged from a blocking request (audit §3a)." );
	}

	public function test_deliver_signature_pins_exact_signed_header_list(): void {
		$this->install_signing_key();
		$captured = $this->broadcast_and_capture();

		$signature_header = (string) ( $captured['headers']['Signature'] ?? '' );
		$this->assertNotSame( '', $signature_header, 'A stored private key must produce a Signature header.' );

		preg_match_all( '/(\w+)="([^"]*)"/', $signature_header, $matches, PREG_SET_ORDER );
		$params = [];
		foreach ( $matches as $match ) {
			$params[ $match[1] ] = $match[2];
		}

		// THE §3a pin: this exact list, in this exact order. Mastodon rejects
		// any inbox POST whose signature does not cover digest.
		$this->assertSame( '(request-target) host date digest', $params['headers'] ?? null, 'The signed-header list must be exactly "(request-target) host date digest".' );
		$this->assertSame( 'rsa-sha256', $params['algorithm'] ?? null );
		$this->assertSame( rest_url( 'agnosis/v1/activitypub/actor' ) . '#main-key', $params['keyId'] ?? null );
	}

	public function test_deliver_signature_verifies_over_signing_string_including_digest(): void {
		$public_pem = $this->install_signing_key();
		$captured   = $this->broadcast_and_capture();

		preg_match( '/signature="([^"]*)"/', (string) ( $captured['headers']['Signature'] ?? '' ), $match );
		$raw_signature = base64_decode( $match[1] ?? '', true );
		$this->assertNotFalse( $raw_signature, 'Signature value must be valid base64.' );

		// Reconstruct the signing string exactly as a receiving server
		// (HttpSignature::build_signing_string()'s mirror image) would from
		// the headers actually sent — this pins the signing-string
		// COMPOSITION, the precise place §3a lived undetected.
		$signing_string = '(request-target): post ' . wp_parse_url( self::REMOTE_INBOX_URL, PHP_URL_PATH )
			. "\nhost: " . wp_parse_url( self::REMOTE_INBOX_URL, PHP_URL_HOST )
			. "\ndate: " . ( $captured['headers']['Date'] ?? '' )
			. "\ndigest: " . ( $captured['headers']['Digest'] ?? '' );

		$this->assertSame( 1, openssl_verify( $signing_string, $raw_signature, $public_pem, OPENSSL_ALGO_SHA256 ), 'The emitted signature must verify over "(request-target) host date digest" of the headers actually sent.' );
	}

	public function test_deliver_logs_non_2xx_response(): void {
		Logger::clear();
		$this->broadcast_and_capture( 401 );

		$entries = array_values( array_filter( Logger::get_entries(), static fn( array $e ) => 'activitypub' === $e['context'] ) );
		$this->assertCount( 1, $entries, 'A rejected delivery must produce exactly one activitypub-context log entry.' );
		$this->assertSame( 'warning', $entries[0]['level'] );
		$this->assertStringContainsString( 'HTTP 401', $entries[0]['message'] );
		$this->assertStringContainsString( self::REMOTE_INBOX_URL, $entries[0]['message'], 'The log entry must name the inbox the delivery failed against.' );
	}

	public function test_deliver_does_not_log_on_2xx(): void {
		Logger::clear();
		$this->broadcast_and_capture( 202 );

		$entries = array_filter( Logger::get_entries(), static fn( array $e ) => 'activitypub' === $e['context'] );
		$this->assertCount( 0, $entries, 'A successful (2xx) delivery must not write a log entry.' );
	}

	// -------------------------------------------------------------------------
	// Ninth audit §3b — keyId↔actor binding wired into the inbox permission
	// callback. (Distinct from the earlier SSRF finding also labeled §3b in
	// its own cycle, referenced at the top of this file.)
	// -------------------------------------------------------------------------
	// HttpSignatureTest covers verify_actor_binding()'s own matrix; these two
	// pin the WIRING — verify_inbox_signature() must chain the binding check
	// after signature verification, otherwise the method exists but the inbox
	// route never runs it.

	/**
	 * Build an inbound inbox request correctly signed by a fresh key whose
	 * keyId lives at $key_actor_url, with a body claiming $actor_claim.
	 * Mocks the signer's actor document so fetch_public_key() succeeds.
	 */
	private function build_inbound_signed_request( string $actor_claim, string $key_actor_url ): \WP_REST_Request {
		$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		$this->assertNotFalse( $key, 'Precondition: openssl must be able to mint an RSA keypair.' );
		openssl_pkey_export( $key, $private_pem );
		$public_pem = openssl_pkey_get_details( $key )['key'];

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( $key_actor_url, $public_pem ) {
				if ( strpos( $url, $key_actor_url ) !== false ) {
					return [
						'response' => [ 'code' => 200, 'message' => 'OK' ],
						'headers'  => [],
						'body'     => (string) wp_json_encode( [ 'publicKey' => [ 'publicKeyPem' => $public_pem ] ] ),
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);

		$body   = (string) wp_json_encode( [ 'type' => 'Follow', 'actor' => $actor_claim ] );
		$date   = gmdate( 'D, d M Y H:i:s \G\M\T' );
		$digest = 'SHA-256=' . base64_encode( hash( 'sha256', $body, true ) );
		$path   = '/' . rest_get_url_prefix() . '/agnosis/v1/activitypub/inbox';
		$host   = (string) wp_parse_url( rest_url( '/' ), PHP_URL_HOST );

		$signing_string = "(request-target): post {$path}\nhost: {$host}\ndate: {$date}\ndigest: {$digest}";
		openssl_sign( $signing_string, $raw_sig, $private_pem, OPENSSL_ALGO_SHA256 );

		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'Content-Type', 'application/activity+json' );
		$request->set_header( 'date', $date );
		$request->set_header( 'host', $host );
		$request->set_header( 'digest', $digest );
		$request->set_header( 'signature', 'keyId="' . $key_actor_url . '#main-key",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . base64_encode( $raw_sig ) . '"' );
		$request->set_body( $body );

		return $request;
	}

	public function test_verify_inbox_signature_rejects_actor_forged_in_anothers_name(): void {
		// Correctly signed by the attacker's own valid key — the signature
		// itself verifies — but the body claims the victim's actor id.
		$request = $this->build_inbound_signed_request(
			'https://mastodon.social/users/victim',
			'https://attacker.example/users/mallory'
		);

		$result = ( new ActivityPub() )->verify_inbox_signature( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ap_actor_mismatch', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_verify_inbox_signature_accepts_matching_key_owner_and_actor(): void {
		$request = $this->build_inbound_signed_request( self::REMOTE_ACTOR_URL, self::REMOTE_ACTOR_URL );

		$this->assertTrue( ( new ActivityPub() )->verify_inbox_signature( $request ), 'A request whose keyId owner matches its actor claim must still pass end to end.' );
	}

	// -------------------------------------------------------------------------
	// Ninth audit §3c — object ids dereference: id from get_permalink(), and
	// content negotiation on artwork singulars.
	// -------------------------------------------------------------------------

	/**
	 * Create a published artwork and return [ Note object from the outbox, post id ].
	 *
	 * @return array{0: array<string, mixed>, 1: int}
	 */
	private function published_artwork_note(): array {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_title'  => 'Test Artwork',
		] );

		// The no-`page` root now returns an OrderedCollection with no inline
		// items (audit §3d) — page 1 is where orderedItems actually lives.
		$request = new \WP_REST_Request( 'GET', '/agnosis/v1/activitypub/outbox' );
		$request->set_param( 'page', 1 );
		$response = ( new ActivityPub() )->outbox( $request );
		$items    = $response->get_data()['orderedItems'];
		$this->assertNotEmpty( $items, 'Sanity check: the published artwork must appear in the outbox.' );

		return [ $items[0]['object'], $post_id ];
	}

	public function test_note_id_equals_permalink_under_plain_permalinks(): void {
		$this->set_permalink_structure( '' );

		[ $note, $post_id ] = $this->published_artwork_note();

		$this->assertSame( get_permalink( $post_id ), $note['id'], 'Note id must be the real permalink — under plain permalinks the old hardcoded /art/<slug> id 404ed.' );
		$this->assertSame( $note['id'], $note['url'], 'id and url must be the same URL in every permalink mode.' );
		$this->assertStringContainsString( 'agnosis_artwork=', $note['id'], 'Precondition: this test must actually be running under plain permalinks.' );
	}

	public function test_note_id_equals_permalink_under_pretty_permalinks(): void {
		$this->set_permalink_structure( '/%postname%/' );

		// register_post_type() only registers a CPT's permastruct when a
		// permalink structure exists AT REGISTRATION TIME (WP_Post_Type::
		// add_rewrite_rules() gates add_permastruct() on is_admin() ||
		// get_option('permalink_structure')). The bootstrap registered the
		// artwork CPT under the suite's default plain permalinks, so
		// re-register now that a structure is set — otherwise get_permalink()
		// still falls back to the ?agnosis_artwork= query form.
		( new \Agnosis\Artist\Profile() )->register_post_type();

		[ $note, $post_id ] = $this->published_artwork_note();

		$this->assertSame( get_permalink( $post_id ), $note['id'] );
		$this->assertSame( $note['id'], $note['url'] );
		$this->assertStringContainsString( '/art/', $note['id'], 'Precondition: the artwork CPT rewrite slug must be in effect.' );
	}

	public function test_singular_activity_json_serves_note_for_mastodon_accept_header(): void {
		update_option( 'agnosis_activitypub_enabled', true );
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		$this->go_to( get_permalink( $post_id ) );
		// Mastodon's actual Accept header when dereferencing an object.
		$_SERVER['HTTP_ACCEPT'] = 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

		$json = ( new ActivityPub() )->singular_activity_json();

		$this->assertNotNull( $json, 'An AP consumer dereferencing an artwork permalink must receive JSON.' );
		$note = json_decode( $json, true );
		$this->assertSame( 'Note', $note['type'] );
		$this->assertSame( get_permalink( $post_id ), $note['id'], 'The served object id must be the URL it was fetched from — id dereferences to the object.' );
	}

	public function test_singular_activity_json_serves_note_for_plain_ld_json_accept(): void {
		update_option( 'agnosis_activitypub_enabled', true );
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		$this->go_to( get_permalink( $post_id ) );
		$_SERVER['HTTP_ACCEPT'] = 'application/ld+json';

		$this->assertNotNull( ( new ActivityPub() )->singular_activity_json() );
	}

	public function test_singular_activity_json_declines_html_accept(): void {
		update_option( 'agnosis_activitypub_enabled', true );
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		$this->go_to( get_permalink( $post_id ) );
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

		$this->assertNull( ( new ActivityPub() )->singular_activity_json(), 'A browser request must fall through to the theme.' );
	}

	public function test_singular_activity_json_declines_non_artwork_singulars(): void {
		update_option( 'agnosis_activitypub_enabled', true );
		$post_id = self::factory()->post->create( [ 'post_type' => 'post', 'post_status' => 'publish' ] );

		$this->go_to( get_permalink( $post_id ) );
		$_SERVER['HTTP_ACCEPT'] = 'application/activity+json';

		$this->assertNull( ( new ActivityPub() )->singular_activity_json() );
	}

	// -------------------------------------------------------------------------
	// Ninth audit §3e — lifecycle federation: Delete + Tombstone (410), Update.
	// These drive the REAL WordPress lifecycle (wp_trash_post, wp_delete_post,
	// wp_update_post, thumbnail meta) so they exercise the Plugin.php wiring,
	// not just the handler methods.
	// -------------------------------------------------------------------------

	/**
	 * Collect every inbox POST's decoded activity.
	 *
	 * @param array<int, array<string, mixed>> &$deliveries Populated as deliveries fire.
	 */
	private function mock_inbox_collect( array &$deliveries ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$deliveries ) {
				if ( strpos( $url, self::REMOTE_INBOX_URL ) !== false ) {
					$deliveries[] = json_decode( (string) $args['body'], true );
					return [
						'response' => [ 'code' => 202, 'message' => '' ],
						'headers'  => [],
						'body'     => '',
						'cookies'  => [],
						'filename' => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/** Create a published artwork with one stored follower, return [ id, permalink ]. */
	private function published_artwork_with_follower(): array {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		update_option( 'agnosis_activitypub_enabled', true );

		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		return [ $post_id, get_permalink( $post_id ) ];
	}

	public function test_trashing_published_artwork_federates_delete_with_tombstone(): void {
		[ $post_id, $object_id ] = $this->published_artwork_with_follower();
		$deliveries              = [];
		$this->mock_inbox_collect( $deliveries );

		wp_trash_post( $post_id );

		$this->assertCount( 1, $deliveries, 'Trashing must federate exactly one activity (the wp_trash_post-internal post_updated must not add an Update).' );
		$this->assertSame( 'Delete', $deliveries[0]['type'] );
		$this->assertSame( 'Tombstone', $deliveries[0]['object']['type'] );
		$this->assertSame( $object_id, $deliveries[0]['object']['id'], 'The Tombstone id must be the object id the Note federated under — its published permalink.' );
		$this->assertSame( 'Note', $deliveries[0]['object']['formerType'] );
	}

	public function test_force_delete_of_published_artwork_federates_delete(): void {
		[ $post_id, $object_id ] = $this->published_artwork_with_follower();
		$deliveries              = [];
		$this->mock_inbox_collect( $deliveries );

		// Departure's path: wp_delete_post( id, true ) — bypasses trash and
		// never fires transition_post_status.
		wp_delete_post( $post_id, true );

		$this->assertCount( 1, $deliveries );
		$this->assertSame( 'Delete', $deliveries[0]['type'] );
		$this->assertSame( $object_id, $deliveries[0]['object']['id'] );
	}

	public function test_force_delete_from_trash_does_not_send_second_delete(): void {
		[ $post_id ] = $this->published_artwork_with_follower();
		$deliveries  = [];
		$this->mock_inbox_collect( $deliveries );

		wp_trash_post( $post_id );
		wp_delete_post( $post_id, true ); // Emptying the trash.

		$this->assertCount( 1, $deliveries, 'The trash already tombstoned this object; emptying the trash must not federate a duplicate Delete.' );
	}

	public function test_tombstone_json_served_for_ap_accept_after_trash(): void {
		[ $post_id, $object_id ] = $this->published_artwork_with_follower();
		$slug                    = get_post( $post_id )->post_name;

		wp_trash_post( $post_id );

		$this->go_to( add_query_arg( 'agnosis_artwork', $slug, home_url( '/' ) ) );
		$_SERVER['HTTP_ACCEPT'] = 'application/activity+json';

		$activitypub = new ActivityPub();
		$this->assertNull( $activitypub->singular_activity_json(), 'Precondition: the trashed artwork must no longer resolve as a live singular.' );

		$json = $activitypub->tombstone_activity_json();
		$this->assertNotNull( $json, 'A tombstoned slug must serve Tombstone JSON to an AP consumer.' );
		$tombstone = json_decode( $json, true );
		$this->assertSame( 'Tombstone', $tombstone['type'] );
		$this->assertSame( $object_id, $tombstone['id'], 'The served Tombstone id must be the pre-deletion object id.' );
	}

	public function test_tombstone_json_not_served_for_html_accept(): void {
		[ $post_id ] = $this->published_artwork_with_follower();
		$slug        = get_post( $post_id )->post_name;

		wp_trash_post( $post_id );

		$this->go_to( add_query_arg( 'agnosis_artwork', $slug, home_url( '/' ) ) );
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';

		$this->assertNull( ( new ActivityPub() )->tombstone_activity_json(), 'Browsers keep the theme\'s ordinary 404.' );
	}

	public function test_republish_clears_tombstone(): void {
		[ $post_id ] = $this->published_artwork_with_follower();
		$slug        = get_post( $post_id )->post_name;

		wp_trash_post( $post_id );
		$this->assertArrayHasKey( $slug, get_option( 'agnosis_ap_tombstones', [] ), 'Precondition: the trash must have recorded a tombstone.' );

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish', 'post_name' => $slug ] );

		$this->assertArrayNotHasKey( $slug, get_option( 'agnosis_ap_tombstones', [] ), 'A restored artwork dereferences again — its tombstone must be cleared.' );
	}

	public function test_title_edit_of_published_artwork_federates_update(): void {
		[ $post_id, $object_id ] = $this->published_artwork_with_follower();
		$deliveries              = [];
		$this->mock_inbox_collect( $deliveries );

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Corrected Title' ] );

		$this->assertCount( 1, $deliveries );
		$this->assertSame( 'Update', $deliveries[0]['type'] );
		$this->assertSame( 'Note', $deliveries[0]['object']['type'] );
		$this->assertSame( $object_id, $deliveries[0]['object']['id'] );
		$this->assertSame( 'Corrected Title', $deliveries[0]['object']['name'], 'The Update must carry the REFRESHED Note.' );
	}

	public function test_non_content_update_does_not_federate(): void {
		[ $post_id ] = $this->published_artwork_with_follower();
		$deliveries  = [];
		$this->mock_inbox_collect( $deliveries );

		wp_update_post( [ 'ID' => $post_id, 'menu_order' => 7 ] );

		$this->assertCount( 0, $deliveries, 'Only meaningful edits (title/content/excerpt/photo) federate an Update.' );
	}

	public function test_thumbnail_change_federates_update(): void {
		[ $post_id ] = $this->published_artwork_with_follower();
		$deliveries  = [];
		$this->mock_inbox_collect( $deliveries );

		// ContentEditor's photo replacement path is set_post_thumbnail(),
		// which only writes _thumbnail_id meta — no post_updated fires.
		update_post_meta( $post_id, '_thumbnail_id', 99999 );

		$this->assertCount( 1, $deliveries );
		$this->assertSame( 'Update', $deliveries[0]['type'] );
	}

	public function test_draft_artwork_edit_does_not_federate(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		update_option( 'agnosis_activitypub_enabled', true );
		$post_id    = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'draft' ] );
		$deliveries = [];
		$this->mock_inbox_collect( $deliveries );

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Still Unpublished' ] );

		$this->assertCount( 0, $deliveries );
	}

	public function test_language_sibling_lifecycle_does_not_federate(): void {
		[ $post_id ] = $this->published_artwork_with_follower();
		update_option( 'linguaforge_primary_language', 'en' );
		update_post_meta( $post_id, '_lf_lang', 'de' ); // A machine-translated sibling.

		$deliveries = [];
		$this->mock_inbox_collect( $deliveries );

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Übersetzter Titel' ] );
		wp_trash_post( $post_id );

		$this->assertCount( 0, $deliveries, 'Create only ever fires for the primary post — Delete/Update must mirror that scope.' );
		$this->assertSame( [], get_option( 'agnosis_ap_tombstones', [] ), 'A sibling was never federated, so it must not be tombstoned either.' );
	}

	public function test_singular_activity_json_declines_when_activitypub_disabled(): void {
		// Not `false`: update_option( $k, false ) is a silent no-op when the
		// option row doesn't exist (get_option() returns false for "missing",
		// old === new, nothing is written) — and the code's
		// get_option( ..., true ) would then still see the default `true`.
		// `0` persists as '0', which casts falsy like the settings form's own
		// unchecked value.
		update_option( 'agnosis_activitypub_enabled', 0 );
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		$this->go_to( get_permalink( $post_id ) );
		$_SERVER['HTTP_ACCEPT'] = 'application/activity+json';

		$this->assertNull( ( new ActivityPub() )->singular_activity_json(), 'The operator lever that gates broadcast() must gate dereferencing too.' );
	}

	// -------------------------------------------------------------------------
	// Audit §3d: outbox root shape and pagination
	// -------------------------------------------------------------------------

	/** Create $count published artworks, oldest first (so page ordering is deterministic by post_date). */
	private function create_artworks( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			self::factory()->post->create( [
				'post_type'   => 'agnosis_artwork',
				'post_status' => 'publish',
				'post_title'  => "Outbox Artwork {$i}",
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() - ( $count - $i ) ), // ascending; DESC query returns newest ($count-1) first.
			] );
		}
	}

	public function test_outbox_root_with_no_page_param_is_an_ordered_collection_naming_first(): void {
		$this->create_artworks( 3 );

		$response = ( new ActivityPub() )->outbox( new \WP_REST_Request( 'GET', '/agnosis/v1/activitypub/outbox' ) );
		$data     = $response->get_data();

		$this->assertSame( 'OrderedCollection', $data['type'] );
		$this->assertSame( rest_url( 'agnosis/v1/activitypub/outbox' ), $data['id'] );
		$this->assertSame( 3, $data['totalItems'] );
		$this->assertSame( rest_url( 'agnosis/v1/activitypub/outbox' ) . '?page=1', $data['first'] );
		$this->assertArrayNotHasKey( 'orderedItems', $data, 'The root must not inline items — it only points at page 1 via `first`.' );
		$this->assertArrayNotHasKey( 'partOf', $data, 'The root is not itself a page, so it has no partOf.' );
	}

	public function test_outbox_page_one_has_next_when_more_items_remain(): void {
		// limit is 20 per page; 25 items means page 1 is full and page 2 exists.
		$this->create_artworks( 25 );

		$request = new \WP_REST_Request( 'GET', '/agnosis/v1/activitypub/outbox' );
		$request->set_param( 'page', 1 );
		$data = ( new ActivityPub() )->outbox( $request )->get_data();

		$this->assertSame( 'OrderedCollectionPage', $data['type'] );
		$this->assertSame( rest_url( 'agnosis/v1/activitypub/outbox' ) . '?page=1', $data['id'] );
		$this->assertSame( rest_url( 'agnosis/v1/activitypub/outbox' ), $data['partOf'] );
		$this->assertCount( 20, $data['orderedItems'] );
		$this->assertSame( rest_url( 'agnosis/v1/activitypub/outbox' ) . '?page=2', $data['next'] );
		$this->assertArrayNotHasKey( 'prev', $data, 'Page 1 has no prev — the collection root (via `first`) is the entry point.' );
	}

	public function test_outbox_last_page_has_prev_and_no_next(): void {
		$this->create_artworks( 25 );

		$request = new \WP_REST_Request( 'GET', '/agnosis/v1/activitypub/outbox' );
		$request->set_param( 'page', 2 );
		$data = ( new ActivityPub() )->outbox( $request )->get_data();

		$this->assertCount( 5, $data['orderedItems'], 'Page 2 holds the remaining 5 of 25 items.' );
		$this->assertSame( rest_url( 'agnosis/v1/activitypub/outbox' ) . '?page=1', $data['prev'] );
		$this->assertArrayNotHasKey( 'next', $data, 'No items remain past page 2 of 25.' );
	}

	public function test_outbox_single_page_has_neither_next_nor_prev(): void {
		$this->create_artworks( 3 );

		$request = new \WP_REST_Request( 'GET', '/agnosis/v1/activitypub/outbox' );
		$request->set_param( 'page', 1 );
		$data = ( new ActivityPub() )->outbox( $request )->get_data();

		$this->assertCount( 3, $data['orderedItems'] );
		$this->assertArrayNotHasKey( 'next', $data );
		$this->assertArrayNotHasKey( 'prev', $data );
	}

	// -------------------------------------------------------------------------
	// Audit §3f: Note enrichment — alt text, mediaType, hashtags, full content
	// -------------------------------------------------------------------------

	/**
	 * Invoke the private post_to_note() directly — it's the unit actually
	 * under test here; post_to_activity() just wraps it in a Create envelope.
	 *
	 * @return array<string, mixed>
	 */
	private function note_for( int $post_id ): array {
		$rc     = new \ReflectionClass( ActivityPub::class );
		$method = $rc->getMethod( 'post_to_note' );
		$method->setAccessible( true );

		return $method->invoke( new ActivityPub(), get_post( $post_id ) );
	}

	public function test_note_attachment_carries_real_alt_text_and_mime_type(): void {
		$post_id       = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		$attachment_id = $this->create_real_image_attachment( 'image/png', 'A vivid orange sunset over rolling hills.' );
		set_post_thumbnail( $post_id, $attachment_id );

		$note = $this->note_for( $post_id );

		$this->assertSame( 'image/png', $note['attachment'][0]['mediaType'], 'The real attachment MIME type must be used, not a hardcoded image/jpeg.' );
		$this->assertSame( 'A vivid orange sunset over rolling hills.', $note['attachment'][0]['name'] );
	}

	public function test_note_attachment_omits_name_when_no_alt_text_is_set(): void {
		$post_id       = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		$attachment_id = $this->create_real_image_attachment( 'image/gif', '' );
		set_post_thumbnail( $post_id, $attachment_id );

		$note = $this->note_for( $post_id );

		$this->assertArrayNotHasKey( 'name', $note['attachment'][0], 'No alt text was set — the field must be omitted, not sent empty.' );
	}

	/**
	 * A real, fully-processed image attachment (real file + generated
	 * metadata), not a bare `factory()->attachment->create()` stub. WP
	 * core's `set_post_thumbnail()` calls `wp_get_attachment_image()`
	 * internally and silently *deletes* `_thumbnail_id` instead of setting
	 * it when that returns empty — which it does for an attachment with no
	 * real underlying file/metadata (the exact trap
	 * `ContentEditorTest::create_fake_attachment()` already documents and
	 * works around; caught here the same way after Ulises's real PHPUnit
	 * run hit "Undefined array key attachment" — set_post_thumbnail() had
	 * silently no-op'd on the original factory-stub attachments).
	 *
	 * Uses `Publishing\PostCreator::upload_media()`, the same real
	 * sideload path production code uses — `post_mime_type` on the
	 * resulting attachment is WP's own real-content-sniffed type (via
	 * `wp_check_filetype_and_ext()`), not just whatever `$mime` is passed,
	 * so the fixture bytes must actually match: a real 1x1 GIF for
	 * 'image/gif', a real 1x1 PNG for 'image/png'.
	 */
	private function create_real_image_attachment( string $mime, string $alt ): int {
		if ( 'image/png' === $mime ) {
			$binary   = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' );
			$filename = 'test.png';
		} else {
			$binary   = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7' );
			$filename = 'test.gif';
		}

		$id = ( new \Agnosis\Publishing\PostCreator() )->upload_media( $binary, $mime, $filename, $alt, 'Test Attachment', md5( $binary ) );

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	public function test_note_tag_array_and_content_carry_hashtags_from_tags_and_medium(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		wp_set_post_tags( $post_id, [ 'blue abstract' ] );
		wp_set_object_terms( $post_id, 'Oil Painting', 'agnosis_medium' );

		$note = $this->note_for( $post_id );

		$names = array_column( $note['tag'], 'name' );
		$this->assertContains( '#BlueAbstract', $names, 'Multi-word terms become CamelCase, not lowercase-concatenated.' );
		$this->assertContains( '#OilPainting', $names );

		foreach ( $note['tag'] as $tag ) {
			$this->assertSame( 'Hashtag', $tag['type'] );
			$this->assertNotEmpty( $tag['href'] );
		}

		// Mastodon indexes hashtags from the content text itself, not the tag array.
		$this->assertStringContainsString( '#BlueAbstract', $note['content'] );
		$this->assertStringContainsString( '#OilPainting', $note['content'] );
	}

	public function test_note_has_no_tag_key_when_post_has_no_terms(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		$note = $this->note_for( $post_id );

		$this->assertArrayNotHasKey( 'tag', $note );
	}

	public function test_note_content_carries_the_full_description_not_a_50_word_truncation(): void {
		// A paragraph deliberately longer than the old 50-word cap, inserted
		// exactly the way Publishing\PostCreator::build_post_content() does —
		// raw HTML, not wrapped in a Gutenberg block — next to a real
		// wp:gallery block for an attached image.
		$long_paragraph = '<p>' . implode( ' ', array_fill( 0, 80, 'word' ) ) . '.</p>';
		$gallery_block  = '<!-- wp:gallery {"ids":[1]} --><figure class="wp-block-gallery"><!-- wp:image {"id":1} --><figure class="wp-block-image"><img src="https://example.test/x.jpg" /></figure><!-- /wp:image --></figure><!-- /wp:gallery -->';

		$post_id = self::factory()->post->create( [
			'post_type'    => 'agnosis_artwork',
			'post_status'  => 'publish',
			'post_content' => $gallery_block . $long_paragraph,
		] );

		$note = $this->note_for( $post_id );

		$this->assertStringContainsString( $long_paragraph, $note['content'], 'The full paragraph must survive, not a 50-word truncation.' );
		$this->assertStringNotContainsString( 'wp-block-gallery', $note['content'], 'Gallery/image block markup must not leak into content — the image travels separately via `attachment`.' );
		$this->assertStringNotContainsString( '<!-- wp:', $note['content'], 'No raw block comments should remain in content.' );
	}

	public function test_note_content_falls_back_to_truncated_summary_when_no_freeform_text_exists(): void {
		// Content that is ENTIRELY a Gutenberg block, no freeform text at all —
		// the defensive fallback path.
		$post_id = self::factory()->post->create( [
			'post_type'    => 'agnosis_artwork',
			'post_status'  => 'publish',
			'post_content' => '<!-- wp:paragraph --><p>' . implode( ' ', array_fill( 0, 80, 'word' ) ) . '</p><!-- /wp:paragraph -->',
		] );

		$note = $this->note_for( $post_id );

		// wp_trim_words() default is 50 words + the '&hellip;' ellipsis marker.
		$this->assertLessThanOrEqual( 60, str_word_count( wp_strip_all_tags( $note['content'] ) ), 'Falls back to the truncated summary when parse_blocks() finds no freeform segment.' );
	}

	public function test_note_has_no_sensitive_key_by_default(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		$note = $this->note_for( $post_id );

		$this->assertArrayNotHasKey( 'sensitive', $note );
		$this->assertArrayNotHasKey( 'summary', $note );
	}

	public function test_note_is_sensitive_when_the_artist_flag_is_set(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_agnosis_sensitive', '1' );

		$note = $this->note_for( $post_id );

		$this->assertTrue( $note['sensitive'] );
		$this->assertNotEmpty( $note['summary'] );
	}

	public function test_note_is_sensitive_when_its_medium_is_flagged_by_default(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'Oil Painting', 'agnosis_medium' );
		$term = get_term_by( 'name', 'Oil Painting', 'agnosis_medium' );
		update_term_meta( $term->term_id, '_agnosis_medium_sensitive', '1' );

		$note = $this->note_for( $post_id );

		$this->assertTrue( $note['sensitive'], 'A medium flagged sensitive by the operator must mark every artwork under it, with no per-artwork flag needed.' );
	}

	public function test_note_not_sensitive_when_a_different_unflagged_medium_is_assigned(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'Photography', 'agnosis_medium' );
		$flagged_term = get_term_by( 'name', 'Oil Painting', 'agnosis_medium' ) ?: wp_insert_term( 'Oil Painting', 'agnosis_medium' );
		$flagged_id   = is_array( $flagged_term ) ? $flagged_term['term_id'] : $flagged_term->term_id;
		update_term_meta( $flagged_id, '_agnosis_medium_sensitive', '1' );

		$note = $this->note_for( $post_id );

		$this->assertArrayNotHasKey( 'sensitive', $note, 'Only the artwork\'s OWN assigned terms should be checked, not every flagged term in the taxonomy.' );
	}

	// -------------------------------------------------------------------------
	// Audit §3g note iii — followers table (replaces agnosis_ap_followers option)
	// -------------------------------------------------------------------------

	public function test_handle_undo_removes_stored_follower(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		$this->assertSame( self::REMOTE_INBOX_URL, $this->stored_follower_inbox( self::REMOTE_ACTOR_URL ), 'Precondition: follower must be seeded.' );

		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'Content-Type', 'application/activity+json' );
		$request->set_body( (string) wp_json_encode( [
			'type'   => 'Undo',
			'actor'  => self::REMOTE_ACTOR_URL,
			'object' => [ 'type' => 'Follow' ],
		] ) );

		$response = ( new ActivityPub() )->inbox( $request );

		$this->assertSame( 'accepted', $response->get_data()['status'] );
		$this->assertNull( $this->stored_follower_inbox( self::REMOTE_ACTOR_URL ), 'Undo of a Follow must remove the stored follower row.' );
	}

	public function test_handle_undo_ignores_non_follow_objects(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );

		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'Content-Type', 'application/activity+json' );
		$request->set_body( (string) wp_json_encode( [
			'type'   => 'Undo',
			'actor'  => self::REMOTE_ACTOR_URL,
			'object' => [ 'type' => 'Like' ],
		] ) );

		( new ActivityPub() )->inbox( $request );

		$this->assertSame( self::REMOTE_INBOX_URL, $this->stored_follower_inbox( self::REMOTE_ACTOR_URL ), 'An Undo of something other than a Follow must not remove the follower.' );
	}

	// -------------------------------------------------------------------------
	// Audit §2b (AUDIT-1.0.0.md) — Delete (remote account gone) and Move
	// (deliberately unhandled — recorded, not built)
	// -------------------------------------------------------------------------

	private function delete_request( string $actor, mixed $activity_object ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'Content-Type', 'application/activity+json' );
		$request->set_body( (string) wp_json_encode( [
			'type'   => 'Delete',
			'actor'  => $actor,
			'object' => $activity_object,
		] ) );
		return $request;
	}

	/** A Mastodon-shaped self-delete: object is a bare actor-URL string identical to actor. */
	public function test_handle_delete_removes_follower_when_object_is_the_signing_actor(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );

		$response = ( new ActivityPub() )->inbox( $this->delete_request( self::REMOTE_ACTOR_URL, self::REMOTE_ACTOR_URL ) );

		$this->assertSame( 'accepted', $response->get_data()['status'] );
		$this->assertNull( $this->stored_follower_inbox( self::REMOTE_ACTOR_URL ), 'A verified self-Delete must remove the actor\'s stored follower row.' );
	}

	/** Mastodon's real shape: object is an AS2 Tombstone, not a bare string. */
	public function test_handle_delete_removes_follower_via_tombstone_object(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );

		$response = ( new ActivityPub() )->inbox( $this->delete_request( self::REMOTE_ACTOR_URL, [
			'type' => 'Tombstone',
			'id'   => self::REMOTE_ACTOR_URL,
		] ) );

		$this->assertSame( 'accepted', $response->get_data()['status'] );
		$this->assertNull( $this->stored_follower_inbox( self::REMOTE_ACTOR_URL ), 'A Tombstone-shaped self-Delete must remove the actor\'s stored follower row, same as a bare-string object.' );
	}

	/** A Delete of some OTHER object (e.g. a remote post) is a different activity shape — must not touch followers. */
	public function test_handle_delete_ignores_object_that_is_not_the_actor_itself(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );

		$response = ( new ActivityPub() )->inbox( $this->delete_request( self::REMOTE_ACTOR_URL, self::REMOTE_ACTOR_URL . '/statuses/12345' ) );

		$this->assertSame( 'accepted', $response->get_data()['status'] );
		$this->assertSame( self::REMOTE_INBOX_URL, $this->stored_follower_inbox( self::REMOTE_ACTOR_URL ), 'A Delete of an object other than the actor itself must not remove the follower.' );
	}

	/** The remote actor no longer exists at all — every owner's row for it (node AND any artist) must go, unlike Undo's single-target scoping. */
	public function test_handle_delete_removes_rows_across_every_owner(): void {
		$artist_id = $this->create_artist();
		$this->seed_follower_for( 'node', 0, self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		$this->seed_follower_for( 'artist', $artist_id, self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );

		global $wpdb;
		$count_before = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_followers WHERE actor_id = %s",
			self::REMOTE_ACTOR_URL
		) );
		$this->assertSame( 2, $count_before, 'Precondition: the same actor must be following both the node and an artist.' );

		( new ActivityPub() )->inbox( $this->delete_request( self::REMOTE_ACTOR_URL, self::REMOTE_ACTOR_URL ) );

		$count_after = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_followers WHERE actor_id = %s",
			self::REMOTE_ACTOR_URL
		) );
		$this->assertSame( 0, $count_after, 'A self-Delete must remove every row for that actor_id, regardless of which local owner(s) it followed.' );
	}

	/** Move is deliberately unhandled at this scale (audit's own explicit call) — must return 'ignored' and leave the follower row alone. */
	public function test_move_activity_is_ignored_and_does_not_touch_followers(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );

		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'Content-Type', 'application/activity+json' );
		$request->set_body( (string) wp_json_encode( [
			'type'   => 'Move',
			'actor'  => self::REMOTE_ACTOR_URL,
			'object' => self::REMOTE_ACTOR_URL,
			'target' => 'https://mastodon.example/users/newhome',
		] ) );

		$response = ( new ActivityPub() )->inbox( $request );

		$this->assertSame( 'ignored', $response->get_data()['status'] );
		$this->assertSame( self::REMOTE_INBOX_URL, $this->stored_follower_inbox( self::REMOTE_ACTOR_URL ), 'Move is deliberately unhandled — the follower row must be untouched.' );
	}

	/**
	 * Regression test for audit §2a (AUDIT-1.0.0.md): the followers
	 * collection must publish actor IDs — resolvable actor documents, per
	 * AS2/ActivityPub — not the inbox-URL delivery-plumbing detail. A
	 * consumer dereferencing an item (Mastodon's follower-list rendering, a
	 * crawler, another node's follower-graph work) expects the former; the
	 * latter only answers signed POSTs and previously leaked which
	 * shared-inbox endpoint each follower delivers through.
	 */
	public function test_followers_endpoint_lists_stored_actor_ids(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		$this->seed_follower( 'https://mastodon.example/users/second', 'https://mastodon.example/users/second/inbox' );

		$request  = new \WP_REST_Request( 'GET', '/agnosis/v1/activitypub/followers' );
		$response = ( new ActivityPub() )->followers( $request );
		$data     = $response->get_data();

		$this->assertSame( 'OrderedCollection', $data['type'] );
		$this->assertSame( 2, $data['totalItems'] );
		$this->assertContains( self::REMOTE_ACTOR_URL, $data['orderedItems'], 'orderedItems must contain the follower\'s actor id.' );
		$this->assertNotContains( self::REMOTE_INBOX_URL, $data['orderedItems'], 'orderedItems must NOT contain the delivery-plumbing inbox URL.' );
	}

	public function test_handle_follow_upserts_rather_than_duplicates(): void {
		$seen = [];
		$this->mock_transport( $seen );

		$activitypub = new ActivityPub();
		$activitypub->inbox( $this->build_follow_request() );
		$activitypub->inbox( $this->build_follow_request() ); // Re-follow the same actor.

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_followers WHERE actor_id = %s",
			self::REMOTE_ACTOR_URL
		) );

		$this->assertSame( 1, $count, 'Re-following the same actor must update the existing row (UNIQUE KEY on actor_id), not insert a duplicate.' );
	}

	// -------------------------------------------------------------------------
	// Audit §3g note iv — delivery retry queue
	// -------------------------------------------------------------------------

	/** Read the sole delivery_queue row these tests seed/produce, or null. */
	private function sole_queue_row(): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test helper reading a small, test-only table.
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}agnosis_ap_delivery_queue ORDER BY id ASC" );
		return $rows[0] ?? null;
	}

	/** Seed a delivery_queue row directly, bypassing enqueue_delivery_retry(). */
	private function seed_queue_row( int $attempts, string $next_attempt_at ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_ap_delivery_queue',
			[
				'inbox_url'       => self::REMOTE_INBOX_URL,
				'activity_type'   => 'Create',
				'activity_json'   => (string) wp_json_encode( [ 'type' => 'Create' ] ),
				'attempts'        => $attempts,
				'next_attempt_at' => $next_attempt_at,
			],
			[ '%s', '%s', '%s', '%d', '%s' ]
		);
	}

	public function test_failed_live_delivery_enqueues_a_retry_row(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		update_option( 'agnosis_activitypub_enabled', true );

		$captured = [];
		$this->mock_inbox_capture( $captured, 500 );
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new ActivityPub() )->broadcast( $post_id );

		$row = $this->sole_queue_row();
		$this->assertNotNull( $row, 'A failed live delivery must enqueue exactly one retry row.' );
		$this->assertSame( self::REMOTE_INBOX_URL, $row->inbox_url );
		$this->assertSame( 'pending', $row->status );
		$this->assertSame( 0, (int) $row->attempts );
		$this->assertSame( 'Create', $row->activity_type );

		$seconds_out = strtotime( $row->next_attempt_at . ' UTC' ) - time();
		$this->assertGreaterThan( 0, $seconds_out, 'The first retry must be scheduled in the future.' );
		$this->assertLessThanOrEqual( 5 * MINUTE_IN_SECONDS, $seconds_out, 'The first retry interval is 5 minutes.' );
	}

	// -------------------------------------------------------------------------
	// Audit §2b (AUDIT-1.0.0.md) — a definitive 410 Gone/404 Not Found skips
	// the retry queue's multi-day backoff entirely, on both the live-delivery
	// path (below) and the retry-queue processor (further below).
	// -------------------------------------------------------------------------

	public function test_failed_live_delivery_with_410_records_dead_delivery_instead_of_enqueueing_retry(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		update_option( 'agnosis_activitypub_enabled', true );

		$captured = [];
		$this->mock_inbox_capture( $captured, 410 );
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new ActivityPub() )->broadcast( $post_id );

		$row = $this->sole_queue_row();
		$this->assertNotNull( $row, 'A definitively dead inbox must still leave a queryable record.' );
		$this->assertSame( 'failed', $row->status, 'A 410 Gone must record straight to the terminal failed state, not "pending".' );
		$this->assertSame( 0, (int) $row->attempts, 'No retry cycle was ever spent — this never entered the pending/backoff loop at all.' );
		$this->assertNotNull( $row->resolved_at );
		$this->assertStringContainsString( 'HTTP 410', (string) $row->last_error );
	}

	/** Same fast-path, the other named code (404 Not Found). */
	public function test_failed_live_delivery_with_404_records_dead_delivery_instead_of_enqueueing_retry(): void {
		$this->seed_follower( self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		update_option( 'agnosis_activitypub_enabled', true );

		$captured = [];
		$this->mock_inbox_capture( $captured, 404 );
		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		( new ActivityPub() )->broadcast( $post_id );

		$row = $this->sole_queue_row();
		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row->status );
		$this->assertStringContainsString( 'HTTP 404', (string) $row->last_error );
	}

	public function test_retry_processor_deletes_row_on_success(): void {
		$this->seed_queue_row( 1, gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		$seen = [];
		$this->mock_transport( $seen ); // Answers the inbox URL with 202.

		( new ActivityPub() )->process_delivery_retry_queue();

		$this->assertNull( $this->sole_queue_row(), 'A succeeding retry must delete its queue row.' );
	}

	public function test_retry_processor_skips_rows_not_yet_due(): void {
		$this->seed_queue_row( 0, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		$fetch_count = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$fetch_count ) {
				if ( strpos( $url, self::REMOTE_INBOX_URL ) !== false ) {
					$fetch_count++;
				}
				return $preempt;
			},
			10,
			3
		);

		( new ActivityPub() )->process_delivery_retry_queue();

		$this->assertSame( 0, $fetch_count, 'A row whose next_attempt_at is in the future must not be retried yet.' );
		$this->assertNotNull( $this->sole_queue_row(), 'The not-yet-due row must remain queued.' );
	}

	public function test_retry_processor_advances_backoff_on_repeated_failure(): void {
		$this->seed_queue_row( 0, gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'http_request_failed', 'Connection refused' ) );

		( new ActivityPub() )->process_delivery_retry_queue();

		$row = $this->sole_queue_row();
		$this->assertNotNull( $row );
		$this->assertSame( 'pending', $row->status );
		$this->assertSame( 1, (int) $row->attempts );

		// Second entry in RETRY_INTERVALS (index 1) is 30 minutes.
		$seconds_out = strtotime( $row->next_attempt_at . ' UTC' ) - time();
		$this->assertGreaterThan( 25 * MINUTE_IN_SECONDS, $seconds_out );
		$this->assertLessThanOrEqual( 30 * MINUTE_IN_SECONDS, $seconds_out );
	}

	public function test_retry_processor_marks_row_failed_after_exhausting_every_interval(): void {
		// RETRY_INTERVALS has 6 entries; seeding attempts=5 means this failure
		// (advancing it to 6) exhausts every interval.
		$this->seed_queue_row( 5, gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'http_request_failed', 'Connection refused' ) );

		Logger::clear();
		( new ActivityPub() )->process_delivery_retry_queue();

		$row = $this->sole_queue_row();
		$this->assertNotNull( $row, 'An exhausted row is kept as a terminal failure record, not deleted.' );
		$this->assertSame( 'failed', $row->status );
		$this->assertSame( 6, (int) $row->attempts );
		$this->assertNotNull( $row->resolved_at );

		$entries = array_values( array_filter( Logger::get_entries(), static fn( array $e ) => 'activitypub' === $e['context'] ) );
		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( 'permanently failed', $entries[0]['message'] );
	}

	/**
	 * The retry-queue half of the 410/404 fast-path: a queued row's very
	 * FIRST retry attempt (attempts=0 going in, nowhere near exhausting
	 * RETRY_INTERVALS' 6 entries) must still jump straight to 'failed' when
	 * the response is a definitive 410 — contrast with
	 * test_retry_processor_advances_backoff_on_repeated_failure, where a
	 * plain connection error at the same starting point correctly advances
	 * to the next backoff interval instead.
	 */
	public function test_retry_processor_marks_row_failed_immediately_on_410_regardless_of_remaining_attempts(): void {
		$this->seed_queue_row( 0, gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		$captured = [];
		$this->mock_inbox_capture( $captured, 410 );

		( new ActivityPub() )->process_delivery_retry_queue();

		$row = $this->sole_queue_row();
		$this->assertNotNull( $row, 'An immediately-dead row is kept as a terminal failure record, not deleted.' );
		$this->assertSame( 'failed', $row->status );
		$this->assertSame( 1, (int) $row->attempts, 'Exactly one attempt was made — the fast-path skips the remaining backoff interval count, not the attempt itself.' );
		$this->assertNotNull( $row->resolved_at );
	}

	// -------------------------------------------------------------------------
	// Claim-then-read concurrency (security audit §2c) — closes deferred-test
	// debt flagged by AUDIT-1.0.0.md §4d, mirroring
	// QueueProcessorTest's equivalent section for the newsletter queue's
	// identical mechanism. Every test above only exercises normal,
	// non-overlapping single-call behavior; these simulate a second,
	// concurrently-running tick directly (writing 'claimed' rows this call
	// did not itself claim) to prove the mechanism really prevents
	// duplicate deliveries.
	// -------------------------------------------------------------------------

	/**
	 * Force the sole delivery-queue row's status/claim fields directly,
	 * bypassing process_delivery_retry_queue()'s own claim UPDATE —
	 * simulates a row already claimed by a different, still-in-flight
	 * overlapping tick (or one abandoned mid-run).
	 */
	private function force_queue_claim( int $row_id, string $status, ?string $claim_token, ?string $claimed_at ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'agnosis_ap_delivery_queue',
			[ 'status' => $status, 'claim_token' => $claim_token, 'claimed_at' => $claimed_at ],
			[ 'id' => $row_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public function test_retry_processor_does_not_touch_a_row_already_claimed_by_a_concurrent_tick(): void {
		$this->seed_queue_row( 0, gmdate( 'Y-m-d H:i:s', time() - 60 ) ); // due now
		$row = $this->sole_queue_row();

		// Simulate a different, still-in-flight tick having already claimed
		// this row moments ago — the claim UPDATE only ever targets
		// status = 'pending', so it must skip this row entirely.
		$foreign_token = wp_generate_uuid4();
		$this->force_queue_claim( (int) $row->id, 'claimed', $foreign_token, current_time( 'mysql', true ) );

		$fetch_count = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$fetch_count ) {
				if ( strpos( $url, self::REMOTE_INBOX_URL ) !== false ) {
					$fetch_count++;
				}
				return $preempt;
			},
			10,
			3
		);

		( new ActivityPub() )->process_delivery_retry_queue();

		$this->assertSame( 0, $fetch_count, 'A row claimed by another in-flight tick must not be re-claimed and delivered by this one.' );

		$row_after = $this->sole_queue_row();
		$this->assertSame( 'claimed', $row_after->status );
		$this->assertSame( $foreign_token, $row_after->claim_token, 'The foreign claim must survive untouched — process_delivery_retry_queue() must never steal a live claim.' );
	}

	public function test_retry_processor_reset_stale_claims_recovers_an_abandoned_claim(): void {
		$this->seed_queue_row( 0, gmdate( 'Y-m-d H:i:s', time() - 60 ) ); // due now
		$row = $this->sole_queue_row();

		// A process that claimed this row 31 minutes ago and then died
		// mid-batch (past STALE_CLAIM_MINUTES = 30) — reset_stale_delivery_claims(),
		// run at the top of every call, must return it to 'pending' so this
		// same tick can actually deliver it.
		$stale_claimed_at = gmdate( 'Y-m-d H:i:s', time() - 31 * MINUTE_IN_SECONDS );
		$this->force_queue_claim( (int) $row->id, 'claimed', wp_generate_uuid4(), $stale_claimed_at );

		$seen = [];
		$this->mock_transport( $seen ); // Answers the inbox URL with 202.

		( new ActivityPub() )->process_delivery_retry_queue();

		$this->assertNull( $this->sole_queue_row(), 'An abandoned (stale) claim must be recovered and, once it succeeds, deleted like any other successful retry.' );
	}

	public function test_retry_processor_reset_stale_claims_leaves_a_fresh_claim_untouched(): void {
		$this->seed_queue_row( 0, gmdate( 'Y-m-d H:i:s', time() - 60 ) ); // due now
		$row = $this->sole_queue_row();

		// 5 minutes old — well within STALE_CLAIM_MINUTES = 30. A genuinely
		// in-flight claim must not be mistaken for an abandoned one.
		$fresh_token      = wp_generate_uuid4();
		$fresh_claimed_at = gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS );
		$this->force_queue_claim( (int) $row->id, 'claimed', $fresh_token, $fresh_claimed_at );

		$seen = [];
		$this->mock_transport( $seen );

		( new ActivityPub() )->process_delivery_retry_queue();

		$row_after = $this->sole_queue_row();
		$this->assertNotNull( $row_after, 'A fresh claim must not be reset — the row must not vanish as if it had been (re-)delivered.' );
		$this->assertSame( 'claimed', $row_after->status );
		$this->assertSame( $fresh_token, $row_after->claim_token );
	}

	// -------------------------------------------------------------------------
	// Audit §3h — per-artist actors
	// -------------------------------------------------------------------------

	private function actor_request( ?int $artist_id = null ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/agnosis/v1/activitypub/actor' );
		if ( null !== $artist_id ) {
			$request->set_param( 'artist_id', (string) $artist_id );
		}
		return $request;
	}

	public function test_artist_actor_returns_404_for_non_artist_user(): void {
		$plain_user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$response = ( new ActivityPub() )->actor( $this->actor_request( $plain_user_id ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'ap_actor_not_found', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] ?? null );
	}

	public function test_artist_actor_returns_404_for_nonexistent_user(): void {
		$response = ( new ActivityPub() )->actor( $this->actor_request( 999999 ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'ap_actor_not_found', $response->get_error_code() );
	}

	public function test_artist_actor_document_shape(): void {
		$artist_id = $this->create_artist();
		$this->install_artist_signing_key( $artist_id );

		$response = ( new ActivityPub() )->actor( $this->actor_request( $artist_id ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$user = get_userdata( $artist_id );

		$this->assertSame( 'Person', $data['type'], 'An artist actor must present as a Person, not the node\'s Service.' );
		$this->assertSame( $user->user_nicename, $data['preferredUsername'] );
		$this->assertStringEndsWith( '/activitypub/actor/' . $artist_id, $data['id'] );
		$this->assertStringEndsWith( '/activitypub/actor/' . $artist_id . '/inbox', $data['inbox'] );
		$this->assertStringEndsWith( '/activitypub/actor/' . $artist_id . '/outbox', $data['outbox'] );
		$this->assertStringEndsWith( '/activitypub/actor/' . $artist_id . '/followers', $data['followers'] );
		$this->assertSame( rest_url( 'agnosis/v1/activitypub/inbox' ), $data['endpoints']['sharedInbox'], 'sharedInbox must point at the one global inbox route, same as the node\'s.' );
		$this->assertNotEmpty( $data['publicKey']['publicKeyPem'], 'The artist\'s own key must be exposed, not left empty or the node\'s.' );
	}

	public function test_artist_outbox_scopes_to_that_artists_own_published_artworks(): void {
		$artist_a = $this->create_artist();
		$artist_b = $this->create_artist();
		self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish', 'post_author' => $artist_a ] );
		self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish', 'post_author' => $artist_a ] );
		self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish', 'post_author' => $artist_b ] );

		$request = new \WP_REST_Request( 'GET', '/agnosis/v1/activitypub/actor/' . $artist_a . '/outbox' );
		$request->set_param( 'artist_id', (string) $artist_a );

		$response = ( new ActivityPub() )->outbox( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		$this->assertSame( 2, $response->get_data()['totalItems'], 'A per-artist outbox root must count only that artist\'s own published artworks.' );
	}

	public function test_artist_outbox_page_only_contains_that_artists_own_posts(): void {
		$artist_a  = $this->create_artist();
		$artist_b  = $this->create_artist();
		$a_post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish', 'post_author' => $artist_a ] );
		self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish', 'post_author' => $artist_b ] );

		$request = new \WP_REST_Request( 'GET', '/agnosis/v1/activitypub/actor/' . $artist_a . '/outbox' );
		$request->set_param( 'artist_id', (string) $artist_a );
		$request->set_param( 'page', '1' );

		$response = ( new ActivityPub() )->outbox( $request );
		$items    = $response->get_data()['orderedItems'];

		$this->assertCount( 1, $items, 'The page must only contain artist A\'s own post.' );
		$this->assertSame( get_permalink( $a_post_id ), $items[0]['object']['id'] );
	}

	public function test_artist_followers_endpoint_only_lists_that_artists_own_followers(): void {
		$artist_id = $this->create_artist();
		$this->seed_follower_for( 'node', 0, self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		$this->seed_follower_for( 'artist', $artist_id, self::OTHER_ACTOR_URL, self::OTHER_INBOX_URL );

		$request = new \WP_REST_Request( 'GET', '/agnosis/v1/activitypub/actor/' . $artist_id . '/followers' );
		$request->set_param( 'artist_id', (string) $artist_id );

		$response = ( new ActivityPub() )->followers( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['totalItems'] );
		$this->assertSame( [ self::OTHER_ACTOR_URL ], $data['orderedItems'], 'Must list only this artist\'s own follower (by actor id), not the node\'s.' );
	}

	public function test_webfinger_resolves_node_handle(): void {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$result = ( new ActivityPub() )->resolve_webfinger_subject( 'acct:agnosis@' . $host );

		$this->assertNotNull( $result );
		$this->assertSame( rest_url( 'agnosis/v1/activitypub/actor' ), $result['links'][0]['href'] );
	}

	public function test_webfinger_resolves_artist_handle(): void {
		$artist_id = $this->create_artist();
		$nicename  = get_userdata( $artist_id )->user_nicename;
		$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$result = ( new ActivityPub() )->resolve_webfinger_subject( 'acct:' . $nicename . '@' . $host );

		$this->assertNotNull( $result );
		$this->assertStringEndsWith( '/activitypub/actor/' . $artist_id, $result['links'][0]['href'] );
	}

	public function test_webfinger_does_not_resolve_non_artist_user_slug(): void {
		$plain_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$nicename = get_userdata( $plain_id )->user_nicename;
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$result = ( new ActivityPub() )->resolve_webfinger_subject( 'acct:' . $nicename . '@' . $host );

		$this->assertNull( $result, 'A user without the agnosis_artist role must not resolve via WebFinger.' );
	}

	public function test_webfinger_returns_null_for_wrong_host(): void {
		$result = ( new ActivityPub() )->resolve_webfinger_subject( 'acct:agnosis@not-this-site.example' );

		$this->assertNull( $result );
	}

	public function test_follow_targeting_artist_object_stores_under_artist_owner(): void {
		$artist_id = $this->create_artist();
		$seen      = [];
		$this->mock_transport( $seen );

		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/actor/' . $artist_id . '/inbox' );
		$request->set_header( 'Content-Type', 'application/activity+json' );
		$request->set_body( (string) wp_json_encode( [
			'type'   => 'Follow',
			'actor'  => self::REMOTE_ACTOR_URL,
			'object' => rest_url( 'agnosis/v1/activitypub/actor/' . $artist_id ),
		] ) );

		( new ActivityPub() )->inbox( $request );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}agnosis_followers WHERE actor_id = %s",
			self::REMOTE_ACTOR_URL
		) );

		$this->assertNotNull( $row, 'A Follow naming the artist\'s actor as object must store a followers row.' );
		$this->assertSame( 'artist', $row->owner_type );
		$this->assertSame( $artist_id, (int) $row->owner_id );
	}

	public function test_follow_with_unrecognized_object_falls_back_to_node(): void {
		$seen = [];
		$this->mock_transport( $seen );

		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/inbox' );
		$request->set_header( 'Content-Type', 'application/activity+json' );
		$request->set_body( (string) wp_json_encode( [
			'type'   => 'Follow',
			'actor'  => self::REMOTE_ACTOR_URL,
			'object' => 'https://not-a-known-local-actor.example/whoever',
		] ) );

		( new ActivityPub() )->inbox( $request );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}agnosis_followers WHERE actor_id = %s",
			self::REMOTE_ACTOR_URL
		) );

		$this->assertNotNull( $row );
		$this->assertSame( 'node', $row->owner_type, 'An unrecognized Follow object must default to the node, not silently drop the follow.' );
	}

	public function test_undo_targeting_artist_removes_only_that_artists_follower(): void {
		$artist_id = $this->create_artist();
		$this->seed_follower_for( 'node', 0, self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		$this->seed_follower_for( 'artist', $artist_id, self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );

		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/activitypub/actor/' . $artist_id . '/inbox' );
		$request->set_header( 'Content-Type', 'application/activity+json' );
		$request->set_body( (string) wp_json_encode( [
			'type'   => 'Undo',
			'actor'  => self::REMOTE_ACTOR_URL,
			'object' => [
				'type'   => 'Follow',
				'object' => rest_url( 'agnosis/v1/activitypub/actor/' . $artist_id ),
			],
		] ) );

		( new ActivityPub() )->inbox( $request );

		global $wpdb;
		$remaining = $wpdb->get_col( $wpdb->prepare(
			"SELECT owner_type FROM {$wpdb->prefix}agnosis_followers WHERE actor_id = %s",
			self::REMOTE_ACTOR_URL
		) );

		$this->assertSame( [ 'node' ], $remaining, 'Undo targeting the artist must remove only the artist-scoped row, leaving the node-scoped one alone.' );
	}

	public function test_broadcast_attributes_note_to_artist_and_delivers_union_audience_deduped(): void {
		$artist_id = $this->create_artist();
		$this->install_artist_signing_key( $artist_id );
		update_option( 'agnosis_activitypub_enabled', true );

		// Node follower and artist follower are DIFFERENT remote actors at
		// DIFFERENT inboxes — both must receive a copy.
		$this->seed_follower_for( 'node', 0, self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		$this->seed_follower_for( 'artist', $artist_id, self::OTHER_ACTOR_URL, self::OTHER_INBOX_URL );

		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish', 'post_author' => $artist_id ] );

		$deliveries = [];
		$this->mock_all_deliveries( $deliveries );

		( new ActivityPub() )->broadcast( $post_id );

		$this->assertCount( 2, $deliveries, 'Both the node\'s own follower and the artist\'s own follower must receive a copy.' );

		$urls = array_column( $deliveries, 'url' );
		$this->assertContains( self::REMOTE_INBOX_URL, $urls );
		$this->assertContains( self::OTHER_INBOX_URL, $urls );

		foreach ( $deliveries as $delivery ) {
			$this->assertStringEndsWith( '/activitypub/actor/' . $artist_id, $delivery['body']['actor'], 'The Create must be attributed to the ARTIST\'s actor, not the node\'s, even when delivered to the node\'s own follower.' );
			$this->assertStringEndsWith( '/activitypub/actor/' . $artist_id, $delivery['body']['object']['attributedTo'] );
		}
	}

	public function test_broadcast_delivers_once_when_the_same_follower_follows_both_node_and_artist(): void {
		$artist_id = $this->create_artist();
		$this->install_artist_signing_key( $artist_id );
		update_option( 'agnosis_activitypub_enabled', true );

		// The SAME remote actor/inbox follows both — must be delivered to exactly once.
		$this->seed_follower_for( 'node', 0, self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );
		$this->seed_follower_for( 'artist', $artist_id, self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );

		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish', 'post_author' => $artist_id ] );

		$deliveries = [];
		$this->mock_all_deliveries( $deliveries );

		( new ActivityPub() )->broadcast( $post_id );

		$this->assertCount( 1, $deliveries, 'A follower of both the node and the artist must be delivered to exactly once, not twice.' );
	}

	public function test_broadcast_signs_artist_delivery_with_the_artists_own_key(): void {
		$artist_id  = $this->create_artist();
		$public_pem = $this->install_artist_signing_key( $artist_id );
		update_option( 'agnosis_activitypub_enabled', true );
		$this->seed_follower_for( 'artist', $artist_id, self::REMOTE_ACTOR_URL, self::REMOTE_INBOX_URL );

		$post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish', 'post_author' => $artist_id ] );

		$captured = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$captured ) {
				if ( strpos( $url, self::REMOTE_INBOX_URL ) !== false ) {
					$captured = $args;
					return [ 'response' => [ 'code' => 202, 'message' => '' ], 'headers' => [], 'body' => '', 'cookies' => [], 'filename' => '' ];
				}
				return $preempt;
			},
			10,
			3
		);

		( new ActivityPub() )->broadcast( $post_id );

		$this->assertNotEmpty( $captured, 'Sanity check: the delivery must actually have fired.' );

		$sig_header = $captured['headers']['Signature'] ?? '';
		$this->assertStringContainsString( 'keyId="' . rest_url( 'agnosis/v1/activitypub/actor/' . $artist_id ) . '#main-key"', $sig_header, 'The Signature must carry the ARTIST\'s own keyId, not the node\'s.' );

		preg_match( '/signature="([^"]+)"/', $sig_header, $sig_matches );
		$raw_signature = base64_decode( $sig_matches[1] ?? '', true );

		$signing_string = '(request-target): post ' . wp_parse_url( self::REMOTE_INBOX_URL, PHP_URL_PATH )
			. "\nhost: " . wp_parse_url( self::REMOTE_INBOX_URL, PHP_URL_HOST )
			. "\ndate: " . ( $captured['headers']['Date'] ?? '' )
			. "\ndigest: " . ( $captured['headers']['Digest'] ?? '' );

		$this->assertSame( 1, openssl_verify( $signing_string, $raw_signature, $public_pem, OPENSSL_ALGO_SHA256 ), 'The signature must verify against the ARTIST\'s own public key.' );
	}

	public function test_nodeinfo_reports_artist_and_artwork_counts(): void {
		$artist_a = $this->create_artist();
		$artist_b = $this->create_artist();
		self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish', 'post_author' => $artist_a ] );
		self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish', 'post_author' => $artist_b ] );
		self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'draft', 'post_author' => $artist_a ] );

		$response = ( new ActivityPub() )->nodeinfo();
		$data     = $response->get_data();

		$this->assertSame( '2.0', $data['version'] );
		$this->assertSame( 'agnosis', $data['software']['name'] );
		$this->assertSame( [ 'activitypub' ], $data['protocols'] );
		$this->assertGreaterThanOrEqual( 2, $data['usage']['users']['total'], 'Must count at least the two artists created for this test.' );
		$this->assertGreaterThanOrEqual( 2, $data['usage']['localPosts'], 'Must count only published artworks, but at least the two published in this test.' );
	}
}
