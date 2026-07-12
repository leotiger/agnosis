<?php
/**
 * Integration test — patch 18 "true staging": merging a new submission into
 * an already-published singleton (biography) must never touch the live post.
 *
 * Original bug this file guarded against (superseded — see below): merging
 * into an already-published post preserved 'publish' status while STILL
 * minting a fresh review token, but ReviewEndpoints::approve()/reject() both
 * hard-require 'draft' === $post->post_status before acting on a token —
 * a 409 otherwise. The artist's "Approve" link always landed on "Link
 * expired or already used", indistinguishable from a genuinely bad token.
 *
 * First fix attempt forced the merge target itself to 'draft' so the token
 * became redeemable — but that meant already-published content could sit
 * unpublished for as long as agnosis_review_token_expiry_days (default 7)
 * while the artist got around to approving. Rejected after assessment.
 *
 * Final ("true staging") design: a merge into an already-published post
 * never writes to that post at all. PostCreator::create_post() instead
 * inserts a separate draft post tagged '_agnosis_pending_update_for' with
 * the live post's ID, and only ReviewEndpoints::finalize_publish() (called
 * from approve()) copies the staging draft's fields onto the live post and
 * deletes the draft. Rejecting deletes the staging draft outright — the live
 * post is untouched either way, for as long as the update is pending.
 *
 * A merge into a target that is still a draft (e.g. an
 * Artist\ApplicationBiography-created bio the artist has never approved
 * yet) is unaffected by any of this — it's updated in place exactly as
 * before, since there is no "live" version to protect.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;
use Agnosis\Publishing\ReviewEndpoints;
use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;
use WP_REST_Request;

require_once __DIR__ . '/../AI/Stubs/WpAiClientTestRegistry.php';
require_once __DIR__ . '/../AI/Stubs/wp_ai_provider_namespace_stubs.php';

class PostCreatorMergeRedraftsPublishedTargetTest extends \WP_UnitTestCase {

	private int $artist_id;

	protected function setUp(): void {
		parent::setUp();

		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_user_by( 'id', $this->artist_id )->add_role( 'agnosis_artist' );

		update_option( 'agnosis_quality_rejection_threshold', 0 ); // disable gate noise for this suite.
		update_option( 'agnosis_email_bio', 'bio@agnosis.art' );
	}

	protected function tearDown(): void {
		parent::tearDown();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}agnosis_queue WHERE message_uid LIKE 'test-redraft-%'" );
		delete_option( 'agnosis_email_bio' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function insert_bio_queue_row( string $uid, bool $with_photo = true ): int {
		global $wpdb;
		$attachments = [];
		if ( $with_photo ) {
			$attachments[] = [
				'filename' => 'portrait.jpg',
				'mime'     => 'image/jpeg',
				'data'     => base64_encode( 'fake-image-data' ),
				'encoding' => 'base64',
			];
		}
		$submission = wp_json_encode( [
			'from'        => 'artist@example.com',
			'subject'     => 'About the artist',
			'description' => 'Updated biography text.',
			'attachments' => $attachments,
			'artist_id'  => $this->artist_id,
			'to_address' => 'bio@agnosis.art',
			'source'     => 'test',
		] );
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_queue',
			[
				'message_uid' => $uid,
				'artist_id'   => $this->artist_id,
				'raw_email'   => $submission,
				'status'      => 'pending',
			],
			[ '%s', '%d', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	/** Minimal Pipeline stub — no AI calls, one successful image-shaped result per attachment. */
	private function make_pipeline(): Pipeline {
		return new class() extends Pipeline {
			public function __construct() {
				// Skip parent constructor — no real provider needed.
			}

			/** @return array<string, mixed>[] */
			public function process( array $submission, bool $skip_enhancement = false ): array {
				$results = [];
				foreach ( $submission['attachments'] ?? [] as $att ) {
					$results[] = [
						'filename'             => $att['filename'] ?? 'portrait.jpg',
						'original_data'        => $att['data'] ?? '',
						'enhanced_data'        => $att['data'] ?? '',
						'mime_type'            => $att['mime'] ?? 'image/jpeg',
						'title'                => 'About the artist',
						'excerpt'              => '',
						'body'                 => '<p>Updated biography text.</p>',
						'tags'                 => [],
						'alt_text'             => 'Portrait.',
						'description_ok'       => true,
						'error'                => null,
						'photo_quality_score'  => 8,
						'photo_quality_issues' => [],
						'enhanced'             => false,
					];
				}
				return $results;
			}
		};
	}

	/**
	 * Simulates Artist\ApplicationBiography's auto-created draft after the
	 * artist has already approved it once: post_status = publish, and
	 * '_agnosis_review_token' absent — exactly what
	 * ReviewEndpoints::approve() leaves behind (it deletes the token on
	 * success). Optionally carries an existing photo, to test that staging
	 * doesn't lose it when an update omits a new one.
	 */
	private function create_published_biography_without_token( bool $with_existing_photo = false ): int {
		$id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
			'post_title'  => 'About the Artist',
		] );
		if ( $with_existing_photo ) {
			$existing_attachment_id = self::factory()->attachment->create( [ 'post_parent' => $id ] );
			update_post_meta( $id, '_agnosis_gallery_ids', [ $existing_attachment_id ] );
			return $id;
		}
		update_post_meta( $id, '_agnosis_gallery_ids', [] );
		return $id;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_biography_update_to_a_published_post_leaves_it_untouched_and_creates_a_staging_draft(): void {
		$live_id = $this->create_published_biography_without_token();

		$queue_id = $this->insert_bio_queue_row( 'test-redraft-1' );
		$creator  = new PostCreator( $this->make_pipeline() );
		$creator->handle( $queue_id );

		$live = get_post( $live_id );
		$this->assertNotNull( $live );
		$this->assertSame(
			'publish',
			$live->post_status,
			'A merge into an already-published biography must never take it offline — the whole point of true staging.'
		);
		$this->assertSame( 'About the Artist', $live->post_title, 'The live post title must be untouched while an update is only staged.' );

		$staging = get_posts( [
			'post_type'      => 'agnosis_biography',
			'author'         => $this->artist_id,
			'post_status'    => 'draft',
			'meta_key'       => '_agnosis_pending_update_for',
			'meta_value'     => (string) $live_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		$this->assertNotEmpty( $staging, 'A staging draft tagged with _agnosis_pending_update_for must exist.' );

		$staging_id = (int) $staging[0];
		$token      = (string) get_post_meta( $staging_id, '_agnosis_review_token', true );
		$this->assertNotSame( '', $token, 'The staging draft must carry its own redeemable review token.' );
		$this->assertTrue( true === ReviewEndpoints::verify_token( $staging_id, $token ) );
	}

	public function test_approving_a_staged_biography_update_applies_it_without_ever_unpublishing_the_live_post(): void {
		$live_id = $this->create_published_biography_without_token();

		$queue_id = $this->insert_bio_queue_row( 'test-redraft-2' );
		$creator  = new PostCreator( $this->make_pipeline() );
		$creator->handle( $queue_id );

		$staging = get_posts( [
			'post_type'      => 'agnosis_biography',
			'author'         => $this->artist_id,
			'post_status'    => 'draft',
			'meta_key'       => '_agnosis_pending_update_for',
			'meta_value'     => (string) $live_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		$this->assertNotEmpty( $staging );
		$staging_id = (int) $staging[0];
		$token      = (string) get_post_meta( $staging_id, '_agnosis_review_token', true );

		$published_fired = 0;
		add_action( 'agnosis_post_published', function () use ( &$published_fired ) {
			++$published_fired;
		} );

		// End-to-end: the exact REST call the artist's "Approve" click makes —
		// this is the precise call that used to redirect to "Link expired or
		// already used" before true staging existed.
		$endpoints = new ReviewEndpoints();
		$request   = new WP_REST_Request( 'POST', '/agnosis/v1/review/' . $staging_id . '/approve' );
		$request->set_param( 'id', $staging_id );
		$request->set_param( 'token', $token );
		$response = $endpoints->approve( $request );

		$this->assertFalse(
			is_wp_error( $response ),
			is_wp_error( $response ) ? 'approve() returned an error: ' . $response->get_error_message() : ''
		);

		$data = is_array( $response ) ? $response : ( method_exists( $response, 'get_data' ) ? $response->get_data() : [] );
		if ( isset( $data['post_id'] ) ) {
			$this->assertSame( $live_id, (int) $data['post_id'], 'approve() must report the LIVE post id, not the staging draft\'s.' );
		}

		$live = get_post( $live_id );
		$this->assertSame( 'publish', $live->post_status, 'The live post must remain published throughout — it was never unpublished.' );
		$this->assertStringContainsString( 'Updated biography text.', $live->post_content, 'The staged content must now be applied to the live post.' );

		$this->assertNull( get_post( $staging_id ), 'The staging draft must be deleted once its update is applied.' );

		$this->assertSame(
			0,
			$published_fired,
			'Applying a staged update to already-live content must not re-fire agnosis_post_published (it was never unpublished — no re-broadcast/re-translate is warranted).'
		);
	}

	/**
	 * Native-language pipeline regression coverage (Phase 6, agnosis-audit/
	 * NATIVE-LANGUAGE-PIPELINE.md §6) — Phase 3/4 both modify
	 * ReviewEndpoints::finalize_publish(), the exact method the "true staging"
	 * tests above exercise, but none of them ever set the artist's locale, so
	 * _agnosis_native_lang always resolved to '' and every native-language
	 * code path in finalize_publish()'s staged branch was a no-op throughout
	 * this whole file. This test is the one place staging and the
	 * native-language pipeline are exercised TOGETHER: a non-primary-language
	 * artist's staged update must be translated exactly once and the
	 * TRANSLATED result — not the staging draft's own native-language text —
	 * must land on the live post, with the native-language meta following it
	 * there rather than being lost with the deleted staging draft.
	 */
	public function test_approving_a_staged_biography_update_from_a_non_primary_language_artist_translates_once_onto_the_live_post(): void {
		update_user_meta( $this->artist_id, 'locale', 'es_ES' );
		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [
			'title' => 'About the Artist (translated)',
			'body'  => 'Translated biography text.',
		] );

		$live_id = $this->create_published_biography_without_token();

		$queue_id = $this->insert_bio_queue_row( 'test-redraft-6' );
		$creator  = new PostCreator( $this->make_pipeline() );
		$creator->handle( $queue_id );

		$staging = get_posts( [
			'post_type'      => 'agnosis_biography',
			'author'         => $this->artist_id,
			'post_status'    => 'draft',
			'meta_key'       => '_agnosis_pending_update_for',
			'meta_value'     => (string) $live_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		$this->assertNotEmpty( $staging );
		$staging_id = (int) $staging[0];
		$this->assertSame(
			'es',
			get_post_meta( $staging_id, '_agnosis_native_lang', true ),
			'Fixture sanity check: PostCreator::create_post() must resolve and persist the artist\'s locale onto the staging draft, same as any first-time submission.'
		);
		$token = (string) get_post_meta( $staging_id, '_agnosis_review_token', true );

		$endpoints = new ReviewEndpoints();
		$request   = new WP_REST_Request( 'POST', '/agnosis/v1/review/' . $staging_id . '/approve' );
		$request->set_param( 'id', $staging_id );
		$request->set_param( 'token', $token );
		$response = $endpoints->approve( $request );

		$this->assertFalse(
			is_wp_error( $response ),
			is_wp_error( $response ) ? 'approve() returned an error: ' . $response->get_error_message() : ''
		);

		$this->assertCount(
			1,
			WpAiClientTestRegistry::$prompts,
			'A staged update from a non-primary-language artist must be translated exactly once, same as a first-time publish.'
		);

		$live = get_post( $live_id );
		$this->assertSame( 'publish', $live->post_status, 'The live post must remain published throughout, exactly as every other test in this file already proves for the same-language case.' );
		$this->assertStringContainsString(
			'Translated biography text.',
			$live->post_content,
			'The TRANSLATED content must land on the live post — not the staging draft\'s original native-language text, which is what a caller forgetting to route through translate_native_content_to_primary() would produce instead.'
		);

		$this->assertSame(
			'es',
			get_post_meta( $live_id, '_agnosis_native_lang', true ),
			'Native-language meta must be copied onto the surviving live post, not lost with the deleted staging draft.'
		);
		$this->assertStringContainsString(
			'Updated biography text.',
			(string) get_post_meta( $live_id, '_agnosis_native_body', true ),
			'Phase 2: the pre-translation native text must be preserved on the post that actually survives.'
		);

		$this->assertNull( get_post( $staging_id ), 'The staging draft must still be deleted once its update is applied, exactly as the same-language case already proves.' );

		delete_option( 'agnosis_ai_provider' );
		WpAiClientTestRegistry::reset();
	}

	public function test_approving_a_staged_biography_update_with_no_new_photo_keeps_the_existing_one(): void {
		$live_id          = $this->create_published_biography_without_token( true );
		$existing_gallery = get_post_meta( $live_id, '_agnosis_gallery_ids', true );
		$this->assertNotEmpty( $existing_gallery, 'Fixture sanity check.' );

		// Text-only update — no attachments.
		$queue_id = $this->insert_bio_queue_row( 'test-redraft-3', false );
		$creator  = new PostCreator( $this->make_pipeline() );
		$creator->handle( $queue_id );

		$staging = get_posts( [
			'post_type'      => 'agnosis_biography',
			'author'         => $this->artist_id,
			'post_status'    => 'draft',
			'meta_key'       => '_agnosis_pending_update_for',
			'meta_value'     => (string) $live_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		$this->assertNotEmpty( $staging );
		$staging_id = (int) $staging[0];
		$token      = (string) get_post_meta( $staging_id, '_agnosis_review_token', true );

		$endpoints = new ReviewEndpoints();
		$request   = new WP_REST_Request( 'POST', '/agnosis/v1/review/' . $staging_id . '/approve' );
		$request->set_param( 'id', $staging_id );
		$request->set_param( 'token', $token );
		$endpoints->approve( $request );

		$this->assertSame(
			$existing_gallery,
			get_post_meta( $live_id, '_agnosis_gallery_ids', true ),
			'A staged text-only update must not wipe out the existing published photo — merge_gallery() must read the live post\'s gallery as the "existing" one, not the (empty) staging draft\'s.'
		);
	}

	public function test_rejecting_a_staged_biography_update_discards_it_and_leaves_the_live_post_untouched(): void {
		$live_id = $this->create_published_biography_without_token();

		$queue_id = $this->insert_bio_queue_row( 'test-redraft-4' );
		$creator  = new PostCreator( $this->make_pipeline() );
		$creator->handle( $queue_id );

		$staging = get_posts( [
			'post_type'      => 'agnosis_biography',
			'author'         => $this->artist_id,
			'post_status'    => 'draft',
			'meta_key'       => '_agnosis_pending_update_for',
			'meta_value'     => (string) $live_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		$this->assertNotEmpty( $staging );
		$staging_id = (int) $staging[0];
		$token      = (string) get_post_meta( $staging_id, '_agnosis_review_token', true );

		$endpoints = new ReviewEndpoints();
		$request   = new WP_REST_Request( 'POST', '/agnosis/v1/review/' . $staging_id . '/reject' );
		$request->set_param( 'id', $staging_id );
		$request->set_param( 'token', $token );
		$response = $endpoints->reject( $request );

		$this->assertFalse(
			is_wp_error( $response ),
			is_wp_error( $response ) ? 'reject() returned an error: ' . $response->get_error_message() : ''
		);

		$this->assertNull( get_post( $staging_id ), 'Rejecting a staged update must delete the staging draft outright (not trash it — there\'s nothing to recover from trash for an update that was never live).' );

		$live = get_post( $live_id );
		$this->assertNotNull( $live );
		$this->assertSame( 'publish', $live->post_status, 'Rejecting a staged update must never affect the live post\'s status.' );
		$this->assertSame( 'About the Artist', $live->post_title, 'Rejecting a staged update must never affect the live post\'s content.' );
	}

	public function test_merge_into_a_still_draft_target_updates_in_place_with_no_staging_involved(): void {
		// Simulates an ApplicationBiography-created draft the artist has never
		// approved yet — there is no "live" version, so staging must not apply.
		$draft_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'draft',
			'post_author' => $this->artist_id,
			'post_title'  => 'About the Artist',
		] );
		update_post_meta( $draft_id, '_agnosis_gallery_ids', [] );

		$queue_id = $this->insert_bio_queue_row( 'test-redraft-5' );
		$creator  = new PostCreator( $this->make_pipeline() );
		$creator->handle( $queue_id );

		$all_bios = get_posts( [
			'post_type'      => 'agnosis_biography',
			'author'         => $this->artist_id,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		] );
		$this->assertCount( 1, $all_bios, 'A merge into a still-draft target must update it in place, not create a separate staging draft.' );
		$this->assertSame( $draft_id, (int) $all_bios[0] );

		$post = get_post( $draft_id );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertStringContainsString( 'Updated biography text.', $post->post_content );
		$this->assertFalse(
			metadata_exists( 'post', $draft_id, '_agnosis_pending_update_for' ),
			'A directly-updated draft must not be tagged as a pending update for itself.'
		);
	}
}
