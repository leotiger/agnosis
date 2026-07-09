<?php
/**
 * Integration tests — fourth audit §3b: the opt-in SPF/DKIM auth gate must be
 * evaluated BEFORE the goodbye@/community@ aliases are routed, and goodbye@
 * must be per-sender throttled, on the webhook path (Webhook::handle()).
 *
 * Previously `Webhook::handle()` routed goodbye@/community@ before "Gate 3"
 * (the opt-in `agnosis_require_email_auth` check), which sat ~90 lines further
 * down and was only ever reached by requests that fell through to the normal
 * artwork/bio/event pipeline — so a spoofed From claiming to be a real admitted
 * artist could still trigger a goodbye@ self-removal confirmation email even
 * with auth enforcement turned on, with no per-sender throttle on this path
 * at all.
 *
 * `handle()` accepts a plain array-backed WP_REST_Request, so unlike the IMAP
 * path (Inbox) this needs no message-object double — a real WP_UnitTestCase
 * environment is enough.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Core\RateLimiter;
use Agnosis\Email\Webhook;
use WP_REST_Request;

class WebhookAliasAuthGateTest extends \WP_UnitTestCase {

	private Webhook $webhook;

	private const GOODBYE_ADDR = 'goodbye@example.com';
	private const ARTIST_EMAIL = 'artist@example.com';

	protected function setUp(): void {
		parent::setUp();
		$this->webhook = new Webhook();
		update_option( 'agnosis_email_goodbye', self::GOODBYE_ADDR );
	}

	protected function tearDown(): void {
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

	private function goodbye_request( string $auth_header = '' ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( 'sender', self::ARTIST_EMAIL );
		$request->set_param( 'recipient', self::GOODBYE_ADDR );
		if ( '' !== $auth_header ) {
			$request->set_param( 'authentication-results', $auth_header );
		}
		return $request;
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

	public function test_goodbye_alias_blocked_when_auth_required_and_no_auth_header(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_require_email_auth', 1 );

		$request = $this->goodbye_request();
		$fires   = $this->count_confirmation_requests( function () use ( $request ): void {
			$this->webhook->handle( $request );
		} );

		$this->assertSame(
			0,
			$fires,
			'A goodbye@ request with no Authentication-Results data must be rejected before reaching Departure — the auth gate must run before alias routing.'
		);
	}

	public function test_goodbye_alias_returns_skipped_auth_failed_status(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_require_email_auth', 1 );

		$response = $this->webhook->handle( $this->goodbye_request() );
		$data     = $response->get_data();

		$this->assertSame( 'skipped', $data['status'] );
		$this->assertSame( 'auth_failed', $data['reason'] );
	}

	public function test_goodbye_alias_blocked_when_spf_dkim_both_fail(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_require_email_auth', 1 );

		$request = $this->goodbye_request( 'spf=fail smtp.mailfrom=artist@example.com; dkim=fail header.d=example.com' );
		$fires   = $this->count_confirmation_requests( function () use ( $request ): void {
			$this->webhook->handle( $request );
		} );

		$this->assertSame( 0, $fires, 'A goodbye@ request failing both SPF and DKIM must be rejected — this is exactly the spoofed-From scenario the audit flagged.' );
	}

	public function test_goodbye_alias_proceeds_when_spf_passes(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_require_email_auth', 1 );

		$request = $this->goodbye_request( 'spf=pass smtp.mailfrom=artist@example.com; dkim=fail header.d=example.com' );
		$fires   = $this->count_confirmation_requests( function () use ( $request ): void {
			$this->webhook->handle( $request );
		} );

		$this->assertSame( 1, $fires, 'A genuinely authenticated goodbye@ request must still work — the fix must not break the legitimate path.' );
	}

	public function test_goodbye_alias_unaffected_when_auth_not_required(): void {
		// agnosis_require_email_auth left at its default (off) — regression
		// safety: the vast majority of sites do not enable this option.
		$this->create_admitted_artist();

		$fires = $this->count_confirmation_requests( function (): void {
			$this->webhook->handle( $this->goodbye_request() );
		} );

		$this->assertSame( 1, $fires );
	}

	// -------------------------------------------------------------------------
	// Goodbye@ is now per-sender throttled (previously unlimited on this path)
	// -------------------------------------------------------------------------

	public function test_goodbye_alias_throttles_repeat_requests_from_the_same_sender(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_goodbye_request_limit', 1 );

		$first = $this->count_confirmation_requests( function (): void {
			$this->webhook->handle( $this->goodbye_request() );
		} );
		$this->assertSame( 1, $first, 'The first goodbye@ request within the daily limit must succeed.' );

		$second_response = null;
		$second           = $this->count_confirmation_requests( function () use ( &$second_response ): void {
			$second_response = $this->webhook->handle( $this->goodbye_request() );
		} );

		$this->assertSame(
			0,
			$second,
			'A second goodbye@ request from the same sender beyond the configured daily limit must be throttled, not trigger another confirmation email.'
		);
		$this->assertSame( 'goodbye_throttled', $second_response->get_data()['reason'] );
	}

	// -------------------------------------------------------------------------
	// community@ is covered by the same moved gate — this is the impersonation
	// scenario the audit called out as the most damaging: a spoofed From
	// relays attacker text to every other artist under the impersonated
	// artist's own name.
	// -------------------------------------------------------------------------

	public function test_community_alias_blocked_when_auth_required_and_no_auth_header(): void {
		$this->create_admitted_artist();
		update_option( 'agnosis_email_community', 'community@example.com' );
		update_option( 'agnosis_require_email_auth', 1 );

		$request = new WP_REST_Request();
		$request->set_param( 'sender', self::ARTIST_EMAIL );
		$request->set_param( 'recipient', 'community@example.com' );
		$request->set_param( 'subject', 'Impersonated announcement' );
		$request->set_param( 'stripped-text', 'This did not really come from me.' );

		$response = $this->webhook->handle( $request );
		$data     = $response->get_data();

		$this->assertSame( 'skipped', $data['status'] );
		$this->assertSame(
			'auth_failed',
			$data['reason'],
			'A spoofed-From community@ broadcast must be rejected by the auth gate before ever reaching CommunityBroadcast — previously this ran ~90 lines below and never covered this alias.'
		);

		delete_option( 'agnosis_email_community' );
	}
}
