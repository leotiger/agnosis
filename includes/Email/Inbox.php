<?php
/**
 * IMAP inbox poller.
 *
 * Connects to the configured mailbox, fetches unseen messages,
 * pushes each one into the submission queue, then marks as seen.
 *
 * @package Agnosis\Email
 */

declare(strict_types=1);

namespace Agnosis\Email;

use Agnosis\Email\Parser;

class Inbox {

	private Parser $parser;

	public function __construct() {
		$this->parser = new Parser();
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	/** Schedule the cron poll if not already scheduled (idempotent). */
	public function schedule_poll(): void {
		if ( ! wp_next_scheduled( 'agnosis_poll_inbox' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'agnosis_poll_inbox' );
		}
	}

	/** Cron callback — poll the IMAP inbox for new submissions. */
	public function poll(): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		$connection = $this->connect();
		if ( false === $connection ) {
			$this->log( 'IMAP connection failed: ' . imap_last_error() );
			return;
		}

		try {
			$this->process_messages( $connection );
		} finally {
			imap_close( $connection );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function is_configured(): bool {
		return ! empty( get_option( 'agnosis_imap_host' ) )
			&& ! empty( get_option( 'agnosis_imap_user' ) )
			&& ! empty( get_option( 'agnosis_imap_pass' ) );
	}

	/** @return \IMAP\Connection|false */
	private function connect() {
		$host    = get_option( 'agnosis_imap_host' );
		$port    = (int) get_option( 'agnosis_imap_port', 993 );
		$ssl     = (bool) get_option( 'agnosis_imap_ssl', true );
		$user    = get_option( 'agnosis_imap_user' );
		$pass    = get_option( 'agnosis_imap_pass' );
		$mailbox = sprintf( '{%s:%d/imap%s}INBOX', $host, $port, $ssl ? '/ssl' : '' );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- imap_open emits PHP notices on connection failure; the return value is the authoritative error signal.
		return @imap_open( $mailbox, $user, $pass, 0, 1 );
	}

	/** @param \IMAP\Connection $conn */
	private function process_messages( $conn ): void {
		$message_ids = imap_search( $conn, 'UNSEEN' );

		if ( false === $message_ids || empty( $message_ids ) ) {
			return;
		}

		foreach ( $message_ids as $msg_num ) {
			try {
				$uid     = (string) imap_uid( $conn, $msg_num );
				$headers = imap_headerinfo( $conn, $msg_num );

				if ( false === $headers ) {
					continue;
				}

				// Skip if already queued.
				if ( $this->is_already_queued( $uid ) ) {
					continue;
				}

				$submission = $this->parser->parse_imap_message( $conn, $msg_num, $headers );

				if ( null !== $submission ) {
					$this->enqueue( $uid, $submission );
				}

				// Mark as seen regardless of parse result.
				imap_setflag_full( $conn, (string) $msg_num, '\\Seen' );

			} catch ( \Throwable $e ) {
				$this->log( 'Error processing message #' . $msg_num . ': ' . $e->getMessage() );
			}
		}
	}

	private function is_already_queued( string $uid ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; caching an idempotency check would risk duplicate queue entries.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}agnosis_queue WHERE message_uid = %s LIMIT 1",
				$uid
			)
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table, no WP abstraction available.
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_queue',
			[
				'message_uid' => $uid,
				'artist_id'   => $submission['artist_id'] ?? null,
				'raw_email'   => wp_json_encode( $submission ),
				'status'      => 'pending',
			],
			[ '%s', '%d', '%s', '%s' ]
		);

		$queue_id = $wpdb->insert_id;

		if ( $queue_id ) {
			// Schedule async processing (WP-Cron single event, fires immediately).
			wp_schedule_single_event(
				time(),
				'agnosis_publish_submission',
				[ $queue_id ]
			);
		}
	}

	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Agnosis Inbox] ' . $message );
		}
	}
}
