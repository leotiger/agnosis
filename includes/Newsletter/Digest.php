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

class Digest {

	/** Maximum items listed per section before collapsing to "and N more". */
	private const MAX_ITEMS = 8;

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
			return '<p style="margin:0 0 20px;font-size:15px;color:#666;">'
				. esc_html__( 'Nothing new to report this time — but the community is still here, and the next issue will have more.', 'agnosis' )
				. '</p>';
		}

		$html = '';

		if ( ! empty( $artworks ) ) {
			$html .= '<h2 style="margin:0 0 16px;font-size:18px;color:#111;">' . esc_html__( 'New artwork', 'agnosis' ) . '</h2>';
			$html .= self::render_post_list( $artworks, false, $lf_lang );
		}

		if ( ! empty( $events ) ) {
			$html .= '<h2 style="margin:28px 0 16px;font-size:18px;color:#111;">' . esc_html__( 'Upcoming events', 'agnosis' ) . '</h2>';
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
		$artworks     = self::recent_posts( 'agnosis_artwork', $since );
		$events       = self::recent_posts( 'agnosis_event', $since );
		$new_members  = self::newly_admitted_artists( $since );
		$open_votes   = self::open_vote_count();

		$html = '<ul style="margin:0 0 20px;padding-left:20px;font-size:15px;line-height:1.8;color:#444;">';
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

		$html .= '</ul>';

		if ( ! empty( $artworks ) ) {
			$html .= '<h2 style="margin:0 0 16px;font-size:18px;color:#111;">' . esc_html__( 'Recent work from the community', 'agnosis' ) . '</h2>';
			$html .= self::render_post_list( $artworks, false, $lf_lang );
		}

		return $html;
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

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
	 */
	private static function render_post_list( array $posts, bool $show_date = false, string $lf_lang = '' ): string {
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
					. '<a href="' . esc_url( $permalink ) . '"><img src="' . esc_url( $thumb ) . '" width="72" style="display:block;border-radius:4px;max-width:72px;height:auto;"></a>'
					. '</td>';
			}
			$html .= '<td style="vertical-align:top;">'
				. '<a href="' . esc_url( $permalink ) . '" style="font-size:16px;font-weight:600;color:#111;text-decoration:none;">' . esc_html( $title ) . '</a>';
			if ( $show_date ) {
				$event_date = get_post_meta( $display->ID, '_agnosis_event_date', true );
				if ( $event_date ) {
					$formatted = (string) mysql2date( (string) get_option( 'date_format' ), (string) $event_date );
					if ( '' !== $formatted ) {
						$html .= '<br><span style="font-size:13px;color:#888;">' . esc_html( $formatted ) . '</span>';
					}
				}
			}
			$html .= '</td></tr></table>';
		}

		if ( $overflow > 0 ) {
			$html .= '<p style="margin:0 0 16px;font-size:14px;color:#888;">'
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
}
