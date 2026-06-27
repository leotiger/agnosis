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

		$thumbnail_html = '';
		$thumb_id = get_post_thumbnail_id( $post->ID );
		if ( $thumb_id ) {
			$thumb_src = wp_get_attachment_image_src( $thumb_id, 'medium' );
			if ( $thumb_src ) {
				$thumbnail_html = '<img src="' . esc_url( $thumb_src[0] ) . '" alt="" style="max-width:100%;height:auto;border-radius:4px;margin-bottom:20px;">';
			}
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

		<?php if ( $thumbnail_html ) : ?>
			<?php echo $thumbnail_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() applied inside build_email() above. ?>
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
		return add_query_arg(
			[ 'token' => $token ],
			rest_url( 'agnosis/v1/review/' . $post_id . '/' . $action )
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
