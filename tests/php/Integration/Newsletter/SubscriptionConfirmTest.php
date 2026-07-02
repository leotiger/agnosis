<?php
/**
 * Integration tests — SubscriptionConfirm template_redirect shim.
 *
 * handle() calls exit() after rendering its response page for every valid
 * dispatch (confirm/unsubscribe), so — same limitation documented in
 * VouchConfirmTest / ReviewConfirm — only the no-op guard can be exercised
 * end-to-end in PHPUnit. The routing logic it dispatches to (Subscriber::
 * confirm()/unsubscribe(), Tokens::verify_artist_unsubscribe_token()) has its
 * own full coverage in SubscriberTest and TokensTest.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\SubscriptionConfirm;

class SubscriptionConfirmTest extends \WP_UnitTestCase {

	private SubscriptionConfirm $confirm;

	protected function setUp(): void {
		parent::setUp();
		$this->confirm = new SubscriptionConfirm();
	}

	public function test_handle_is_noop_when_agnosis_newsletter_absent(): void {
		global $wpdb;

		unset( $_GET['agnosis_newsletter'] );

		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_subscribers" );

		// Must return early (no exit) when the query var is absent.
		$this->confirm->handle();

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_subscribers" );

		$this->assertSame( $before, $after, 'handle() must not write to the DB when agnosis_newsletter is absent.' );
	}

	public function test_register_hooks_adds_template_redirect_action(): void {
		remove_all_actions( 'template_redirect' );

		$this->confirm->register_hooks();

		$this->assertGreaterThan( 0, has_action( 'template_redirect', [ $this->confirm, 'handle' ] ) );
	}
}
