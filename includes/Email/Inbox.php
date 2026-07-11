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
use Agnosis\Artist\CommunityBroadcast;
use Agnosis\Artist\Departure;
use Agnosis\Core\Debug;
use Agnosis\Core\Logger;
use Agnosis\Core\RateLimiter;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

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
	 * Cron callback — purge old IMAP messages, stale queue rows, old log
	 * entries, and expired debug-tracing dumps.
	 *
	 * Reads `agnosis_imap_cleanup_days` (default 7 — matches the Settings
	 * field's own documented default and the value Activator seeds on
	 * install; audit §5e flagged this docblock and two other call sites for
	 * previously reading 30 here instead, a documentation/behavior drift
	 * that only ever bit a fresh install whose option row was somehow
	 * missing). Any SEEN IMAP message older than that threshold is
	 * permanently deleted. Queue rows that are
	 * 'failed' or 'done' and older than the same threshold are also pruned.
	 *
	 * Debug dumps (fourth audit §5c) use their own, shorter-lived
	 * `agnosis_debug_retention_days` (default 14) rather than reusing the
	 * IMAP/queue threshold — a raw pipeline dump is a full copy of an
	 * artist's raw email, so it's the most sensitive thing this cron touches
	 * and defaults to expiring sooner, independent of whatever retention an
	 * admin has configured for routine IMAP/queue housekeeping.
	 */
	public function cleanup(): void {
		$days = max( 1, (int) get_option( 'agnosis_imap_cleanup_days', 7 ) );
		$this->cleanup_imap();
		$this->cleanup_queue();
		Logger::prune( $days );
		Debug::prune( max( 1, (int) get_option( 'agnosis_debug_retention_days', 14 ) ) );
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
	 * Detect an IMAP UIDVALIDITY change and reset the UID cursor when one
	 * occurs (reliability audit §5d).
	 *
	 * Per RFC 3501 §2.3.1.1, a UID is only guaranteed stable within one
	 * UIDVALIDITY generation for a folder — a mailbox rebuild, a provider
	 * migration, or the folder itself being recreated can (and in practice
	 * does) make the server reissue UIDs starting from 1 again while
	 * UIDVALIDITY changes to signal exactly that. Without this check,
	 * `agnosis_imap_last_uid` survives such an event unnoticed: every new
	 * message now has a UID below the stale cursor, `UID N+1:*`
	 * (query_messages()) matches nothing ever again, and the failure is
	 * silent — no error, the cron keeps running, the queue keeps "draining"
	 * (there's simply nothing new in it) — indistinguishable from the
	 * outside from "artists just stopped emailing."
	 *
	 * Called at the very top of process_messages(), before the cursor is
	 * read, so a reset here lands in the very same poll: query_messages()
	 * then takes its existing $last_uid === 0 branch and rescans the
	 * date-bounded retention window instead of an empty `UID 1:*`.
	 *
	 * @param Folder $folder The currently selected/examined IMAP folder.
	 */
	private function check_uidvalidity( Folder $folder ): void {
		try {
			$status = $folder->getStatus();
		} catch ( \Throwable $e ) {
			// This is a diagnostic add-on to the cursor, never a prerequisite
			// for polling — if the server doesn't answer STATUS cleanly,
			// fall through and let the existing cursor logic run exactly as
			// it did before this check existed, rather than failing the poll
			// over a check whose whole purpose is preventing silent failure.
			Logger::warning( 'Poll: could not read folder STATUS for the UIDVALIDITY check — ' . $e->getMessage(), 'inbox' );
			return;
		}

		$current = (int) ( $status['uidvalidity'] ?? 0 );
		if ( $current <= 0 ) {
			return; // Server didn't report one (rare, but MUST is not MUST-implement-correctly) — nothing to compare.
		}

		$stored = (int) get_option( 'agnosis_imap_uidvalidity', 0 );

		if ( 0 === $stored ) {
			// First poll we've ever tracked UIDVALIDITY for this mailbox —
			// nothing to compare against yet, just start tracking it.
			update_option( 'agnosis_imap_uidvalidity', $current );
			return;
		}

		if ( $stored !== $current ) {
			Logger::warning(
				sprintf(
					'Poll: IMAP UIDVALIDITY changed (%d → %d) — the mailbox was rebuilt, migrated, or the folder recreated. Resetting the UID cursor to 0 so the next query rescans the retention window instead of silently matching nothing.',
					$stored,
					$current
				),
				'inbox'
			);
			update_option( 'agnosis_imap_last_uid', 0 );
			update_option( 'agnosis_imap_uidvalidity', $current );
		}
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
		// UIDVALIDITY guard (security/reliability audit §5d) — must run
		// before the cursor below is read, so a reset here is picked up by
		// this same poll cycle rather than the next one.
		$this->check_uidvalidity( $folder );

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

		$goodbye_addr   = strtolower( trim( (string) get_option( 'agnosis_email_goodbye', '' ) ) );
		$community_addr = strtolower( trim( (string) get_option( 'agnosis_email_community', '' ) ) );

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

				// Resolved once, up front (fourth audit §3b): the auth gate just below
				// — now evaluated before any alias routing — needs an artist_id for
				// accurate logging/admin-table display, the same identity "cheap gate
				// 1" further down used to resolve when auth was checked after it. This
				// is a plain lookup for logging purposes only, NOT an admission check —
				// the goodbye/community alias handlers below do their own, and gate 1
				// below still performs the real admitted-artist gate.
				$artist_user = $from_email ? get_user_by( 'email', $from_email ) : null;
				$artist_id   = $artist_user ? $artist_user->ID : null;

				// --- Bounce/complaint DSN (security audit §5a) — checked BEFORE the
				// auth gate below, deliberately: a DSN is a legitimate delivery-status
				// notification from the RECEIVING mail server, not a message an artist
				// authenticated, so it will routinely fail SPF/DKIM-for-artist-domain
				// checks when agnosis_require_email_auth is on. Requiring that gate
				// here would silently discard the bounce signal that gate has nothing
				// to do with. Previously (and still, when this doesn't match) every DSN
				// fell through to "cheap gate 1" below and was recorded as an
				// unregistered_sender skip — noise in the Inbox table that also threw
				// away the one thing worth reading in the message.
				if ( $this->is_bounce_dsn( $message ) ) {
					$this->handle_bounce_dsn( $message, $from_email, $uid );
					continue;
				}

				// --- Auth gate (opt-in, fourth audit §3b) — evaluated BEFORE any
				// alias routing. Previously this ran as "cheap gate 3", ~40 lines
				// below, reached only by messages that fell through to the normal
				// artwork/bio/event pipeline — so the opt-in SPF/DKIM hardening this
				// option promises never covered the two aliases where a spoofed From
				// does the most damage: an impersonated community@ broadcast (relayed
				// to every other artist under the spoofed sender's own name) and a
				// forged goodbye@ removal request. Moving the check here closes both.
				if ( ! $this->passes_email_auth( $message, $from_email, $uid ) ) {
					$this->mark_no_artwork( $uid, $artist_id, 'auth_failed', $from_email );
					continue;
				}

				// --- Goodbye alias: self-removal request (no attachment required) ---
				// fifth audit §5a: matched against every To:/Cc: recipient, not
				// just To[0] — an artist writing to goodbye@ while CCing someone
				// else, or whose mail client serialised To: in an unexpected
				// order, used to fall through to the normal pipeline instead.
				if ( $goodbye_addr ) {
					$recipients = $this->message_recipient_addresses( $message );
					if ( in_array( $goodbye_addr, $recipients, true ) ) {
						$this->handle_goodbye_email( $from_email, $uid );
						continue;
					}
				}

				// --- Community alias: broadcast to all other artists (no attachment required) ---
				if ( $community_addr ) {
					$recipients = $this->message_recipient_addresses( $message );
					if ( in_array( $community_addr, $recipients, true ) ) {
						$this->handle_community_email( $message, $from_email, $uid );
						continue;
					}
				}

				// --- Cheap gate 1: admitted sender (header-only) -------------------
				// $artist_user / $artist_id already resolved above.
				if ( null === $artist_id ) {
					Logger::warning( 'Skipped UID ' . $uid . ': unregistered sender <' . $from_email . '>.', 'inbox' );
					$this->mark_no_artwork( $uid, null, 'unregistered_sender', $from_email );
					continue;
				}

				if ( ! $this->is_admitted_artist( $artist_id ) ) {
					Logger::warning( 'Skipped UID ' . $uid . ': sender <' . $from_email . '> (user #' . $artist_id . ') is not admitted.', 'inbox' );
					$this->mark_no_artwork( $uid, $artist_id, 'not_admitted', $from_email );
					continue;
				}

				// --- Cheap gate 2: per-sender intake throttle ----------------------
				$sender_limit  = max( 1, (int) get_option( 'agnosis_intake_per_sender_limit', 5 ) );
				$throttle      = RateLimiter::check_sender( 'email_intake', $from_email, $sender_limit, HOUR_IN_SECONDS );
				if ( is_wp_error( $throttle ) ) {
					Logger::warning( 'Skipped UID ' . $uid . ': sender <' . $from_email . '> throttled (' . $sender_limit . '/hour limit).', 'inbox' );
					$this->mark_no_artwork( $uid, $artist_id, 'throttled', $from_email );
					continue;
				}

				// Cheap gate 3 (SPF/DKIM) used to run here — moved above alias
				// routing (fourth audit §3b); see passes_email_auth() and its call
				// site near the top of this loop, right after $from_email/$artist_id
				// are resolved. Every message reaching this point has already passed
				// it, regardless of which branch (goodbye/community/normal) it took.

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

					$this->mark_no_artwork( $uid, $artist_id, 'no_attachments', $from_email );

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

		// Default aligned to 7 (audit §5e) — matches the Settings field's own
		// documented default and Activator's seeded value; this fallback is
		// only ever consulted if that option row is somehow missing.
		$days  = max( 1, (int) get_option( 'agnosis_imap_cleanup_days', 7 ) );
		$since = new \DateTime( '-' . $days . ' days' );
		$messages = $base->since( $since )->get();
		Logger::info(
			sprintf( 'Poll: no UID cursor — scanning %d message(s) from last %d day(s).', $messages->count(), $days ),
			'inbox'
		);
		return $messages;
	}

	/**
	 * Collect every To:/Cc: address on a message, lowercased (fifth audit
	 * §5a). Previously alias detection only ever read To[0], so intent lost
	 * to header order for any message where the alias wasn't the first To:
	 * recipient (e.g. CCing a friend on a goodbye@/community@ message).
	 * getCc() is wrapped in a try/catch since not every Message double used
	 * in tests implements it (FakeAliasImapMessage deliberately doesn't —
	 * see its own docblock); To: addresses alone are still collected either way.
	 *
	 * @param object $message webklex Message (or a test double exposing getTo()).
	 * @return string[] Lowercased, sanitized email addresses (To + Cc combined).
	 */
	private function message_recipient_addresses( object $message ): array {
		$addrs = [];
		// @phpstan-ignore-next-line -- $message is deliberately typed `object` (see docblock above) so test doubles that only duck-type getTo() still pass; real webklex Message always has it.
		foreach ( $message->getTo()->toArray() as $a ) {
			$addrs[] = strtolower( sanitize_email( (string) ( $a->mail ?? '' ) ) );
		}
		try {
			// @phpstan-ignore-next-line -- same reasoning as getTo() above; getCc() is additionally guarded by the try/catch below for doubles that omit it entirely.
			foreach ( $message->getCc()->toArray() as $a ) {
				$addrs[] = strtolower( sanitize_email( (string) ( $a->mail ?? '' ) ) );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- message double doesn't implement getCc(); To: alone is fine.
		}
		return array_values( array_unique( array_filter( $addrs ) ) );
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
			$this->mark_no_artwork( $uid, $user ? (int) $user->ID : null, 'goodbye_non_artist', $from_email );
			return;
		}

		// Per-sender throttle (fourth audit §3b): a genuine self-removal only
		// ever needs to be requested once. Without this, a spoofed From (or a
		// confused repeat sender) could trigger unlimited removal-confirmation
		// emails to the real artist's inbox — the same 'goodbye_request' bucket
		// key is shared with Webhook::handle()'s goodbye branch, mirroring how
		// 'community_broadcast' is already shared between both intake paths.
		$limit    = max( 1, (int) get_option( 'agnosis_goodbye_request_limit', 3 ) );
		$throttle = RateLimiter::check_sender( 'goodbye_request', $from_email, $limit, DAY_IN_SECONDS );
		if ( is_wp_error( $throttle ) ) {
			Logger::warning(
				'Goodbye email from <' . $from_email . '> (user #' . $user->ID . '): daily limit (' . $limit . ') reached — ignored.',
				'inbox'
			);
			$this->mark_no_artwork( $uid, $user->ID, 'goodbye_throttled', $from_email );
			return;
		}

		$departure = new Departure();
		$ok        = $departure->initiate_removal_for_user( $user->ID );

		// $ok reflects whether initiate_removal_for_user() actually found an active
		// (admitted/banned) agnosis_applications row and dispatched the confirmation
		// email — is_admitted_artist() above only checks the WP role/capability, which
		// can legitimately be true while that membership row is missing or in some
		// other status. The two outcomes are NOT the same and must not share a reason:
		// previously both were recorded as 'goodbye_handled', so a sender for whom no
		// email was ever sent still showed "self-removal confirmation sent." in the
		// Inbox admin table.
		if ( $ok ) {
			Logger::info(
				'Goodbye email from <' . $from_email . '> (user #' . $user->ID . '): confirmation sent.',
				'inbox'
			);
			$this->mark_no_artwork( $uid, $user->ID, 'goodbye_handled', $from_email );
		} else {
			Logger::warning(
				'Goodbye email from <' . $from_email . '> (user #' . $user->ID . '): no active membership — ignored.',
				'inbox'
			);
			$this->mark_no_artwork( $uid, $user->ID, 'goodbye_no_membership', $from_email );
		}
	}

	/**
	 * Opt-in SPF/DKIM authentication gate (fourth audit §3b).
	 *
	 * Evaluated for EVERY message in process_messages() — including the
	 * goodbye@/community@ aliases — before any routing decision is made, not
	 * just messages that fall through to the normal artwork/bio/event
	 * pipeline. Returns true immediately (no-op) when the
	 * `agnosis_require_email_auth` option is off, which it is by default.
	 *
	 * Deliberately untyped for $message (not `Message`, despite this always
	 * being a real webklex Message in production): called unconditionally for
	 * EVERY message before any routing decision, including in test doubles
	 * (e.g. FakeImapMessage in InboxUidCursorTest) that duck-type only the
	 * methods process_messages() itself calls directly and don't implement
	 * getHeader() at all. Safe because this method returns true immediately,
	 * before ever touching $message, whenever agnosis_require_email_auth is
	 * off — the default, and the case every existing test double relies on.
	 *
	 * @param object $message    Full IMAP message (read for the Authentication-Results header).
	 * @param string $from_email Sender address, used only for logging.
	 * @param string $uid        IMAP UID, used only for logging.
	 * @return bool True when the message passes (or auth is not required at all).
	 */
	private function passes_email_auth( object $message, string $from_email, string $uid ): bool {
		if ( ! get_option( 'agnosis_require_email_auth' ) ) {
			return true;
		}

		$auth_raw = '';
		try {
			// @phpstan-ignore-next-line -- $message is deliberately typed `object` (see docblock above) so test doubles that don't implement getHeader() can still be passed; real webklex Message always has it, and any mismatch is caught below anyway.
			$auth_raw = (string) $message->getHeader()->get( 'authentication-results' );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- header absent or library threw; $auth_raw stays ''
		}

		if ( ! $auth_raw ) {
			Logger::warning( 'Skipped UID ' . $uid . ': auth required but no Authentication-Results header.', 'inbox' );
			return false;
		}

		$verdicts = EmailAuth::check_header( $auth_raw );
		if ( ! EmailAuth::passes( $verdicts ) ) {
			Logger::warning(
				sprintf( 'Skipped UID ' . $uid . ': <' . $from_email . '> failed auth — SPF=%s DKIM=%s.', $verdicts['spf'] ?: 'none', $verdicts['dkim'] ?: 'none' ),
				'inbox'
			);
			return false;
		}

		return true;
	}

	/**
	 * Whether a message carries an `Auto-Submitted` header indicating it is an
	 * automated response (vacation auto-reply, mailing-list bounce, etc.)
	 * rather than a human-written email (fourth audit §3c).
	 *
	 * Per RFC 3834, genuine auto-responses set this header to a value other
	 * than `no` (typically `auto-replied`) — normal mail clients either omit
	 * the header entirely or, less commonly, set it explicitly to `no`. Either
	 * of those is treated as "not an auto-response" here; only a present,
	 * non-`no` value trips this guard, which is why an absent header (the
	 * overwhelming majority of real mail) is not treated as suspicious.
	 *
	 * Deliberately untyped for $message for the same reason as
	 * passes_email_auth() above — but unlike that method, this one is only
	 * ever called from handle_community_email(), whose own $message parameter
	 * is already typed `Message`, so this is never reached with a test double
	 * lacking getHeader() in practice.
	 *
	 * @param object $message Full IMAP message (read for the Auto-Submitted header).
	 * @return bool True when the message looks like an automated response.
	 */
	private function is_auto_submitted( object $message ): bool {
		$raw = '';
		try {
			// @phpstan-ignore-next-line -- $message is deliberately typed `object` (see docblock above); only ever called with a real webklex Message in practice, and any mismatch is caught below anyway.
			$raw = (string) $message->getHeader()->get( 'auto-submitted' );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- header absent or library threw; $raw stays ''
		}

		$value = strtolower( trim( $raw ) );
		return '' !== $value && 'no' !== $value;
	}

	/**
	 * Handle a community-broadcast email: verify the sender is an admitted
	 * artist and within their daily send limit, relay it to every other
	 * admitted artist via CommunityBroadcast, then mark the message so it
	 * won't be re-processed. Never reaches the artwork/bio/event pipeline.
	 *
	 * @param Message $message    Full webklex message (needed for subject/body — unlike
	 *                            the goodbye alias above, which needs neither).
	 * @param string  $from_email Sender email address.
	 * @param string  $uid        IMAP message UID (used for deduplication).
	 */
	private function handle_community_email( Message $message, string $from_email, string $uid ): void {
		// Mail-loop guard (fourth audit §3c): broadcast copies deliberately set
		// Reply-To to this same alias (see CommunityBroadcast::send_one()'s
		// docblock) so a human reply gets translated for everyone — but that
		// means a recipient's vacation auto-responder also fires on the
		// broadcast and lands right back here. RFC 3834-compliant
		// auto-responders (and most ESPs / mail clients) set
		// `Auto-Submitted: auto-replied` (never `no`) on those messages —
		// checked first, before resolving the sender at all, so an
		// auto-response never even counts against anyone's throttle.
		if ( $this->is_auto_submitted( $message ) ) {
			Logger::info( 'Community broadcast from <' . $from_email . '> looks like an auto-response (Auto-Submitted header) — ignored, not broadcast.', 'inbox' );
			$this->mark_no_artwork( $uid, null, 'community_auto_submitted', $from_email );
			return;
		}

		$user = get_user_by( 'email', $from_email );

		if ( ! $user || ! $this->is_admitted_artist( $user->ID ) ) {
			Logger::warning(
				'Community broadcast from non-artist <' . $from_email . '> — ignored.',
				'inbox'
			);
			$this->mark_no_artwork( $uid, $user ? (int) $user->ID : null, 'community_non_artist', $from_email );
			return;
		}

		$limit    = max( 1, (int) get_option( 'agnosis_community_broadcast_limit', 3 ) );
		$throttle = RateLimiter::check_sender( 'community_broadcast', $from_email, $limit, DAY_IN_SECONDS );
		if ( is_wp_error( $throttle ) ) {
			Logger::warning(
				'Community broadcast from <' . $from_email . '> (user #' . $user->ID . '): daily limit (' . $limit . ') reached — ignored.',
				'inbox'
			);
			$this->mark_no_artwork( $uid, $user->ID, 'community_throttled', $from_email );
			return;
		}

		$parsed = $this->parser->parse_broadcast_body( $message );

		if ( '' === trim( $parsed['subject'] ) && '' === trim( $parsed['body'] ) ) {
			Logger::warning( 'Community broadcast from <' . $from_email . '> was empty — bounced.', 'inbox' );
			( new CommunityBroadcast() )->send_empty_bounce( $user->ID );
			$this->mark_no_artwork( $uid, $user->ID, 'community_empty', $from_email );
			return;
		}

		$broadcast = new CommunityBroadcast();

		// Checked BEFORE broadcast() — the whole point is to bail out before
		// any AI translation calls are made (one per recipient), not after.
		if ( $broadcast->exceeds_max_length( $parsed['subject'], $parsed['body'] ) ) {
			$length = mb_strlen( $parsed['subject'] ) + mb_strlen( $parsed['body'] );
			Logger::warning(
				'Community broadcast from <' . $from_email . '> (user #' . $user->ID . '): ' . $length . ' characters exceeds the configured limit — bounced.',
				'inbox'
			);
			$broadcast->send_too_long_bounce( $user->ID, $length );
			$this->mark_no_artwork( $uid, $user->ID, 'community_too_long', $from_email );
			return;
		}

		$sent = $broadcast->broadcast( $user->ID, $parsed['subject'], $parsed['body'] );

		Logger::info(
			'Community broadcast from <' . $from_email . '> (user #' . $user->ID . '): sent to ' . $sent . ' recipient(s).',
			'inbox'
		);
		$this->mark_no_artwork( $uid, $user->ID, 'community_handled', $from_email );
	}

	/**
	 * Whether a message looks like a bounce/complaint DSN rather than a
	 * genuine artist submission (security audit §5a) — checked in the same
	 * cheap, header-only pass as the other gates in process_messages(),
	 * before the auth gate or any admitted-sender check: a DSN's From
	 * address is virtually never an admitted artist (it's the receiving
	 * mail server's own mailer-daemon), so without this check every DSN
	 * silently became an 'unregistered_sender' skip — the bounce signal
	 * itself thrown away instead of feeding suppression.
	 *
	 * Two independent signals, either sufficient:
	 *   - Content-Type: multipart/report (RFC 3462) — the standard MIME
	 *     wrapper for both delivery-status (bounce) and feedback-report
	 *     (spam complaint) notifications.
	 *   - X-Failed-Recipients — not RFC-standard, but a de-facto header
	 *     several common MTAs attach directly; cheaper to trust when present
	 *     than parsing the body for anything.
	 *
	 * @param object $message Full IMAP message (read for headers only here).
	 * @return bool
	 */
	private function is_bounce_dsn( object $message ): bool {
		$content_type = '';
		try {
			// @phpstan-ignore-next-line -- $message is deliberately typed `object` (see passes_email_auth()'s docblock for why); real webklex Message always has getHeader().
			$content_type = (string) $message->getHeader()->get( 'content-type' );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- header absent or library threw; not a DSN by this signal.
		}

		if ( false !== stripos( $content_type, 'multipart/report' ) ) {
			return true;
		}

		$failed_recipients = '';
		try {
			// @phpstan-ignore-next-line -- same reasoning as above.
			$failed_recipients = (string) $message->getHeader()->get( 'x-failed-recipients' );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- header absent or library threw.
		}

		return '' !== trim( $failed_recipients );
	}

	/**
	 * Extract the failed recipient(s) from a recognized bounce/complaint DSN
	 * and route each through Email\BounceHandler::record(), then mark the
	 * message so it's never re-checked (security audit §5a).
	 *
	 * Address extraction, cheapest-first:
	 *   1. X-Failed-Recipients header, if present — no body fetch needed.
	 *   2. Otherwise the machine-readable delivery-status part's
	 *      "Final-Recipient: rfc822; <addr>" line (RFC 3464) — the one body
	 *      fetch this method performs, worth it since a message already
	 *      recognized as a DSN is rare and the address is the entire point
	 *      of reading it.
	 *
	 * A feedback-report (spam complaint) DSN carrying neither is logged and
	 * skipped rather than guessed at — ARF's own address-of-record is
	 * frequently the reporting mailbox provider's abuse desk, not the
	 * complaining recipient, so guessing here risks suppressing the wrong
	 * address entirely.
	 *
	 * @param object $message    Full IMAP message.
	 * @param string $from_email The DSN's own sender (e.g. a mailer-daemon
	 *                           address) — logged/persisted the same way
	 *                           every other mark_no_artwork() call site does,
	 *                           not the address that actually bounced.
	 * @param string $uid        IMAP message UID (used for deduplication/logging).
	 */
	private function handle_bounce_dsn( object $message, string $from_email, string $uid ): void {
		$recipients = [];

		$failed_recipients_header = '';
		try {
			// @phpstan-ignore-next-line -- see is_bounce_dsn()'s docblock.
			$failed_recipients_header = (string) $message->getHeader()->get( 'x-failed-recipients' );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- header absent or library threw.
		}

		if ( '' !== trim( $failed_recipients_header ) ) {
			foreach ( explode( ',', $failed_recipients_header ) as $addr ) {
				$addr = sanitize_email( trim( $addr ) );
				if ( '' !== $addr ) {
					$recipients[] = $addr;
				}
			}
		}

		if ( empty( $recipients ) ) {
			// @phpstan-ignore-next-line -- $message is deliberately typed `object`; only ever a real webklex Message in practice for a message that reached this branch.
			$body = (string) $message->getTextBody();
			if ( preg_match_all( '/Final-Recipient:\s*rfc822;\s*([^\s,]+)/i', $body, $matches ) ) {
				foreach ( $matches[1] as $addr ) {
					$addr = sanitize_email( trim( $addr ) );
					if ( '' !== $addr ) {
						$recipients[] = $addr;
					}
				}
			}
		}

		$recipients = array_values( array_unique( $recipients ) );

		if ( empty( $recipients ) ) {
			Logger::info( 'IMAP UID ' . $uid . ': recognized as a bounce/complaint DSN but no failed-recipient address could be extracted — skipped.', 'inbox' );
			$this->mark_no_artwork( $uid, null, 'bounce_unresolved', $from_email );
			return;
		}

		foreach ( $recipients as $addr ) {
			BounceHandler::record( $addr, 'bounce', 'imap' );
		}

		Logger::info( 'IMAP UID ' . $uid . ': bounce/complaint DSN processed for ' . implode( ', ', $recipients ) . '.', 'inbox' );
		$this->mark_no_artwork( $uid, null, 'bounce_handled', $from_email );
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
	 * Public (was private) as of the Inbox admin table's reason filter/spam
	 * aggregation (audit §4c) — Admin\InboxPage matches this exact prose
	 * against the queue row's `error` column to filter by reason and to
	 * exclude 'unregistered_sender' rows from the default view, rather than
	 * duplicating these strings in a second place that could drift out of
	 * sync with them.
	 *
	 * The goodbye_ and community_ prefixed entries are composed from
	 * IntakeGates::SHARED_ALIAS_REASONS (sixth audit §6) — the same shared
	 * source Webhook::ALIAS_REASONS uses — spliced between this transport's
	 * own IMAP-only gate reasons and its two bounce/DSN reasons (neither of
	 * which the webhook transport has an equivalent for) via array `+`, which
	 * preserves the exact same key order as before this refactor.
	 *
	 * @var array<string, string>
	 */
	public const SKIP_REASONS = [
		'unregistered_sender' => 'Skipped: sender is not a registered WordPress user.',
		'not_admitted'        => 'Skipped: sender is registered but not an admitted artist.',
		'throttled'           => 'Skipped: sender exceeded the per-hour intake limit.',
		'auth_failed'         => 'Skipped: message failed SPF/DKIM authentication.',
		'no_attachments'      => 'Skipped: no valid image, audio, or video attachment found in the message.',
	] + IntakeGates::SHARED_ALIAS_REASONS + [
		'bounce_handled'    => 'Bounce/complaint DSN processed — the failed recipient address was suppressed and/or an artist bounce counter was incremented.',
		'bounce_unresolved' => 'Recognized as a bounce/complaint DSN, but no failed-recipient address could be extracted from it.',
	];

	/**
	 * Per-reason override for the queue row's status. Reasons not listed here
	 * default to 'failed' — the historical behaviour, still accurate for every
	 * rejection/gate-skip reason. 'goodbye_handled' is the one reason that
	 * represents an actual success (the confirmation email was sent), so it is
	 * recorded as 'skipped' instead: not an artwork submission, but not a
	 * failure either. See mark_no_artwork() below.
	 *
	 * Composed from IntakeGates::SHARED_ALIAS_STATUSES (sixth audit §6) plus
	 * this transport's own two bounce/DSN statuses — see SKIP_REASONS above.
	 *
	 * @var array<string, string>
	 */
	private const SKIP_STATUSES = IntakeGates::SHARED_ALIAS_STATUSES + [
		'bounce_handled'    => 'skipped',
		'bounce_unresolved' => 'skipped',
	];

	/**
	 * Record a skipped message in the queue table so we never re-check it.
	 *
	 * Status defaults to 'failed' with a reason-specific error so it appears in
	 * the queue and can be inspected, but won't be picked up by PostCreator.
	 * A small number of reasons (see self::SKIP_STATUSES) represent a genuine
	 * success rather than a rejection and are recorded with a different status
	 * instead, so the Inbox admin table doesn't show a misleading red "Failed"
	 * badge for something that worked as intended. INSERT IGNORE is used because
	 * a row may already exist (e.g. previous skip).
	 *
	 * $artist_id is persisted whenever it's already known (i.e. the sender did
	 * resolve to a WP user, even if not an admitted artist) purely so the Inbox
	 * admin table can show who actually sent it instead of always falling back
	 * to "unregistered sender". Likewise $from_email — when known — is persisted
	 * as a minimal {"from": "..."} JSON blob in raw_email (instead of the bare
	 * '{}' used previously) purely so the admin table's From column can display
	 * the sender's address instead of an em dash; it carries no attachments or
	 * description, so is_already_queued()'s "nothing to retry" check below still
	 * treats these rows as empty. is_already_queued() deliberately does NOT
	 * auto-retry them (unlike a genuine PostCreator processing failure, which
	 * keeps the full original submission and is worth retrying): the underlying
	 * email never changes, so a retry can't produce a different outcome, and
	 * resetting one of these to 'pending' previously handed PostCreator::handle()
	 * a genuinely empty submission — which slipped past every "no attachment"
	 * guard and published a blank, untitled draft. If a sender is later admitted,
	 * they simply need to resend their email — see is_already_queued()'s 'failed'
	 * case.
	 *
	 * @param string   $uid        IMAP message UID.
	 * @param int|null $artist_id  WP user ID of the sender, if resolved. Null when
	 *                             the sender never matched a WP account at all.
	 * @param string   $reason     Key into self::SKIP_REASONS (and, optionally, self::SKIP_STATUSES).
	 * @param string   $from_email Sender email address, if known. Empty string when unavailable.
	 */
	private function mark_no_artwork( string $uid, ?int $artist_id = null, string $reason = 'unregistered_sender', string $from_email = '' ): void {
		global $wpdb;

		$error  = self::SKIP_REASONS[ $reason ] ?? self::SKIP_REASONS['unregistered_sender'];
		$status = self::SKIP_STATUSES[ $reason ] ?? 'failed';

		// Stash the raw reason key (not just its prose $error text) alongside
		// the sender address already persisted here, but only for the
		// genuine-success skips — lets InboxPage::render_status_badge() show
		// something more specific than a flat gray "Skipped" for e.g. a
		// completed self-removal (2026-07-08: a "Skipped" badge on a request
		// that permanently deleted an artist's account and content read as if
		// nothing had happened).
		$meta = [];
		if ( '' !== $from_email ) {
			$meta['from'] = $from_email;
		}
		if ( 'skipped' === $status ) {
			$meta['skip_reason'] = $reason;
		}
		$raw = ! empty( $meta ) ? wp_json_encode( $meta ) : '{}';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table insert; INSERT IGNORE is idempotent.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}agnosis_queue
				 (message_uid, artist_id, status, raw_email, error)
				 VALUES (%s, %s, %s, %s, %s)",
				$uid,
				null !== $artist_id ? (string) $artist_id : null,
				$status,
				$raw,
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
	 *  - failed (retriable)     → reset to pending, true  (retry via Process Queue)
	 *  - failed (gate skip)     → true, left alone  (nothing would change on retry)
	 *  - skipped                → true, left alone  (terminal — e.g. goodbye@ handled)
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
				//     raw_email holding at most a bare {"from": "..."} (or '{}' when
				//     even that isn't known) on purpose, since there was never a real
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

			case 'skipped':
				// Deliberate terminal state (e.g. a handled goodbye@ request) — the
				// underlying email never changes, so there's nothing to retry. Unlike
				// 'failed', this never gets auto-reset; see mark_no_artwork().
				return true;

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

		// Default aligned to 7 (audit §5e) — see poll()'s identical fallback above.
		$days   = max( 1, (int) get_option( 'agnosis_imap_cleanup_days', 7 ) );
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
	 * Prune finished / failed / skipped queue rows older than the retention period.
	 *
	 * Rows with status 'published', 'failed', or 'skipped' that are past the
	 * retention threshold no longer serve any operational purpose and are
	 * removed. 'skipped' rows (e.g. goodbye@ self-removal requests — see
	 * mark_no_artwork()) would otherwise accumulate forever, since they're
	 * never 'failed' and is_already_queued() never touches them again.
	 */
	private function cleanup_queue(): void {
		global $wpdb;

		// Default aligned to 7 (audit §5e) — see poll()'s identical fallback above.
		$days = max( 1, (int) get_option( 'agnosis_imap_cleanup_days', 7 ) );

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE on custom table; caching does not apply to write queries.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}agnosis_queue
				 WHERE status IN ('published','failed','skipped')
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
