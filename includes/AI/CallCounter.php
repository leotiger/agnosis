<?php
/**
 * Per-post counter for AI *translation* calls (seventh audit G-2).
 *
 * `agnosis-audit/NATIVE-LANGUAGE-PIPELINE.md` §7 estimated that the 0.9.19
 * native-first pipeline redesign would drop translation-related AI calls
 * from 3–4 per cross-language submission to roughly 2, but flagged it as an
 * estimate rather than a measured number ("worth instrumenting once
 * implemented to get a real number rather than this estimate"). This class
 * is that instrumentation: every actual translation call this plugin makes,
 * or observably triggers in Lingua Forge's own fan-out, increments a
 * per-post counter and writes a log line, so an operator can query
 * `_agnosis_ai_translation_calls` post meta or grep the `ai-calls` context
 * in Settings → Logs for real calls-per-submission figures.
 *
 * Deliberately NOT counted here: the description/vision AI call
 * (`AI\Pipeline::describe()`) that generates the artwork's title/excerpt/
 * body/tags/medium in the first place. §7's estimate is specifically about
 * *translation* calls — the description call is unchanged by the redesign
 * and isn't part of what's being validated.
 *
 * Two call sites feed this counter:
 *   - `Publishing\ReviewEndpoints::translate_native_content_to_primary()` —
 *     the one direct AI call this plugin makes per cross-language approval
 *     (native → primary, batched title/excerpt/body/medium/tags).
 *   - `Compat\LinguaForge`'s `linguaforge_translation_complete` listener —
 *     one increment per language Lingua Forge's own fan-out genuinely
 *     translates. Excludes the synthetic firing `sync_native_sibling()`
 *     does for its own AI-free native sibling (guarded by that class's
 *     `$suppress_native_sibling_term_sync` flag, which is also true for the
 *     exact duration of that one synthetic call).
 *
 * @package Agnosis\AI
 */

declare(strict_types=1);

namespace Agnosis\AI;

use Agnosis\Core\Logger;

class CallCounter {

	private const META_KEY = '_agnosis_ai_translation_calls';

	/**
	 * Record one AI translation call against $post_id and log it.
	 *
	 * @param int    $post_id Post the call was made on behalf of (the primary
	 *                        Agnosis post the submission belongs to).
	 * @param string $label   Short machine-readable label identifying the call
	 *                        site, e.g. 'native_to_primary' or 'lf_fanout'.
	 */
	public static function record( int $post_id, string $label ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$total = self::get_total( $post_id ) + 1;
		update_post_meta( $post_id, self::META_KEY, $total );

		Logger::info(
			sprintf( 'AI translation call (%s) for post #%d — running total for this submission: %d.', $label, $post_id, $total ),
			'ai-calls'
		);
	}

	/** Current tally of translation calls recorded against $post_id. */
	public static function get_total( int $post_id ): int {
		return (int) get_post_meta( $post_id, self::META_KEY, true );
	}
}
