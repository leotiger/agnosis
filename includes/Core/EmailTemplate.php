<?php
/**
 * Shared branded HTML email shell.
 *
 * Every HTML email the plugin sends (Publishing\Notification,
 * Artist\AdmissionNotification, Artist\DepartureNotification,
 * Artist\CommunityCapNotification, Artist\CommunityBroadcast, and both
 * Newsletter\Mailer digests) previously hand-rolled the same 600px branded
 * card — DOCTYPE, meta tags, header/body/footer table rows, the
 * `#0d0d12`/`#7c6af7` color pair, and the CTA button style string —
 * independently in each `build_*_body()` method. That worked but meant (a)
 * the same ~20 lines of boilerplate were duplicated a dozen-plus times, and
 * (b) the header/accent colors were literal string constants, so an
 * operator who wanted to re-brand outgoing mail (a common ask once a site
 * has its own visual identity) had no lever anywhere — changing the accent
 * meant editing PHP in a dozen files by hand.
 *
 * This class is that shared shell now — genuinely, for every class listed
 * above: `render()` builds the full HTML document around a caller-supplied
 * body (and optional extra header/footer content), reading every visual
 * value it renders from Settings → Branding instead of a hardcoded literal:
 * header background, accent color, card width, page/card/footer background,
 * primary/secondary text color+size, divider/border color, button text
 * color, and notice/info-box background (`agnosis_email_header_bg`,
 * `agnosis_email_accent`, `agnosis_email_width`, `agnosis_email_body_bg`,
 * `agnosis_email_card_bg`, `agnosis_email_footer_bg`,
 * `agnosis_email_text_color`, `agnosis_email_text_size`,
 * `agnosis_email_text_secondary_color`, `agnosis_email_text_secondary_size`,
 * `agnosis_email_border_color`, `agnosis_email_button_text_color`,
 * `agnosis_email_notice_bg`, `agnosis_email_footer_label_color`), so every
 * email re-brands together the moment any setting changes — not just the
 * shell's own header/body/footer rows, but the decorative notice/quote boxes
 * and dividers every `build_*_body()` method dots throughout its content,
 * which used to carry their own independent `#f9f9f9`/`#eee` literals no
 * setting ever reached. `card()` is
 * the same markup minus the DOCTYPE/html/head/body wrapper, as a reusable
 * fragment — `Newsletter\Mailer::build_body()` calls it directly since
 * `Newsletter\Archive`'s "view this issue online" page needs the identical
 * card without a second document wrapper. `button()` is the matching shared
 * CTA-button renderer, replacing each file's own repeated `$btn_base`
 * style-string pattern. `Core\EmailFooter` (the "work emails" reference card
 * several of the classes above append as `$footer_extra_html`) also reads
 * `accent()`, `footer_label_color()`, and `text_secondary_color()` for its
 * mailto link, bold labels, and description/preferences-link colors, so a
 * rebranded install doesn't end up with the new accent on every button but
 * the old purple/greys still showing in the footer underneath it.
 *
 * `EmailBranding::header_subtitle_color()` (a small public method on the
 * sibling class, not this one) covers one thing Settings → Branding
 * deliberately does NOT expose a raw field for: the muted subtitle line
 * under the header wordmark (`Newsletter\Mailer`'s "Community Newsletter"
 * heading, `Artist\Invitation`'s "You're invited" line). That color has to
 * track `header_bg()`'s own contrast, the same way the wordmark fallback
 * itself does — a fixed literal or an independently-configurable field would
 * both risk the exact invisible-text failure AUDIT-0.9.29.md §2b fixed for
 * the wordmark, just for the subtitle instead.
 *
 * Deliberately NOT touching the destructive/"danger" red (`self::DANGER`)
 * with a setting — unlike the header/accent pair, that color is a
 * semantic signal (an irreversible action: reject, remove, vote-to-remove)
 * rather than a brand choice, and every email in the plugin already uses
 * the exact same red for exactly that meaning. Making it configurable
 * would invite an operator to make destructive actions LESS visually
 * distinct, which is the opposite of what it's for. Also deliberately fixed:
 * the white backing panel behind an uploaded logo (`EmailBranding`) — a
 * functional contrast device against the (possibly dark) header bar, not a
 * brand choice, so it stays white regardless of card_bg().
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class EmailTemplate {

	/** Semantic color for destructive/irreversible actions — not brand-configurable; see class docblock. */
	public const DANGER = '#c0392b';

	/** Default header background, used until an operator sets Settings → Branding → "Header background". */
	private const DEFAULT_HEADER_BG = '#0d0d12';

	/** Default accent color, used until an operator sets Settings → Branding → "Accent color". */
	private const DEFAULT_ACCENT = '#7c6af7';

	/** Default card width in px, used until an operator sets Settings → Branding → "Email width (px)". */
	private const DEFAULT_WIDTH = 600;

	/** Default page (letterbox) background, used until an operator sets Settings → Branding → "Page background color". */
	private const DEFAULT_BODY_BG = '#f5f5f5';

	/** Default card background, used until an operator sets Settings → Branding → "Card background color". */
	private const DEFAULT_CARD_BG = '#ffffff';

	/** Default primary text color, used until an operator sets Settings → Branding → "Primary text color". */
	private const DEFAULT_TEXT_COLOR = '#222222';

	/** Default primary text size in px, used until an operator sets Settings → Branding → "Primary text size (px)". */
	private const DEFAULT_TEXT_SIZE = 16;

	/** Default secondary (footer/muted) text color, used until an operator sets Settings → Branding → "Secondary text color". */
	private const DEFAULT_TEXT_SECONDARY_COLOR = '#bbbbbb';

	/** Default secondary text size in px, used until an operator sets Settings → Branding → "Secondary text size (px)". */
	private const DEFAULT_TEXT_SECONDARY_SIZE = 14;

	/** Default footer background, used until an operator sets Settings → Branding → "Footer background color". */
	private const DEFAULT_FOOTER_BG = '#ffffff';

	/** Default divider/border color, used until an operator sets Settings → Branding → "Divider / border color". */
	private const DEFAULT_BORDER_COLOR = '#eeeeee';

	/** Default button text color, used until an operator sets Settings → Branding → "Button text color". */
	private const DEFAULT_BUTTON_TEXT_COLOR = '#ffffff';

	/** Default notice/info box background, used until an operator sets Settings → Branding → "Notice box background color". */
	private const DEFAULT_NOTICE_BG = '#f9f9f9';

	/** Default footer label color, used until an operator sets Settings → Branding → "Footer label color". */
	private const DEFAULT_FOOTER_LABEL_COLOR = '#555555';

	/**
	 * The configured (or default) header background color.
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

	/** The configured (or default) accent color. See header_bg()'s docblock for the sanitize-fallback reasoning. */
	public static function accent(): string {
		$stored    = (string) get_option( 'agnosis_email_accent', self::DEFAULT_ACCENT );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_ACCENT;
	}

	/** The configured (or default) card width in px, clamped to the same 320–800 range Admin\Settings sanitizes to. */
	public static function width(): int {
		$stored = (int) get_option( 'agnosis_email_width', self::DEFAULT_WIDTH );
		return $stored >= 320 && $stored <= 800 ? $stored : self::DEFAULT_WIDTH;
	}

	/** The configured (or default) page/letterbox background color, surrounding the card. See header_bg()'s docblock. */
	public static function body_bg(): string {
		$stored    = (string) get_option( 'agnosis_email_body_bg', self::DEFAULT_BODY_BG );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_BODY_BG;
	}

	/** The configured (or default) card background color. See header_bg()'s docblock. */
	public static function card_bg(): string {
		$stored    = (string) get_option( 'agnosis_email_card_bg', self::DEFAULT_CARD_BG );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_CARD_BG;
	}

	/** The configured (or default) primary/body text color. See header_bg()'s docblock. */
	public static function text_color(): string {
		$stored    = (string) get_option( 'agnosis_email_text_color', self::DEFAULT_TEXT_COLOR );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_TEXT_COLOR;
	}

	/** The configured (or default) primary text size in px, clamped to the same 10–24 range Admin\Settings sanitizes to. */
	public static function text_size(): int {
		$stored = (int) get_option( 'agnosis_email_text_size', self::DEFAULT_TEXT_SIZE );
		return $stored >= 10 && $stored <= 24 ? $stored : self::DEFAULT_TEXT_SIZE;
	}

	/** The configured (or default) secondary/muted text color (footer tagline). See header_bg()'s docblock. */
	public static function text_secondary_color(): string {
		$stored    = (string) get_option( 'agnosis_email_text_secondary_color', self::DEFAULT_TEXT_SECONDARY_COLOR );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_TEXT_SECONDARY_COLOR;
	}

	/** The configured (or default) secondary text size in px, clamped to the same 10–20 range Admin\Settings sanitizes to. */
	public static function text_secondary_size(): int {
		$stored = (int) get_option( 'agnosis_email_text_secondary_size', self::DEFAULT_TEXT_SECONDARY_SIZE );
		return $stored >= 10 && $stored <= 20 ? $stored : self::DEFAULT_TEXT_SECONDARY_SIZE;
	}

	/** The configured (or default) footer background color — independent of card_bg(), so the footer can be set off from the body. See header_bg()'s docblock. */
	public static function footer_bg(): string {
		$stored    = (string) get_option( 'agnosis_email_footer_bg', self::DEFAULT_FOOTER_BG );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_FOOTER_BG;
	}

	/** The configured (or default) divider/border color — body/footer divider, notice-row divider, work-addresses card divider. See header_bg()'s docblock. */
	public static function border_color(): string {
		$stored    = (string) get_option( 'agnosis_email_border_color', self::DEFAULT_BORDER_COLOR );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_BORDER_COLOR;
	}

	/** The configured (or default) button text color. See header_bg()'s docblock. */
	public static function button_text_color(): string {
		$stored    = (string) get_option( 'agnosis_email_button_text_color', self::DEFAULT_BUTTON_TEXT_COLOR );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_BUTTON_TEXT_COLOR;
	}

	/** The configured (or default) notice/info box background color — quoted bios/statements, "did you mean" suggestions, and similar callouts. See header_bg()'s docblock. */
	public static function notice_bg(): string {
		$stored    = (string) get_option( 'agnosis_email_notice_bg', self::DEFAULT_NOTICE_BG );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_NOTICE_BG;
	}

	/** The configured (or default) footer label color — the bold address labels in EmailFooter's work-addresses card. See header_bg()'s docblock. */
	public static function footer_label_color(): string {
		$stored    = (string) get_option( 'agnosis_email_footer_label_color', self::DEFAULT_FOOTER_LABEL_COLOR );
		$sanitized = sanitize_hex_color( $stored );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : self::DEFAULT_FOOTER_LABEL_COLOR;
	}

	/**
	 * Render one CTA button/link.
	 *
	 * Defaults to a solid accent-colored button (the shape almost every
	 * email uses for its primary action). Pass `'bg' => self::DANGER` for a
	 * destructive primary action (e.g. "Confirm removal"), or
	 * `'bg' => '#fff', 'color' => self::DANGER, 'border' => self::DANGER`
	 * for the outline "secondary/reject" shape (e.g. "Vote NO").
	 *
	 * @param array{bg?: string, color?: string, border?: string, padding?: string, margin?: string} $style
	 */
	public static function button( string $url, string $label, array $style = [] ): string {
		$bg      = $style['bg'] ?? self::accent();
		$color   = $style['color'] ?? self::button_text_color();
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
	 * Render just the branded "card" — the two nested `<table>`s, with no
	 * surrounding DOCTYPE/html/head/body — as a reusable fragment.
	 *
	 * `render()` below wraps this in the full document for a normal
	 * `wp_mail()` send. `Newsletter\Mailer::build_body()` calls this directly
	 * instead, since `Newsletter\Archive`'s "view this issue online" page
	 * (rendered via `wp_die()`) needs the identical card markup without a
	 * second DOCTYPE/html/head/body wrapped around it.
	 *
	 * $body_html, $footer_extra_html, $header_extra_html, and $notice_row_html
	 * are raw HTML, already built and escaped by the caller — this class only
	 * owns the shell, not the per-email content.
	 *
	 * @param string $body_html         Raw HTML for the card's body row.
	 * @param string $footer_extra_html Optional raw HTML appended below the standard "{site} — art blooming out of oblivion" line (e.g. EmailFooter's address list, edit reminder, preferences link).
	 * @param string $header_extra_html Optional raw HTML appended inside the header cell, below the logo/wordmark — e.g. Newsletter\Mailer's "Community Newsletter" heading, Artist\Invitation's "You're invited" line.
	 * @param string $notice_row_html   Optional complete extra `<tr><td>…</td></tr>` inserted between the header and body rows — its own background/padding, not folded into the body cell. Only Newsletter\Mailer's "having trouble viewing this email? View it online." banner uses this today.
	 */
	public static function card( string $body_html, string $footer_extra_html = '', string $header_extra_html = '', string $notice_row_html = '' ): string {
		$site_name  = get_bloginfo( 'name' );
		$header_bg  = self::header_bg();
		$width      = self::width();
		$body_bg    = self::body_bg();
		$card_bg    = self::card_bg();
		$footer_bg  = self::footer_bg();
		$border     = self::border_color();
		$sec_color  = self::text_secondary_color();
		$sec_size   = self::text_secondary_size();

		$footer_tagline = sprintf(
			/* translators: %s: site name */
			esc_html__( '%s — art blooming out of oblivion', 'agnosis' ),
			esc_html( $site_name )
		);

		return '<table width="100%" cellpadding="0" cellspacing="0" style="background:' . esc_attr( $body_bg ) . ';padding:40px 0;">'
			. '<tr><td align="center" style="background:' . esc_attr( $body_bg ) . ';">'
			. '<table width="' . esc_attr( (string) $width ) . '" cellpadding="0" cellspacing="0" style="background:' . esc_attr( $card_bg ) . ';border-radius:8px;overflow:hidden;max-width:' . esc_attr( (string) $width ) . 'px;width:100%;">'
			. '<tr><td style="background:' . esc_attr( $header_bg ) . ';padding:28px 24px;">'
			. EmailBranding::header_html() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailBranding::header_html() escapes internally.
			. $header_extra_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-built and already escaped, matching $footer_extra_html's convention below.
			. '</td></tr>'
			. $notice_row_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-built and already escaped, a complete <tr><td>…</td></tr> or ''.
			. '<tr><td style="background:' . esc_attr( $card_bg ) . ';padding:36px 24px;">'
			. $body_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-built and already escaped, matching every pre-existing build_*_body() convention.
			. '</td></tr>'
			. '<tr><td style="background:' . esc_attr( $footer_bg ) . ';padding:20px 24px;border-top:1px solid ' . esc_attr( $border ) . ';">'
			. '<p style="margin:0;font-size:' . esc_attr( (string) $sec_size ) . 'px;color:' . esc_attr( $sec_color ) . ';text-align:center;">' . $footer_tagline . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $footer_tagline is built from esc_html() above.
			. $footer_extra_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-built and already escaped, matching every pre-existing build_*_body() convention.
			. '</td></tr>'
			. '</table>'
			. '</td></tr>'
			. '</table>';
	}

	/**
	 * Render the full branded HTML email document around $body_html — the
	 * DOCTYPE/html/head/body wrapper plus `card()`'s output.
	 *
	 * @param string $html_lang         BCP 47 language tag — see html_lang() helpers on the calling classes; must be called AFTER switch_to_locale().
	 * @param string $body_html         Raw HTML for the card's body row.
	 * @param string $footer_extra_html Optional raw HTML appended below the standard "{site} — art blooming out of oblivion" line (e.g. EmailFooter's address list, edit reminder, preferences link).
	 * @param string $header_extra_html Optional raw HTML appended inside the header cell, below the logo/wordmark — see card()'s docblock.
	 * @param string $notice_row_html   Optional complete extra `<tr><td>…</td></tr>` inserted between the header and body rows — see card()'s docblock.
	 */
	public static function render( string $html_lang, string $body_html, string $footer_extra_html = '', string $header_extra_html = '', string $notice_row_html = '' ): string {
		$body_bg   = self::body_bg();
		$text      = self::text_color();
		$text_size = self::text_size();

		return '<!DOCTYPE html>'
			. '<html lang="' . esc_attr( $html_lang ) . '">'
			. '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"></head>'
			. '<body style="margin:0;padding:0;background:' . esc_attr( $body_bg ) . ';font-family:Georgia,serif;color:' . esc_attr( $text ) . ';font-size:' . esc_attr( (string) $text_size ) . 'px;">'
			. self::card( $body_html, $footer_extra_html, $header_extra_html, $notice_row_html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card() escapes internally.
			. '</body>'
			. '</html>';
	}
}
