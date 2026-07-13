<?php
/**
 * Artist review notification.
 *
 * Fires on 'agnosis_post_drafted' and sends the artist an HTML email
 * containing a preview of the AI-generated post and three action links:
 *
 *   • Approve & Publish — token-signed REST call, no login required.
 *   • Edit before publishing — link to WP admin post editor.
 *   • Discard — token-signed REST call, trashes the draft.
 *
 * Tokens expire after Settings → Behavior → "Review link expiry (days)"
 * (agnosis_review_token_expiry_days, default 7 — same window as the
 * review_expiry meta PostCreator/ApplicationBiography write).
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\Core\CommunityMailer;
use Agnosis\Core\EmailBranding;
use Agnosis\Core\EmailFooter;

class Notification {

	/**
	 * Hook callback for 'agnosis_removal_requested'.
	 *
	 * Sends the artist a signed confirmation email. The post is not touched
	 * until the artist clicks the confirm link — removal is their decision alone.
	 *
	 * @param int $post_id   The artwork or event post ID (2026-07-06: remove@
	 *                       is no longer artwork-only).
	 * @param int $artist_id WordPress user ID of the requesting artist.
	 */
	public function on_removal_requested( int $post_id, int $artist_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$artist = get_userdata( $artist_id );
		if ( ! $artist ) {
			return;
		}

		$token = (string) get_post_meta( $post_id, '_agnosis_removal_token', true );
		if ( empty( $token ) ) {
			return;
		}

		// Switch to the artist's locale so all translated strings are correct.
		$artist_locale = (string) get_user_meta( $artist_id, 'locale', true );
		if ( '' !== $artist_locale ) {
			switch_to_locale( $artist_locale );
		}

		$subject = sprintf(
			/* translators: %s: artwork or event title */
			__( '[Agnosis] Confirm removal of: %s', 'agnosis' ),
			$post->post_title
		);

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $this->sender_header(),
		];

		wp_mail( $artist->user_email, $subject, $this->build_removal_email( $post, $artist->display_name, $token, $artist_id ), $headers );

		if ( '' !== $artist_locale ) {
			restore_current_locale();
		}
	}

	// -------------------------------------------------------------------------
	// remove@ / promote@ feedback (fifth audit §2b/§2c)
	// -------------------------------------------------------------------------

	/**
	 * Hook callback for 'agnosis_removal_target_not_found'.
	 *
	 * Previously a remove@ request whose subject matched nothing exactly got
	 * no artist-facing response at all — the queue row was silently marked
	 * 'published' and the artist was left believing their email had worked.
	 * This sends a "we couldn't find that" email listing the artist's current
	 * titles and, when the AI fuzzy-match layer found a plausible candidate
	 * (§2c), a one-click confirmation link for it — safe to offer because
	 * clicking is still the real consent step, so a wrong guess just sits
	 * unclicked until it expires.
	 *
	 * @param int      $artist_id        Requesting artist's user ID.
	 * @param string   $subject          The subject line that didn't match.
	 * @param string[] $titles           The artist's current artwork/event titles.
	 * @param int      $suggestion_id    Fuzzy-matched post ID, or 0.
	 * @param string   $suggestion_title Fuzzy-matched post title, or ''.
	 * @param string   $suggestion_token Pre-generated removal token for the suggestion, or ''.
	 */
	public function on_removal_target_not_found( int $artist_id, string $subject, array $titles, int $suggestion_id, string $suggestion_title, string $suggestion_token ): void {
		$artist = get_userdata( $artist_id );
		if ( ! $artist || ! $artist->user_email ) {
			return;
		}

		$artist_locale = (string) get_user_meta( $artist_id, 'locale', true );
		if ( '' !== $artist_locale ) {
			switch_to_locale( $artist_locale );
		}

		$subject_line = sprintf(
			/* translators: %s: site name */
			__( '[%s] We couldn\'t find that artwork or event', 'agnosis' ),
			get_bloginfo( 'name' )
		);

		$confirm_url = '';
		if ( $suggestion_id && '' !== $suggestion_token ) {
			$confirm_url = add_query_arg(
				[
					'agnosis_review' => '1',
					'id'             => $suggestion_id,
					'action'         => 'remove',
					'token'          => $suggestion_token,
				],
				home_url( '/' )
			);
		}

		wp_mail(
			$artist->user_email,
			$subject_line,
			$this->build_not_found_email( $artist->display_name, $subject, $titles, $suggestion_title, $confirm_url, $artist_id, 'remove' ),
			[
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $this->sender_header(),
			]
		);

		if ( '' !== $artist_locale ) {
			restore_current_locale();
		}
	}

	/**
	 * Hook callback for 'agnosis_promotion_result'.
	 *
	 * promote@ previously had no feedback in either direction: a match
	 * silently flipped the featured-artwork meta, and a miss silently did
	 * nothing. Now sends a plain confirmation on success, or a "we couldn't
	 * find that" email (same shape as removal's, minus a confirmation link —
	 * promote@ has no confirm step to attach one to) on a miss, optionally
	 * with a fuzzy-matched title suggestion (§2c) the artist can resend with.
	 *
	 * @param int      $artist_id Requesting artist's user ID.
	 * @param string   $subject   The subject line that was matched (or not).
	 * @param bool     $found     Whether a matching published artwork was found.
	 * @param string[] $titles    Artist's current published artwork titles (failure only).
	 * @param string   $suggestion_title Fuzzy-matched title suggestion, or '' (failure only).
	 */
	public function on_promotion_result( int $artist_id, string $subject, bool $found, array $titles, string $suggestion_title ): void {
		$artist = get_userdata( $artist_id );
		if ( ! $artist || ! $artist->user_email ) {
			return;
		}

		$artist_locale = (string) get_user_meta( $artist_id, 'locale', true );
		if ( '' !== $artist_locale ) {
			switch_to_locale( $artist_locale );
		}

		if ( $found ) {
			$subject_line = sprintf(
				/* translators: %s: site name */
				__( '[%s] Your artwork is now featured', 'agnosis' ),
				get_bloginfo( 'name' )
			);
			$body = $this->build_promotion_success_email( $artist->display_name, $subject, $artist_id );
		} else {
			$subject_line = sprintf(
				/* translators: %s: site name */
				__( '[%s] We couldn\'t find that artwork', 'agnosis' ),
				get_bloginfo( 'name' )
			);
			$body = $this->build_not_found_email( $artist->display_name, $subject, $titles, $suggestion_title, '', $artist_id, 'promote' );
		}

		wp_mail(
			$artist->user_email,
			$subject_line,
			$body,
			[
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $this->sender_header(),
			]
		);

		if ( '' !== $artist_locale ) {
			restore_current_locale();
		}
	}

	/**
	 * Build the shared "we couldn't find that title" email body for both
	 * remove@ and promote@ misses (§2b/§2c).
	 *
	 * @param string   $artist_name      Artist's display name.
	 * @param string   $subject          The subject line that didn't match.
	 * @param string[] $titles           The artist's current titles to list.
	 * @param string   $suggestion_title Fuzzy-matched title suggestion, or ''.
	 * @param string   $confirm_url      One-click confirm link for the suggestion
	 *                                   (remove@ only), or '' when not applicable.
	 * @param int      $artist_id        WP user ID — gates EmailFooter::edit_reminder_html().
	 * @param string   $lane             'remove' or 'promote' — selects the wording.
	 * @return string HTML email body.
	 */
	private function build_not_found_email( string $artist_name, string $subject, array $titles, string $suggestion_title, string $confirm_url, int $artist_id, string $lane ): string {
		$site_name = get_bloginfo( 'name' );
		$header_bg = '#0d0d12';
		$accent    = '#c0392b';

		$intro = 'remove' === $lane
			/* translators: %s: the subject line the artist sent */
			? sprintf( __( 'We received a removal request for "%s", but couldn\'t find an artwork or event with that exact title in your account.', 'agnosis' ), $subject )
			/* translators: %s: the subject line the artist sent */
			: sprintf( __( 'We received a request to feature "%s", but couldn\'t find a published artwork with that exact title in your account.', 'agnosis' ), $subject );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $this->html_lang() ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center" style="background:#f5f5f5;">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<tr><td style="background:<?php echo esc_attr( $header_bg ); ?>;padding:28px 24px;">
		<?php echo EmailBranding::header_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally. ?>
	</td></tr>

	<tr><td style="background:#ffffff;padding:36px 24px;">
		<p style="margin:0 0 20px;font-size:20px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $artist_name )
			);
			?>
		</p>
		<p style="margin:0 0 20px;font-size:19px;line-height:1.6;color:#555;">
			<?php echo esc_html( $intro ); ?>
		</p>

		<?php if ( '' !== $suggestion_title ) : ?>
		<div style="background:#f9f9f9;padding:16px 20px;border-radius:4px;margin:0 0 24px;border-left:3px solid #7c6af7;">
			<p style="margin:0 0 12px;font-size:18px;color:#333;">
				<?php
				printf(
					/* translators: %s: suggested title */
					esc_html__( 'Did you mean "%s"?', 'agnosis' ),
					esc_html( $suggestion_title )
				);
				?>
			</p>
			<?php if ( '' !== $confirm_url ) : ?>
			<table cellpadding="0" cellspacing="0"><tr><td>
				<a href="<?php echo esc_url( $confirm_url ); ?>" style="display:inline-block;padding:10px 20px;border-radius:6px;font-size:16px;font-weight:600;text-decoration:none;margin:0 0 8px;background:<?php echo esc_attr( $accent ); ?>;color:#fff;">
					<?php esc_html_e( 'Yes, remove this instead', 'agnosis' ); ?>
				</a>
			</td></tr></table>
			<p style="margin:8px 0 0;font-size:15px;color:#888;">
				<?php esc_html_e( 'Or simply resend your original email with that exact title.', 'agnosis' ); ?>
			</p>
			<?php else : ?>
			<p style="margin:0;font-size:15px;color:#888;">
				<?php esc_html_e( 'Resend your original email with that exact title.', 'agnosis' ); ?>
			</p>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $titles ) ) : ?>
		<p style="margin:0 0 8px;font-size:17px;font-weight:700;color:#333;"><?php esc_html_e( 'Your current titles:', 'agnosis' ); ?></p>
		<ul style="margin:0 0 24px;padding-left:20px;">
			<?php foreach ( $titles as $t ) : ?>
			<li style="margin:0 0 6px;font-size:17px;color:#555;"><?php echo esc_html( $t ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>

		<p style="margin:0;font-size:16px;line-height:1.6;color:#555;">
			<?php esc_html_e( 'Titles are matched exactly, so a small difference in wording is enough to miss — double-check spelling, capitalization, and punctuation against the list above before resending.', 'agnosis' ); ?>
		</p>
	</td></tr>

	<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:15px;color:#999;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
		<?php $work_emails_html = EmailFooter::html(); ?>
		<?php if ( '' !== $work_emails_html ) : ?>
		<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid #eee;">
			<?php echo $work_emails_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::html() escapes each label/address itself. ?>
		</div>
		<?php endif; ?>
		<?php $edit_reminder_html = EmailFooter::edit_reminder_html( $artist_id ); ?>
		<?php if ( '' !== $edit_reminder_html ) : ?>
		<p style="margin:12px 0 0;font-size:15px;color:#888;text-align:center;">
			<?php echo $edit_reminder_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::edit_reminder_html() escapes internally. ?>
		</p>
		<?php endif; ?>
	</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build the promote@ success confirmation email body — previously promote@
	 * had no feedback at all on success; the artist found out only by visiting
	 * the gallery overview themselves (§2b).
	 *
	 * @param string $artist_name Artist's display name.
	 * @param string $title       The artwork's title, now featured.
	 * @param int    $artist_id   WP user ID — gates EmailFooter::edit_reminder_html().
	 * @return string HTML email body.
	 */
	private function build_promotion_success_email( string $artist_name, string $title, int $artist_id ): string {
		$site_name = get_bloginfo( 'name' );
		$header_bg = '#0d0d12';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $this->html_lang() ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center" style="background:#f5f5f5;">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<tr><td style="background:<?php echo esc_attr( $header_bg ); ?>;padding:28px 24px;">
		<?php echo EmailBranding::header_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally. ?>
	</td></tr>

	<tr><td style="background:#ffffff;padding:36px 24px;">
		<p style="margin:0 0 20px;font-size:20px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $artist_name )
			);
			?>
		</p>
		<p style="margin:0 0 28px;padding:16px 20px;background:#f9f9f9;border-left:3px solid #7c6af7;border-radius:4px;font-size:20px;line-height:1.6;color:#333;">
			<?php
			printf(
				/* translators: %s: artwork title */
				esc_html__( '"%s" is now your featured artwork on the gallery overview.', 'agnosis' ),
				esc_html( $title )
			);
			?>
		</p>
		<p style="margin:0;font-size:16px;line-height:1.6;color:#555;">
			<?php esc_html_e( 'Any previously featured artwork has been unfeatured automatically — only one piece is featured at a time.', 'agnosis' ); ?>
		</p>
	</td></tr>

	<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:15px;color:#999;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
		<?php $work_emails_html = EmailFooter::html(); ?>
		<?php if ( '' !== $work_emails_html ) : ?>
		<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid #eee;">
			<?php echo $work_emails_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::html() escapes each label/address itself. ?>
		</div>
		<?php endif; ?>
		<?php $edit_reminder_html = EmailFooter::edit_reminder_html( $artist_id ); ?>
		<?php if ( '' !== $edit_reminder_html ) : ?>
		<p style="margin:12px 0 0;font-size:15px;color:#888;text-align:center;">
			<?php echo $edit_reminder_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::edit_reminder_html() escapes internally. ?>
		</p>
		<?php endif; ?>
	</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Hook callback for 'agnosis_post_drafted'.
	 *
	 * @param int $post_id   The drafted artwork post ID.
	 * @param int $artist_id WordPress user ID of the submitting artist.
	 */
	public function on_post_drafted( int $post_id, int $artist_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$artist = get_userdata( $artist_id );
		if ( ! $artist ) {
			return;
		}

		$token = get_post_meta( $post_id, '_agnosis_review_token', true );
		if ( empty( $token ) ) {
			return;
		}

		// Since 0.2.0, post_title holds the artist's original submitted title (in their
		// language).  _agnosis_translated_title holds the AI-generated site title (site
		// language).  The email shows both so the artist understands what will be
		// published, even if they don't speak the site's primary language.
		//
		// Native-language pipeline (agnosis-audit/NATIVE-LANGUAGE-PIPELINE.md):
		// every draft is now native-first (Phase 1, 2026-07-12) — carries
		// `_agnosis_native_lang`, set at intake by PostCreator::create_post().
		// $site_title read here is therefore NOT yet a real primary-language
		// translation for a native-first draft — PostCreator seeds
		// `_agnosis_translated_title` with the AI's own (still native) title at
		// intake, and only ReviewEndpoints::finalize_publish() turns it into a
		// genuine primary-language value, at approval. Showing it as "here's
		// your title in the site's language" at draft/review-email time would
		// be actively wrong, not just imprecise, so it's treated as not
		// meaningful yet for a native-first draft.
		//
		// Phase 5 (2026-07-13): this used to also back-translate a genuinely
		// primary-language excerpt/body into the artist's own language here for
		// the email preview, caching the result into
		// ReviewConfirm::BACKTRANSLATION_META so the artist's click-through to
		// the confirm page almost always found a warm cache. That whole path is
		// now unreachable going forward — is_native_draft is true for every
		// submission the native-first pipeline creates, and false only when the
		// artist's locale itself can't be resolved (in which case $artist_locale
		// below is also '', so there was nothing to translate from anyway) — so
		// it was deleted outright rather than kept as permanently dead code.
		// $body_preview in build_email() below already falls back correctly to
		// the post's own (native-language) content whenever no back-translated
		// preview is supplied.
		$is_native_draft = '' !== (string) get_post_meta( $post_id, '_agnosis_native_lang', true );
		$site_title       = $is_native_draft ? '' : (string) get_post_meta( $post_id, '_agnosis_translated_title', true );

		$artist_locale           = (string) get_user_meta( $artist_id, 'locale', true );
		$translated_site_title   = ''; // Site title back-translated to artist's language — legacy field, always '' now (see above).
		$translated_body_preview = ''; // Body preview back-translated to artist's language — legacy field, always '' now (see above).

		// Switch locale so UI strings (buttons, labels) are in the artist's language.
		if ( '' !== $artist_locale ) {
			switch_to_locale( $artist_locale );
		}

		$to = $artist->user_email;

		// post_title is now the artist's original title — use it directly in the subject.
		$subject = sprintf(
			/* translators: %s: artwork title (in the artist's own language) */
			__( '[Agnosis] Your submission is ready to review: %s', 'agnosis' ),
			$post->post_title
		);

		// Reply-To: the artist's own intake address for this exact content type
		// (submit@/bio@/event@/photo@/pure@, per Settings → Email) — an artist
		// reading this email is, by definition, in the middle of sending
		// submissions, and is a natural moment to want to send another one.
		// Hitting reply on this email should land back on that same address,
		// not the generic community/one-off-action From identity above.
		$headers = array_merge(
			[
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $this->sender_header(),
			],
			CommunityMailer::reply_to_header_for_post( $post_id )
		);

		$body = $this->build_email( $post, $artist->display_name, (string) $token, $site_title, $translated_site_title, $artist_id, $translated_body_preview );

		wp_mail( $to, $subject, $body, $headers );

		if ( '' !== $artist_locale ) {
			restore_current_locale();
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the removal confirmation email body.
	 *
	 * @param \WP_Post $post         The artwork or event post requested for removal.
	 * @param string   $artist_name  Artist's display name.
	 * @param string   $token        Signed removal token stored in post meta.
	 * @param int      $artist_id    WP user ID — gates EmailFooter::edit_reminder_html().
	 * @return string HTML email body.
	 */
	private function build_removal_email( \WP_Post $post, string $artist_name, string $token, int $artist_id = 0 ): string {
		// Frontend shim — token stays out of REST logs / browser history.
		$confirm_url = add_query_arg(
			[
				'agnosis_review' => '1',
				'id'             => $post->ID,
				'action'         => 'remove',
				'token'          => $token,
			],
			home_url( '/' )
		);

		$site_name = esc_html( get_bloginfo( 'name' ) );
		$title     = esc_html( $post->post_title );
		$accent    = '#c0392b';
		$btn_base  = 'display:inline-block;padding:12px 24px;border-radius:6px;font-size:18px;font-weight:600;text-decoration:none;margin:6px 4px;';

		// 2026-07-06: remove@ covers events as well as artwork — the copy below
		// says "artwork" or "event" depending on what's actually being removed,
		// rather than assuming artwork unconditionally.
		$label = 'agnosis_event' === $post->post_type ? __( 'event', 'agnosis' ) : __( 'artwork', 'agnosis' );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $this->html_lang() ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center" style="background:#f5f5f5;">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<!-- Header -->
	<tr><td style="background:#0d0d12;padding:28px 24px;">
		<?php echo EmailBranding::header_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally. ?>
	</td></tr>

	<!-- Body -->
	<tr><td style="background:#ffffff;padding:36px 24px;">
		<p style="margin:0 0 20px;font-size:20px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $artist_name )
			);
			?>
		</p>

		<p style="margin:0 0 16px;font-size:20px;line-height:1.6;color:#555;">
			<?php
			printf(
				/* translators: %s: "artwork" or "event" */
				esc_html__( 'We received a removal request for the following %s:', 'agnosis' ),
				esc_html( $label )
			);
			?>
		</p>

		<p style="margin:0 0 28px;padding:16px 20px;background:#f9f9f9;border-left:3px solid #7c6af7;border-radius:4px;font-size:21px;font-weight:600;color:#111;">
			<?php echo esc_html( $title ); ?>
		</p>

		<p style="margin:0 0 28px;font-size:18px;line-height:1.6;color:#555;">
			<?php
			printf(
				/* translators: %s: "artwork" or "event" */
				esc_html__( 'If you want to permanently remove this %s, click the button below. This action cannot be undone.', 'agnosis' ),
				esc_html( $label )
			);
			?>
		</p>

		<table cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
		<tr><td>
			<a href="<?php echo esc_url( $confirm_url ); ?>" style="<?php echo esc_attr( $btn_base ); ?>background:<?php echo esc_attr( $accent ); ?>;color:#fff;">
				<?php
				printf(
					/* translators: %s: "artwork" or "event" */
					esc_html__( 'Yes, remove this %s', 'agnosis' ),
					esc_html( $label )
				);
				?>
			</a>
		</td></tr>
		</table>

		<p style="font-size:16px;color:#999;margin:0 0 12px;padding:14px 16px;background:#fef9f9;border-radius:6px;border:1px solid #fad7d7;">
			<?php
			printf(
				/* translators: %s: "artwork" or "event" */
				esc_html__( 'If you did not request this removal, simply ignore this email — your %s will remain published.', 'agnosis' ),
				esc_html( $label )
			);
			?>
		</p>

		<p style="font-size:16px;color:#999;margin:0;">
			<?php esc_html_e( 'This confirmation link expires in 7 days.', 'agnosis' ); ?>
		</p>
	</td></tr>

	<!-- Footer -->
	<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:15px;color:#999;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
		<?php $work_emails_html = EmailFooter::html(); ?>
		<?php if ( '' !== $work_emails_html ) : ?>
		<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid #eee;">
			<?php echo $work_emails_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::html() escapes each label/address itself. ?>
		</div>
		<?php endif; ?>
		<?php $edit_reminder_html = EmailFooter::edit_reminder_html( $artist_id ); ?>
		<?php if ( '' !== $edit_reminder_html ) : ?>
		<p style="margin:12px 0 0;font-size:15px;color:#888;text-align:center;">
			<?php echo $edit_reminder_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::edit_reminder_html() escapes internally. ?>
		</p>
		<?php endif; ?>
	</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build the full HTML email body.
	 *
	 * @param \WP_Post $post         The draft artwork post.
	 * @param string   $artist_name  Artist's display name.
	 * @param string   $token        Signed review token stored in post meta.
	 * @return string HTML email body.
	 */
	private function build_email( \WP_Post $post, string $artist_name, string $token, string $site_title = '', string $translated_site_title = '', int $artist_id = 0, string $translated_body_preview = '' ): string {
		$approve_url      = $this->action_url( $post->ID, 'approve', $token );
		$reject_url       = $this->action_url( $post->ID, 'reject', $token );
		$submissions_url  = $this->submissions_page_url();

		// Render all gallery images at agnosis-email size (420px wide, proportional).
		// _agnosis_gallery_ids holds every attachment for this post in submission order.
		// Falls back to the featured image when the meta is absent (legacy posts).
		$gallery_ids = (array) get_post_meta( $post->ID, '_agnosis_gallery_ids', true );
		if ( empty( $gallery_ids ) ) {
			$thumb_id    = get_post_thumbnail_id( $post->ID );
			$gallery_ids = $thumb_id ? [ $thumb_id ] : [];
		}

		$images_html = '';
		foreach ( array_filter( $gallery_ids ) as $att_id ) {
			$src = wp_get_attachment_image_src( (int) $att_id, 'agnosis-email' );
			if ( $src ) {
				// margin-bottom on all but the last; a simple gap between stacked images.
				$images_html .= '<img src="' . esc_url( $src[0] ) . '" alt="" '
					. 'width="' . (int) $src[1] . '" '
					. 'style="display:block;max-width:100%;height:auto;border-radius:4px;margin-bottom:12px;">';
			}
		}

		// Build quality/enhancement notice.
		$quality_score  = (int) get_post_meta( $post->ID, '_agnosis_photo_quality_score', true );
		$was_enhanced   = '1' === (string) get_post_meta( $post->ID, '_agnosis_enhanced', true );
		$issues_raw     = (string) get_post_meta( $post->ID, '_agnosis_photo_quality_issues', true );
		$quality_issues = $issues_raw ? (array) json_decode( $issues_raw, true ) : [];

		$quality_html = '';
		if ( $quality_score > 0 && $was_enhanced && ! empty( $quality_issues ) ) {
			$issue_items = implode(
				'',
				array_map( fn( string $i ) => '<li style="margin:0 0 4px;">' . esc_html( $i ) . '</li>', $quality_issues )
			);
			$quality_html = '<div style="background:#fef9ec;border-left:3px solid #f0a500;padding:14px 16px;border-radius:4px;margin:0 0 24px;font-size:17px;color:#555;">'
				. '<strong style="color:#8a6200;">'
				. esc_html__( '📷 Photo enhanced automatically', 'agnosis' )
				. '</strong>'
				. '<p style="margin:8px 0 6px;">'
				. esc_html__( 'We detected some photographic issues and applied AI correction to improve visibility of your artwork:', 'agnosis' )
				. '</p>'
				. '<ul style="margin:0;padding-left:18px;">' . $issue_items . '</ul>'
				. '<p style="margin:8px 0 0;font-size:16px;color:#888;">'
				. esc_html__( 'The artwork itself has not been altered — only the photograph quality was corrected.', 'agnosis' )
				. '</p>'
				. '</div>';
		} elseif ( $quality_score > 0 && ! $was_enhanced ) {
			$quality_html = '<p style="font-size:16px;color:#888;margin:0 0 20px;">'
				. sprintf(
					/* translators: %d: photo quality score out of 10 */
					esc_html__( '📷 Photo quality score: %d/10', 'agnosis' ),
					$quality_score
				)
				. '</p>';
		}

		// Dropped-link notice (2026-07-10, generalised beyond biography's
		// portfolio field) — any submission can mention a link that doesn't
		// clear EmbedPolicy (untrusted host, AI review off/rejected, fetch
		// failure...) and gets silently left out of the post. Previously this
		// was nothing beyond a log line the artist never sees. Both drafting
		// paths now record every dropped link plus WHY, in the same shape:
		// `_agnosis_dropped_links` (JSON array of {url, reason}) — written by
		// PostCreator::build_external_link_embeds() for artwork/biography/event
		// email submissions, and by ApplicationBiography::on_artist_admitted()
		// for a join-application's portfolio field. One reader here covers
		// both, so every content type gets the same explanation instead of
		// only biography.
		$dropped_links_html = '';
		$dropped_links_raw  = (string) get_post_meta( $post->ID, '_agnosis_dropped_links', true );
		$dropped_links      = $dropped_links_raw ? (array) json_decode( $dropped_links_raw, true ) : [];

		if ( ! empty( $dropped_links ) ) {
			$link_items = implode(
				'',
				array_map(
					static function ( $link ): string {
						$link   = (array) $link;
						$url    = (string) ( $link['url'] ?? '' );
						$reason = (string) ( $link['reason'] ?? '' );
						return '<li style="margin:0 0 8px;">'
							. '<strong>' . esc_html( $url ) . '</strong>'
							. ( '' !== $reason ? '<br><span style="color:#888;">' . esc_html( $reason ) . '</span>' : '' )
							. '</li>';
					},
					$dropped_links
				)
			);

			// Biography is the only content type with an editable "fix this
			// link" field today (ReviewConfirm's approve form) — everything
			// else just gets an explanation, since resending the email with a
			// corrected link is the only fix for those.
			$footer_hint = 'agnosis_biography' === $post->post_type
				? esc_html__( 'Double-check the link is correct — you can fix or remove it in the form below before publishing.', 'agnosis' )
				: esc_html__( 'If the link was correct, its destination may simply not be from a source this site automatically embeds.', 'agnosis' );

			$dropped_links_html = '<div style="background:#fef9ec;border-left:3px solid #f0a500;padding:14px 16px;border-radius:4px;margin:0 0 24px;font-size:17px;color:#555;">'
				. '<strong style="color:#8a6200;">'
				. esc_html( _n( '🔗 A link was not included', '🔗 Some links were not included', count( $dropped_links ), 'agnosis' ) )
				. '</strong>'
				. '<p style="margin:8px 0 6px;">'
				. esc_html__( "The following couldn't be automatically embedded, so they were left out:", 'agnosis' )
				. '</p>'
				. '<ul style="margin:0 0 8px;padding-left:18px;">' . $link_items . '</ul>'
				. '<p style="margin:8px 0 0;font-size:16px;color:#888;">' . $footer_hint . '</p>'
				. '</div>';
		}

		// replace@/[Event] title-miss suggestion (audit §2a) — a subject that
		// doesn't exactly match one of the artist's existing titles still
		// creates a brand-new post (unchanged, and correctly so — replace is
		// destructive, never auto-merged on a fuzzy guess), but that used to
		// read exactly like an ordinary new-artwork draft, so an artist who
		// meant to update "Sunset Over the Harbor" would only discover the
		// duplicate later. `_agnosis_merge_miss_suggestion` (JSON
		// {type, title}) is written by PostCreator::create_post() whenever
		// find_post_by_subject() missed AND gather_title_context()'s fuzzy AI
		// comparison — the same "did you mean" machinery §2c already built for
		// remove@/promote@ — found a plausible candidate among the artist's
		// other posts.
		$merge_miss_html = '';
		$merge_miss_raw  = (string) get_post_meta( $post->ID, '_agnosis_merge_miss_suggestion', true );
		$merge_miss      = $merge_miss_raw ? (array) json_decode( $merge_miss_raw, true ) : [];
		$merge_miss_title = (string) ( $merge_miss['title'] ?? '' );

		if ( '' !== $merge_miss_title ) {
			// Two full, independently-translatable sentences rather than
			// composing a translated verb fragment into another translated
			// string via sprintf() — word order and grammar don't necessarily
			// compose the same way across the 17 shipped locales.
			$message = 'event_update' === ( $merge_miss['type'] ?? '' )
				? sprintf(
					/* translators: %s: fuzzy-matched event title suggestion */
					esc_html__( 'Its subject line didn\'t exactly match one of your existing titles. If you meant to update an existing event, "%s", resend your email with that exact title instead.', 'agnosis' ),
					esc_html( $merge_miss_title )
				)
				: sprintf(
					/* translators: %s: fuzzy-matched artwork title suggestion */
					esc_html__( 'Its subject line didn\'t exactly match one of your existing titles. If you meant to replace an existing artwork, "%s", resend your email with that exact title instead.', 'agnosis' ),
					esc_html( $merge_miss_title )
				);

			$merge_miss_html = '<div style="background:#fef9ec;border-left:3px solid #f0a500;padding:14px 16px;border-radius:4px;margin:0 0 24px;font-size:17px;color:#555;">'
				. '<strong style="color:#8a6200;">' . esc_html__( '📄 This was published as a new post', 'agnosis' ) . '</strong>'
				. '<p style="margin:8px 0 0;">' . $message . '</p>'
				. '</div>';
		}

		$site_name = esc_html( get_bloginfo( 'name' ) );
		$title     = esc_html( $post->post_title );
		$excerpt   = esc_html( $post->post_excerpt );
		// Strip blocks / shortcodes for the email body preview. $translated_body_preview
		// is always '' now (Phase 5, agnosis-audit/NATIVE-LANGUAGE-PIPELINE.md —
		// see on_post_drafted()'s docblock note), so this always falls through to
		// post_content directly — already in the artist's own language, per the
		// native-first pipeline (Phase 1).
		$body_preview = esc_html(
			'' !== $translated_body_preview
				? $translated_body_preview
				: wp_trim_words( wp_strip_all_tags( $post->post_content ), 80 )
		);
		// post_title is the artist's original title (their language).
		// $site_title is the AI-generated title for the site (site language).
		// $translated_site_title is the site title back-translated into the artist's language.
		// Both are escaped at point of output (esc_html inside printf) — not pre-escaped here.

		$accent    = '#7c6af7';
		$header_bg = '#0d0d12'; // matches the theme's dark header/background colour on the live site.
		$btn_base  = 'display:inline-block;padding:12px 24px;border-radius:6px;font-size:18px;font-weight:600;text-decoration:none;margin:6px 4px;';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $this->html_lang() ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center" style="background:#f5f5f5;">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<!-- Header -->
	<tr><td style="background:<?php echo esc_attr( $header_bg ); ?>;padding:28px 24px;">
		<?php echo EmailBranding::header_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally. ?>
	</td></tr>

	<!-- Body -->
	<tr><td style="background:#ffffff;padding:36px 24px;">
		<p style="margin:0 0 20px;font-size:20px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $artist_name )
			);
			?>
		</p>
		<p style="margin:0 0 28px;font-size:20px;line-height:1.6;color:#555;">
			<?php esc_html_e( "Your submission has been processed. Here's what our AI curator came up with — take a look and let us know if it's ready to publish.", 'agnosis' ); ?>
		</p>

		<?php if ( $images_html ) : ?>
			<?php echo $images_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() and (int) applied per image above. ?>
		<?php endif; ?>

		<?php if ( $quality_html ) : ?>
			<?php echo $quality_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped via esc_html() and esc_html__() above. ?>
		<?php endif; ?>

		<?php if ( $dropped_links_html ) : ?>
			<?php echo $dropped_links_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped via esc_html() above. ?>
		<?php endif; ?>

		<?php if ( $merge_miss_html ) : ?>
			<?php echo $merge_miss_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped via esc_html()/esc_html__() above. ?>
		<?php endif; ?>

		<!-- Original title (artist's language) — the canonical name of the work -->
		<h2 style="margin:0 0 8px;font-size:28px;font-weight:700;color:#111;"><?php echo esc_html( $title ); ?></h2>

		<?php if ( $site_title && $site_title !== $title ) : ?>
		<!-- AI-generated site title (site language) — what visitors will see -->
		<p style="margin:0 0 4px;font-size:17px;color:#888;">
			<?php
			printf(
				/* translators: %s: the AI-generated site title in the site's primary language */
				esc_html__( 'On the site: %s', 'agnosis' ),
				esc_html( $site_title )
			);
			?>
		</p>
		<?php endif; ?>

		<?php if ( $translated_site_title ) : ?>
		<!-- Back-translation of the site title into the artist's language — clarity hint -->
		<p style="margin:0 0 16px;font-size:16px;font-style:italic;color:#999;">
			<?php
			printf(
				/* translators: %s: the site title translated back into the artist's language */
				esc_html__( 'In your language: %s', 'agnosis' ),
				esc_html( $translated_site_title )
			);
			?>
		</p>
		<?php else : ?>
		<div style="margin-bottom:16px;"></div>
		<?php endif; ?>

		<?php if ( $excerpt ) : ?>
		<p style="margin:0 0 16px;font-size:18px;font-style:italic;color:#666;border-left:3px solid <?php echo esc_attr( $accent ); ?>;padding-left:14px;"><?php echo esc_html( $excerpt ); ?></p>
		<?php endif; ?>

		<?php if ( $body_preview ) : ?>
		<p style="margin:0 0 32px;font-size:18px;line-height:1.7;color:#444;"><?php echo esc_html( $body_preview ); ?>&hellip;</p>
		<?php endif; ?>

		<!-- Primary CTAs -->
		<table cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
		<tr><td>
			<a href="<?php echo esc_url( $approve_url ); ?>" style="<?php echo esc_attr( $btn_base ); ?>background:<?php echo esc_attr( $accent ); ?>;color:#fff;">
				✓ <?php esc_html_e( 'Looks great — Publish it', 'agnosis' ); ?>
			</a>
			<a href="<?php echo esc_url( $reject_url ); ?>" style="<?php echo esc_attr( $btn_base ); ?>background:#fff;color:#c0392b;border:1px solid #c0392b;">
				✕ <?php esc_html_e( 'Discard', 'agnosis' ); ?>
			</a>
		</td></tr>
		</table>

		<?php if ( $submissions_url ) : ?>
		<p style="font-size:17px;color:#666;margin:0 0 24px;padding:14px 16px;background:#f9f9f9;border-radius:6px;">
			<?php esc_html_e( 'Want to tweak the title, text or tags before publishing?', 'agnosis' ); ?>
			<a href="<?php echo esc_url( $submissions_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;font-weight:600;">
				<?php esc_html_e( 'Open your submissions page →', 'agnosis' ); ?>
			</a>
		</p>
		<?php endif; ?>

		<p style="font-size:16px;color:#999;margin:0;">
			<?php
			$review_expiry_days = max( 1, (int) get_option( 'agnosis_review_token_expiry_days', 7 ) );
			$review_expiry_text = sprintf(
				/* translators: %d is the number of days the review link stays valid — configurable under Settings, Behavior tab */
				_n(
					'The Publish and Discard links above expire in %d day. Your submission stays as a draft until you decide.',
					'The Publish and Discard links above expire in %d days. Your submission stays as a draft until you decide.',
					$review_expiry_days,
					'agnosis'
				),
				$review_expiry_days
			);
			echo esc_html( $review_expiry_text );
			?>
		</p>
	</td></tr>

	<!-- Footer -->
	<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:15px;color:#999;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
		<?php $work_emails_html = EmailFooter::html(); ?>
		<?php if ( '' !== $work_emails_html ) : ?>
		<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid #eee;">
			<?php echo $work_emails_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::html() escapes each label/address itself. ?>
		</div>
		<?php endif; ?>
		<?php $edit_reminder_html = EmailFooter::edit_reminder_html( $artist_id ); ?>
		<?php if ( '' !== $edit_reminder_html ) : ?>
		<p style="margin:12px 0 0;font-size:15px;color:#888;text-align:center;">
			<?php echo $edit_reminder_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::edit_reminder_html() escapes internally. ?>
		</p>
		<?php endif; ?>
	</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build a signed REST URL for an approve or reject action.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  'approve' or 'reject'.
	 * @param string $token   Signed token from post meta.
	 * @return string Full URL.
	 */
	private function action_url( int $post_id, string $action, string $token ): string {
		// Link to the frontend shim instead of directly to the REST endpoint.
		// The shim processes the token server-side via rest_do_request() and
		// redirects to a clean URL — keeping the token out of REST access logs,
		// browser history, and Referer headers.  See ReviewConfirm.
		return add_query_arg(
			[
				'agnosis_review' => '1',
				'id'             => $post_id,
				'action'         => $action,
				'token'          => $token,
			],
			home_url( '/' )
		);
	}

	/**
	 * Return the URL of the auto-created submissions page, or empty string if
	 * it hasn't been created yet (shouldn't happen in normal operation).
	 *
	 * The submissions page is created by Activator::create_submissions_page()
	 * and its ID is stored in the 'agnosis_submissions_page_id' option.
	 *
	 * @return string Page URL or empty string.
	 */
	private function submissions_page_url(): string {
		$page_id = (int) get_option( 'agnosis_submissions_page_id' );
		if ( ! $page_id ) {
			return '';
		}
		return (string) get_permalink( $page_id );
	}

	// -------------------------------------------------------------------------
	// Submission rejection
	// -------------------------------------------------------------------------

	/**
	 * Hook callback for 'agnosis_submission_rejected'.
	 *
	 * Sends the artist a friendly rejection email explaining the photo quality
	 * issue in plain language and giving specific, actionable advice so they
	 * can resubmit with a better photograph.
	 *
	 * @param int      $queue_id  Queue row ID (used only for logging).
	 * @param int      $artist_id WordPress user ID.
	 * @param int      $score     Detected photo quality score (1–10).
	 * @param string[] $issues    Issue labels returned by the vision AI.
	 */
	public function on_submission_rejected( int $queue_id, int $artist_id, int $score, array $issues ): void {
		$artist = get_userdata( $artist_id );
		if ( ! $artist || ! $artist->user_email ) {
			return;
		}

		$artist_locale = (string) get_user_meta( $artist_id, 'locale', true );
		if ( '' !== $artist_locale ) {
			switch_to_locale( $artist_locale );
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] We couldn\'t process your submission', 'agnosis' ),
			get_bloginfo( 'name' )
		);

		$body = $this->build_rejection_email( $artist->display_name, $score, $issues, $artist_id );

		wp_mail(
			$artist->user_email,
			$subject,
			$body,
			[
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $this->sender_header(),
			]
		);

		if ( '' !== $artist_locale ) {
			restore_current_locale();
		}
	}

	// -------------------------------------------------------------------------
	// Submission with no recognizable attachment
	// -------------------------------------------------------------------------

	/**
	 * Hook callback for 'agnosis_submission_no_attachment'.
	 *
	 * Fired from two places, both meaning the same thing to the artist —
	 * nothing was published because there was no usable file to work with:
	 *
	 *   - Inbox::process_messages(), when Parser can't find any recognizable
	 *     attachment at all (e.g. a photo pasted/inserted inline rather than
	 *     properly attached).
	 *   - PostCreator::handle(), when attachment(s) WERE found and queued but
	 *     every one of them failed to convert into something the pipeline can
	 *     use (e.g. a HEIC/HEIF photo on a server whose ImageMagick build
	 *     can't decode it — see MediaAdapter::adapt_heic()).
	 *
	 * Previously both failures were completely silent: the artist had no way
	 * to know their email didn't go through. This sends a short, friendly
	 * explanation covering both causes so they know to resend.
	 *
	 * @param int    $artist_id WordPress user ID of the sender.
	 * @param string $uid       IMAP message UID or queue message_uid (logging only).
	 */
	public function on_submission_no_attachment( int $artist_id, string $uid ): void {
		$artist = get_userdata( $artist_id );
		if ( ! $artist || ! $artist->user_email ) {
			return;
		}

		$artist_locale = (string) get_user_meta( $artist_id, 'locale', true );
		if ( '' !== $artist_locale ) {
			switch_to_locale( $artist_locale );
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] We couldn\'t find a photo in your email', 'agnosis' ),
			get_bloginfo( 'name' )
		);

		wp_mail(
			$artist->user_email,
			$subject,
			$this->build_no_attachment_email( $artist->display_name, $artist_id ),
			[
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $this->sender_header(),
			]
		);

		if ( '' !== $artist_locale ) {
			restore_current_locale();
		}
	}

	/**
	 * Build the HTML "no attachment found" email body.
	 *
	 * @param string $artist_name Artist's display name.
	 * @param int    $artist_id   WP user ID — gates EmailFooter::edit_reminder_html().
	 * @return string HTML email body.
	 */
	private function build_no_attachment_email( string $artist_name, int $artist_id ): string {
		$site_name = get_bloginfo( 'name' );
		$header_bg = '#0d0d12'; // matches the theme's dark header/background colour on the live site.

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $this->html_lang() ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center" style="background:#f5f5f5;">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<tr><td style="background:<?php echo esc_attr( $header_bg ); ?>;padding:28px 24px;">
		<?php echo EmailBranding::header_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally. ?>
	</td></tr>

	<tr><td style="background:#ffffff;padding:36px 24px;">
		<p style="margin:0 0 20px;font-size:20px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $artist_name )
			);
			?>
		</p>
		<p style="margin:0 0 24px;font-size:20px;line-height:1.6;color:#555;">
			<?php esc_html_e( "We received your email, but couldn't process a usable photo, audio, or video file from it — so nothing was published. This can happen when an image is pasted or inserted inline rather than added as a proper attachment, or when a file arrives in a format this server can't convert (for example, an iPhone photo saved in HEIC/HEIF format).", 'agnosis' ); ?>
		</p>

		<div style="background:#f9f9f9;padding:16px 20px;border-radius:4px;margin:0 0 28px;">
			<p style="margin:0 0 10px;font-size:17px;font-weight:700;color:#333;"><?php esc_html_e( 'To resend correctly:', 'agnosis' ); ?></p>
			<ul style="margin:0;padding-left:20px;">
				<li style="margin:0 0 6px;font-size:17px;color:#555;"><?php esc_html_e( 'Use your mail app\'s "Attach file" (usually a paperclip icon) rather than "Insert photo" or pasting the image directly into the message body.', 'agnosis' ); ?></li>
				<li style="margin:0 0 6px;font-size:17px;color:#555;"><?php esc_html_e( 'If your photo was taken on an iPhone, try switching Settings → Camera → Formats to "Most Compatible" before taking or sending it, or use "Options" in Mail to send it as a JPEG.', 'agnosis' ); ?></li>
				<li style="margin:0 0 0;font-size:17px;color:#555;"><?php esc_html_e( 'Supported formats: JPEG, PNG, WebP, GIF, TIFF, or HEIC/HEIF for images; MP3, WAV, M4A, OGG, or FLAC for audio; MP4, MOV, AVI, WebM, OGG, or MPEG for video.', 'agnosis' ); ?></li>
			</ul>
		</div>

		<p style="margin:0;font-size:18px;line-height:1.6;color:#555;">
			<?php esc_html_e( 'Once it\'s properly attached, just send it to the same address again — we\'ll pick it up automatically.', 'agnosis' ); ?>
		</p>
	</td></tr>

	<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:15px;color:#999;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
		<?php $work_emails_html = EmailFooter::html(); ?>
		<?php if ( '' !== $work_emails_html ) : ?>
		<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid #eee;">
			<?php echo $work_emails_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::html() escapes each label/address itself. ?>
		</div>
		<?php endif; ?>
		<?php $edit_reminder_html = EmailFooter::edit_reminder_html( $artist_id ); ?>
		<?php if ( '' !== $edit_reminder_html ) : ?>
		<p style="margin:12px 0 0;font-size:15px;color:#888;text-align:center;">
			<?php echo $edit_reminder_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::edit_reminder_html() escapes internally. ?>
		</p>
		<?php endif; ?>
	</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Map a list of AI-detected quality issues to actionable advice sentences.
	 *
	 * Each issue label is matched (case-insensitive substring) against the
	 * known patterns. Unrecognised issues are included verbatim as a fallback.
	 *
	 * @param string[] $issues Issue labels from the vision AI.
	 * @return string[] Corresponding human-readable advice sentences.
	 */
	private function issues_to_advice( array $issues ): array {
		$advice = [];

		$map = [
			// Lighting
			'dark'         => __( 'The photo is too dark to show your artwork clearly. Try photographing near a large window in daylight, or use a soft lamp aimed at the work from the side.', 'agnosis' ),
			'underexpos'   => __( 'The photo is underexposed (too dark). Try photographing near a large window in daylight, or use a soft lamp aimed at the work from the side.', 'agnosis' ),
			'overexpos'    => __( 'The photo is overexposed (too bright / washed out). Avoid direct sunlight or flash pointed straight at the artwork — diffuse or indirect light works best.', 'agnosis' ),
			'bright'       => __( 'The photo is too bright or washed out. Avoid direct sunlight or flash — diffuse light or shade gives more even tones.', 'agnosis' ),
			'glare'        => __( 'There is glare or a bright reflection on the artwork. Tilt the canvas slightly or photograph at an angle to eliminate reflections from the surface.', 'agnosis' ),
			'reflection'   => __( 'There is a reflection on the artwork surface. Tilt the canvas slightly or change your angle to eliminate it.', 'agnosis' ),
			// Sharpness
			'blur'         => __( 'The image is blurry. Rest your phone or camera on a stable surface, use the self-timer, and make sure the artwork fills most of the frame so autofocus locks on it.', 'agnosis' ),
			'focus'        => __( 'The image is out of focus. Tap on the artwork on your phone screen before shooting to make sure autofocus locks on it, and hold the camera still.', 'agnosis' ),
			'motion'       => __( 'There is motion blur. Hold the camera very still or use a tripod; press the shutter gently or use the self-timer to avoid shake.', 'agnosis' ),
			'shake'        => __( 'Camera shake is visible. Use a tripod or rest the camera on a flat surface and use the self-timer to avoid movement when pressing the shutter.', 'agnosis' ),
			// Resolution / detail
			'resolution'   => __( 'The image resolution is too low. Use your phone\'s highest quality setting and make sure you\'re close enough that the artwork fills the frame.', 'agnosis' ),
			'low res'      => __( 'The image resolution is too low. Use your phone\'s highest quality setting and fill the frame with the artwork.', 'agnosis' ),
			'pixelat'      => __( 'The image is pixelated. Shoot from closer or use a higher resolution setting on your camera.', 'agnosis' ),
			// Colour / white balance
			'colour cast'  => __( 'The photo has a strong color cast. Shoot under neutral daylight or use your phone\'s Auto White Balance setting for more accurate colors.', 'agnosis' ),
			'color cast'   => __( 'The photo has a strong color cast. Shoot under neutral daylight or use your phone\'s Auto White Balance setting for more accurate colors.', 'agnosis' ),
			'yellow'       => __( 'The photo looks too yellow or warm. Shoot under natural daylight or switch your phone\'s white balance to Daylight / Cloudy.', 'agnosis' ),
			'blue'         => __( 'The photo has a cold blue tint. Move the artwork to a warmer, more natural light source or adjust your camera\'s white balance.', 'agnosis' ),
			// Composition
			'cropped'      => __( 'Part of the artwork appears to be cropped out of the frame. Step back and make sure the entire work is visible before shooting.', 'agnosis' ),
			'angle'        => __( 'The artwork is photographed at an angle, causing distortion. Shoot straight on, with the camera parallel to the canvas.', 'agnosis' ),
			'distort'      => __( 'The artwork looks distorted or skewed. Position your camera directly in front of the artwork, parallel to its surface.', 'agnosis' ),
			'shadow'       => __( 'There is a shadow across part of the artwork. Reposition your light source or move the artwork so no shadows fall on the surface.', 'agnosis' ),
		];

		foreach ( $issues as $issue ) {
			$matched = false;
			$lower   = strtolower( $issue );
			foreach ( $map as $keyword => $sentence ) {
				if ( str_contains( $lower, $keyword ) ) {
					if ( ! in_array( $sentence, $advice, true ) ) {
						$advice[] = $sentence;
					}
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				// Unknown issue — include it verbatim so the artist still has context.
				$advice[] = esc_html( ucfirst( $issue ) ) . '.';
			}
		}

		return $advice;
	}

	/**
	 * Build the HTML rejection email body.
	 *
	 * @param string   $artist_name Artist's display name.
	 * @param int      $score       Quality score (1–10).
	 * @param string[] $issues      Issue labels from the vision AI.
	 * @param int      $artist_id   WP user ID — gates EmailFooter::edit_reminder_html().
	 * @return string HTML email body.
	 */
	private function build_rejection_email( string $artist_name, int $score, array $issues, int $artist_id = 0 ): string {
		$site_name       = get_bloginfo( 'name' );
		$submissions_url = $this->submissions_page_url();
		$accent          = '#7c6af7';
		$header_bg       = '#0d0d12'; // matches the theme's dark header/background colour on the live site.
		$advice_items    = $this->issues_to_advice( $issues );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $this->html_lang() ); ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center" style="background:#f5f5f5;">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<!-- Header -->
	<tr><td style="background:<?php echo esc_attr( $header_bg ); ?>;padding:28px 24px;">
		<?php echo EmailBranding::header_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally. ?>
	</td></tr>

	<!-- Body -->
	<tr><td style="background:#ffffff;padding:36px 24px;">
		<p style="margin:0 0 20px;font-size:20px;color:#555;">
			<?php
			printf(
				/* translators: %s: recipient's display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $artist_name )
			);
			?>
		</p>
		<p style="margin:0 0 24px;font-size:20px;line-height:1.6;color:#555;">
			<?php esc_html_e( "We received your submission, but unfortunately the photo quality is too low for us to show your artwork clearly. We didn't publish it — but please don't be discouraged! A simple retake is usually all it takes.", 'agnosis' ); ?>
		</p>

		<!-- Issue panel -->
		<div style="background:#fff8f0;border-left:3px solid #e07b00;padding:16px 20px;border-radius:4px;margin:0 0 28px;">
			<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#b35900;">
				<?php
				printf(
					/* translators: %d: quality score out of 10 */
					esc_html__( '📷 Photo quality score: %d / 10', 'agnosis' ),
					absint( $score )
				);
				?>
			</p>
			<?php if ( ! empty( $advice_items ) ) : ?>
			<p style="margin:0 0 10px;font-size:17px;color:#555;"><?php esc_html_e( 'Here\'s what our AI detected and how to fix it:', 'agnosis' ); ?></p>
			<ul style="margin:0;padding-left:20px;">
				<?php foreach ( $advice_items as $advice ) : ?>
				<li style="margin:0 0 8px;font-size:17px;line-height:1.5;color:#444;"><?php echo wp_kses_post( $advice ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php else : ?>
			<p style="margin:0;font-size:17px;color:#555;"><?php esc_html_e( 'The overall photo quality was too low for our AI to process the artwork clearly.', 'agnosis' ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Tips -->
		<div style="background:#f9f9f9;padding:16px 20px;border-radius:4px;margin:0 0 28px;">
			<p style="margin:0 0 10px;font-size:17px;font-weight:700;color:#333;"><?php esc_html_e( '💡 Quick tips for a great artwork photo', 'agnosis' ); ?></p>
			<ul style="margin:0;padding-left:20px;">
				<li style="margin:0 0 6px;font-size:17px;color:#555;"><?php esc_html_e( 'Use natural light — position the artwork near a window on a cloudy day for even, soft light.', 'agnosis' ); ?></li>
				<li style="margin:0 0 6px;font-size:17px;color:#555;"><?php esc_html_e( 'Shoot straight on — hold your phone parallel to the canvas to avoid perspective distortion.', 'agnosis' ); ?></li>
				<li style="margin:0 0 6px;font-size:17px;color:#555;"><?php esc_html_e( 'Fill the frame — let the artwork take up most of the shot; crop out distracting backgrounds.', 'agnosis' ); ?></li>
				<li style="margin:0 0 0;font-size:17px;color:#555;"><?php esc_html_e( 'Hold still — tap the artwork on your screen to focus, then press the shutter gently or use a self-timer.', 'agnosis' ); ?></li>
			</ul>
		</div>

		<p style="margin:0 0 28px;font-size:18px;line-height:1.6;color:#555;">
			<?php esc_html_e( 'Once you have a clearer photo, just email it to the same address as before — we\'ll pick it up automatically.', 'agnosis' ); ?>
		</p>

		<?php if ( $submissions_url ) : ?>
		<p style="font-size:17px;color:#666;margin:0 0 0;padding:14px 16px;background:#f0eeff;border-radius:6px;">
			<?php esc_html_e( 'Your previous submission is saved in your submissions page in case you want to review what was sent.', 'agnosis' ); ?>
			<a href="<?php echo esc_url( $submissions_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;font-weight:600;">
				<?php esc_html_e( 'View submissions →', 'agnosis' ); ?>
			</a>
		</p>
		<?php endif; ?>
	</td></tr>

	<!-- Footer -->
	<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:15px;color:#999;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
		<?php $work_emails_html = EmailFooter::html(); ?>
		<?php if ( '' !== $work_emails_html ) : ?>
		<div style="margin:16px 0 0;padding-top:14px;border-top:1px solid #eee;">
			<?php echo $work_emails_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::html() escapes each label/address itself. ?>
		</div>
		<?php endif; ?>
		<?php $edit_reminder_html = EmailFooter::edit_reminder_html( $artist_id ); ?>
		<?php if ( '' !== $edit_reminder_html ) : ?>
		<p style="margin:12px 0 0;font-size:15px;color:#888;text-align:center;">
			<?php echo $edit_reminder_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::edit_reminder_html() escapes internally. ?>
		</p>
		<?php endif; ?>
	</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build the From header for a submission-review email.
	 *
	 * Delegates to Core\CommunityMailer — the shared workflow/transactional
	 * sender identity (Settings → Email → "Mail from:"), configured independently
	 * from the Newsletter sender since this is one-off action mail (a review
	 * link), not digest mail. Previously hardcoded to the site name and
	 * admin_email with no way to configure it (2026-07-08).
	 *
	 * @return string e.g. "Agnosis <hello@agnosis.art>"
	 */
	private function sender_header(): string {
		return CommunityMailer::sender_header();
	}

	/**
	 * Return the BCP 47 language tag for use in the HTML <html lang="…"> attribute.
	 *
	 * Converts a WordPress locale string (e.g. 'es_ES', 'zh_TW') to the hyphenated
	 * BCP 47 form expected by browsers and screen readers (e.g. 'es-ES', 'zh-TW').
	 * Defaults to 'en' when no locale is available.
	 *
	 * Must be called AFTER switch_to_locale() so get_locale() returns the
	 * recipient's locale, not the site locale.
	 */
	private function html_lang(): string {
		$locale = get_locale();
		return $locale ? str_replace( '_', '-', $locale ) : 'en';
	}
}
