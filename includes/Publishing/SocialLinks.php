<?php
/**
 * Auto-detects a social/profile platform from a bare URL and renders an icon
 * row for it using WordPress core's own Social Icons block — no custom SVG
 * set, no bespoke styling, just `core/social-links`/`core/social-link`
 * markup built procedurally (same "build block markup as a string" technique
 * `PostCreator` already uses for images/embeds/galleries) and handed to
 * `do_blocks()` so core's real icons and default styling render it.
 *
 * Used by `Publishing\ReviewConfirm` (biography approve form — just stores
 * the raw URLs, no detection needed there) and `Artist\Profile`'s
 * `agnosis/biography-social-links` render callback (detection + rendering,
 * at display time — the URL is the only stored value; the service is always
 * re-derived from it, never cached, so there is nothing to keep in sync).
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SocialLinks {

	/**
	 * Hostname (bare, no "www.") => core/social-link service slug.
	 *
	 * Deliberately not exhaustive of every service core supports — just the
	 * platforms an artist is realistically going to paste a link to. Matching
	 * is suffix-based (see detect_service()), so "myname.bandcamp.com" still
	 * matches the "bandcamp.com" entry the same way EmbedPolicy::is_trusted_host()
	 * already matches subdomains.
	 *
	 * @var array<string, string>
	 */
	private const HOST_SERVICE_MAP = [
		'facebook.com'   => 'facebook',
		'fb.com'         => 'facebook',
		'instagram.com'  => 'instagram',
		'threads.net'    => 'threads',
		'x.com'          => 'x',
		'twitter.com'    => 'x',
		'tiktok.com'     => 'tiktok',
		'youtube.com'    => 'youtube',
		'youtu.be'       => 'youtube',
		'vimeo.com'      => 'vimeo',
		'soundcloud.com' => 'soundcloud',
		'bandcamp.com'   => 'bandcamp',
		'spotify.com'    => 'spotify',
		'pinterest.com'  => 'pinterest',
		'behance.net'    => 'behance',
		'dribbble.com'   => 'dribbble',
		'deviantart.com' => 'deviantart',
		'linkedin.com'   => 'linkedin',
		'tumblr.com'     => 'tumblr',
		'whatsapp.com'   => 'whatsapp',
		'wa.me'          => 'whatsapp',
		'telegram.me'    => 'telegram',
		't.me'           => 'telegram',
		'patreon.com'    => 'patreon',
		'etsy.com'       => 'etsy',
		'github.com'     => 'github',
		'flickr.com'     => 'flickr',
		'mastodon.social' => 'mastodon',
	];

	/** core/social-link's generic fallback icon for a host that isn't a recognized platform. */
	private const FALLBACK_SERVICE = 'chain';

	/**
	 * Detect the core/social-link service slug for $url's host.
	 *
	 * Never fails closed the way EmbedPolicy does — an unrecognized host
	 * (a personal website, a federated Mastodon instance other than the one
	 * literal entry above, anything) still gets a link, just with the
	 * generic "chain" icon rather than being rejected. Nothing an artist
	 * submits here is silently dropped.
	 *
	 * @param string $url Already-sanitized URL (esc_url_raw()'d by the caller).
	 * @return string A core/social-link service slug — never empty.
	 */
	public static function detect_service( string $url ): string {
		$host = strtolower( (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' ) );
		if ( '' === $host ) {
			return self::FALLBACK_SERVICE;
		}
		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		foreach ( self::HOST_SERVICE_MAP as $known_host => $service ) {
			if ( $host === $known_host || str_ends_with( $host, '.' . $known_host ) ) {
				return $service;
			}
		}

		return self::FALLBACK_SERVICE;
	}

	/**
	 * Render an icon row for an ordered list of URLs using core/social-links.
	 *
	 * Empty/blank entries are skipped (so a caller can pass a fixed-size
	 * array like [$portfolio_url, $social_1, $social_2, $social_3] without
	 * pre-filtering); returns '' when nothing usable is left, so the caller's
	 * block takes no space — same "empty means no output" convention every
	 * other render callback in Artist\Profile already follows.
	 *
	 * @param string[] $urls Ordered URLs, already sanitized (esc_url_raw()).
	 * @return string Rendered HTML, or '' if $urls has no non-empty entries.
	 */
	public static function render_icon_row( array $urls ): string {
		$links = '';
		foreach ( $urls as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			$service = self::detect_service( $url );
			$attrs   = wp_json_encode( [ 'url' => $url, 'service' => $service ] ) ?: '{}';
			$links  .= '<!-- wp:social-link ' . $attrs . ' /-->' . "\n";
		}

		if ( '' === $links ) {
			return '';
		}

		$markup = '<!-- wp:social-links --><ul class="wp-block-social-links">' . "\n" . $links . '</ul><!-- /wp:social-links -->';

		return do_blocks( $markup );
	}
}
