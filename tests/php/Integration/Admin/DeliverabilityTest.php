<?php
/**
 * Integration tests — security audit §5c: the "Deliverability" health card
 * on Settings → Email Inbox.
 *
 * Covers the pure diagnostic class (Admin\Deliverability — From-domain vs
 * site-domain matching, SPF/DMARC TXT-record checks, SMTP-plugin detection)
 * and Settings::handle_send_deliverability_test(), the admin-post handler
 * behind the card's "Send Test" button.
 *
 * dns_get_record() has no WordPress core filter of its own (unlike
 * wp_remote_get()'s pre_http_request), so Deliverability::txt_lookup()
 * exposes its own 'agnosis_deliverability_dns_txt' filter as a test seam —
 * every SPF/DMARC test below hooks that instead of depending on live,
 * possibly network-isolated, DNS resolution for a purely diagnostic admin
 * page feature. Likewise 'agnosis_deliverability_smtp_plugin_detectors'
 * lets the "a plugin IS detected" case be tested without a real SMTP
 * plugin's class actually being loaded.
 *
 * The admin-post handler tests use the same RedirectCapture/DieCapture
 * pattern SettingsTermTranslationCacheTest already established for
 * intercepting wp_safe_redirect()/wp_die() without killing the test process.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\Deliverability;
use Agnosis\Admin\Settings;
use Agnosis\Tests\Integration\Support\DieCapture;
use Agnosis\Tests\Integration\Support\RedirectCapture;

class DeliverabilityTest extends \WP_UnitTestCase {

	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();

		$this->settings = new Settings();

		add_filter(
			'wp_redirect',
			static function ( string $url, int $status ): never {
				throw new RedirectCapture( $url, $status );
			},
			10,
			2
		);

		$die_interceptor = static function (): callable {
			return static function ( string|\WP_Error $message, string $title = '', array $args = [] ): never {
				$http_status = (int) ( $args['response'] ?? 200 );
				$title_str   = is_string( $title ) ? $title : '';
				$msg_str     = is_string( $message ) ? wp_strip_all_tags( $message ) : (string) $message->get_error_message();
				throw new DieCapture( $msg_str, $title_str, $http_status );
			};
		};
		add_filter( 'wp_die_handler',      $die_interceptor );
		add_filter( 'wp_die_ajax_handler', $die_interceptor );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_mail_from_email' );
		delete_option( 'agnosis_newsletter_from_email' );
		unset( $_REQUEST['_wpnonce'], $_POST['test_email'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		remove_all_filters( 'agnosis_deliverability_dns_txt' );
		remove_all_filters( 'agnosis_deliverability_smtp_plugin_detectors' );
		remove_all_filters( 'agnosis_deliverability_site_domain' );
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

	// =========================================================================
	// Deliverability::site_domain()
	// =========================================================================

	public function test_site_domain_matches_home_url_host(): void {
		$expected = strtolower( (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' ) );

		$this->assertSame( $expected, Deliverability::site_domain() );
	}

	// =========================================================================
	// Deliverability::identity_report()
	// =========================================================================

	public function test_identity_report_flags_a_domain_that_matches_the_site(): void {
		// Pinned via the filter rather than trusting home_url() directly:
		// this WP test environment's WP_TESTS_DOMAIN resolves to a bare
		// "localhost" (no TLD), which is_email() itself rejects as a domain
		// — that's a quirk of this environment, not something this test's
		// domain-matching logic should depend on.
		add_filter( 'agnosis_deliverability_site_domain', static fn(): string => 'my-agnosis-site.example' );
		update_option( 'agnosis_mail_from_email', 'hello@my-agnosis-site.example' );
		update_option( 'agnosis_newsletter_from_email', 'digest@totally-different-domain.example' );
		add_filter( 'agnosis_deliverability_dns_txt', static fn(): array => [ 'status' => 'not_found', 'records' => [] ] );

		$rows = Deliverability::identity_report();

		$this->assertCount( 2, $rows );
		$this->assertSame( 'my-agnosis-site.example', $rows[0]['domain'] );
		$this->assertTrue( $rows[0]['domain_matches_site'] );
		$this->assertSame( 'totally-different-domain.example', $rows[1]['domain'] );
		$this->assertFalse( $rows[1]['domain_matches_site'] );
	}

	public function test_identity_report_dedupes_identities_sharing_one_address(): void {
		// Both left unconfigured — CommunityMailer::sender_header() and
		// Newsletter\Mailer::sender_header() both fall back to admin_email,
		// so this is the common single-address setup, not an edge case.
		delete_option( 'agnosis_mail_from_email' );
		delete_option( 'agnosis_newsletter_from_email' );
		update_option( 'admin_email', 'shared@example.com' );
		add_filter( 'agnosis_deliverability_dns_txt', static fn(): array => [ 'status' => 'not_found', 'records' => [] ] );

		$rows = Deliverability::identity_report();

		$this->assertCount( 1, $rows, 'Two identities resolving to the same address must be reported once, not twice.' );
		$this->assertSame( 'shared@example.com', $rows[0]['email'] );
		$this->assertStringContainsString( 'Mail from', $rows[0]['label'] );
		$this->assertStringContainsString( 'Newsletter', $rows[0]['label'] );
	}

	// =========================================================================
	// Deliverability::spf_check()
	// =========================================================================

	public function test_spf_check_reports_found_for_a_v_spf1_record(): void {
		add_filter( 'agnosis_deliverability_dns_txt', static fn(): array => [
			'status'  => 'found',
			'records' => [ 'v=spf1 include:_spf.example.com ~all' ],
		] );

		$result = Deliverability::spf_check( 'example.com' );

		$this->assertSame( 'found', $result['status'] );
		$this->assertCount( 1, $result['records'] );
	}

	public function test_spf_check_reports_not_found_when_txt_records_have_no_spf_prefix(): void {
		add_filter( 'agnosis_deliverability_dns_txt', static fn(): array => [
			'status'  => 'found',
			'records' => [ 'google-site-verification=abc123' ],
		] );

		$result = Deliverability::spf_check( 'example.com' );

		$this->assertSame( 'not_found', $result['status'], 'An unrelated TXT record must not be mistaken for SPF just because a TXT record exists.' );
	}

	public function test_spf_check_passes_through_a_failed_dns_lookup(): void {
		add_filter( 'agnosis_deliverability_dns_txt', static fn(): array => [ 'status' => 'lookup_failed', 'records' => [] ] );

		$result = Deliverability::spf_check( 'example.com' );

		$this->assertSame( 'lookup_failed', $result['status'] );
	}

	// =========================================================================
	// Deliverability::dmarc_check()
	// =========================================================================

	public function test_dmarc_check_queries_the_underscore_dmarc_subdomain(): void {
		$queried_host = null;
		add_filter( 'agnosis_deliverability_dns_txt', function ( $pre, string $host ) use ( &$queried_host ): array {
			$queried_host = $host;
			return [ 'status' => 'found', 'records' => [ 'v=DMARC1; p=none' ] ];
		}, 10, 2 );

		Deliverability::dmarc_check( 'example.com' );

		$this->assertSame( '_dmarc.example.com', $queried_host );
	}

	public function test_dmarc_check_is_unavailable_for_an_empty_domain(): void {
		$result = Deliverability::dmarc_check( '' );

		$this->assertSame( 'unavailable', $result['status'] );
	}

	// =========================================================================
	// Deliverability::detected_smtp_plugin()
	// =========================================================================

	public function test_detected_smtp_plugin_returns_null_when_none_are_active(): void {
		$this->assertNull( Deliverability::detected_smtp_plugin() );
	}

	public function test_detected_smtp_plugin_reports_a_filter_registered_detector(): void {
		add_filter( 'agnosis_deliverability_smtp_plugin_detectors', static function ( array $detectors ): array {
			$detectors['Fake SMTP Plugin'] = static fn(): bool => true;
			return $detectors;
		} );

		$this->assertSame( 'Fake SMTP Plugin', Deliverability::detected_smtp_plugin() );
	}

	// =========================================================================
	// Settings::handle_send_deliverability_test()
	// =========================================================================

	public function test_test_handler_rejects_users_without_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_send_deliverability_test' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['test_email']  = 'someone@example.com';

		try {
			$this->settings->handle_send_deliverability_test();
			$this->fail( 'Expected wp_die() for a user without manage_options.' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}
	}

	public function test_test_handler_rejects_an_invalid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_REQUEST['_wpnonce'] = 'not-a-valid-nonce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['test_email']  = 'someone@example.com';

		try {
			$this->settings->handle_send_deliverability_test();
			$this->fail( 'Expected wp_die() for an invalid nonce.' );
		} catch ( DieCapture $e ) {
			$this->addToAssertionCount( 1 ); // check_admin_referer() itself dies here — reached the right place.
		}
	}

	public function test_test_handler_redirects_with_failure_for_an_invalid_email(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_send_deliverability_test' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['test_email']  = 'not-an-email';

		try {
			$this->settings->handle_send_deliverability_test();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'deliverability_test_failed', $e->url );
		}
	}

	public function test_test_handler_sends_a_real_test_email_and_redirects_with_success(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'agnosis_send_deliverability_test' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['test_email']  = 'operator@example.com';

		$captured = null;
		$filter   = $this->capture_mail( $captured );

		try {
			$this->settings->handle_send_deliverability_test();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'deliverability_test_sent', $e->url );
		} finally {
			remove_filter( 'pre_wp_mail', $filter, 10 );
		}

		$this->assertNotNull( $captured );
		$this->assertSame( 'operator@example.com', $captured['to'] );
		$this->assertStringContainsString( '[TEST]', $captured['subject'] );
	}
}
