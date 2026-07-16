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
 * Claim-then-read (security audit §2c): process() previously SELECTed
 * 'pending' rows and only updated their status after sending — two
 * overlapping ticks (WP-Cron firing twice for the same event, or a cron tick
 * landing while an admin's "Send Now" is still running) could both select
 * the same rows and each send them, double-mailing recipients. process() now
 * atomically claims a batch first — a single `UPDATE … WHERE status =
 * 'pending' ORDER BY id ASC LIMIT %d` tagging the claimed rows with a
 * per-run `claim_token` — and only reads back rows carrying that exact
 * token. InnoDB row-locking means at most one concurrent claim ever wins
 * each row: the WHERE clause's `status = 'pending'` check is re-evaluated
 * against the live, committed value as each row's lock is acquired, so a
 * second UPDATE racing for the same row simply finds it already 'claimed'
 * and skips it. A PHP process that dies mid-batch after claiming but before
 * finishing would otherwise strand those rows in 'claimed' forever (the
 * claim UPDATE only ever targets 'pending' rows) — reset_stale_claims(),
 * run at the top of every process() tick, self-heals that automatically.
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
	 * How long a row may sit 'claimed' before reset_stale_claims() treats it
	 * as abandoned and returns it to 'pending' — see that method's own
	 * docblock (security audit §2c). Matches Email\Inbox::force_reprocess()'s
	 * stuck-'processing' threshold for the intake queue.
	 */
	private const STALE_CLAIM_MINUTES = 30;

	/**
	 * Hook callback for 'agnosis_send_newsletter_queue'.
	 */
	public function process(): void {
		global $wpdb;

		$this->reset_stale_claims();

		$batch_size  = max( 1, (int) get_option( 'agnosis_newsletter_batch_size', 20 ) );
		$claim_token = wp_generate_uuid4();

		// Claim-then-read (security audit §2c) — see this class's own docblock.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agnosis_newsletter_queue
				 SET status = 'claimed', claim_token = %s, claimed_at = %s
				 WHERE status = 'pending'
				 ORDER BY id ASC
				 LIMIT %d",
				$claim_token,
				current_time( 'mysql' ),
				$batch_size
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE claim_token = %s ORDER BY id ASC",
				$claim_token
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

				// A single recipient's send_one() throwing (a bad locale render, a
				// branding/Imagick error building the email, etc.) must never abort
				// the rest of this batch or skip reconcile_sending_issues() below —
				// that would leave every 'sending' issue, not just the one that
				// errored, permanently stuck ("Sending…" with Send Now disabled)
				// since reconcile() would then never run again for any of them.
				// Found 2026-07-06: an issue stayed stuck even after the cron ran,
				// because an uncaught exception on one row's send silently killed
				// the whole tick before reconcile() was ever reached.
				try {
					$ok = $this->send_one( $row, $issue, $locale_maps[ (int) $row->issue_id ] ?? [] );
				} catch ( \Throwable $e ) {
					Logger::warning(
						sprintf( 'Newsletter: send_one() threw for issue #%d, recipient %s: %s', $issue->id, $row->recipient_email, $e->getMessage() ),
						'newsletter'
					);
					$ok = false;
				}

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

	/**
	 * Mark 'sent' every currently-'sending' issue that has zero pending rows
	 * left. Public so Settings::render_newsletter_dashboard() can call it
	 * directly on page load — self-healing a stuck 'Sending…' status the
	 * moment an admin looks at the dashboard, rather than waiting on
	 * WP-Cron's next tick (which, on a low-traffic site with no real system
	 * cron wired to wp-cron.php, may not run again for a long time).
	 */
	public function reconcile_sending_issues(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sending_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}agnosis_newsletter_issues WHERE status = 'sending'" );

		foreach ( $sending_ids as $issue_id ) {
			$this->maybe_complete_issue( (int) $issue_id );
		}
	}

	/**
	 * Reset any row stuck in 'claimed' longer than STALE_CLAIM_MINUTES back
	 * to 'pending' (security audit §2c) — a PHP process that claimed a batch
	 * (see process()'s own docblock) and then died mid-run (execution-time
	 * limit, host OOM kill, uncaught fatal outside send_one()'s own
	 * try/catch) would otherwise leave those rows permanently unreachable:
	 * the claim UPDATE only ever targets status = 'pending'. Runs at the top
	 * of every process() tick — self-healing, no admin action needed, same
	 * shape as Email\Inbox::force_reprocess()'s stuck-'processing' reset for
	 * the intake queue.
	 */
	private function reset_stale_claims(): void {
		global $wpdb;

		$cutoff = current_datetime()->modify( '-' . self::STALE_CLAIM_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agnosis_newsletter_queue
				 SET status = 'pending', claim_token = NULL, claimed_at = NULL
				 WHERE status = 'claimed' AND claimed_at < %s",
				$cutoff
			)
		);
	}

	/**
	 * Reset every terminally-'failed' row for one issue back to 'pending'
	 * with attempts=0 and resolved_at cleared, so the next cron tick retries
	 * them (fifth/sixth audit §5e). Previously an outage longer than
	 * MAX_ATTEMPTS worth of 5-minute cron ticks (~15 minutes) left those
	 * recipients permanently skipped for the issue with no resend
	 * affordance at all — an admin could not tell "SMTP hiccuped" from
	 * "these addresses are actually broken" apart from reading the log.
	 *
	 * Public for the Settings → Newsletter dashboard's "Retry Failed" button
	 * (Settings::handle_retry_failed_newsletter_recipients()).
	 *
	 * @return int Number of rows actually reset.
	 */
	public function retry_failed( int $issue_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agnosis_newsletter_queue SET status = 'pending', attempts = 0, resolved_at = NULL, claim_token = NULL, claimed_at = NULL WHERE issue_id = %d AND status = 'failed'",
				$issue_id
			)
		);

		return (int) $wpdb->rows_affected;
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

		// "View in browser" only makes sense for the public newsletter — the
		// artist newsletter's content (open votes, new-member names) is
		// community-internal and deliberately never published to Archive's
		// public, unauthenticated pages (see Archive::render_issue()).
		$view_online_url = 'public' === (string) $issue->newsletter_type
			? Archive::issue_permalink( (int) $issue->id )
			: '';

		$body    = Mailer::build_email( (string) $issue->newsletter_type, $content['intro'], $content['digest_html'], $unsubscribe_url, $view_online_url );
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
			[ 'status' => $status, 'resolved_at' => current_time( 'mysql' ), 'claim_token' => null, 'claimed_at' => null ],
			[ 'id' => $queue_row_id ],
			[ '%s', '%s', '%s', '%s' ],
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

		$data    = [ 'status' => $exhausted ? 'failed' : 'pending', 'attempts' => $attempts, 'claim_token' => null, 'claimed_at' => null ];
		$formats = [ '%s', '%d', '%s', '%s' ];
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
	 * Mark an issue 'sent' once no pending (or in-flight 'claimed') queue
	 * rows remain for it.
	 *
	 * Counting 'claimed' alongside 'pending' matters now that process()
	 * claims a batch before working it (security audit §2c): without it, a
	 * row another overlapping tick had already claimed but not yet finished
	 * would read as neither 'pending' nor 'sent'/'failed', and this method
	 * — called at the end of every tick via reconcile_sending_issues() —
	 * could mark the issue 'sent' while that other tick was still actively
	 * sending some of its recipients.
	 */
	private function maybe_complete_issue( int $issue_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE issue_id = %d AND status IN ('pending','claimed')",
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
