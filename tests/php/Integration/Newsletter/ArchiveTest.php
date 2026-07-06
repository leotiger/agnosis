<?php
/**
 * Integration tests — Archive (public newsletter archive / "view in browser").
 *
 * Covers permalink helpers, rewrite-route registration, and dispatch()'s
 * rendering of both routes. render_issue()/render_index() finish via
 * wp_die() (not a raw echo+exit — see Archive's class doc for why), so it is
 * intercepted here the same way SubscriptionConfirmTest/VouchConfirmTest do:
 * via the 'wp_die_handler' filter, thrown as DieCapture.
 *
 * The most important behavior covered here is the security boundary: an
 * artist-type issue (open community votes, new-member names — see
 * Digest::build_artist()) must 404 exactly like a nonexistent issue, never
 * render, regardless of its status.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\Archive;
use Agnosis\Tests\Integration\Support\DieCapture;

class ArchiveTest extends \WP_UnitTestCase {

	private Archive $archive;

	protected function setUp(): void {
		parent::setUp();
		$this->archive = new Archive();

		// Intercept wp_die() — throw instead of outputting HTML/exiting, same
		// pattern as SubscriptionConfirmTest.
		$die_interceptor = static function (): callable {
			return static function ( string|\WP_Error $message, string $title = '', array $args = [] ): never {
				$http_status = (int) ( $args['response'] ?? 200 );
				$msg_str     = is_string( $message ) ? $message : (string) $message->get_error_message();
				throw new DieCapture( $msg_str, $title, $http_status );
			};
		};
		add_filter( 'wp_die_handler',      $die_interceptor );
		add_filter( 'wp_die_ajax_handler', $die_interceptor );
	}

	protected function tearDown(): void {
		set_query_var( 'agnosis_newsletter_issue', '' );
		set_query_var( 'agnosis_newsletter_archive', '' );
		set_query_var( 'paged', '' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Insert an issue row directly (mirrors the DB-manipulation approach
	 * QueueProcessorTest already uses for edge cases) rather than going
	 * through Scheduler, so artist/public + every status combination is
	 * cheap to set up without real subscribers or digest content.
	 */
	private function insert_issue( string $type, string $status, string $intro = 'Hello there', string $digest_html = '<p>DIGEST_MARKER</p>', ?string $sent_at = null, array $locale_content = [] ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'agnosis_newsletter_issues',
			[
				'newsletter_type' => $type,
				'status'          => $status,
				'intro'           => $intro,
				'digest_html'     => $digest_html,
				'locale_content'  => ! empty( $locale_content ) ? wp_json_encode( $locale_content ) : null,
				'recipient_count' => 1,
				'sent_at'         => $sent_at,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	// =========================================================================
	// Permalink helpers
	// =========================================================================

	public function test_archive_url_points_at_newsletter_root(): void {
		// Trailing-slash presence is environment-dependent — Archive::archive_url()
		// deliberately defers to the site's own permalink structure via
		// user_trailingslashit() rather than hardcoding one, so compare with it
		// stripped rather than assuming a trailing slash is always there.
		$this->assertStringEndsWith( '/newsletter', untrailingslashit( Archive::archive_url() ) );
	}

	public function test_issue_permalink_includes_the_issue_id(): void {
		$url = Archive::issue_permalink( 42 );

		$this->assertStringEndsWith( '/newsletter/42', untrailingslashit( $url ) );
	}

	public function test_issue_permalink_differs_per_issue(): void {
		$this->assertNotSame( Archive::issue_permalink( 1 ), Archive::issue_permalink( 2 ) );
	}

	// =========================================================================
	// register_routes()
	// =========================================================================

	public function test_register_routes_adds_template_redirect_dispatch(): void {
		remove_all_actions( 'template_redirect' );

		$this->archive->register_routes();

		$this->assertGreaterThan( 0, has_action( 'template_redirect', [ $this->archive, 'dispatch' ] ) );
	}

	public function test_register_routes_registers_both_query_vars(): void {
		$this->archive->register_routes();

		$vars = apply_filters( 'query_vars', [] );

		$this->assertContains( 'agnosis_newsletter_archive', $vars );
		$this->assertContains( 'agnosis_newsletter_issue', $vars );
	}

	public function test_register_routes_adds_expected_rewrite_patterns(): void {
		global $wp_rewrite;

		$this->archive->register_routes();

		$patterns = array_keys( $wp_rewrite->extra_rules_top );

		$this->assertContains( '^newsletter/?$', $patterns );
		$this->assertContains( '^newsletter/([0-9]+)/?$', $patterns );
		$this->assertContains( '^newsletter/page/([0-9]+)/?$', $patterns );
	}

	// =========================================================================
	// dispatch() — single issue: 404 guard (security boundary)
	// =========================================================================

	public function test_dispatch_404s_for_nonexistent_issue(): void {
		set_query_var( 'agnosis_newsletter_issue', 999999 );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected a 404 (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 404, $e->http_status );
		}
	}

	/**
	 * The critical security boundary: an artist-type issue must never be
	 * reachable through this public route, even when fully 'sent' — its
	 * content (open votes, new-member names) is community-internal.
	 */
	public function test_dispatch_404s_for_artist_type_issue_even_when_sent(): void {
		$issue_id = $this->insert_issue( 'artist', 'sent', 'Artist intro', '<p>OPEN_VOTES_MARKER</p>', current_time( 'mysql' ) );
		set_query_var( 'agnosis_newsletter_issue', $issue_id );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected a 404 (wp_die) — artist issues must never be publicly reachable.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 404, $e->http_status );
		}
	}

	public function test_dispatch_404s_for_public_issue_not_yet_sent(): void {
		$issue_id = $this->insert_issue( 'public', 'sending' );
		set_query_var( 'agnosis_newsletter_issue', $issue_id );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected a 404 (wp_die) — a not-yet-sent issue must not be viewable.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 404, $e->http_status );
		}
	}

	// =========================================================================
	// dispatch() — single issue: success rendering
	// =========================================================================

	public function test_dispatch_renders_sent_public_issue(): void {
		$issue_id = $this->insert_issue( 'public', 'sent', 'Hello subscribers', '<p>DIGEST_MARKER_OK</p>', current_time( 'mysql' ) );
		set_query_var( 'agnosis_newsletter_issue', $issue_id );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected the rendered issue page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'DIGEST_MARKER_OK', $e->body );
			$this->assertStringContainsString( 'Hello subscribers', $e->body );
		}
	}

	public function test_rendered_issue_has_no_unsubscribe_or_view_online_link(): void {
		// This page *is* the online view — an unsubscribe link makes no sense
		// with no recipient, and a "view online" banner pointing at itself
		// would be redundant/confusing.
		$issue_id = $this->insert_issue( 'public', 'sent', '', '<p>x</p>', current_time( 'mysql' ) );
		set_query_var( 'agnosis_newsletter_issue', $issue_id );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected the rendered issue page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringNotContainsString( 'Unsubscribe<', $e->body );
			$this->assertStringNotContainsString( 'View it online', $e->body );
		}
	}

	public function test_rendered_issue_title_includes_site_name(): void {
		$issue_id = $this->insert_issue( 'public', 'sent', '', '<p>x</p>', current_time( 'mysql' ) );
		set_query_var( 'agnosis_newsletter_issue', $issue_id );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected the rendered issue page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( get_bloginfo( 'name' ), $e->title );
		}
	}

	// =========================================================================
	// dispatch() — single issue: locale selection
	// =========================================================================

	public function test_dispatch_uses_locale_specific_content_when_available(): void {
		$issue_id = $this->insert_issue(
			'public',
			'sent',
			'Default intro',
			'<p>DEFAULT_DIGEST</p>',
			current_time( 'mysql' ),
			[
				get_locale() => [ 'intro' => 'LOCALE_MATCH_INTRO', 'digest_html' => '<p>LOCALE_MATCH_DIGEST</p>' ],
			]
		);
		set_query_var( 'agnosis_newsletter_issue', $issue_id );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected the rendered issue page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'LOCALE_MATCH_DIGEST', $e->body );
			$this->assertStringNotContainsString( 'DEFAULT_DIGEST', $e->body );
		}
	}

	public function test_dispatch_falls_back_to_base_content_when_locale_missing_from_map(): void {
		$issue_id = $this->insert_issue(
			'public',
			'sent',
			'Default intro',
			'<p>DEFAULT_DIGEST_FALLBACK</p>',
			current_time( 'mysql' ),
			[ 'xx_XX' => [ 'intro' => 'Should not appear', 'digest_html' => '<p>WRONG_DIGEST</p>' ] ]
		);
		set_query_var( 'agnosis_newsletter_issue', $issue_id );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected the rendered issue page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'DEFAULT_DIGEST_FALLBACK', $e->body );
			$this->assertStringNotContainsString( 'WRONG_DIGEST', $e->body );
		}
	}

	// =========================================================================
	// dispatch() — archive index
	// =========================================================================

	public function test_dispatch_renders_empty_state_with_no_issues(): void {
		set_query_var( 'agnosis_newsletter_archive', '1' );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected the rendered archive index (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'No issues have gone out yet', $e->body );
		}
	}

	public function test_dispatch_index_lists_only_public_sent_issues(): void {
		$this->insert_issue( 'public', 'sent', 'PUBLIC_SENT_INTRO', '<p>x</p>', current_time( 'mysql' ) );
		$this->insert_issue( 'artist', 'sent', 'ARTIST_SENT_INTRO', '<p>x</p>', current_time( 'mysql' ) );
		$this->insert_issue( 'public', 'sending', 'PUBLIC_SENDING_INTRO', '<p>x</p>', null );

		set_query_var( 'agnosis_newsletter_archive', '1' );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected the rendered archive index (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'PUBLIC_SENT_INTRO', $e->body );
			$this->assertStringNotContainsString( 'ARTIST_SENT_INTRO', $e->body );
			$this->assertStringNotContainsString( 'PUBLIC_SENDING_INTRO', $e->body );
		}
	}

	public function test_dispatch_index_lists_newest_issue_first(): void {
		global $wpdb;

		$older_id = $this->insert_issue( 'public', 'sent', 'OLDER_INTRO', '<p>x</p>', '2026-01-01 00:00:00' );
		$newer_id = $this->insert_issue( 'public', 'sent', 'NEWER_INTRO', '<p>x</p>', '2026-06-01 00:00:00' );

		set_query_var( 'agnosis_newsletter_archive', '1' );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected the rendered archive index (wp_die).' );
		} catch ( DieCapture $e ) {
			$newer_pos = strpos( $e->body, 'NEWER_INTRO' );
			$older_pos = strpos( $e->body, 'OLDER_INTRO' );
			$this->assertNotFalse( $newer_pos );
			$this->assertNotFalse( $older_pos );
			$this->assertLessThan( $older_pos, $newer_pos, 'The newest issue must be listed before the older one.' );
		}
	}

	public function test_dispatch_index_links_to_each_issue_permalink(): void {
		$issue_id = $this->insert_issue( 'public', 'sent', 'LINK_TEST_INTRO', '<p>x</p>', current_time( 'mysql' ) );

		set_query_var( 'agnosis_newsletter_archive', '1' );

		try {
			$this->archive->dispatch();
			$this->fail( 'Expected the rendered archive index (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( Archive::issue_permalink( $issue_id ), $e->body );
		}
	}
}
