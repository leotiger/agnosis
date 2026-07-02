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

use WP_Error;

class Subscriber {

	/**
	 * Subscribe (or re-subscribe) an email address to the public newsletter.
	 *
	 * - Already confirmed → WP_Error (no duplicate confirmation email sent).
	 * - Pending            → confirmation email is resent with a fresh token.
	 * - Unsubscribed       → treated as a fresh signup (new token, back to pending).
	 * - Unknown            → new pending row created.
	 *
	 * @param string $email  Sanitised, validated email address.
	 * @param string $locale Optional WP locale (e.g. 'es_ES') to send the
	 *                       confirmation email in the visitor's language.
	 * @return array{id: int, token: string, resent: bool}|WP_Error
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$wpdb->prefix}agnosis_newsletter_subscribers WHERE email = %s",
				$email
			)
		);

		if ( $existing && 'confirmed' === $existing->status ) {
			return new WP_Error(
				'agnosis_already_subscribed',
				__( 'This email address is already subscribed to the newsletter.', 'agnosis' ),
				[ 'status' => 409 ]
			);
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

			return [ 'id' => (int) $existing->id, 'token' => $token, 'resent' => true ];
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

		return [ 'id' => (int) $wpdb->insert_id, 'token' => $token, 'resent' => false ];
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
}
