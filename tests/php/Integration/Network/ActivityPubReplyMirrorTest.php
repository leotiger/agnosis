<?php
/**
 * Integration tests — Network\ActivityPub's cross-post reply mirroring.
 *
 * agnosis-audit/REPLY-LANGUAGE-MIRRORING-ROADMAP.md, RLM1-RLM3+RLM6
 * (2026-07-29), widened by RLM4/RLM5/RLM9 (2026-07-29, same day) once
 * Ulises answered §4's open questions directly in that document:
 *   - Q1 (late-created siblings): "we conform with our three language
 *     approach... [a newly available one] should show up" -> RLM9,
 *     backfill_reply_mirrors_for_new_sibling(), hooked on
 *     'linguaforge_translation_complete'.
 *   - Q2 (independent interactivity): "we allow replies in every context
 *     for every user" -> a mirror is a real, fully interactive reply, not
 *     a read-only reflection; replying to one is exactly as valid as
 *     replying to the canonical row.
 *   - Q3 (nested replies): "for sure, that's vital" -> RLM5, both
 *     artist-authored AND visitor-submitted replies-to-a-reply now mirror,
 *     with the mirror's own comment_parent mapped to whichever row
 *     represents the parent's OWN reply-group on that same sibling.
 *   - Q4 (edit cascading): "we want this, yes" -> RLM4,
 *     handle_reply_content_edit(), hooked on 'edit_comment'.
 *   - Q5 (un-mirroring on rejection): "does not make any sense to keep
 *     mirrored comments" -> RLM3 (built the same day as the original
 *     assessment, unchanged by this round).
 *
 * Covers:
 *   - Core mirroring (RLM2): mirror creation across 0/1/2 actual siblings
 *     (including the "only two languages" case), group-id integrity,
 *     idempotency, cascading delete, one-directional-cascade guard.
 *   - RLM5: a nested visitor reply's mirror is correctly parented onto
 *     whichever row represents ITS OWN parent's reply-group on that same
 *     sibling; skipped (not orphaned) on a sibling where the parent has no
 *     representative row yet; and the recursive "cascade forward" that
 *     catches a child up once its parent's own mirrors are (re)created,
 *     regardless of the order replies happened to be approved in.
 *   - RLM5: an artist-authored (outbound) reply mirrors too, using WP13's
 *     own outbound three-version model — source is the artist's declared
 *     language, the third slot is the ORIGINAL COMMENTER's language (not
 *     "artist-native", which already IS the source for an outbound reply).
 *   - RLM9: backfill_reply_mirrors_for_new_sibling() picks up an
 *     already-approved reply that had no real sibling to mirror onto at
 *     approval time, once one appears.
 *   - RLM4: editing the CANONICAL row's own text re-translates and pushes
 *     fresh content to every mirror; editing an individual MIRROR's own
 *     text does not cascade anywhere (one-directional, same as delete).
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\ActivityPub;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;
use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;

require_once __DIR__ . '/../AI/Stubs/WpAiClientTestRegistry.php';
require_once __DIR__ . '/../AI/Stubs/wp_ai_provider_namespace_stubs.php';

class ActivityPubReplyMirrorTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeLinguaForge::reset();
		WpAiClientTestRegistry::reset();
		update_option( 'linguaforge_primary_language', 'en' );
		delete_option( 'agnosis_ai_provider' );
		add_filter( 'agnosis_translation_languages', static fn( array $langs ): array => array_replace(
			$langs,
			[ 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ca' => 'Catalan' ]
		) );
	}

	protected function tearDown(): void {
		FakeLinguaForge::reset();
		WpAiClientTestRegistry::reset();
		delete_option( 'agnosis_ai_provider' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a WP user with the agnosis_artist role, optionally with a
	 * declared native-language locale, and return their ID. 'role'/'locale'
	 * passed directly to the factory (rather than get_userdata()->add_role()
	 * afterward) sidesteps both the add_role()-on-WP_User|false chain and a
	 * second int|WP_Error touch point — same pattern
	 * ActivityPubOutboundReplyTranslationTest::create_artist() already uses.
	 */
	private function create_artist( string $locale = '' ): int {
		// @phpstan-ignore-next-line -- factory()->user->create() returns int|WP_Error; a bare artist fixture with a role/email/locale never fails in practice.
		return self::factory()->user->create( array_filter( [
			'role'       => 'agnosis_artist',
			'user_email' => uniqid( 'artist', true ) . '@example.com',
			'locale'     => $locale,
		] ) );
	}

	private function create_artwork( int $artist_id ): int {
		// @phpstan-ignore-next-line -- factory()->post->create() returns int|WP_Error; fixed, valid fixture args never fail.
		return self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist_id,
		] );
	}

	/**
	 * A canonical (not-yet-mirrored) visitor reply, tagged exactly the way
	 * store_local_reply()/handle_create_reply() tag one at insertion time
	 * (RLM1) — REPLY_GROUP_ID_META set to its own id, REPLY_SOURCE_LANG_META
	 * set to $source_lang. comment_parent defaults to 0 (top-level) and
	 * user_id defaults to 0 (visitor, not artist) unless overridden.
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function create_canonical_reply( int $post_id, string $source_lang, array $overrides = [] ): int {
		$comment_id = self::factory()->comment->create( array_merge( [
			'comment_post_ID'  => $post_id,
			'comment_type'     => ActivityPub::LOCAL_REPLY_COMMENT_TYPE,
			'comment_approved' => '0',
			'comment_content'  => 'Original text.',
		], $overrides ) );

		// @phpstan-ignore-next-line -- factory()->comment->create() returns int|WP_Error; fixed, valid fixture args never fail. Bare assignment above isn't itself flagged — only strictly int-typed consumption is, starting here.
		update_comment_meta( $comment_id, '_agnosis_reply_group_id', (string) $comment_id );
		// @phpstan-ignore-next-line -- see above.
		update_comment_meta( $comment_id, '_agnosis_reply_source_lang', $source_lang );

		// @phpstan-ignore-next-line -- see above.
		return $comment_id;
	}

	/** @return \WP_Comment[] */
	private function comments_on( int $post_id ): array {
		$comments = get_comments( [ 'post_id' => $post_id, 'status' => 'any' ] );
		if ( ! is_array( $comments ) ) {
			return [];
		}
		return array_values( array_filter( $comments, static fn( $c ) => $c instanceof \WP_Comment ) );
	}

	/** The single row on $post_id whose _agnosis_reply_group_id equals $group_id, or null. */
	private function row_with_group_id( int $post_id, string $group_id ): ?\WP_Comment {
		foreach ( $this->comments_on( $post_id ) as $comment ) {
			if ( $group_id === get_comment_meta( (int) $comment->comment_ID, '_agnosis_reply_group_id', true ) ) {
				return $comment;
			}
		}
		return null;
	}

	/**
	 * Approve via the REAL wp_set_comment_status() — not a direct call to
	 * handle_reply_status_transition() — so the comment's own
	 * comment_approved column is actually persisted, not just the handler
	 * invoked in isolation. mirror_reply_across_languages()'s recursive
	 * cascade-to-children step and backfill_reply_mirrors_for_new_sibling()'s
	 * sweep both run their OWN get_comments(['status' => 'approve']) queries
	 * against real DB state; a comment "approved" only via a bare handler
	 * call (the original shortcut here) is invisible to those, silently
	 * breaking exactly the out-of-order-approval and backfill scenarios this
	 * file tests. wp_set_comment_status() fires the real
	 * 'transition_comment_status' hook itself (Plugin.php already wires
	 * handle_reply_status_transition() to it for real), so this is also a
	 * more faithful simulation of production than the manual call was —
	 * same convention already established in ActivityPubTest.php.
	 */
	private function approve( int $comment_id ): void {
		wp_set_comment_status( $comment_id, 'approve' );
	}

	private function trash( int $comment_id ): void {
		wp_set_comment_status( $comment_id, 'trash' );
	}

	/**
	 * Invoke a private/protected ActivityPub method by name — same convention as ActivityPubOutboundReplyTranslationTest.
	 *
	 * @param array<int, mixed> $args
	 */
	private function invoke( string $method, array $args = [] ): mixed {
		$ref = new \ReflectionMethod( ActivityPub::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( new ActivityPub(), $args );
	}

	/** The most recently inserted child comment of $parent_comment_id on $post_id. */
	private function latest_child_comment( int $post_id, int $parent_comment_id ): ?\WP_Comment {
		$comments = get_comments( [
			'post_id' => $post_id,
			'parent'  => $parent_comment_id,
			'status'  => 'any',
			'orderby' => 'comment_ID',
			'order'   => 'DESC',
			'number'  => 1,
		] );
		return ( is_array( $comments ) && ! empty( $comments ) && $comments[0] instanceof \WP_Comment ) ? $comments[0] : null;
	}

	// -------------------------------------------------------------------------
	// Core mirroring (RLM2)
	// -------------------------------------------------------------------------

	public function test_approving_a_reply_mirrors_it_onto_primary_and_artist_native_siblings(): void {
		$artist_id = $this->create_artist( 'de_DE' ); // artist's own native language: 'de'
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' ); // canonical post is the Chinese sibling

		$primary_sibling_id = $this->create_artwork( $artist_id );
		$artist_sibling_id  = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $primary_sibling_id );
		FakeLinguaForge::link( $post_id, 'de', $artist_sibling_id );

		$comment_id = $this->create_canonical_reply( $post_id, 'zh' );
		update_comment_meta( $comment_id, '_agnosis_reply_translated_primary', 'Hello!' );
		update_comment_meta( $comment_id, '_agnosis_reply_translated_content', 'Hallo!' );

		$this->approve( $comment_id );

		$primary_comments = $this->comments_on( $primary_sibling_id );
		$this->assertCount( 1, $primary_comments, 'A mirror must appear on the primary-language sibling.' );
		$this->assertSame( 'Hello!', $primary_comments[0]->comment_content );
		$this->assertSame( '1', $primary_comments[0]->comment_approved );
		$this->assertSame( ActivityPub::LOCAL_REPLY_COMMENT_TYPE, $primary_comments[0]->comment_type );
		$this->assertSame( (string) $comment_id, get_comment_meta( (int) $primary_comments[0]->comment_ID, '_agnosis_reply_group_id', true ) );

		$artist_comments = $this->comments_on( $artist_sibling_id );
		$this->assertCount( 1, $artist_comments, 'A mirror must appear on the artist-native-language sibling.' );
		$this->assertSame( 'Hallo!', $artist_comments[0]->comment_content );
		$this->assertSame( (string) $comment_id, get_comment_meta( (int) $artist_comments[0]->comment_ID, '_agnosis_reply_group_id', true ) );

		$this->assertCount( 1, $this->comments_on( $post_id ), 'The canonical post itself must not gain a duplicate/self-mirror.' );
	}

	public function test_a_single_real_sibling_still_gets_mirrored_sometimes_only_two_languages(): void {
		$artist_id = $this->create_artist( 'en_US' ); // artist's native language equals the site primary
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' );

		$primary_sibling_id = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $primary_sibling_id );

		$comment_id = $this->create_canonical_reply( $post_id, 'zh' );
		update_comment_meta( $comment_id, '_agnosis_reply_translated_primary', 'Hello!' );
		update_comment_meta( $comment_id, '_agnosis_reply_translated_content', 'Hello!' );

		$this->approve( $comment_id );

		$this->assertCount( 1, $this->comments_on( $primary_sibling_id ), 'A single real sibling must still receive a mirror.' );
	}

	public function test_mirror_is_skipped_silently_when_no_real_sibling_exists_for_a_target_language(): void {
		$artist_id = $this->create_artist( 'de_DE' );
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' );

		$primary_sibling_id = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $primary_sibling_id );

		$comment_id = $this->create_canonical_reply( $post_id, 'zh' );
		update_comment_meta( $comment_id, '_agnosis_reply_translated_primary', 'Hello!' );
		update_comment_meta( $comment_id, '_agnosis_reply_translated_content', 'Hallo!' );

		$this->approve( $comment_id );

		$this->assertCount( 1, $this->comments_on( $primary_sibling_id ), 'The existing primary sibling must still get its mirror.' );
	}

	public function test_no_mirrors_at_all_when_the_artwork_has_no_real_siblings(): void {
		$artist_id  = $this->create_artist( 'de_DE' );
		$post_id    = $this->create_artwork( $artist_id );
		$comment_id = $this->create_canonical_reply( $post_id, 'en' );

		$this->approve( $comment_id );

		$this->assertCount( 1, $this->comments_on( $post_id ), 'Only the canonical reply itself — no mirrors were ever possible.' );
	}

	public function test_re_approving_never_inserts_a_second_mirror(): void {
		$artist_id = $this->create_artist( 'de_DE' );
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' );

		$sibling_id = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $sibling_id );

		$comment_id = $this->create_canonical_reply( $post_id, 'zh' );

		$this->approve( $comment_id );
		$this->approve( $comment_id );

		$this->assertCount( 1, $this->comments_on( $sibling_id ), 'A duplicate approval transition must never insert a second mirror for the same sibling.' );
	}

	// -------------------------------------------------------------------------
	// Cascading delete (RLM3, roadmap §4 Q5)
	// -------------------------------------------------------------------------

	public function test_trashing_the_canonical_reply_hard_deletes_every_mirror(): void {
		$artist_id = $this->create_artist( 'de_DE' );
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' );

		$primary_sibling_id = $this->create_artwork( $artist_id );
		$artist_sibling_id  = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $primary_sibling_id );
		FakeLinguaForge::link( $post_id, 'de', $artist_sibling_id );

		$comment_id = $this->create_canonical_reply( $post_id, 'zh' );
		$this->approve( $comment_id );
		$this->assertCount( 1, $this->comments_on( $primary_sibling_id ) );
		$this->assertCount( 1, $this->comments_on( $artist_sibling_id ) );

		$this->trash( $comment_id );

		$this->assertCount( 0, $this->comments_on( $primary_sibling_id ), 'Rejecting the canonical reply must remove its primary-language mirror.' );
		$this->assertCount( 0, $this->comments_on( $artist_sibling_id ), 'Rejecting the canonical reply must remove its artist-language mirror.' );
	}

	public function test_trashing_one_mirror_does_not_cascade_back_to_the_canonical_or_other_mirrors(): void {
		$artist_id = $this->create_artist( 'de_DE' );
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' );

		$primary_sibling_id = $this->create_artwork( $artist_id );
		$artist_sibling_id  = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $primary_sibling_id );
		FakeLinguaForge::link( $post_id, 'de', $artist_sibling_id );

		$comment_id = $this->create_canonical_reply( $post_id, 'zh' );
		$this->approve( $comment_id );

		$primary_mirror_id = (int) $this->comments_on( $primary_sibling_id )[0]->comment_ID;
		$this->trash( $primary_mirror_id );

		$this->assertCount( 1, $this->comments_on( $post_id ), 'Trashing one mirror must not delete the canonical reply.' );
		$this->assertCount( 1, $this->comments_on( $artist_sibling_id ), 'Trashing one mirror must not cascade to a sibling mirror (one-directional, roadmap §4 Q2/Q5).' );
	}

	// -------------------------------------------------------------------------
	// RLM5 — nested (visitor) reply mirroring, roadmap §4 Q2/Q3
	// -------------------------------------------------------------------------

	public function test_nested_reply_mirrors_with_comment_parent_mapped_to_the_parents_mirror_on_each_sibling(): void {
		$artist_id = $this->create_artist( 'de_DE' );
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' );

		$primary_sibling_id = $this->create_artwork( $artist_id );
		$artist_sibling_id  = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $primary_sibling_id );
		FakeLinguaForge::link( $post_id, 'de', $artist_sibling_id );

		$parent_id = $this->create_canonical_reply( $post_id, 'zh' );
		$this->approve( $parent_id );
		$parent_primary_mirror_id = (int) $this->comments_on( $primary_sibling_id )[0]->comment_ID;
		$parent_artist_mirror_id  = (int) $this->comments_on( $artist_sibling_id )[0]->comment_ID;

		$child_id = $this->create_canonical_reply( $post_id, 'zh', [ 'comment_parent' => $parent_id ] );
		$this->approve( $child_id );

		$child_group_id      = (string) get_comment_meta( $child_id, '_agnosis_reply_group_id', true );
		$child_primary_mirror = $this->row_with_group_id( $primary_sibling_id, $child_group_id );
		$child_artist_mirror  = $this->row_with_group_id( $artist_sibling_id, $child_group_id );

		$this->assertNotNull( $child_primary_mirror, 'The nested reply must also mirror onto the primary sibling, where its parent already has a mirror.' );
		$this->assertSame( $parent_primary_mirror_id, (int) $child_primary_mirror->comment_parent, "The child's mirror on the primary sibling must be parented to the PARENT's own mirror there, not the original cross-post parent id." );

		$this->assertNotNull( $child_artist_mirror );
		$this->assertSame( $parent_artist_mirror_id, (int) $child_artist_mirror->comment_parent );
	}

	public function test_nested_reply_is_skipped_when_its_parent_has_no_group_id_at_all(): void {
		// A plain pre-RLM1 comment (no _agnosis_reply_group_id meta at all) —
		// find_mirror_on_sibling() must treat this as "no representative row
		// can ever be found" and skip the child rather than orphan it.
		$artist_id = $this->create_artist( 'de_DE' );
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' );

		$sibling_id = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $sibling_id );

		$untagged_parent_id = self::factory()->comment->create( [
			'comment_post_ID'  => $post_id,
			'comment_type'     => ActivityPub::LOCAL_REPLY_COMMENT_TYPE,
			'comment_approved' => '1',
			'comment_content'  => 'A pre-RLM1 legacy comment.',
		] );
		// Deliberately no _agnosis_reply_group_id meta set on $untagged_parent_id.

		$child_id = $this->create_canonical_reply( $post_id, 'zh', [ 'comment_parent' => $untagged_parent_id ] );
		$this->approve( $child_id );

		$this->assertCount( 0, $this->comments_on( $sibling_id ), 'A reply whose parent has no reply-group must not be mirrored anywhere.' );
	}

	public function test_approving_the_parent_later_catches_up_a_child_approved_out_of_order(): void {
		// Roadmap §4 Q1/Q3's "cascade forward": an artist can approve a
		// nested reply before its own parent — mirror_reply_across_languages()
		// must retry the child once the parent's own mirrors finally exist.
		$artist_id = $this->create_artist( 'de_DE' );
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' );

		$primary_sibling_id = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $primary_sibling_id );

		$parent_id = $this->create_canonical_reply( $post_id, 'zh' ); // left UNAPPROVED
		$child_id  = $this->create_canonical_reply( $post_id, 'zh', [ 'comment_parent' => $parent_id ] );

		$this->approve( $child_id ); // Out of order — parent still held.
		$this->assertCount( 0, $this->comments_on( $primary_sibling_id ), 'Nothing can be mirrored yet — the parent has no representative row anywhere but its own post.' );

		$this->approve( $parent_id ); // Now approve the parent.

		$child_group_id       = (string) get_comment_meta( $child_id, '_agnosis_reply_group_id', true );
		$parent_mirror        = $this->comments_on( $primary_sibling_id )[0] ?? null;
		$child_primary_mirror = $this->row_with_group_id( $primary_sibling_id, $child_group_id );

		$this->assertNotNull( $parent_mirror, "The parent's own mirror must now exist." );
		$this->assertNotNull( $child_primary_mirror, "The child, approved earlier, must be caught up once its parent's mirror appears." );
		$this->assertSame( (int) $parent_mirror->comment_ID, (int) $child_primary_mirror->comment_parent );
	}

	// -------------------------------------------------------------------------
	// RLM5 — artist-authored (outbound) reply mirroring, roadmap §4 Q3
	// -------------------------------------------------------------------------

	public function test_artist_authored_reply_mirrors_using_the_original_commenters_language_as_the_third_slot(): void {
		$artist_id = $this->create_artist( 'ca_ES' ); // artist's own declared language: 'ca'
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' ); // canonical post's own language must be NEITHER 'en' nor 'fr' nor 'ca' — otherwise that target gets skipped as "already visible right here" and the parent reply's own mirrors below would come up short.

		$primary_sibling_id = $this->create_artwork( $artist_id );
		$fr_sibling_id       = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $primary_sibling_id );
		FakeLinguaForge::link( $post_id, 'fr', $fr_sibling_id );
		// Deliberately no 'ca' sibling — the artist's own reply must not force one into existence.

		$parent_id = $this->create_canonical_reply( $post_id, 'fr' );
		$this->approve( $parent_id ); // Mirrors the visitor's parent onto both siblings first.
		$parent_primary_mirror_id = (int) $this->comments_on( $primary_sibling_id )[0]->comment_ID;
		$parent_fr_mirror_id       = (int) $this->comments_on( $fr_sibling_id )[0]->comment_ID;

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'content' => 'Contingut traduït' ] );

		$this->invoke( 'store_artist_gateway_reply', [ $post_id, $parent_id, 'Text en català', false ] );
		$reply = $this->latest_child_comment( $post_id, $parent_id );
		$this->assertNotNull( $reply );
		$reply_id = (int) $reply->comment_ID;

		$this->invoke( 'drain_reply_translation_queue' );

		$this->assertSame( 'ca', get_comment_meta( $reply_id, '_agnosis_reply_source_lang', true ) );

		$group_id       = (string) get_comment_meta( $reply_id, '_agnosis_reply_group_id', true );
		$this->assertSame( (string) $reply_id, $group_id, 'The artist reply must be tagged as its own fresh canonical reply.' );

		$primary_mirror = $this->row_with_group_id( $primary_sibling_id, $group_id );
		$fr_mirror       = $this->row_with_group_id( $fr_sibling_id, $group_id );

		$this->assertNotNull( $primary_mirror, 'The artist reply must mirror onto the site-primary-language sibling.' );
		$this->assertSame( $parent_primary_mirror_id, (int) $primary_mirror->comment_parent );

		$this->assertNotNull( $fr_mirror, "The artist reply must mirror onto the original commenter's own language sibling." );
		$this->assertSame( $parent_fr_mirror_id, (int) $fr_mirror->comment_parent );
		// No third mirror is force-created for the artist's own 'ca' — there
		// is no real 'ca' sibling registered anywhere in this test.
	}

	// -------------------------------------------------------------------------
	// RLM9 — backfill on a newly-created sibling, roadmap §4 Q1
	// -------------------------------------------------------------------------

	public function test_backfill_mirrors_an_already_approved_reply_once_a_new_sibling_appears(): void {
		$artist_id  = $this->create_artist( 'de_DE' );
		$post_id    = $this->create_artwork( $artist_id );
		$comment_id = $this->create_canonical_reply( $post_id, 'en' );

		$this->approve( $comment_id ); // No real siblings exist yet — no mirrors possible.
		$this->assertCount( 1, $this->comments_on( $post_id ) );

		$new_sibling_id = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'de', $new_sibling_id ); // Now the artist-language sibling exists.

		( new ActivityPub() )->backfill_reply_mirrors_for_new_sibling( $new_sibling_id, $post_id, 'de' );

		$this->assertCount( 1, $this->comments_on( $new_sibling_id ), 'The already-approved reply must now be mirrored onto the newly-created sibling.' );
	}

	public function test_backfill_is_a_no_op_when_the_reply_is_already_mirrored_there(): void {
		$artist_id = $this->create_artist( 'de_DE' );
		$post_id   = $this->create_artwork( $artist_id );

		$sibling_id = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'de', $sibling_id );

		$comment_id = $this->create_canonical_reply( $post_id, 'en' );
		$this->approve( $comment_id );
		$this->assertCount( 1, $this->comments_on( $sibling_id ) );

		( new ActivityPub() )->backfill_reply_mirrors_for_new_sibling( $sibling_id, $post_id, 'de' );

		$this->assertCount( 1, $this->comments_on( $sibling_id ), 'Re-running backfill for an already-mirrored sibling must not create a duplicate.' );
	}

	// -------------------------------------------------------------------------
	// RLM4 — edit cascading, roadmap §4 Q4
	// -------------------------------------------------------------------------

	public function test_editing_the_canonical_reply_pushes_fresh_translated_content_to_every_mirror(): void {
		$artist_id = $this->create_artist( 'de_DE' );
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' );

		$primary_sibling_id = $this->create_artwork( $artist_id );
		$artist_sibling_id  = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $primary_sibling_id );
		FakeLinguaForge::link( $post_id, 'de', $artist_sibling_id );

		$comment_id = $this->create_canonical_reply( $post_id, 'zh' );
		update_comment_meta( $comment_id, '_agnosis_reply_translated_primary', 'Hello (old)!' );
		update_comment_meta( $comment_id, '_agnosis_reply_translated_content', 'Hallo (old)!' );
		$this->approve( $comment_id );

		$this->assertSame( 'Hello (old)!', $this->comments_on( $primary_sibling_id )[0]->comment_content );
		$this->assertSame( 'Hallo (old)!', $this->comments_on( $artist_sibling_id )[0]->comment_content );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'content' => 'Refreshed translation' ] );

		wp_update_comment( [ 'comment_ID' => $comment_id, 'comment_content' => 'Ni hao again, edited!' ] );
		( new ActivityPub() )->handle_reply_content_edit( $comment_id );

		$this->assertSame( 'Ni hao again, edited!', get_comment( $comment_id )->comment_content );
		$this->assertSame( 'Refreshed translation', get_comment_meta( $comment_id, '_agnosis_reply_translated_primary', true ) );
		$this->assertSame( 'Refreshed translation', get_comment_meta( $comment_id, '_agnosis_reply_translated_content', true ) );

		$this->assertSame( 'Refreshed translation', $this->comments_on( $primary_sibling_id )[0]->comment_content, "The primary sibling's mirror must be refreshed with the new translation." );
		$this->assertSame( 'Refreshed translation', $this->comments_on( $artist_sibling_id )[0]->comment_content, "The artist sibling's mirror must be refreshed with the new translation." );
	}

	public function test_editing_an_individual_mirror_does_not_cascade_anywhere(): void {
		$artist_id = $this->create_artist( 'de_DE' );
		$post_id   = $this->create_artwork( $artist_id );
		update_post_meta( $post_id, '_lf_lang', 'zh' );

		$primary_sibling_id = $this->create_artwork( $artist_id );
		$artist_sibling_id  = $this->create_artwork( $artist_id );
		FakeLinguaForge::link( $post_id, 'en', $primary_sibling_id );
		FakeLinguaForge::link( $post_id, 'de', $artist_sibling_id );

		$comment_id = $this->create_canonical_reply( $post_id, 'zh' );
		update_comment_meta( $comment_id, '_agnosis_reply_translated_primary', 'Hello!' );
		update_comment_meta( $comment_id, '_agnosis_reply_translated_content', 'Hallo!' );
		$this->approve( $comment_id );

		$primary_mirror_id = (int) $this->comments_on( $primary_sibling_id )[0]->comment_ID;

		wp_update_comment( [ 'comment_ID' => $primary_mirror_id, 'comment_content' => 'Edited directly on the mirror.' ] );
		( new ActivityPub() )->handle_reply_content_edit( $primary_mirror_id );

		$this->assertSame( 'Edited directly on the mirror.', get_comment( $primary_mirror_id )->comment_content );
		$this->assertSame( 'Original text.', get_comment( $comment_id )->comment_content, 'Editing a mirror must never cascade back to the canonical reply.' );
		$this->assertSame( 'Hallo!', $this->comments_on( $artist_sibling_id )[0]->comment_content, 'Editing one mirror must never cascade sideways to another mirror.' );
	}
}
