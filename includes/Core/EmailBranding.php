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

	/**
	 * Markup for the coloured header bar's brand mark: the configured logo
	 * (Settings → General → Email logo) when one is set, or the plain-text
	 * "✦ Site Name" wordmark every template used before this setting existed.
	 *
	 * Capped at 40px tall via inline style (email clients routinely strip
	 * `<style>` blocks and external CSS, so this has to be inline) so a logo
	 * of any width or aspect ratio fits the same header bar height the text
	 * wordmark already used — no per-image sizing decision needed from
	 * whoever uploads it.
	 */
	public static function header_html(): string {
		$site_name = get_bloginfo( 'name' );
		$logo_id   = (int) get_option( 'agnosis_email_logo_id', 0 );

		if ( $logo_id ) {
			$src = wp_get_attachment_image_src( $logo_id, 'medium' );
			if ( $src ) {
				return sprintf(
					'<img src="%s" alt="%s" style="display:block;max-height:40px;width:auto;border:0;">',
					esc_url( $src[0] ),
					esc_attr( $site_name )
				);
			}
		}

		return sprintf(
			'<span style="font-size:22px;font-weight:700;color:#fff;letter-spacing:.02em;">✦ %s</span>',
			esc_html( $site_name )
		);
	}
}
