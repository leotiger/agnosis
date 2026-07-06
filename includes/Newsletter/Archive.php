<?php
/**
 * Public newsletter archive — "view in browser" for past issues.
 *
 * Two front-end routes, neither backed by a real WP post (issues live in the
 * agnosis_newsletter_issues custom table, not wp_posts):
 *
 *   GET /newsletter/            — paginated list of past public issues
 *   GET /newsletter/{id}/       — one issue, rendered via Mailer::build_body()
 *
 * Deliberately public-newsletter only. The artist newsletter's content (open
 * community votes, new-member names — see Digest::build_artist()) is
 * community-internal, not meant for anonymous visitors, so it is never
 * reachable through this class regardless of an issue's ID.
 *
 * Follows the same rewrite-rule + query_vars + template_redirect pattern
 * already used for the plugin's other non-post front-end routes
 * (Network\Node's `/.well-known/agnosis-node`, Network\ActivityPub's
 * `/.well-known/webfinger`) rather than inventing a new mechanism.
 *
 * Both render_* methods finish by calling wp_die() rather than a raw
 * echo+exit — the same convention used by every other "render a page and
 * stop" flow in this plugin (SubscriptionConfirm, VouchConfirm,
 * ReviewConfirm), specifically so this stays testable via the
 * 'wp_die_handler' filter (see ArchiveTest) instead of killing the PHP
 * process a test is running in.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

class Archive {

	/** Issues listed per archive-index page. */
	private const PER_PAGE = 20;

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/** Hooked to 'init' — registers the rewrite rules, query vars, and the
	 * template_redirect dispatcher, all bundled the same way
	 * Node::register_identity() bundles its own `.well-known` route.
	 */
	public function register_routes(): void {
		add_rewrite_rule( '^newsletter/page/([0-9]+)/?$', 'index.php?agnosis_newsletter_archive=1&paged=$matches[1]', 'top' );
		add_rewrite_rule( '^newsletter/([0-9]+)/?$',       'index.php?agnosis_newsletter_issue=$matches[1]',           'top' );
		add_rewrite_rule( '^newsletter/?$',                'index.php?agnosis_newsletter_archive=1',                  'top' );

		add_filter( 'query_vars', function ( array $vars ): array {
			$vars[] = 'agnosis_newsletter_archive';
			$vars[] = 'agnosis_newsletter_issue';
			return $vars;
		} );

		add_action( 'template_redirect', [ $this, 'dispatch' ] );
	}

	/** template_redirect callback — renders and exits, or does nothing (letting WP continue normally). */
	public function dispatch(): void {
		$issue_id = (int) get_query_var( 'agnosis_newsletter_issue' );
		if ( $issue_id > 0 ) {
			$this->render_issue( $issue_id );
			return;
		}

		if ( get_query_var( 'agnosis_newsletter_archive' ) ) {
			$paged = max( 1, (int) get_query_var( 'paged' ) );
			$this->render_index( $paged );
		}
	}

	// -------------------------------------------------------------------------
	// Public helpers — permalinks (used by QueueProcessor, SignupBlock, tests)
	// -------------------------------------------------------------------------

	/** Permalink for the archive index. */
	public static function archive_url(): string {
		return user_trailingslashit( home_url( '/newsletter/' ) );
	}

	/** Permalink for a single issue's "view in browser" page. */
	public static function issue_permalink( int $issue_id ): string {
		return user_trailingslashit( home_url( '/newsletter/' . $issue_id . '/' ) );
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	private function render_issue( int $issue_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$issue = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_newsletter_issues
				 WHERE id = %d AND newsletter_type = 'public' AND status = 'sent'",
				$issue_id
			)
		);

		// The newsletter_type = 'public' guard above is the whole reason this
		// query can't be widened to "any issue id" — an artist-type issue
		// (open community votes, new-member names; see Digest::build_artist())
		// must 404 exactly like a nonexistent one, never render.
		if ( ! $issue ) {
			wp_die(
				esc_html__( 'This newsletter issue could not be found.', 'agnosis' ),
				esc_html__( 'Issue not found', 'agnosis' ),
				[ 'response' => 404 ]
			);
		}

		// Best-effort localization: if Lingua Forge (or anything else) has
		// already resolved the visitor's language before this request reached
		// template_redirect, and this issue happens to have a rendered copy
		// for that exact locale, show it. Otherwise fall back to the issue's
		// own base (default-locale) render — the same fallback QueueProcessor
		// uses when a recipient's locale isn't in the map.
		$locale_map = ! empty( $issue->locale_content ) ? (array) json_decode( (string) $issue->locale_content, true ) : [];
		$locale     = get_locale();
		$content    = isset( $locale_map[ $locale ] )
			? $locale_map[ $locale ]
			: [ 'intro' => (string) $issue->intro, 'digest_html' => (string) $issue->digest_html ];

		// No unsubscribe URL and no "view online" banner — this already *is*
		// the online view. See Mailer::build_body() doc for why a fragment
		// (not build_email()'s full document) is used here: wp_die() below
		// supplies its own doctype/head/body, following the same convention
		// as SubscriptionConfirm/VouchConfirm/ReviewConfirm so this stays
		// testable via the 'wp_die_handler' filter.
		$body = Mailer::build_body( 'public', $content['intro'], $content['digest_html'], null );

		nocache_headers();
		wp_die( $body, esc_html( $this->issue_title( $issue ) ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body is built and escaped by Mailer::build_body()/Digest::build_*().
	}

	private function render_index( int $paged ): void {
		global $wpdb;

		$offset = ( $paged - 1 ) * self::PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_issues WHERE newsletter_type = 'public' AND status = 'sent'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$issues = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, intro, sent_at FROM {$wpdb->prefix}agnosis_newsletter_issues
				 WHERE newsletter_type = 'public' AND status = 'sent'
				 ORDER BY sent_at DESC LIMIT %d OFFSET %d",
				self::PER_PAGE,
				$offset
			)
		);

		$site_name = get_bloginfo( 'name' );

		ob_start();
		?>
<div style="max-width:600px;margin:60px auto;font-family:Georgia,serif;color:#222;padding:0 20px;">
	<h1 style="margin:0 0 8px;font-size:28px;color:#111;"><?php esc_html_e( 'Newsletter archive', 'agnosis' ); ?></h1>
	<p style="margin:0 0 32px;font-size:15px;color:#666;">
		<?php
		printf(
			/* translators: %s: site name */
			esc_html__( 'Past issues of the %s newsletter.', 'agnosis' ),
			esc_html( $site_name )
		);
		?>
	</p>

		<?php if ( empty( $issues ) ) : ?>
		<p style="font-size:15px;color:#666;"><?php esc_html_e( 'No issues have gone out yet — check back soon.', 'agnosis' ); ?></p>
	<?php else : ?>
		<ul style="list-style:none;margin:0;padding:0;">
			<?php foreach ( $issues as $issue ) : ?>
				<li style="margin:0 0 24px;padding-bottom:24px;border-bottom:1px solid #eee;">
					<a href="<?php echo esc_url( self::issue_permalink( (int) $issue->id ) ); ?>" style="font-size:18px;font-weight:600;color:#111;text-decoration:none;">
						<?php echo esc_html( $this->issue_title( $issue ) ); ?>
					</a>
					<?php $snippet = $this->issue_description( (string) $issue->intro, '' ); ?>
					<?php if ( '' !== $snippet ) : ?>
						<p style="margin:6px 0 0;font-size:14px;color:#888;"><?php echo esc_html( $snippet ); ?></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $total > self::PER_PAGE ) : ?>
			<p style="font-size:14px;color:#888;">
				<?php if ( $paged > 1 ) : ?>
					<a href="<?php echo esc_url( $this->index_page_url( $paged - 1 ) ); ?>" style="color:#7c6af7;"><?php esc_html_e( '← Newer', 'agnosis' ); ?></a>
				<?php endif; ?>
				<?php if ( $offset + self::PER_PAGE < $total ) : ?>
					<a href="<?php echo esc_url( $this->index_page_url( $paged + 1 ) ); ?>" style="color:#7c6af7;float:right;"><?php esc_html_e( 'Older →', 'agnosis' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
		<?php
		$body = (string) ob_get_clean();

		$title = sprintf(
			/* translators: %s: site name */
			__( 'Newsletter archive — %s', 'agnosis' ),
			$site_name
		);

		// See render_issue()'s doc for why this goes through wp_die() rather
		// than a raw echo+exit — same codebase-wide convention, same reason
		// (testability via the 'wp_die_handler' filter).
		nocache_headers();
		wp_die( $body, esc_html( $title ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body is escaped above.
	}

	/** Build the URL for archive page $page (2+, page 1 is the bare archive URL). */
	private function index_page_url( int $page ): string {
		return $page > 1
			? user_trailingslashit( home_url( '/newsletter/page/' . $page . '/' ) )
			: self::archive_url();
	}

	/**
	 * @param object{id: int|string, sent_at: string|null} $issue
	 */
	private function issue_title( object $issue ): string {
		$site_name = get_bloginfo( 'name' );
		$date      = $issue->sent_at ? mysql2date( (string) get_option( 'date_format' ), (string) $issue->sent_at ) : '';

		return '' !== $date
			? sprintf(
				/* translators: 1: site name, 2: date the issue was sent */
				__( '%1$s Newsletter — %2$s', 'agnosis' ),
				$site_name,
				$date
			)
			: sprintf(
				/* translators: %s: site name */
				__( '%s Newsletter', 'agnosis' ),
				$site_name
			);
	}

	/** A short plain-text snippet used as an archive-row preview under each issue's title. */
	private function issue_description( string $intro, string $digest_html ): string {
		$source = '' !== trim( $intro ) ? $intro : wp_strip_all_tags( $digest_html );
		$source = trim( preg_replace( '/\s+/', ' ', $source ) ?? '' );

		return '' === $source ? '' : wp_trim_words( $source, 30 );
	}
}
