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
 * Excerpt/body are AI-authored in the site's primary language at this stage
 * (audit §3d — "content at rest is always primary-language"), so they are
 * back-translated for display via SubmissionTranslator::translate_text() — the
 * same lightweight, single-purpose translate call Notification.php already
 * uses for the review email preview, deliberately NOT the full generative
 * writing pipeline that produced the draft ("bypass AI" per the artist's own
 * framing of this feature).
 *
 * That back-translation is cached in `_agnosis_review_backtranslation` post
 * meta, keyed by a hash of the current excerpt/body and the artist's language.
 * Without this cache, every GET — including a mail-scanner prefetch, and
 * every time the artist reopens the same link — would re-spend an AI call;
 * with it, translation happens at most once per edit generation.
 *
 * If the artist changes anything, the edited excerpt/body are translated back
 * into the primary language on submit (again via translate_text(), not the
 * generative pipeline) and the post is saved-and-published in one call via the
 * existing PUT /agnosis/v1/review/{id} route. If nothing changed, the plain
 * POST /agnosis/v1/review/{id}/approve route runs exactly as before — no
 * translation call at all in the common case.
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
 * Hooks registered in Plugin::register_services() on 'template_redirect' (priority 1).
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\AI\SubmissionTranslator;

class ReviewConfirm {

	/**
	 * Post meta key caching the artist-language back-translation of the
	 * excerpt/body shown on the approve confirm form. See class docblock.
	 */
	private const BACKTRANSLATION_META = '_agnosis_review_backtranslation';

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
	 */
	private function redirect_result( string $result, string $post_type = '' ): void {
		$args = [ 'agnosis_result' => $result ];
		if ( '' !== $post_type ) {
			$args['agnosis_type'] = $post_type;
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
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		[ $label, $message ] = $this->result_copy( $result, $post_type );
		$is_err = ! in_array( $result, [ 'approve', 'reject', 'remove' ], true );
		$status = $is_err ? 400 : 200;
		$icon   = $is_err ? '✕' : '✦';
		$color  = $is_err ? '#c0392b' : '#7c6af7';

		$html = sprintf(
			'<div style="max-width:520px;margin:80px auto;font-family:Georgia,serif;text-align:center;color:#222;">'
			. '<p style="font-size:34px;color:%1$s;margin:0 0 16px;">%2$s</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 12px;">%3$s</h1>'
			. '<p style="font-size:18px;color:#555;margin:0 0 32px;">%4$s</p>'
			. '<a href="%5$s" style="color:%1$s;font-size:16px;text-decoration:none;">&larr; %6$s</a>'
			. '</div>',
			esc_attr( $color ),
			esc_html( $icon ),
			esc_html( $label ),
			esc_html( $message ),
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

			$this->redirect_result( $response->is_error() ? 'error' : 'approve', $post->post_type );
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
				[
					'title'   => $title,
					'excerpt' => $excerpt,
					'body'    => $body,
				],
				$error
			);
			return;
		}

		// Something changed — translate the edited excerpt/body back into the
		// site's primary language (translate_text(), not the generative
		// pipeline) before writing them. post_title is never translated for any
		// CPT: it stays the artist's own words verbatim everywhere in this
		// plugin (PostCreator::create_post()'s $original_title is passed through
		// unchanged regardless of post type), so it is passed straight through.
		$artist_lang = $this->resolve_artist_lang( (int) $post->post_author );
		$translator  = SubmissionTranslator::from_settings();

		$primary_excerpt = $excerpt;
		$primary_body    = $body;

		if ( null !== $translator && '' !== $artist_lang ) {
			$primary_lang = $translator->resolve_target_language();

			if ( $primary_lang !== $artist_lang ) {
				if ( $has_excerpt ) {
					$primary_excerpt = '' !== trim( $excerpt ) ? $translator->translate_text( $excerpt, $primary_lang ) : '';
				}
				$primary_body = $translator->translate_text( $body, $primary_lang );

				// Title changed too — refresh the AI-generated display title
				// (_agnosis_translated_title) so it doesn't go stale next to a
				// corrected original title. Same regeneration ContentEditor's
				// propagate_title() performs for a post-publish title edit.
				// Artwork-only: the dual-title display-title system
				// (Compat\LinguaForge::hold_artist_title()) is specific to
				// agnosis_artwork — biography/event titles are translated
				// normally by Lingua Forge's own post-publish fan-out, nothing
				// to pre-seed here.
				if ( $has_title && 'agnosis_artwork' === $post->post_type && trim( $title ) !== trim( $orig_title ) ) {
					$display_title = $translator->translate_text( $title, $primary_lang );
					if ( '' !== $display_title ) {
						update_post_meta( $id, '_agnosis_translated_title', $display_title );
					}
				}
			}
		}

		$rest_request = new \WP_REST_Request( 'PUT', '/agnosis/v1/review/' . $id );
		$rest_request->set_param( 'token', $token );
		$rest_request->set_param( 'title', $title );
		$rest_request->set_param( 'excerpt', $primary_excerpt );
		$rest_request->set_param( 'body', $primary_body );
		$rest_request->set_param( 'publish', true );

		$response = rest_do_request( $rest_request );

		$this->redirect_result( $response->is_error() ? 'error' : 'approve', $post->post_type );
	}

	/**
	 * Resolve an artist's language code (ISO 639-1) from their WP user locale.
	 *
	 * Mirrors the inline conversion Notification::on_post_drafted() already
	 * uses ('es_ES' → 'es'), kept independent of Lingua Forge here — this page
	 * must work whether or not LF is active, same as SubmissionTranslator
	 * itself. Returns '' when the artist has no declared locale (nothing to
	 * back-translate against).
	 */
	private function resolve_artist_lang( int $artist_id ): string {
		$locale = (string) get_user_meta( $artist_id, 'locale', true );
		if ( '' === $locale ) {
			return '';
		}
		return strtolower( substr( $locale, 0, 2 ) );
	}

	/**
	 * Return [excerpt, body] in the artist's own language for display on the
	 * approve confirm form, translating and caching on first use.
	 *
	 * Returns the untranslated post fields as-is (no AI call at all) when: the
	 * artist has no declared locale, no AI provider is configured, or the
	 * artist's language already matches the site's primary language — the
	 * common single-language case costs nothing extra.
	 *
	 * @return array{0:string,1:string}
	 */
	private function get_display_text( \WP_Post $post ): array {
		$body_plain = wp_strip_all_tags( (string) $post->post_content );

		$artist_lang = $this->resolve_artist_lang( (int) $post->post_author );
		if ( '' === $artist_lang ) {
			return [ $post->post_excerpt, $body_plain ];
		}

		$translator = SubmissionTranslator::from_settings();
		if ( null === $translator ) {
			return [ $post->post_excerpt, $body_plain ];
		}

		$primary_lang = $translator->resolve_target_language();
		if ( $primary_lang === $artist_lang ) {
			return [ $post->post_excerpt, $body_plain ];
		}

		$source_hash = md5( $post->post_excerpt . '|' . $body_plain );
		$cached      = get_post_meta( $post->ID, self::BACKTRANSLATION_META, true );

		if (
			is_array( $cached )
			&& ( $cached['hash'] ?? '' ) === $source_hash
			&& ( $cached['lang'] ?? '' ) === $artist_lang
		) {
			return [ (string) $cached['excerpt'], (string) $cached['body'] ];
		}

		// Not cached, or the source text/artist language changed since the
		// cache was built — this is the only point in the whole confirm flow
		// that spends an AI call on a plain GET. Cached immediately below so a
		// mail-scanner prefetch or a second visit to the same link never
		// re-spends it.
		$excerpt_display = '' !== trim( $post->post_excerpt ) ? $translator->translate_text( $post->post_excerpt, $artist_lang ) : '';
		$body_display    = '' !== trim( $body_plain ) ? $translator->translate_text( $body_plain, $artist_lang ) : '';

		update_post_meta( $post->ID, self::BACKTRANSLATION_META, [
			'hash'    => $source_hash,
			'lang'    => $artist_lang,
			'excerpt' => $excerpt_display,
			'body'    => $body_display,
		] );

		return [
			'' !== $excerpt_display ? $excerpt_display : $post->post_excerpt,
			'' !== $body_display ? $body_display : $body_plain,
		];
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
				. '<input type="text" name="title" value="' . esc_attr( $title ) . '" style="' . esc_attr( $input_style ) . '">';
		} else {
			$fields_html .= '<p style="font-size:20px;font-weight:700;margin:0 0 16px;">' . esc_html( $baseline_title ) . '</p>';
		}

		if ( $has_excerpt ) {
			$fields_html .= '<input type="hidden" name="orig_excerpt" value="' . esc_attr( $baseline_excerpt ) . '">'
				. '<label style="' . esc_attr( $label_style ) . '">' . esc_html__( 'Short description', 'agnosis' ) . '</label>'
				. '<textarea name="excerpt" rows="2" style="' . esc_attr( $input_style ) . '">' . esc_textarea( $excerpt ) . '</textarea>';
		}

		$fields_html .= '<input type="hidden" name="orig_body" value="' . esc_attr( $baseline_body ) . '">'
			. '<label style="' . esc_attr( $label_style ) . '">' . esc_html__( 'Full text', 'agnosis' ) . '</label>'
			. '<textarea name="body" rows="10" style="' . esc_attr( $body_style ) . '">' . esc_textarea( $body ) . '</textarea>';

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
