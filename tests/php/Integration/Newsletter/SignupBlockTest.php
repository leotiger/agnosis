<?php
/**
 * Integration tests — agnosis/newsletter-signup block rendering.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\Archive;
use Agnosis\Newsletter\SignupBlock;

class SignupBlockTest extends \WP_UnitTestCase {

	private SignupBlock $block;

	protected function setUp(): void {
		parent::setUp();
		$this->block = new SignupBlock();
	}

	/** Mirrors ArchiveTest's helper — direct insert so no real send pipeline is needed. */
	private function insert_sent_public_issue(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'agnosis_newsletter_issues',
			[
				'newsletter_type' => 'public',
				'status'          => 'sent',
				'sent_at'         => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s' ]
		);
	}

	public function test_render_block_is_empty_when_public_newsletter_disabled(): void {
		update_option( 'agnosis_newsletter_public_enabled', false );

		$html = $this->block->render_block();

		$this->assertSame( '', $html );
	}

	public function test_render_block_outputs_form_when_enabled(): void {
		update_option( 'agnosis_newsletter_public_enabled', true );

		$html = $this->block->render_block();

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'type="email"', $html );
		$this->assertStringContainsString( 'agnosis-newsletter-signup', $html );
	}

	/**
	 * Accessibility (security audit §3f): the email label must be explicitly
	 * associated with its input via for/id — previously they were unrelated
	 * siblings, so a screen reader had no programmatic link between them.
	 */
	public function test_email_label_is_associated_with_its_input(): void {
		update_option( 'agnosis_newsletter_public_enabled', true );

		$html = $this->block->render_block();

		$this->assertMatchesRegularExpression( '/<label\s+for="([^"]+)"/', $html );
		preg_match( '/<label\s+for="([^"]+)"/', $html, $label_match );
		preg_match( '/<input[^>]*\bid="([^"]+)"/', $html, $input_match );

		$this->assertNotEmpty( $label_match[1] ?? '' );
		$this->assertSame( $label_match[1], $input_match[1] ?? null, "The label's for attribute must match the input's id." );
	}

	// =========================================================================
	// "See past issues" archive link (added 2026-07-06)
	// =========================================================================

	public function test_archive_link_absent_when_nothing_has_ever_been_sent(): void {
		update_option( 'agnosis_newsletter_public_enabled', true );

		$html = $this->block->render_block();

		$this->assertStringNotContainsString( 'See past issues', $html );
	}

	public function test_archive_link_shown_once_an_issue_has_been_sent(): void {
		update_option( 'agnosis_newsletter_public_enabled', true );
		$this->insert_sent_public_issue();

		$html = $this->block->render_block();

		$this->assertStringContainsString( 'See past issues', $html );
		$this->assertStringContainsString( Archive::archive_url(), $html );
	}

	// =========================================================================
	// Language selector (mirrors JoinPage's, added alongside the locale-coverage metric)
	// =========================================================================

	public function test_render_block_outputs_language_select(): void {
		update_option( 'agnosis_newsletter_public_enabled', true );

		$html = $this->block->render_block();

		$this->assertStringContainsString( 'name="language"', $html );
		$this->assertStringContainsString( '<select', $html );
	}

	/**
	 * Unlike JoinPage's language select, this one must NOT be required and
	 * must NOT have a disabled placeholder option — a wrong/blank guess here
	 * is low-stakes (see SignupBlock::render_block()'s doc), so the field
	 * stays a single required field lighter than the join form.
	 */
	public function test_language_select_is_not_required(): void {
		update_option( 'agnosis_newsletter_public_enabled', true );

		$html = $this->block->render_block();

		preg_match( '/<select[^>]*name="language"[^>]*>/', $html, $select_match );
		$this->assertNotEmpty( $select_match[0] ?? '' );
		$this->assertStringNotContainsString( 'required', $select_match[0] );
	}

	public function test_language_select_lists_at_least_one_language_option(): void {
		update_option( 'agnosis_newsletter_public_enabled', true );

		$html = $this->block->render_block();

		$this->assertMatchesRegularExpression( '/<select[^>]*name="language"[^>]*>.*?<option value="[a-z-]+">.*?<\/select>/s', $html );
	}

	/** Accessibility, same convention as the email field. */
	public function test_language_label_is_associated_with_its_select(): void {
		update_option( 'agnosis_newsletter_public_enabled', true );

		$html = $this->block->render_block();

		preg_match( '/<label\s+for="([^"]*language[^"]*)"/', $html, $label_match );
		preg_match( '/<select[^>]*\bid="([^"]*language[^"]*)"/', $html, $select_match );

		$this->assertNotEmpty( $label_match[1] ?? '' );
		$this->assertSame( $label_match[1], $select_match[1] ?? null, "The language label's for attribute must match the select's id." );
	}
}
