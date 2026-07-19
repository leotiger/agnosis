<?php
/**
 * Integration tests for Email\AttachmentStore.
 *
 * All tests use a real filesystem under wp_upload_dir() so they exercise the
 * actual file-write and directory-management paths.  Each test cleans up after
 * itself via tearDown.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Email\AttachmentStore;

class AttachmentStoreTest extends \WP_UnitTestCase {

	/** UIDs created during tests — deleted in tearDown. */
	private array $created_uids = [];

	protected function tearDown(): void {
		parent::tearDown();

		foreach ( $this->created_uids as $uid ) {
			AttachmentStore::delete_dir( $uid );
		}
		$this->created_uids = [];
	}

	// ── store() ───────────────────────────────────────────────────────────────

	public function test_store_writes_file_and_returns_path(): void {
		$uid  = 'test-store-' . uniqid();
		$this->created_uids[] = $uid;

		$path = AttachmentStore::store( $uid, 0, 'photo.jpg', 'fake-binary-data' );

		$this->assertNotSame( '', $path );
		$this->assertFileExists( $path );
	}

	public function test_store_file_contains_correct_binary(): void {
		$uid  = 'test-binary-' . uniqid();
		$this->created_uids[] = $uid;
		$binary = "\x89PNG\r\n\x1a\n"; // PNG magic bytes.

		$path = AttachmentStore::store( $uid, 0, 'image.png', $binary );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( $binary, file_get_contents( $path ) );
	}

	public function test_store_prefixes_file_with_index(): void {
		$uid  = 'test-index-' . uniqid();
		$this->created_uids[] = $uid;

		$path = AttachmentStore::store( $uid, 2, 'cat.jpg', 'data' );

		$this->assertStringContainsString( '2-', basename( $path ) );
	}

	public function test_store_multiple_attachments_in_same_uid_dir(): void {
		$uid  = 'test-multi-' . uniqid();
		$this->created_uids[] = $uid;

		$path0 = AttachmentStore::store( $uid, 0, 'a.jpg', 'data-a' );
		$path1 = AttachmentStore::store( $uid, 1, 'b.jpg', 'data-b' );

		$this->assertFileExists( $path0 );
		$this->assertFileExists( $path1 );
		$this->assertSame( dirname( $path0 ), dirname( $path1 ), 'Both files should be in the same uid dir.' );
	}

	public function test_store_sanitises_uid_directory_traversal(): void {
		// The directory name is now an HMAC of the uid (P-3 hardening), never
		// the raw value, so a traversal-shaped uid can't escape the queue dir
		// regardless — this test still exercises that guarantee end-to-end.
		$uid  = '../../../etc-test-' . uniqid();
		$this->created_uids[] = $uid;

		$path = AttachmentStore::store( $uid, 0, 'file.jpg', 'data' );

		if ( '' !== $path ) {
			// The path must sit inside the queue base directory.
			$upload   = wp_upload_dir();
			$expected = trailingslashit( $upload['basedir'] ) . 'agnosis-queue/';
			$this->assertStringStartsWith( $expected, $path );
		} else {
			// store() returned empty — that's also acceptable for a traversal uid on
			// systems where wp_mkdir_p() refuses to create the path.
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_store_derives_directory_name_from_uid_not_raw_value(): void {
		// The on-disk directory name must not be (or contain) the raw uid —
		// that's the whole point of the P-3 hardening.
		$uid  = 'test-hmac-' . uniqid();
		$this->created_uids[] = $uid;

		$path = AttachmentStore::store( $uid, 0, 'file.jpg', 'data' );
		$dir_name = basename( dirname( $path ) );

		$this->assertStringNotContainsString( $uid, $dir_name );
		// Deterministic: storing again under the same uid resolves to the same directory.
		$path2 = AttachmentStore::store( $uid, 1, 'file2.jpg', 'data' );
		$this->assertSame( dirname( $path ), dirname( $path2 ) );
	}

	// ── delete_dir() ──────────────────────────────────────────────────────────

	public function test_delete_dir_removes_directory_and_files(): void {
		$uid  = 'test-delete-' . uniqid();
		$path = AttachmentStore::store( $uid, 0, 'file.jpg', 'data' );
		$this->assertFileExists( $path );

		AttachmentStore::delete_dir( $uid );

		$this->assertFileDoesNotExist( $path );
		$this->assertDirectoryDoesNotExist( dirname( $path ) );
	}

	public function test_delete_dir_is_safe_when_dir_does_not_exist(): void {
		// Should not throw.
		AttachmentStore::delete_dir( 'nonexistent-uid-' . uniqid() );
		$this->addToAssertionCount( 1 );
	}

	// ── ensure_protected() ────────────────────────────────────────────────────

	public function test_ensure_protected_creates_base_directory(): void {
		AttachmentStore::ensure_protected();

		$upload = wp_upload_dir();
		$base   = trailingslashit( $upload['basedir'] ) . 'agnosis-queue';

		$this->assertDirectoryExists( $base );
	}

	public function test_ensure_protected_creates_htaccess(): void {
		AttachmentStore::ensure_protected();

		$upload   = wp_upload_dir();
		$htaccess = trailingslashit( $upload['basedir'] ) . 'agnosis-queue/.htaccess';

		$this->assertFileExists( $htaccess );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'Deny', file_get_contents( $htaccess ) );
	}

	public function test_ensure_protected_creates_index_php(): void {
		AttachmentStore::ensure_protected();

		$upload = wp_upload_dir();
		$index  = trailingslashit( $upload['basedir'] ) . 'agnosis-queue/index.php';

		$this->assertFileExists( $index );
	}

	public function test_ensure_protected_is_idempotent(): void {
		AttachmentStore::ensure_protected();
		AttachmentStore::ensure_protected(); // Second call must not throw or overwrite.

		$upload   = wp_upload_dir();
		$htaccess = trailingslashit( $upload['basedir'] ) . 'agnosis-queue/.htaccess';
		$this->assertFileExists( $htaccess );
	}

	// ── sweep_orphans() ───────────────────────────────────────────────────────

	public function test_sweep_orphans_removes_old_dir_with_no_queue_row(): void {
		$uid  = 'test-sweep-' . uniqid();
		$path = AttachmentStore::store( $uid, 0, 'file.jpg', 'data' );
		$dir  = dirname( $path );

		// Back-date the directory so it appears old.
		touch( $dir, time() - ( 8 * DAY_IN_SECONDS ) );

		AttachmentStore::sweep_orphans( 7 );

		$this->assertDirectoryDoesNotExist( $dir );
	}

	public function test_sweep_orphans_keeps_recent_dir(): void {
		$uid  = 'test-keep-recent-' . uniqid();
		$this->created_uids[] = $uid; // Cleaned up in tearDown if not swept.
		$path = AttachmentStore::store( $uid, 0, 'file.jpg', 'data' );
		$dir  = dirname( $path );

		// Directory mtime is now — within the TTL.
		AttachmentStore::sweep_orphans( 7 );

		$this->assertDirectoryExists( $dir );
	}

	public function test_sweep_orphans_keeps_old_dir_with_live_queue_row(): void {
		// Guards the P-3 hardening's keep-set rewrite: since the directory
		// name is now a derived HMAC rather than the raw message_uid, the
		// live-row check can no longer match a scanned entry name directly
		// against the queue table — this test would fail if that mapping
		// were ever broken.
		global $wpdb;

		$uid = 'test-live-' . uniqid();
		$this->created_uids[] = $uid;

		$path = AttachmentStore::store( $uid, 0, 'file.jpg', 'data' );
		$dir  = dirname( $path );

		$wpdb->insert(
			$wpdb->prefix . 'agnosis_queue',
			[
				'message_uid' => $uid,
				'status'      => 'pending',
				'raw_email'   => '{}',
			],
			[ '%s', '%s', '%s' ]
		);

		// Back-date the directory past the TTL — only a live row should save it.
		touch( $dir, time() - ( 8 * DAY_IN_SECONDS ) );

		AttachmentStore::sweep_orphans( 7 );

		$this->assertDirectoryExists( $dir );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}agnosis_queue WHERE message_uid = %s", $uid ) );
	}
}
