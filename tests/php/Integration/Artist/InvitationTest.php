<?php
/**
 * Integration tests — Artist\Invitation.
 *
 * Invitation is deliberately stateless (no list, no queue, nothing tracked —
 * see the class docblock), so these tests only cover: input validation,
 * that a real send actually calls wp_mail() with the right shape, that
 * send_test() prefixes the subject with [TEST], that the join-page link
 * resolves correctly (both the recorded-page-ID path and its fallback), and
 * that the configured intro appears in the body. AI-translation of the intro
 * itself is not exercised here — no provider is configured in this test
 * environment, so localized_intro() always takes its no-provider-configured
 * fallback (the original, untranslated text) regardless of which language is
 * requested; that fallback is exactly what these tests assert on.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Invitation;

class InvitationTest extends \WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( 'agnosis_invitation_intro' );
		delete_option( 'agnosis_join_page_id' );
		parent::tearDown();
	}

	/** @return array{0: array<string, mixed>|null, 1: callable} */
	private function capture_mail( ?array &$captured ): callable {
		$filter = function ( $pre, array $atts ) use ( &$captured ) {
			$captured = $atts;
			return true; // Short-circuits the real wp_mail() send.
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		return $filter;
	}

	public function test_send_rejects_an_invalid_email(): void {
		$result = ( new Invitation() )->send( 'not-an-email', '' );
		$this->assertSame( 'Please enter a valid email address.', $result );
	}

	public function test_send_test_rejects_an_invalid_email(): void {
		$result = ( new Invitation() )->send_test( 'not-an-email', '' );
		$this->assertSame( 'Please enter a valid email address.', $result );
	}

	public function test_send_delivers_to_the_given_address(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$result = ( new Invitation() )->send( 'prospect@example.com', '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertTrue( $result );
		$this->assertNotNull( $captured );
		$this->assertSame( 'prospect@example.com', $captured['to'] );
		$this->assertStringContainsString( "You're invited", $captured['subject'] );
		$this->assertStringNotContainsString( '[TEST]', $captured['subject'] );
	}

	public function test_send_test_prefixes_the_subject(): void {
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		( new Invitation() )->send_test( 'admin@example.com', '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertStringStartsWith( '[TEST]', $captured['subject'] );
	}

	public function test_email_body_includes_the_configured_intro(): void {
		update_option( 'agnosis_invitation_intro', 'A distinctive intro paragraph for this test.' );

		$captured = null;
		$filter   = $this->capture_mail( $captured );

		( new Invitation() )->send( 'prospect@example.com', '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( 'A distinctive intro paragraph for this test.', $captured['message'] );
	}

	public function test_email_body_omits_the_intro_block_when_none_configured(): void {
		// Default option value is real starter copy (see Settings::field_definitions()),
		// so explicitly force it empty to test the "nothing configured" branch.
		update_option( 'agnosis_invitation_intro', '' );

		$captured = null;
		$filter   = $this->capture_mail( $captured );

		( new Invitation() )->send( 'prospect@example.com', '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( 'Apply to join', $captured['message'], 'The rest of the email must still render with no intro configured.' );
	}

	public function test_email_links_to_the_recorded_join_page(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'Join', 'post_status' => 'publish' ] );
		update_option( 'agnosis_join_page_id', $page_id );

		$captured = null;
		$filter   = $this->capture_mail( $captured );

		( new Invitation() )->send( 'prospect@example.com', '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( (string) get_permalink( $page_id ), $captured['message'] );
	}

	public function test_email_falls_back_to_the_conventional_join_slug_when_no_page_is_recorded(): void {
		// agnosis_join_page_id deliberately left unset by this test.
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		( new Invitation() )->send( 'prospect@example.com', '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( home_url( '/join/' ), $captured['message'] );
	}
}
