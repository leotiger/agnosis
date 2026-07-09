<?php
/**
 * Integration tests for Core\Debug — fourth audit §5c.
 *
 * Core\Debug previously had zero test coverage at all (dir()/write()/clear()
 * pre-date this file). This file is scoped to the §5c fix itself — prune()'s
 * age-based deletion, and clear()/dir() only as much as needed to exercise it
 * safely — not a full backfill of every pre-existing method.
 *
 * Redirects Debug::dir() to a throwaway temp directory via the `agnosis_debug_dir`
 * filter the class already supports, so these tests never touch a real
 * wp-content/agnosis-debug/.
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\Debug;

class DebugTest extends \WP_UnitTestCase {

	private string $test_dir;

	protected function setUp(): void {
		parent::setUp();

		$this->test_dir = rtrim( sys_get_temp_dir(), '/' ) . '/agnosis-debug-test-' . wp_generate_password( 8, false );

		add_filter( 'agnosis_debug_dir', [ $this, 'filter_debug_dir' ] );
	}

	protected function tearDown(): void {
		remove_filter( 'agnosis_debug_dir', [ $this, 'filter_debug_dir' ] );

		if ( is_dir( $this->test_dir ) ) {
			foreach ( glob( $this->test_dir . '/*' ) ?: [] as $file ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test-only teardown of a throwaway temp dir.
			}
			@rmdir( $this->test_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test-only teardown of a throwaway temp dir.
		}

		parent::tearDown();
	}

	public function filter_debug_dir(): string {
		return $this->test_dir;
	}

	/** Writes a dump file directly (bypassing write()'s enabled() gate) and backdates its mtime. */
	private function seed_file( string $name, int $age_days ): string {
		if ( ! is_dir( $this->test_dir ) ) {
			mkdir( $this->test_dir, 0777, true );
		}

		$path = $this->test_dir . '/' . $name;
		file_put_contents( $path, 'raw dump content' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture, not plugin runtime code.

		$mtime = time() - ( $age_days * DAY_IN_SECONDS );
		touch( $path, $mtime );

		return $path;
	}

	public function test_prune_deletes_files_older_than_threshold(): void {
		$old = $this->seed_file( 'inbox-poll-old-aaaaaaaa.txt', 20 );
		$new = $this->seed_file( 'inbox-poll-new-bbbbbbbb.txt', 2 );

		$removed = Debug::prune( 14 );

		$this->assertSame( 1, $removed );
		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $new );
	}

	public function test_prune_runs_even_when_debug_logging_is_currently_disabled(): void {
		// The whole point of the fix: a dump left over from a PAST debug
		// session must still expire even if debug logging has since been
		// turned back off — gating prune() on enabled() would defeat that.
		update_option( 'agnosis_debug_enabled', false );
		$old = $this->seed_file( 'parser-attachments-old-cccccccc.txt', 30 );

		$removed = Debug::prune( 14 );

		$this->assertSame( 1, $removed );
		$this->assertFileDoesNotExist( $old );
	}

	public function test_prune_keeps_files_within_the_threshold(): void {
		$recent = $this->seed_file( 'media-adapter-recent-dddddddd.txt', 5 );

		$removed = Debug::prune( 14 );

		$this->assertSame( 0, $removed );
		$this->assertFileExists( $recent );
	}

	public function test_prune_returns_zero_when_directory_does_not_exist(): void {
		$this->assertDirectoryDoesNotExist( $this->test_dir );

		$this->assertSame( 0, Debug::prune( 14 ) );
	}

	public function test_clear_still_removes_regardless_of_age(): void {
		// Regression guard: clear() and prune() now share delete_matching();
		// clear()'s own "delete everything" contract must be unaffected.
		$old = $this->seed_file( 'post-creator-old-eeeeeeee.txt', 40 );
		$new = $this->seed_file( 'post-creator-new-ffffffff.txt', 1 );

		$removed = Debug::clear();

		$this->assertSame( 2, $removed );
		$this->assertFileDoesNotExist( $old );
		$this->assertFileDoesNotExist( $new );
	}
}
