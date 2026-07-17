<?php
/**
 * Decides whether an artist-submitted external link may become a wp:embed
 * block — shared between PostCreator (artwork/biography/event email
 * submissions) and Artist\ApplicationBiography (the portfolio URL from an
 * admission application).
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agnosis\AI\Pipeline;
use Agnosis\Core\Logger;

/**
 * Three-tier trust model:
 *
 *   0. Community bypass (opt-in, off by default via
 *      `agnosis_embed_trust_community`) — every artist here was already
 *      vouched in by the community during admission. A site admin who
 *      considers that vetting sufficient can turn this on to skip tiers 1
 *      and 2 entirely: any link an artist submits embeds immediately, no
 *      allowlist check, no fetch, no AI call. This is a deliberate,
 *      explicit opt-in — off by default — because it removes every other
 *      safeguard in this class at once.
 *
 *   1. Trusted-host fast path — a configurable list of known platforms
 *      (YouTube, Vimeo, SoundCloud, Bandcamp, …). No network request, no AI
 *      call. Was previously a hardcoded PostCreator constant; now a Settings
 *      field (`agnosis_embed_trusted_hosts`) so a site admin can add or
 *      remove platforms without a plugin update, on top of the developer-
 *      facing `agnosis_embed_host_allowlist` filter, which still exists for
 *      the same purpose it always has.
 *
 *   2. AI review (opt-in, off by default via `agnosis_embed_ai_vetting_enabled`)
 *      — for a link to any OTHER host, if enabled, this fetches the
 *      destination page (via wp_safe_remote_get(), which validates the URL
 *      to guard against SSRF — see fetch_page()) and asks the site's
 *      configured AI provider (Pipeline::classify_link(), reusing the same
 *      description-provider credentials already configured under Settings →
 *      AI Providers) to judge it against the admin's configured disallowed
 *      categories (adult content, commercial sites, gambling, or free-text
 *      custom categories — see disallowed_categories()).
 *
 * Fails closed at every step: disabled feature, fetch failure, empty
 * category list is the one exception (nothing configured to check against,
 * so nothing to block — see is_allowed()), and an inconclusive/unparseable
 * AI response all result in the link NOT being embedded. The artist's
 * submission is still published either way — only that specific link fails
 * to become a rich embed. The community bypass (tier 0) is the sole
 * exception to "fails closed": it is an explicit admin choice to trust
 * unconditionally, not a failure mode.
 */
class EmbedPolicy {

	/**
	 * Default trusted platforms — used as the Settings field's default value
	 * (see Admin\Settings::field_definitions()) and as PostCreator's original
	 * hardcoded list before this became configurable.
	 */
	public const DEFAULT_TRUSTED_HOSTS = [
		'youtube.com',
		'youtu.be',
		'vimeo.com',
		'dailymotion.com',
		'soundcloud.com',
		'bandcamp.com',
		'archive.org',
	];

	/** Hard cap on how much of a fetched page's text is sent to the AI — cost/latency control. */
	private const SNIPPET_LIMIT = 3000;

	private Pipeline $pipeline;

	/**
	 * Human-readable reason the most recent is_allowed() call returned false —
	 * '' when it returned true, or before is_allowed() has been called at all.
	 * See last_reason()'s docblock.
	 *
	 * @var string
	 */
	private string $last_reason = '';

	/**
	 * @param Pipeline|null $pipeline Injectable for tests; production callers
	 *                                get a fully-configured Pipeline automatically.
	 */
	public function __construct( ?Pipeline $pipeline = null ) {
		$this->pipeline = $pipeline ?? new Pipeline();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Whether $url may become a wp:embed block.
	 *
	 * Resets last_reason() to '' at the start of every call, then sets it
	 * whenever this returns false — read it immediately after calling this
	 * method (before checking another URL on the same instance, e.g. in
	 * PostCreator::build_external_link_embeds()'s per-URL loop) to explain a
	 * rejection to the artist rather than only logging it.
	 */
	public function is_allowed( string $url ): bool {
		$this->last_reason = '';

		$host = strtolower( (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' ) );
		if ( '' === $host ) {
			$this->last_reason = __( "That doesn't look like a valid web address.", 'agnosis' );
			return false;
		}

		if ( self::community_trust_enabled() ) {
			Logger::info(
				sprintf( 'EmbedPolicy: community-trust bypass is enabled — embedding "%s" without allowlist or AI review.', $url ),
				'embed-policy'
			);
			return true;
		}

		if ( self::is_trusted_host( $host ) ) {
			return true;
		}

		if ( ! self::ai_vetting_enabled() ) {
			$this->last_reason = __( "This site isn't on this Agnosis node's list of trusted platforms, and automatic link review is turned off here.", 'agnosis' );
			return false;
		}

		$categories = self::disallowed_categories();
		if ( empty( $categories ) ) {
			// AI review is on, but the admin hasn't configured anything to
			// disallow — there is nothing for the AI to check this link
			// against, so there is nothing to block it for.
			return true;
		}

		$page = self::fetch_page( $url );
		if ( null === $page ) {
			Logger::info(
				sprintf( 'EmbedPolicy: could not safely fetch "%s" for AI review — not embedded.', $url ),
				'embed-policy'
			);
			$this->last_reason = __( "This node couldn't safely load that page to review it (it may be unreachable, or blocked for security reasons).", 'agnosis' );
			return false; // Fail closed — unreachable/unsafe-to-fetch page.
		}

		$verdict = $this->pipeline->classify_link( $page['title'], $page['description'], $page['snippet'], $categories );

		if ( null === $verdict ) {
			Logger::info(
				sprintf( 'EmbedPolicy: AI review of "%s" was inconclusive — not embedded.', $url ),
				'embed-policy'
			);
			$this->last_reason = __( "Automatic review of that page's content was inconclusive.", 'agnosis' );
			return false; // Fail closed — no usable verdict.
		}

		Logger::info(
			sprintf( 'EmbedPolicy: AI review of "%s" — %s.', $url, $verdict ? 'allowed' : 'blocked' ),
			'embed-policy'
		);

		if ( ! $verdict ) {
			$this->last_reason = __( "Automatic review found that page's content isn't allowed to be embedded on this site.", 'agnosis' );
		}

		return $verdict;
	}

	/**
	 * Human-readable explanation for why the most recent is_allowed() call
	 * returned false — '' if it returned true (or hasn't been called yet).
	 *
	 * Callers that surface a dropped link to the artist (Notification's review
	 * email notice, ApplicationBiography, PostCreator) call this immediately
	 * after is_allowed() so the explanation always matches the URL just
	 * checked — this is deliberately per-call state, not per-URL, so it must
	 * be read before checking a second URL on the same EmbedPolicy instance.
	 */
	public function last_reason(): string {
		return $this->last_reason;
	}

	/**
	 * Whether $host is, or is a subdomain of, a configured trusted platform
	 * (e.g. "myname.bandcamp.com" matches the "bandcamp.com" entry).
	 *
	 * Pure and static — no network access, no AI call — so PostCreator's
	 * existing is_allowed_embed_host() (exercised directly by
	 * PostCreatorExternalLinkEmbedTest via ReflectionMethod) can keep
	 * delegating to this with identical behavior.
	 */
	public static function is_trusted_host( string $host ): bool {
		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		$host = strtolower( $host );

		$configured = (string) get_option( 'agnosis_embed_trusted_hosts', implode( "\n", self::DEFAULT_TRUSTED_HOSTS ) );
		$base_hosts = array_values( array_filter( array_map( 'trim', explode( "\n", $configured ) ) ) );

		/**
		 * Filters the list of hostnames an artist-submitted link may point to
		 * in order to skip AI review and become a wp:embed block immediately.
		 * Additive to the Settings-configured list.
		 *
		 * @param string[] $hosts Base hostnames (subdomains match automatically).
		 */
		$allowed = (array) apply_filters( 'agnosis_embed_host_allowlist', $base_hosts );

		foreach ( $allowed as $allowed_host ) {
			$allowed_host = strtolower( trim( (string) $allowed_host ) );
			if ( '' === $allowed_host ) {
				continue;
			}
			if ( $host === $allowed_host || str_ends_with( $host, '.' . $allowed_host ) ) {
				return true;
			}
		}

		return false;
	}

	/** Whether AI review is enabled for links to hosts not on the trusted list. */
	public static function ai_vetting_enabled(): bool {
		return (bool) get_option( 'agnosis_embed_ai_vetting_enabled', false );
	}

	/**
	 * Whether the admin has chosen to trust every admitted artist's links
	 * unconditionally, bypassing the trusted-host allowlist and AI review
	 * entirely. Off by default.
	 */
	public static function community_trust_enabled(): bool {
		return (bool) get_option( 'agnosis_embed_trust_community', false );
	}

	// -------------------------------------------------------------------------
	// AI category configuration
	// -------------------------------------------------------------------------

	/**
	 * Human-readable disallowed-content categories, built from the admin's
	 * Settings choices. An empty return means AI review has nothing configured
	 * to check against.
	 *
	 * Public (not just this class's own is_allowed()) so Artist\ContactForm
	 * can reuse the same admin-configured adult/commercial/gambling/custom
	 * categories for contact-message moderation instead of duplicating these
	 * option reads under a second set of settings — one admin-facing
	 * "disallowed content" configuration for the whole site, not one per
	 * feature.
	 *
	 * @return string[]
	 */
	public static function disallowed_categories(): array {
		$categories = [];

		if ( get_option( 'agnosis_embed_block_adult', true ) ) {
			$categories[] = 'Pornographic or sexually explicit content';
		}
		if ( get_option( 'agnosis_embed_block_commercial', false ) ) {
			$categories[] = 'Primarily commercial or promotional content (online stores, advertising, marketing landing pages)';
		}
		if ( get_option( 'agnosis_embed_block_gambling', false ) ) {
			$categories[] = 'Gambling or betting sites';
		}

		$custom = trim( (string) get_option( 'agnosis_embed_block_custom', '' ) );
		if ( '' !== $custom ) {
			$categories[] = $custom;
		}

		return $categories;
	}

	// -------------------------------------------------------------------------
	// Safe fetch + extraction
	// -------------------------------------------------------------------------

	/**
	 * Fetch $url's destination page and extract the minimal signal needed for
	 * AI classification. Returns null on any failure — every failure mode is
	 * treated identically by the caller (fail closed).
	 *
	 * Uses wp_safe_remote_get() rather than a hand-rolled HTTP client
	 * specifically for its built-in SSRF protection (wp_http_validate_url())
	 * — the same mechanism WordPress core relies on for its own safe outbound
	 * requests (e.g. pingbacks). It rejects private/loopback/link-local/
	 * reserved IP targets. This does not defend against DNS-rebinding (a
	 * hostname resolving to a public IP at validation time and a private one
	 * at connection time) — a deeper problem WP core itself does not solve
	 * either; matching core's own accepted security posture here rather than
	 * attempting a custom, likely-incomplete mitigation.
	 *
	 * @return array{title: string, description: string, snippet: string}|null
	 */
	private static function fetch_page( string $url ): ?array {
		$scheme = (string) ( wp_parse_url( $url, PHP_URL_SCHEME ) ?: '' );
		if ( ! in_array( strtolower( $scheme ), [ 'http', 'https' ], true ) ) {
			return null;
		}

		$response = wp_safe_remote_get( $url, [
			'timeout'             => 8,
			'redirection'         => 3,
			'limit_response_size' => 2 * MB_IN_BYTES,
			'user-agent'          => 'Agnosis link reviewer/' . ( defined( 'AGNOSIS_VERSION' ) ? AGNOSIS_VERSION : '0' ),
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$html = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $html ) ) {
			return null;
		}

		return [
			'title'       => self::extract_title( $html ),
			'description' => self::extract_meta_description( $html ),
			'snippet'     => self::extract_text_snippet( $html ),
		];
	}

	private static function extract_title( string $html ): string {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			return trim( wp_strip_all_tags( $m[1] ) );
		}
		return '';
	}

	private static function extract_meta_description( string $html ): string {
		if ( preg_match( '#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']#is', $html, $m ) ) {
			return trim( html_entity_decode( $m[1], ENT_QUOTES ) );
		}
		return '';
	}

	private static function extract_text_snippet( string $html ): string {
		$stripped = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $html );
		$text     = wp_strip_all_tags( $stripped ?? $html );
		$text     = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		return function_exists( 'mb_substr' )
			? mb_substr( $text, 0, self::SNIPPET_LIMIT )
			: substr( $text, 0, self::SNIPPET_LIMIT );
	}
}
