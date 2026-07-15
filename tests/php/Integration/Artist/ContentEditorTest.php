<?php
/**
 * Integration tests — front-end correction for artists (ContentEditor, §7).
 *
 * Phase 1: covers the permission boundaries from §7c (admitted-artist gate,
 * author match, own-language gate, admin bypass, rate limit), the field
 * allowlist per CPT, the bio -> _agnosis_artist_prompt sync, revision creation
 * (now that the three CPTs support 'revisions'), and the translation-coherence
 * propagation reassessed in the third audit on 2026-07-06: same-language edits
 * schedule a fan-out excluding the source language; non-primary-language edits
 * leave the primary post untouched (and log a warning) when no AI provider is
 * configured, rather than silently failing or corrupting content.
 *
 * Phase 2: photo substitution — original-preservation provenance meta, gallery/
 * featured-image swap, and synchronous (no-AI) propagation to every language
 * version of the post.
 *
 * Phase 3: title editing's dual-title handling — the literal title is copied
 * verbatim to every sibling (never translated), while the separate AI-translated
 * display title is regenerated only when an AI provider is configured; and the
 * "restore original photo" one-click, which reverses (never deletes) the
 * provenance pointer Phase 2 records.
 *
 * Audit §3f: the artist-facing half of the `sensitive` lever —
 * POST .../sensitive sets/clears `_agnosis_sensitive`, artwork-only (400 on
 * biography/event), ownership-checked the same as every other field here,
 * and — being boolean and language-neutral — propagated synchronously to
 * every language sibling with no translation leg, the same shape as a photo
 * swap (Phase 2). ActivityPub::post_to_note() is what actually reads it.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Tests\Integration\Support\FakeLinguaForge;
use WP_UnitTestCase;

class ContentEditorTest extends WP_UnitTestCase {

	private int $artist_id;
	private int $other_artist_id;
	private int $admin_id;
	private int $subscriber_id;

	public function setUp(): void {
		parent::setUp();
		FakeLinguaForge::reset();

		// Compat\LinguaForge::is_active() gates both the own-language check and
		// the translation-propagation call in ContentEditor — these constants are
		// what it checks for. Not defined by default in this suite (the real LF
		// plugin is deliberately not loaded — see FakeLinguaForge's docblock);
		// other Compat test files define the same guarded constants for the same
		// reason, so cross-file persistence within one PHPUnit process is already
		// an accepted pattern here.
		if ( ! defined( 'LINGUAFORGE_VERSION' ) ) {
			define( 'LINGUAFORGE_VERSION', '1.0.0-test' );
		}
		if ( ! defined( 'LINGUAFORGE_FILE' ) ) {
			define( 'LINGUAFORGE_FILE', '/tmp/linguaforge-content-editor-test.php' );
		}

		$this->artist_id       = $this->create_artist( 'en_US' );
		$this->other_artist_id = $this->create_artist( 'en_US' );
		$this->admin_id        = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		FakeLinguaForge::reset();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_artist( string $locale = '' ): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber', 'locale' => $locale ] );
		$user = get_user_by( 'id', $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private function create_artwork( int $author_id, string $status = 'publish', array $meta = [] ): int {
		$post_id = self::factory()->post->create( [
			'post_type'    => 'agnosis_artwork',
			'post_author'  => $author_id,
			'post_status'  => $status,
			'post_content' => 'Original content.',
			'post_excerpt' => 'Original excerpt.',
		] );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		return $post_id;
	}

	private function rest_post( string $route, array $params, ?int $user_id ): \WP_REST_Response|\WP_Error {
		wp_set_current_user( $user_id ?? 0 );
		$request = new \WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function edit( int $post_id, string $field, string $value, ?int $user_id ): \WP_REST_Response {
		$result = $this->rest_post( "/agnosis/v1/content/{$post_id}/text", [
			'field' => $field,
			'value' => $value,
		], $user_id );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		return $result;
	}

	// -------------------------------------------------------------------------
	// Authentication / authorization boundaries (§7c)
	// -------------------------------------------------------------------------

	public function test_unauthenticated_request_rejected(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->edit( $post_id, 'content', 'Hacked.', null );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_non_admitted_artist_rejected(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->edit( $post_id, 'content', 'Nope.', $this->subscriber_id );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_wrong_author_rejected(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->edit( $post_id, 'content', 'Not yours.', $this->other_artist_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'Original content.', get_post( $post_id )->post_content );
	}

	public function test_admin_can_edit_any_artists_post(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->edit( $post_id, 'content', 'Admin fixed this.', $this->admin_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Admin fixed this.', get_post( $post_id )->post_content );
	}

	public function test_nonexistent_post_returns_404(): void {
		$response = $this->edit( 999999, 'content', 'Ghost.', $this->artist_id );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_ineligible_post_type_returns_404(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'post',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$response = $this->edit( $post_id, 'content', 'Nope.', $this->artist_id );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_unpublished_post_rejected(): void {
		$post_id = $this->create_artwork( $this->artist_id, 'draft' );

		$response = $this->edit( $post_id, 'content', 'Too early.', $this->artist_id );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_invalid_field_rejected(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		// 'featured_status' is not, and never will be, an artist-editable field.
		$response = $this->edit( $post_id, 'featured_status', 'yes', $this->artist_id );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_invalid_event_date_rejected(): void {
		$event_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_event',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$response = $this->edit( $event_id, 'event_date', 'not a date', $this->artist_id );

		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Own-language gate (§7c, reassessed 2026-07-06)
	// -------------------------------------------------------------------------

	public function test_wrong_language_post_rejected(): void {
		FakeLinguaForge::$source_language = 'en';
		$this->set_artist_locale( $this->artist_id, 'es_ES' );

		$post_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'en' ] );

		// The artist's own language is Spanish; this post is the English (primary)
		// version. They may not correct it directly — only their own-language copy.
		$response = $this->edit( $post_id, 'content', 'Not my language.', $this->artist_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'Original content.', get_post( $post_id )->post_content );
	}

	public function test_own_language_post_accepted(): void {
		FakeLinguaForge::$source_language = 'en';
		$this->set_artist_locale( $this->artist_id, 'es_ES' );

		$post_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'es' ] );

		$response = $this->edit( $post_id, 'content', 'Mi propio idioma.', $this->artist_id );

		$this->assertSame( 200, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Persistence, revisions, bio prompt sync
	// -------------------------------------------------------------------------

	public function test_content_edit_persists_and_creates_revision(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$this->edit( $post_id, 'content', 'Fixed the typo.', $this->artist_id );

		$post = get_post( $post_id );
		$this->assertStringContainsString( 'Fixed the typo.', $post->post_content );

		$revisions = wp_get_post_revisions( $post_id );
		$this->assertNotEmpty( $revisions, 'Expected a revision to be created — CPT must support "revisions".' );
	}

	public function test_excerpt_edit_persists(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->edit( $post_id, 'excerpt', 'Better excerpt.', $this->artist_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Better excerpt.', get_post( $post_id )->post_excerpt );
	}

	public function test_bio_edit_syncs_artist_prompt(): void {
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$this->edit( $bio_id, 'content', 'I just won the Premio Nacional.', $this->artist_id );

		$this->assertSame( 'I just won the Premio Nacional.', get_post_meta( $bio_id, '_agnosis_artist_prompt', true ) );
	}

	public function test_event_location_and_date_editable(): void {
		$event_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_event',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$this->edit( $event_id, 'event_location', 'Gallery 44, Madrid', $this->artist_id );
		$this->edit( $event_id, 'event_date', '2026-09-01T19:00:00', $this->artist_id );

		$this->assertSame( 'Gallery 44, Madrid', get_post_meta( $event_id, '_agnosis_event_location', true ) );
		$this->assertSame( '2026-09-01T19:00:00', get_post_meta( $event_id, '_agnosis_event_date', true ) );
	}

	// -------------------------------------------------------------------------
	// Biography social links (2026-07-13) — set once at approval by
	// Publishing\ReviewConfirm, correctable afterward here. No on-page
	// click-to-edit affordance yet (see EDITABLE_FIELDS's own comment) — these
	// tests exercise the REST capability directly, same as this file already
	// does for content/excerpt/event fields.
	// -------------------------------------------------------------------------

	public function test_biography_social_link_editable(): void {
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$response = $this->edit( $bio_id, 'social_url_1', 'https://instagram.com/artist', $this->artist_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'https://instagram.com/artist', get_post_meta( $bio_id, '_agnosis_biography_social_url_1', true ) );
	}

	public function test_biography_all_three_social_links_independently_editable(): void {
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$this->edit( $bio_id, 'social_url_1', 'https://instagram.com/artist', $this->artist_id );
		$this->edit( $bio_id, 'social_url_2', 'https://bandcamp.com/artist', $this->artist_id );
		$this->edit( $bio_id, 'social_url_3', 'https://wa.me/1234567890', $this->artist_id );

		$this->assertSame( 'https://instagram.com/artist', get_post_meta( $bio_id, '_agnosis_biography_social_url_1', true ) );
		$this->assertSame( 'https://bandcamp.com/artist', get_post_meta( $bio_id, '_agnosis_biography_social_url_2', true ) );
		$this->assertSame( 'https://wa.me/1234567890', get_post_meta( $bio_id, '_agnosis_biography_social_url_3', true ) );
	}

	public function test_biography_social_link_can_be_cleared(): void {
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );
		update_post_meta( $bio_id, '_agnosis_biography_social_url_1', 'https://instagram.com/artist' );

		$response = $this->edit( $bio_id, 'social_url_1', '', $this->artist_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', get_post_meta( $bio_id, '_agnosis_biography_social_url_1', true ) );
	}

	public function test_biography_social_link_rejects_a_non_http_scheme(): void {
		// esc_url_raw() (sanitize_value()'s own sanitizer for this field)
		// allows several non-web schemes (ftp, mailto, tel, …) through
		// unchanged via wp_allowed_protocols() — a scheme like "ftp://"
		// survives sanitization as a non-empty value, so this is the branch
		// the http(s)-only validation actually has to catch. A value with no
		// recognizable scheme at all ("not a url") is handled differently —
		// see test_biography_social_link_garbage_input_is_sanitized_to_empty_below.
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$response = $this->edit( $bio_id, 'social_url_1', 'ftp://example.com/file', $this->artist_id );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '', get_post_meta( $bio_id, '_agnosis_biography_social_url_1', true ) );
	}

	public function test_biography_social_link_schemeless_text_is_treated_as_an_implied_http_url(): void {
		// WordPress's own clean_url() (esc_url_raw()'s underlying sanitizer)
		// prepends "http://" to any string that has no scheme (no ':') and
		// doesn't start with '/', '#', or '?' — a best-effort "the user meant
		// a web address" assumption baked into core, not something this
		// class controls. So free text like "not a url" doesn't sanitize
		// down to '' — it becomes an "http://"-prefixed, percent-encoded
		// value that DOES pass the http(s)-scheme check (200, not 400).
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$response = $this->edit( $bio_id, 'social_url_1', 'not a url', $this->artist_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( 'http://', get_post_meta( $bio_id, '_agnosis_biography_social_url_1', true ) );
	}

	public function test_biography_social_link_relative_path_is_rejected(): void {
		// A schemeless value that DOES start with '/' is exactly what
		// clean_url()'s "imply http://" heuristic deliberately leaves alone
		// (it looks like a relative path, not free text) — so this is the
		// genuinely reachable "no recognizable scheme" rejection case.
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$response = $this->edit( $bio_id, 'social_url_1', '/just/a/path', $this->artist_id );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '', get_post_meta( $bio_id, '_agnosis_biography_social_url_1', true ) );
	}

	public function test_biography_portfolio_url_is_not_content_editor_editable(): void {
		// Deliberate: portfolio_url has only ever been approval-form-editable
		// (Publishing\ReviewConfirm's EmbedPolicy-gated sync) — adding the
		// social links to EDITABLE_FIELDS must not have accidentally also
		// exposed this one, which has different (embed-vetting) semantics.
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$response = $this->edit( $bio_id, 'portfolio_url', 'https://example.com/portfolio', $this->artist_id );

		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Translation coherence (§7c, reassessed 2026-07-06)
	// -------------------------------------------------------------------------

	public function test_edit_on_primary_language_post_schedules_fanout_excluding_source(): void {
		FakeLinguaForge::$source_language = 'en';
		$this->set_artist_locale( $this->artist_id, 'en_US' );

		$post_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'en' ] );

		$this->edit( $post_id, 'content', 'Corrected in English.', $this->artist_id );

		$this->assertNotFalse(
			wp_next_scheduled( 'agnosis_dispatch_lf_translations', [ $post_id, [ 'en' ] ] ),
			'Expected the fan-out to be scheduled, excluding the artist\'s own source language.'
		);
	}

	public function test_non_primary_edit_without_ai_provider_leaves_primary_untouched(): void {
		FakeLinguaForge::$source_language = 'en';
		$this->set_artist_locale( $this->artist_id, 'es_ES' );

		// No agnosis_openai_api_key / agnosis_anthropic_api_key configured in this
		// test environment — SubmissionTranslator::from_settings() returns null,
		// exactly the same "no provider configured" fallback build_title_translations()
		// already relies on elsewhere.
		delete_option( 'agnosis_openai_api_key' );
		delete_option( 'agnosis_anthropic_api_key' );
		update_option( 'agnosis_ai_provider', 'openai' );

		$spanish_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'es' ] );
		$primary_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'en' ] );

		FakeLinguaForge::link( $spanish_id, 'en', $primary_id );
		FakeLinguaForge::link( $spanish_id, 'es', $spanish_id );

		$response = $this->edit( $spanish_id, 'content', 'Corregido en espanol.', $this->artist_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Corregido en espanol.', get_post( $spanish_id )->post_content );
		// Primary post is untouched — no provider configured to translate into it.
		$this->assertSame( 'Original content.', get_post( $primary_id )->post_content );
		$this->assertFalse(
			wp_next_scheduled( 'agnosis_dispatch_lf_translations', [ $primary_id, [ 'es' ] ] ),
			'No fan-out should be scheduled when the primary post could not be updated.'
		);
	}

	// -------------------------------------------------------------------------
	// Rate limiting (§7c safety rail: 30/hour/user)
	// -------------------------------------------------------------------------

	public function test_rate_limit_enforced_after_thirty_saves(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		for ( $i = 1; $i <= 30; $i++ ) {
			$response = $this->edit( $post_id, 'content', "Edit #{$i}.", $this->artist_id );
			$this->assertSame( 200, $response->get_status(), "Save #{$i} should have succeeded." );
		}

		$response = $this->edit( $post_id, 'content', 'One too many.', $this->artist_id );
		$this->assertSame( 429, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Photo substitution (Phase 2)
	// -------------------------------------------------------------------------

	public function test_photo_replace_requires_a_file(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->photo_replace( $post_id, null, $this->artist_id );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_photo_replace_rejects_disallowed_mime(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->photo_replace( $post_id, [
			'name'     => 'notes.txt',
			'type'     => 'text/plain',
			'tmp_name' => $this->write_temp_file( 'just some text' ),
			'error'    => UPLOAD_ERR_OK,
			'size'     => 15,
		], $this->artist_id );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_photo_replace_wrong_author_rejected(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->photo_replace( $post_id, $this->fake_gif_file(), $this->other_artist_id );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_photo_replace_persists_and_preserves_original(): void {
		$post_id = $this->create_artwork( $this->artist_id );
		$old_id  = $this->create_fake_attachment();

		update_post_meta( $post_id, '_agnosis_gallery_ids', [ $old_id ] );
		set_post_thumbnail( $post_id, $old_id );

		$response = $this->photo_replace( $post_id, $this->fake_gif_file(), $this->artist_id );

		$this->assertSame( 200, $response->get_status() );
		$data   = $response->get_data();
		$new_id = (int) $data['attachment_id'];

		$this->assertNotSame( $old_id, $new_id );
		$this->assertSame( $old_id, (int) get_post_meta( $new_id, '_agnosis_original_attachment_id', true ) );
		$this->assertSame( '1', get_post_meta( $old_id, '_agnosis_is_original', true ) );

		$gallery = array_map( 'intval', (array) get_post_meta( $post_id, '_agnosis_gallery_ids', true ) );
		$this->assertSame( [ $new_id ], $gallery );
		$this->assertSame( $new_id, (int) get_post_thumbnail_id( $post_id ) );

		// The old attachment is never deleted — still resolvable for a future
		// "restore original photo" action (Phase 3).
		$this->assertInstanceOf( \WP_Post::class, get_post( $old_id ) );
	}

	public function test_photo_replace_on_biography_sets_featured_image_directly(): void {
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$response = $this->photo_replace( $bio_id, $this->fake_gif_file(), $this->artist_id );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( (int) $data['attachment_id'], (int) get_post_thumbnail_id( $bio_id ) );
		// Biography has no gallery concept — nothing should be written there.
		$this->assertSame( '', get_post_meta( $bio_id, '_agnosis_gallery_ids', true ) );
	}

	public function test_photo_replace_propagates_to_translated_sibling(): void {
		FakeLinguaForge::$source_language = 'en';
		$this->set_artist_locale( $this->artist_id, 'es_ES' );

		$spanish_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'es' ] );
		$primary_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'en' ] );

		$old_id = $this->create_fake_attachment();
		foreach ( [ $spanish_id, $primary_id ] as $pid ) {
			update_post_meta( $pid, '_agnosis_gallery_ids', [ $old_id ] );
			set_post_thumbnail( $pid, $old_id );
		}

		FakeLinguaForge::link( $spanish_id, 'en', $primary_id );
		FakeLinguaForge::link( $spanish_id, 'es', $spanish_id );

		$response = $this->photo_replace( $spanish_id, $this->fake_gif_file(), $this->artist_id );
		$this->assertSame( 200, $response->get_status() );
		$new_id = (int) $response->get_data()['attachment_id'];

		// Both the artist's own post and the primary-language sibling should now
		// point at the new attachment — images are language-neutral, propagated
		// synchronously, no AI call involved.
		$this->assertSame( $new_id, (int) get_post_thumbnail_id( $spanish_id ) );
		$this->assertSame( $new_id, (int) get_post_thumbnail_id( $primary_id ) );
		$this->assertSame( [ $new_id ], array_map( 'intval', (array) get_post_meta( $primary_id, '_agnosis_gallery_ids', true ) ) );
	}

	// -------------------------------------------------------------------------
	// Title editing — dual-title handling (Phase 3)
	// -------------------------------------------------------------------------

	public function test_empty_title_rejected(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->edit( $post_id, 'title', '   ', $this->artist_id );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_title_edit_propagates_verbatim_to_siblings(): void {
		FakeLinguaForge::$source_language = 'en';
		$this->set_artist_locale( $this->artist_id, 'es_ES' );

		$spanish_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'es' ] );
		$primary_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'en' ] );

		FakeLinguaForge::link( $spanish_id, 'en', $primary_id );
		FakeLinguaForge::link( $spanish_id, 'es', $spanish_id );

		$response = $this->edit( $spanish_id, 'title', 'Jardín Secreto', $this->artist_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Jardín Secreto', get_post( $spanish_id )->post_title );
		// Dual-title: the artist's literal words are copied verbatim to every
		// sibling — never translated, unlike post_content/excerpt.
		$this->assertSame( 'Jardín Secreto', get_post( $primary_id )->post_title );
	}

	public function test_title_edit_without_ai_provider_leaves_translated_title_unchanged(): void {
		FakeLinguaForge::$source_language = 'en';
		$this->set_artist_locale( $this->artist_id, 'es_ES' );

		delete_option( 'agnosis_openai_api_key' );
		delete_option( 'agnosis_anthropic_api_key' );
		update_option( 'agnosis_ai_provider', 'openai' );

		$spanish_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'es' ] );
		$primary_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'en' ] );
		update_post_meta( $primary_id, '_agnosis_translated_title', 'The Secret Garden (old)' );

		FakeLinguaForge::link( $spanish_id, 'en', $primary_id );
		FakeLinguaForge::link( $spanish_id, 'es', $spanish_id );

		$this->edit( $spanish_id, 'title', 'Jardín Secreto', $this->artist_id );

		// The title itself still propagates (asserted above); only the
		// AI-generated display title is left alone when there's no provider to
		// regenerate it from correctly — never guessed at or blanked.
		$this->assertSame( 'Jardín Secreto', get_post( $primary_id )->post_title );
		$this->assertSame( 'The Secret Garden (old)', get_post_meta( $primary_id, '_agnosis_translated_title', true ) );
	}

	public function test_title_edit_on_primary_post_sets_translated_title_directly(): void {
		FakeLinguaForge::$source_language = 'en';
		$this->set_artist_locale( $this->artist_id, 'en_US' );

		$post_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'en' ] );

		$this->edit( $post_id, 'title', 'The Secret Garden', $this->artist_id );

		// Editing directly on the primary post needs no AI call — the new title
		// IS the primary-language display title.
		$this->assertSame( 'The Secret Garden', get_post_meta( $post_id, '_agnosis_translated_title', true ) );
	}

	public function test_biography_title_edit_persists(): void {
		$bio_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		// Biography has no dual-title system — a plain field like any other.
		$response = $this->edit( $bio_id, 'title', 'Jane Doe', $this->artist_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Jane Doe', get_post( $bio_id )->post_title );
	}

	// -------------------------------------------------------------------------
	// Restore original photo (Phase 3)
	// -------------------------------------------------------------------------

	public function test_restore_photo_reverses_provenance(): void {
		$post_id = $this->create_artwork( $this->artist_id );
		$old_id  = $this->create_fake_attachment();

		update_post_meta( $post_id, '_agnosis_gallery_ids', [ $old_id ] );
		set_post_thumbnail( $post_id, $old_id );

		$replace_response = $this->photo_replace( $post_id, $this->fake_gif_file(), $this->artist_id );
		$new_id           = (int) $replace_response->get_data()['attachment_id'];

		$restore_response = $this->rest_post( "/agnosis/v1/content/{$post_id}/photo/restore", [], $this->artist_id );

		$this->assertSame( 200, $restore_response->get_status() );
		$restored_id = (int) $restore_response->get_data()['attachment_id'];
		$this->assertSame( $old_id, $restored_id );
		$this->assertSame( $old_id, (int) get_post_thumbnail_id( $post_id ) );
		$this->assertSame( [ $old_id ], array_map( 'intval', (array) get_post_meta( $post_id, '_agnosis_gallery_ids', true ) ) );

		// Provenance is reversed, not deleted — a second restore call would undo
		// this one too, the same way a photo replacement is never destructive.
		$this->assertSame( $new_id, (int) get_post_meta( $old_id, '_agnosis_original_attachment_id', true ) );
		$this->assertSame( '1', get_post_meta( $new_id, '_agnosis_is_original', true ) );
		$this->assertSame( '', get_post_meta( $old_id, '_agnosis_is_original', true ) );
	}

	public function test_restore_photo_without_earlier_version_returns_404(): void {
		$post_id = $this->create_artwork( $this->artist_id );
		$only_id = $this->create_fake_attachment();
		update_post_meta( $post_id, '_agnosis_gallery_ids', [ $only_id ] );
		set_post_thumbnail( $post_id, $only_id );

		$response = $this->rest_post( "/agnosis/v1/content/{$post_id}/photo/restore", [], $this->artist_id );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_restore_photo_wrong_author_rejected(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->rest_post( "/agnosis/v1/content/{$post_id}/photo/restore", [], $this->other_artist_id );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Sensitive-content flag (audit §3f)
	// -------------------------------------------------------------------------

	public function test_sensitive_flag_can_be_set_and_cleared(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->rest_post( "/agnosis/v1/content/{$post_id}/sensitive", [ 'value' => true ], $this->artist_id );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', get_post_meta( $post_id, '_agnosis_sensitive', true ) );

		$response = $this->rest_post( "/agnosis/v1/content/{$post_id}/sensitive", [ 'value' => false ], $this->artist_id );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', get_post_meta( $post_id, '_agnosis_sensitive', true ), 'Clearing the flag must delete the meta row, not store a falsy string.' );
	}

	public function test_sensitive_flag_wrong_author_rejected(): void {
		$post_id = $this->create_artwork( $this->artist_id );

		$response = $this->rest_post( "/agnosis/v1/content/{$post_id}/sensitive", [ 'value' => true ], $this->other_artist_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', get_post_meta( $post_id, '_agnosis_sensitive', true ) );
	}

	public function test_sensitive_flag_rejected_on_non_artwork_post_types(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_author' => $this->artist_id,
			'post_status' => 'publish',
		] );

		$response = $this->rest_post( "/agnosis/v1/content/{$post_id}/sensitive", [ 'value' => true ], $this->artist_id );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_sensitive_flag_propagates_to_translated_sibling(): void {
		FakeLinguaForge::$source_language = 'en';
		$this->set_artist_locale( $this->artist_id, 'es_ES' );

		$spanish_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'es' ] );
		$primary_id = $this->create_artwork( $this->artist_id, 'publish', [ '_lf_lang' => 'en' ] );

		FakeLinguaForge::link( $spanish_id, 'en', $primary_id );
		FakeLinguaForge::link( $spanish_id, 'es', $spanish_id );

		$response = $this->rest_post( "/agnosis/v1/content/{$spanish_id}/sensitive", [ 'value' => true ], $this->artist_id );

		$this->assertSame( 200, $response->get_status() );
		// Boolean, language-neutral — no translation leg, same shape as a photo swap.
		$this->assertSame( '1', get_post_meta( $spanish_id, '_agnosis_sensitive', true ) );
		$this->assertSame( '1', get_post_meta( $primary_id, '_agnosis_sensitive', true ) );
	}

	// -------------------------------------------------------------------------
	// Test-local helpers
	// -------------------------------------------------------------------------

	private function set_artist_locale( int $user_id, string $locale ): void {
		wp_update_user( [ 'ID' => $user_id, 'locale' => $locale ] );
	}

	/** A real (but content-less) attachment post — enough for meta/thumbnail comparisons. */
	/**
	 * A real, fully-processed attachment (real file + generated metadata), not a
	 * bare `wp_insert_attachment()` stub. WP core's `set_post_thumbnail()` calls
	 * `wp_get_attachment_image()` internally and silently *deletes* `_thumbnail_id`
	 * instead of setting it when that returns empty — which it does for an
	 * attachment with no real underlying file/metadata. Every fixture that will
	 * ever be set as a thumbnail (including the "old" photo used in restore
	 * tests) has to go through the same real upload path as production code uses.
	 */
	private function create_fake_attachment(): int {
		$binary = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7' );
		$id     = ( new \Agnosis\Publishing\PostCreator() )->upload_media( $binary, 'image/gif', 'old-photo.gif', '', 'Old Photo', md5( $binary ) );

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/** Write a temp file with arbitrary bytes and return its path (caller doesn't need to clean up — PHP sweeps sys temp dirs). */
	private function write_temp_file( string $contents ): string {
		$path = wp_tempnam( 'agnosis-test' );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $path;
	}

	/**
	 * A minimal, byte-valid 1x1 transparent GIF — real MIME sniffing
	 * (wp_check_filetype_and_ext() inside wp_handle_sideload()) requires actual
	 * file content, not an arbitrary placeholder string (same reasoning as the
	 * WAV fixture in PostCreatorGalleryAudioSkipTest).
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
	 */
	private function fake_gif_file(): array {
		$binary = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7' );
		$path   = $this->write_temp_file( $binary );

		return [
			'name'     => 'new-photo.gif',
			'type'     => 'image/gif',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $binary ),
		];
	}

	/**
	 * @param array{name: string, type: string, tmp_name: string, error: int, size: int}|null $file
	 */
	private function photo_replace( int $post_id, ?array $file, ?int $user_id ): \WP_REST_Response {
		wp_set_current_user( $user_id ?? 0 );

		$request = new \WP_REST_Request( 'POST', "/agnosis/v1/content/{$post_id}/photo" );
		$request->set_param( 'id', $post_id );

		if ( null !== $file ) {
			$request->set_file_params( [ 'file' => $file ] );
		}

		$result = rest_do_request( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		return $result;
	}
}
