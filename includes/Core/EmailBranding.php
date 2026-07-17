<?php
/**
 * Shared branded email header — used by every HTML email template the plugin
 * sends (Publishing\Notification's three artist notifications, and
 * Newsletter\Mailer's digest emails), so a logo configured once in
 * Settings → Branding shows up consistently everywhere instead of each
 * template independently deciding what to render.
 *
 * The text-wordmark fallback's color is derived from the configured header
 * background's own relative luminance (see header_text_color()) rather than
 * a fixed white — an operator who picks a light "Header background" (Settings
 * → Branding), a plausible choice for a light-themed site, would otherwise
 * get invisible white-on-white header text on every single outgoing email,
 * including the admission-confirmation email new applicants must click.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class EmailBranding {

	/** Maximum rendered height of the logo in outgoing emails, in pixels. */
	private const LOGO_MAX_HEIGHT = 150;

	/**
	 * Markup for the colored header bar's brand mark: the configured logo
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
				// Derived from the configured card width, not a percentage:
				// every template that calls this method gives the header
				// <td> the same `padding:28px 24px`, so its inner content
				// width is exactly EmailTemplate::width() − 24px − 24px. A
				// literal-width table fills that exactly, flush against both
				// padding edges simultaneously — centered by construction,
				// with no slack to be off-center, and no percentage-width
				// guessing for email clients to get wrong. The image is
				// left-aligned inside it automatically (a block-level
				// element starts at its container's left edge). Deliberately
				// always white, not the configurable card background — the
				// point of this panel is contrast against the (possibly
				// dark) header bar behind it, not to match the card.
				$panel_width = EmailTemplate::width() - 48;
				return '<table width="' . esc_attr( (string) $panel_width ) . '" cellpadding="0" cellspacing="0" style="width:' . esc_attr( (string) $panel_width ) . 'px;">'
					. '<tr><td style="background:#ffffff;padding:12px 0;">'
					. $img
					. '</td></tr></table>';
			}
		}

		return sprintf(
			'<span style="font-size:24px;font-weight:700;color:%s;letter-spacing:.02em;">✦ %s</span>',
			esc_attr( self::header_text_color() ),
			esc_html( $site_name )
		);
	}

	/**
	 * Text color for the wordmark fallback above, chosen for contrast against
	 * the configured "Header background" (Settings → Branding) instead of a
	 * fixed white — audit AUDIT-0.9.29.md §2b: a light background (a plausible
	 * "match my site" pick, since plenty of art sites are light-themed) made
	 * the fallback invisible white-on-white in every email, since nothing
	 * downstream ever looked at what background it was actually rendering on.
	 *
	 * WCAG relative luminance (not a full contrast-ratio calculation, which
	 * would also need to know the exact white it's compared against — this is
	 * the same "~0.5 threshold" shape the audit itself suggested, self-healing
	 * and needing no operator education): below ~0.5 the background reads as
	 * dark, so white text stays white; at or above, the background reads as
	 * light, so the text switches to the same near-black
	 * (`EmailTemplate::header_bg()`'s own default) used everywhere else in the
	 * plugin as the "dark ink" color. A malformed/unsanitized background
	 * (shouldn't happen — `EmailTemplate::header_bg()` already re-validates
	 * with `sanitize_hex_color()`) falls back to white, the original always-on
	 * default, rather than guessing.
	 */
	private static function header_text_color(): string {
		$hex = ltrim( EmailTemplate::header_bg(), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#fff';
		}

		$luminance = 0;
		foreach ( [ 0, 2, 4 ] as $offset ) {
			$channel    = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channel    = $channel <= 0.03928 ? $channel / 12.92 : ( ( $channel + 0.055 ) / 1.055 ) ** 2.4;
			$luminance += ( 0 === $offset ? 0.2126 : ( 2 === $offset ? 0.7152 : 0.0722 ) ) * $channel;
		}

		return $luminance >= 0.5 ? '#0d0d12' : '#fff';
	}

	/**
	 * Text color for the small subtitle line some emails render under the
	 * header wordmark (Newsletter\Mailer's "Community Newsletter"/"Newsletter"
	 * heading, Artist\Invitation's "You're invited" line) — a muted variant of
	 * header_text_color() rather than a fixed literal, for the same reason
	 * header_text_color() itself isn't fixed white: both classes used to
	 * hardcode `color:#ece9ff` (a light lavender tint) regardless of
	 * header_bg(), which reads fine against the dark default but goes
	 * low-contrast-to-invisible against a light "Header background" pick —
	 * the identical failure shape §2b fixed for the wordmark itself, just not
	 * caught there since neither of these two call sites route through
	 * header_html().
	 *
	 * Public (unlike header_text_color()) since it's read from outside this
	 * class — Newsletter\Mailer and Artist\Invitation are the two current
	 * callers, both building `$header_extra_html` for EmailTemplate::render().
	 */
	public static function header_subtitle_color(): string {
		return '#fff' === self::header_text_color() ? '#ece9ff' : '#4a4a56';
	}
}
