<?php
/**
 * Shared branded email header — used by every HTML email template the plugin
 * sends (Publishing\Notification's three artist notifications, and
 * Newsletter\Mailer's digest emails), so a logo configured once in
 * Settings → General shows up consistently everywhere instead of each
 * template independently deciding what to render.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class EmailBranding {

	/** Maximum rendered height of the logo in outgoing emails, in pixels. */
	private const LOGO_MAX_HEIGHT = 150;

	/**
	 * Markup for the coloured header bar's brand mark: the configured logo
	 * (Settings → General → Email logo) when one is set, or the plain-text
	 * "✦ Site Name" wordmark every template used before this setting existed.
	 *
	 * Capped at LOGO_MAX_HEIGHT via inline style (email clients routinely
	 * strip `<style>` blocks and external CSS, so this has to be inline) so a
	 * logo of any width or aspect ratio fits a consistent header bar height —
	 * no per-image sizing decision needed from whoever uploads it. Chosen to
	 * accommodate a full banner-style logo (wordmark + tagline baked into the
	 * image, not just an icon); a compact square icon will simply render
	 * smaller than this ceiling since width scales proportionally.
	 */
	public static function header_html(): string {
		$site_name = get_bloginfo( 'name' );
		$logo_id   = (int) get_option( 'agnosis_email_logo_id', 0 );

		if ( $logo_id ) {
			// 'full', not a named size like 'medium'/'large' — those are
			// bounding boxes that WordPress never upscales past, so for a wide
			// banner logo (much wider than tall) the generated file can easily
			// land shorter than LOGO_MAX_HEIGHT even at 'large' (1024×1024).
			// max-height can only shrink an image, never enlarge one, so any
			// named size risks silently undercutting the configured height.
			// 'full' is simply whatever the uploaded file's actual resolution
			// is — always the best available source for the CSS cap to work
			// from. (If the logo is STILL small at this height, the uploaded
			// file itself doesn't have enough native pixels — a code-side cap
			// can't manufacture resolution that isn't there; re-export the
			// logo at a higher resolution.)
			$src = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( $src ) {
				$img = sprintf(
					'<img src="%s" alt="%s" style="display:block;max-height:%dpx;width:auto;border:0;">',
					esc_url( $src[0] ),
					esc_attr( $site_name ),
					self::LOGO_MAX_HEIGHT
				);

				// White backing panel for the logo — the uploaded file has a
				// white background baked in (a transparent PNG didn't render
				// cleanly against the dark header), so this gives it a clean
				// rectangle instead of the dark header bleeding through.
				// Fixed at 552px on purpose, not a percentage: every template
				// that calls this method gives the header <td> the same
				// `padding:28px 24px`, so its inner content width is exactly
				// 600px (the card) − 24px − 24px = 552px — identical to the
				// body content width below. A literal 552 table fills that
				// exactly, flush against both padding edges simultaneously —
				// centered by construction, with no slack to be off-centre,
				// and no percentage-width guessing for email clients to get
				// wrong. The image is left-aligned inside it automatically
				// (a block-level element starts at its container's left edge).
				return '<table width="552" cellpadding="0" cellspacing="0" style="width:552px;">'
					. '<tr><td style="background:#ffffff;padding:12px 0;">'
					. $img
					. '</td></tr></table>';
			}
		}

		return sprintf(
			'<span style="font-size:24px;font-weight:700;color:#fff;letter-spacing:.02em;">✦ %s</span>',
			esc_html( $site_name )
		);
	}
}
