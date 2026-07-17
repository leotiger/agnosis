<?php
/**
 * Debug-file writer and enable-toggle resolver for deep pipeline tracing.
 *
 * Agnosis\Core\Logger already gives a persistent, always-on one-line summary
 * of pipeline events in Settings → Logs — enough for routine operation, but
 * not for tracing exactly where a single message fell through the email
 * intake pipeline (raw IMAP structure → Parser's attachment loop →
 * MediaAdapter → PostCreator) when something silently drops what looks like
 * a perfectly valid submission. Logger's one-line entries can't carry a full
 * MIME-part tree or a raw attachment dump without flooding the log table.
 *
 * This class is the opt-in, verbose counterpart: when enabled, each stage of
 * the intake pipeline writes a raw dump to a dedicated file instead, so the
 * exact point and reason of a failure is visible after the fact without
 * needing to reproduce it live or add temporary var_dump()s.
 *
 * Follows the same enable/resolve/writer pattern as Lingua Forge's
 * LinguaForge\AI\Core\TranslationDebug (constant override → option → off;
 * dedicated directory outside uploads/, .htaccess + index.html guards,
 * random-suffixed filenames) — reusing a proven shape rather than inventing
 * a new one.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class Debug {

	/**
	 * Whether force() has been called this process — lets a future WP-CLI
	 * command force debug on/off for a single run without touching the DB
	 * option or wp-config.php, the same escape hatch TranslationDebug offers.
	 *
	 * @var boolean
	 */
	private static bool $override_set = false;

	/**
	 * The value force() was called with, when $override_set is true.
	 *
	 * @var boolean
	 */
	private static bool $override_value = false;

	// -------------------------------------------------------------------------
	// Enable resolution
	// -------------------------------------------------------------------------

	public static function force( bool $value ): void {
		self::$override_set   = true;
		self::$override_value = $value;
	}

	/**
	 * Resolution order (constant wins, same pattern WP uses for WP_DEBUG):
	 *   1. Runtime override set via force().
	 *   2. AGNOSIS_DEBUG constant defined in wp-config.php — returned verbatim.
	 *   3. agnosis_debug_enabled option, set via Settings → General.
	 *   4. Off by default.
	 */
	public static function enabled(): bool {
		if ( self::$override_set ) {
			return self::$override_value;
		}

		if ( defined( 'AGNOSIS_DEBUG' ) ) {
			return (bool) AGNOSIS_DEBUG;
		}

		return (bool) get_option( 'agnosis_debug_enabled', false );
	}

	/** Whether the wp-config.php constant currently overrides the Settings toggle. */
	public static function constant_defined(): bool {
		return defined( 'AGNOSIS_DEBUG' );
	}

	/** The literal value AGNOSIS_DEBUG currently holds, or null if undefined. */
	public static function constant_value(): ?bool {
		return defined( 'AGNOSIS_DEBUG' ) ? (bool) AGNOSIS_DEBUG : null;
	}

	// -------------------------------------------------------------------------
	// Directory / file management (Settings → General panel)
	// -------------------------------------------------------------------------

	/**
	 * Absolute filesystem path of the debug directory, no trailing slash.
	 *
	 * Default: wp-content/agnosis-debug — outside wp-content/uploads/ (which
	 * is universally web-readable) so that, combined with the random suffix
	 * in write()'s filenames, a dump stays unguessable even if the directory
	 * itself ends up listable. Filterable via `agnosis_debug_dir` for sites
	 * that want debug output redirected somewhere even more locked down.
	 */
	public static function dir(): string {
		$default = defined( 'WP_CONTENT_DIR' )
			? WP_CONTENT_DIR . '/agnosis-debug'
			: ABSPATH . 'wp-content/agnosis-debug';

		$dir = (string) apply_filters( 'agnosis_debug_dir', $default );
		if ( '' === $dir ) {
			$dir = $default;
		}

		return untrailingslashit( $dir );
	}

	/** Count of *.txt dumps currently in the debug directory. */
	public static function file_count(): int {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$files = glob( $dir . '/*.txt' );
		return is_array( $files ) ? count( $files ) : 0;
	}

	/**
	 * Delete every *.txt dump. Returns the number removed. Leaves the
	 * directory (and its .htaccess/index.html guards) in place so the next
	 * write() still lands cleanly.
	 */
	public static function clear(): int {
		return self::delete_matching( fn( string $path ): bool => true );
	}

	/**
	 * Delete *.txt dumps whose mtime is older than $days days. Returns the
	 * number removed. Same symlink-safety and directory-preservation
	 * behavior as clear() — this is clear() with an age filter.
	 *
	 * Fourth audit §5c: a raw pipeline dump contains an artist's full raw
	 * email (attachments included), so unlike agnosis_log's DB-backed
	 * entries (already pruned by Logger::prune()), these files had no
	 * expiry at all — a site that ever turned debug logging on would
	 * accumulate PII in this directory forever. Wired into the existing
	 * `agnosis_cleanup_inbox` daily cron (see Inbox::cleanup()) rather than
	 * a new cron event, since that hook already exists purely to run
	 * periodic retention housekeeping (IMAP messages, queue rows, log rows)
	 * — one more prune call there, not a new moving part.
	 *
	 * Runs unconditionally on the cleanup cron — including when debug
	 * logging is currently OFF — so dumps left over from a since-disabled
	 * debug session still expire instead of sitting there indefinitely;
	 * gating this on enabled() would defeat the point for exactly the
	 * site that most needs it: one that turned debug on, captured what it
	 * needed, and turned it back off again.
	 *
	 * @param int $days Age threshold in days (default 14, per the audit's own suggestion).
	 */
	public static function prune( int $days = 14 ): int {
		$cutoff = time() - ( max( 1, $days ) * DAY_IN_SECONDS );

		return self::delete_matching(
			fn( string $path ): bool => ( filemtime( $path ) ?: 0 ) < $cutoff
		);
	}

	/**
	 * Shared symlink-safe deletion loop for clear()/prune() — deletes every
	 * *.txt file in the debug directory for which $should_delete( $path )
	 * returns true.
	 *
	 * @param callable(string): bool $should_delete
	 */
	private static function delete_matching( callable $should_delete ): int {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$files = glob( $dir . '/*.txt' );
		if ( ! is_array( $files ) || empty( $files ) ) {
			return 0;
		}

		// Defensive: only delete entries whose resolved path is still inside
		// the debug directory — guards against a hostile symlink glob might
		// surface. Cheap (one realpath() per file) and matches the same
		// guard TranslationDebug::clear_debug_files() uses.
		$real_dir = realpath( $dir );
		if ( false === $real_dir ) {
			return 0;
		}

		$removed = 0;
		foreach ( $files as $path ) {
			$real = realpath( $path );
			if ( false === $real ) {
				continue;
			}
			if ( 0 !== strpos( $real, $real_dir . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			if ( ! $should_delete( $path ) ) {
				continue;
			}

			wp_delete_file( $path );
			++$removed;
		}

		return $removed;
	}

	// -------------------------------------------------------------------------
	// Writer
	// -------------------------------------------------------------------------

	/**
	 * Write a raw diagnostic dump to the debug directory. No-op when
	 * disabled — callers do not need to guard every call site with their own
	 * enabled() check, but MAY skip building an expensive $content string
	 * ahead of time by checking enabled() first (see call sites in Email\Parser).
	 *
	 * Files: {stage}-{ymd-his}-{random}.txt — $stage identifies which
	 * pipeline step wrote it (e.g. "inbox-poll", "parser-attachments",
	 * "media-adapter", "post-creator"), so a directory listing alone gives a
	 * rough timeline of a submission's path through the pipeline.
	 *
	 * @param string $stage   Short slug identifying the pipeline step.
	 * @param string $content Raw diagnostic text to write verbatim.
	 */
	public static function write( string $stage, string $content ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$dir = self::dir();

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );

			// Apache "Deny from all" + an index.html placeholder that blocks
			// directory listing on every server type (nginx/IIS ignore
			// .htaccess) — both run only on first directory create.
			$htaccess_path = $dir . '/.htaccess';
			if ( ! file_exists( $htaccess_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem would require request_filesystem_credentials() and can block silently on FTP-based hosts, defeating the diagnostic write here — a one-line static guard, written once per debug-dir lifetime, inside a plugin-owned path.
				file_put_contents( $htaccess_path, "Deny from all\n" );
			}
			$index_path = $dir . '/index.html';
			if ( ! file_exists( $index_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same WP_Filesystem rationale as the .htaccess write above.
				file_put_contents( $index_path, "<!-- Silence is golden. -->\n" );
			}
		}

		$stage    = preg_replace( '/[^a-z0-9_-]+/i', '-', $stage ) ?? 'debug';
		$filename = sprintf(
			'%s-%s-%s.txt',
			$stage,
			gmdate( 'Ymd-His' ),
			wp_generate_password( 8, false )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Debug-mode-only write into a plugin-owned directory, gated by enabled() above — same rationale as LinguaForge\AI\Core\TranslationDebug::debug_write().
		file_put_contents( $dir . '/' . $filename, $content );
	}
}
