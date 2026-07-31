<?php
/**
 * Compat\LinguaForge — the boundary between Agnosis's own reply translation
 * and Lingua Forge's generic comment translation (LF 2.7.0).
 *
 * Sixteenth audit, L-2 (2026-07-31). Agnosis owns reply translation for its own
 * post types end to end: `ActivityPub`'s three-version model produces the
 * strings and `mirror_reply_across_languages()` places them on the real sibling
 * posts. LF 2.7.0 shipped a generic version of that same idea — derived from
 * this plugin's design, per LF's own changelog — and if it ever ran over an
 * Agnosis artwork, an approved reply would be mirrored TWICE: once by us under
 * `_agnosis_reply_group_id`, once by LF under `_lf_comment_group_id`, with our
 * mirrors then eligible as inputs to its pass. Duplicate comment rows on every
 * sibling and doubled AI spend — failing silently, not loudly.
 *
 * No collision exists today, but only because three LF defaults happen to hold
 * at once (feature off, mode 'manual', and `eligible_types` defaulting to
 * `['comment']` while our replies are `agnosis_reply`/`agnosis_ap_reply`).
 * Those are another plugin's defaults to change, not ours, so the exclusion is
 * the real boundary — and it should break loudly here if anyone removes it.
 *
 * **Scope note, deliberately stated rather than left to be discovered:** these
 * tests exercise the filter callback's own contract, NOT the `add_filter()`
 * wiring in `Compat\LinguaForge::__construct()`. That constructor returns early
 * unless `is_active()` is true, which requires `LINGUAFORGE_FILE`/
 * `LINGUAFORGE_VERSION` to be defined — and PHP constants cannot be undefined
 * once set, so defining them inside one test would leak into every other test
 * sharing the process. Asserting the wiring is a bootstrap-fixture decision
 * (the same one that keeps `sitemap_extra_urls()` uncovered), not something to
 * force here. What is pinned is the part that carries the logic.
 *
 * @package Agnosis
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Compat;

use Agnosis\Compat\LinguaForge;

class LinguaForgeCommentTranslationBoundaryTest extends \WP_UnitTestCase {

	private const AGNOSIS_TYPES = [ 'agnosis_artwork', 'agnosis_biography', 'agnosis_event' ];

	private LinguaForge $compat;

	protected function setUp(): void {
		parent::setUp();
		$this->compat = new LinguaForge();
	}

	public function test_every_agnosis_post_type_is_excluded(): void {
		$excluded = $this->compat->exclude_agnosis_types_from_lf_comment_translation( [] );

		foreach ( self::AGNOSIS_TYPES as $type ) {
			$this->assertContains(
				$type,
				$excluded,
				"{$type} must be excluded from Lingua Forge's own comment translation — Agnosis mirrors replies for it already."
			);
		}
	}

	/** Additive: whatever another caller excluded stays excluded. */
	public function test_exclusion_preserves_another_callers_entries(): void {
		$excluded = $this->compat->exclude_agnosis_types_from_lf_comment_translation( [ 'some_other_plugin_type' ] );

		$this->assertContains( 'some_other_plugin_type', $excluded );
		$this->assertContains( 'agnosis_artwork', $excluded );
	}

	public function test_exclusion_does_not_duplicate_an_already_excluded_type(): void {
		$excluded = $this->compat->exclude_agnosis_types_from_lf_comment_translation( [ 'agnosis_artwork' ] );

		$this->assertCount(
			1,
			array_keys( $excluded, 'agnosis_artwork', true ),
			'A type already excluded by someone else must not be added a second time.'
		);
	}

	/**
	 * Ordinary WordPress content must be untouched: a site already using LF's
	 * comment translation on its own posts and pages keeps working exactly as
	 * it did before Agnosis was installed.
	 */
	public function test_ordinary_post_types_are_not_excluded(): void {
		$excluded = $this->compat->exclude_agnosis_types_from_lf_comment_translation( [] );

		$this->assertNotContains( 'post', $excluded );
		$this->assertNotContains( 'page', $excluded );
	}

	/** The returned list is a clean, re-indexed array — LF iterates it directly. */
	public function test_returns_a_sequential_array(): void {
		$excluded = $this->compat->exclude_agnosis_types_from_lf_comment_translation( [ 'agnosis_artwork', 'x' ] );

		$this->assertSame( array_values( $excluded ), $excluded );
	}
}
