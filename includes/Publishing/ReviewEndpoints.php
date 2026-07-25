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
 *             (Lingua Forge language-meta/translation-scheduling — NOT
 *             ActivityPub federation since TAG-REDESIGN.md F3: see
 *             finalize_publish()'s own note on where federation is
 *             actually triggered from now).
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
use Agnosis\Network\FederationSettlement;
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

		// Tags — 2026-07-24 demolition (TAG-REDESIGN.md, T0). Tags are no
		// longer a pre-approval review-card concern at all (§1: "the review
		// card's tags field disappears"); this endpoint no longer accepts or
		// writes a 'tags' param. See TAG-REDESIGN.md §2/§4 for the
		// replacement acquisition/association pipeline (T1+).

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

				if ( $source instanceof \WP_Post && 'agnosis_artwork' === $source->post_type && '' !== $translated['medium'] ) {
					// 2026-07-21: same silent-drop bug PostCreator::write_post_meta()
					// had, and the same fix — a translated medium that doesn't match
					// the live vocabulary is now recorded as a reviewable proposal
					// (Admin\MediumProposals) instead of doing nothing. Clear any
					// stale proposal from an earlier finalize_publish() pass first,
					// same reasoning as write_post_meta()'s own reset.
					delete_post_meta( $post_id, '_agnosis_medium_proposal' );
					delete_post_meta( $post_id, '_agnosis_medium_proposal_created' );
					if ( in_array( $translated['medium'], PromptConfig::medium_terms(), true ) ) {
						wp_set_object_terms( $post_id, $translated['medium'], 'agnosis_medium' );
					} else {
						update_post_meta( $post_id, '_agnosis_medium_proposal', $translated['medium'] );
						update_post_meta( $post_id, '_agnosis_medium_proposal_created', time() );
					}
				}

				// Tags — 2026-07-25 (TAG-REDESIGN.md T1): candidates acquired
				// on THIS translation call for a native-language submission.
				// Post-type agnostic by construction (soundness review §8) —
				// unlike medium above, this isn't gated to agnosis_artwork; a
				// biography/event's own translate_native_content_to_primary()
				// call proposes tags from its own content the same way.
				if ( ! empty( $translated['tags'] ) ) {
					update_post_meta( $post_id, '_agnosis_tag_candidates', wp_json_encode( $translated['tags'], JSON_UNESCAPED_UNICODE ) );
				} else {
					delete_post_meta( $post_id, '_agnosis_tag_candidates' );
				}
			}

			// Tags — ASSOCIATION (T2, finalize_tags() v2). Runs unconditionally
			// here (not nested inside the `null !== $translated` block above) —
			// a PRIMARY-language submission never enters that block at all
			// ($translated stays null, "nothing to translate"), but its own
			// candidates were already written at INTAKE by
			// PostCreator::write_post_meta() and still need associating.
			$this->finalize_tags( $post_id );

			// Federation trigger (TAG-REDESIGN.md F3, §6c) — deliberately AFTER
			// finalize_tags() so this sees the just-associated tags/proposals,
			// and NOT via 'agnosis_post_published' below (that action no longer
			// drives federation at all — see this class's own header note). A
			// submission whose every candidate matched the existing vocabulary
			// settles and federates immediately here; one with a genuinely new
			// tag/medium proposal waits (Admin\TagProposals/MediumProposals'
			// resolve hooks, or the agnosis_federation_tag_wait_sweep cron
			// fallback, settle it later).
			( new FederationSettlement() )->maybe_settle( $post_id );

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

		if ( null !== $translated ) {
			update_post_meta( $pending_for, '_agnosis_translated_title', $translated['display_title'] );

			// Phase 2 (§4b) — same preservation as the direct-publish branch
			// above, here written onto $pending_for (the post that survives)
			// rather than $post_id (the staging draft, about to be deleted a
			// few lines below).
			update_post_meta( $pending_for, '_agnosis_native_excerpt', $translated['native_excerpt'] );
			update_post_meta( $pending_for, '_agnosis_native_body', $translated['native_body'] );

			if ( 'agnosis_artwork' === $target->post_type && '' !== $translated['medium'] ) {
				// 2026-07-21: same fix as the direct-publish branch above — see
				// that comment for the full explanation.
				delete_post_meta( $pending_for, '_agnosis_medium_proposal' );
				delete_post_meta( $pending_for, '_agnosis_medium_proposal_created' );
				if ( in_array( $translated['medium'], PromptConfig::medium_terms(), true ) ) {
					wp_set_object_terms( $pending_for, $translated['medium'], 'agnosis_medium' );
				} else {
					update_post_meta( $pending_for, '_agnosis_medium_proposal', $translated['medium'] );
					update_post_meta( $pending_for, '_agnosis_medium_proposal_created', time() );
				}
			}

			// Tags — 2026-07-25 (TAG-REDESIGN.md T1): a NATIVE-language staged
			// update's candidates come from THIS translation, same as the
			// direct-publish branch above — see that branch's own comment.
			// Takes precedence over whatever the staging draft's own intake
			// pass wrote (it wouldn't have written anything for a native
			// submission anyway — PostCreator::write_post_meta()'s own gate).
			if ( ! empty( $translated['tags'] ) ) {
				update_post_meta( $pending_for, '_agnosis_tag_candidates', wp_json_encode( $translated['tags'], JSON_UNESCAPED_UNICODE ) );
			} else {
				delete_post_meta( $pending_for, '_agnosis_tag_candidates' );
			}
		} else {
			// PRIMARY-language staged update — translate_native_content_to_primary()
			// never ran, so any candidates come from the staging draft's own
			// intake-time write (PostCreator::write_post_meta()) instead;
			// carry it over onto the post that survives, same "explicit
			// set-or-clear" shape the native_lang/native_medium block above
			// uses rather than the generic copy-loop's "skip when empty"
			// (a staging draft with NO candidates — e.g. the AI proposed
			// none — must actively CLEAR a stale value left on $pending_for
			// by an earlier revision, not silently leave it in place).
			$staged_tag_candidates = (string) get_post_meta( $post_id, '_agnosis_tag_candidates', true );
			if ( '' !== $staged_tag_candidates ) {
				update_post_meta( $pending_for, '_agnosis_tag_candidates', $staged_tag_candidates );
			} else {
				delete_post_meta( $pending_for, '_agnosis_tag_candidates' );
			}
		}

		// Tags — ASSOCIATION (T2, finalize_tags() v2), on $pending_for (the
		// post that survives) — same reasoning as the direct-publish branch's
		// own call: must run for BOTH the native-translated and the
		// primary-language-carried-over cases above, so it sits after the
		// if/else rather than inside either branch.
		$this->finalize_tags( $pending_for );

		// Federation trigger (TAG-REDESIGN.md F3) — same reasoning as the
		// direct-publish branch's own call just above finalize_tags()'s
		// sibling call site there. $pending_for is already-published content
		// (this whole branch never toggles its post_status), so this is a
		// no-op when it already federated; it settles/federates now for the
		// first time if the ORIGINAL publish left it pending-tags and this
		// staged update's own tag association just cleared the gate.
		( new FederationSettlement() )->maybe_settle( $pending_for );

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
		// N-2 (fifteenth audit, hardening): re-verify each id is actually a
		// generated text-poster attachment — not just trust the stashed meta —
		// before the hard delete below. '_agnosis_stale_poster_ids' is written
		// once at drafting time (PostCreator::$last_dropped_poster_ids) and
		// only ever read back here, so nothing should currently invalidate it
		// between those two points; this guard exists purely as defense in
		// depth against a future bug (a stale/reused id, or a code path that
		// writes this meta incorrectly) turning into a silent, unrecoverable
		// deletion of a real artist attachment rather than a loud, logged skip.
		$stale_poster_ids = json_decode( (string) get_post_meta( $post_id, '_agnosis_stale_poster_ids', true ), true );
		if ( is_array( $stale_poster_ids ) ) {
			foreach ( $stale_poster_ids as $stale_id ) {
				$stale_id = (int) $stale_id;
				if ( ! PostCreator::is_text_poster_attachment( $stale_id ) ) {
					Logger::warning(
						sprintf( 'finalize_publish(#%d): #%d in _agnosis_stale_poster_ids no longer looks like a text-poster attachment — skipped hard-delete, left in place.', $post_id, $stale_id ),
						'review'
					);
					continue;
				}
				wp_delete_attachment( $stale_id, true );
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
	 * @return array{display_title: string, excerpt: string, content: string, medium: string, tags: array<string>, native_excerpt: string, native_body: string}|null
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

		// Tags — 2026-07-25 (TAG-REDESIGN.md T1): the repurposed prompt is
		// reintroduced — see §2's exact wording: "propose 3–{tag_count} tags
		// in {primary language}; when a concept matches one of these
		// existing tags, use its EXACT text: {primary vocabulary list}".
		// Folded into THIS SAME translate_fields() call via
		// $field_instructions (see that method's own docblock — one AI call
		// per approval, not a second reconciliation round trip) rather than
		// translating some pre-existing native-tags text: there is no such
		// text any more (`_agnosis_native_tags` itself was removed outright
		// in T0) — this is a fresh PROPOSAL generated from the artist's
		// title/excerpt/body content, already present in this same batched
		// prompt, not a translation of anything. $fields['tags']'s own
		// "text" below is therefore just a placeholder the instruction
		// explicitly overrides — never itself sent for translation.
		$tag_count             = PromptConfig::from_options()->tag_count;
		$primary_language_name = SubmissionTranslator::primary_language_name();
		$existing_tags          = PromptConfig::existing_tags();
		$existing_tags_note     = ! empty( $existing_tags )
			? '; when a concept matches one of these existing tags, use its EXACT text: ' . implode( ' | ', $existing_tags )
			: '';

		// Batched into ONE chat() call via translate_fields() — title,
		// excerpt, body, medium, and tags together — rather than one call
		// per field. This is the single AI call §7 of the design doc
		// accounts for per cross-language approval.
		$fields = array_filter(
			[
				'title'   => $source->post_title,
				'excerpt' => $source->post_excerpt,
				'body'    => $body_plain,
				'medium'  => $native_medium,
				'tags'    => '(n/a — see instruction above; generate from the TITLE/EXCERPT/BODY sections)',
			],
			static fn( $v ) => '' !== trim( (string) $v )
		);

		if ( empty( $fields ) ) {
			return null; // Nothing with any text content to translate.
		}

		// translate_fields() returns each field as a single STRING (it's built
		// for prose fields — title/excerpt/body/medium — and rejects an
		// array-typed value from the model as "not a plain string", dropping
		// it silently; see that method's own decode loop). Asking for a
		// " | "-delimited single line rather than a JSON array keeps this
		// call inside that existing string-only contract instead of widening
		// a shared method four other unrelated callers also depend on — the
		// same delimiter convention {existing_tags}/{medium_list} already use
		// throughout PromptConfig, so the model has already seen this exact
		// format elsewhere. Split back into a real array below, once
		// $translated comes back.
		$field_instructions = [
			'tags' => "Do NOT translate the line below — ignore its literal text entirely. Instead, propose 3–{$tag_count} tags in {$primary_language_name} for the artwork described in the TITLE/EXCERPT/BODY sections above, as a single line separated by \" | \" (no numbering, no quotes, no other punctuation){$existing_tags_note}.",
		];

		// $native_lang passed through explicitly (2026-07-23) — see
		// translate_fields()'s own docblock for why: without it, the model
		// has no signal for which language is actually "the source's own
		// dominant language" when more than one is present in the text (e.g.
		// a Latin quotation inside a Catalan poem), and can guess backward.
		$translated = $translator->translate_fields( $fields, $primary_lang, $field_instructions, $native_lang );
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

		// Split the " | "-delimited tags line back into a real array — see
		// $field_instructions['tags']'s own comment above for why the model
		// was asked for this shape rather than a JSON array. Tolerant of the
		// model using a bare "|" with no surrounding spaces despite the
		// instruction. Deliberately no length/junk gating here (TAG-REDESIGN.md's
		// gate — normalize_for_match(), junk rules — is T2's finalize_tags()
		// v2 concern, at ASSOCIATION time; this is still just acquisition).
		$tags = [];
		if ( '' !== trim( (string) ( $translated['tags'] ?? '' ) ) ) {
			$tags = array_values( array_unique( array_filter(
				array_map( 'sanitize_text_field', array_map( 'trim', explode( '|', $translated['tags'] ) ) ),
				static fn( string $t ): bool => '' !== $t
			) ) );
		}

		return [
			'display_title'    => $translated['title']   ?? $source->post_title,
			'excerpt'          => $translated['excerpt'] ?? $source->post_excerpt,
			'content'          => $content,
			'medium'           => trim( $translated['medium'] ?? $native_medium ),
			'tags'             => $tags,
			'native_excerpt'   => $source->post_excerpt,
			'native_body'      => $body_plain,
		];
	}

	/**
	 * Tag ASSOCIATION — TAG-REDESIGN.md T2's finalize_tags() v2, the single
	 * writer of post_tag term assignments (invariant 5: association happens
	 * only at approval, or an ordinary admin edit of an already-published
	 * primary post — never a pre-approval draft). Called from both
	 * finalize_publish() branches, after either the native-language
	 * translation call or the primary-language intake write has already put
	 * candidates in place — see those call sites' own comments for exactly
	 * which case supplies '_agnosis_tag_candidates' here.
	 *
	 * No term is EVER created here (invariant 1) — every candidate is either
	 * matched against the live primary vocabulary (by normalized name, via
	 * TagGate) and assigned by ID, or — if unmatched — recorded as an
	 * `_agnosis_tag_proposal` row for Admin\TagProposals (T2's other half)
	 * to later resolve. Association is always by ID (invariant 3);
	 * wp_set_object_terms() is never called with a tag NAME anywhere in this
	 * method.
	 *
	 * Replaces (not appends) the post's entire post_tag assignment every
	 * time it runs — wp_set_object_terms()'s own default $append=false, the
	 * same "full replace on every approval" behavior the medium block above
	 * already relies on (a resubmission's AI re-derives tags fresh from the
	 * current content, same as it re-derives medium). This is also exactly
	 * what Retag (§2) needs — "assign by ID (replace, not append)" — so
	 * ordinary approval and Retag share this one code path with no special
	 * casing (invariant 8).
	 *
	 * A post with NO candidates this pass (absent/empty '_agnosis_tag_candidates' —
	 * e.g. the T1→T2 deployment window the soundness review calls out, or a
	 * native submission whose translation call genuinely produced nothing)
	 * is left completely untouched: no wp_set_object_terms() call, no
	 * proposal-row clearing. This is deliberately more conservative than
	 * medium's own "always write, even to clear" pattern — silently wiping
	 * an already-tagged post's tags just because THIS particular
	 * resubmission's candidates happened to be empty would be actively
	 * destructive, not a neutral no-op the way an empty medium is.
	 *
	 * @param int $post_id The live post to associate tags onto.
	 */
	private function finalize_tags( int $post_id ): void {
		$raw = (string) get_post_meta( $post_id, '_agnosis_tag_candidates', true );
		if ( '' === $raw ) {
			return;
		}

		$candidates = json_decode( $raw, true );
		if ( ! is_array( $candidates ) || empty( $candidates ) ) {
			if ( ! is_array( $candidates ) ) {
				Logger::warning(
					sprintf( 'finalize_tags(#%d): _agnosis_tag_candidates did not decode to an array — leaving existing tags untouched.', $post_id ),
					'review'
				);
			}
			return;
		}

		// The actual gate → match → assign-by-ID → proposal-rows algorithm
		// lives on TagGate::associate() now, not here — invariant 8 requires
		// Retag to run "the same acquisition, gate, association ... code as
		// a real approval," so this method and Publishing\Retag::run() both
		// call that one shared implementation rather than each having their
		// own copy. See TagGate::associate()'s own docblock for the
		// gate/cap/replace-not-append details this used to document inline.
		$result = TagGate::associate( $post_id, $candidates );

		Logger::info(
			sprintf(
				'finalize_tags(#%d): %d matched (assigned by ID), %d new proposal(s) recorded, %d candidate(s) gated out or trimmed by cap.',
				$post_id,
				$result['matched'],
				$result['proposed'],
				$result['gated']
			),
			'review'
		);
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
