<?php
/**
 * Integration tests — BounceHandler::record() (security audit §5a).
 *
 * The shared sink both intake transports (Webhook::handle_bounce_event() and
 * Inbox's DSN detection) funnel a recognized bounce/complaint through — see
 * BounceHandler.php's own docblock. Tested directly here rather than only
 * indirectly through its two callers, since it's the piece that actually
 * decides what a bounce means for a given address: nothing (unknown
 * address), a suppressed newsletter subscriber, an incremented artist bounce
 * counter, or both at once for an address that's both.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Email\BounceHandler;
use Agnosis\Newsletter\Subscriber;

class BounceHandlerTest extends \WP_UnitTestCase {

	private function create_admitted_artist( string $email ): int {
		global $wpdb;

		$user_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		get_user_by( 'id', $user_id )->add_role( 'agnosis_artist' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => $email,
				'display_name' => 'Test Artist',
				'status'       => 'admitted',
				'wp_user_id'   => $user_id,
				'resolved_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%d', '%s' ]
		);

		return $user_id;
	}

	public function test_record_suppresses_matching_subscriber(): void {
		Subscriber::subscribe( 'sub-only@example.com' );

		$result = BounceHandler::record( 'sub-only@example.com' );

		$this->assertTrue( $result['subscriber_suppressed'] );
		$this->assertNull( $result['artist_id'] );
	}

	public function test_record_increments_matching_artist_bounce_counter(): void {
		$user_id = $this->create_admitted_artist( 'artist-only@example.com' );

		$result = BounceHandler::record( 'artist-only@example.com' );

		$this->assertFalse( $result['subscriber_suppressed'] );
		$this->assertSame( $user_id, $result['artist_id'] );
		$this->assertSame( 1, (int) get_user_meta( $user_id, '_agnosis_bounce_count', true ) );
	}

	public function test_record_handles_address_that_is_both_subscriber_and_artist(): void {
		$user_id = $this->create_admitted_artist( 'both@example.com' );
		Subscriber::subscribe( 'both@example.com' );

		$result = BounceHandler::record( 'both@example.com' );

		$this->assertTrue( $result['subscriber_suppressed'] );
		$this->assertSame( $user_id, $result['artist_id'] );
	}

	public function test_record_is_a_no_op_for_unknown_address(): void {
		$result = BounceHandler::record( 'nobody-knows-this@example.com' );

		$this->assertFalse( $result['subscriber_suppressed'] );
		$this->assertNull( $result['artist_id'] );
	}

	public function test_record_ignores_malformed_address(): void {
		$result = BounceHandler::record( 'not-an-email' );

		$this->assertFalse( $result['subscriber_suppressed'] );
		$this->assertNull( $result['artist_id'] );
	}

	public function test_record_accumulates_bounce_count_across_calls(): void {
		$user_id = $this->create_admitted_artist( 'repeat-bouncer@example.com' );

		BounceHandler::record( 'repeat-bouncer@example.com' );
		BounceHandler::record( 'repeat-bouncer@example.com' );
		BounceHandler::record( 'repeat-bouncer@example.com' );

		$this->assertSame( 3, (int) get_user_meta( $user_id, '_agnosis_bounce_count', true ) );
	}

	public function test_non_admitted_user_with_matching_email_is_not_counted_as_artist(): void {
		self::factory()->user->create( [ 'user_email' => 'plain-subscriber@example.com', 'role' => 'subscriber' ] );

		$result = BounceHandler::record( 'plain-subscriber@example.com' );

		$this->assertNull( $result['artist_id'] );
	}
}
