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

	// =========================================================================
	// RECURRING_CRON_SCHEDULE parity — sixteenth audit, Q-3 (2026-07-31)
	// =========================================================================

	/**
	 * The single-event hooks, which are in CRON_HOOKS but deliberately NOT in
	 * RECURRING_CRON_SCHEDULE: each is scheduled per-call with its own
	 * arguments (a queue id, a translation dispatch payload) rather than on a
	 * recurrence, so there is no interval to give them.
	 */
	private const SINGLE_EVENT_HOOKS = [
		'agnosis_publish_submission',
		'agnosis_dispatch_lf_translations',
		'agnosis_flush_permalinks',
	];

	/**
	 * RECURRING_CRON_SCHEDULE must cover every recurring hook in CRON_HOOKS —
	 * no more, no fewer.
	 *
	 * Q-3: that map used to hold 13 of the 18, on the reasoning that the other
	 * five self-healed via their own classes' `init`-hooked schedulers. The
	 * runtime behaviour was fine; the risk was that a const named
	 * RECURRING_CRON_SCHEDULE, whose docblock reads as the authoritative
	 * inventory, was a partial one — so "is every recurring cron self-healing?
	 * yes, they're all in the map" was a reasonable check that returned the
	 * wrong answer. `agnosis_poll_inbox` — the entire email intake pipeline —
	 * was one of the five it silently didn't cover.
	 *
	 * 0.9.66 folded all five in and deleted the per-class schedulers. This
	 * test is what stops the split reappearing: add a recurring hook to
	 * CRON_HOOKS without giving it an interval here and it fails immediately,
	 * naming the hook.
	 */
	public function test_recurring_cron_schedule_covers_every_recurring_hook(): void {
		$recurring = array_values( array_diff( Activator::CRON_HOOKS, self::SINGLE_EVENT_HOOKS ) );

		$this->assertEqualsCanonicalizing(
			$recurring,
			array_keys( Activator::RECURRING_CRON_SCHEDULE ),
			'Activator::RECURRING_CRON_SCHEDULE has drifted from CRON_HOOKS. Every recurring hook needs an interval there, or it will only ever be scheduled once — at the single request where a version bump makes maybe_upgrade() run — and nothing will bring it back if anything clears it. That is the 2026-07-28 incident.'
		);
	}

	/** Every interval must be one WordPress can actually schedule. */
	public function test_recurring_cron_schedule_uses_only_known_intervals(): void {
		// 'every_five_minutes' is this plugin's own, registered via the
		// cron_schedules filter in Email\Inbox; the rest are WP core built-ins.
		$known = [ 'every_five_minutes', 'hourly', 'twicedaily', 'daily', 'weekly' ];

		foreach ( Activator::RECURRING_CRON_SCHEDULE as $hook => $recurrence ) {
			$this->assertContains(
				$recurrence,
				$known,
				"{$hook} is scheduled with an unknown recurrence '{$recurrence}' — wp_schedule_event() validates the recurrence against wp_get_schedules() and silently refuses to schedule anything it doesn't recognise."
			);
		}
	}

	/** The single-event hooks must stay OUT of the recurrence map. */
	public function test_single_event_hooks_are_not_in_the_recurring_schedule(): void {
		foreach ( self::SINGLE_EVENT_HOOKS as $hook ) {
			$this->assertArrayNotHasKey(
				$hook,
				Activator::RECURRING_CRON_SCHEDULE,
				"{$hook} is scheduled per-call with its own arguments; giving it a recurrence would have ensure_recurring_crons_scheduled() register an argument-less repeating copy of it."
			);
		}
	}
}
