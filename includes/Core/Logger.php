<?php
/**
 * Persistent logger.
 *
 * Writes structured entries to the agnosis_log DB table so that admins can
 * review pipeline activity from Settings → Logs without requiring SSH access
 * or WP_DEBUG_LOG to be enabled.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class Logger {

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	public static function info( string $message, string $context = 'system' ): void {
		self::write( 'info', $context, $message );
	}

	public static function warning( string $message, string $context = 'system' ): void {
		self::write( 'warning', $context, $message );
	}

	public static function error( string $message, string $context = 'system' ): void {
		self::write( 'error', $context, $message );
	}

	// -------------------------------------------------------------------------
	// Query helpers (used by Settings)
	// -------------------------------------------------------------------------

	/**
	 * Fetch log entries, newest first.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_entries( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- log viewer; caching would show stale entries.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, level, context, message, created_at
				 FROM {$wpdb->prefix}agnosis_log
				 ORDER BY created_at DESC, id DESC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/** Total number of log entries. */
	public static function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live count for pagination.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_log" );
	}

	/** Delete all log entries. */
	public static function clear(): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE all on custom table; caching not applicable.
			"DELETE FROM {$wpdb->prefix}agnosis_log"
		);
	}

	/**
	 * Remove entries older than $days days.
	 * Called from Inbox::cleanup() so the table never grows unbounded.
	 */
	public static function prune( int $days = 30 ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- time-based DELETE on custom table; caching not applicable.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}agnosis_log WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	private static function write( string $level, string $context, string $message ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- append-only log write; no caching applicable.
			$wpdb->prefix . 'agnosis_log',
			[
				'level'   => $level,
				'context' => substr( $context, 0, 64 ),
				'message' => $message,
			],
			[ '%s', '%s', '%s' ]
		);

		// Mirror to PHP error log when debug mode is active.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[Agnosis][%s][%s] %s', strtoupper( $level ), $context, $message ) );
		}
	}
}
