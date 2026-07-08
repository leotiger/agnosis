<?php
/**
 * Integration tests — InboxPage::render_status_badge() (2026-07-08).
 *
 * InboxPage had no test coverage at all before this pass. Scope here is
 * deliberately limited to render_status_badge() — the method that just
 * gained a reason-aware branch for the 'skipped' status — via reflection
 * (it's private), capturing its direct HTML output. A broader InboxPage
 * suite (the full admin table render, row actions, attachment previews, etc.)
 * is a pre-existing gap, not one introduced or closed here.
 *
 * Before this fix, EVERY 'skipped' queue row — regardless of why — rendered
 * an identical gray "Skipped" badge. That read as "nothing happened" even for
 * goodbye_handled, which (once the artist clicks the confirmation link they
 * were emailed) permanently deletes their whole account and content —
 * reported directly as confusing. $skip_reason (sourced from the row's
 * raw_email JSON — see InboxMarkNoArtworkTest) now selects a distinct label
 * per reason.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\InboxPage;

class InboxPageStatusBadgeTest extends \WP_UnitTestCase {

	private InboxPage $page;

	protected function setUp(): void {
		parent::setUp();
		$this->page = new InboxPage();
	}

	private function render( string $status, ?string $wp_post_status = null, string $skip_reason = '' ): string {
		$ref = new \ReflectionMethod( InboxPage::class, 'render_status_badge' );
		$ref->setAccessible( true );

		ob_start();
		$ref->invoke( $this->page, $status, $wp_post_status, $skip_reason );
		return (string) ob_get_clean();
	}

	public function test_goodbye_handled_shows_member_removed_not_generic_skipped(): void {
		$html = $this->render( 'skipped', null, 'goodbye_handled' );

		$this->assertStringContainsString( 'Member Removed', $html );
		$this->assertStringNotContainsString( '>Skipped<', $html );
	}

	public function test_community_handled_shows_broadcast_sent(): void {
		$html = $this->render( 'skipped', null, 'community_handled' );

		$this->assertStringContainsString( 'Broadcast Sent', $html );
	}

	public function test_community_too_long_shows_bounced(): void {
		$html = $this->render( 'skipped', null, 'community_too_long' );

		$this->assertStringContainsString( 'Bounced', $html );
	}

	/**
	 * A row created before this fix shipped (or any future skip reason not yet
	 * mapped) has no skip_reason at all — must fall back to a generic label
	 * rather than erroring or showing a blank badge.
	 */
	public function test_skipped_without_reason_falls_back_to_generic_handled_label(): void {
		$html = $this->render( 'skipped', null, '' );

		$this->assertStringContainsString( 'Handled', $html );
	}

	public function test_failed_status_is_unaffected(): void {
		$html = $this->render( 'failed', null, '' );

		$this->assertStringContainsString( 'Failed', $html );
	}

	public function test_pending_status_is_unaffected(): void {
		$html = $this->render( 'pending', null, '' );

		$this->assertStringContainsString( 'Pending', $html );
	}

	public function test_published_status_still_resolves_post_status_not_skip_branch(): void {
		// Sanity check: 'published' must still take the wp_post_status switch,
		// not be shadowed by the new 'skipped' branch.
		$html = $this->render( 'published', 'publish', 'goodbye_handled' );

		$this->assertStringContainsString( 'Live', $html );
		$this->assertStringNotContainsString( 'Member Removed', $html );
	}
}
