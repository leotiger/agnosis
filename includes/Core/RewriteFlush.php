<?php
/**
 * Debounced permalink-flush scheduler.
 *
 * WordPress only matches incoming requests against whatever rewrite rules are
 * cached in the `rewrite_rules` option — `add_rewrite_rule()` calls made
 * during the current request (Agnosis's own CPT registration, Lingua Forge's
 * language-prefixed CPT/taxonomy rules — see `language-router/includes/
 * rewrite/class-manager.php` in the Lingua Forge plugin) never retroactively
 * update that cached option; only an explicit `flush_rewrite_rules()` call
 * does. Confirmed live: an artist's newly approved submission 404'd on both
 * its primary-language URL and every translated sibling until an admin
 * manually resaved Settings → Permalinks.
 *
 * Rather than calling `flush_rewrite_rules()` synchronously at every publish
 * or translation event (expensive, and pointless to repeat many times in a
 * short burst — e.g. a submission that fans out to a dozen languages), this
 * class schedules ONE debounced WP-Cron event: repeated calls within the same
 * pending window collapse into the single flush that's already scheduled,
 * exactly the pattern `Compat\LinguaForge::schedule_fanout()` already uses
 * for its own deferred translation dispatch.
 *
 * Two call sites (see each one's own docblock for why):
 *   - `Publishing\ReviewEndpoints::finalize_publish()` — once a submission is
 *     approved and its primary-language post (plus, when applicable, its
 *     native-language sibling) exists.
 *   - `Compat\LinguaForge::mark_fanout_progress()` — once every Lingua
 *     Forge-configured target language has finished translating for a post.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class RewriteFlush {

	/** WP-Cron hook that performs the deferred flush. */
	private const HOOK = 'agnosis_flush_permalinks';

	/** Register the cron callback. Call once, during plugin boot. */
	public static function register(): void {
		add_action( self::HOOK, 'flush_rewrite_rules' );
	}

	/**
	 * Schedule a debounced permalink flush, unless one is already pending.
	 *
	 * Safe to call as often as needed — `wp_next_scheduled()` guarantees only
	 * one flush actually runs per pending window, no matter how many posts or
	 * languages trigger it in that window.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}
}
