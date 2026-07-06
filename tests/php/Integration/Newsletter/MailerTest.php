<?php
/**
 * Integration tests — Mailer (email rendering + sender header).
 *
 * Covers build_email() composition, build_subject() per type, and
 * sender_header()'s dedicated-address fallback chain — the newest piece,
 * added so digest mail can use its own From: address separate from admin_email.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\Mailer;

class MailerTest extends \WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( 'agnosis_newsletter_from_name' );
		delete_option( 'agnosis_newsletter_from_email' );
		parent::tearDown();
	}

	// =========================================================================
	// build_email()
	// =========================================================================

	public function test_build_email_includes_digest_html_verbatim(): void {
		$html = Mailer::build_email( 'public', '', '<p>UNIQUE_DIGEST_MARKER</p>', 'https://example.com/unsub' );

		$this->assertStringContainsString( 'UNIQUE_DIGEST_MARKER', $html );
	}

	public function test_build_email_includes_intro_when_present(): void {
		$html = Mailer::build_email( 'public', 'Hello subscribers, big news this month!', '<p>digest</p>', 'https://example.com/unsub' );

		$this->assertStringContainsString( 'Hello subscribers, big news this month!', $html );
	}

	public function test_build_email_omits_intro_block_when_blank(): void {
		$with_intro    = Mailer::build_email( 'public', 'Some intro', '<p>digest</p>', 'https://example.com/unsub' );
		$without_intro = Mailer::build_email( 'public', '', '<p>digest</p>', 'https://example.com/unsub' );

		$this->assertNotSame( $with_intro, $without_intro );
	}

	public function test_build_email_includes_unsubscribe_link(): void {
		$html = Mailer::build_email( 'artist', '', '<p>digest</p>', 'https://example.com/unsub?token=abc123' );

		$this->assertStringContainsString( 'https://example.com/unsub?token=abc123', $html );
	}

	public function test_build_email_artist_heading_differs_from_public(): void {
		$artist = Mailer::build_email( 'artist', '', '<p>x</p>', 'https://example.com/u' );
		$public = Mailer::build_email( 'public', '', '<p>x</p>', 'https://example.com/u' );

		$this->assertStringContainsString( 'Community Newsletter', $artist );
		$this->assertStringNotContainsString( 'Community Newsletter', $public );
	}

	// =========================================================================
	// build_email() — "view in browser" (Newsletter\Archive, added 2026-07-06)
	// =========================================================================

	public function test_build_email_omits_view_online_banner_by_default(): void {
		$html = Mailer::build_email( 'public', '', '<p>x</p>', 'https://example.com/unsub' );

		$this->assertStringNotContainsString( 'View it online', $html );
	}

	public function test_build_email_shows_view_online_link_when_provided(): void {
		$html = Mailer::build_email( 'public', '', '<p>x</p>', 'https://example.com/unsub', 'https://example.com/newsletter/7/' );

		$this->assertStringContainsString( 'View it online', $html );
		$this->assertStringContainsString( 'https://example.com/newsletter/7/', $html );
	}

	public function test_build_email_shows_subscribe_link_instead_of_unsubscribe_when_no_recipient(): void {
		// Newsletter\Archive renders this same body for an anonymous visitor —
		// there is no recipient/token to unsubscribe, so the footer must not
		// show a broken/empty Unsubscribe link.
		$html = Mailer::build_email( 'public', '', '<p>x</p>', null );

		$this->assertStringNotContainsString( 'Unsubscribe<', $html );
		$this->assertStringContainsString( 'Subscribe to get these by email', $html );
	}

	public function test_build_email_shows_real_unsubscribe_link_when_recipient_present(): void {
		$html = Mailer::build_email( 'public', '', '<p>x</p>', 'https://example.com/unsub?token=abc' );

		$this->assertStringContainsString( 'Unsubscribe', $html );
		$this->assertStringNotContainsString( 'Subscribe to get these by email', $html );
	}

	// =========================================================================
	// build_body() — fragment reused by Newsletter\Archive (no doctype/head/body)
	// =========================================================================

	public function test_build_body_omits_doctype_and_html_wrapper(): void {
		$fragment = Mailer::build_body( 'public', '', '<p>UNIQUE_FRAGMENT_MARKER</p>', null );

		$this->assertStringNotContainsString( '<!DOCTYPE', $fragment );
		$this->assertStringNotContainsString( '<html', $fragment );
		$this->assertStringNotContainsString( '<body', $fragment );
		$this->assertStringContainsString( 'UNIQUE_FRAGMENT_MARKER', $fragment );
	}

	public function test_build_email_wraps_build_body_output(): void {
		// build_email() must still produce a full document containing the
		// same branded card build_body() returns on its own.
		$full = Mailer::build_email( 'public', '', '<p>UNIQUE_MARKER_2</p>', 'https://example.com/unsub' );

		$this->assertStringContainsString( '<!DOCTYPE html>', $full );
		$this->assertStringContainsString( 'UNIQUE_MARKER_2', $full );
	}

	// =========================================================================
	// build_subject()
	// =========================================================================

	public function test_build_subject_differs_by_type(): void {
		$this->assertNotSame( Mailer::build_subject( 'artist' ), Mailer::build_subject( 'public' ) );
	}

	public function test_build_subject_includes_site_name(): void {
		$site_name = get_bloginfo( 'name' );
		if ( $site_name ) {
			$this->assertStringContainsString( $site_name, Mailer::build_subject( 'public' ) );
		} else {
			$this->assertNotEmpty( Mailer::build_subject( 'public' ) );
		}
	}

	// =========================================================================
	// sender_header()
	// =========================================================================

	public function test_sender_header_falls_back_to_site_name_and_admin_email_by_default(): void {
		delete_option( 'agnosis_newsletter_from_name' );
		delete_option( 'agnosis_newsletter_from_email' );

		$header = Mailer::sender_header();

		$this->assertStringContainsString( get_bloginfo( 'name' ), $header );
		$this->assertStringContainsString( get_option( 'admin_email' ), $header );
	}

	public function test_sender_header_uses_dedicated_name_and_email_when_set(): void {
		update_option( 'agnosis_newsletter_from_name', 'Agnosis Digest' );
		update_option( 'agnosis_newsletter_from_email', 'newsletter@agnosis.art' );

		$header = Mailer::sender_header();

		$this->assertSame( 'Agnosis Digest <newsletter@agnosis.art>', $header );
	}

	public function test_sender_header_falls_back_to_admin_email_when_dedicated_email_is_invalid(): void {
		update_option( 'agnosis_newsletter_from_name', 'Agnosis Digest' );
		update_option( 'agnosis_newsletter_from_email', 'not-an-email' );

		$header = Mailer::sender_header();

		$this->assertStringContainsString( 'Agnosis Digest', $header );
		$this->assertStringContainsString( get_option( 'admin_email' ), $header );
		$this->assertStringNotContainsString( 'not-an-email', $header );
	}

	public function test_sender_header_uses_dedicated_email_with_default_name_when_name_blank(): void {
		update_option( 'agnosis_newsletter_from_name', '' );
		update_option( 'agnosis_newsletter_from_email', 'newsletter@agnosis.art' );

		$header = Mailer::sender_header();

		$this->assertStringContainsString( get_bloginfo( 'name' ), $header );
		$this->assertStringContainsString( 'newsletter@agnosis.art', $header );
	}
}
