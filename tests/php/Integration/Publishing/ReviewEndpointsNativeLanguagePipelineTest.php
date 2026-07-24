<?php
/**
 * Integration tests for the native-language AI pipeline's approval-time
 * behavior in ReviewEndpoints::finalize_publish() (agnosis-audit/
 * NATIVE-LANGUAGE-PIPELINE.md, Phase 6 — "New coverage needed").
 *
 * Phases 1-5 moved translation from "up to 3-4 AI calls scattered across
 * intake/display/edit" to "exactly 1 batched call, at approval, only when the
 * artist's language actually differs from the site's primary language." This
 * file proves that concretely rather than by reading the code:
 *
 *   - Phase 6a: a cross-language approval spends exactly ONE AI call, not
 *     several (§7's accounting, made checkable).
 *   - Phase 6c: the medium-term hallucination guard — previously exercised
 *     only at intake (see PostCreatorMediumTermTest) — still works now that
 *     validation happens at approval, against the AI's TRANSLATED medium
 *     value, not PostCreator's original native-language one.
 *   - Phase 6d (end-to-end leg): finalize_publish()'s $exclude_langs really
 *     does reach the scheduled Lingua Forge fan-out cron event. See
 *     LinguaForgeCompatTest for the lower-level schedule_fanout()/
 *     request_translations() coverage this complements.
 *
 * Lingua Forge stubs (LINGUAFORGE_FILE/LINGUAFORGE_VERSION constants,
 * linguaforge_languages()) ARE loaded by this file, and setUp() constructs a
 * fresh Compat\LinguaForge() instance — required for the cron-scheduling
 * assertions below to mean anything: 'agnosis_post_published' -> schedule_translations()
 * only fires at all if something actually registered that hook, and the
 * production singleton (Plugin::register_services()'s `new LinguaForge()`)
 * was already constructed at MU-plugin load time, before ANY test file's
 * stub constants exist — is_active() was false at that moment, so it never
 * registered schedule_translations() in the first place (same root cause
 * LinguaForgeCompatTest's own rename-cache tests document and work around
 * the same way: a fresh instance, constructed after the constants are
 * defined, registers hooks for real, scoped to that one test — WP_UnitTestCase
 * backs up/restores $wp_filter around every test). $lf_languages is
 * deliberately left null (never set to include 'es') so
 * LinguaForge::sync_native_sibling() — also now reachable, since is_active()
 * is true — stays a clean no-op throughout this file: only the
 * cron-scheduling behavior above it is under test here.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\CallCounter;
use Agnosis\Compat\LinguaForge;
use Agnosis\Publishing\ReviewEndpoints;
use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;
use WP_REST_Request;

require_once __DIR__ . '/../Compat/Stubs/lf_global_stubs.php';
require_once __DIR__ . '/../AI/Stubs/WpAiClientTestRegistry.php';
require_once __DIR__ . '/../AI/Stubs/wp_ai_provider_namespace_stubs.php';

class ReviewEndpointsNativeLanguagePipelineTest extends \WP_UnitTestCase {

	private ReviewEndpoints $endpoints;
	private int $artist_id;
	private int $post_id;

	private const VALID_TOKEN = 'native-pipeline-test-token';

	/** Matches translate_fields()'s expected response shape exactly. */
	private const TRANSLATED_RESPONSE = [
		'title'   => 'Sunrise Over the Bay',
		'excerpt' => 'A vivid excerpt in English.',
		'body'    => 'Full translated body text.',
		'medium'  => 'Oil Painting',
		'tags'    => 'Landscape | Coastal',
	];

	protected function setUp(): void {
		parent::setUp();

		$this->endpoints = new ReviewEndpoints();

		// See this file's own docblock for why: registers schedule_translations()
		// (and every other Compat\LinguaForge hook) on 'agnosis_post_published'
		// for real, for this test only — the production singleton missed doing
		// so at boot time, before LINGUAFORGE_FILE/VERSION existed.
		new LinguaForge();

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $this->artist_id, 'locale', 'es_ES' );

		// Simulates exactly what the native-first pipeline leaves behind at
		// intake (Phase 1) — post_title never translated, excerpt/body/medium
		// natively Spanish, _agnosis_native_lang recorded from the artist's
		// own profile locale (Phase 2).
		$this->post_id = (int) wp_insert_post( [
			'post_type'    => 'agnosis_artwork',
			'post_status'  => 'draft',
			'post_title'   => 'Amanecer sobre la bahía',
			'post_excerpt' => 'Un resumen vívido en español.',
			'post_content' => '<!-- wp:paragraph --><p>Texto completo del cuerpo en español.</p><!-- /wp:paragraph -->',
			'post_author'  => $this->artist_id,
		] );
		update_post_meta( $this->post_id, '_agnosis_native_lang', 'es' );
		update_post_meta( $this->post_id, '_agnosis_native_medium', 'Óleo' );
		// 2026-07-24 redesign: PostCreator::write_post_meta() no longer
		// attaches native tags to the real post_tag taxonomy at intake at
		// all — it caches the names as postmeta instead, which
		// ReviewEndpoints::finalize_tags() reads at approval time (see that
		// method's own docblock). Simulate exactly that cache, not a real
		// taxonomy assignment.
		update_post_meta( $this->post_id, '_agnosis_native_tags', wp_json_encode( [ 'Paisaje', 'Costero' ] ) );

		update_post_meta( $this->post_id, '_agnosis_review_token', self::VALID_TOKEN );
		update_post_meta( $this->post_id, '_agnosis_review_expiry', time() + 86400 * 7 );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( self::TRANSLATED_RESPONSE );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_ai_provider' );
		WpAiClientTestRegistry::reset();
		parent::tearDown();
	}

	private function approve(): \WP_REST_Response|\WP_Error {
		$req = new WP_REST_Request();
		$req->set_param( 'id', $this->post_id );
		$req->set_param( 'token', self::VALID_TOKEN );
		return $this->endpoints->approve( $req );
	}

	// -------------------------------------------------------------------------
	// Phase 6a — exactly one AI call for a cross-language approval
	// -------------------------------------------------------------------------

	public function test_approve_of_native_language_draft_makes_exactly_one_ai_call(): void {
		$this->approve();

		$this->assertCount(
			1,
			WpAiClientTestRegistry::$prompts,
			'A cross-language approval must translate title+excerpt+body+medium+tags together in one batched translate_fields() call — the concrete, checkable version of the design doc\'s §7 accounting (3-4 calls before this redesign, 1 now).'
		);
		$this->assertStringContainsString( 'TITLE:', WpAiClientTestRegistry::$prompts[0] );
		$this->assertStringContainsString( 'EXCERPT:', WpAiClientTestRegistry::$prompts[0] );
		$this->assertStringContainsString( 'BODY:', WpAiClientTestRegistry::$prompts[0] );
		$this->assertStringContainsString( 'MEDIUM:', WpAiClientTestRegistry::$prompts[0] );
		$this->assertStringContainsString( 'TAGS:', WpAiClientTestRegistry::$prompts[0] );
	}

	public function test_approve_of_same_language_draft_makes_zero_ai_calls(): void {
		// Artist's own language already matches the site's primary ('en') —
		// nothing to translate, the single-language case must cost nothing.
		update_post_meta( $this->post_id, '_agnosis_native_lang', 'en' );

		$this->approve();

		$this->assertSame( [], WpAiClientTestRegistry::$prompts );
	}

	/**
	 * Seventh audit G-2 — turns §7's estimate into a measured number.
	 * translate_native_content_to_primary()'s one batched call must record
	 * against Agnosis\AI\CallCounter, not just spend an AI prompt.
	 */
	public function test_approve_of_native_language_draft_records_one_ai_translation_call(): void {
		$this->approve();

		$this->assertSame( 1, CallCounter::get_total( $this->post_id ) );
	}

	public function test_approve_of_same_language_draft_records_zero_ai_translation_calls(): void {
		update_post_meta( $this->post_id, '_agnosis_native_lang', 'en' );

		$this->approve();

		$this->assertSame( 0, CallCounter::get_total( $this->post_id ) );
	}

	public function test_approve_writes_the_translated_content_onto_the_published_post(): void {
		$this->approve();

		$post = get_post( $this->post_id );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'Amanecer sobre la bahía', $post->post_title, 'post_title is never translated — it stays the artist\'s own verbatim words.' );
		$this->assertSame( self::TRANSLATED_RESPONSE['excerpt'], $post->post_excerpt );
		$this->assertStringContainsString( self::TRANSLATED_RESPONSE['body'], $post->post_content );
	}

	/**
	 * Regression test for the 2026-07-21 fix: translate_native_content_to_primary()
	 * used to hand-wrap the AI-translated body in a single
	 * '<!-- wp:paragraph --><p>...</p>' with no wpautop() call, so a
	 * multi-line translated body (e.g. a poem) lost every one of its line
	 * breaks the moment a native-language artwork was approved into the
	 * site's primary language. Now runs through the same
	 * wpautop()+PostCreator::paragraphs_to_blocks() path build_post_content()
	 * already uses.
	 */
	public function test_approve_preserves_line_breaks_in_multiline_translated_body(): void {
		$multiline_response          = self::TRANSLATED_RESPONSE;
		$multiline_response['body']  = "Line one\nLine two\nLine three";
		WpAiClientTestRegistry::$response = (string) wp_json_encode( $multiline_response );

		$this->approve();

		$content = get_post( $this->post_id )->post_content;
		// Three lines means two line breaks between them, not three.
		$this->assertSame( 2, substr_count( $content, '<br' ), 'Each line break in the translated body must survive as a <br /> tag.' );
	}

	public function test_approve_preserves_the_original_native_text_before_overwriting_it(): void {
		$this->approve();

		// Phase 2 (§4b) sanity check, reinforcing that translation happens
		// exactly once and the pre-translation text isn't simply lost.
		$this->assertSame( 'Un resumen vívido en español.', get_post_meta( $this->post_id, '_agnosis_native_excerpt', true ) );
		$this->assertStringContainsString( 'Texto completo del cuerpo en español.', (string) get_post_meta( $this->post_id, '_agnosis_native_body', true ) );
		// wp_get_post_tags() (what native_tags is built from) doesn't guarantee
		// insertion order, so this compares as sets rather than assertSame().
		$this->assertEqualsCanonicalizing(
			[ 'Paisaje', 'Costero' ],
			(array) json_decode( (string) get_post_meta( $this->post_id, '_agnosis_native_tags', true ), true )
		);
	}

	// -------------------------------------------------------------------------
	// Phase 6c — medium-term validation, now at approval time
	// -------------------------------------------------------------------------

	public function test_approve_assigns_a_valid_translated_medium_term(): void {
		// TRANSLATED_RESPONSE['medium'] = 'Oil Painting', a PromptConfig::CANONICAL_MEDIUMS
		// entry — must be assigned to the published post exactly as
		// PostCreatorMediumTermTest's own intake-time equivalent expects.
		$this->approve();

		$this->assertSame(
			[ 'Oil Painting' ],
			wp_get_post_terms( $this->post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] )
		);
	}

	public function test_approve_drops_a_hallucinated_translated_medium_term(): void {
		// Same hallucination scenario PostCreatorMediumTermTest exercises at
		// intake ('Interpretive Dance' is not in CANONICAL_MEDIUMS and no
		// agnosis_medium terms are seeded in this test env) — except this time
		// it's the AI's TRANSLATED value that hallucinates, which only
		// happens to be checkable now that validation runs at approval,
		// against translate_fields()'s own output (Phase 3), not at intake
		// against a native-language string that was never going to match a
		// primary-language vocabulary in the first place.
		WpAiClientTestRegistry::$response = (string) wp_json_encode(
			array_replace( self::TRANSLATED_RESPONSE, [ 'medium' => 'Interpretive Dance' ] )
		);

		$this->approve();

		$this->assertSame(
			[],
			wp_get_post_terms( $this->post_id, 'agnosis_medium', [ 'fields' => 'names', 'hide_empty' => false ] ),
			'A hallucinated medium term must be silently dropped, not assigned.'
		);
	}

	// -------------------------------------------------------------------------
	// Tag finalization (2026-07-24 redesign) — ReviewEndpoints::finalize_tags()
	// is now the ONE place a post's real post_tag terms are ever assigned; see
	// its own docblock, and PostCreator::write_post_meta()'s, for the
	// data-integrity incident this closes (no tags ever reachable under
	// Admin\TaxonomyLanguageFilter's "Primary language" view; a published
	// post still showing its own raw native-language tags on its own
	// primary-language listing).
	// -------------------------------------------------------------------------

	public function test_approve_assigns_resolved_primary_tags_for_a_cross_language_draft(): void {
		// Uses the class default fixture — TRANSLATED_RESPONSE's 'tags' entry is "Landscape | Coastal".
		$this->approve();

		$assigned = wp_get_post_terms( $this->post_id, 'post_tag', [ 'fields' => 'all', 'hide_empty' => false ] );
		$this->assertIsArray( $assigned );
		$this->assertEqualsCanonicalizing(
			[ 'Landscape', 'Coastal' ],
			wp_list_pluck( $assigned, 'name' ),
			'A successfully translated tag bundle must be assigned to the published post as real, resolved primary tags.'
		);
		foreach ( $assigned as $term ) {
			$this->assertSame(
				'',
				get_term_meta( $term->term_id, LinguaForge::TRANSLATED_TERM_META, true ),
				sprintf( 'Genuinely resolved primary tag "%s" must NOT be flagged as native-language — see LinguaForge::assign_resolved_primary_tags().', $term->name )
			);
		}
	}

	public function test_approve_of_same_language_draft_assigns_native_tags_directly(): void {
		// Artist's own language already matches the site's primary — no
		// translation happens at all (see test_approve_of_same_language_draft_makes_zero_ai_calls
		// above), but the tags cached at intake are still this post's real,
		// final ones — they ARE the primary vocabulary already.
		update_post_meta( $this->post_id, '_agnosis_native_lang', 'en' );

		$this->approve();

		$assigned = wp_get_post_terms( $this->post_id, 'post_tag', [ 'fields' => 'all', 'hide_empty' => false ] );
		$this->assertIsArray( $assigned );
		$this->assertEqualsCanonicalizing( [ 'Paisaje', 'Costero' ], wp_list_pluck( $assigned, 'name' ) );
		foreach ( $assigned as $term ) {
			$this->assertSame(
				'',
				get_term_meta( $term->term_id, LinguaForge::TRANSLATED_TERM_META, true ),
				'A same-language artist\'s tags are already primary-language and must never be flagged as native.'
			);
		}
	}

	public function test_approve_falls_back_to_flagged_native_tags_when_tag_translation_fails(): void {
		// The AI's response is missing 'tags' entirely — translate_fields()
		// drops a field it can't parse as a plain string rather than failing
		// the whole batch (see that method's own docblock), so
		// translate_native_content_to_primary() falls back to the untranslated
		// native names. finalize_tags() must publish those directly rather
		// than nothing — but correctly flagged as native-language, not
		// silently masquerading as resolved primary tags (the exact
		// 2026-07-24 incident this redesign closes).
		$response = self::TRANSLATED_RESPONSE;
		unset( $response['tags'] );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( $response );

		$this->approve();

		$assigned = wp_get_post_terms( $this->post_id, 'post_tag', [ 'fields' => 'all', 'hide_empty' => false ] );
		$this->assertIsArray( $assigned );
		$this->assertEqualsCanonicalizing( [ 'Paisaje', 'Costero' ], wp_list_pluck( $assigned, 'name' ) );
		foreach ( $assigned as $term ) {
			$this->assertSame(
				'es',
				get_term_meta( $term->term_id, LinguaForge::TRANSLATED_TERM_META, true ),
				sprintf( 'Untranslated native-language fallback tag "%s" must be flagged non-primary, not left indistinguishable from a genuine primary tag.', $term->name )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Phase 6d (end-to-end leg) — $exclude_langs reaches the LF fan-out cron
	// -------------------------------------------------------------------------

	public function test_approve_schedules_the_lf_fanout_cron_excluding_the_native_language(): void {
		$this->approve();

		$this->assertNotFalse(
			wp_next_scheduled( 'agnosis_dispatch_lf_translations', [ $this->post_id, [ 'es' ] ] ),
			'finalize_publish() must compute $exclude_langs from _agnosis_native_lang and pass it all the way through do_action(agnosis_post_published, ...) -> LinguaForge::schedule_translations() -> schedule_fanout() so Lingua Forge never re-translates the language Agnosis just populated directly.'
		);
	}

	public function test_approve_with_no_declared_native_language_schedules_the_lf_fanout_cron_with_no_exclusions(): void {
		// Empty _agnosis_native_lang (e.g. an artist whose WP locale couldn't
		// be resolved at intake) is the genuinely-nothing-to-exclude case —
		// NOT the same as native_lang equaling the primary language, which
		// finalize_publish() still records as a one-element exclude list (see
		// its own inline comment: read straight from post meta, not gated on
		// whether translation actually happened).
		update_post_meta( $this->post_id, '_agnosis_native_lang', '' );

		$this->approve();

		$this->assertNotFalse(
			wp_next_scheduled( 'agnosis_dispatch_lf_translations', [ $this->post_id ] ),
			'With no declared native language there is nothing to exclude — the fan-out must be scheduled under the original one-arg signature, not a two-arg one with an empty array.'
		);
	}

	public function test_approve_when_native_language_matches_primary_still_excludes_it_from_the_fanout(): void {
		// finalize_publish() reads _agnosis_native_lang straight from post meta
		// for $exclude_langs regardless of whether translate_native_content_to_primary()
		// actually ran — so even though 'en' == primary means zero AI calls
		// happen (see test_approve_of_same_language_draft_makes_zero_ai_calls),
		// the cron event is still scheduled excluding 'en'. Harmless in
		// practice (the source language is never a fan-out target to begin
		// with — see LinguaForgeCompatTest's own get_target_languages()
		// coverage), but pinned here as the actual, current behavior so a
		// future change to this ordering is a deliberate choice, not a silent
		// regression either way.
		update_post_meta( $this->post_id, '_agnosis_native_lang', 'en' );

		$this->approve();

		$this->assertNotFalse(
			wp_next_scheduled( 'agnosis_dispatch_lf_translations', [ $this->post_id, [ 'en' ] ] )
		);
	}
}
