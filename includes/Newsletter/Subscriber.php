<?php
/**
 * Public newsletter subscriber repository.
 *
 * Wraps the agnosis_newsletter_subscribers table. This table holds ONLY the
 * public-newsletter audience — external visitors with no WP account, admitted
 * via a double opt-in (email confirmation) flow. The artist-newsletter audience
 * is never stored here; it is derived live from WP_User_Query (role
 * agnosis_artist) minus the _agnosis_newsletter_optout user meta flag, so
 * membership never drifts out of sync with who is actually an admitted artist.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

use Agnosis\Core\Logger;
use WP_Error;

class Subscriber {

	/**
	 * How long a still-pending resubscribe is throttled (security audit §2d):
	 * repeated submissions for the same unconfirmed address within this window
	 * (an impatient double-click, or a bot hammering the form within the
	 * existing 5/IP/5min rate limit) neither churn a fresh token nor resend a
	 * confirmation email — the pending row is left completely untouched.
	 */
	private const RESEND_COOLDOWN_SECONDS = 300; // 5 minutes.

	/**
	 * How long an unconfirmed 'pending' row survives before Scheduler::prepare()'s
	 * daily housekeeping deletes it (security audit §2d).
	 */
	private const PENDING_EXPIRY_DAYS = 14;

	/**
	 * Subscribe (or re-subscribe) an email address to the public newsletter.
	 *
	 * Enumeration-safe (security audit §2c): every outcome below returns the
	 * same success shape, so a caller can never distinguish "already on the
	 * list" from "just signed up" by the return value alone — and the REST
	 * endpoint built on top of this always responds 201 either way. Only
	 * whether a confirmation email goes out differs, which is invisible to
	 * the caller.
	 *
	 * - Already confirmed → success shape with `already_confirmed: true`; no email sent, no new token issued — nothing to confirm.
	 * - Pending, resubmitted within RESEND_COOLDOWN_SECONDS → success shape with `throttled: true`; row and token untouched, no email (audit §2d).
	 * - Pending, past the cooldown → confirmation email is resent with a fresh token.
	 * - Unsubscribed       → treated as a fresh signup (new token, back to pending).
	 * - Unknown            → new pending row created.
	 *
	 * @param string $email  Sanitised, validated email address.
	 * @param string $locale Optional WP locale (e.g. 'es_ES') to send the
	 *                       confirmation email in the visitor's language.
	 * @return array{id: int, token: string, resent: bool, already_confirmed: bool, throttled: bool}|WP_Error
	 */
	public static function subscribe( string $email, string $locale = '' ): array|WP_Error {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'agnosis_invalid_email',
				__( 'Please enter a valid email address.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		// is_recent is computed by MySQL itself (comparing created_at against its
		// own NOW()) rather than in PHP against WP's clock, so there is no
		// timezone/clock-mixing risk (see the separate, still-open §3e finding
		// about clock mixing elsewhere in the newsletter system).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, token,
				        ( created_at > ( NOW() - INTERVAL %d SECOND ) ) AS is_recent
				 FROM {$wpdb->prefix}agnosis_newsletter_subscribers
				 WHERE email = %s",
				self::RESEND_COOLDOWN_SECONDS,
				$email
			)
		);

		if ( $existing && 'confirmed' === $existing->status ) {
			// No distinguishable error, no email, no new token — the recipient's
			// own inbox already tells them they're subscribed.
			return [ 'id' => (int) $existing->id, 'token' => '', 'resent' => false, 'already_confirmed' => true, 'throttled' => false ];
		}

		if ( $existing && 'pending' === $existing->status && $existing->is_recent ) {
			return [ 'id' => (int) $existing->id, 'token' => (string) $existing->token, 'resent' => false, 'already_confirmed' => false, 'throttled' => true ];
		}

		$token = bin2hex( random_bytes( 32 ) );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'agnosis_newsletter_subscribers',
				[
					'status'          => 'pending',
					'token'           => $token,
					'locale'          => '' !== $locale ? $locale : null,
					'confirmed_at'    => null,
					'unsubscribed_at' => null,
				],
				[ 'id' => $existing->id ],
				[ '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);

			// Bump created_at on every real (non-throttled) resend so the §2d
			// expiry clock tracks last genuine activity, not just the original
			// signup — an actively-retrying visitor's row should not expire out
			// from under them. Done via a dedicated NOW() query rather than a PHP
			// timestamp, so this stays on the exact same clock as the column's
			// own CURRENT_TIMESTAMP default — no clock-mixing (see §3e).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}agnosis_newsletter_subscribers SET created_at = NOW() WHERE id = %d",
					$existing->id
				)
			);

			return [ 'id' => (int) $existing->id, 'token' => $token, 'resent' => true, 'already_confirmed' => false, 'throttled' => false ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_newsletter_subscribers',
			[
				'email'  => $email,
				'status' => 'pending',
				'token'  => $token,
				'locale' => '' !== $locale ? $locale : null,
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		return [ 'id' => (int) $wpdb->insert_id, 'token' => $token, 'resent' => false, 'already_confirmed' => false, 'throttled' => false ];
	}

	/**
	 * Delete abandoned, never-confirmed 'pending' rows older than
	 * PENDING_EXPIRY_DAYS (security audit §2d hardening note). Without this, a
	 * bot hammering the signup form with a fresh address each time (still
	 * bounded by the existing per-IP rate limit) accumulates rows — and every
	 * one of them sent a confirmation email — forever. Confirmed and
	 * unsubscribed rows are never touched.
	 *
	 * Piggybacks on the existing daily 'agnosis_prepare_newsletters' cron
	 * (called from Scheduler::prepare()) rather than registering a new
	 * scheduled event for what is a low-volume cleanup task.
	 *
	 * The age comparison is done in SQL against MySQL's own NOW(), the same
	 * clock created_at's CURRENT_TIMESTAMP default already uses — no PHP-side
	 * timezone conversion, so no risk of the clock-mixing class of bug tracked
	 * separately in §3e.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function expire_stale_pending(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}agnosis_newsletter_subscribers
				 WHERE status = 'pending' AND created_at < ( NOW() - INTERVAL %d DAY )",
				self::PENDING_EXPIRY_DAYS
			)
		);

		if ( $deleted > 0 ) {
			Logger::info( sprintf( 'Newsletter: expired %d abandoned pending subscription(s) older than %d days.', $deleted, self::PENDING_EXPIRY_DAYS ), 'newsletter' );
		}

		return $deleted;
	}

	/**
	 * Confirm a pending subscription by its token (double opt-in click).
	 *
	 * @return bool True on success, false when the token is unknown or already confirmed.
	 */
	public static function confirm( string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'agnosis_newsletter_subscribers',
			[
				'status'       => 'confirmed',
				'confirmed_at' => current_time( 'mysql' ),
			],
			[ 'token' => $token, 'status' => 'pending' ],
			[ '%s', '%s' ],
			[ '%s', '%s' ]
		);

		return (bool) $updated;
	}

	/**
	 * Unsubscribe by token — works whether the subscriber was pending or confirmed.
	 *
	 * @return bool True on success, false when the token is unknown or already unsubscribed.
	 */
	public static function unsubscribe( string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agnosis_newsletter_subscribers
				 SET status = 'unsubscribed', unsubscribed_at = %s
				 WHERE token = %s AND status != 'unsubscribed'",
				current_time( 'mysql' ),
				$token
			)
		);

		return (bool) $updated;
	}

	/**
	 * Find a subscriber row by its unique token.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function find_by_token( string $token ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_newsletter_subscribers WHERE token = %s",
				$token
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * All confirmed subscriber emails — the live public-newsletter send list.
	 *
	 * locale is the language captured at signup (best-effort — may be empty if
	 * the visitor didn't specify one), used by Scheduler to group recipients
	 * for per-locale digest rendering.
	 *
	 * @return array<int, array{email: string, token: string, locale: string|null}>
	 */
	public static function confirmed_recipients(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT email, token, locale FROM {$wpdb->prefix}agnosis_newsletter_subscribers WHERE status = 'confirmed'",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Counts by status, for the admin dashboard.
	 *
	 * @return array{pending: int, confirmed: int, unsubscribed: int}
	 */
	public static function counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) as total FROM {$wpdb->prefix}agnosis_newsletter_subscribers GROUP BY status",
			ARRAY_A
		);

		$counts = [ 'pending' => 0, 'confirmed' => 0, 'unsubscribed' => 0 ];
		foreach ( (array) $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Confirmed-subscriber counts grouped by locale, for the admin dashboard's
	 * locale-coverage metric (audit §8 — "cheap signal for which LF languages
	 * earn their AI translation spend"). Only 'confirmed' subscribers are
	 * counted — pending/unsubscribed rows aren't part of the live send list a
	 * translation spend would actually be serving.
	 *
	 * Rows with no recorded locale (NULL/'' — a visitor who signed up before
	 * the §3c frontend.js fix, or simply didn't have a browser locale to send)
	 * are bucketed under the empty-string key rather than dropped, so the
	 * counts here always sum to Subscriber::counts()['confirmed'].
	 *
	 * @return array<string, int> Locale (e.g. 'es_ES', or '' for unknown) => confirmed count, highest first.
	 */
	public static function counts_by_locale(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT locale, COUNT(*) as total FROM {$wpdb->prefix}agnosis_newsletter_subscribers
			 WHERE status = 'confirmed'
			 GROUP BY locale
			 ORDER BY total DESC",
			ARRAY_A
		);

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$locale             = (string) ( $row['locale'] ?? '' );
			$counts[ $locale ] = (int) $row['total'];
		}

		return $counts;
	}
}
