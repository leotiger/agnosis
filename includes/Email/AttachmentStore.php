<?php
/**
 * Temporary attachment storage for the submission queue.
 *
 * Writes incoming image binaries to a protected subdirectory under
 * wp-content/uploads/agnosis-queue/ instead of base64-encoding them into the
 * queue table.  This keeps the database free of large binary payloads while
 * still allowing PostCreator to read the file before uploading it to the media
 * library.
 *
 * Directory layout:
 *   uploads/agnosis-queue/
 *     .htaccess          — deny direct HTTP access
 *     index.php          — empty guard (WordPress convention)
 *     {token}/
 *       0-filename.jpg   — one file per attachment, prefixed with its index
 *       1-photo.png
 *
 * `{token}` is `hash_hmac('sha256', $uid, wp_salt())`, truncated — not the
 * raw queue `message_uid` (which, for IMAP intake, is a small sequential
 * integer). The `.htaccess` guard above is inert on nginx (it only reads
 * Apache config), so on that stack a guessed/enumerated path would have been
 * directly servable if the directory name were the guessable uid itself;
 * deriving it from a per-install-salted HMAC instead closes that without a
 * schema change (P-3, `agnosis-audit/AUDIT-0.9.39.md` §4 — see the README's
 * own nginx `location` block for the primary fix this pairs with). Still a
 * deterministic function of `$uid`, so `store()`/`delete_dir()` recompute the
 * same path from the uid alone; `sweep_orphans()` correspondingly builds its
 * keep-set from live rows' *derived* directory names rather than matching a
 * scanned directory name straight back to a `message_uid` column value.
 *
 * Temp files are deleted by PostCreator immediately after a successful upload.
 * The cleanup cron sweeps any orphans left by failed or permanently-stuck rows.
 *
 * @package Agnosis\Email
 */

declare(strict_types=1);

namespace Agnosis\Email;

use Agnosis\Core\Logger;

class AttachmentStore {

	/** Subdirectory name under wp uploads base. */
	private const QUEUE_DIR = 'agnosis-queue';

	/** Max age in days before an orphaned uid directory is swept. */
	private const ORPHAN_TTL_DAYS = 7;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Write a single attachment binary to disk.
	 *
	 * @param  string $uid      Queue message UID — used as the subdirectory name.
	 * @param  int    $index    Attachment index within the submission (0-based).
	 * @param  string $filename Original filename (sanitised before use).
	 * @param  string $binary   Raw image binary data.
	 * @return string           Absolute filesystem path to the written file,
	 *                          or empty string on failure.
	 */
	public static function store( string $uid, int $index, string $filename, string $binary ): string {
		$dir = self::uid_dir( $uid );

		if ( ! wp_mkdir_p( $dir ) ) {
			Logger::error( sprintf( 'AttachmentStore: failed to create directory %s.', $dir ), 'inbox' );
			return '';
		}

		$safe_name = $index . '-' . sanitize_file_name( $filename ?: 'attachment' );
		$path      = trailingslashit( $dir ) . $safe_name;

		$fs = self::filesystem();
		if ( ! $fs || ! $fs->put_contents( $path, $binary, FS_CHMOD_FILE ) ) {
			Logger::error( sprintf( 'AttachmentStore: failed to write %s.', $path ), 'inbox' );
			return '';
		}

		return $path;
	}

	/**
	 * Delete the entire uid subdirectory and all its files.
	 *
	 * Called by PostCreator after a successful media library upload.
	 *
	 * @param string $uid Queue message UID.
	 */
	public static function delete_dir( string $uid ): void {
		$dir = self::uid_dir( $uid );
		if ( is_dir( $dir ) ) {
			self::rmdir_recursive( $dir );
		}
	}

	/**
	 * Remove uid directories whose queue rows are gone or fully processed.
	 *
	 * Runs as part of the daily cleanup cron.  Any directory older than
	 * ORPHAN_TTL_DAYS that has no corresponding pending/processing queue row
	 * is deleted.
	 *
	 * @param int $days Age threshold in days (defaults to ORPHAN_TTL_DAYS).
	 */
	public static function sweep_orphans( int $days = self::ORPHAN_TTL_DAYS ): void {
		$base = self::queue_base_dir();
		if ( ! is_dir( $base ) ) {
			return;
		}

		global $wpdb;

		// Build the keep-set from every uid a live queue row still cares
		// about, up front — a scanned directory's own name is now a derived
		// HMAC (see uid_dir()), not the message_uid itself, so it can no
		// longer be matched back to a row with a direct `WHERE message_uid =
		// %s` lookup against the entry name. One query instead of one per
		// directory, as a side effect.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sweep; real-time check against live queue.
		$live_uids = (array) $wpdb->get_col(
			"SELECT message_uid FROM {$wpdb->prefix}agnosis_queue WHERE status IN ('pending','processing')"
		);

		$keep = [];
		foreach ( $live_uids as $live_uid ) {
			$keep[ basename( self::uid_dir( (string) $live_uid ) ) ] = true;
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$swept  = 0;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.dir_opendir -- native scandir is unavailable in older WP filesystem API; direct is fine for our private dir.
		foreach ( (array) scandir( $base ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$dir_path = trailingslashit( $base ) . $entry;

			if ( ! is_dir( $dir_path ) ) {
				continue; // Skip .htaccess, index.php, etc.
			}

			// Only sweep directories older than the TTL.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime
			if ( filemtime( $dir_path ) >= $cutoff ) {
				continue;
			}

			// Keep dirs whose queue row is still pending or processing.
			if ( isset( $keep[ $entry ] ) ) {
				continue;
			}

			self::rmdir_recursive( $dir_path );
			++$swept;
		}

		if ( $swept > 0 ) {
			Logger::info( sprintf( 'AttachmentStore: swept %d orphaned queue dir(s).', $swept ), 'inbox.cleanup' );
		}
	}

	/**
	 * Create the queue base directory and write access-denial guards.
	 *
	 * Called on plugin activation.  Idempotent — safe to call repeatedly.
	 */
	public static function ensure_protected(): void {
		$base = self::queue_base_dir();

		if ( ! wp_mkdir_p( $base ) ) {
			return;
		}

		$fs = self::filesystem();
		if ( ! $fs ) {
			return;
		}

		// Apache — deny direct HTTP access.
		$htaccess = trailingslashit( $base ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$fs->put_contents( $htaccess, "Deny from all\n", FS_CHMOD_FILE );
		}

		// Nginx / WordPress convention — empty index file prevents directory listing.
		$index = trailingslashit( $base ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			$fs->put_contents( $index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/** Absolute path to the shared queue base directory. */
	private static function queue_base_dir(): string {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . self::QUEUE_DIR;
	}

	/**
	 * Absolute path to the uid-specific subdirectory.
	 *
	 * The directory name is a per-install-salted HMAC of $uid, not $uid
	 * itself (P-3 hardening, see class docblock) — this both prevents
	 * directory traversal (the hash output is always a fixed-length hex
	 * string) and stops the directory name from directly exposing the
	 * queue's own guessable message_uid.
	 */
	private static function uid_dir( string $uid ): string {
		$token = substr( hash_hmac( 'sha256', $uid, wp_salt() ), 0, 32 );
		return trailingslashit( self::queue_base_dir() ) . $token;
	}

	/**
	 * Return an initialised WP_Filesystem_Direct instance.
	 *
	 * Forces the 'direct' method because FTP/SSH credentials cannot be
	 * prompted in cron or admin-post contexts.  Returns null if the class
	 * cannot be loaded (extremely unlikely on any standard WP install).
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function filesystem(): ?\WP_Filesystem_Base {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		add_filter( 'filesystem_method', fn() => 'direct' );
		WP_Filesystem();
		remove_all_filters( 'filesystem_method' );

		global $wp_filesystem;
		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/** Recursively delete a directory and its contents. */
	private static function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = trailingslashit( $dir ) . $entry;

			if ( is_dir( $path ) ) {
				self::rmdir_recursive( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}
}
