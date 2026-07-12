<?php
/**
 * Frontend shim for email action links.
 *
 * Artists receive one-click action links in notification emails.  Previously
 * those links pointed directly at REST endpoints:
 *
 *   /wp-json/agnosis/v1/review/{id}/approve?token=<token>
 *
 * Tokens in query strings appear in server access logs, HTTP Referer headers
 * sent to external resources, and browser history.  This class moves those
 * tokens off the REST layer:
 *
 *   /?agnosis_review=1&id={id}&action=approve&token=<token>   (email link, GET)
 *       → renders a confirm page with a single POST button (no action taken yet)
 *       → artist clicks the button → POST /?agnosis_review=1 (token in POST body)
 *       → server processes via rest_do_request() (no logged REST URL)
 *       → 302 → /?agnosis_result=approve                      (clean URL)
 *
 * The GET/POST split exists because corporate mail-security scanners (Outlook
 * SafeLinks, Mimecast, Proofpoint, etc.) prefetch links in incoming email to
 * scan them — issuing a GET, never a POST, and never clicking a button. Before
 * this split, that prefetch alone was enough to approve, reject, or trash
 * artwork, or consume a single-use token so the artist's real click showed
 * "link expired". See docs/security audit §2a.
 *
 * The token still appears only once in a URL — in the initial GET's server log
 * entry. The confirmation POST carries it only in the request body (a hidden
 * form field), never in a query string, so it is never logged a second time,
 * never forwarded to the REST access log, never in browser history after the
 * final redirect, and never in a Referer header (same-origin redirect).
 *
 * Final text edits on approve (2026-07-08): the approve confirm page shows the
 * post's title/excerpt/body as an editable form rather than a plain button.
 * post_title is always already in the artist's own language (dual-title
 * design, never AI-translated — see Compat\LinguaForge), so it's shown as-is.
 *
 * Native-language pipeline (agnosis-audit/NATIVE-LANGUAGE-PIPELINE.md,
 * Phases 1/3/5 — 2026-07-12/13): excerpt/body are also now shown as-is,
 * with no translation of any kind at this stage. The description AI writes
 * natively, in the artist's own language, at intake (Phase 1) — content at
 * rest here genuinely IS the artist's own words, not an AI-authored
 * primary-language draft needing back-translation to be readable. Nothing is
 * translated until `ReviewEndpoints::finalize_publish()` converts the FINAL
 * (possibly artist-edited) result to the site's primary language exactly
 * once, at actual publish time — strictly after this page has already been
 * shown and submitted (Phase 3). This class does not call the AI translator
 * at all any more; see get_display_text()'s docblock for what used to be
 * here and why it was removed outright rather than kept as a fallback
 * (Phase 5).
 *
 * If the artist changes anything, the edited excerpt/body are saved and
 * published in one call via the existing PUT /agnosis/v1/review/{id} route,
 * unchanged from whatever the artist typed. If nothing changed, the plain
 * POST /agnosis/v1/review/{id}/approve route runs exactly as before.
 *
 * Safeguard: if the submitted title or body is blank after trimming, the whole
 * approval is cancelled — nothing is published or changed, and the token is
 * NOT invalidated, so the artist can simply try again from the same email
 * link. This re-renders the same confirm form inline (never a redirect) so
 * the token never appears a second time in a URL — the same reasoning that
 * motivates the GET/POST split above.
 *
 * Post types (2026-07-08): the approve form's field set is CPT-aware
 * (APPROVE_FIELDS) — artwork gets title+excerpt+body, biography gets
 * title+body (no excerpt concept), event gets body only (title isn't
 * artist-editable anywhere else in the plugin either) — mirroring
 * Artist\ContentEditor::EDITABLE_FIELDS's own per-CPT restrictions. This was
 * fixed alongside ReviewEndpoints's matching artwork-only gate bug, which had
 * silently 404'd every biography/event approve/reject/save call regardless of
 * token validity.
 *
 * Token check ordering (fourth audit §3a, 2026-07-09): render_approve_confirm()
 * (GET) and handle_approve_submission() (POST) both call the shared
 * require_valid_token() gate — ReviewEndpoints::verify_token() — as their very
 * first step, before any draft content is displayed, any AI translate_text()
 * call is made, or any post meta is written. Previously the only real token
 * check on this path was inside the final rest_do_request() dispatch, well
 * after the GET path had already rendered the draft's title/excerpt/body and
 * the POST "edited" path had already regenerated and written
 * _agnosis_translated_title — a guessable sequential post ID plus any string
 * in the token slot was enough to read an unpublished submission and spend an
 * unauthenticated AI call.
 *
 * Hooks registered in Plugin::register_services() on 'template_redirect' (priority 1).
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\Artist\ApplicationBiography;

class ReviewConfirm {

	/**
	 * Which of title/excerpt/body are editable on the approve confirm form,
	 * per post type (2026-07-08 — generalised alongside the ReviewEndpoints
	 * artwork-only gate fix). Deliberately mirrors
	 * Artist\ContentEditor::EDITABLE_FIELDS's own per-CPT restrictions rather
	 * than inventing new rules: biography has no excerpt concept anywhere
	 * else in the plugin, and event's title is never artist-editable either
	 * (see that class's docblock for why). Falls back to `['body']` for any
	 * post type not listed — the one field every reviewable CPT has.
	 */
	private const APPROVE_FIELDS = [
		'agnosis_artwork'   => [ 'title', 'excerpt', 'body' ],
		'agnosis_biography' => [ 'title', 'body' ],
		'agnosis_event'     => [ 'body' ],
	];

	// -------------------------------------------------------------------------
	// Public hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Handle ?agnosis_review=1.
	 *
	 * On GET: renders a confirm page with a single POST button — no state
	 * changes yet. On POST: processes the token action and redirects to a
	 * clean confirmation URL.
	 *
	 * Must run on 'template_redirect' before WP attempts to load a template.
	 */
	public function handle_confirm(): void {
		$is_post = $this->is_post_request();

		// This branches to $_POST once the confirm button is clicked (§2a) —
		// there is no WP nonce here because the request is unauthenticated by
		// design (an email-link recipient with no WP session); the single-use
		// HMAC-style review token plays the nonce's role instead.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$source = $is_post ? $_POST : $_GET;

		if ( empty( $source['agnosis_review'] ) ) {
			return;
		}

		$id     = absint( wp_unslash( $source['id'] ?? 0 ) );
		$action = sanitize_key( wp_unslash( $source['action'] ?? '' ) );
		$token  = sanitize_text_field( wp_unslash( $source['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		$allowed = [ 'approve', 'reject', 'remove' ];

		if ( ! $id || ! $token || ! in_array( $action, $allowed, true ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// GET only renders the confirm page — a mail scanner prefetching this
		// URL gets a harmless page, not a state change. The token travels in
		// the confirm form's hidden POST field, never in the form's action URL.
		if ( ! $is_post ) {
			$this->render_confirm( $id, $action, $token );
			return;
		}

		// Approve carries its own (possibly edited) text fields and its own
		// blank-submission safeguard — handled separately from the plain
		// reject/remove POST below.
		if ( 'approve' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- token itself is the auth mechanism, see above.
			$this->handle_approve_submission( $id, $token, $source );
			return; // Always exits internally (publish, redirect, or re-render).
		}

		// Fetched before dispatch so the redirect can carry the content type even
		// after reject/remove trashes the post below (trashing doesn't touch
		// post_type) — lets handle_result() say "Biography discarded" instead of
		// a hardcoded "Submission discarded" that used to be accurate only
		// because this route was artwork-only.
		$post      = get_post( $id );
		$post_type = $post ? $post->post_type : '';

		// Map action to REST path.  Token travels in the POST body via
		// set_param(), so it never appears in a REST access-log URL.
		$path = ( 'remove' === $action )
			? '/agnosis/v1/removal/' . $id . '/confirm'
			: '/agnosis/v1/review/' . $id . '/' . $action;

		$rest_request = new \WP_REST_Request( 'POST', $path );
		$rest_request->set_param( 'token', $token );

		$response = rest_do_request( $rest_request );

		$this->redirect_result( $response->is_error() ? 'error' : $action, $post_type );
	}

	/**
	 * Redirect to the clean ?agnosis_result= confirmation URL, optionally
	 * carrying the content's post type so handle_result() can say "Biography
	 * published" / "Event removed" instead of always assuming artwork — see
	 * class docblock's 2026-07-08 post-types note. Always exits.
	 *
	 * $post_id (added alongside the "view it live" link below) is the FINAL
	 * post id — for a staged update this is the live post's id
	 * (ReviewEndpoints::finalize_publish()'s return value / the REST
	 * response's 'post_id'), never the now-deleted staging draft's — so the
	 * link this produces always resolves. Only meaningful for a successful
	 * 'approve'; omitted (0) for reject/remove/error, which have nothing to
	 * link to.
	 */
	private function redirect_result( string $result, string $post_type = '', int $post_id = 0 ): void {
		$args = [ 'agnosis_result' => $result ];
		if ( '' !== $post_type ) {
			$args['agnosis_type'] = $post_type;
		}
		if ( $post_id > 0 ) {
			$args['agnosis_post'] = $post_id;
		}
		wp_safe_redirect( add_query_arg( $args, home_url( '/' ) ) );
		exit;
	}

	/**
	 * Handle ?agnosis_result={action|error} — show a minimal confirmation page.
	 *
	 * This runs on the same 'template_redirect' hook (priority 1).  Because
	 * handle_confirm() always exits, only one of these two handlers fires per
	 * request.
	 */
	public function handle_result(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['agnosis_result'] ) ) {
			return;
		}
		$result    = sanitize_key( wp_unslash( $_GET['agnosis_result'] ) );
		$post_type = sanitize_key( wp_unslash( $_GET['agnosis_type'] ?? '' ) );
		$post_id   = absint( wp_unslash( $_GET['agnosis_post'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		[ $label, $message ] = $this->result_copy( $result, $post_type );
		$is_err = ! in_array( $result, [ 'approve', 'reject', 'remove' ], true );
		$status = $is_err ? 400 : 200;
		$icon   = $is_err ? '✕' : '✦';
		$color  = $is_err ? '#c0392b' : '#7c6af7';

		// "View it live" — only for a successful approve, and only when the
		// post genuinely resolves to a real, published permalink (never trust
		// the query string alone: a stale/crafted agnosis_post value on any
		// other result, or a post that's since been unpublished/deleted,
		// simply gets no button rather than a dead link).
		$view_link_html = '';
		if ( 'approve' === $result && $post_id > 0 ) {
			$live_post = get_post( $post_id );
			if ( $live_post instanceof \WP_Post && 'publish' === $live_post->post_status ) {
				$permalink = get_permalink( $post_id );
				if ( $permalink ) {
					$view_link_html = sprintf(
						'<p style="margin:0 0 24px;"><a href="%1$s" style="display:inline-block;background:#7c6af7;color:#fff;border-radius:6px;padding:12px 28px;font-size:16px;text-decoration:none;">%2$s</a></p>',
						esc_url( $permalink ),
						esc_html__( 'View it live', 'agnosis' )
					);
				}
			}
		}

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:34px;color:%1$s;margin:0 0 16px;">%2$s</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%3$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 32px;">%4$s</p>'
			. '%5$s'
			. '<a href="%6$s" style="color:%1$s;font-size:16px;text-decoration:none;">&larr; %7$s</a>'
			. '</div>',
			esc_attr( $color ),
			esc_html( $icon ),
			esc_html( $label ),
			esc_html( $message ),
			$view_link_html, // Built entirely from esc_url()/esc_html() pieces above.
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);

		wp_die( $html, esc_html( $label ), [ 'response' => $status ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is fully escaped above.
	}

	/**
	 * Label + message for the ?agnosis_result= page, tailored per content type
	 * and result kind. Written out per combination rather than templated with
	 * content_label() — "published to the gallery" is accurate for artwork but
	 * wrong for a biography or event, neither of which lives in a gallery — so
	 * a generic %s substitution would read oddly for those two.
	 *
	 * $post_type defaults to '' for any older in-flight redirect issued before
	 * the 2026-07-08 fix added the `agnosis_type` param — the `default` arm
	 * below (and every unmatched $post_type) falls back to the original
	 * artwork wording, so an old link mid-flight when this ships still shows
	 * sensible copy.
	 *
	 * @return array{0:string,1:string} [label, message]
	 */
	private function result_copy( string $result, string $post_type ): array {
		return match ( true ) {
			'approve' === $result && 'agnosis_biography' === $post_type => [ __( 'Biography published', 'agnosis' ), __( 'Your biography has been published.', 'agnosis' ) ],
			'approve' === $result && 'agnosis_event' === $post_type     => [ __( 'Event published', 'agnosis' ), __( 'The event has been published.', 'agnosis' ) ],
			'approve' === $result                                      => [ __( 'Artwork published', 'agnosis' ), __( 'The artwork has been published to the gallery.', 'agnosis' ) ],
			'reject' === $result                                       => [ __( 'Submission discarded', 'agnosis' ), __( 'The submission has been discarded.', 'agnosis' ) ],
			'remove' === $result && 'agnosis_biography' === $post_type  => [ __( 'Biography removed', 'agnosis' ), __( 'Your biography has been removed.', 'agnosis' ) ],
			'remove' === $result && 'agnosis_event' === $post_type      => [ __( 'Event removed', 'agnosis' ), __( 'The event has been removed.', 'agnosis' ) ],
			'remove' === $result                                       => [ __( 'Artwork removed', 'agnosis' ), __( 'Your artwork has been removed from the gallery.', 'agnosis' ) ],
			default                                                     => [ __( 'Link expired or already used', 'agnosis' ), __( 'This link may have already been used or may have expired. Please log in to manage your submissions.', 'agnosis' ) ],
		};
	}

	// -------------------------------------------------------------------------
	// Approve — final text edits + blank-submission safeguard
	// -------------------------------------------------------------------------

	/**
	 * Handle the POST from the approve confirm form.
	 *
	 * The form always carries hidden `orig_title`/`orig_excerpt`/`orig_body`
	 * fields holding exactly what render_approve_confirm() showed, so this can
	 * tell whether the artist actually changed anything without re-deriving or
	 * re-translating anything itself.
	 *
	 * @param array<string,mixed> $source Sanitized $_POST superglobal.
	 */
	private function handle_approve_submission( int $id, string $token, array $source ): void {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, ReviewEndpoints::REVIEWABLE_POST_TYPES, true ) ) {
			wp_safe_redirect( add_query_arg( 'agnosis_result', 'error', home_url( '/' ) ) );
			exit;
		}

		// Fourth audit §3a: this must run BEFORE anything below — the translated-
		// title regeneration further down calls translate_text() (unauthenticated
		// AI spend) and writes _agnosis_translated_title, and both used to happen
		// before the only token check on this path (inside the final
		// rest_do_request() dispatch). A forged POST with a garbage token could
		// already reach and complete both side effects. Always exits on failure.
		$this->require_valid_token( $id, $token, $post->post_type );

		// No ?? fallback needed: the gate above already guarantees $post->post_type
		// is one of ReviewEndpoints::REVIEWABLE_POST_TYPES, and APPROVE_FIELDS has
		// an entry for each of those three keys — PHPStan proves the offset always
		// exists here, so an unreachable fallback would just be dead code.
		$fields      = self::APPROVE_FIELDS[ $post->post_type ];
		$has_title   = in_array( 'title', $fields, true );
		$has_excerpt = in_array( 'excerpt', $fields, true );

		// Fields this content type doesn't expose for editing are simply held at
		// their current post value on both sides of the diff below — that makes
		// them permanently "unchanged" without any special-casing further down.
		$title   = $has_title   ? sanitize_text_field( wp_unslash( $source['title'] ?? '' ) )      : $post->post_title;
		$excerpt = $has_excerpt ? sanitize_textarea_field( wp_unslash( $source['excerpt'] ?? '' ) ) : $post->post_excerpt;
		$body    = sanitize_textarea_field( wp_unslash( $source['body'] ?? '' ) );

		$orig_title   = $has_title   ? sanitize_text_field( wp_unslash( $source['orig_title'] ?? '' ) )      : $post->post_title;
		$orig_excerpt = $has_excerpt ? sanitize_textarea_field( wp_unslash( $source['orig_excerpt'] ?? '' ) ) : $post->post_excerpt;
		$orig_body    = sanitize_textarea_field( wp_unslash( $source['orig_body'] ?? '' ) );

		$edited = ( $has_title && trim( $title ) !== trim( $orig_title ) )
			|| ( $has_excerpt && trim( $excerpt ) !== trim( $orig_excerpt ) )
			|| ( trim( $body ) !== trim( $orig_body ) );

		if ( ! $edited ) {
			// Nothing changed — identical to the pre-existing plain approve
			// path. Deliberately checked, and dispatched, BEFORE the blank-field
			// safeguard below: a request that never touched title/excerpt/body at
			// all (a stale/simplified email link, or a request replaying the
			// pre-edit-form contract) must still reach the real token check
			// inside ReviewEndpoints::approve() rather than being intercepted by
			// a safeguard meant for an artist who actively cleared a field. A
			// draft whose body was already blank before this request is not this
			// safeguard's concern — nothing changed, so there is nothing to
			// protect against here.
			$rest_request = new \WP_REST_Request( 'POST', '/agnosis/v1/review/' . $id . '/approve' );
			$rest_request->set_param( 'token', $token );
			$response = rest_do_request( $rest_request );

			// Extra structured fields (portfolio link / event date-time-location-
			// timezone-address) are handled entirely outside the title/excerpt/body
			// diff above — see sync_extra_fields()'s docblock for why they're always
			// re-applied from the submitted form rather than gated on $edited.
			//
			// Patch 18 ("true staging"): $id may be a pending-update staging
			// draft that ReviewEndpoints::finalize_publish() just deleted,
			// copying its fields onto a DIFFERENT, already-published post
			// instead. That final post's ID comes back as 'post_id' in the
			// REST response — sync_extra_fields() must target THAT post, not
			// the now-deleted $id, or it silently writes nothing. Also carried
			// into redirect_result() so the confirmation page can link
			// straight to the live post instead of just the site homepage.
			$final_id = $id;
			if ( ! $response->is_error() ) {
				$data     = $response->get_data();
				$final_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : $id;
				$this->sync_extra_fields( $final_id, $post->post_type, $source );
			}

			$this->redirect_result( $response->is_error() ? 'error' : 'approve', $post->post_type, $final_id );
			return;
		}

		// Safeguard: only reachable once we know something was actually edited —
		// a blank title (when editable) or a blank body at that point means the
		// artist edited a field down to nothing, so the whole approval is
		// cancelled. Typed values (including whichever field IS blank) are
		// preserved so they don't lose the other edits, and the token is left
		// untouched (no delete_post_meta anywhere on this path) so the same
		// email link works again.
		if ( ( $has_title && '' === trim( $title ) ) || '' === trim( $body ) ) {
			$error = $has_title
				? __( 'Title and full text cannot be empty. Fill them back in, or use "Discard" from the original email instead.', 'agnosis' )
				: __( 'The full text cannot be empty. Fill it back in, or use "Discard" from the original email instead.', 'agnosis' );

			$this->render_approve_confirm(
				$id,
				$token,
				array_merge(
					[
						'title'   => $title,
						'excerpt' => $excerpt,
						'body'    => $body,
					],
					// Preserve whatever the artist typed into the extra structured
					// fields too, so a blank-title/body retry doesn't reset a
					// portfolio-link correction or event detail edit made in the
					// same submission.
					$this->extra_prefill_from_source( $post->post_type, $source )
				),
				$error
			);
			return;
		}

		// Something changed. post_title is never translated for any CPT — it
		// stays the artist's own words verbatim everywhere in this plugin
		// (PostCreator::create_post()'s $original_title is passed through
		// unchanged regardless of post type), so it is passed straight through
		// exactly as always.
		//
		// Native-language pipeline (Phase 3, 2026-07-12 — agnosis-audit/
		// NATIVE-LANGUAGE-PIPELINE.md §4c/§4e): excerpt/body are no longer
		// forward-translated to primary language here at all — this class no
		// longer needs to know or care what the site's primary language is.
		// They are saved in whatever language the artist is actually editing
		// in (native at rest, per Phase 1) exactly as submitted, and
		// ReviewEndpoints::finalize_publish() is what translates the FINAL
		// text — this edit, or the untouched original if nothing changed — to
		// primary exactly once, at the moment of actual publish. That same
		// method also regenerates `_agnosis_translated_title` centrally for
		// every approval that needs it, so the title-specific regeneration
		// this branch used to perform here is gone too — one call, one place,
		// instead of a small one here plus a bigger one there.
		$rest_request = new \WP_REST_Request( 'PUT', '/agnosis/v1/review/' . $id );
		$rest_request->set_param( 'token', $token );
		$rest_request->set_param( 'title', $title );
		$rest_request->set_param( 'excerpt', $excerpt );
		$rest_request->set_param( 'body', $body );
		$rest_request->set_param( 'publish', true );

		$response = rest_do_request( $rest_request );

		// See the unedited-fast-path branch above for why this must target
		// the REST response's returned 'post_id', not $id — patch 18. Also
		// carried into redirect_result() for the "view it live" link.
		$final_id = $id;
		if ( ! $response->is_error() ) {
			$data     = $response->get_data();
			$final_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : $id;
			$this->sync_extra_fields( $final_id, $post->post_type, $source );
		}

		$this->redirect_result( $response->is_error() ? 'error' : 'approve', $post->post_type, $final_id );
	}

	// -------------------------------------------------------------------------
	// Extra structured fields — portfolio link (biography), event details (event)
	// -------------------------------------------------------------------------

	/**
	 * Apply the approve form's extra, non-translatable structured fields —
	 * added 2026-07-10 alongside the title/excerpt/body edits above, but
	 * deliberately NOT folded into that $edited diff-and-gate logic: these
	 * fields are structured metadata (a URL, a date, a timezone identifier),
	 * not language content, so there is nothing to translate and no reason to
	 * skip re-applying them just because title/excerpt/body happened to be
	 * unchanged. Always reads straight from the submitted form and re-applies
	 * it — called from both branches of handle_approve_submission() (the
	 * unedited fast path and the edited/translated path), after the
	 * publish/save REST call succeeds, so either path ends up with identical,
	 * correct metadata regardless of which one a given submission took.
	 *
	 * @param array<string, mixed> $source Raw $_POST for this request (see handle_confirm()).
	 */
	private function sync_extra_fields( int $id, string $post_type, array $source ): void {
		if ( 'agnosis_biography' === $post_type ) {
			$this->sync_portfolio_embed( $id, $source );
			$this->sync_social_links( $id, $source );
			return;
		}

		if ( 'agnosis_event' === $post_type ) {
			$this->sync_event_fields( $id, $source );
		}
	}

	/**
	 * Re-apply the biography approve form's three optional social-link
	 * fields. Unlike the portfolio link (sync_portfolio_embed()), these never
	 * become embed blocks and are never vetted by EmbedPolicy — they're
	 * plain outbound `<a href>` links rendered as icons by
	 * Publishing\SocialLinks, not remote content pulled into the page, so
	 * there's nothing here that needs a fetch/AI trust check. Always
	 * re-applies all three straight from the submitted form, same
	 * always-write convention as sync_event_fields() below — an artist
	 * clearing a field is exactly as valid an edit as changing it.
	 *
	 * @param array<string, mixed> $source Raw $_POST for this request (see handle_confirm()).
	 */
	private function sync_social_links( int $id, array $source ): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$raw = (string) wp_unslash( $source[ "social_url_{$i}" ] ?? '' );
			$url = '' !== trim( $raw ) ? esc_url_raw( trim( $raw ) ) : '';
			update_post_meta( $id, "_agnosis_biography_social_url_{$i}", $url );
		}
	}

	/**
	 * Re-apply the biography approve form's portfolio-link field.
	 *
	 * `_agnosis_biography_portfolio_url`/`_agnosis_biography_portfolio_embedded`
	 * (ApplicationBiography::on_artist_admitted()) are the source of truth. Two
	 * independent things happen here, deliberately kept separate:
	 *
	 *   1. The embed is always re-synced into post_content, whether or not the
	 *      URL changed. ReviewEndpoints::save() (the PUT path a body edit goes
	 *      through) rebuilds post_content from the submitted body text plus any
	 *      LEADING image/gallery blocks only — a trailing wp:embed block is not
	 *      part of that reconstruction, so a plain body-only edit used to
	 *      silently drop an already-approved portfolio embed. Stripping
	 *      whatever trailing embed is currently there and re-adding it if it's
	 *      still supposed to be there fixes that regardless of what else the
	 *      artist edited.
	 *   2. EmbedPolicy::is_allowed() — a network fetch and, if AI review is
	 *      enabled, an AI call — is only re-run when the URL actually changed
	 *      from the baseline. Running it unconditionally on every single
	 *      biography approval (whether or not this field was touched) would
	 *      spend that cost needlessly on the common case.
	 *
	 * @param array<string, mixed> $source Raw $_POST for this request (see handle_confirm()).
	 */
	private function sync_portfolio_embed( int $id, array $source ): void {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$baseline_url      = (string) get_post_meta( $id, '_agnosis_biography_portfolio_url', true );
		$baseline_embedded = '1' === (string) get_post_meta( $id, '_agnosis_biography_portfolio_embedded', true );

		$raw         = (string) wp_unslash( $source['portfolio_url'] ?? $baseline_url );
		$url         = '' !== trim( $raw ) ? esc_url_raw( trim( $raw ) ) : '';
		$url_changed = $url !== $baseline_url;

		// Reason tracking (2026-07-10) — feeds the same generic
		// `_agnosis_dropped_links` notice Notification::build_email() reads for
		// every post type. Only re-derived when the URL actually changed
		// (EmbedPolicy::is_allowed() is only re-run then, per the cost note
		// above); otherwise the previously-recorded reason is carried forward
		// unchanged, since nothing new was actually checked.
		$reason = '';
		if ( $url_changed ) {
			$policy   = new EmbedPolicy();
			$approved = '' !== $url && $policy->is_allowed( $url );
			$reason   = $approved ? '' : $policy->last_reason();
		} else {
			$approved = '' !== $url && $baseline_embedded;
			if ( ! $approved && '' !== $url ) {
				$existing_raw   = (string) get_post_meta( $id, '_agnosis_dropped_links', true );
				$existing_links = $existing_raw ? (array) json_decode( $existing_raw, true ) : [];
				$reason         = (string) ( $existing_links[0]['reason'] ?? '' );
			}
		}

		$content_without_embed = $this->strip_trailing_embed_block( $post->post_content );
		$new_content           = $approved
			? rtrim( $content_without_embed ) . "\n\n" . ApplicationBiography::build_embed_block( $url )
			: $content_without_embed;

		if ( $new_content !== $post->post_content ) {
			wp_update_post( [ 'ID' => $id, 'post_content' => $new_content ] );
		}

		if ( $url_changed || $approved !== $baseline_embedded ) {
			update_post_meta( $id, '_agnosis_biography_portfolio_url', $url );
			update_post_meta( $id, '_agnosis_biography_portfolio_embedded', $approved ? '1' : '0' );
		}

		update_post_meta(
			$id,
			'_agnosis_dropped_links',
			wp_json_encode( ( '' !== $url && ! $approved ) ? [ [ 'url' => $url, 'reason' => $reason ] ] : [] )
		);
	}

	/**
	 * Strip a trailing `wp:embed` block (with any surrounding whitespace) from
	 * the end of post content — biography posts have at most one (the
	 * portfolio link), always appended last by
	 * ApplicationBiography::build_content()/build_embed_block().
	 */
	private function strip_trailing_embed_block( string $content ): string {
		$stripped = preg_replace( '/\s*<!-- wp:embed\b.*?<!-- \/wp:embed -->\s*$/s', '', $content );

		return rtrim( null !== $stripped ? $stripped : $content );
	}

	/**
	 * Re-apply the event approve form's structured fields directly to post
	 * meta — no translation involved (unlike title/excerpt/body), so this is
	 * a plain sanitize-and-write, not routed through the REST review endpoint.
	 *
	 * Date and hour are submitted as two separate inputs (matching how an
	 * artist actually thinks about "the date" vs. "what time"), but stored
	 * combined in the single `_agnosis_event_date` meta exactly as
	 * Pipeline::extract_event_fields()/PostCreator have always stored it (e.g.
	 * "2026-08-15T19:00") — every other reader of that meta (render_event_date(),
	 * order_events_archive(), ContentEditor's event_date field) already expects
	 * that one combined format, so this does not introduce a second shape.
	 *
	 * Deliberately lenient: an invalid date or an unrecognised timezone
	 * identifier is silently ignored (the previous value is kept) rather than
	 * blocking the whole approval — these are supplementary metadata, not the
	 * core content the artist is here to approve, and a mistake here is still
	 * correctable afterwards via Artist\ContentEditor's own event_date/
	 * event_timezone fields once the event is published.
	 *
	 * @param array<string, mixed> $source Raw $_POST for this request (see handle_confirm()).
	 */
	private function sync_event_fields( int $id, array $source ): void {
		$baseline_datetime = (string) get_post_meta( $id, '_agnosis_event_date', true );
		[ $baseline_date, $baseline_hour ] = $this->split_event_datetime( $baseline_datetime );

		$date = sanitize_text_field( wp_unslash( $source['event_date'] ?? $baseline_date ) );
		$hour = sanitize_text_field( wp_unslash( $source['event_hour'] ?? $baseline_hour ) );

		$combined = $this->combine_event_datetime( $date, $hour );
		if ( '' === $combined || false !== strtotime( $combined ) ) {
			update_post_meta( $id, '_agnosis_event_date', $combined );
		}

		$location = (string) get_post_meta( $id, '_agnosis_event_location', true );
		update_post_meta( $id, '_agnosis_event_location', sanitize_text_field( wp_unslash( $source['event_location'] ?? $location ) ) );

		$address = (string) get_post_meta( $id, '_agnosis_event_address', true );
		update_post_meta( $id, '_agnosis_event_address', sanitize_text_field( wp_unslash( $source['event_address'] ?? $address ) ) );

		$timezone = sanitize_text_field( wp_unslash( $source['event_timezone'] ?? '' ) );
		if ( '' === $timezone || in_array( $timezone, \DateTimeZone::listIdentifiers(), true ) ) {
			update_post_meta( $id, '_agnosis_event_timezone', $timezone );
		}
	}

	/**
	 * Split a stored `_agnosis_event_date` value into [date, hour] for form display.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function split_event_datetime( string $value ): array {
		if ( '' === $value ) {
			return [ '', '' ];
		}

		if ( str_contains( $value, 'T' ) ) {
			[ $date, $time ] = explode( 'T', $value, 2 );
			return [ $date, substr( $time, 0, 5 ) ]; // HH:MM — drop a seconds component if present.
		}

		return [ $value, '' ];
	}

	/** Combine a [date, hour] form submission back into one `_agnosis_event_date` value. */
	private function combine_event_datetime( string $date, string $hour ): string {
		if ( '' === $date ) {
			return '';
		}

		return '' !== $hour ? $date . 'T' . $hour : $date;
	}

	/**
	 * Read the extra structured fields straight off a submitted form (raw,
	 * per-post-type) for prefill on a blank-title/body validation retry — see
	 * handle_approve_submission()'s safeguard branch.
	 *
	 * @param array<string, mixed> $source Raw $_POST for this request (see handle_confirm()).
	 * @return array<string, string>
	 */
	private function extra_prefill_from_source( string $post_type, array $source ): array {
		if ( 'agnosis_biography' === $post_type ) {
			return [
				'portfolio_url' => sanitize_text_field( wp_unslash( $source['portfolio_url'] ?? '' ) ),
				'social_url_1'  => sanitize_text_field( wp_unslash( $source['social_url_1'] ?? '' ) ),
				'social_url_2'  => sanitize_text_field( wp_unslash( $source['social_url_2'] ?? '' ) ),
				'social_url_3'  => sanitize_text_field( wp_unslash( $source['social_url_3'] ?? '' ) ),
			];
		}

		if ( 'agnosis_event' === $post_type ) {
			return [
				'event_date'     => sanitize_text_field( wp_unslash( $source['event_date']     ?? '' ) ),
				'event_hour'     => sanitize_text_field( wp_unslash( $source['event_hour']     ?? '' ) ),
				'event_location' => sanitize_text_field( wp_unslash( $source['event_location'] ?? '' ) ),
				'event_address'  => sanitize_text_field( wp_unslash( $source['event_address']  ?? '' ) ),
				'event_timezone' => sanitize_text_field( wp_unslash( $source['event_timezone'] ?? '' ) ),
			];
		}

		return [];
	}

	/**
	 * Render the extra structured-field inputs appended to the approve form,
	 * after the title/excerpt/body fields — biography gets a portfolio link
	 * plus three optional social links, event gets date/hour/location/
	 * timezone/address. Returns '' for artwork (no extra fields defined).
	 *
	 * @param array<string, string> $prefill Same $prefill passed to render_approve_confirm().
	 */
	private function render_extra_fields_html( string $post_type, int $post_id, array $prefill ): string {
		if ( 'agnosis_biography' === $post_type ) {
			return $this->render_portfolio_field( $post_id, $prefill ) . $this->render_social_link_fields( $post_id, $prefill );
		}

		if ( 'agnosis_event' === $post_type ) {
			return $this->render_event_fields_html( $post_id, $prefill );
		}

		return '';
	}

	/**
	 * @param array<string, string> $prefill Same $prefill passed to render_approve_confirm().
	 */
	private function render_portfolio_field( int $post_id, array $prefill ): string {
		$baseline = (string) get_post_meta( $post_id, '_agnosis_biography_portfolio_url', true );
		$value    = $prefill['portfolio_url'] ?? $baseline;

		$label_style = 'display:block;font-size:14px;color:#888;margin:0 0 4px;';
		$input_style = 'width:100%;box-sizing:border-box;padding:10px;font-size:16px;font-family:inherit;border:1px solid #ddd;border-radius:6px;margin:0 0 16px;';

		return '<label style="' . esc_attr( $label_style ) . '">' . esc_html__( 'Portfolio link', 'agnosis' ) . '</label>'
			. '<input type="url" name="portfolio_url" value="' . esc_attr( $value ) . '" placeholder="https://" style="' . esc_attr( $input_style ) . '">';
	}

	/**
	 * Three optional social-profile link inputs — auto-detected (facebook,
	 * instagram, bandcamp, …) and rendered as an icon row on the published
	 * biography page (see Publishing\SocialLinks, Artist\Profile's
	 * agnosis/biography-social-links block). Deliberately no platform-name
	 * label per field — the artist just pastes a URL, detection happens at
	 * render time from the URL itself, nothing to pick from here.
	 *
	 * @param array<string, string> $prefill Same $prefill passed to render_approve_confirm().
	 */
	private function render_social_link_fields( int $post_id, array $prefill ): string {
		$label_style = 'display:block;font-size:14px;color:#888;margin:0 0 4px;';
		$input_style = 'width:100%;box-sizing:border-box;padding:10px;font-size:16px;font-family:inherit;border:1px solid #ddd;border-radius:6px;margin:0 0 16px;';

		$html = '';
		for ( $i = 1; $i <= 3; $i++ ) {
			$key      = "social_url_{$i}";
			$baseline = (string) get_post_meta( $post_id, "_agnosis_biography_social_url_{$i}", true );
			$value    = $prefill[ $key ] ?? $baseline;

			$html .= '<label style="' . esc_attr( $label_style ) . '">'
				. sprintf(
					/* translators: %d is the field number (1-3) — each field is otherwise identical, no platform is required. */
					esc_html__( 'Social link %d (optional)', 'agnosis' ),
					$i
				)
				. '</label>'
				. '<input type="url" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" placeholder="https://" style="' . esc_attr( $input_style ) . '">';
		}

		return $html;
	}

	/**
	 * @param array<string, string> $prefill Same $prefill passed to render_approve_confirm().
	 */
	private function render_event_fields_html( int $post_id, array $prefill ): string {
		$baseline_datetime = (string) get_post_meta( $post_id, '_agnosis_event_date', true );
		[ $baseline_date, $baseline_hour ] = $this->split_event_datetime( $baseline_datetime );

		$baseline_location = (string) get_post_meta( $post_id, '_agnosis_event_location', true );
		$baseline_address  = (string) get_post_meta( $post_id, '_agnosis_event_address', true );
		$baseline_timezone = (string) get_post_meta( $post_id, '_agnosis_event_timezone', true );

		$date     = $prefill['event_date']     ?? $baseline_date;
		$hour     = $prefill['event_hour']     ?? $baseline_hour;
		$location = $prefill['event_location'] ?? $baseline_location;
		$address  = $prefill['event_address']  ?? $baseline_address;
		$timezone = $prefill['event_timezone'] ?? $baseline_timezone;

		$label_style = 'display:block;font-size:14px;color:#888;margin:0 0 4px;';
		$input_style = 'width:100%;box-sizing:border-box;padding:10px;font-size:16px;font-family:inherit;border:1px solid #ddd;border-radius:6px;margin:0 0 16px;';
		$half_style  = 'width:100%;box-sizing:border-box;padding:10px;font-size:16px;font-family:inherit;border:1px solid #ddd;border-radius:6px;';

		return '<div style="display:flex;gap:12px;margin:0 0 16px;">'
			. '<div style="flex:1;"><label style="' . esc_attr( $label_style ) . '">' . esc_html__( 'Date', 'agnosis' ) . '</label>'
			. '<input type="date" name="event_date" value="' . esc_attr( $date ) . '" style="' . esc_attr( $half_style ) . '"></div>'
			. '<div style="flex:1;"><label style="' . esc_attr( $label_style ) . '">' . esc_html__( 'Hour', 'agnosis' ) . '</label>'
			. '<input type="time" name="event_hour" value="' . esc_attr( $hour ) . '" style="' . esc_attr( $half_style ) . '"></div>'
			. '</div>'
			. '<label style="' . esc_attr( $label_style ) . '">' . esc_html__( 'Location', 'agnosis' ) . '</label>'
			. '<input type="text" name="event_location" value="' . esc_attr( $location ) . '" style="' . esc_attr( $input_style ) . '">'
			. '<label style="' . esc_attr( $label_style ) . '">' . esc_html__( 'Address', 'agnosis' ) . '</label>'
			. '<input type="text" name="event_address" value="' . esc_attr( $address ) . '" style="' . esc_attr( $input_style ) . '">'
			. '<label style="' . esc_attr( $label_style ) . '">' . esc_html__( 'Timezone', 'agnosis' ) . '</label>'
			. '<select name="event_timezone" style="' . esc_attr( $input_style ) . '">' . $this->timezone_options_html( $timezone ) . '</select>';
	}

	/**
	 * `<option>`/`<optgroup>` markup for every IANA timezone identifier,
	 * grouped by region (fifth/sixth audit §2b). Previously this was a free
	 * `<input type="text">` with an "Europe/Madrid" placeholder — validated
	 * server-side against `DateTimeZone::listIdentifiers()` in
	 * sync_event_fields() below, but silently discarded (left unchanged)
	 * when invalid, with no indication to the artist that what they typed
	 * ("CET", "GMT+2", "Madrid" — all plausible things a non-technical person
	 * types) didn't stick. A `<select>` removes the whole failure class: only
	 * a real identifier can ever be submitted.
	 *
	 * @param string $selected Currently-set (or prefilled) identifier, may be ''.
	 */
	private function timezone_options_html( string $selected ): string {
		$grouped = [];
		foreach ( \DateTimeZone::listIdentifiers() as $identifier ) {
			$region              = str_contains( $identifier, '/' ) ? strstr( $identifier, '/', true ) : __( 'Other', 'agnosis' );
			$grouped[ $region ][] = $identifier;
		}
		ksort( $grouped );

		$html = '<option value="">' . esc_html__( '— Not set —', 'agnosis' ) . '</option>';
		foreach ( $grouped as $region => $identifiers ) {
			$html .= '<optgroup label="' . esc_attr( (string) $region ) . '">';
			foreach ( $identifiers as $identifier ) {
				$html .= '<option value="' . esc_attr( $identifier ) . '"' . selected( $selected, $identifier, false ) . '>' . esc_html( $identifier ) . '</option>';
			}
			$html .= '</optgroup>';
		}

		return $html;
	}

	/**
	 * Shared read-only token gate for the approve confirm form (fourth audit
	 * §3a) — the same `hash_equals()` + expiry check
	 * `ReviewEndpoints::check_access()` already performs for the REST layer,
	 * exposed via `ReviewEndpoints::verify_token()` (public static, pure) so
	 * there is exactly one authoritative implementation rather than a second
	 * copy that could drift out of sync with it.
	 *
	 * Called at the very top of both render_approve_confirm() (GET) and
	 * handle_approve_submission() (POST) — before any draft content is
	 * rendered, any AI translation call is made, or any post meta is written.
	 * On failure, redirects to the same `?agnosis_result=error` page a bad
	 * token already produces later via the REST dispatch, so the visible
	 * behavior for an invalid token is unchanged from the artist's/attacker's
	 * perspective — only how early it is rejected changes. Always exits.
	 */
	private function require_valid_token( int $id, string $token, string $post_type ): void {
		if ( true !== ReviewEndpoints::verify_token( $id, $token ) ) {
			$this->redirect_result( 'error', $post_type );
		}
	}

	/**
	 * Token gate for the GET reject/remove confirm page (fifth audit §2e).
	 *
	 * Mirrors require_valid_token()'s approve-only gate but dispatches to the
	 * token store the given action actually uses: reject shares the review
	 * token approve uses (ReviewEndpoints::verify_token(), same
	 * `_agnosis_review_token` meta), while remove uses its own
	 * `_agnosis_removal_token` (RemovalEndpoints::verify_token()). Always
	 * exits on failure — redirects to the same `?agnosis_result=error` page
	 * an invalid token already produced later via the REST dispatch, so the
	 * only change is how early it's shown.
	 */
	private function require_valid_action_token( int $id, string $action, string $token, string $post_type ): void {
		$result = 'remove' === $action
			? RemovalEndpoints::verify_token( $id, $token )
			: ReviewEndpoints::verify_token( $id, $token );

		if ( true !== $result ) {
			$this->redirect_result( 'error', $post_type );
		}
	}

	/**
	 * Return [excerpt, body] for display on the approve confirm form.
	 *
	 * Native-language pipeline (agnosis-audit/NATIVE-LANGUAGE-PIPELINE.md):
	 * a submission's excerpt/body are written in the artist's own language at
	 * intake (Phase 1) and stay that way at rest until
	 * `ReviewEndpoints::finalize_publish()` translates the final, possibly
	 * artist-edited result to the site's primary language exactly once, at
	 * actual publish time — which happens strictly AFTER this confirm page is
	 * ever shown (Phase 3). So by the time an artist sees this form, there is
	 * nothing left to translate: the content is already in their own
	 * language, verbatim.
	 *
	 * Phase 5 (2026-07-13): this used to back-translate a genuinely
	 * primary-language excerpt/body into the artist's language here, on
	 * demand, cached in a now-deleted `_agnosis_review_backtranslation`
	 * postmeta entry — necessary before Phase 1 existed, since content really
	 * was primary-language at rest at this stage back then. Deleted outright
	 * rather than kept as a fallback: the only posts it could still matter
	 * for are drafts created before Phase 1 shipped (2026-07-12) whose
	 * review-link token (7-day default expiry) hadn't yet lapsed — a
	 * shrinking, self-resolving edge case, not worth carrying dead
	 * translation machinery for.
	 *
	 * @return array{0:string,1:string}
	 */
	private function get_display_text( \WP_Post $post ): array {
		return [ $post->post_excerpt, wp_strip_all_tags( (string) $post->post_content ) ];
	}

	// -------------------------------------------------------------------------
	// Confirm interstitial (GET) — no state change, single POST button
	// -------------------------------------------------------------------------

	/**
	 * Render a "are you sure" page with a single POST button for a validated
	 * (but not yet executed) action. Reached only via GET — the button's form
	 * POSTs the id/action/token back as hidden fields so the token never
	 * appears in the form's action URL.
	 *
	 * @param array<string,string> $prefill Retry values after a failed approve
	 *                                      submission (see handle_approve_submission()).
	 *                                      Empty on a normal first-visit GET.
	 * @param string                $error  Inline error message for a retry. Empty otherwise.
	 */
	private function render_confirm( int $id, string $action, string $token, array $prefill = [], string $error = '' ): void {
		if ( 'approve' === $action ) {
			$this->render_approve_confirm( $id, $token, $prefill, $error );
			return;
		}

		// fifth audit §2e: reject/remove used to render this generic "are you
		// sure?" page for ANY token, valid or not — an artist with an expired
		// or already-used link only discovered that after clicking the button
		// and hitting the REST layer's own check. require_valid_token() already
		// exists for approve; the same one-step-earlier gate now applies here,
		// dispatched to the right token store per action (reject shares the
		// review token approve uses; remove has its own removal token).
		$post      = get_post( $id );
		$post_type = $post ? $post->post_type : '';
		$this->require_valid_action_token( $id, $action, $token, $post_type );

		$prompts = [
			'reject'  => [
				__( 'Discard this submission?', 'agnosis' ),
				__( 'This will permanently discard the submission — it will not be published.', 'agnosis' ),
				__( 'Yes, discard it', 'agnosis' ),
			],
			'remove'  => [
				__( 'Remove this artwork?', 'agnosis' ),
				__( 'This will remove the published artwork from the gallery.', 'agnosis' ),
				__( 'Yes, remove it', 'agnosis' ),
			],
		];

		[ $title, $description, $button ] = $prompts[ $action ];

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:34px;color:#7c6af7;margin:0 0 16px;">✦</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%1$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 32px;">%2$s</p>'
			. '<form method="post" action="%3$s">'
			. '<input type="hidden" name="agnosis_review" value="1">'
			. '<input type="hidden" name="id" value="%4$s">'
			. '<input type="hidden" name="action" value="%5$s">'
			. '<input type="hidden" name="token" value="%6$s">'
			. '<button type="submit" style="background:#7c6af7;color:#fff;border:0;border-radius:6px;padding:12px 28px;font-size:17px;font-family:inherit;cursor:pointer;">%7$s</button>'
			. '</form>'
			. '<p style="margin:24px 0 0;"><a href="%8$s" style="color:#999;font-size:16px;text-decoration:none;">&larr; %9$s</a></p>'
			. '</div>',
			esc_html( $title ),
			esc_html( $description ),
			esc_url( home_url( '/' ) ),
			esc_attr( (string) $id ),
			esc_attr( $action ),
			esc_attr( $token ),
			esc_html( $button ),
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);

		wp_die( $html, esc_html( $title ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is fully escaped above.
	}

	/**
	 * Render the approve confirm form — editable title/excerpt/body, prefilled
	 * in the artist's own language, plus hidden "original" fields used by
	 * handle_approve_submission() to detect whether anything changed.
	 *
	 * @param array<string,string> $prefill Retry values (see render_confirm()).
	 */
	private function render_approve_confirm( int $id, string $token, array $prefill, string $error ): void {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, ReviewEndpoints::REVIEWABLE_POST_TYPES, true ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// Fourth audit §3a: this used to check only that the post exists and is
		// reviewable — not that the supplied token is the one actually issued for
		// it, or even that a token exists at all. Since this method renders the
		// draft's title/excerpt/body (translated into the artist's language via
		// get_display_text(), which can itself spend an AI call on cache miss),
		// a guessable sequential post ID plus ANY string in the token slot used
		// to be enough to read an unpublished submission. Always exits on failure.
		$this->require_valid_token( $id, $token, $post->post_type );

		// No ?? fallback needed: the gate above already guarantees $post->post_type
		// is one of ReviewEndpoints::REVIEWABLE_POST_TYPES, and APPROVE_FIELDS has
		// an entry for each of those three keys — PHPStan proves the offset always
		// exists here, so an unreachable fallback would just be dead code.
		$fields      = self::APPROVE_FIELDS[ $post->post_type ];
		$has_title   = in_array( 'title', $fields, true );
		$has_excerpt = in_array( 'excerpt', $fields, true );

		// Baseline is always recomputed (cheap — cached after the first call)
		// so the hidden orig_* fields stay correct even on a validation-error
		// retry, which must diff against the ORIGINAL back-translation, not
		// against whatever the artist just (invalidly) typed.
		[ $baseline_excerpt, $baseline_body ] = $this->get_display_text( $post );
		$baseline_title = $post->post_title;

		$title   = $prefill['title']   ?? $baseline_title;
		$excerpt = $prefill['excerpt'] ?? $baseline_excerpt;
		$body    = $prefill['body']    ?? $baseline_body;

		// Unlike the visitor-facing contact/join forms (where we never know in
		// advance what language the writer will use, so the field is left to
		// inherit the page's own lang), the text edited here is *always* in one
		// specific known language — the artist's own (`_agnosis_native_lang`,
		// set once at intake by PostCreator::create_post() — see that
		// constant's own docblock). Without an explicit `lang` attribute the
		// browser has no way to know to spellcheck a Spanish artist's bio in
		// Spanish when the staff reviewer's own browser/OS is set to English —
		// it would either silently skip spellcheck or, worse, flag every word
		// as a misspelling. Omitted entirely when unset (older posts predating
		// this meta field), same as every other native_lang consumer here.
		$native_lang = (string) get_post_meta( $post->ID, '_agnosis_native_lang', true );
		$lang_attr   = '' !== $native_lang ? ' lang="' . esc_attr( $native_lang ) . '"' : '';

		$error_html = '' !== $error
			? '<p style="background:#fef2f2;color:#c0392b;border:1px solid #fad7d7;border-radius:6px;padding:12px 16px;font-size:15px;margin:0 0 20px;">' . esc_html( $error ) . '</p>'
			: '';

		$label_style = 'display:block;font-size:14px;color:#888;margin:0 0 4px;';
		$input_style = 'width:100%;box-sizing:border-box;padding:10px;font-size:16px;font-family:inherit;border:1px solid #ddd;border-radius:6px;margin:0 0 16px;';
		$body_style  = 'width:100%;box-sizing:border-box;padding:10px;font-size:16px;font-family:inherit;border:1px solid #ddd;border-radius:6px;margin:0 0 24px;';

		// Only the fields this content type actually supports (see
		// APPROVE_FIELDS) are rendered as inputs — everything else is either
		// shown read-only (title, when not editable for this CPT) or omitted
		// entirely (excerpt). Built as a single escaped string rather than more
		// sprintf() placeholders since the field set varies by post type.
		$fields_html = '';

		if ( $has_title ) {
			$fields_html .= '<input type="hidden" name="orig_title" value="' . esc_attr( $baseline_title ) . '">'
				. '<label style="' . esc_attr( $label_style ) . '">' . esc_html__( 'Title', 'agnosis' ) . '</label>'
				. '<input type="text" name="title" value="' . esc_attr( $title ) . '" style="' . esc_attr( $input_style ) . '"' . $lang_attr . '>';
		} else {
			$fields_html .= '<p style="font-size:20px;font-weight:700;margin:0 0 16px;"' . $lang_attr . '>' . esc_html( $baseline_title ) . '</p>';
		}

		if ( $has_excerpt ) {
			$fields_html .= '<input type="hidden" name="orig_excerpt" value="' . esc_attr( $baseline_excerpt ) . '">'
				. '<label style="' . esc_attr( $label_style ) . '">' . esc_html__( 'Short description', 'agnosis' ) . '</label>'
				. '<textarea name="excerpt" rows="2" style="' . esc_attr( $input_style ) . '"' . $lang_attr . '>' . esc_textarea( $excerpt ) . '</textarea>';
		}

		$fields_html .= '<input type="hidden" name="orig_body" value="' . esc_attr( $baseline_body ) . '">'
			. '<label style="' . esc_attr( $label_style ) . '">' . esc_html__( 'Full text', 'agnosis' ) . '</label>'
			. '<textarea name="body" rows="10" style="' . esc_attr( $body_style ) . '"' . $lang_attr . '>' . esc_textarea( $body ) . '</textarea>';

		// Extra structured fields (portfolio link / event date-hour-location-
		// timezone-address) — added below the free-text fields above, per
		// post type. See render_extra_fields_html()'s docblock.
		$fields_html .= $this->render_extra_fields_html( $post->post_type, $post->ID, $prefill );

		$heading = sprintf(
			/* translators: %s is the content type — artwork, biography, or event */
			esc_html__( 'Publish this %s?', 'agnosis' ),
			esc_html( $this->content_label( $post->post_type ) )
		);

		$html = sprintf(
			'<div style="max-width:560px;margin:60px auto;font-family:Georgia,serif;color:#222;">'
			. '<p style="font-size:34px;color:#7c6af7;margin:0 0 16px;text-align:center;">✦</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 8px;text-align:center;">%1$s</h1>'
			. '<p style="font-size:17px;color:#555;margin:0 0 24px;text-align:center;">%2$s</p>'
			. '%3$s'
			. '<form method="post" action="%4$s">'
			. '<input type="hidden" name="agnosis_review" value="1">'
			. '<input type="hidden" name="id" value="%5$s">'
			. '<input type="hidden" name="action" value="approve">'
			. '<input type="hidden" name="token" value="%6$s">'
			. '%7$s'
			. '<div style="text-align:center;">'
			. '<button type="submit" style="background:#7c6af7;color:#fff;border:0;border-radius:6px;padding:12px 28px;font-size:17px;font-family:inherit;cursor:pointer;">%8$s</button>'
			. '</div>'
			. '</form>'
			. '<p style="margin:24px 0 0;text-align:center;"><a href="%9$s" style="color:#999;font-size:16px;text-decoration:none;">&larr; %10$s</a></p>'
			. '</div>',
			$heading,
			esc_html__( 'Make any final changes below, or leave everything as-is to publish exactly what was drafted.', 'agnosis' ),
			$error_html,
			esc_url( home_url( '/' ) ),
			esc_attr( (string) $id ),
			esc_attr( $token ),
			$fields_html, // Built entirely from esc_attr()/esc_html()/esc_textarea() pieces above.
			esc_html__( 'Publish it', 'agnosis' ),
			esc_url( home_url( '/' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);

		wp_die( $html, $heading, [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped above.
	}

	/** Human-readable content-type label for approve-form copy ("artwork", "biography", "event"). */
	private function content_label( string $post_type ): string {
		return match ( $post_type ) {
			'agnosis_biography' => __( 'biography', 'agnosis' ),
			'agnosis_event'     => __( 'event', 'agnosis' ),
			default             => __( 'artwork', 'agnosis' ),
		};
	}

	/** True when the current request is a POST (the confirm button was clicked). */
	private function is_post_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparison only, not used as output.
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
	}
}
