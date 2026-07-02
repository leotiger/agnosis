<?php
/**
 * Integration tests — public newsletter subscriber repository.
 *
 * Covers the double opt-in lifecycle (subscribe → confirm → unsubscribe) and
 * the edge cases around re-subscribing and duplicate confirmation.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\Subscriber;

class SubscriberTest extends \WP_UnitTestCase {

	public function test_subscribe_creates_pending_row(): void {
		$result = Subscriber::subscribe( 'artlover@example.com' );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['token'] );
		$this->assertFalse( $result['resent'] );

		$row = Subscriber::find_by_token( $result['token'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 'artlover@example.com', $row['email'] );
	}

	public function test_subscribe_rejects_invalid_email(): void {
		$result = Subscriber::subscribe( 'not-an-email' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'agnosis_invalid_email', $result->get_error_code() );
	}

	public function test_confirm_moves_pending_to_confirmed(): void {
		$result = Subscriber::subscribe( 'confirmme@example.com' );

		$ok = Subscriber::confirm( $result['token'] );
		$this->assertTrue( $ok );

		$row = Subscriber::find_by_token( $result['token'] );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertNotEmpty( $row['confirmed_at'] );
	}

	public function test_confirm_rejects_unknown_token(): void {
		$this->assertFalse( Subscriber::confirm( 'not-a-real-token' ) );
	}

	public function test_confirm_is_not_repeatable(): void {
		$result = Subscriber::subscribe( 'onceonly@example.com' );
		Subscriber::confirm( $result['token'] );

		// Second confirm attempt on an already-confirmed row must fail
		// (status is no longer 'pending', so the guarded UPDATE matches nothing).
		$this->assertFalse( Subscriber::confirm( $result['token'] ) );
	}

	public function test_subscribe_already_confirmed_email_errors(): void {
		$result = Subscriber::subscribe( 'taken@example.com' );
		Subscriber::confirm( $result['token'] );

		$second = Subscriber::subscribe( 'taken@example.com' );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'agnosis_already_subscribed', $second->get_error_code() );
	}

	public function test_subscribe_pending_email_resends_with_fresh_token(): void {
		$first  = Subscriber::subscribe( 'resend@example.com' );
		$second = Subscriber::subscribe( 'resend@example.com' );

		$this->assertTrue( $second['resent'] );
		$this->assertNotSame( $first['token'], $second['token'] );

		// Old token must no longer resolve (row's token column was overwritten).
		$this->assertNull( Subscriber::find_by_token( $first['token'] ) );
	}

	public function test_unsubscribe_confirmed_subscriber(): void {
		$result = Subscriber::subscribe( 'byebye@example.com' );
		Subscriber::confirm( $result['token'] );

		$ok = Subscriber::unsubscribe( $result['token'] );
		$this->assertTrue( $ok );

		$row = Subscriber::find_by_token( $result['token'] );
		$this->assertSame( 'unsubscribed', $row['status'] );
	}

	public function test_unsubscribe_is_not_repeatable(): void {
		$result = Subscriber::subscribe( 'onlyonce@example.com' );
		Subscriber::unsubscribe( $result['token'] );

		$this->assertFalse( Subscriber::unsubscribe( $result['token'] ) );
	}

	public function test_resubscribing_after_unsubscribe_resets_to_pending(): void {
		$result = Subscriber::subscribe( 'comeback@example.com' );
		Subscriber::confirm( $result['token'] );
		Subscriber::unsubscribe( $result['token'] );

		$again = Subscriber::subscribe( 'comeback@example.com' );

		$this->assertIsArray( $again );
		$row = Subscriber::find_by_token( $again['token'] );
		$this->assertSame( 'pending', $row['status'] );
	}

	public function test_confirmed_recipients_excludes_pending_and_unsubscribed(): void {
		$confirmed = Subscriber::subscribe( 'a@example.com' );
		Subscriber::confirm( $confirmed['token'] );

		Subscriber::subscribe( 'b@example.com' ); // stays pending

		$unsub = Subscriber::subscribe( 'c@example.com' );
		Subscriber::confirm( $unsub['token'] );
		Subscriber::unsubscribe( $unsub['token'] );

		$emails = array_column( Subscriber::confirmed_recipients(), 'email' );

		$this->assertContains( 'a@example.com', $emails );
		$this->assertNotContains( 'b@example.com', $emails );
		$this->assertNotContains( 'c@example.com', $emails );
	}

	public function test_counts_reflects_all_statuses(): void {
		$p = Subscriber::subscribe( 'p@example.com' );
		$c = Subscriber::subscribe( 'c2@example.com' );
		Subscriber::confirm( $c['token'] );
		$u = Subscriber::subscribe( 'u@example.com' );
		Subscriber::confirm( $u['token'] );
		Subscriber::unsubscribe( $u['token'] );

		$counts = Subscriber::counts();

		$this->assertSame( 1, $counts['pending'] );
		$this->assertSame( 1, $counts['confirmed'] );
		$this->assertSame( 1, $counts['unsubscribed'] );
	}
}
