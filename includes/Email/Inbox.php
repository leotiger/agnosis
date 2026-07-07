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

use Agnosis\Artist\Admission;
use Agnosis\Artist\Departure;
use Agnosis\Core\Debug;
use Agnosis\Core\Logger;
use Agnosis\Core\RateLimiter;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;

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
		if ( $this->is_configured() ) {
			try {
				$client = $this->make_client();
				$folder = $this->get_inbox_folder( $client );
				$this->process_messages( $folder );
				$client->disconnect();
			} catch ( \Throwable $e ) {
				Logger::error( 'IMAP poll failed: ' . self::exception_chain( $e ), 'inbox' );
			}
		}

		// Always drain the pending queue — covers webhook-sourced rows and any
		// IMAP rows whose single event was missed (no page load after enqueue).
		$this->drain_pending();
	}

	/**
	 * Schedule a processing event for every queue row that is still pending.
	 *
	 * Called at the end of every poll() run so that pending rows are retried
	 * even when IMAP is unconfigured (webhook-only mode) or when a prior
	 * wp_schedule_single_event() fired but no page load occurred to run it.
	 *
	 * wp_schedule_single_event() is idempotent when identical (hook, args) is
	 * already queued — WP deduplicates by (hook, args, timestamp bucket) — so
	 * calling this repeatedly is safe.
	 */
	public function drain_pending(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- real-time queue drain; result must not be cached.
		$ids = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}agnosis_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 50"
		);

		if ( empty( $ids ) ) {
			return;
		}

		$scheduled = 0;
		foreach ( $ids as $id ) {
			$queue_id = (int) $id;
			// Spread events by 1 second each so they don't all fire simultaneously.
			wp_schedule_single_event( time() + $scheduled, 'agnosis_publish_submission', [ $queue_id ] );
			++$scheduled;
		}

		Logger::info(
			sprintf( 'drain_pending: scheduled %d pending row(s) for processing.', $scheduled ),
			'inbox'
		);
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
		// ---- Build the query — headers-first, UID-sequenced -----------------
		//
		// fetchBody(false): webklex fetches only RFC-822 headers on the first
		// IMAP FETCH.  The body is pulled lazily when parse_imap_message() is
		// called below — and only for messages that clear all cheap gates.
		//
		// setSequence(ST_UID): the IMAP server numbers messages two ways:
		//   • Message Sequence Numbers (MSN) — renumber when older mail is deleted.
		//   • UIDs — stable for the lifetime of a mailbox.
		// Operating in UID mode means our cursor survives expunges and reconnects.
		//
		// UID cursor: on every run after the first we send `UID N+1:*` which tells
		// the server to return only messages whose UID is strictly greater than the
		// last one we processed.  First run (or cursor reset) falls back to the
		// configured retention window so we do not skip legitimate back-dated mail.

		$last_uid = max( 0, (int) get_option( 'agnosis_imap_last_uid', 0 ) );
		$messages = $this->query_messages( $folder, $last_uid );
		$max_uid_seen = $last_uid;

		$goodbye_addr = strtolower( trim( (string) get_option( 'agnosis_email_goodbye', '' ) ) );

		foreach ( $messages as $message ) {
			try {
				$uid = (string) $message->getUid();

				// Track the highest UID we have seen in this poll so we can
				// advance the cursor even for messages we decide to skip.
				$numeric_uid = (int) $uid;
				if ( $numeric_uid > $max_uid_seen ) {
					$max_uid_seen = $numeric_uid;
				}

				if ( $this->is_already_queued( $uid ) ) {
					continue; // DB state machine handles all cases.
				}

				// ---- Cheap header checks (before body download) --------------------
				// Read From: and To: from headers — no body fetch needed. These are
				// fast IMAP FETCH (RFC 822 header) operations. All expensive work
				// (body download, attachment parsing, AI) only runs for admitted senders
				// that also pass throttle and auth checks.

				$from_list  = $message->getFrom()->toArray();
				$from_email = $from_list ? sanitize_email( (string) $from_list[0]->mail ) : '';

				// --- Goodbye alias: self-removal request (no attachment required) ---
				if ( $goodbye_addr ) {
					$to_list = $message->getTo()->toArray();
					$msg_to  = $to_list ? strtolower( sanitize_email( (string) $to_list[0]->mail ) ) : '';
					if ( $msg_to === $goodbye_addr ) {
						$this->handle_goodbye_email( $from_email, $uid );
						continue;
					}
				}

				// --- Cheap gate 1: admitted sender (header-only) -------------------
				$artist_user = $from_email ? get_user_by( 'email', $from_email ) : null;
				$artist_id   = $artist_user ? $artist_user->ID : null;

				if ( null === $artist_id ) {
					Logger::warning( 'Skipped UID ' . $uid . ': unregistered sender <' . $from_email . '>.', 'inbox' );
					$this->mark_no_artwork( $uid, null, 'unregistered_sender' );
					continue;
				}

				if ( ! $this->is_admitted_artist( $artist_id ) ) {
					Logger::warning( 'Skipped UID ' . $uid . ': sender <' . $from_email . '> (user #' . $artist_id . ') is not admitted.', 'inbox' );
					$this->mark_no_artwork( $uid, $artist_id, 'not_admitted' );
					continue;
				}

				// --- Cheap gate 2: per-sender intake throttle ----------------------
				$sender_limit  = max( 1, (int) get_option( 'agnosis_intake_per_sender_limit', 5 ) );
				$throttle      = RateLimiter::check_sender( 'email_intake', $from_email, $sender_limit, HOUR_IN_SECONDS );
				if ( is_wp_error( $throttle ) ) {
					Logger::warning( 'Skipped UID ' . $uid . ': sender <' . $from_email . '> throttled (' . $sender_limit . '/hour limit).', 'inbox' );
					$this->mark_no_artwork( $uid, $artist_id, 'throttled' );
					continue;
				}

				// --- Cheap gate 3: SPF/DKIM (opt-in) -------------------------------
				if ( get_option( 'agnosis_require_email_auth' ) ) {
					$auth_raw = '';
					try {
						$auth_raw = (string) $message->getHeader()->get( 'authentication-results' );
					} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- header absent or library threw; $auth_raw stays ''
					}
					if ( $auth_raw ) {
						$verdicts = EmailAuth::check_header( $auth_raw );
						if ( ! EmailAuth::passes( $verdicts ) ) {
							Logger::warning(
								sprintf( 'Skipped UID ' . $uid . ': <' . $from_email . '> failed auth — SPF=%s DKIM=%s.', $verdicts['spf'] ?: 'none', $verdicts['dkim'] ?: 'none' ),
								'inbox'
							);
							$this->mark_no_artwork( $uid, $artist_id, 'auth_failed' );
							continue;
						}
					} else {
						Logger::warning( 'Skipped UID ' . $uid . ': auth required but no Authentication-Results header.', 'inbox' );
						$this->mark_no_artwork( $uid, $artist_id, 'auth_failed' );
						continue;
					}
				}

				// ---- Full parse (body + attachments) — admitted senders only ------
				$submission = $this->parser->parse_imap_message( $message );

				if ( null === $submission ) {
					// No valid image attachments — mark it so we don't re-check it. The
					// sender IS a known, admitted artist here (both gates above already
					// passed) — persist $artist_id so the Inbox admin table shows who
					// this was instead of "unregistered sender", and so a later
					// auto-retry (is_already_queued()'s 'failed' branch) doesn't have
					// PostCreator wrongly re-reject a real artist as unadmitted.
					Logger::info( 'Skipped UID ' . $uid . ': no valid image attachments.', 'inbox' );

					if ( Debug::enabled() ) {
						// Parser::parse_imap_message() already wrote a detailed
						// 'parser-attachments-*' dump (raw MIME structure, per-
						// attachment MIME sniff/declared/disposition/size, and
						// why each one was accepted or rejected) for this exact
						// UID just above, in the call this branch is reacting
						// to. This companion file just makes that dump easy to
						// find from a directory listing sorted by time — the
						// two should have matching timestamps.
						Debug::write(
							'inbox-skip-no-attachments',
							sprintf(
								"UID: %s\nFrom: %s\nSubject: %s\nArtist user ID: %d\n\nSee the parser-attachments-* dump written immediately before this one (same poll cycle) for the full MIME/attachment trace.",
								$uid,
								$from_email,
								(string) $message->getSubject(),
								$artist_id
							)
						);
					}

					$this->mark_no_artwork( $uid, $artist_id, 'no_attachments' );

					/**
					 * Fires when an admitted artist's email was received but contained no
					 * recognizable image/audio/video attachment (e.g. a photo inserted
					 * inline rather than properly attached).
					 *
					 * @param int    $artist_id WordPress user ID of the sender.
					 * @param string $uid       IMAP message UID (for logging/reference only).
					 */
					do_action( 'agnosis_submission_no_attachment', $artist_id, $uid );
					continue;
				}

				$this->enqueue( $uid, $submission );

			} catch ( \Throwable $e ) {
				Logger::error( 'Error processing message UID ' . ( $uid ?? '?' ) . ': ' . $e->getMessage(), 'inbox' );
			}
		}

		// Advance the UID cursor so the next poll only fetches genuinely new mail.
		if ( $max_uid_seen > $last_uid ) {
			update_option( 'agnosis_imap_last_uid', $max_uid_seen );
			Logger::info( 'Poll: UID cursor advanced to ' . $max_uid_seen . '.', 'inbox' );
		}
	}

	/**
	 * Build the IMAP message query for this poll.
	 *
	 * Extracted as a protected method so tests can subclass Inbox and inject a
	 * controlled message collection without requiring a live IMAP connection.
	 *
	 * When $last_uid > 0 we send `UID N+1:*` to the server, retrieving only
	 * messages whose UID is strictly greater than the last processed one.
	 * On the first run ($last_uid === 0) we fall back to a date-bounded window
	 * so we do not skip legitimate back-dated mail.
	 *
	 * @param Folder $folder   Webklex folder to query.
	 * @param int    $last_uid Last UID persisted from the previous poll (0 = first run).
	 * @return \Webklex\PHPIMAP\Support\MessageCollection
	 */
	protected function query_messages( Folder $folder, int $last_uid ): \Webklex\PHPIMAP\Support\MessageCollection {
		$base = $folder->query(); // WhereQuery — do not chain through Query-typed fluent setters.
		$base->setSequence( IMAP::ST_UID );
		$base->fetchBody( false );

		if ( $last_uid > 0 ) {
			$messages = $base->whereUid( ( $last_uid + 1 ) . ':*' )->get();
			Logger::info(
				sprintf( 'Poll: UID cursor at %d — fetched %d new message(s).', $last_uid, $messages->count() ),
				'inbox'
			);
			return $messages;
		}

		$days  = max( 1, (int) get_option( 'agnosis_imap_cleanup_days', 30 ) );
		$since = new \DateTime( '-' . $days . ' days' );
		$messages = $base->since( $since )->get();
		Logger::info(
			sprintf( 'Poll: no UID cursor — scanning %d message(s) from last %d day(s).', $messages->count(), $days ),
			'inbox'
		);
		return $messages;
	}

	/**
	 * Handle a goodbye email: trigger the self-removal confirmation flow for
	 * the sending artist, then mark the message so it won't be re-processed.
	 *
	 * @param string $from_email Sender email address.
	 * @param string $uid        IMAP message UID (used for deduplication).
	 */
	private function handle_goodbye_email( string $from_email, string $uid ): void {
		$user = get_user_by( 'email', $from_email );

		if ( ! $user || ! $this->is_admitted_artist( $user->ID ) ) {
			Logger::warning(
				'Goodbye email from non-artist <' . $from_email . '> — ignored.',
				'inbox'
			);
			$this->mark_no_artwork( $uid, $user ? (int) $user->ID : null, 'goodbye_non_artist' );
			return;
		}

		$departure = new Departure();
		$ok        = $departure->initiate_removal_for_user( $user->ID );

		if ( $ok ) {
			Logger::info(
				'Goodbye email from <' . $from_email . '> (user #' . $user->ID . '): confirmation sent.',
				'inbox'
			);
		} else {
			Logger::warning(
				'Goodbye email from <' . $from_email . '> (user #' . $user->ID . '): no active membership — ignored.',
				'inbox'
			);
		}

		$this->mark_no_artwork( $uid, $user->ID, 'goodbye_handled' );
	}

	/**
	 * Human-readable error text per skip reason — shown in the Inbox admin
	 * table's Error column, and (via $reason) drives whether the sender's
	 * identity is worth keeping on the row.
	 *
	 * Every reason gets its own accurate string. Previously every call site
	 * shared one generic "no valid artwork or unregistered sender" message,
	 * which made a content problem (no attachment) indistinguishable from an
	 * identity problem (unknown sender) — see mark_no_artwork() below.
	 *
	 * @var array<string, string>
	 */
	private const SKIP_REASONS = [
		'unregistered_sender'  => 'Skipped: sender is not a registered WordPress user.',
		'not_admitted'         => 'Skipped: sender is registered but not an admitted artist.',
		'throttled'            => 'Skipped: sender exceeded the per-hour intake limit.',
		'auth_failed'          => 'Skipped: message failed SPF/DKIM authentication.',
		'no_attachments'       => 'Skipped: no valid image, audio, or video attachment found in the message.',
		'goodbye_non_artist'   => 'Skipped: goodbye request from a non-artist sender.',
		'goodbye_handled'      => 'Goodbye request processed — self-removal confirmation sent.',
	];

	/**
	 * Record a skipped message in the queue table so we never re-check it.
	 *
	 * Uses status 'failed' with a reason-specific error so it appears in the
	 * queue and can be inspected, but won't be picked up by PostCreator.
	 * INSERT IGNORE is used because a row may already exist (e.g. previous skip).
	 *
	 * $artist_id is persisted whenever it's already known (i.e. the sender did
	 * resolve to a WP user, even if not an admitted artist) purely so the Inbox
	 * admin table can show who actually sent it instead of always falling back
	 * to "unregistered sender". These rows are stored with raw_email = '{}' —
	 * there was never a real submission to preserve — and is_already_queued()
	 * deliberately does NOT auto-retry them (unlike a genuine PostCreator
	 * processing failure, which keeps the full original submission and is
	 * worth retrying): the underlying email never changes, so a retry can't
	 * produce a different outcome, and resetting one of these to 'pending'
	 * previously handed PostCreator::handle() a genuinely empty submission —
	 * which slipped past every "no attachment" guard and published a blank,
	 * untitled draft. If a sender is later admitted, they simply need to
	 * resend their email — see is_already_queued()'s 'failed' case.
	 *
	 * @param string   $uid       IMAP message UID.
	 * @param int|null $artist_id WP user ID of the sender, if resolved. Null when
	 *                            the sender never matched a WP account at all.
	 * @param string   $reason    Key into self::SKIP_REASONS.
	 */
	private function mark_no_artwork( string $uid, ?int $artist_id = null, string $reason = 'unregistered_sender' ): void {
		global $wpdb;

		$error = self::SKIP_REASONS[ $reason ] ?? self::SKIP_REASONS['unregistered_sender'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table insert; INSERT IGNORE is idempotent.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}agnosis_queue
				 (message_uid, artist_id, status, raw_email, error)
				 VALUES (%s, %s, 'failed', '{}', %s)",
				$uid,
				null !== $artist_id ? (string) $artist_id : null,
				$error
			)
		);
	}

	/**
	 * Check whether a WP user is an admitted Agnosis artist (or an admin).
	 *
	 * Delegates to the shared Admission::is_admitted_artist() so the logic
	 * stays in one place across all intake paths.
	 */
	private function is_admitted_artist( int $user_id ): bool {
		return Admission::is_admitted_artist( $user_id );
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
		// raw_email IS selected — the 'failed' branch below needs it to tell a real
		// processing failure apart from an intake-gate skip (see that branch).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- idempotency check on custom table; must be real-time.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, updated_at, raw_email FROM {$wpdb->prefix}agnosis_queue WHERE message_uid = %s LIMIT 1",
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
				// Two very different things share the 'failed' status:
				//   - A real PostCreator::handle() processing failure (AI timeout,
				//     provider error, etc.) — mark() only ever touches status/error/
				//     post_id, so raw_email still holds the full original submission.
				//     Retrying this is exactly the point: the same content might
				//     succeed on a second attempt.
				//   - An intake-gate skip recorded by mark_no_artwork() (unregistered
				//     sender, not admitted, throttled, auth failed, or — critically —
				//     no valid attachment found) — these rows are inserted with
				//     raw_email = '{}' on purpose, since there was never a real
				//     submission to store. The underlying email never changes, so
				//     retrying can't produce a different outcome; worse, resetting
				//     one of these to 'pending' hands PostCreator::handle() a
				//     genuinely empty submission, which previously slipped past every
				//     "no attachment" guard (those only checked the attach_count > 0
				//     branch) and published a blank, untitled draft. Recognise that
				//     shape here and leave the row alone instead of resurrecting it.
				$decoded     = json_decode( (string) $row->raw_email, true );
				$nothing_to_retry = empty( $decoded )
					|| ( empty( $decoded['attachments'] ?? null ) && empty( $decoded['description'] ?? '' ) );
				if ( $nothing_to_retry ) {
					return true; // Nothing retriable here — already correctly marked failed/skipped.
				}

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

		// Write each attachment binary to the agnosis-queue temp directory so the
		// database never holds large binary payloads.  PostCreator reads the file
		// back when it processes the row, then deletes it after upload.
		foreach ( $submission['attachments'] as $i => &$att ) {
			if ( isset( $att['data'] ) ) {
				$path = AttachmentStore::store( $uid, $i, $att['filename'] ?? '', $att['data'] );
				if ( '' !== $path ) {
					$att['file'] = $path;
					unset( $att['data'], $att['encoding'] );
				} else {
					// Store failed (disk full, permissions) — fall back to base64 so the
					// submission is not silently lost.
					$att['data']     = base64_encode( $att['data'] );
					$att['encoding'] = 'base64';
					Logger::warning( sprintf( 'AttachmentStore write failed for UID %s att %d; falling back to base64.', $uid, $i ), 'inbox' );
				}
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no WP abstraction; INSERT IGNORE is inherently uncacheable.
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

		// Sweep any orphaned temp attachment directories left by failed or
		// permanently-stuck queue rows.
		AttachmentStore::sweep_orphans( $days );
	}
}
