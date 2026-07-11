<?php
/**
 * Integration tests — security/UX audit §5e: the "Retry Failed" button on
 * the newsletter dashboard.
 *
 * Previously a permanently-failed recipient (one that exhausted
 * QueueProcessor::MAX_ATTEMPTS, e.g. during an SMTP outage) had no resend
 * affordance at all — an operator noticing "3 failed" on the dashboard had
 * no way to act on it short of a direct database edit. This suite covers
 * both halves of the fix:
 *
 *   - Settings::render_retry_failed_button() — the private HTML renderer,
 *     exercised via ReflectionMethod (same pattern SettingsTurnstileWarningTest
 *     already uses for this class).
 *   - Settings::handle_retry_failed_newsletter_recipients() — the admin-post
 *     handler, exercised via the RedirectCapture/DieCapture pattern
 *     DeliverabilityTest/SettingsTermTranslationCacheTest already established.
 *
 * Scheduler::latest_issue_id()/failed_count_for_latest_issue() and
 * QueueProcessor::retry_failed() themselves have their own direct coverage in
 * SchedulerTest and QueueProcessorTest respectively — this file only covers
 * the admin-facing wiring on top of them.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\Settings;
use Agnosis\Newsletter\QueueProcessor;
use Agnosis\Newsletter\Scheduler;
use Agnosis\Newsletter\Subscriber;
use Agnosis\Tests\Integration\Support\DieCapture;
use Agnosis\Tests\Integration\Support\RedirectCapture;

class SettingsRetryFailedNewsletterTest extends \WP_UnitTestCase {

	private Settings $settings;
	private Scheduler $scheduler;
	private QueueProcessor $processor;
	private \ReflectionMethod $render_button;

	protected function setUp(): void {
		parent::setUp();

		$this->settings  = new Settings();
		$this->scheduler = new Scheduler();
		$this->processor = new QueueProcessor();

		$rc                  = new \ReflectionClass( Settings::class );
		$this->render_button = $rc->getMethod( 'render_retry_failed_button' );
		$this->render_button->setAccessible( true );

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
		unset( $_REQUEST['_wpnonce'], $_POST['newsletter_type'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function render( string $type ): string {
		ob_start();
		$this->render_button->invoke( $this->settings, $type );
		return (string) ob_get_clean();
	}

	private function create_confirmed_subscriber( string $email ): void {
		$result = Subscriber::subscribe( $email );
		Subscriber::confirm( $result['token'] );
	}

	private function latest_issue_id( string $type ): int {
		$id = $this->scheduler->latest_issue_id( $type );
		$this->assertNotNull( $id );
		return $id;
	}

	/** Send + fail one recipient of $type's issue MAX_ATTEMPTS times, so it becomes terminally 'failed'. */
	private function create_a_terminally_failed_recipient( string $type ): void {
		if ( 'public' === $type ) {
			$this->create_confirmed_subscriber( 'fails-' . uniqid() . '@example.com' );
		} else {
			$id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
			$user = get_userdata( $id );
			$user->add_role( 'agnosis_artist' );
		}
		$this->scheduler->send_now( $type );

		$filter = function ( $pre, array $atts ): bool {
			return false; // every send fails
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		$this->processor->process();
		$this->processor->process();
		$this->processor->process(); // MAX_ATTEMPTS exhausted
		remove_filter( 'pre_wp_mail', $filter, 10 );
	}

	// =========================================================================
	// render_retry_failed_button()
	// =========================================================================

	public function test_shows_none_when_no_issue_exists(): void {
		$html = $this->render( 'public' );

		$this->assertStringContainsString( 'None', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_shows_none_when_the_latest_issue_has_no_failed_rows(): void {
		$this->create_confirmed_subscriber( 'ok@example.com' );
		$this->scheduler->send_now( 'public' );

		$html = $this->render( 'public' );

		$this->assertStringContainsString( 'None', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_shows_a_retry_button_and_count_when_recipients_have_failed(): void {
		$this->create_a_terminally_failed_recipient( 'public' );

		$html = $this->render( 'public' );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'value="agnosis_retry_failed_newsletter_recipients"', $html );
		$this->assertStringContainsString( 'value="public"', $html );
		$this->assertStringContainsString( '1', $html );
	}

	// =========================================================================
	// handle_retry_failed_newsletter_recipients()
	// =========================================================================

	public function test_handler_rejects_users_without_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_REQUEST['_wpnonce']    = wp_create_nonce( 'agnosis_retry_failed_newsletter_recipients_public' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['newsletter_type'] = 'public';

		try {
			$this->settings->handle_retry_failed_newsletter_recipients();
			$this->fail( 'Expected wp_die() for a user without manage_options.' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'permission', $e->body );
		}
	}

	public function test_handler_rejects_an_invalid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_REQUEST['_wpnonce']    = 'not-a-valid-nonce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['newsletter_type'] = 'public';

		try {
			$this->settings->handle_retry_failed_newsletter_recipients();
			$this->fail( 'Expected wp_die() for an invalid nonce.' );
		} catch ( DieCapture $e ) {
			$this->addToAssertionCount( 1 ); // check_admin_referer() itself dies here.
		}
	}

	public function test_handler_requeues_failed_rows_and_redirects_with_success(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->create_a_terminally_failed_recipient( 'public' );
		$issue_id = $this->latest_issue_id( 'public' );

		$_REQUEST['_wpnonce']    = wp_create_nonce( 'agnosis_retry_failed_newsletter_recipients_public' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['newsletter_type'] = 'public';

		try {
			$this->settings->handle_retry_failed_newsletter_recipients();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'newsletter_retry_queued', $e->url );
		}

		$this->assertSame( 0, $this->scheduler->failed_count_for_latest_issue( 'public' ), 'The failed row must have been reset to pending.' );
		$this->assertSame( $issue_id, $this->latest_issue_id( 'public' ), 'retry_failed() reuses the existing issue — it must not create a new one.' );
	}

	public function test_handler_redirects_with_none_queued_when_nothing_failed(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->create_confirmed_subscriber( 'all-ok@example.com' );
		$this->scheduler->send_now( 'public' );

		$_REQUEST['_wpnonce']    = wp_create_nonce( 'agnosis_retry_failed_newsletter_recipients_public' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['newsletter_type'] = 'public';

		try {
			$this->settings->handle_retry_failed_newsletter_recipients();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'newsletter_retry_none', $e->url );
		}
	}

	public function test_handler_redirects_with_none_queued_when_no_issue_exists_at_all(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_REQUEST['_wpnonce']    = wp_create_nonce( 'agnosis_retry_failed_newsletter_recipients_artist' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['newsletter_type'] = 'artist';

		try {
			$this->settings->handle_retry_failed_newsletter_recipients();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCapture $e ) {
			$this->assertStringContainsString( 'newsletter_retry_none', $e->url );
		}
	}

	public function test_handler_only_requeues_the_named_type_not_the_other(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->create_a_terminally_failed_recipient( 'public' );
		$this->create_a_terminally_failed_recipient( 'artist' );

		$_REQUEST['_wpnonce']    = wp_create_nonce( 'agnosis_retry_failed_newsletter_recipients_public' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['newsletter_type'] = 'public';

		try {
			$this->settings->handle_retry_failed_newsletter_recipients();
		} catch ( RedirectCapture $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( 0, $this->scheduler->failed_count_for_latest_issue( 'public' ) );
		$this->assertSame( 1, $this->scheduler->failed_count_for_latest_issue( 'artist' ), 'Retrying "public" must not touch the "artist" issue\'s failed rows.' );
	}
}
