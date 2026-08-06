<?php
/**
 * Federation transport — signing an activity, sending it, and getting it there.
 *
 * Second unit extracted from Network\ActivityPub (sixteenth audit, Q-2, WP2 —
 * agnosis-audit/ACTIVITYPUB-SPLIT-ROADMAP.md). It owns exactly one job: putting
 * a already-built activity into a remote inbox, and keeping trying when that
 * fails. It does not know what an activity *means*.
 *
 * **Transport only — this is §2a's correction in code.** The original plan put
 * `post_to_note()`/`reply_to_note()` in this class. They are serializers: an AS2
 * Note carries `likesCount`, `sharesCount` and `repliesCount`, so building one
 * needs interaction and reply data, and a transport layer that reaches upward
 * for reply counts is an inverted layer. They stay behind for WP6\'s
 * `Serialization` unit, which sits *above* Interactions and Replies. Delivery
 * is ~500 lines of pure transport instead of ~900 lines of mixed concern.
 *
 * Depends on Identity alone (for `signing_key_for()`) — injected, never
 * constructed here, so the acyclic layering is enforced by construction:
 *
 *     Identity -> **Delivery** -> Interactions -> Replies -> Serialization
 *
 * The retry queue is a claim-then-read design (`claim_token`), so two concurrent
 * cron ticks can never deliver the same row twice; see
 * `process_delivery_retry_queue()` and `reset_stale_delivery_claims()`.
 *
 * @package Agnosis\Network\Federation
 */

declare(strict_types=1);

namespace Agnosis\Network\Federation;

use Agnosis\Core\Logger;

class Delivery {

	public function __construct( private Identity $identity ) {}

	/** Max retry-queue rows processed per agnosis_ap_retry_deliveries cron tick. */
	private const RETRY_BATCH_SIZE = 20;

	/**
	 * Backoff schedule for the delivery retry queue (audit §3g note iv).
	 * Index N is how long to wait before the (N+2)th attempt at a delivery —
	 * the live deliver() call is attempt 1, so the first agnosis_vendor_retry (index 0) is
	 * scheduled 5 minutes after that fails. A delivery that still hasn't
	 * succeeded after every interval here is marked 'failed' for good — total
	 * span is a little over 4 days, in the neighborhood of how long Mastodon
	 * itself keeps retrying a delivery before giving up on a dead inbox.
	 */
	private const RETRY_INTERVALS = [
		5 * MINUTE_IN_SECONDS,
		30 * MINUTE_IN_SECONDS,
		2 * HOUR_IN_SECONDS,
		12 * HOUR_IN_SECONDS,
		DAY_IN_SECONDS,
		3 * DAY_IN_SECONDS,
	];

	/**
	 * How long a delivery-retry row may sit 'claimed' before
	 * process_delivery_retry_queue()'s stale-claim sweep treats it as
	 * abandoned and returns it to 'pending' (security audit §2c) — see that
	 * method's own docblock.
	 */
	private const STALE_CLAIM_MINUTES = 30;

	/** @param array<string, mixed> $activity */
	public function deliver( string $inbox_url, array $activity, string $owner_type = 'node', int $owner_id = 0 ): void {
		$body = wp_json_encode( $activity );
		if ( false === $body ) {
			return;
		}

		$activity_type = is_string( $activity['type'] ?? null ) ? $activity['type'] : 'activity';
		$result        = $this->attempt_send( $inbox_url, $body, $owner_type, $owner_id );

		if ( true === $result ) {
			return;
		}

		// Deliveries were fire-and-forget ('blocking' => false) until §3a —
		// which is exactly how a 100%-rejection bug stayed invisible for so
		// long. Block and log anything that isn't a 2xx so delivery failures
		// surface in Settings → Logs.
		Logger::warning(
			sprintf( 'ActivityPub delivery (%s) to %s failed: %s', $activity_type, $inbox_url, $result ),
			'activitypub'
		);

		// Audit §2b, AUDIT-1.0.0.md — a definitive 410 Gone/404 Not Found
		// means the inbox is confirmed dead, not transiently unreachable;
		// skip the retry queue's multi-day backoff entirely rather than
		// spending it on an endpoint that's already known gone.
		if ( $this->is_permanently_dead_delivery_error( $result ) ) {
			$this->record_dead_delivery( $inbox_url, $activity_type, $body, $owner_type, $owner_id, $result );
			return;
		}

		// A cron-driven retry queue picks this delivery back up instead of it
		// being lost after this one fire-and-forget attempt (audit §3g note
		// iv) — previously this log line was the only trace a failed
		// delivery ever left.
		$this->enqueue_delivery_retry( $inbox_url, $activity_type, $body, $owner_type, $owner_id );
	}

	/**
	 * Sign and POST one already-encoded activity body to one inbox.
	 *
	 * Pure transport: returns success/failure but never logs or enqueues a
	 * retry itself — deliver() (a live, first attempt) and
	 * process_delivery_retry_queue() (a queued retry) each need to react to a
	 * failure differently, so that decision stays with the caller.
	 *
	 * Never actually returns `false` — only `true` or a `string` — but kept
	 * as a native `bool|string` type rather than PHP 8.2's standalone `true`
	 * type: the audit sandbox's bundled linter tops out at PHP 8.1 and can't
	 * parse `true` in a type position (though the plugin's real minimum is
	 * already 8.2), so this stays independently verifiable here instead of
	 * shipping unverified syntax. The `@return` tag below still gives
	 * PHPStan the precise `true|string` shape, so
	 * `is_permanently_dead_delivery_error()`/`record_dead_delivery()`'s
	 * `string`-typed `$error` parameters don't see a phantom `false` branch
	 * after the `true === $result` check both call sites narrow on first.
	 *
	 * @return true|string True on a 2xx response; an error-message string otherwise.
	 */
	public function attempt_send( string $inbox_url, string $body, string $owner_type = 'node', int $owner_id = 0 ): bool|string {
		[ $private_key, $key_id ] = $this->identity->signing_key_for( $owner_type, $owner_id );

		$date   = gmdate( 'D, d M Y H:i:s \G\M\T' );
		$digest = 'SHA-256=' . base64_encode( hash( 'sha256', $body, true ) );

		$signature = '';
		if ( $private_key && function_exists( 'openssl_sign' ) ) {
			// Mastodon requires the Digest header to exist AND be covered by
			// the signature on every inbox POST; Pixelfed and most other major
			// implementations inherit the same rule. A signature over only
			// "(request-target) host date" is rejected outright, which made
			// every outbound Accept/Create bounce with a 401 (audit §3a).
			$signing_string = '(request-target): post ' . wp_parse_url( $inbox_url, PHP_URL_PATH )
				. "\nhost: " . wp_parse_url( $inbox_url, PHP_URL_HOST )
				. "\ndate: " . $date
				. "\ndigest: " . $digest;
			openssl_sign( $signing_string, $raw_sig, $private_key, OPENSSL_ALGO_SHA256 );
			$signature = 'keyId="' . $key_id . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . base64_encode( $raw_sig ) . '"';
		}

		// $inbox_url is peer-supplied (the follower's own actor document, or a
		// stored follower inbox), so use the "safe" variant: it rejects
		// private/loopback/link-local/ULA targets, re-checked on every
		// redirect hop (audit §3b).
		$response = wp_safe_remote_post( $inbox_url, [
			'timeout'    => 15,
			'headers'    => array_filter( [
				'Content-Type' => 'application/activity+json',
				'Accept'       => 'application/activity+json',
				'Date'         => $date,
				'Digest'       => $digest,
				'Signature'    => $signature ?: null,
			] ),
			'body'       => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return sprintf( 'HTTP %d: %s', $code, wp_remote_retrieve_body( $response ) );
		}

		return true;
	}

	public function resolve_inbox( string $actor_url ): ?string {
		if ( empty( $actor_url ) ) {
			return null;
		}
		// $actor_url is peer-supplied (from an inbound Follow activity's
		// "actor" field), so use the "safe" variant (audit §3b).
		$response = wp_safe_remote_get( $actor_url, [
			'headers' => [ 'Accept' => 'application/activity+json' ],
			'timeout' => 10,
		] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $data['inbox'] ) ? esc_url_raw( $data['inbox'] ) : null;
	}

	/**
	 * Deliver one activity to every relevant follower inbox.
	 *
	 * For the node itself, that's the node's own follower list, unchanged.
	 * For an artist (audit §3h), it's the UNION of that artist's own
	 * followers and the node's followers — deduplicated by inbox_url — so
	 * existing node-level followers keep getting the full firehose (nobody's
	 * subscription silently narrows just because artists now have their own
	 * actors) while a new follower can choose to follow just one artist.
	 *
	 * @param array<string, mixed> $activity Activity payload.
	 */
	public function deliver_to_followers( array $activity, string $owner_type = 'node', int $owner_id = 0 ): void {
		global $wpdb;

		if ( 'artist' === $owner_type && $owner_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small, node-scale table (audit §3g note iii); parameterized via prepare().
			$inbox_urls = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT inbox_url FROM {$wpdb->prefix}agnosis_followers
				 WHERE ( owner_type = 'node' AND owner_id = 0 ) OR ( owner_type = 'artist' AND owner_id = %d )",
				$owner_id
			) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small, node-scale table (audit §3g note iii); no caching layer for it exists.
			$inbox_urls = $wpdb->get_col( "SELECT inbox_url FROM {$wpdb->prefix}agnosis_followers WHERE owner_type = 'node' AND owner_id = 0 ORDER BY id ASC" );
		}

		foreach ( $inbox_urls as $follower_inbox ) {
			$this->deliver( $follower_inbox, $activity, $owner_type, $owner_id );
		}
	}

	/**
	 * Insert a delivery retry queue row after a live delivery's first failure.
	 */
	public function enqueue_delivery_retry( string $inbox_url, string $activity_type, string $activity_json, string $owner_type, int $owner_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() parameterizes every value.
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_ap_delivery_queue',
			[
				'inbox_url'       => $inbox_url,
				'activity_type'   => $activity_type,
				'activity_json'   => $activity_json,
				'owner_type'      => $owner_type,
				'owner_id'        => $owner_id,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::RETRY_INTERVALS[0] ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s' ]
		);
	}

	/**
	 * Insert a delivery-queue row already in its terminal 'failed' state,
	 * for a first-attempt live delivery that failed with a definitive
	 * dead-inbox signal — see is_permanently_dead_delivery_error()'s own
	 * docblock. Skips the normal pending/backoff cycle entirely: still
	 * recorded in the same table/shape a normally-exhausted retry ends up
	 * in (queryable via Settings → Logs the same way), just without ever
	 * occupying a 'pending' row or spending any retry-queue cron cycles on
	 * an inbox that's already confirmed gone.
	 */
	public function record_dead_delivery( string $inbox_url, string $activity_type, string $activity_json, string $owner_type, int $owner_id, string $error ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() parameterizes every value.
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_ap_delivery_queue',
			[
				'inbox_url'     => $inbox_url,
				'activity_type' => $activity_type,
				'activity_json' => $activity_json,
				'owner_type'    => $owner_type,
				'owner_id'      => $owner_id,
				'status'        => 'failed',
				'attempts'      => 0,
				'last_error'    => $error,
				'resolved_at'   => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * A definitive "this inbox no longer exists" signal from attempt_send()'s
	 * own `'HTTP %d: %s'` error format (see that method) — HTTP 410 Gone (the
	 * spec-correct code for a deliberately-removed resource, and what
	 * Mastodon serves for a deleted account's inbox) or 404 Not Found.
	 * Retrying either like a transient failure for the full multi-day
	 * backoff wastes retry-queue cycles on an inbox that's already known
	 * dead (audit §2b, AUDIT-1.0.0.md).
	 */
	public function is_permanently_dead_delivery_error( string $error ): bool {
		return 1 === preg_match( '/^HTTP (410|404):/', $error );
	}

	/**
	 * agnosis_ap_retry_deliveries cron callback: work one batch of due
	 * delivery-retry rows (audit §3g note iv).
	 *
	 * A succeeding row is deleted outright — there's nothing further to do
	 * with it. A failing row advances to the next backoff interval in
	 * RETRY_INTERVALS, or — once every interval is exhausted — is left in
	 * place with status='failed' as the terminal record of a delivery that
	 * was never accepted.
	 *
	 * Claim-then-read (security audit §2c): this previously SELECTed due
	 * 'pending' rows and only updated them after attempting delivery — two
	 * overlapping cron ticks could both select the same row and both POST
	 * the same activity to the same inbox, a duplicate delivery. This method
	 * now atomically claims a batch first — a single `UPDATE … WHERE status
	 * = 'pending' AND next_attempt_at <= … ORDER BY id ASC LIMIT %d` tagging
	 * the claimed rows with a per-run `claim_token` — and only reads back
	 * rows carrying that exact token, the same pattern (and the same
	 * InnoDB-row-locking guarantee) as Newsletter\QueueProcessor::process();
	 * see that method's own docblock for the full reasoning. A PHP process
	 * that dies mid-batch after claiming but before finishing would
	 * otherwise strand those rows in 'claimed' forever — reset_stale_claims(),
	 * run at the top of every call, self-heals that automatically.
	 */
	public function process_delivery_retry_queue(): void {
		global $wpdb;

		$this->reset_stale_delivery_claims();

		$claim_token = wp_generate_uuid4();
		$now         = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- RETRY_BATCH_SIZE is a class constant, not user input; $now/$claim_token are bound parameters.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agnosis_ap_delivery_queue
				 SET status = 'claimed', claim_token = %s, claimed_at = %s
				 WHERE status = 'pending' AND next_attempt_at <= %s
				 ORDER BY id ASC
				 LIMIT %d",
				$claim_token,
				$now,
				$now,
				self::RETRY_BATCH_SIZE
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_ap_delivery_queue WHERE claim_token = %s ORDER BY id ASC",
				$claim_token
			)
		);

		foreach ( $rows as $row ) {
			$activity = json_decode( (string) $row->activity_json, true );
			$result   = $this->attempt_send( (string) $row->inbox_url, (string) $row->activity_json, (string) $row->owner_type, (int) $row->owner_id );

			if ( true === $result ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() parameterizes the id.
				$wpdb->delete( $wpdb->prefix . 'agnosis_ap_delivery_queue', [ 'id' => $row->id ], [ '%d' ] );
				continue;
			}

			$attempts  = (int) $row->attempts + 1;
			// Audit §2b, AUDIT-1.0.0.md — a definitive 410/404 exhausts
			// immediately rather than working through the remaining backoff
			// intervals; see is_permanently_dead_delivery_error()'s own
			// docblock.
			$exhausted = $this->is_permanently_dead_delivery_error( $result ) || $attempts >= count( self::RETRY_INTERVALS );

			$data   = [ 'attempts' => $attempts, 'last_error' => $result, 'claim_token' => null, 'claimed_at' => null ];
			$format = [ '%d', '%s', '%s', '%s' ];

			if ( $exhausted ) {
				$data['status']      = 'failed';
				$data['resolved_at'] = current_time( 'mysql', true );
				$format[]            = '%s';
				$format[]            = '%s';

				Logger::warning(
					sprintf(
						'ActivityPub delivery (%s) to %s permanently failed after %d attempts: %s',
						is_array( $activity ) && is_string( $activity['type'] ?? null ) ? $activity['type'] : (string) $row->activity_type,
						$row->inbox_url,
						$attempts + 1, // +1 for the original live attempt that first enqueued this row.
						$result
					),
					'activitypub'
				);
			} else {
				// Still has retries left — return to 'pending' for its next
				// scheduled attempt (the claim above moved it to 'claimed',
				// so this must be explicit; the pre-claim code never needed
				// to touch status here since the row had never left 'pending').
				$data['status']          = 'pending';
				$data['next_attempt_at'] = gmdate( 'Y-m-d H:i:s', time() + self::RETRY_INTERVALS[ $attempts ] );
				$format[]                = '%s';
				$format[]                = '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() parameterizes every value.
			$wpdb->update( $wpdb->prefix . 'agnosis_ap_delivery_queue', $data, [ 'id' => $row->id ], $format, [ '%d' ] );
		}
	}

	/**
	 * Reset any delivery-retry row stuck in 'claimed' longer than
	 * STALE_CLAIM_MINUTES back to 'pending' (security audit §2c) — same
	 * reasoning as Newsletter\QueueProcessor::reset_stale_claims(): a PHP
	 * process that claimed a batch and then died mid-run before finishing
	 * would otherwise leave those rows permanently unreachable, since the
	 * claim UPDATE only ever targets status = 'pending'.
	 */
	public function reset_stale_delivery_claims(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_CLAIM_MINUTES * MINUTE_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agnosis_ap_delivery_queue
				 SET status = 'pending', claim_token = NULL, claimed_at = NULL
				 WHERE status = 'claimed' AND claimed_at < %s",
				$cutoff
			)
		);
	}
}
