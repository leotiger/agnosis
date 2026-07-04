<?php
/**
 * Batch sender for the newsletter send queue.
 *
 * Hooked to the 'agnosis_send_newsletter_queue' cron event (every 5 minutes,
 * the same interval the IMAP inbox poll already registers). Each tick sends a
 * small, configurable batch of pending recipients — never the whole list in
 * one request — so self-hosted sending survives shared-host outbound rate
 * limits and PHP execution time limits even at a few hundred recipients.
 *
 * A recipient whose wp_mail() call fails (transient SMTP hiccup, momentary
 * host rate-limit, etc.) is not given up on after a single failure: the row
 * stays 'pending' and is retried on later ticks, up to MAX_ATTEMPTS, before
 * it is marked terminally 'failed'. See security audit §3d.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

use Agnosis\Core\Logger;

class QueueProcessor {

	/**
	 * How many failed wp_mail() attempts a queue row gets before it is given up
	 * on and marked 'failed' for good. While attempts remain, a failed row is
	 * left 'pending' so the next cron tick retries it — see security audit §3d.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Hook callback for 'agnosis_send_newsletter_queue'.
	 */
	public function process(): void {
		global $wpdb;

		$batch_size = max( 1, (int) get_option( 'agnosis_newsletter_batch_size', 20 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE status = 'pending' ORDER BY id ASC LIMIT %d",
				$batch_size
			)
		);

		if ( ! empty( $rows ) ) {
			// Preload the (usually one) issue(s) referenced in this batch, and decode
			// each issue's per-locale content map once per batch rather than once per row.
			$issue_ids   = array_unique( array_map( fn( $r ) => (int) $r->issue_id, $rows ) );
			$issues      = [];
			$locale_maps = [];
			foreach ( $issue_ids as $issue_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$issue = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agnosis_newsletter_issues WHERE id = %d", $issue_id )
				);

				$issues[ $issue_id ]      = $issue;
				$locale_maps[ $issue_id ] = ( $issue && ! empty( $issue->locale_content ) )
					? (array) json_decode( (string) $issue->locale_content, true )
					: [];
			}

			foreach ( $rows as $row ) {
				$issue = $issues[ (int) $row->issue_id ] ?? null;
				if ( ! $issue ) {
					// Orphaned row (issue deleted) — mark failed immediately; there is
					// nothing a retry could ever fix here.
					$this->mark_terminal( (int) $row->id, 'failed' );
					continue;
				}

				$ok = $this->send_one( $row, $issue, $locale_maps[ (int) $row->issue_id ] ?? [] );
				if ( $ok ) {
					$this->mark_terminal( (int) $row->id, 'sent' );
				} else {
					$this->record_failed_attempt( (int) $row->id, (int) $row->attempts );
				}
			}
		}

		// Reconcile every currently-'sending' issue (not just ones this batch
		// touched) each tick. If every recipient of an issue fails, none of its
		// rows ever reach 'sent' — checking only issues this batch just sent
		// successfully would leave that issue stuck in 'sending' forever
		// ("Sending…" with Send Now disabled on the admin dashboard), since its
		// rows are no longer 'pending' and it would never be looked at again.
		// This also self-heals any issue already stuck this way.
		$this->reconcile_sending_issues();
	}

	/** Mark 'sent' every currently-'sending' issue that has zero pending rows left. */
	private function reconcile_sending_issues(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sending_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}agnosis_newsletter_issues WHERE status = 'sending'" );

		foreach ( $sending_ids as $issue_id ) {
			$this->maybe_complete_issue( (int) $issue_id );
		}
	}

	// -------------------------------------------------------------------------

	/**
	 * @param object{id: int|string, issue_id: int|string, recipient_id: int|string|null, recipient_email: string, recipient_type: string, unsubscribe_token: string, locale: string|null, status: string, attempts: int|string} $row   Row from agnosis_newsletter_queue.
	 * @param object{id: int|string, newsletter_type: string, status: string, intro: string|null, digest_html: string|null, locale_content: string|null, recipient_count: int|string, scheduled_at: string|null, sent_at: string|null} $issue Row from agnosis_newsletter_issues.
	 * @param array<string, array{intro: string, digest_html: string}> $locale_map Decoded issue locale_content, keyed by locale.
	 */
	private function send_one( object $row, object $issue, array $locale_map = [] ): bool {
		$type = (string) $row->recipient_type;

		$args = [
			'agnosis_newsletter' => '1',
			'action'             => 'unsubscribe',
			'type'               => $type,
			'token'              => $row->unsubscribe_token,
		];
		if ( 'artist' === $type && $row->recipient_id ) {
			$args['uid'] = (int) $row->recipient_id;
		}
		$unsubscribe_url = add_query_arg( $args, home_url( '/' ) );

		// The recipient's send-locale was resolved once, at prepare time, and
		// stored on the queue row — no repeat per-recipient meta/DB lookup here.
		// Content for that locale comes from the issue's locale_content map;
		// fall back to the issue's base (default-locale) render if the row's
		// locale is missing or wasn't present in the map for any reason.
		$locale  = (string) ( $row->locale ?? '' );
		$content = ( '' !== $locale && isset( $locale_map[ $locale ] ) )
			? $locale_map[ $locale ]
			: [ 'intro' => (string) $issue->intro, 'digest_html' => (string) $issue->digest_html ];

		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		$body    = Mailer::build_email( (string) $issue->newsletter_type, $content['intro'], $content['digest_html'], $unsubscribe_url );
		$subject = Mailer::build_subject( (string) $issue->newsletter_type );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . Mailer::sender_header(),
			'List-Unsubscribe: <' . esc_url_raw( $unsubscribe_url ) . '>',
			// RFC 8058 one-click unsubscribe — tells the mail client it may POST
			// straight to the List-Unsubscribe URL above with no user interaction
			// beyond clicking its own "Unsubscribe" affordance. SubscriptionConfirm
			// handles that bare POST immediately (no confirm page); see §2b.
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		];

		$sent = wp_mail( $row->recipient_email, $subject, $body, $headers );

		if ( '' !== $locale ) {
			restore_current_locale();
		}

		if ( ! $sent ) {
			Logger::warning( sprintf( 'Newsletter: wp_mail() failed for issue #%d, recipient %s (attempt %d/%d).', $issue->id, $row->recipient_email, (int) $row->attempts + 1, self::MAX_ATTEMPTS ), 'newsletter' );
		}

		return $sent;
	}

	/**
	 * Mark a row 'sent' or an unretryable 'failed' (orphaned row) — sets
	 * resolved_at as the resolution time. Named resolved_at (not sent_at,
	 * despite also covering a failure) since it's stamped either way — see
	 * security audit §3f.
	 */
	private function mark_terminal( int $queue_row_id, string $status ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_newsletter_queue',
			[ 'status' => $status, 'resolved_at' => current_time( 'mysql' ) ],
			[ 'id' => $queue_row_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Record a failed send attempt. The row stays 'pending' (so the next cron
	 * tick retries it) until MAX_ATTEMPTS is reached, at which point it flips to
	 * a terminal 'failed' and resolved_at is stamped with the final-failure time.
	 */
	private function record_failed_attempt( int $queue_row_id, int $prior_attempts ): void {
		global $wpdb;

		$attempts  = $prior_attempts + 1;
		$exhausted = $attempts >= self::MAX_ATTEMPTS;

		$data    = [ 'status' => $exhausted ? 'failed' : 'pending', 'attempts' => $attempts ];
		$formats = [ '%s', '%d' ];
		if ( $exhausted ) {
			$data['resolved_at'] = current_time( 'mysql' );
			$formats[]           = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_newsletter_queue',
			$data,
			[ 'id' => $queue_row_id ],
			$formats,
			[ '%d' ]
		);
	}

	/**
	 * Mark an issue 'sent' once no pending queue rows remain for it.
	 */
	private function maybe_complete_issue( int $issue_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE issue_id = %d AND status = 'pending'",
				$issue_id
			)
		);

		if ( $pending > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sent_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE issue_id = %d AND status = 'sent'",
				$issue_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_newsletter_issues',
			[
				'status'          => 'sent',
				'sent_at'         => current_time( 'mysql' ),
				'recipient_count' => $sent_count,
			],
			[ 'id' => $issue_id ],
			[ '%s', '%s', '%d' ],
			[ '%d' ]
		);

		Logger::info( sprintf( 'Newsletter issue #%d fully sent (%d recipient(s)).', $issue_id, $sent_count ), 'newsletter' );
	}
}
