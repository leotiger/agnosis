<?php
/**
 * Auto-digest content builder for both newsletters.
 *
 * Builds an HTML fragment summarising everything published since the last
 * issue. Rendered once per distinct recipient locale when an issue is
 * prepared (Scheduler) and stored verbatim on the issue row, so every
 * recipient sharing that locale sees identical content regardless of when
 * their batch is sent.
 *
 * Localization: `recent_posts()` scopes results to the site's primary
 * Lingua Forge language (falling back to "no _lf_lang meta at all", for
 * posts predating LF or sites without it) — otherwise a multi-language site
 * would list the same artwork once per translated duplicate. When a target
 * $lf_lang is given, `render_post_list()` then looks up each post's
 * translated counterpart via `linguaforge_get_translations()` and links to
 * that instead, falling back to the primary-language post whenever no
 * translation exists yet.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

use Agnosis\Artist\NotificationPreferences;
use Agnosis\Network\ActivityPub;
use Agnosis\Network\FederationSettlement;

class Digest {

	/** Maximum items listed per section before collapsing to "and N more". */
	private const MAX_ITEMS = 8;

	/**
	 * NL1 (RHIZOME-NETWORK-ROADMAP.md §11a, 2026-07-30) — embedded once in
	 * build_artist()'s shared, per-locale `<ul>`. A per-artist like/boost
	 * count can't be known at render time (this HTML is rendered ONCE per
	 * locale and shared by every recipient in it, same constraint
	 * render_post_list()'s own `{{AGNOSIS_LIKE:<id>}}`/`{{AGNOSIS_BOOST:<id>}}`
	 * placeholders exist for) — substitute_interaction_summary() resolves it
	 * later, per recipient, at actual send time (QueueProcessor::send_one()).
	 */
	public const INTERACTION_SUMMARY_PLACEHOLDER = '{{AGNOSIS_INTERACTION_SUMMARY}}';

	/**
	 * Build the public-newsletter digest: new artwork and events published
	 * since $since, artist-agnostic.
	 *
	 * @param string $since   MySQL datetime — content published after this is included.
	 * @param string $lf_lang Optional Lingua Forge language code (e.g. 'es') the
	 *                        recipient reads in. When given, each listed post links
	 *                        to its translated counterpart if one exists.
	 */
	public static function build_public( string $since, string $lf_lang = '' ): string {
		$artworks = self::recent_posts( 'agnosis_artwork', $since );
		$events   = self::recent_posts( 'agnosis_event', $since );

		if ( empty( $artworks ) && empty( $events ) ) {
			return '<p style="margin:0 0 20px;font-size:17px;color:#666;">'
				. esc_html__( 'Nothing new to report this time — but the community is still here, and the next issue will have more.', 'agnosis' )
				. '</p>';
		}

		$html = '';

		if ( ! empty( $artworks ) ) {
			$html .= '<h2 style="margin:0 0 16px;font-size:20px;color:#111;">' . esc_html__( 'New artwork', 'agnosis' ) . '</h2>';
			// Public recipients never get a boost link (§4 Phase 3G step 1's
			// audience rule — they have no actor to boost under), hence the
			// default $include_boost = false below.
			$html .= self::render_post_list( $artworks, false, $lf_lang );
		}

		if ( ! empty( $events ) ) {
			$html .= '<h2 style="margin:28px 0 16px;font-size:20px;color:#111;">' . esc_html__( 'Upcoming events', 'agnosis' ) . '</h2>';
			$html .= self::render_post_list( $events, true, $lf_lang );
		}

		return $html;
	}

	/**
	 * Build the artist-newsletter digest: a community-facing summary —
	 * activity counts, new members, and any open community votes.
	 *
	 * @param string $since   MySQL datetime — content/events after this is included.
	 * @param string $lf_lang Optional Lingua Forge language code (e.g. 'es') the
	 *                        recipient reads in. When given, each listed post links
	 *                        to its translated counterpart if one exists.
	 */
	public static function build_artist( string $since, string $lf_lang = '' ): string {
		$artworks         = self::recent_posts( 'agnosis_artwork', $since );
		$events           = self::recent_posts( 'agnosis_event', $since );
		$new_members      = self::newly_admitted_artists( $since );
		$open_votes       = self::open_vote_count();
		$rhizome_activity = self::rhizome_activity_summary( $since );

		$html = '<ul style="margin:0 0 20px;padding-left:20px;font-size:17px;line-height:1.8;color:#444;">';
		$html .= '<li>' . sprintf(
			/* translators: %d: number of new artworks published */
			esc_html( _n( '%d new artwork published', '%d new artworks published', count( $artworks ), 'agnosis' ) ),
			count( $artworks )
		) . '</li>';
		$html .= '<li>' . sprintf(
			/* translators: %d: number of new events announced */
			esc_html( _n( '%d new event announced', '%d new events announced', count( $events ), 'agnosis' ) ),
			count( $events )
		) . '</li>';

		if ( ! empty( $new_members ) ) {
			$html .= '<li>' . sprintf(
				/* translators: %s: comma-separated list of new members' display names */
				esc_html( _n( 'Welcome to our newest member: %s', 'Welcome to our newest members: %s', count( $new_members ), 'agnosis' ) ),
				esc_html( implode( ', ', $new_members ) )
			) . '</li>';
		}

		if ( $open_votes > 0 ) {
			$html .= '<li>' . sprintf(
				/* translators: %d: number of open community votes */
				esc_html( _n( '%d community vote is open — check your email for your personal voting link.', '%d community votes are open — check your email for your personal voting links.', $open_votes, 'agnosis' ) ),
				$open_votes
			) . '</li>';
		}

		// NL2 (RHIZOME-NETWORK-ROADMAP.md §11b, 2026-07-30) — community-wide,
		// not personalized to any one artist, same shape as the new_members/
		// open_votes bullets just above: how much of the rhizome's activity
		// this node relayed to its own followers since $since, reading rows
		// RN3b's log_relay_activity() already writes. Omitted entirely when
		// there's nothing to report, same gate as new_members/open_votes.
		if ( $rhizome_activity['relays'] > 0 ) {
			$html .= '<li>' . sprintf(
				/* translators: %d: number of pieces relayed across the rhizome */
				esc_html( _n( '%d piece relayed across the rhizome.', '%d pieces relayed across the rhizome.', $rhizome_activity['relays'], 'agnosis' ) ),
				$rhizome_activity['relays']
			) . '</li>';
			$html .= '<li>' . sprintf(
				/* translators: %d: number of trusted partner nodes that relayed activity */
				esc_html( _n( 'From %d trusted partner node.', 'From %d trusted partner nodes.', $rhizome_activity['partners'], 'agnosis' ) ),
				$rhizome_activity['partners']
			) . '</li>';
		}

		// NL1 (§11a) — see this class's own INTERACTION_SUMMARY_PLACEHOLDER
		// docblock. Resolves to zero, one, or two <li> bullets per recipient at
		// send time; a bare no-op string here for a public-newsletter or
		// zero-count recipient, so it's always safe to leave in the markup
		// unconditionally.
		$html .= self::INTERACTION_SUMMARY_PLACEHOLDER;

		$html .= '</ul>';

		if ( ! empty( $artworks ) ) {
			$html .= '<h2 style="margin:0 0 16px;font-size:20px;color:#111;">' . esc_html__( 'Recent work from the community', 'agnosis' ) . '</h2>';
			// WP5: the ARTIST digest is the only one that ever offers a boost
			// link (§4 Phase 3E/3G step 1) — its recipients are, by definition,
			// admitted Agnosis artists with real actors.
			$html .= self::render_post_list( $artworks, false, $lf_lang, true );
		}

		return $html;
	}

	/**
	 * Structured (non-HTML) summary of what's new since $since — the raw
	 * material for Pipeline::generate_newsletter_intro()'s AI-drafted intro,
	 * built from the same underlying query as build_public()/build_artist()
	 * but shaped for a text prompt rather than an HTML fragment: title,
	 * AI-generated excerpt, tags, and (for artwork) medium, so the drafted
	 * intro can speak to what the new work actually is instead of only a
	 * bare count.
	 *
	 * @param string $type  'artist' or 'public' — 'artist' also includes new
	 *                      members and open community votes, matching build_artist().
	 * @param string $since MySQL datetime — content published after this is included.
	 * @return array{artworks: array<int, array{title: string, excerpt: string, tags: string[], medium: string[]}>, events: array<int, array{title: string, excerpt: string, tags: string[]}>, new_members?: string[], open_votes?: int}
	 */
	public static function build_intro_context( string $type, string $since ): array {
		$artworks = self::recent_posts( 'agnosis_artwork', $since );
		$events   = self::recent_posts( 'agnosis_event', $since );

		$context = [
			'artworks' => array_map( fn( \WP_Post $post ) => self::summarize_post( $post, true ), array_slice( $artworks, 0, self::MAX_ITEMS ) ),
			'events'   => array_map( fn( \WP_Post $post ) => self::summarize_post( $post, false ), array_slice( $events, 0, self::MAX_ITEMS ) ),
		];

		if ( 'artist' === $type ) {
			$context['new_members'] = self::newly_admitted_artists( $since );
			$context['open_votes']  = self::open_vote_count();
		}

		return $context;
	}

	/**
	 * Resolve INTERACTION_SUMMARY_PLACEHOLDER (NL1, §11a) with this specific
	 * recipient's own personal like/boost counts since the digest window —
	 * called from QueueProcessor::send_one(), the same per-recipient send-time
	 * stage that already resolves the LIKE/BOOST link placeholders.
	 *
	 * Disclosure depth: aggregate counts only, per §11a's own ANSWERED
	 * resolution — never names the liking/boosting actor or instance, matching
	 * the existing on-site "⟲ N boosts" count's own anonymity
	 * (ActivityPub::render_interaction_counts()). Cadence: digest-only, no
	 * instant option — this method is only ever called once, at the shared
	 * digest's own send time, never on any separate schedule.
	 *
	 * $recipient_artist_id of 0 (a public-newsletter recipient) always yields
	 * an empty replacement — a public recipient has no artwork of their own to
	 * summarize, and the placeholder never legitimately appears in
	 * build_public()'s own output anyway (defensive, not an expected path,
	 * same posture InteractionGateway::substitute_boost_links() takes for its
	 * own artist-only placeholder).
	 */
	public static function substitute_interaction_summary( string $html, int $recipient_artist_id, string $since ): string {
		// An empty $since means this issue predates the digest_since column
		// (NL1 added it; a row inserted before that and still mid-send when
		// this ships has it NULL, which QueueProcessor::send_one() coalesces
		// to ''). It must NOT be passed through to the query: MySQL coerces
		// '' to a zero-date in `received_at > %s`, so every interaction the
		// artist has ever received would match and a "since your last digest"
		// line would silently report an all-time total (§13 F4, 2026-07-30).
		// Omitting the section entirely for that one transitional issue is
		// the honest answer — the same thing a zero count already does.
		if ( $recipient_artist_id <= 0 || '' === trim( $since ) || NotificationPreferences::is_interaction_summary_opted_out( $recipient_artist_id ) ) {
			return str_replace( self::INTERACTION_SUMMARY_PLACEHOLDER, '', $html );
		}

		$counts = ( new ActivityPub() )->personal_interaction_counts( $recipient_artist_id, $since );
		$likes  = $counts['like'];
		$boosts = $counts['announce'];

		if ( 0 === $likes && 0 === $boosts ) {
			return str_replace( self::INTERACTION_SUMMARY_PLACEHOLDER, '', $html );
		}

		$bullets = '';
		if ( $likes > 0 ) {
			$bullets .= '<li>' . sprintf(
				/* translators: %d: number of likes received on the recipient's own work */
				esc_html( _n( '%d like on your work since your last digest.', '%d likes on your work since your last digest.', $likes, 'agnosis' ) ),
				$likes
			) . '</li>';
		}
		if ( $boosts > 0 ) {
			$bullets .= '<li>' . sprintf(
				/* translators: %d: number of boosts received on the recipient's own work */
				esc_html( _n( '%d boost on your work since your last digest.', '%d boosts on your work since your last digest.', $boosts, 'agnosis' ) ),
				$boosts
			) . '</li>';
		}

		return str_replace( self::INTERACTION_SUMMARY_PLACEHOLDER, $bullets, $html );
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	/**
	 * @return array{title: string, excerpt: string, tags: string[], medium: string[]}
	 */
	private static function summarize_post( \WP_Post $post, bool $include_medium ): array {
		$medium = [];
		if ( $include_medium ) {
			$terms  = wp_get_post_terms( $post->ID, 'agnosis_medium', [ 'fields' => 'names' ] );
			$medium = is_wp_error( $terms ) ? [] : $terms;
		}

		$tags = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );

		return [
			'title'   => get_the_title( $post ),
			'excerpt' => (string) $post->post_excerpt,
			'tags'    => is_wp_error( $tags ) ? [] : $tags,
			'medium'  => $medium,
		];
	}

	/**
	 * @return array<int, \WP_Post>
	 */
	private static function recent_posts( string $post_type, string $since ): array {
		$query_args = [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => self::MAX_ITEMS + 1, // +1 so we can detect "and more".
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => [
				[
					'column' => 'post_date',
					'after'  => $since,
				],
			],
			'no_found_rows'  => true,
		];

		// Scope to the site's primary Lingua Forge language only. Without this,
		// a multi-language site would list the same artwork once per translated
		// duplicate post, since each translation is its own agnosis_artwork post.
		// Posts predating LF (or on sites without it) carry no _lf_lang meta at
		// all, so they're included via the NOT EXISTS branch rather than excluded.
		if ( function_exists( 'linguaforge_source_language' ) ) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, infrequent (daily cron) query over recently-published posts only.
				'relation' => 'OR',
				[ 'key' => '_lf_lang', 'value' => linguaforge_source_language() ],
				[ 'key' => '_lf_lang', 'compare' => 'NOT EXISTS' ],
			];
		}

		$query = new \WP_Query( $query_args );

		/** @var \WP_Post[] $posts */
		$posts = $query->posts;

		return $posts;
	}

	/**
	 * Resolve which post to actually display for a recipient's language: the
	 * given post's own translated counterpart when $lf_lang is set and a
	 * published translation exists, otherwise the post itself.
	 */
	private static function localized_post( \WP_Post $post, string $lf_lang ): \WP_Post {
		if ( '' === $lf_lang || ! function_exists( 'linguaforge_get_translations' ) ) {
			return $post;
		}

		$source = function_exists( 'linguaforge_source_language' ) ? linguaforge_source_language() : '';
		if ( $lf_lang === $source ) {
			return $post; // Already the primary-language post — nothing to look up.
		}

		$translations  = linguaforge_get_translations( $post->ID );
		$translated_id = (int) ( $translations[ $lf_lang ] ?? 0 );
		if ( $translated_id <= 0 ) {
			return $post; // No translation yet — fall back to the primary-language post.
		}

		$translated = get_post( $translated_id );
		return ( $translated instanceof \WP_Post && 'publish' === $translated->post_status ) ? $translated : $post;
	}

	/**
	 * @param \WP_Post[] $posts
	 * @param bool       $include_boost Emit a boost placeholder alongside the
	 *                                  like one (WP5) — true only from
	 *                                  build_artist(); build_public() never
	 *                                  passes true, since a public-newsletter
	 *                                  recipient has no actor to boost under
	 *                                  (§4 Phase 3G step 1's audience rule).
	 */
	private static function render_post_list( array $posts, bool $show_date = false, string $lf_lang = '', bool $include_boost = false ): string {
		$shown    = array_slice( $posts, 0, self::MAX_ITEMS );
		$overflow = count( $posts ) - count( $shown );

		$html = '';
		foreach ( $shown as $post ) {
			$display   = self::localized_post( $post, $lf_lang );
			$title     = get_the_title( $display );
			$permalink = get_permalink( $display );
			$thumb     = get_the_post_thumbnail_url( $display, 'agnosis-email' );

			$html .= '<table cellpadding="0" cellspacing="0" style="margin:0 0 16px;width:100%;">';
			$html .= '<tr>';
			if ( $thumb ) {
				$html .= '<td style="width:80px;padding-right:16px;vertical-align:top;">'
					. '<a href="' . esc_url( $permalink ) . '"><img src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $title ) . '" width="72" style="display:block;border-radius:4px;max-width:72px;height:auto;"></a>'
					. '</td>';
			}
			$html .= '<td style="vertical-align:top;">'
				. '<a href="' . esc_url( $permalink ) . '" style="font-size:18px;font-weight:600;color:#111;text-decoration:none;">' . esc_html( $title ) . '</a>';
			if ( $show_date ) {
				$event_date = get_post_meta( $display->ID, '_agnosis_event_date', true );
				if ( $event_date ) {
					$formatted = (string) mysql2date( (string) get_option( 'date_format' ), (string) $event_date );
					if ( '' !== $formatted ) {
						$html .= '<br><span style="font-size:15px;color:#888;">' . esc_html( $formatted ) . '</span>';
					}
				}
			}

			// Interaction-surface roadmap, Phase 3, WP3/WP5 (2026-07-27) — an
			// inert placeholder, never a real token: this HTML is rendered
			// ONCE per locale and stored verbatim on the shared issue row
			// (Scheduler), long before any specific recipient is known, so a
			// per-recipient token cannot be baked in here.
			// Newsletter\InteractionGateway::substitute_links()/
			// substitute_boost_links()/inert() resolve them later, per
			// recipient, at actual send time (QueueProcessor) or strip them
			// for the public "view in browser" page (Archive). Artwork-only
			// ($show_date is true only for the events list — events have no
			// like/boost concept at all, same as
			// ActivityPub::render_interaction_counts()'s own artwork-only
			// guard) and only for artwork that's actually federated —
			// FederationSettlement::is_federated() checks the DISPLAYED
			// post ($display — possibly a translated sibling), not
			// assumed from the primary ($post) alone, per §4 Phase 3G step 2.
			if ( ! $show_date && FederationSettlement::is_federated( $display->ID, $post->ID ) ) {
				$html .= '<br><span style="font-size:15px;">{{AGNOSIS_LIKE:' . (int) $display->ID . '}}';
				if ( $include_boost ) {
					$html .= ' · {{AGNOSIS_BOOST:' . (int) $display->ID . '}}';
				}
				$html .= '</span>';
			}

			$html .= '</td></tr></table>';
		}

		if ( $overflow > 0 ) {
			$html .= '<p style="margin:0 0 16px;font-size:16px;color:#888;">'
				. sprintf(
					/* translators: %d: number of additional items not individually listed */
					esc_html( _n( '…and %d more.', '…and %d more.', $overflow, 'agnosis' ) ),
					$overflow
				)
				. '</p>';
		}

		return $html;
	}

	/**
	 * @return string[] Display names of artists admitted since $since.
	 */
	private static function newly_admitted_artists( string $since ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT display_name FROM {$wpdb->prefix}agnosis_applications
				 WHERE status = 'admitted' AND resolved_at > %s
				 ORDER BY resolved_at ASC",
				$since
			)
		);

		return array_map( 'sanitize_text_field', (array) $rows );
	}

	private static function open_vote_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$removals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_removal_requests WHERE status = 'open'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$caps     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_cap_proposals WHERE status = 'open'" );

		return $removals + $caps;
	}

	/**
	 * NL2 (§11b) — how much of the rhizome's activity this node relayed to
	 * its own followers since $since, reading RN3b's own log
	 * (`agnosis_rhizome_relay_log`, written by `ActivityPub::log_relay_activity()`
	 * from `relay_trusted_announce()`). `partners` counts distinct
	 * `peer_node_id`s, not rows — three relays from the same partner count as
	 * one partner, not three.
	 *
	 * @return array{relays: int, partners: int}
	 */
	private static function rhizome_activity_summary( string $since ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$relays = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_rhizome_relay_log WHERE relayed_at > %s",
			$since
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$partners = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT peer_node_id) FROM {$wpdb->prefix}agnosis_rhizome_relay_log WHERE relayed_at > %s",
			$since
		) );

		return [ 'relays' => $relays, 'partners' => $partners ];
	}
}
