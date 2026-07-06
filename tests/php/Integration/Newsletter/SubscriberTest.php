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

	/**
	 * Enumeration-safe (security audit §2c): re-subscribing an already-confirmed
	 * address must NOT return a distinguishable error — same success shape as
	 * every other outcome, just flagged internally so the REST layer knows not
	 * to send a second confirmation email.
	 */
	public function test_subscribe_already_confirmed_email_returns_success_shape_not_error(): void {
		$result = Subscriber::subscribe( 'taken@example.com' );
		Subscriber::confirm( $result['token'] );

		$second = Subscriber::subscribe( 'taken@example.com' );

		$this->assertIsArray( $second );
		$this->assertTrue( $second['already_confirmed'] );
		$this->assertFalse( $second['resent'] );

		// The confirmed row must be completely untouched — no new token issued.
		$row = Subscriber::find_by_token( $result['token'] );
		$this->assertSame( 'confirmed', $row['status'] );
	}

	// =========================================================================
	// Resend cooldown + pending expiry (security audit §2d)
	// =========================================================================

	/**
	 * Repeated submissions for the same still-pending address within the
	 * cooldown window (an impatient double-click, or a bot hammering the form)
	 * must not rotate the token or trigger a fresh confirmation email.
	 */
	public function test_subscribe_pending_email_immediate_resubmission_is_throttled(): void {
		$first  = Subscriber::subscribe( 'immediate@example.com' );
		$second = Subscriber::subscribe( 'immediate@example.com' );

		$this->assertTrue( $second['throttled'] );
		$this->assertFalse( $second['resent'] );
		$this->assertSame( $first['token'], $second['token'], 'Token must not rotate while throttled.' );

		$row = Subscriber::find_by_token( $first['token'] );
		$this->assertSame( 'pending', $row['status'], 'The original token must remain valid.' );
	}

	public function test_subscribe_pending_email_resends_with_fresh_token_after_cooldown(): void {
		global $wpdb;

		$first = Subscriber::subscribe( 'resend@example.com' );

		// Backdate created_at past the resend cooldown window (400s > 300s).
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			"UPDATE {$wpdb->prefix}agnosis_newsletter_subscribers SET created_at = ( NOW() - INTERVAL 400 SECOND ) WHERE id = %d",
			$first['id']
		) );

		$second = Subscriber::subscribe( 'resend@example.com' );

		$this->assertTrue( $second['resent'] );
		$this->assertFalse( $second['throttled'] );
		$this->assertNotSame( $first['token'], $second['token'] );

		// Old token must no longer resolve (row's token column was overwritten).
		$this->assertNull( Subscriber::find_by_token( $first['token'] ) );
	}

	public function test_expire_stale_pending_deletes_abandoned_rows(): void {
		global $wpdb;

		$result = Subscriber::subscribe( 'abandoned@example.com' );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			"UPDATE {$wpdb->prefix}agnosis_newsletter_subscribers SET created_at = ( NOW() - INTERVAL 15 DAY ) WHERE id = %d",
			$result['id']
		) );

		$deleted = Subscriber::expire_stale_pending();

		$this->assertSame( 1, $deleted );
		$this->assertNull( Subscriber::find_by_token( $result['token'] ) );
	}

	public function test_expire_stale_pending_keeps_recent_pending_rows(): void {
		$result = Subscriber::subscribe( 'fresh@example.com' );

		$deleted = Subscriber::expire_stale_pending();

		$this->assertSame( 0, $deleted );
		$this->assertNotNull( Subscriber::find_by_token( $result['token'] ) );
	}

	public function test_expire_stale_pending_never_deletes_confirmed_or_unsubscribed_rows(): void {
		global $wpdb;

		$confirmed = Subscriber::subscribe( 'old-confirmed@example.com' );
		Subscriber::confirm( $confirmed['token'] );

		$unsub = Subscriber::subscribe( 'old-unsub@example.com' );
		Subscriber::confirm( $unsub['token'] );
		Subscriber::unsubscribe( $unsub['token'] );

		// Backdate both as if they'd been sitting untouched for weeks.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			"UPDATE {$wpdb->prefix}agnosis_newsletter_subscribers SET created_at = ( NOW() - INTERVAL 30 DAY ) WHERE id IN ( %d, %d )",
			$confirmed['id'],
			$unsub['id']
		) );

		$deleted = Subscriber::expire_stale_pending();

		$this->assertSame( 0, $deleted, 'Only pending rows may ever be expired.' );
		$this->assertNotNull( Subscriber::find_by_token( $confirmed['token'] ) );
		$this->assertNotNull( Subscriber::find_by_token( $unsub['token'] ) );
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

	// =========================================================================
	// counts_by_locale() — audit §8 "locale coverage metric"
	// =========================================================================

	public function test_counts_by_locale_groups_confirmed_subscribers_by_locale(): void {
		$a = Subscriber::subscribe( 'a@example.com', 'es_ES' );
		Subscriber::confirm( $a['token'] );
		$b = Subscriber::subscribe( 'b@example.com', 'es_ES' );
		Subscriber::confirm( $b['token'] );
		$c = Subscriber::subscribe( 'c@example.com', 'fr_FR' );
		Subscriber::confirm( $c['token'] );

		$by_locale = Subscriber::counts_by_locale();

		$this->assertSame( 2, $by_locale['es_ES'] );
		$this->assertSame( 1, $by_locale['fr_FR'] );
	}

	public function test_counts_by_locale_orders_highest_count_first(): void {
		$a = Subscriber::subscribe( 'a@example.com', 'fr_FR' );
		Subscriber::confirm( $a['token'] );
		$b = Subscriber::subscribe( 'b@example.com', 'es_ES' );
		Subscriber::confirm( $b['token'] );
		$c = Subscriber::subscribe( 'c@example.com', 'es_ES' );
		Subscriber::confirm( $c['token'] );

		$by_locale = Subscriber::counts_by_locale();
		$locales   = array_keys( $by_locale );

		$this->assertSame( 'es_ES', $locales[0], 'The locale with more confirmed subscribers must be listed first.' );
	}

	public function test_counts_by_locale_excludes_pending_and_unsubscribed(): void {
		$pending = Subscriber::subscribe( 'pending@example.com', 'es_ES' ); // never confirmed
		unset( $pending );
		$unsubscribed = Subscriber::subscribe( 'unsub@example.com', 'es_ES' );
		Subscriber::confirm( $unsubscribed['token'] );
		Subscriber::unsubscribe( $unsubscribed['token'] );
		$confirmed = Subscriber::subscribe( 'confirmed@example.com', 'es_ES' );
		Subscriber::confirm( $confirmed['token'] );

		$by_locale = Subscriber::counts_by_locale();

		$this->assertSame( 1, $by_locale['es_ES'], 'Only the confirmed subscriber should be counted, not the pending or unsubscribed ones.' );
	}

	public function test_counts_by_locale_buckets_missing_locale_under_empty_string(): void {
		// A subscriber with no recorded locale (e.g. signed up before the §3c
		// frontend.js fix) must still be counted, not silently dropped.
		$no_locale = Subscriber::subscribe( 'no-locale@example.com' );
		Subscriber::confirm( $no_locale['token'] );

		$by_locale = Subscriber::counts_by_locale();

		$this->assertSame( 1, $by_locale[''] );
	}

	public function test_counts_by_locale_sums_to_total_confirmed_count(): void {
		$a = Subscriber::subscribe( 'a@example.com', 'es_ES' );
		Subscriber::confirm( $a['token'] );
		$b = Subscriber::subscribe( 'b@example.com', 'fr_FR' );
		Subscriber::confirm( $b['token'] );
		$c = Subscriber::subscribe( 'c@example.com' ); // no locale
		Subscriber::confirm( $c['token'] );
		$d = Subscriber::subscribe( 'd@example.com', 'es_ES' ); // left pending
		unset( $d );

		$by_locale = Subscriber::counts_by_locale();

		$this->assertSame( Subscriber::counts()['confirmed'], array_sum( $by_locale ) );
	}
}
