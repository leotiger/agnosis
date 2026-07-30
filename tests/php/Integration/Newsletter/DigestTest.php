<?php
/**
 * Integration tests — newsletter auto-digest content builder.
 *
 * Covers the "since" cutoff (only content published after the last issue is
 * included), the empty-state message, and that the artist digest surfaces
 * activity counts and new members.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Artist\NotificationPreferences;
use Agnosis\Network\FederationSettlement;
use Agnosis\Newsletter\Digest;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;

class DigestTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeLinguaForge::reset();
	}

	protected function tearDown(): void {
		FakeLinguaForge::reset();
		parent::tearDown();
	}

	private function make_artwork( string $title, string $post_date ): int {
		return (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_date'   => $post_date,
			'post_author' => self::factory()->user->create(),
		] );
	}

	public function test_public_digest_is_empty_message_when_nothing_new(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$html = Digest::build_public( $since );

		$this->assertStringContainsString( 'Nothing new to report', $html );
	}

	public function test_public_digest_includes_artwork_published_after_since(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->make_artwork( 'Fresh Piece', gmdate( 'Y-m-d H:i:s' ) );

		$html = Digest::build_public( $since );

		$this->assertStringContainsString( 'Fresh Piece', $html );
		$this->assertStringContainsString( 'New artwork', $html );
	}

	public function test_public_digest_excludes_artwork_published_before_since(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->make_artwork( 'Old Piece', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );

		$html = Digest::build_public( $since );

		$this->assertStringNotContainsString( 'Old Piece', $html );
		$this->assertStringContainsString( 'Nothing new to report', $html );
	}

	public function test_artist_digest_reports_artwork_count(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->make_artwork( 'Community Piece One', gmdate( 'Y-m-d H:i:s' ) );
		$this->make_artwork( 'Community Piece Two', gmdate( 'Y-m-d H:i:s' ) );

		$html = Digest::build_artist( $since );

		$this->assertStringContainsString( '2 new artworks published', $html );
	}

	// =========================================================================
	// Interaction-surface roadmap, Phase 3, WP3 — like-link placeholder
	// =========================================================================

	public function test_federated_artwork_gets_a_like_placeholder_in_the_artist_digest(): void {
		$since   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$post_id = $this->make_artwork( 'Federated Piece', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, FederationSettlement::STATE_META, FederationSettlement::STATE_FEDERATED );

		$html = Digest::build_artist( $since );

		$this->assertStringContainsString( '{{AGNOSIS_LIKE:' . $post_id . '}}', $html );
	}

	public function test_federated_artwork_gets_a_like_placeholder_in_the_public_digest_too(): void {
		$since   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$post_id = $this->make_artwork( 'Federated Piece Public', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, FederationSettlement::STATE_META, FederationSettlement::STATE_FEDERATED );

		$html = Digest::build_public( $since );

		$this->assertStringContainsString( '{{AGNOSIS_LIKE:' . $post_id . '}}', $html );
	}

	public function test_not_yet_federated_artwork_gets_no_like_placeholder(): void {
		$since   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$post_id = $this->make_artwork( 'Pending Piece', gmdate( 'Y-m-d H:i:s' ) );
		// No STATE_META at all — the common "just published, still pending
		// tag/medium settlement" case (FederationSettlement's own STATE_PENDING).

		$html = Digest::build_artist( $since );

		$this->assertStringNotContainsString( '{{AGNOSIS_LIKE:', $html );
	}

	// =========================================================================
	// Interaction-surface roadmap, Phase 3, WP5 — boost-link placeholder
	// =========================================================================

	public function test_federated_artwork_gets_a_boost_placeholder_in_the_artist_digest(): void {
		$since   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$post_id = $this->make_artwork( 'Federated Boostable Piece', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, FederationSettlement::STATE_META, FederationSettlement::STATE_FEDERATED );

		$html = Digest::build_artist( $since );

		$this->assertStringContainsString( '{{AGNOSIS_BOOST:' . $post_id . '}}', $html );
	}

	public function test_federated_artwork_never_gets_a_boost_placeholder_in_the_public_digest(): void {
		// §4 Phase 3G step 1's audience rule: the public newsletter's
		// recipients have no actor, so a boost link would be a dead
		// affordance — only the artist digest ever offers one.
		$since   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$post_id = $this->make_artwork( 'Federated Piece No Boost', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, FederationSettlement::STATE_META, FederationSettlement::STATE_FEDERATED );

		$html = Digest::build_public( $since );

		$this->assertStringContainsString( '{{AGNOSIS_LIKE:' . $post_id . '}}', $html, 'The public digest still gets a like placeholder — only boost is withheld.' );
		$this->assertStringNotContainsString( '{{AGNOSIS_BOOST:', $html );
	}

	public function test_not_yet_federated_artwork_gets_no_boost_placeholder(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->make_artwork( 'Pending Boost Piece', gmdate( 'Y-m-d H:i:s' ) );

		$html = Digest::build_artist( $since );

		$this->assertStringNotContainsString( '{{AGNOSIS_BOOST:', $html );
	}

	public function test_events_never_get_a_like_placeholder_even_if_federated(): void {
		// build_artist() never lists individual events at all — only a bare
		// count ("N new events announced", see Digest::build_artist()'s own
		// <ul> summary). Only build_public() actually calls render_post_list()
		// for events (with $show_date = true), so that's the one that can
		// prove render_post_list()'s "! $show_date" guard is what's actually
		// keeping the placeholder off events, not just that events never
		// render at all.
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// @phpstan-ignore-next-line -- factory()->user->create() returns int|WP_Error; a bare event fixture never fails in practice (see feedback_phpstan_baseline_test_gotchas Rule 4).
		$event_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_title'  => 'Federated Event',
			'post_date'   => gmdate( 'Y-m-d H:i:s' ),
			'post_author' => self::factory()->user->create(),
		] );
		update_post_meta( $event_id, FederationSettlement::STATE_META, FederationSettlement::STATE_FEDERATED );

		$html = Digest::build_public( $since );

		$this->assertStringContainsString( 'Federated Event', $html );
		$this->assertStringNotContainsString( '{{AGNOSIS_LIKE:', $html );
	}

	// =========================================================================
	// Per-locale rendering
	// =========================================================================

	/**
	 * Link two posts as Lingua Forge translations of each other: real _lf_lang
	 * meta (recent_posts() reads this directly to scope its own WP_Query) plus
	 * a FakeLinguaForge registry entry (stands in for linguaforge_get_translations(),
	 * since the real Lingua Forge plugin isn't loaded in this test environment —
	 * see tests/php/Integration/Support/FakeLinguaForge.php for why).
	 */
	private function link_as_translations( int $original_id, string $original_lang, int $translated_id, string $translated_lang ): void {
		update_post_meta( $original_id, '_lf_lang', $original_lang );
		update_post_meta( $translated_id, '_lf_lang', $translated_lang );
		FakeLinguaForge::link( $original_id, $translated_lang, $translated_id );
	}

	public function test_recent_posts_excludes_translated_duplicate_from_the_default_render(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$original_id   = $this->make_artwork( 'Dedup Original', gmdate( 'Y-m-d H:i:s' ) );
		$translated_id = $this->make_artwork( 'Dedup Translated', gmdate( 'Y-m-d H:i:s' ) );
		$this->link_as_translations( $original_id, 'en', $translated_id, 'es' );

		$html = Digest::build_public( $since );

		$this->assertStringContainsString( 'Dedup Original', $html, 'The primary-language post must still appear.' );
		$this->assertStringNotContainsString( 'Dedup Translated', $html, 'A translated duplicate must not be listed separately.' );
		$this->assertSame( 1, substr_count( $html, '<table' ), 'The artwork must be listed exactly once, not once per language.' );
	}

	public function test_public_digest_uses_translated_title_for_matching_recipient_language(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$original_id   = $this->make_artwork( 'Original Title', gmdate( 'Y-m-d H:i:s' ) );
		$translated_id = $this->make_artwork( 'Título Traducido', gmdate( 'Y-m-d H:i:s' ) );
		$this->link_as_translations( $original_id, 'en', $translated_id, 'es' );

		$html = Digest::build_public( $since, 'es' );

		$this->assertStringContainsString( 'Título Traducido', $html );
		$this->assertStringNotContainsString( 'Original Title', $html );
	}

	public function test_public_digest_falls_back_to_original_when_no_translation_exists(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->make_artwork( 'Untranslated Piece', gmdate( 'Y-m-d H:i:s' ) );

		$html = Digest::build_public( $since, 'es' );

		$this->assertStringContainsString( 'Untranslated Piece', $html, 'With no translation available, the primary-language post must still be shown rather than nothing.' );
	}

	public function test_public_digest_with_source_lf_lang_uses_original_directly(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$original_id   = $this->make_artwork( 'Same Language Piece', gmdate( 'Y-m-d H:i:s' ) );
		$translated_id = $this->make_artwork( 'Should Not Appear', gmdate( 'Y-m-d H:i:s' ) );
		$this->link_as_translations( $original_id, 'en', $translated_id, 'es' );

		// Requesting the site's own primary language must not trigger a translation lookup.
		$html = Digest::build_public( $since, 'en' );

		$this->assertStringContainsString( 'Same Language Piece', $html );
		$this->assertStringNotContainsString( 'Should Not Appear', $html );
	}

	public function test_artist_digest_lists_newly_admitted_members(): void {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => 'newmember@example.com',
				'display_name' => 'Nova Artist',
				'status'       => 'admitted',
				'resolved_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		$html = Digest::build_artist( $since );

		$this->assertStringContainsString( 'Nova Artist', $html );
	}

	// =========================================================================
	// build_intro_context() — structured summary for the AI intro drafter
	// =========================================================================

	public function test_intro_context_includes_artwork_title_excerpt_tags_and_medium(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$id = $this->make_artwork( 'Context Piece', gmdate( 'Y-m-d H:i:s' ) );
		wp_update_post( [ 'ID' => $id, 'post_excerpt' => 'A striking new work.' ] );
		wp_set_post_tags( $id, [ 'blue', 'abstract' ] );
		wp_set_object_terms( $id, 'Oil Painting', 'agnosis_medium' );

		$context = Digest::build_intro_context( 'public', $since );

		$this->assertCount( 1, $context['artworks'] );
		$item = $context['artworks'][0];
		$this->assertSame( 'Context Piece', $item['title'] );
		$this->assertSame( 'A striking new work.', $item['excerpt'] );
		$this->assertContains( 'blue', $item['tags'] );
		$this->assertContains( 'abstract', $item['tags'] );
		$this->assertContains( 'Oil Painting', $item['medium'] );
	}

	public function test_intro_context_events_have_no_medium_key_value(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		wp_insert_post( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_title'  => 'Context Event',
			'post_date'   => gmdate( 'Y-m-d H:i:s' ),
			'post_author' => self::factory()->user->create(),
		] );

		$context = Digest::build_intro_context( 'public', $since );

		$this->assertCount( 1, $context['events'] );
		$this->assertSame( [], $context['events'][0]['medium'] ?? [], 'Events carry no agnosis_medium terms — the key must be absent or empty, never populated from an unrelated artwork.' );
	}

	public function test_intro_context_public_type_omits_members_and_votes(): void {
		$since   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$context = Digest::build_intro_context( 'public', $since );

		$this->assertArrayNotHasKey( 'new_members', $context );
		$this->assertArrayNotHasKey( 'open_votes', $context );
	}

	public function test_intro_context_artist_type_includes_new_members(): void {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => 'context-member@example.com',
				'display_name' => 'Context Artist',
				'status'       => 'admitted',
				'resolved_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		$context = Digest::build_intro_context( 'artist', $since );

		$this->assertContains( 'Context Artist', $context['new_members'] );
		$this->assertSame( 0, $context['open_votes'] );
	}

	public function test_intro_context_empty_when_nothing_new(): void {
		$since   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$context = Digest::build_intro_context( 'public', $since );

		$this->assertSame( [], $context['artworks'] );
		$this->assertSame( [], $context['events'] );
	}

	// =========================================================================
	// NL1 (RHIZOME-NETWORK-ROADMAP.md §11a, 2026-07-30) — personal
	// interaction-summary placeholder + its per-recipient substitution.
	// =========================================================================

	private function create_artist(): int {
		// @phpstan-ignore-next-line -- factory()->user->create() returns int|WP_Error; a bare artist fixture never fails in practice (see feedback_phpstan_baseline_test_gotchas Rule 4).
		return (int) self::factory()->user->create( [ 'role' => 'agnosis_artist' ] );
	}

	private function make_artwork_for( int $author_id, string $title ): int {
		return (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_author' => $author_id,
		] );
	}

	private function seed_interaction( int $post_id, string $activity_type, string $received_at, string $actor_id = '' ): void {
		global $wpdb;
		static $n = 0;
		++$n;
		$wpdb->insert( $wpdb->prefix . 'agnosis_interactions', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
			'post_id'       => $post_id,
			'activity_type' => $activity_type,
			'actor_id'      => '' !== $actor_id ? $actor_id : "https://fediverse.example/actor-{$n}",
			'received_at'   => $received_at,
		] );
	}

	/** NL2 (§11b) — seeds a row directly onto RN3b's own log table. */
	private function seed_relay_log( int $peer_node_id, string $relayed_at ): void {
		global $wpdb;
		static $n = 0;
		++$n;
		$wpdb->insert( $wpdb->prefix . 'agnosis_rhizome_relay_log', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
			'peer_node_id'        => $peer_node_id,
			'peer_url'            => "https://partner-{$peer_node_id}.example",
			'announcing_actor_id' => "https://partner-{$peer_node_id}.example/actor",
			'object_url'          => "https://partner-{$peer_node_id}.example/artwork/{$n}",
			'relay_activity_id'   => "https://relay.example/activity-{$n}",
			'relayed_at'          => $relayed_at,
		] );
	}

	public function test_artist_digest_always_carries_the_interaction_summary_placeholder(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$html = Digest::build_artist( $since );

		$this->assertStringContainsString( Digest::INTERACTION_SUMMARY_PLACEHOLDER, $html );
	}

	public function test_public_digest_never_carries_the_interaction_summary_placeholder(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$html = Digest::build_public( $since );

		$this->assertStringNotContainsString( Digest::INTERACTION_SUMMARY_PLACEHOLDER, $html );
	}

	public function test_substitute_interaction_summary_renders_both_bullets_when_both_counts_are_nonzero(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->make_artwork_for( $artist_id, 'Liked And Boosted Piece' );
		$since     = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$this->seed_interaction( $post_id, 'like', current_time( 'mysql' ) );
		$this->seed_interaction( $post_id, 'like', current_time( 'mysql' ) );
		$this->seed_interaction( $post_id, 'announce', current_time( 'mysql' ) );

		$html = Digest::substitute_interaction_summary( Digest::INTERACTION_SUMMARY_PLACEHOLDER, $artist_id, $since );

		$this->assertStringContainsString( '2 likes on your work since your last digest.', $html );
		$this->assertStringContainsString( '1 boost on your work since your last digest.', $html );
		$this->assertStringNotContainsString( Digest::INTERACTION_SUMMARY_PLACEHOLDER, $html );
	}

	public function test_substitute_interaction_summary_renders_only_the_likes_bullet_when_no_boosts(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->make_artwork_for( $artist_id, 'Liked Only Piece' );
		$since     = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$this->seed_interaction( $post_id, 'like', current_time( 'mysql' ) );

		$html = Digest::substitute_interaction_summary( Digest::INTERACTION_SUMMARY_PLACEHOLDER, $artist_id, $since );

		$this->assertStringContainsString( '1 like on your work since your last digest.', $html );
		$this->assertStringNotContainsString( 'boost', $html );
	}

	public function test_substitute_interaction_summary_excludes_interactions_before_the_since_window(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->make_artwork_for( $artist_id, 'Old Interaction Piece' );
		$since     = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$this->seed_interaction( $post_id, 'like', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );

		$html = Digest::substitute_interaction_summary( Digest::INTERACTION_SUMMARY_PLACEHOLDER, $artist_id, $since );

		$this->assertSame( '', $html, 'An interaction older than the digest window must not be counted, leaving the placeholder blank.' );
	}

	/**
	 * §13 F4 (2026-07-30). QueueProcessor::send_one() coalesces a NULL
	 * digest_since to '' — reachable for an issue row created before NL1
	 * added that column and still mid-send. Passing '' straight through to
	 * `i.received_at > %s` makes MySQL coerce it to a zero-date, so every
	 * interaction the artist has ever received matches and a "since your
	 * last digest" line silently reports an all-time total.
	 */
	public function test_substitute_interaction_summary_is_blank_when_the_digest_window_is_unknown(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->make_artwork_for( $artist_id, 'Ancient History Piece' );

		$this->seed_interaction( $post_id, 'like', gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) );
		$this->seed_interaction( $post_id, 'announce', current_time( 'mysql' ) );

		$html = Digest::substitute_interaction_summary( Digest::INTERACTION_SUMMARY_PLACEHOLDER, $artist_id, '' );

		$this->assertSame( '', $html, 'With no known window, the section must be omitted entirely rather than reporting an all-time total under a "since your last digest" heading.' );
	}

	public function test_substitute_interaction_summary_is_blank_when_the_artist_has_zero_interactions(): void {
		$artist_id = $this->create_artist();
		$since     = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$html = Digest::substitute_interaction_summary( Digest::INTERACTION_SUMMARY_PLACEHOLDER, $artist_id, $since );

		$this->assertSame( '', $html );
	}

	public function test_substitute_interaction_summary_is_blank_for_a_public_recipient(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$html = Digest::substitute_interaction_summary( Digest::INTERACTION_SUMMARY_PLACEHOLDER, 0, $since );

		$this->assertSame( '', $html, 'recipient_artist_id = 0 (a public-newsletter recipient) must never trigger a DB lookup or render a bullet.' );
	}

	public function test_substitute_interaction_summary_is_blank_when_the_artist_opted_out(): void {
		$artist_id = $this->create_artist();
		$post_id   = $this->make_artwork_for( $artist_id, 'Opted Out Piece' );
		$since     = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$this->seed_interaction( $post_id, 'like', current_time( 'mysql' ) );
		NotificationPreferences::set_interaction_summary_optout( $artist_id, true );

		$html = Digest::substitute_interaction_summary( Digest::INTERACTION_SUMMARY_PLACEHOLDER, $artist_id, $since );

		$this->assertSame( '', $html, 'An opted-out artist must see no bullet at all, even with real, in-window interactions.' );
	}

	public function test_substitute_interaction_summary_only_counts_the_recipients_own_artwork(): void {
		$artist_id  = $this->create_artist();
		$other_id   = $this->create_artist();
		$other_post = $this->make_artwork_for( $other_id, "Someone Else's Piece" );
		$since      = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$this->seed_interaction( $other_post, 'like', current_time( 'mysql' ) );

		$html = Digest::substitute_interaction_summary( Digest::INTERACTION_SUMMARY_PLACEHOLDER, $artist_id, $since );

		$this->assertSame( '', $html, 'A like on a different artist\'s own artwork must never be attributed to this recipient.' );
	}

	// =========================================================================
	// NL2 (RHIZOME-NETWORK-ROADMAP.md §11b, 2026-07-30) — rhizome-community
	// activity bullets in the artist digest.
	// =========================================================================

	public function test_build_artist_includes_rhizome_activity_bullets_when_relays_exist(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->seed_relay_log( 101, current_time( 'mysql' ) );
		$this->seed_relay_log( 101, current_time( 'mysql' ) );
		$this->seed_relay_log( 202, current_time( 'mysql' ) );

		$html = Digest::build_artist( $since );

		$this->assertStringContainsString( '3 pieces relayed across the rhizome.', $html );
		$this->assertStringContainsString( 'From 2 trusted partner nodes.', $html, 'Two relays from the same partner (101) must count as one partner, not two.' );
	}

	public function test_build_artist_uses_singular_form_for_exactly_one_relay_and_one_partner(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->seed_relay_log( 303, current_time( 'mysql' ) );

		$html = Digest::build_artist( $since );

		$this->assertStringContainsString( '1 piece relayed across the rhizome.', $html );
		$this->assertStringContainsString( 'From 1 trusted partner node.', $html );
	}

	public function test_build_artist_omits_rhizome_activity_bullets_when_nothing_relayed(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$html = Digest::build_artist( $since );

		$this->assertStringNotContainsString( 'relayed across the rhizome', $html );
		$this->assertStringNotContainsString( 'trusted partner node', $html );
	}

	public function test_build_artist_excludes_relays_before_the_since_window(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->seed_relay_log( 404, gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );

		$html = Digest::build_artist( $since );

		$this->assertStringNotContainsString( 'relayed across the rhizome', $html, 'A relay logged before the digest window must not be counted.' );
	}

	public function test_public_digest_never_includes_rhizome_activity_bullets(): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->seed_relay_log( 505, current_time( 'mysql' ) );

		$html = Digest::build_public( $since );

		$this->assertStringNotContainsString( 'relayed across the rhizome', $html, 'NL2 is community-facing but artist-digest-only — build_public() must never surface it.' );
	}
}
