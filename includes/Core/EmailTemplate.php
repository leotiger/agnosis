<?php
/**
 * Shared branded HTML email shell.
 *
 * Every HTML email the plugin sends (Publishing\Notification,
 * Artist\AdmissionNotification, Artist\DepartureNotification,
 * Artist\CommunityCapNotification, Artist\CommunityBroadcast, and both
 * Newsletter\Mailer digests) previously hand-rolled the same 600px branded
 * card — DOCTYPE, meta tags, header/body/footer table rows, the
 * `#0d0d12`/`#7c6af7` colour pair, and the CTA button style string —
 * independently in each `build_*_body()` method. That worked but meant (a)
 * the same ~20 lines of boilerplate were duplicated a dozen-plus times, and
 * (b) the header/accent colours were literal string constants, so an
 * operator who wanted to re-brand outgoing mail (a common ask once a site
 * has its own visual identity) had no lever anywhere — changing the accent
 * meant editing PHP in a dozen files by hand.
 *
 * This class is that shared shell now: `render()` builds the full HTML
 * document around a caller-supplied body (and optional extra footer
 * content), reading its header background and accent colour from Settings →
 * General → Branding (`agnosis_email_header_bg`/`agnosis_email_accent`,
 * both new) instead of a hardcoded literal, so every email re-brands
 * together the moment either setting changes. `button()` is the matching
 * shared CTA-button renderer, replacing each file's own repeated
 * `$btn_base` style-string pattern.
 *
 * Deliberately NOT touching the destructive/"danger" red (`self::DANGER`)
 * with a setting — unlike the header/accent pair, that colour is a
 * semantic signal (an irreversible action: reject, remove, vote-to-remove)
 * rather than a brand choice, and every email in the plugin already uses
 * the exact same red for exactly that meaning. Making it configurable
 * would invite an operator to make destructive actions LESS visually
 * distinct, which is the opposite of what it's for.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class EmailTemplate {

	/** Semantic colour for destructive/irreversible actions — not brand-configurable; see class docblock. */
	public const DANGER = '#c0392b';

	/** Default header background, used until an operator sets Settings → General → Branding → "Email header background". */
	private const DEFAULT_HEADER_BG = '#0d0d12';

	/** Default accent colour, used until an operator sets Settings → General → Branding → "Email accent color". */
	private const DEFAULT_ACCENT = '#7c6af7';

	/**
	 * The configured (or default) header background colour.
	 *
	 * `sanitize_hex_color()` returns null for anything that isn't a real
	 * `#rgb`/`#rrggbb` value — defends against a stored value that
	 * predates validation, or a direct `update_option()` call bypassing
	 * Settings' own `sanitize_hex_color()` callback (see field_definitions()
	 * in Admin\Settings).
	 */
	public static function header_bg(): string {
		$stored   = (string) get_option( 'agnosis_email_header_bg', self::DEFAULT_HEADER_BG );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_HEADER_BG;
	}

	/** The configured (or default) accent colour. See header_bg()'s docblock for the sanitize-fallback reasoning. */
	public static function accent(): string {
		$stored    = (string) get_option( 'agnosis_email_accent', self::DEFAULT_ACCENT );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_ACCENT;
	}

	/**
	 * Render one CTA button/link.
	 *
	 * Defaults to a solid accent-coloured button (the shape almost every
	 * email uses for its primary action). Pass `'bg' => self::DANGER` for a
	 * destructive primary action (e.g. "Confirm removal"), or
	 * `'bg' => '#fff', 'color' => self::DANGER, 'border' => self::DANGER`
	 * for the outline "secondary/reject" shape (e.g. "Vote NO").
	 *
	 * @param array{bg?: string, color?: string, border?: string, padding?: string, margin?: string} $style
	 */
	public static function button( string $url, string $label, array $style = [] ): string {
		$bg      = $style['bg'] ?? self::accent();
		$color   = $style['color'] ?? '#fff';
		$padding = $style['padding'] ?? '12px 24px';
		$margin  = $style['margin'] ?? '6px 4px';
		$border  = isset( $style['border'] ) ? 'border:1px solid ' . esc_attr( $style['border'] ) . ';' : '';

		return sprintf(
			'<a href="%s" style="display:inline-block;padding:%s;border-radius:6px;font-size:17px;font-weight:600;text-decoration:none;margin:%s;background:%s;color:%s;%s">%s</a>',
			esc_url( $url ),
			esc_attr( $padding ),
			esc_attr( $margin ),
			esc_attr( $bg ),
			esc_attr( $color ),
			$border,
			esc_html( $label )
		);
	}

	/**
	 * Render the full branded HTML email document around $body_html.
	 *
	 * $body_html and $footer_extra_html are raw HTML, already built and
	 * escaped by the caller (this mirrors how every existing `build_*_body()`
	 * method already composes its own body — this class only owns the
	 * shell, not the per-email content).
	 *
	 * @param string $html_lang        BCP 47 language tag — see html_lang() helpers on the calling classes; must be called AFTER switch_to_locale().
	 * @param string $body_html        Raw HTML for the card's body row.
	 * @param string $footer_extra_html Optional raw HTML appended below the standard "{site} — art blooming out of oblivion" line (e.g. EmailFooter's address list, edit reminder, preferences link).
	 */
	public static function render( string $html_lang, string $body_html, string $footer_extra_html = '' ): string {
		$site_name = get_bloginfo( 'name' );
		$header_bg = self::header_bg();

		$footer_tagline = sprintf(
			/* translators: %s: site name */
			esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
			esc_html( $site_name )
		);

		return '<!DOCTYPE html>'
			. '<html lang="' . esc_attr( $html_lang ) . '">'
			. '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>'
			. '<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,serif;color:#222;">'
			. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0;">'
			. '<tr><td align="center" style="background:#f5f5f5;">'
			. '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">'
			. '<tr><td style="background:' . esc_attr( $header_bg ) . ';padding:28px 24px;">'
			. EmailBranding::header_html() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally.
			. '</td></tr>'
			. '<tr><td style="background:#ffffff;padding:36px 24px;">'
			. $body_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-built and already escaped, matching every pre-existing build_*_body() convention.
			. '</td></tr>'
			. '<tr><td style="background:#ffffff;padding:20px 24px;border-top:1px solid #eee;">'
			. '<p style="margin:0;font-size:14px;color:#bbb;text-align:center;">' . $footer_tagline . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $footer_tagline is built from esc_html() above.
			. $footer_extra_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-built and already escaped, matching every pre-existing build_*_body() convention.
			. '</td></tr>'
			. '</table>'
			. '</td></tr>'
			. '</table>'
			. '</body>'
			. '</html>';
	}
}
