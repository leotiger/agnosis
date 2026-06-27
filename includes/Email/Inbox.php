<?php
/**
 * IMAP inbox poller.
 *
 * Connects to the configured mailbox using webklex/php-imap, which uses
 * PHP's stream_socket_client() under the hood. Unlike the built-in PHP
 * imap_open() (c-client), PHP streams send the SNI hostname during the TLS
 * handshake — essential on shared-IP hosting where Dovecot cannot otherwise
 * present the correct domain certificate.
 *
 * @package Agnosis\Email
 */

declare(strict_types=1);

namespace Agnosis\Email;

use Agnosis\Core\Logger;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;

class Inbox {

	private Parser $parser;

	public function __construct() {
		$this->parser = new Parser();
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Register the custom cron interval.
	 *
	 * Must be called on every request (hooked to cron_schedules) so WordPress
	 * recognises 'every_five_minutes' when evaluating the cron queue — not just
	 * at plugin activation time.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing cron schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_interval( array $schedules ): array {
		if ( ! isset( $schedules['every_five_minutes'] ) ) {
			// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5-minute poll is intentional; email latency target is <5 min.
			$schedules['every_five_minutes'] = [
				'interval' => 300,
				'display'  => __( 'Every 5 minutes', 'agnosis' ),
			];
		}
		return $schedules;
	}

	/** Schedule the cron poll if not already scheduled (idempotent). */
	public function schedule_poll(): void {
		if ( ! wp_next_scheduled( 'agnosis_poll_inbox' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'agnosis_poll_inbox' );
		}
	}

	/** Schedule the daily inbox/queue cleanup if not already scheduled (idempotent). */
	public function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'agnosis_cleanup_inbox' ) ) {
			wp_schedule_event( time(), 'daily', 'agnosis_cleanup_inbox' );
		}
	}

	/**
	 * Cron callback — purge old IMAP messages and stale queue rows.
	 *
	 * Reads `agnosis_imap_cleanup_days` (default 30). Any SEEN IMAP message
	 * older than that threshold is permanently deleted. Queue rows that are
	 * 'failed' or 'done' and older than the same threshold are also pruned.
	 */
	public function cleanup(): void {
		$days = max( 1, (int) get_option( 'agnosis_imap_cleanup_days', 7 ) );
		$this->cleanup_imap();
		$this->cleanup_queue();
		Logger::prune( $days );
	}

	/**
	 * Admin action — heal the queue so everything retries on the next poll.
	 *
	 * Resets three categories of broken queue rows back to 'pending':
	 *  1. 'failed' — pipeline exception (parse error, AI error, etc.)
	 *  2. 'processing' older than 30 min — PHP died mid-run (stuck row)
	 *  3. 'published' whose WordPress post no longer exists
	 *
	 * We no longer touch IMAP \Seen flags. poll() now scans ALL recent messages
	 * and lets is_already_queued() decide what needs work — so IMAP flag state
	 * is irrelevant and fighting a concurrent mail client (Apple Mail, etc.) is
	 * unnecessary.
	 *
	 * @return int Total number of queue rows reset to 'pending'.
	 */
	public function force_reprocess(): int {
		global $wpdb;

		// --- 1. Reset failed rows ---
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$failed = (int) $wpdb->query(
			"UPDATE {$wpdb->prefix}agnosis_queue
			 SET status = 'pending', post_id = NULL, error = NULL
			 WHERE status = 'failed'"
		);

		// --- 2. Reset stuck 'processing' rows (older than 30 minutes) ---
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stuck = (int) $wpdb->query(
			"UPDATE {$wpdb->prefix}agnosis_queue
			 SET status = 'pending', error = NULL
			 WHERE status = 'processing'
			 AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
		);

		// --- 3. Reset 'published' rows whose post was deleted ---
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$published_rows = $wpdb->get_results(
			"SELECT id, post_id FROM {$wpdb->prefix}agnosis_queue WHERE status = 'published'"
		);
		$orphaned = 0;
		foreach ( $published_rows as $r ) {
			if ( ! ( (int) $r->post_id > 0 && null !== get_post( (int) $r->post_id ) ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'agnosis_queue',
					[ 'status' => 'pending', 'post_id' => null, 'error' => null ],
					[ 'id' => (int) $r->id ],
					[ '%s', '%s', '%s' ],
					[ '%d' ]
				);
				++$orphaned;
			}
		}

		$total = $failed + $stuck + $orphaned;
		Logger::info(
			sprintf(
				'Force-reprocess: reset %d queue row(s) to pending (%d failed, %d stuck, %d orphaned).',
				$total, $failed, $stuck, $orphaned
			),
			'inbox'
		);

		return $total;
	}

	/**
	 * Test the IMAP connection and return a status summary.
	 *
	 * Logs the full folder list to the Logs tab so folder-name mismatches
	 * (e.g. server uses "Inbox" not "INBOX") are immediately visible.
	 *
	 * @return array{ok: bool, message: string, total: int, unseen: int}
	 */
	public function test_connection(): array {
		if ( ! $this->is_configured() ) {
			return [
				'ok'      => false,
				'message' => __( 'IMAP credentials are not configured.', 'agnosis' ),
				'total'   => 0,
				'unseen'  => 0,
			];
		}

		try {
			$client = $this->make_client();

			// Log available folders — helps diagnose "INBOX not found" issues.
			try {
				$all_folders = $client->getFolders( false, null, true );
				$names       = [];
				foreach ( $all_folders as $f ) {
					$names[] = $f->path;
				}
				Logger::info( 'Folders on server: ' . ( $names ? implode( ', ', $names ) : '(none returned by LIST)' ), 'inbox' );
			} catch ( \Throwable $fe ) {
				Logger::warning( 'Could not list folders: ' . $fe->getMessage(), 'inbox' );
			}

			$folder = $this->get_inbox_folder( $client );
			$total  = $folder->query()->all()->count();
			$unseen = $folder->query()->unseen()->count();

			$message = sprintf(
				/* translators: 1: total message count, 2: unseen message count */
				__( 'Connected successfully. %1$d message(s) in inbox, %2$d unseen.', 'agnosis' ),
				$total,
				$unseen
			);
			Logger::info( 'Connection test OK. ' . $message, 'inbox' );
			$client->disconnect();
			return [ 'ok' => true, 'message' => $message, 'total' => $total, 'unseen' => $unseen ];

		} catch ( \Throwable $e ) {
			$error = self::exception_chain( $e );
			Logger::error( 'Connection test failed: ' . $error, 'inbox' );
			return [ 'ok' => false, 'message' => $error, 'total' => 0, 'unseen' => 0 ];
		}
	}

	/** Cron callback — poll the IMAP inbox for new submissions. */
	public function poll(): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		try {
			$client = $this->make_client();
			$folder = $this->get_inbox_folder( $client );
			$this->process_messages( $folder );
			$client->disconnect();
		} catch ( \Throwable $e ) {
			Logger::error( 'IMAP poll failed: ' . self::exception_chain( $e ), 'inbox' );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Open the INBOX folder reliably.
	 *
	 * `Client::getFolder('INBOX')` sends a LIST command and filters by name.
	 * On some servers INBOX does not appear in the LIST response (it is a
	 * special IMAP mailbox per RFC 3501 §5.1) and getFolder() returns null.
	 *
	 * Strategy:
	 *   1. Try getFolder('INBOX') — works on most servers.
	 *   2. If null, construct a Folder object with path 'INBOX' directly,
	 *      bypassing the folder listing entirely. The path 'INBOX' is always
	 *      valid for SELECT/EXAMINE regardless of what LIST returns.
	 *
	 * @throws \RuntimeException When even the direct constructor approach fails.
	 */
	private function get_inbox_folder( Client $client ): Folder {
		$folder = $client->getFolder( 'INBOX' );

		if ( null !== $folder ) {
			return $folder;
		}

		// getFolder() returned null — likely INBOX not in LIST response.
		// Detect the folder delimiter from whatever the server reported.
		Logger::warning( 'getFolder("INBOX") returned null — opening INBOX directly.', 'inbox' );

		try {
			$all      = $client->getFolders( false, null, true );
			$first    = $all->first();
			$delim    = $first ? $first->delimiter : '.';
		} catch ( \Throwable $e ) {
			$delim = '.';
		}

		// phpcs:ignore NeutronStandard.Functions.DisallowCallUserFunc.CallUserFunc -- constructing webklex Folder directly; no WP alternative.
		return new Folder( $client, 'INBOX', $delim, [] );
	}

	/**
	 * Flatten a full exception chain into a single readable string.
	 *
	 * webklex wraps low-level stream/SSL errors inside ConnectionFailedException,
	 * so getMessage() alone returns "connection failed" without the actual cause.
	 * Walking getPrevious() surfaces the real OpenSSL / stream error message.
	 */
	private static function exception_chain( \Throwable $e ): string {
		$parts = [];
		$seen  = [];
		while ( null !== $e ) {
			$msg = trim( $e->getMessage() );
			// Deduplicate adjacent identical messages.
			if ( '' !== $msg && ! in_array( $msg, $seen, true ) ) {
				$parts[] = $msg;
				$seen[]  = $msg;
			}
			$e = $e->getPrevious();
		}
		return implode( ' → ', $parts );
	}

	private function is_configured(): bool {
		return ! empty( get_option( 'agnosis_imap_host' ) )
			&& ! empty( get_option( 'agnosis_imap_user' ) )
			&& ! empty( get_option( 'agnosis_imap_pass' ) );
	}

	/**
	 * Build and connect a webklex IMAP Client.
	 *
	 * webklex/php-imap's native 'imap' protocol driver connects via
	 * stream_socket_client("ssl://host:port"), which PHP automatically uses to
	 * send the SNI hostname in the TLS handshake. This allows Dovecot on a
	 * shared IP to present the correct domain certificate — something the
	 * built-in PHP imap_open() (c-client) cannot do because c-client has no
	 * SNI support.
	 *
	 * We go through ClientManager::make() rather than instantiating Client
	 * directly. The Client constructor's setAccountConfig() requires a non-null
	 * default config which only ClientManager provides.
	 *
	 * @throws \Webklex\PHPIMAP\Exceptions\ConnectionFailedException When the TCP/SSL connection cannot be established.
	 * @throws \Webklex\PHPIMAP\Exceptions\AuthFailedException When the IMAP LOGIN command is rejected.
	 */
	private function make_client(): Client {
		$host    = (string) get_option( 'agnosis_imap_host' );
		$port    = (int) get_option( 'agnosis_imap_port', 993 );
		$ssl     = (bool) get_option( 'agnosis_imap_ssl', true );
		$no_cert = (bool) get_option( 'agnosis_imap_novalidate_cert', false );
		$user    = (string) get_option( 'agnosis_imap_user' );
		$pass    = (string) get_option( 'agnosis_imap_pass' );

		// ClientManager is required — instantiating Client directly passes null
		// as the default_config argument, triggering a TypeError in v5.
		$manager = new ClientManager( [] );

		$client = $manager->make( [
			'host'          => $host,
			'port'          => $port,
			// 'imap' uses the native PHP stream driver (SNI-capable).
			// Never use 'legacy-imap' — that falls back to imap_open() which has no SNI.
			'protocol'      => 'imap',
			'encryption'    => $ssl ? 'ssl' : false,
			'validate_cert' => ! $no_cert,
			'username'      => $user,
			'password'      => $pass,
		] );

		$client->connect();
		return $client;
	}

	/**
	 * Fetch recent messages and enqueue any not yet in the pipeline.
	 *
	 * We query ALL messages within the retention window rather than only UNSEEN
	 * ones. This avoids a race condition where a concurrent mail client (e.g.
	 * Apple Mail) re-marks messages as \Seen between our clearFlag and our query,
	 * causing the UNSEEN search to return 0.  The database `is_already_queued()`
	 * state machine is the authoritative idempotency guard — IMAP flags are not.
	 *
	 * @param Folder $folder
	 */
	private function process_messages( Folder $folder ): void {
		// Bound the query to the retention window so we never scan the full inbox.
		$days  = max( 1, (int) get_option( 'agnosis_imap_cleanup_days', 30 ) );
		$since = new \DateTime( '-' . $days . ' days' );

		$messages = $folder->query()->since( $since )->get();
		Logger::info( sprintf( 'Poll: scanning %d message(s) from last %d day(s).', $messages->count(), $days ), 'inbox' );

		foreach ( $messages as $message ) {
			try {
				$uid = (string) $message->getUid();

				if ( $this->is_already_queued( $uid ) ) {
					continue; // DB state machine handles all cases.
				}

				$submission = $this->parser->parse_imap_message( $message );

				if ( null === $submission ) {
					// No valid image attachments — mark it so we don't re-check it.
					Logger::info( 'Skipped UID ' . $uid . ': no valid image attachments.', 'inbox' );
					$this->mark_no_artwork( $uid );
					continue;
				}

				$artist_id = $submission['artist_id'] ?? null;

				if ( null === $artist_id ) {
					Logger::warning( 'Skipped: unregistered sender <' . $submission['from'] . '>.', 'inbox' );
					$this->mark_no_artwork( $uid );
					continue;
				}

				if ( ! $this->is_admitted_artist( $artist_id ) ) {
					Logger::warning( 'Skipped: sender <' . $submission['from'] . '> (user #' . $artist_id . ') is not an admitted artist.', 'inbox' );
					$this->mark_no_artwork( $uid );
					continue;
				}

				$this->enqueue( $uid, $submission );

			} catch ( \Throwable $e ) {
				Logger::error( 'Error processing message UID ' . ( $uid ?? '?' ) . ': ' . $e->getMessage(), 'inbox' );
			}
		}
	}

	/**
	 * Record a skipped message in the queue table so we never re-check it.
	 *
	 * Uses status 'failed' with a descriptive error so it appears in the queue
	 * and can be inspected, but won't be picked up by PostCreator.
	 * INSERT IGNORE is used because a row may already exist (e.g. previous skip).
	 *
	 * @param string $uid IMAP message UID.
	 */
	private function mark_no_artwork( string $uid ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table insert; INSERT IGNORE is idempotent.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}agnosis_queue
				 (message_uid, status, raw_email, error)
				 VALUES (%s, 'failed', '{}', 'Skipped: no valid artwork or unregistered sender.')",
				$uid
			)
		);
	}

	/**
	 * Check whether a WP user is an admitted Agnosis artist (or an admin).
	 *
	 * Mirrors the check in Admission::is_artist() without coupling Inbox to that class.
	 */
	private function is_admitted_artist( int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return in_array( 'agnosis_artist', (array) $user->roles, true )
			|| user_can( $user_id, 'manage_options' );
	}

	/**
	 * Check whether a message UID is already handled — and auto-heal broken states.
	 *
	 * Returns true if the message should be skipped by process_messages(); false if
	 * it should be (re-)enqueued.  Broken queue rows are reset in-place so
	 * Process Pending Queue can pick them up without needing a new IMAP fetch.
	 *
	 * State machine:
	 *  - No row         → false  (first time: enqueue it)
	 *  - pending        → true   (already queued, awaiting pipeline)
	 *  - processing <30m→ true   (pipeline running)
	 *  - processing ≥30m→ reset to pending, true  (PHP crashed — recover via queue)
	 *  - published + valid post → true  (fully done)
	 *  - published + missing post → reset to pending, true  (post deleted — re-run)
	 *  - failed         → reset to pending, true  (retry via Process Queue)
	 *
	 * Returning true in all non-null cases means process_messages() marks the IMAP
	 * message as \Seen and moves on — the queued raw_email data is sufficient to
	 * re-run the pipeline without a fresh IMAP fetch.
	 */
	private function is_already_queued( string $uid ): bool {
		global $wpdb;

		// NOTE: Do NOT select post_id here — it may not exist on older table schemas.
		// The post_id check for 'published' rows is done via a separate query below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- idempotency check on custom table; must be real-time.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, updated_at FROM {$wpdb->prefix}agnosis_queue WHERE message_uid = %s LIMIT 1",
				$uid
			)
		);

		if ( null === $row ) {
			return false; // Never seen — let enqueue() insert it.
		}

		$queue_id = (int) $row->id;

		switch ( $row->status ) {
			case 'pending':
				// Row exists but cron may not be scheduled (e.g. previous enqueue failed).
				// Schedule now — WP deduplicates if the same event+args is already queued.
				wp_schedule_single_event( time(), 'agnosis_publish_submission', [ $queue_id ] );
				return true; // Already in queue, pipeline will pick it up.

			case 'processing':
				$age = time() - (int) strtotime( $row->updated_at );
				if ( $age > 1800 ) {
					Logger::warning(
						sprintf( 'Queue #%d stuck in processing for %d min — resetting to pending.', $queue_id, (int) ( $age / 60 ) ),
						'inbox'
					);
					$this->reset_queue_row( $queue_id );
					wp_schedule_single_event( time(), 'agnosis_publish_submission', [ $queue_id ] );
				}
				return true; // Either still running, or just reset — skip IMAP re-fetch.

			case 'published':
				// Fetch post_id via a separate query — graceful if column is missing.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$post_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COALESCE(post_id, 0) FROM {$wpdb->prefix}agnosis_queue WHERE id = %d",
						$queue_id
					)
				);
				if ( $post_id > 0 && null !== get_post( $post_id ) ) {
					return true; // Post exists — fully done.
				}
				Logger::warning(
					sprintf( 'Queue #%d: published but post #%d not found — resetting to pending.', $queue_id, $post_id ),
					'inbox'
				);
				$this->reset_queue_row( $queue_id );
				wp_schedule_single_event( time(), 'agnosis_publish_submission', [ $queue_id ] );
				return true; // Reset — pipeline will re-run.

			case 'failed':
				Logger::info( sprintf( 'Queue #%d: resetting failed row to pending for retry.', $queue_id ), 'inbox' );
				$this->reset_queue_row( $queue_id );
				wp_schedule_single_event( time(), 'agnosis_publish_submission', [ $queue_id ] );
				return true; // Reset — pipeline will retry.

			default:
				return true;
		}
	}

	/**
	 * Reset a queue row to 'pending' so the pipeline can retry it.
	 *
	 * @param int $id Queue row primary key.
	 */
	private function reset_queue_row( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- queue state write; no caching.
		$wpdb->update(
			$wpdb->prefix . 'agnosis_queue',
			[ 'status' => 'pending', 'post_id' => null, 'error' => null ],
			[ 'id' => $id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Insert submission into the queue and fire the async processing action.
	 *
	 * @param string               $uid        Unique message identifier.
	 * @param array<string, mixed> $submission Parsed submission data from Parser.
	 */
	private function enqueue( string $uid, array $submission ): void {
		global $wpdb;

		// Binary image data cannot survive wp_json_encode() — WordPress's UTF-8 sanitiser
		// silently replaces non-UTF-8 bytes with replacement characters, corrupting the image.
		// Base64-encode each attachment's binary payload before serialising to JSON.
		foreach ( $submission['attachments'] as &$att ) {
			if ( isset( $att['data'] ) && ( $att['encoding'] ?? '' ) !== 'base64' ) {
				$att['data']     = base64_encode( $att['data'] );
				$att['encoding'] = 'base64';
			}
		}
		unset( $att );

		$json = wp_json_encode( $submission );
		if ( false === $json ) {
			Logger::error( sprintf( 'Failed to JSON-encode submission for UID %s.', $uid ), 'inbox' );
			return;
		}

		// INSERT IGNORE — silently skips on UNIQUE KEY collision (row already exists from
		// a previous poll). We fetch the row's ID afterward whether it was just inserted
		// or already present. This makes enqueue() fully idempotent.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table, no WP abstraction available.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}agnosis_queue (message_uid, artist_id, raw_email, status)
				 VALUES (%s, %s, %s, 'pending')",
				$uid,
				null !== ( $submission['artist_id'] ?? null ) ? (string) (int) $submission['artist_id'] : null,
				$json
			)
		);

		// Retrieve the row ID — either the one we just inserted or the pre-existing one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$queue_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}agnosis_queue WHERE message_uid = %s LIMIT 1",
				$uid
			)
		);

		if ( ! $queue_id ) {
			Logger::error(
				sprintf( 'Failed to enqueue UID %s: %s', $uid, $wpdb->last_error ?: 'INSERT IGNORE returned no row' ),
				'inbox'
			);
			return;
		}

		Logger::info( sprintf( 'Enqueued UID %s as queue #%d.', $uid, $queue_id ), 'inbox' );

		// Schedule async processing (WP-Cron single event, fires immediately).
		wp_schedule_single_event( time(), 'agnosis_publish_submission', [ $queue_id ] );
	}

	/**
	 * Delete SEEN IMAP messages older than the configured retention period.
	 *
	 * All SEEN messages (processed or rejected) are eligible once the retention
	 * window has passed — the mailbox is a delivery mechanism, not an archive.
	 */
	private function cleanup_imap(): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		$days   = max( 1, (int) get_option( 'agnosis_imap_cleanup_days', 30 ) );
		$cutoff = new \DateTime( '-' . $days . ' days' );

		try {
			$client   = $this->make_client();
			$folder   = $this->get_inbox_folder( $client );
			$messages = $folder->query()->seen()->before( $cutoff )->get();

			foreach ( $messages as $message ) {
				// Pass true to expunge immediately — Folder::expunge() does not exist in this version.
				$message->delete( true );
			}

			if ( $messages->count() > 0 ) {
				Logger::info(
					sprintf( 'Cleanup: deleted %d IMAP message(s) older than %d days.', $messages->count(), $days ),
					'inbox.cleanup'
				);
			}

			$client->disconnect();

		} catch ( \Throwable $e ) {
			Logger::error( 'Cleanup: IMAP error: ' . $e->getMessage(), 'inbox.cleanup' );
		}
	}

	/**
	 * Prune finished / failed queue rows older than the retention period.
	 *
	 * Rows with status 'published' or 'failed' that are past the retention
	 * threshold no longer serve any operational purpose and are removed.
	 */
	private function cleanup_queue(): void {
		global $wpdb;

		$days = max( 1, (int) get_option( 'agnosis_imap_cleanup_days', 30 ) );

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE on custom table; caching does not apply to write queries.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}agnosis_queue
				 WHERE status IN ('published','failed')
				 AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);

		if ( false !== $deleted && $deleted > 0 ) {
			Logger::info( sprintf( 'Cleanup: pruned %d stale queue row(s).', $deleted ), 'inbox.cleanup' );
		}
	}
}
