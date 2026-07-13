<?php
/**
 * Integration tests — fourth audit §3b: the opt-in SPF/DKIM auth gate must be
 * evaluated BEFORE the goodbye@ alias is routed, and goodbye@ must be
 * per-sender throttled, on the IMAP path (Inbox::process_messages()).
 *
 * Previously `Inbox::poll()` routed goodbye@/community@ before "cheap gate 3"
 * (the opt-in `agnosis_require_email_auth` check), which sat ~40 lines further
 * down and was only ever reached by messages that fell through to the normal
 * artwork/bio/event pipeline — so a spoofed From claiming to be a real admitted
 * artist could still trigger a goodbye@ self-removal confirmation email even
 * with auth enforcement turned on, and could do so an unlimited number of
 * times (no per-sender throttle existed on this path at all).
 *
 * Uses TestableInbox (overrides query_messages()) and FakeAliasImapMessage (a
 * configurable From/To/Authentication-Results double — NOT a real
 * Webklex\PHPIMAP\Message, which is why Inbox::passes_email_auth() accepts a
 * plain `object`) so these gates are exercised without a live IMAP connection.
 * Only the goodbye@ alias is driven here — community@ still requires a real
 * `Message` instance for Parser::parse_broadcast_body()'s strict type hint.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Core\RateLimiter;
use Webklex\PHPIMAP\Folder;

class InboxAliasAuthGateTest extends \WP_UnitTestCase {

	private TestableInbox $inbox;
	private Folder $folder;

	private const GOODBYE_ADDR = 'goodbye@example.com';
	private const ARTIST_EMAIL = 'artist@example.com';

	protected function setUp(): void {
		parent::setUp();

		$this->inbox  = new TestableInbox();
		$this->folder = $this->createMock( Folder::class );

		delete_option( 'agnosis_imap_last_uid' );
		update_option( 'agnosis_email_goodbye', self::GOODBYE_ADDR );
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_imap_last_uid' );
		delete_option( 'agnosis_email_goodbye' );
		delete_option( 'agnosis_require_email_auth' );
		delete_option( 'agnosis_goodbye_request_limit' );
		RateLimiter::reset_sender( 'goodbye_request', self::ARTIST_EMAIL, DAY_IN_SECONDS );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Create a WP user with the agnosis_artist role and an 'admitted' application row. */
	private function create_admitted_artist( string $email = self::ARTIST_EMAIL ): int {
		global $wpdb;

		$user_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		$user    = get_user_by( 'id', $user_id );
		$user->add_role( 'agnosis_artist' );

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

	/** Count of agnosis_departure_confirmation_requested fires during the callback. */
	private function count_confirmation_requests( callable $run ): int {
		$count = 0;
		$cb    = function () use ( &$count ) {
			++$count;
		};
		add_action( 'agnosis_departure_confirmation_requested', $cb, 10, 0 );
		$run();
		remove_action( 'agnosis_departure_confirmation_requested', $cb, 10 );
		return $count;
	}

	// -------------------------------------------------------------------------
	// Auth gate now applies to the goodbye@ alias (previously bypassed entirely)
	// -------------------------------------------------------------------------

	public function test_goodbye_alias_blocked_when_auth_required_and_message_has_no_auth_header(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_require_email_auth', 1 );

		$this->inbox->set_pending_messages( [
			new FakeAliasImapMessage( 1, self::ARTIST_EMAIL, self::GOODBYE_ADDR, '' ),
		] );

		$fires = $this->count_confirmation_requests( function (): void {
			$this->inbox->run_process_messages( $this->folder );
		} );

		$this->assertSame(
			0,
			$fires,
			'A goodbye@ request with no Authentication-Results header must be rejected before reaching Departure — the auth gate must run before alias routing.'
		);
	}

	public function test_goodbye_alias_blocked_when_auth_required_and_spf_dkim_both_fail(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_require_email_auth', 1 );

		$this->inbox->set_pending_messages( [
			new FakeAliasImapMessage( 2, self::ARTIST_EMAIL, self::GOODBYE_ADDR, 'spf=fail smtp.mailfrom=artist@example.com; dkim=fail header.d=example.com' ),
		] );

		$fires = $this->count_confirmation_requests( function (): void {
			$this->inbox->run_process_messages( $this->folder );
		} );

		$this->assertSame( 0, $fires, 'A goodbye@ request failing both SPF and DKIM must be rejected — this is exactly the spoofed-From scenario the audit flagged.' );
	}

	public function test_goodbye_alias_proceeds_when_auth_required_and_spf_passes(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_require_email_auth', 1 );

		$this->inbox->set_pending_messages( [
			new FakeAliasImapMessage( 3, self::ARTIST_EMAIL, self::GOODBYE_ADDR, 'spf=pass smtp.mailfrom=artist@example.com; dkim=fail header.d=example.com' ),
		] );

		$fires = $this->count_confirmation_requests( function (): void {
			$this->inbox->run_process_messages( $this->folder );
		} );

		$this->assertSame( 1, $fires, 'A genuinely authenticated goodbye@ request must still work — the fix must not break the legitimate path.' );
	}

	public function test_goodbye_alias_unaffected_when_auth_not_required(): void {
		// agnosis_require_email_auth left at its default (off) — regression
		// safety: the vast majority of sites do not enable this option, and the
		// goodbye@ alias must keep working exactly as before for them.
		$this->create_admitted_artist();

		$this->inbox->set_pending_messages( [
			new FakeAliasImapMessage( 4, self::ARTIST_EMAIL, self::GOODBYE_ADDR, '' ),
		] );

		$fires = $this->count_confirmation_requests( function (): void {
			$this->inbox->run_process_messages( $this->folder );
		} );

		$this->assertSame( 1, $fires );
	}

	// -------------------------------------------------------------------------
	// Goodbye@ is now per-sender throttled (previously unlimited on this path)
	// -------------------------------------------------------------------------

	public function test_goodbye_alias_throttles_repeat_requests_from_the_same_sender(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_goodbye_request_limit', 1 );

		// First request: within the limit, must succeed.
		$this->inbox->set_pending_messages( [
			new FakeAliasImapMessage( 10, self::ARTIST_EMAIL, self::GOODBYE_ADDR ),
		] );
		$first = $this->count_confirmation_requests( function (): void {
			$this->inbox->run_process_messages( $this->folder );
		} );
		$this->assertSame( 1, $first, 'The first goodbye@ request within the daily limit must succeed.' );

		// Second request, same sender, same poll cycle's daily window: must be
		// throttled — a distinct UID so it isn't short-circuited by
		// is_already_queued() as a duplicate of the first message.
		$this->inbox->set_pending_messages( [
			new FakeAliasImapMessage( 11, self::ARTIST_EMAIL, self::GOODBYE_ADDR ),
		] );
		$second = $this->count_confirmation_requests( function (): void {
			$this->inbox->run_process_messages( $this->folder );
		} );
		$this->assertSame(
			0,
			$second,
			'A second goodbye@ request from the same sender beyond the configured daily limit must be throttled, not trigger another confirmation email.'
		);
	}

	// -------------------------------------------------------------------------
	// mark_no_artwork() now captures subject/to_addresses at every skip site
	// (2026-07-14), not just no_attachments — this end-to-end pass confirms
	// the wiring actually reaches the stored row, via two representative
	// paths: a main-loop gate (auth_failed) and a handle_goodbye_email() one
	// (goodbye_throttled).
	// -------------------------------------------------------------------------

	private function latest_raw_email(): array {
		global $wpdb;
		$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT raw_email FROM {$wpdb->prefix}agnosis_queue ORDER BY id DESC LIMIT 1"
		);
		return json_decode( (string) $raw, true ) ?: [];
	}

	public function test_auth_failed_skip_captures_subject_and_recipient(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_require_email_auth', 1 );

		$this->inbox->set_pending_messages( [
			new FakeAliasImapMessage( 20, self::ARTIST_EMAIL, self::GOODBYE_ADDR, '', 'Please remove my account' ),
		] );
		$this->inbox->run_process_messages( $this->folder );

		$data = $this->latest_raw_email();
		$this->assertSame( 'Please remove my account', $data['subject'] ?? null );
		$this->assertContains( self::GOODBYE_ADDR, $data['to_addresses'] ?? [] );
	}

	public function test_goodbye_throttled_skip_captures_subject_and_recipient(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_goodbye_request_limit', 1 );

		$this->inbox->set_pending_messages( [
			new FakeAliasImapMessage( 30, self::ARTIST_EMAIL, self::GOODBYE_ADDR, '', 'Goodbye' ),
		] );
		$this->inbox->run_process_messages( $this->folder );

		// Second request, same sender, distinct UID — throttled.
		$this->inbox->set_pending_messages( [
			new FakeAliasImapMessage( 31, self::ARTIST_EMAIL, self::GOODBYE_ADDR, '', 'Goodbye again' ),
		] );
		$this->inbox->run_process_messages( $this->folder );

		$data = $this->latest_raw_email();
		$this->assertSame( 'goodbye_throttled', $data['skip_reason'] ?? null );
		$this->assertSame( 'Goodbye again', $data['subject'] ?? null );
		$this->assertContains( self::GOODBYE_ADDR, $data['to_addresses'] ?? [] );
	}
}
