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
 * Tokens expire after 7 days (same window as the review_expiry meta).
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

class Notification {

	/**
	 * Hook callback for 'agnosis_removal_requested'.
	 *
	 * Sends the artist a signed confirmation email. The post is not touched
	 * until the artist clicks the confirm link — removal is their decision alone.
	 *
	 * @param int $post_id   The artwork post ID.
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

		$subject = sprintf(
			/* translators: %s: artwork title */
			__( '[Agnosis] Confirm removal of: %s', 'agnosis' ),
			$post->post_title
		);

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $this->sender_header(),
		];

		wp_mail( $artist->user_email, $subject, $this->build_removal_email( $post, $artist->display_name, $token ), $headers );
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

		$to      = $artist->user_email;
		$subject = sprintf(
			/* translators: %s: artwork title */
			__( '[Agnosis] Your submission is ready to review: %s', 'agnosis' ),
			$post->post_title
		);

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $this->sender_header(),
		];

		$body = $this->build_email( $post, $artist->display_name, (string) $token );

		wp_mail( $to, $subject, $body, $headers );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the removal confirmation email body.
	 *
	 * @param \WP_Post $post         The artwork post requested for removal.
	 * @param string   $artist_name  Artist's display name.
	 * @param string   $token        Signed removal token stored in post meta.
	 * @return string HTML email body.
	 */
	private function build_removal_email( \WP_Post $post, string $artist_name, string $token ): string {
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
		$btn_base  = 'display:inline-block;padding:12px 24px;border-radius:6px;font-size:15px;font-weight:600;text-decoration:none;margin:6px 4px;';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<!-- Header -->
	<tr><td style="background:#7c6af7;padding:28px 40px;">
		<span style="font-size:22px;font-weight:700;color:#fff;letter-spacing:.02em;">✦ <?php echo esc_html( $site_name ); ?></span>
	</td></tr>

	<!-- Body -->
	<tr><td style="padding:36px 40px;">
		<p style="margin:0 0 20px;font-size:16px;color:#555;">
			<?php
			printf(
				/* translators: %s: artist display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $artist_name )
			);
			?>
		</p>

		<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#555;">
			<?php esc_html_e( 'We received a removal request for the following artwork:', 'agnosis' ); ?>
		</p>

		<p style="margin:0 0 28px;padding:16px 20px;background:#f9f9f9;border-left:3px solid #7c6af7;border-radius:4px;font-size:17px;font-weight:600;color:#111;">
			<?php echo esc_html( $title ); ?>
		</p>

		<p style="margin:0 0 28px;font-size:15px;line-height:1.6;color:#555;">
			<?php esc_html_e( 'If you want to permanently remove this artwork from the gallery, click the button below. This action cannot be undone.', 'agnosis' ); ?>
		</p>

		<table cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
		<tr><td>
			<a href="<?php echo esc_url( $confirm_url ); ?>" style="<?php echo esc_attr( $btn_base ); ?>background:<?php echo esc_attr( $accent ); ?>;color:#fff;">
				<?php esc_html_e( 'Yes, remove this artwork', 'agnosis' ); ?>
			</a>
		</td></tr>
		</table>

		<p style="font-size:13px;color:#999;margin:0 0 12px;padding:14px 16px;background:#fef9f9;border-radius:6px;border:1px solid #fad7d7;">
			<?php esc_html_e( 'If you did not request this removal, simply ignore this email — your artwork will remain published.', 'agnosis' ); ?>
		</p>

		<p style="font-size:13px;color:#999;margin:0;">
			<?php esc_html_e( 'This confirmation link expires in 7 days.', 'agnosis' ); ?>
		</p>
	</td></tr>

	<!-- Footer -->
	<tr><td style="padding:20px 40px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:12px;color:#bbb;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
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
	private function build_email( \WP_Post $post, string $artist_name, string $token ): string {
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
			$quality_html = '<div style="background:#fef9ec;border-left:3px solid #f0a500;padding:14px 16px;border-radius:4px;margin:0 0 24px;font-size:14px;color:#555;">'
				. '<strong style="color:#8a6200;">'
				. esc_html__( '📷 Photo enhanced automatically', 'agnosis' )
				. '</strong>'
				. '<p style="margin:8px 0 6px;">'
				. esc_html__( 'We detected some photographic issues and applied AI correction to improve visibility of your artwork:', 'agnosis' )
				. '</p>'
				. '<ul style="margin:0;padding-left:18px;">' . $issue_items . '</ul>'
				. '<p style="margin:8px 0 0;font-size:13px;color:#888;">'
				. esc_html__( 'The artwork itself has not been altered — only the photograph quality was corrected.', 'agnosis' )
				. '</p>'
				. '</div>';
		} elseif ( $quality_score > 0 && ! $was_enhanced ) {
			$quality_html = '<p style="font-size:13px;color:#888;margin:0 0 20px;">'
				. sprintf(
					/* translators: %d: photo quality score out of 10 */
					esc_html__( '📷 Photo quality score: %d/10', 'agnosis' ),
					$quality_score
				)
				. '</p>';
		}

		$site_name = esc_html( get_bloginfo( 'name' ) );
		$title     = esc_html( $post->post_title );
		$excerpt   = esc_html( $post->post_excerpt );
		// Strip blocks / shortcodes for the email body preview.
		$body_preview = esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ), 80 ) );

		$accent   = '#7c6af7';
		$btn_base = 'display:inline-block;padding:12px 24px;border-radius:6px;font-size:15px;font-weight:600;text-decoration:none;margin:6px 4px;';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

	<!-- Header -->
	<tr><td style="background:<?php echo esc_attr( $accent ); ?>;padding:28px 40px;">
		<span style="font-size:22px;font-weight:700;color:#fff;letter-spacing:.02em;">✦ <?php echo esc_html( $site_name ); ?></span>
	</td></tr>

	<!-- Body -->
	<tr><td style="padding:36px 40px;">
		<p style="margin:0 0 20px;font-size:16px;color:#555;">
			<?php
			printf(
				/* translators: %s: artist display name */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $artist_name )
			);
			?>
		</p>
		<p style="margin:0 0 28px;font-size:16px;line-height:1.6;color:#555;">
			<?php esc_html_e( "Your submission has been processed. Here's what our AI curator came up with — take a look and let us know if it's ready to publish.", 'agnosis' ); ?>
		</p>

		<?php if ( $images_html ) : ?>
			<?php echo $images_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() and (int) applied per image above. ?>
		<?php endif; ?>

		<?php if ( $quality_html ) : ?>
			<?php echo $quality_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped via esc_html() and esc_html__() above. ?>
		<?php endif; ?>

		<h2 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#111;"><?php echo esc_html( $title ); ?></h2>

		<?php if ( $excerpt ) : ?>
		<p style="margin:0 0 16px;font-size:15px;font-style:italic;color:#666;border-left:3px solid <?php echo esc_attr( $accent ); ?>;padding-left:14px;"><?php echo esc_html( $excerpt ); ?></p>
		<?php endif; ?>

		<?php if ( $body_preview ) : ?>
		<p style="margin:0 0 32px;font-size:15px;line-height:1.7;color:#444;"><?php echo esc_html( $body_preview ); ?>&hellip;</p>
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
		<p style="font-size:14px;color:#666;margin:0 0 24px;padding:14px 16px;background:#f9f9f9;border-radius:6px;">
			<?php esc_html_e( 'Want to tweak the title, text or tags before publishing?', 'agnosis' ); ?>
			<a href="<?php echo esc_url( $submissions_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;font-weight:600;">
				<?php esc_html_e( 'Open your submissions page →', 'agnosis' ); ?>
			</a>
		</p>
		<?php endif; ?>

		<p style="font-size:13px;color:#999;margin:0;">
			<?php esc_html_e( 'The Publish and Discard links above expire in 7 days. Your submission stays as a draft until you decide.', 'agnosis' ); ?>
		</p>
	</td></tr>

	<!-- Footer -->
	<tr><td style="padding:20px 40px;border-top:1px solid #eee;">
		<p style="margin:0;font-size:12px;color:#bbb;text-align:center;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
				esc_html( $site_name )
			);
			?>
		</p>
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

	/**
	 * Build the From header using the site's admin email and name.
	 *
	 * @return string e.g. "My Site <noreply@example.com>"
	 */
	private function sender_header(): string {
		return sprintf( '%s <%s>', get_bloginfo( 'name' ), get_option( 'admin_email' ) );
	}
}
