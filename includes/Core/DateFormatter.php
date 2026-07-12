<?php
/**
 * Locale-natural date formatting for `core/post-date` blocks.
 *
 * WordPress's own date_i18n()/get_the_date() translate month and day NAMES
 * via the current locale's .mo file, but never change the STRUCTURE of a
 * fixed date() format string — so a hardcoded pattern like "j F Y" (day,
 * month, year) renders as a literal transliteration in every language: the
 * translated month name gets slotted into an English-shaped sentence,
 * missing the connector words a reader of that language actually expects
 * (Portuguese/Spanish "de", German's trailing period, Russian's genitive
 * month case, and so on). Reported 2026-07-07 on a Russian-language artwork
 * page ("7 July 2026"-shaped output where "7 июля 2026 г." was expected).
 *
 * PHP's intl extension's IntlDateFormatter is backed by the CLDR locale
 * database and produces a genuinely native date for any locale — correct
 * word order, connectors, and grammatical case — with no per-language
 * format string to author or maintain by hand. Used here when the
 * extension is available; falls back to WordPress's own
 * date_i18n()/date_format option otherwise — the same soft-dependency,
 * graceful-degradation pattern already used for the Imagick extension
 * elsewhere in this plugin (AI\MediaAdapter, Publishing\PostCreator's PDF
 * rasterisation).
 *
 * Applied site-wide via the render_block_core/post-date filter (see
 * Core\Plugin) — every core/post-date block, on any template (artwork,
 * biography, event, the newsletter archive, anywhere else one is used),
 * gets the same locale-natural treatment automatically.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

use Agnosis\Compat\LinguaForge;

class DateFormatter {

	/**
	 * Format a Unix timestamp as a natural, locale-correct long date (day,
	 * full month name, year — no time component), e.g.:
	 *   en → "July 7, 2026"
	 *   pt → "7 de julho de 2026"
	 *   de → "7. Juli 2026"
	 *   ru → "7 июля 2026 г."
	 *
	 * @param int    $timestamp Unix timestamp.
	 * @param string $lang      Two-letter language code (e.g. 'ru', 'pt'). Empty
	 *                          string resolves the current request's language.
	 */
	public static function long_date( int $timestamp, string $lang = '' ): string {
		$lang = '' !== $lang ? $lang : LinguaForge::current_lang();

		if ( class_exists( '\IntlDateFormatter' ) ) {
			try {
				$formatter = new \IntlDateFormatter(
					$lang,
					\IntlDateFormatter::LONG,
					\IntlDateFormatter::NONE
				);
				$formatted = $formatter->format( $timestamp );
			} catch ( \Throwable $e ) {
				$formatted = false;
			}

			if ( is_string( $formatted ) && '' !== $formatted ) {
				return $formatted;
			}
			// $lang not recognised by ICU, or some other formatter failure —
			// fall through to the WordPress fallback below rather than
			// showing nothing.
		}

		// No intl extension (or it failed) — best-effort fallback. Month/day
		// NAMES are still translated correctly via WordPress's own locale
		// files; only the connector words/word order stay English-shaped.
		return date_i18n( get_option( 'date_format', 'F j, Y' ), $timestamp );
	}

	/**
	 * Replace a rendered core/post-date block's displayed text with a
	 * locale-natural long date, preserving every other attribute WordPress
	 * already rendered (wrapper classes/styles, an "isLink" `<a>` wrapper
	 * if the block setting is on) exactly as-is — only the human-visible
	 * date string inside `<time>` is swapped.
	 *
	 * Hooked to render_block_core/post-date (core WordPress applies this
	 * filter to every core/post-date block's output, dynamic or not).
	 *
	 * @param string               $block_content Rendered block HTML.
	 * @param array<string, mixed> $parsed_block  Parsed block data (unused).
	 * @param \WP_Block            $block         Block instance — used for its post context.
	 */
	public static function filter_post_date_block( string $block_content, array $parsed_block, \WP_Block $block ): string {
		unset( $parsed_block );

		$post_id = $block->context['postId'] ?? get_the_ID();
		if ( ! $post_id ) {
			return $block_content;
		}

		$timestamp = get_post_time( 'U', false, (int) $post_id );
		if ( ! $timestamp ) {
			return $block_content;
		}

		$natural = self::long_date( (int) $timestamp );

		$replaced = preg_replace_callback(
			'/(<time\b[^>]*>)(.*?)(<\/time>)/is',
			static function ( array $m ) use ( $natural ): string {
				$inner = $m[2];

				// The "isLink" block setting wraps the date text in an <a>
				// INSIDE the <time> tag — replace only the anchor's inner
				// text so the link itself (href, rel, etc.) survives.
				if ( preg_match( '/^(<a\b[^>]*>)(.*?)(<\/a>)$/is', $inner, $link ) ) {
					return $m[1] . $link[1] . esc_html( $natural ) . $link[3] . $m[3];
				}

				return $m[1] . esc_html( $natural ) . $m[3];
			},
			$block_content
		);

		return null !== $replaced ? $replaced : $block_content;
	}
}
