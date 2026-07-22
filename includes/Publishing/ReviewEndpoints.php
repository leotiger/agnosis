<?php
/**
 * REST endpoints for artist submission review.
 *
 * POST /agnosis/v1/review/{id}/approve  — publish the draft artwork.
 * POST /agnosis/v1/review/{id}/reject   — trash the draft artwork.
 *
 * Authentication accepts two paths:
 *   1. Token-based  — ?token=<signed_token> in the URL (email links, no login needed).
 *   2. WP auth      — logged-in user who is the post author or has manage_options.
 *
 * On approve: post_status set to 'publish', fires 'agnosis_post_published'
 *             (triggers ActivityPub broadcast).
 * On reject:  post moved to trash.
 *
 * Post types (2026-07-08 fix): every method here used to hard-check
 * `'agnosis_artwork' !== $post->post_type`, rejecting any biography or event
 * draft with a 404 ("Submission not found") regardless of token validity —
 * even though PostCreator::create_post() has always written the same
 * `_agnosis_review_token`/`_agnosis_review_expiry` pair for all three CPTs,
 * and Artist\ApplicationBiography explicitly documents biography drafts going
 * through "the exact same review pipeline every other Agnosis post uses"
 * (this class). RemovalEndpoints was already fixed for this (2026-07-06); this
 * class was missed. Now gated on REVIEWABLE_POST_TYPES instead.
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\AI\CallCounter;
use Agnosis\AI\PromptConfig;
use Agnosis\AI\SubmissionTranslator;
use Agnosis\Compat\LinguaForge;
use Agnosis\Core\Logger;
use Agnosis\Core\RewriteFlush;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ReviewEndpoints {

	/** Every CPT PostCreator::create_post() can draft with a review token. */
	public const REVIEWABLE_POST_TYPES = [ 'agnosis_artwork', 'agnosis_biography', 'agnosis_event' ];

	public function register_routes(): void {
		$id_arg = [
			'id' => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
		];

		register_rest_route( 'agnosis/v1', '/review/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'save' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => array_merge( $id_arg, [
				'title'   => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
				'excerpt' => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field' ],
				'body'    => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field' ],
				'tags'    => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
				'publish' => [ 'type' => 'boolean', 'default' => false ],
			] ),
		] );

		register_rest_route( 'agnosis/v1', '/review/(?P<id>\d+)/approve', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'approve' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => $id_arg,
		] );

		register_rest_route( 'agnosis/v1', '/review/(?P<id>\d+)/reject', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'reject' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => $id_arg,
		] );
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * Save artist edits to a draft submission.
	 *
	 * Accepts title, excerpt, body and tags. The image block(s) already embedded
	 * at the top of post_content are preserved — only the text body is replaced.
	 * Pass ?publish=true to save and publish in a single call.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::REVIEWABLE_POST_TYPES, true ) ) {
			return new WP_Error( 'agnosis_not_found', __( 'Submission not found.', 'agnosis' ), [ 'status' => 404 ] );
		}

		$auth = $this->check_access( $request, $post_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( ! in_array( $post->post_status, [ 'draft', 'pending' ], true ) ) {
			return new WP_Error( 'agnosis_not_draft', __( 'This submission is not awaiting review.', 'agnosis' ), [ 'status' => 409 ] );
		}

		// Preserve any image/gallery blocks embedded at the top of the content.
		$image_blocks = $this->extract_image_blocks( $post->post_content );
		$body         = (string) ( $request->get_param( 'body' ) ?? '' );
		// wpautop() + PostCreator::paragraphs_to_blocks() (2026-07-21) — this used
		// to hand-wrap $body in a single '<!-- wp:paragraph --><p>...</p>' with no
		// wpautop() call at all, so an artist's own line breaks (typed into this
		// form's textarea) never became <br /> tags — reported live: a poem's
		// line breaks were STILL lost after the original 0.9.42 fix, because
		// THIS is the path the artist's actual review-and-publish flow runs
		// through, not build_post_content() (which already got this fix, and
		// which this save() path bypasses entirely). See paragraphs_to_blocks()'s
		// own docblock for the full incident.
		$body_block   = $body ? PostCreator::paragraphs_to_blocks( wpautop( wp_kses_post( $body ) ) ) : '';
		$new_content  = $image_blocks ? $image_blocks . "\n\n" . $body_block : $body_block;

		$should_publish = (bool) $request->get_param( 'publish' );

		$update_data = [
			'ID'           => $post_id,
			'post_title'   => (string) ( $request->get_param( 'title' ) ?? $post->post_title ),
			'post_excerpt' => (string) ( $request->get_param( 'excerpt' ) ?? $post->post_excerpt ),
			'post_content' => $new_content ?: $post->post_content,
		];
		// post_status is only ever touched here for the plain-save (not
		// publishing) case. When $should_publish is true, finalize_publish()
		// below owns the status transition — for a pending-update staging
		// draft (patch 18) it must NEVER become 'publish' itself (it gets
		// deleted, not published), so this call leaving it alone and letting
		// finalize_publish() decide is what makes both cases correct with no
		// special-casing needed right here.
		if ( ! $should_publish ) {
			$update_data['post_status'] = 'draft';
		}

		$result = wp_update_post( $update_data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update tags if provided.
		$tags_raw = (string) ( $request->get_param( 'tags' ) ?? '' );
		if ( '' !== $tags_raw ) {
			$tags = array_map( 'trim', explode( ',', $tags_raw ) );
			wp_set_post_tags( $post_id, array_filter( $tags ) );
		}

		$final_id = $post_id;
		if ( $should_publish ) {
			$final_id = $this->finalize_publish( $post_id );
			if ( is_wp_error( $final_id ) ) {
				return $final_id;
			}
		}

		return new WP_REST_Response(
			[
				'status'   => $should_publish ? 'published' : 'saved',
				'post_id'  => $final_id,
				'post_url' => $should_publish ? get_permalink( $final_id ) : null,
			],
			200
		);
	}

	public function approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::REVIEWABLE_POST_TYPES, true ) ) {
			return new WP_Error( 'agnosis_not_found', __( 'Submission not found.', 'agnosis' ), [ 'status' => 404 ] );
		}

		$auth = $this->check_access( $request, $post_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( 'draft' !== $post->post_status ) {
			return new WP_Error( 'agnosis_not_draft', __( 'This submission is not awaiting review.', 'agnosis' ), [ 'status' => 409 ] );
		}

		$final_id = $this->finalize_publish( $post_id );
		if ( is_wp_error( $final_id ) ) {
			return $final_id;
		}

		return new WP_REST_Response(
			[
				'status'   => 'published',
				'post_id'  => $final_id,
				'post_url' => get_permalink( $final_id ),
			],
			200
		);
	}

	public function reject( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::REVIEWABLE_POST_TYPES, true ) ) {
			return new WP_Error( 'agnosis_not_found', __( 'Submission not found.', 'agnosis' ), [ 'status' => 404 ] );
		}

		$auth = $this->check_access( $request, $post_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( 'draft' !== $post->post_status ) {
			return new WP_Error( 'agnosis_not_draft', __( 'This submission is not awaiting review.', 'agnosis' ), [ 'status' => 409 ] );
		}

		// A pending-update staging draft (patch 18) is discarded outright —
		// force-deleted, never trashed — rather than the normal reject()
		// trash-and-clear-meta path: there is no "live" version of THIS post
		// to preserve in the trash, it was only ever a proposed change, and
		// the post it's an update FOR was never touched in the first place,
		// so there is nothing to restore or protect. wp_delete_post()'s
		// cascade removes all of this draft's own postmeta (including the
		// review token) with it — nothing left to clean up separately.
		if ( get_post_meta( $post_id, '_agnosis_pending_update_for', true ) ) {
			// Same repoint as this method's approve counterpart
			// (finalize_publish(), 2026-07-13 fix — see its own comment for the
			// full failure mode). Discarding a staged update deletes this
			// draft too, so without this its originating agnosis_queue row
			// would be left pointing at a post that's about to stop existing
			// and get resurrected/replayed later by
			// Inbox::is_already_queued() — re-drafting and re-emailing a
			// submission the artist explicitly discarded. There's no "new"
			// live post here (the update was discarded, not applied); the
			// pre-existing post it was an update FOR is enough on its own —
			// it still exists, so is_already_queued() has no reason to touch
			// this row again.
			$pending_for_on_reject = (int) get_post_meta( $post_id, '_agnosis_pending_update_for', true );
			$queue_id_on_reject    = (int) get_post_meta( $post_id, '_agnosis_queue_id', true );
			if ( $queue_id_on_reject > 0 && $pending_for_on_reject > 0 ) {
				PostCreator::mark( $queue_id_on_reject, 'published', '', $pending_for_on_reject );
			}

			wp_delete_post( $post_id, true );
		} else {
			wp_trash_post( $post_id );

			delete_post_meta( $post_id, '_agnosis_review_token' );
			delete_post_meta( $post_id, '_agnosis_review_expiry' );
			delete_post_meta( $post_id, '_agnosis_review_backtranslation' );
		}

		// 2026-07-21 fix: this used to fire 'agnosis_submission_rejected' — the
		// SAME hook PostCreator's automatic AI photo-quality gate uses, with a
		// real detected score — hardcoding score=0 and no issues. Notification's
		// listener for that hook is a photo-quality-specific template ("photo
		// quality score: 0/10", camera tips), so every manual discard sent that
		// exact email regardless of post type, whether it had a photo at all, or
		// the artist's actual reason for discarding (reported live: a discarded
		// text-only poem, whose only "photo" was a synthetic poster image,
		// triggered the same "retake your photo" bounce). A manual discard here
		// has no relationship to AI-detected photo quality at all — it's a
		// distinct event with its own, honest, reason-agnostic notification.
		do_action( 'agnosis_submission_discarded', $post_id, (int) $post->post_author, $post->post_title );

		return new WP_REST_Response( [ 'status' => 'rejected', 'post_id' => $post_id ], 200 );
	}

	/**
	 * Finalize an approved submission — the shared tail of both approve()
	 * and save( publish: true ).
	 *
	 * Normal case (no pending-update meta): publishes $post_id itself,
	 * exactly as always — flips post_status to 'publish', clears the review
	 * token, fires 'agnosis_post_published' (ActivityPub broadcast, Lingua
	 * Forge language/translation scheduling).
	 *
	 * Staged case (patch 18 — "true staging"): $post_id is a draft that was
	 * never meant to become a post of its own — it was standing in for a
	 * pending update to an already-published post ($pending_for, read from
	 * '_agnosis_pending_update_for' — see PostCreator::create_post()). Its
	 * current title/excerpt/content and the metadata PostCreator would
	 * otherwise have written directly are copied onto that live post
	 * instead, the live post's own post_status is left completely alone
	 * (never toggled — it was 'publish' throughout and stays that way), the
	 * staging draft is deleted, and 'agnosis_post_published' does NOT fire:
	 * this is an edit to already-published content, not a new publish, the
	 * same distinction Artist\ContentEditor's own direct-edit-while-
	 * published flow already draws by never firing that action either.
	 *
	 * @param int $post_id The draft (ordinary or staging) that was just approved.
	 * @return int|WP_Error The FINAL live post ID — equal to $post_id unless
	 *                      this was a staged update, in which case it's
	 *                      $pending_for. WP_Error if applying the update failed.
	 */
	private function finalize_publish( int $post_id ): int|WP_Error {
		$pending_for = (int) get_post_meta( $post_id, '_agnosis_pending_update_for', true );

		if ( ! $pending_for ) {
			Logger::info( sprintf( 'finalize_publish(#%d): no pending-update meta — publishing this post directly (not a staged update).', $post_id ), 'review' );

			// Native-language pipeline (Phase 3, 2026-07-12 — agnosis-audit/
			// NATIVE-LANGUAGE-PIPELINE.md §4c): this is the one point every
			// approval — staged or not — converges on, so it's where the
			// artist's final native-language content (their original
			// AI-generated result, or their edit of it, per ReviewConfirm) is
			// translated to primary exactly once. Returns null (and changes
			// nothing here) for the common case — no declared native language,
			// or the artist already writes in the site's primary language.
			$source     = get_post( $post_id );
			$translated = $source instanceof \WP_Post ? $this->translate_native_content_to_primary( $source ) : null;

			$update = [ 'ID' => $post_id, 'post_status' => 'publish' ];
			if ( null !== $translated ) {
				// post_title itself is never touched — it stays the artist's own
				// verbatim words at rest, exactly as before this feature existed
				// (see translate_native_content_to_primary()'s docblock).
				$update['post_excerpt'] = $translated['excerpt'];
				$update['post_content'] = $translated['content'];
			}

			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				Logger::error( sprintf( 'finalize_publish(#%d): direct publish failed — %s', $post_id, $result->get_error_message() ), 'review' );
				return $result;
			}

			if ( null !== $translated ) {
				update_post_meta( $post_id, '_agnosis_translated_title', $translated['display_title'] );

				// Phase 2 (§4b) — preserve the native-language version that's
				// about to be overwritten by the primary translation above, so
				// Phase 4 has something to build the artist's own
				// native-language sibling post from later. '_agnosis_native_lang'
				// is already correct here — PostCreator::create_post() wrote it
				// straight onto this exact post at intake, and this is a
				// first-time publish, so $post_id is never replaced by a
				// different post the way a staged update's target is.
				update_post_meta( $post_id, '_agnosis_native_excerpt', $translated['native_excerpt'] );
				update_post_meta( $post_id, '_agnosis_native_body', $translated['native_body'] );
				update_post_meta( $post_id, '_agnosis_native_tags', wp_json_encode( $translated['native_tags'] ) );

				if ( $source instanceof \WP_Post && 'agnosis_artwork' === $source->post_type && '' !== $translated['medium'] ) {
					// 2026-07-21: same silent-drop bug PostCreator::write_post_meta()
					// had, and the same fix — a translated medium that doesn't match
					// the live vocabulary is now recorded as a reviewable proposal
					// (Admin\MediumProposals) instead of doing nothing. Clear any
					// stale proposal from an earlier finalize_publish() pass first,
					// same reasoning as write_post_meta()'s own reset.
					delete_post_meta( $post_id, '_agnosis_medium_proposal' );
					if ( in_array( $translated['medium'], PromptConfig::medium_terms(), true ) ) {
						wp_set_object_terms( $post_id, $translated['medium'], 'agnosis_medium' );
					} else {
						update_post_meta( $post_id, '_agnosis_medium_proposal', $translated['medium'] );
					}
				}

				if ( ! empty( $translated['tags'] ) ) {
					// Trid-gated reconciliation, not a blind wp_set_post_tags()
					// — see LinguaForge::resolve_primary_tags()'s own docblock.
					// Native tags translated straight to a new post_tag term
					// every approval is exactly the bug the medium/tag trid
					// rework closed everywhere else; this was the one call
					// site that rework never reached.
					$primary_tag_ids = ( new LinguaForge() )->resolve_primary_tags( $translated['native_tag_ids'], $translated['tags'] );
					if ( ! empty( $primary_tag_ids ) ) {
						wp_set_object_terms( $post_id, $primary_tag_ids, 'post_tag' );
					}
				}
			}

			delete_post_meta( $post_id, '_agnosis_review_token' );
			delete_post_meta( $post_id, '_agnosis_review_expiry' );
			delete_post_meta( $post_id, '_agnosis_review_backtranslation' );

			// Native-language pipeline (Phase 4, §4d): exclude the artist's own
			// language from Lingua Forge's AI-driven fan-out when a native-language
			// sibling is about to be created directly instead (sync_native_sibling()
			// below) — otherwise LF would separately re-translate this exact
			// language from the primary post it just spent an AI call producing.
			// Native lang is read straight from post meta rather than gated on
			// $translated !== null so this stays correct even if translation
			// happened but the sibling sync below no-ops for some other reason.
			$native_lang   = (string) get_post_meta( $post_id, '_agnosis_native_lang', true );
			$exclude_langs = '' !== $native_lang ? [ $native_lang ] : [];

			do_action( 'agnosis_post_published', $post_id, $exclude_langs );

			LinguaForge::sync_native_sibling( $post_id );

			// The primary-language post (and, when the artist writes in a
			// different language, its native-language sibling just built
			// above) now both exist — see RewriteFlush's own docblock for why
			// a permalink flush is needed for either to actually resolve
			// instead of 404ing.
			RewriteFlush::schedule();

			return $post_id;
		}

		$staging = get_post( $post_id );
		$target  = get_post( $pending_for );
		Logger::info(
			sprintf(
				'finalize_publish(#%d): staged update — pending_for=#%d, staging exists=%s, target exists=%s, target status=%s.',
				$post_id,
				$pending_for,
				$staging ? 'yes' : 'NO',
				$target ? 'yes' : 'NO',
				$target ? $target->post_status : 'n/a'
			),
			'review'
		);
		if ( ! $staging || ! $target ) {
			Logger::error( sprintf( 'finalize_publish(#%d): staged update aborted — staging or target post missing (pending_for=#%d).', $post_id, $pending_for ), 'review' );
			return new WP_Error(
				'agnosis_pending_target_missing',
				__( 'The original published item could not be found.', 'agnosis' ),
				[ 'status' => 500 ]
			);
		}

		// Same native→primary translation the direct branch above performs —
		// see translate_native_content_to_primary()'s docblock. $staging holds
		// the artist's final native-language content (their original result,
		// or their edit of it); when translation is needed, its OUTPUT (not
		// $staging's own raw fields) is what gets written onto the live post.
		$translated = $this->translate_native_content_to_primary( $staging );

		$result = wp_update_post(
			[
				'ID'           => $pending_for,
				'post_title'   => $staging->post_title, // never translated — artist's own words, always.
				'post_excerpt' => null !== $translated ? $translated['excerpt'] : $staging->post_excerpt,
				'post_content' => null !== $translated ? $translated['content'] : $staging->post_content,
			],
			true
		);
		if ( is_wp_error( $result ) ) {
			Logger::error( sprintf( 'finalize_publish(#%d): wp_update_post() onto target #%d failed — %s', $post_id, $pending_for, $result->get_error_message() ), 'review' );
			return $result;
		}

		// Read the target straight back from the DB (bypassing the object
		// cache wp_update_post() just primed) to confirm the write actually
		// landed — the single most useful line in this log if a staged
		// update ever again appears to "succeed" without visibly changing
		// the live post.
		clean_post_cache( $pending_for );
		$verify = get_post( $pending_for );
		Logger::info(
			sprintf(
				'finalize_publish(#%d): wp_update_post() on target #%d returned #%d. Re-read title="%s", content length=%d.',
				$post_id,
				$pending_for,
				(int) $result,
				$verify ? $verify->post_title : '(missing)',
				$verify ? strlen( $verify->post_content ) : -1
			),
			'review'
		);

		// Everything PostCreator::create_post() would otherwise have written
		// directly onto the live post for a non-staged merge. Deliberately
		// excludes write-once/identity-style meta ('_agnosis_original_title',
		// '_agnosis_intake_endpoint') — those are never meant to change once
		// set, on a staged update any more than on a direct one.
		foreach ( [
			'_agnosis_gallery_ids',
			'_agnosis_artist_prompt',
			'_agnosis_translated_title',
			'_agnosis_queue_id',
			'_agnosis_event_location',
			'_agnosis_event_address',
			'_agnosis_event_date',
			'_agnosis_event_timezone',
			'_agnosis_biography_portfolio_url',
			'_agnosis_biography_portfolio_embedded',
			'_agnosis_biography_social_url_1',
			'_agnosis_biography_social_url_2',
			'_agnosis_biography_social_url_3',
			'_agnosis_dropped_links',
			// '_agnosis_native_lang'/'_agnosis_native_medium' are deliberately
			// NOT in this list — see the explicit block right below the loop.
			// They need the OPPOSITE of this loop's "skip when the staging
			// draft's own value is empty" semantics: an artist switching FROM
			// a non-primary language back TO the site's primary one needs the
			// stale prior value actively cleared, not silently left in place.
			// '_agnosis_native_excerpt'/'_agnosis_native_body' are NOT copied
			// here either — they only mean anything once a real translation
			// actually happens (see the unconditional block below), never a
			// static copy of the staging draft's own fields.
		] as $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( '' !== $value ) {
				update_post_meta( $pending_for, $meta_key, $value );
			}
		}

		// The live/target post's featured image — unlike the plain meta keys
		// above, this needs set_post_thumbnail() rather than a raw
		// update_post_meta( '_thumbnail_id' ), matching how
		// Artist\ContentEditor's own direct-edit-to-published-post path
		// already replaces a thumbnail. Previously missing entirely from this
		// method: PostCreator::write_post_meta() always set the new photo as
		// the STAGING draft's own thumbnail, but nothing ever transferred it
		// onto $pending_for before the staging draft was deleted a few lines
		// below — so an artist re-sending a biography/artwork/event with a
		// new photo saw the gallery block in the body update (via
		// '_agnosis_gallery_ids' above) while the featured image silently
		// stayed on the old photo forever, on the live post AND on every
		// Lingua Forge translated sibling (LinguaForge::sync_native_sibling()/
		// schedule_fanout() below just faithfully re-copy whatever
		// '_thumbnail_id' is on $pending_for at the time they run). Skipped
		// when the staging draft has no thumbnail of its own — an update that
		// didn't include a new photo leaves the live post's existing featured
		// image untouched, matching every meta key above's own "skip when
		// empty" behavior.
		$staged_thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		if ( $staged_thumbnail_id ) {
			set_post_thumbnail( $pending_for, $staged_thumbnail_id );
		}

		// Native-language pipeline follow-up fix (seventh audit §2b,
		// NATIVE-LANGUAGE-PIPELINE.md Phase 2's own documented "known
		// follow-up, not a blocker"). $previous_native_lang is read BEFORE
		// either write below touches $pending_for, so it's genuinely the
		// target's pre-update value — needed afterward to detect an actual
		// language change, not just its final state.
		$previous_native_lang  = (string) get_post_meta( $pending_for, '_agnosis_native_lang', true );
		$current_native_lang   = (string) get_post_meta( $post_id, '_agnosis_native_lang', true );
		$current_native_medium = (string) get_post_meta( $post_id, '_agnosis_native_medium', true );

		if ( '' !== $current_native_lang ) {
			update_post_meta( $pending_for, '_agnosis_native_lang', $current_native_lang );
		} else {
			delete_post_meta( $pending_for, '_agnosis_native_lang' );
		}

		if ( '' !== $current_native_medium ) {
			update_post_meta( $pending_for, '_agnosis_native_medium', $current_native_medium );
		} else {
			delete_post_meta( $pending_for, '_agnosis_native_medium' );
		}

		// The artist's declared language actually changed (not just cleared
		// back to primary) — the OLD native-language sibling
		// Compat\LinguaForge::sync_native_sibling() built for
		// $previous_native_lang would otherwise be permanently orphaned:
		// Phase 4 only ever syncs whatever language currently sits on
		// $pending_for, so nothing would ever touch that sibling again.
		if ( '' !== $previous_native_lang && $previous_native_lang !== $current_native_lang ) {
			LinguaForge::trash_orphaned_native_sibling( $pending_for, $previous_native_lang );
		}

		// Translated tags (when translation happened) take priority over the
		// staging draft's own native-language tag terms — see
		// translate_native_content_to_primary()'s docblock. When translation
		// DID happen, resolve through the same trid-gated reconciliation the
		// direct-publish branch above uses (LinguaForge::resolve_primary_tags())
		// rather than a blind wp_set_post_tags() — this was the one call site
		// the medium/tag trid rework never reached. When it didn't (no
		// declared native language, or the artist already writes in primary),
		// the staging draft's own tags are already primary-language — assign
		// them directly exactly as before this feature existed.
		if ( null !== $translated && ! empty( $translated['tags'] ) ) {
			$primary_tag_ids = ( new LinguaForge() )->resolve_primary_tags( $translated['native_tag_ids'], $translated['tags'] );
			if ( ! empty( $primary_tag_ids ) ) {
				wp_set_object_terms( $pending_for, $primary_tag_ids, 'post_tag' );
			}
		} else {
			$tags = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
			// wp_get_post_tags() can return WP_Error (e.g. an invalid taxonomy) —
			// a WP_Error object is never empty(), so the old bare `! empty( $tags )`
			// check would have passed it straight into wp_set_post_tags(), which
			// only accepts array|string for its second parameter.
			if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
				wp_set_post_tags( $pending_for, $tags );
			}
		}

		if ( null !== $translated ) {
			update_post_meta( $pending_for, '_agnosis_translated_title', $translated['display_title'] );

			// Phase 2 (§4b) — same preservation as the direct-publish branch
			// above, here written onto $pending_for (the post that survives)
			// rather than $post_id (the staging draft, about to be deleted a
			// few lines below).
			update_post_meta( $pending_for, '_agnosis_native_excerpt', $translated['native_excerpt'] );
			update_post_meta( $pending_for, '_agnosis_native_body', $translated['native_body'] );
			update_post_meta( $pending_for, '_agnosis_native_tags', wp_json_encode( $translated['native_tags'] ) );

			if ( 'agnosis_artwork' === $target->post_type && '' !== $translated['medium'] ) {
				// 2026-07-21: same fix as the direct-publish branch above — see
				// that comment for the full explanation.
				delete_post_meta( $pending_for, '_agnosis_medium_proposal' );
				if ( in_array( $translated['medium'], PromptConfig::medium_terms(), true ) ) {
					wp_set_object_terms( $pending_for, $translated['medium'], 'agnosis_medium' );
				} else {
					update_post_meta( $pending_for, '_agnosis_medium_proposal', $translated['medium'] );
				}
			}
		}

		// Repoint the originating agnosis_queue row off the staging draft
		// BEFORE it's deleted below (2026-07-13 fix). PostCreator::handle()
		// writes the STAGING draft's own post ID onto its queue row
		// (`mark( $queue_id, 'published', '', $post_id )`) at drafting time —
		// '_agnosis_queue_id' (copied onto $pending_for by the meta loop
		// above) is how we find that row again. Left unrepointed, the row
		// permanently points at a post that's about to stop existing;
		// Inbox::is_already_queued()'s 'published' branch treats a
		// non-resolving post_id as "post deleted — re-run" and resets the row
		// to 'pending' the next time that IMAP UID is re-examined (the admin
		// "heal the queue" action does this unconditionally for every such
		// row; a UIDVALIDITY-triggered mailbox rescan can do it automatically
		// too) — replaying the ORIGINAL submission through the pipeline,
		// minting a second staging draft, and firing a second
		// 'agnosis_post_drafted' review email for content the artist already
		// approved and that's already live, with no artist action at all.
		// Repointing at $pending_for (the post that actually survives) means
		// is_already_queued() finds a real post and leaves the row alone.
		$queue_id_for_target = (int) get_post_meta( $pending_for, '_agnosis_queue_id', true );
		if ( $queue_id_for_target > 0 ) {
			PostCreator::mark( $queue_id_for_target, 'published', '', $pending_for );
		}

		// Delete any text-poster attachment(s) PostCreator::create_post()
		// superseded while building this staging draft (see
		// PostCreator::$last_dropped_poster_ids' own docblock) — it stashed
		// them as '_agnosis_stale_poster_ids' rather than deleting them
		// immediately, since $pending_for (the live post) was still showing
		// its OLD poster until the '_agnosis_gallery_ids' copy above actually
		// replaced it. Read BEFORE wp_delete_post() below removes this
		// draft's own postmeta, same "read off the staging draft before it's
		// gone" ordering as the queue-row repoint just above.
		$stale_poster_ids = json_decode( (string) get_post_meta( $post_id, '_agnosis_stale_poster_ids', true ), true );
		if ( is_array( $stale_poster_ids ) ) {
			foreach ( $stale_poster_ids as $stale_id ) {
				wp_delete_attachment( (int) $stale_id, true );
			}
		}

		// Staging post was never meant to be kept — delete outright (skip
		// trash); its own postmeta (including the review token) goes with it.
		wp_delete_post( $post_id, true );

		// Refresh Lingua Forge's translated siblings with the corrected
		// content. 'agnosis_post_published' deliberately does NOT fire here
		// (this is an edit to already-live content, not a new publish — see
		// this method's own docblock) — but that also means nothing else
		// would ever tell Lingua Forge the source content changed. Without
		// this call, every translated sibling silently goes stale on every
		// single staged update forever: the artist's correction lands on the
		// primary/source post but never reaches any other language. Same
		// explicit schedule_fanout() call Artist\ContentEditor already makes
		// for its own direct-edit-to-published-content path, for the exact
		// same reason.
		//
		// Native-language pipeline (Phase 4, §4d): excludes the artist's own
		// language from this fan-out too, for the same reason the direct
		// (first-time publish) branch above does — a native-language sibling is
		// synced directly, below, rather than left to LF's AI translation.
		$native_lang_for_target = (string) get_post_meta( $pending_for, '_agnosis_native_lang', true );
		LinguaForge::schedule_fanout( $pending_for, '' !== $native_lang_for_target ? [ $native_lang_for_target ] : [] );

		LinguaForge::sync_native_sibling( $pending_for );

		// Same reasoning as the direct-publish branch above — the staged
		// update just landed on the live (primary-language) post and its
		// native-language sibling (if any) was just refreshed too.
		RewriteFlush::schedule();

		Logger::info( sprintf( 'finalize_publish(#%d): staged update applied to #%d and staging draft deleted.', $post_id, $pending_for ), 'review' );

		return $pending_for;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract the leading image/gallery block markup from post content.
	 *
	 * When the artist edits the body text we must not touch the AI-uploaded image
	 * block(s) at the top of the content — only the text below them changes.
	 *
	 * @param string $content Existing post_content.
	 * @return string Image/gallery block markup, or empty string if none.
	 */
	private function extract_image_blocks( string $content ): string {
		// Match one or more leading wp:image or wp:gallery blocks.
		if ( preg_match( '/^((?:<!-- wp:(?:image|gallery)[^>]*-->.*?<!-- \/wp:(?:image|gallery) -->[\s]*)+)/s', $content, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	/**
	 * Translate a native-language draft/staging post's excerpt/body/medium/tags
	 * into the site's primary language in a single AI call, immediately before
	 * publish — Phase 3 of the native-language pipeline redesign
	 * (agnosis-audit/NATIVE-LANGUAGE-PIPELINE.md §4c). Called from both
	 * branches of finalize_publish() so a staged update and a first-time
	 * publish are translated identically.
	 *
	 * post_title is deliberately EXCLUDED from what gets written back onto the
	 * live post — it stays the artist's own verbatim words at rest everywhere
	 * in this plugin (the dual-title design predates this feature; see
	 * PostCreator::create_post()'s '_agnosis_original_title' handling and this
	 * class's own callers, neither of which has ever translated post_title).
	 * The translated title this method DOES produce is a separate,
	 * display-only copy — 'display_title' in the return value — meant for
	 * '_agnosis_translated_title' (Compat\LinguaForge's dual-title system),
	 * exactly the meta PostCreator::create_post() seeds at intake with the raw
	 * (at that point still native, not yet primary — see that method's own
	 * docblock) AI title. This is the point that value actually becomes
	 * trustworthy as a primary-language translation.
	 *
	 * Returns null — meaning "nothing to translate, publish $source's own
	 * fields unchanged" — when: $source has no declared native language
	 * (`_agnosis_native_lang`, only ever set by the native-first pipeline —
	 * see PostCreator::create_post()), no AI provider is configured, the
	 * artist's language already matches the site's primary language (the
	 * common single-language case costs nothing extra, same convention every
	 * other translation method in this codebase uses), or the translation call
	 * itself fails (logged, falls back to publishing the native-language
	 * content as-is rather than blocking the approval entirely).
	 *
	 * Also returns the untranslated native excerpt/body ('native_excerpt'/
	 * 'native_body' below) — the design doc's Phase 2 (§4b, "hold the native
	 * result, don't discard it") — so the caller can persist them onto the
	 * post that actually survives (the target of a staged update, or $source
	 * itself for a first-time publish) BEFORE they're overwritten with the
	 * primary translation below. Deliberately captured HERE, at approval,
	 * rather than at intake as §4b originally proposed: this is the point the
	 * FINAL text is known — the artist's original AI-generated result, or
	 * their edit of it if they changed anything on the confirm form — so
	 * what's preserved is what was actually approved, not a possibly-stale
	 * intake-time snapshot. Without this, once this method's caller writes the
	 * primary translation over post_excerpt/post_content, the native-language
	 * version would be gone entirely — the one thing Phase 4 (creating the
	 * artist's own native-language sibling post, agnosis-audit/
	 * NATIVE-LANGUAGE-PIPELINE.md §4d) needs to exist at all.
	 *
	 * @return array{display_title: string, excerpt: string, content: string, medium: string, tags: string[], native_tag_ids: int[], native_excerpt: string, native_body: string, native_tags: string[]}|null
	 */
	private function translate_native_content_to_primary( \WP_Post $source ): ?array {
		$native_lang = (string) get_post_meta( $source->ID, '_agnosis_native_lang', true );
		if ( '' === $native_lang ) {
			return null;
		}

		$translator = SubmissionTranslator::from_settings();
		if ( null === $translator ) {
			return null;
		}

		$primary_lang = $translator->resolve_target_language();
		if ( $primary_lang === $native_lang ) {
			return null; // Artist already writes in the site's primary language.
		}

		// Strip the leading image/gallery block(s) before translating — only
		// the text content is ever sent to the AI, same convention
		// save()/extract_image_blocks() already use for an artist-edited body.
		$image_blocks = $this->extract_image_blocks( $source->post_content );
		$remainder    = '' !== $image_blocks ? str_replace( $image_blocks, '', $source->post_content ) : $source->post_content;
		$body_plain   = wp_strip_all_tags( $remainder );

		$native_medium = (string) get_post_meta( $source->ID, '_agnosis_native_medium', true );

		// Fetched as full term objects, not just ['fields' => 'names'] —
		// resolve_primary_tags() below needs the native term IDs too, to
		// pair each translated name back to the exact native term it came
		// from (see that method's own docblock for why positional pairing
		// is the only signal translate_fields() leaves available). Both
		// $native_tags (names, for the translation prompt below and for
		// '_agnosis_native_tags') and $native_tag_ids (for the pairing) are
		// derived from this SAME single query, so their ordering always
		// matches each other.
		$native_terms   = wp_get_post_terms( $source->ID, 'post_tag', [ 'fields' => 'all' ] );
		$native_terms   = is_wp_error( $native_terms ) ? [] : $native_terms;
		$native_tags    = wp_list_pluck( $native_terms, 'name' );
		$native_tag_ids = wp_list_pluck( $native_terms, 'term_id' );

		// Batched into ONE chat() call via translate_fields() — title, excerpt,
		// body, medium, and tags together — rather than one call per field.
		// This is the single AI call §7 of the design doc accounts for per
		// cross-language approval.
		$fields = array_filter(
			[
				'title'   => $source->post_title,
				'excerpt' => $source->post_excerpt,
				'body'    => $body_plain,
				'medium'  => $native_medium,
				'tags'    => implode( ' | ', $native_tags ),
			],
			static fn( $v ) => '' !== trim( (string) $v )
		);

		if ( empty( $fields ) ) {
			return null; // Nothing with any text content to translate.
		}

		// Existing primary-language tags folded into THIS SAME call as a
		// per-field instruction, not a separate reconciliation call after
		// translating — see translate_fields()'s own docblock for why: a
		// second AI call here would break the exactly-one-call-per-approval
		// invariant this whole pipeline exists to guarantee. Compat\LinguaForge::
		// resolve_primary_tags() (below) trusts an exact match against this
		// same list precisely because the AI was told, in this one prompt,
		// to copy the existing text verbatim when it fits — not asked to
		// freely translate and hope for a coincidental match afterward.
		$field_instructions = [];
		$existing_primary_tags = PromptConfig::existing_tags_for_language( $primary_lang );
		if ( ! empty( $existing_primary_tags ) ) {
			$field_instructions['tags'] = 'When translating, if a tag means the same as one of these already-existing tags, use its EXACT existing text instead of inventing new wording: '
				. implode( ' | ', $existing_primary_tags );
		}

		$translated = $translator->translate_fields( $fields, $primary_lang, $field_instructions );
		if ( empty( $translated ) ) {
			Logger::warning(
				sprintf( 'translate_native_content_to_primary(#%d): native→primary translation failed — publishing native-language content unchanged.', $source->ID ),
				'review'
			);
			return null;
		}

		// Seventh audit G-2: the single AI translation call §7 of the design
		// doc accounts for per cross-language approval. Recorded here, once
		// translate_fields() has actually returned data, rather than
		// unconditionally at the top of this method — a call that failed or a
		// submission that never needed translating shouldn't inflate the count.
		CallCounter::record( $source->ID, 'native_to_primary' );

		// Same wpautop() + paragraphs_to_blocks() fix as save() above (2026-07-21)
		// — $translated['body'] is plain AI-translated text and previously got
		// the identical single-<p>-no-wpautop() treatment, losing the artist's
		// line breaks on every native-language-to-primary approval.
		$body_block = isset( $translated['body'] ) && '' !== trim( $translated['body'] )
			? PostCreator::paragraphs_to_blocks( wpautop( wp_kses_post( $translated['body'] ) ) )
			: '';
		$content = $image_blocks ? $image_blocks . "\n\n" . $body_block : $body_block;

		$tags = isset( $translated['tags'] ) && '' !== trim( $translated['tags'] )
			? array_values( array_filter( array_map( 'trim', explode( '|', $translated['tags'] ) ) ) )
			: $native_tags; // Translation of the tag bundle failed/was skipped — keep the native names rather than dropping tags entirely.

		return [
			'display_title'  => $translated['title']   ?? $source->post_title,
			'excerpt'        => $translated['excerpt'] ?? $source->post_excerpt,
			'content'        => $content,
			'medium'         => trim( $translated['medium'] ?? $native_medium ),
			'tags'           => $tags,
			// Native term IDs, same order as $native_tags — lets
			// LinguaForge::resolve_primary_tags() pair each translated name
			// in 'tags' back to the exact native term it came from, by
			// array position (see that method's own docblock for why
			// that's the only pairing signal available).
			'native_tag_ids' => $native_tag_ids,
			// Phase 2 (§4b) — see this method's own docblock above. Native
			// tags are the SAME $native_tags read above, before translation —
			// kept distinct from 'tags' (the translated set applied to the
			// primary post) since Phase 4 (§4d) needs the untranslated names
			// for the native-language sibling.
			'native_excerpt' => $source->post_excerpt,
			'native_body'    => $body_plain,
			'native_tags'    => $native_tags,
		];
	}

	// -------------------------------------------------------------------------
	// Access control
	// -------------------------------------------------------------------------

	/**
	 * Verify that the request is authorised.
	 *
	 * Accepts either a valid signed token in the query string or a logged-in
	 * user who owns the post (or has manage_options).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @param int             $post_id The artwork post ID.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	/**
	 * REST permission gate — called before the route callback.
	 *
	 * Accepts the request if a token is present (will be verified inside the
	 * callback) OR the user is already authenticated. Returning WP_Error here
	 * ensures the route is unreachable even if check_access() has a logic bug.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ): bool|WP_Error {
		if ( ! empty( sanitize_text_field( (string) $request->get_param( 'token' ) ) ) ) {
			return true; // Token path — verified inside the callback.
		}
		if ( is_user_logged_in() ) {
			return true; // Auth path — specific permissions verified inside the callback.
		}
		return new WP_Error(
			'agnosis_auth_required',
			__( 'Authentication required.', 'agnosis' ),
			[ 'status' => 401 ]
		);
	}

	private function check_access( WP_REST_Request $request, int $post_id ): bool|WP_Error {
		// Path 1 — token from query string (email link).
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( ! empty( $token ) ) {
			return self::verify_token( $post_id, $token );
		}

		// Path 2 — logged-in user.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error(
				'agnosis_auth_required',
				__( 'Authentication required.', 'agnosis' ),
				[ 'status' => 401 ]
			);
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$post = get_post( $post_id );
		if ( $post && (int) $post->post_author === $user_id ) {
			return true;
		}

		return new WP_Error(
			'agnosis_forbidden',
			__( 'You do not have permission to review this submission.', 'agnosis' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Validate a signed review token against what is stored in post meta.
	 *
	 * Public and static (fourth audit §3a): a pure, read-only check with no
	 * dependency on instance state, so `Publishing\ReviewConfirm` can reuse the
	 * exact same check the REST layer performs — the token-first-then-act order
	 * only holds if both sides use one authoritative implementation, not two
	 * copies that could drift apart.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Token from the request.
	 * @return true|WP_Error
	 */
	public static function verify_token( int $post_id, string $token ): bool|WP_Error {
		$stored_token  = (string) get_post_meta( $post_id, '_agnosis_review_token', true );
		$stored_expiry = (int) get_post_meta( $post_id, '_agnosis_review_expiry', true );

		if ( empty( $stored_token ) ) {
			return new WP_Error(
				'agnosis_token_invalid',
				__( 'Review link not found or already used.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! hash_equals( $stored_token, $token ) ) {
			return new WP_Error(
				'agnosis_token_invalid',
				__( 'Invalid review token.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		if ( $stored_expiry && time() > $stored_expiry ) {
			return new WP_Error(
				'agnosis_token_expired',
				__( 'This review link has expired. Please log in to manage your submissions.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}
}
