<?php
/**
 * Integration test — cron-hook list parity between Core\Activator and
 * uninstall.php (audit §4a, AUDIT-1.0.0.md).
 *
 * uninstall.php deliberately loads none of the plugin's classes (see that
 * file's own header docblock), so it cannot reference Activator::CRON_HOOKS
 * directly — it keeps its own literal, hand-maintained copy of the same
 * list instead. That duplication is exactly what let the two drift apart
 * before this test existed: uninstall.php's own `$cron_hooks` array was
 * missing `agnosis_vote_digest` and `agnosis_flush_permalinks` entirely,
 * silently, for some time.
 *
 * This test parses uninstall.php's source with PHP's own tokenizer — not a
 * regex, and never an `include`/`require` of the file itself, which would
 * both need `WP_UNINSTALL_PLUGIN` defined and actually execute the teardown
 * (dropping every Agnosis table) against whatever database the test suite
 * is pointed at. Reading the token stream is the only safe way to inspect
 * this specific file's contents from a test.
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\Activator;

class CronHookParityTest extends \WP_UnitTestCase {

	/**
	 * Extract the string literals inside uninstall.php's `$cron_hooks = [ ... ]`
	 * array literal via token_get_all(), without ever including/executing the
	 * file. Stops as soon as the array literal that follows the variable's
	 * first appearance closes, so the file's later `foreach ( $cron_hooks ... )`
	 * re-use of the same variable name is never reached.
	 *
	 * @return array<int, string>
	 */
	private function extract_uninstall_cron_hooks(): array {
		$path = dirname( __DIR__, 4 ) . '/uninstall.php';
		$this->assertFileExists( $path, 'uninstall.php must exist at the plugin root for this test to inspect it.' );

		$tokens = token_get_all( (string) file_get_contents( $path ) );

		$hooks     = [];
		$capturing = false;
		$depth     = 0;

		foreach ( $tokens as $token ) {
			if ( ! $capturing ) {
				if ( is_array( $token ) && T_VARIABLE === $token[0] && '$cron_hooks' === $token[1] ) {
					$capturing = true;
				}
				continue;
			}

			if ( is_string( $token ) ) {
				if ( '[' === $token ) {
					++$depth;
					continue;
				}

				if ( ']' === $token ) {
					--$depth;
					if ( 0 === $depth ) {
						break; // The $cron_hooks array literal is fully consumed.
					}
					continue;
				}
			}

			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$hooks[] = trim( $token[1], "'\"" );
			}
		}

		return $hooks;
	}

	public function test_extraction_helper_finds_a_non_empty_array(): void {
		// A sanity check on the extraction mechanism itself, independent of
		// the actual parity assertion below — if this fails, the tokenizer
		// walk is broken, not the source files it's reading.
		$hooks = $this->extract_uninstall_cron_hooks();

		$this->assertNotEmpty( $hooks, 'Failed to extract any hooks from uninstall.php — the tokenizer walk itself may be broken, not the underlying parity.' );
		$this->assertContains( 'agnosis_poll_inbox', $hooks, 'The extraction must find at least this long-standing, unambiguous entry.' );
	}

	/**
	 * The actual regression test: uninstall.php's $cron_hooks array must list
	 * exactly the same set of hooks as Activator::CRON_HOOKS — no more, no
	 * fewer. Order doesn't matter to either consumer (both just loop and
	 * call wp_unschedule_hook() per hook), so this compares as sets.
	 */
	public function test_uninstall_cron_hooks_match_activator_cron_hooks(): void {
		$uninstall_hooks = $this->extract_uninstall_cron_hooks();

		$this->assertEqualsCanonicalizing(
			Activator::CRON_HOOKS,
			$uninstall_hooks,
			"uninstall.php's \$cron_hooks array has drifted from Activator::CRON_HOOKS — the single source of truth. Update uninstall.php's literal copy to match (see the comment above that array, and Activator::CRON_HOOKS's own docblock)."
		);
	}

	public function test_uninstall_cron_hooks_has_no_duplicates(): void {
		$uninstall_hooks = $this->extract_uninstall_cron_hooks();

		$this->assertSame( $uninstall_hooks, array_unique( $uninstall_hooks ), "uninstall.php's \$cron_hooks array must not list the same hook twice." );
	}
}
