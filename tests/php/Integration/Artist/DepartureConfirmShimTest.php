<?php
/**
 * Integration tests — Departure::handle_departure_confirm() (2026-07-08 fix).
 *
 * The shim used to redirect to /?agnosis_departure=confirmed or =invalid on
 * completion, but its guard only checked isset( $_GET['agnosis_departure'] ),
 * not its value — so loading that very redirect target re-entered the same
 * method, found no token, and redirected to /?agnosis_departure=invalid
 * again, which re-triggers itself the same way: an infinite loop, reported
 * live as Safari's "Too many redirects" on that exact URL. The fix checks for
 * the literal value '1' and renders the outcome directly via wp_die() instead
 * of redirecting at all, mirroring Publishing\ReviewConfirm's established
 * pattern for its own review-link result pages.
 *
 * Testing strategy mirrors ReviewConfirmIntegrationTest: wp_die() is
 * intercepted via the 'wp_die_handler' filter and thrown as a DieCapture
 * exception instead of outputting HTML, so assertions can be made on the
 * message and HTTP status without killing the test process.
 *
 * Coverage:
 *   - No agnosis_departure param at all → no-op (untouched request)
 *   - agnosis_departure = 'confirmed' or 'invalid' (the shim's own former
 *     redirect targets) → no-op, NOT reprocessed — this is the regression
 *     test for the infinite-loop bug itself
 *   - agnosis_departure = '1' with no token → renders the "invalid" result
 *   - agnosis_departure = '1' with an unknown token → renders the "invalid" result
 *   - agnosis_departure = '1' with a valid token → executes removal and
 *     renders the "you have left" result
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Departure;
use Agnosis\Tests\Integration\Support\DieCapture;

class DepartureConfirmShimTest extends \WP_UnitTestCase {

	private Departure $departure;

	protected function setUp(): void {
		parent::setUp();
		$this->departure = new Departure();

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
		unset( $_GET['agnosis_departure'], $_GET['token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		parent::tearDown();
	}

	/** Create an admitted artist with a removal token and return [user_id, application_id, token]. */
	private function create_artist_with_removal_token( string $email ): array {
		global $wpdb;

		$user_id = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		get_user_by( 'id', $user_id )->add_role( 'agnosis_artist' );

		$token = bin2hex( random_bytes( 32 ) );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'         => $email,
				'display_name'  => 'Test Artist',
				'status'        => 'admitted',
				'wp_user_id'    => $user_id,
				'removal_token' => $token,
				'resolved_at'   => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		return [ $user_id, (int) $wpdb->insert_id, $token ];
	}

	// -------------------------------------------------------------------------
	// No-op cases
	// -------------------------------------------------------------------------

	public function test_no_op_without_agnosis_departure_param(): void {
		$this->departure->handle_departure_confirm();
		$this->addToAssertionCount( 1 ); // Reached this line = no wp_die fired.
	}

	/**
	 * THE regression test for the infinite-redirect bug: 'confirmed' and
	 * 'invalid' are exactly the values the shim's own (former) redirect used
	 * to set. Landing on that URL must NOT be treated as a fresh confirmation
	 * attempt — it must be a complete no-op, since nothing after this method
	 * on the same request will render anything for these values either.
	 */
	public function test_result_values_are_not_reprocessed(): void {
		foreach ( [ 'confirmed', 'invalid' ] as $value ) {
			$_GET['agnosis_departure'] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$this->departure->handle_departure_confirm();
			$this->addToAssertionCount( 1 ); // Reached this line = no wp_die fired, no loop.

			unset( $_GET['agnosis_departure'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	public function test_bare_zero_value_is_not_reprocessed(): void {
		$_GET['agnosis_departure'] = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->departure->handle_departure_confirm();
		$this->addToAssertionCount( 1 );
	}

	// -------------------------------------------------------------------------
	// Trigger cases — agnosis_departure = '1'
	// -------------------------------------------------------------------------

	public function test_missing_token_renders_invalid_result(): void {
		$_GET['agnosis_departure'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->departure->handle_departure_confirm();
			$this->fail( 'Expected wp_die (invalid result).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
			$this->assertStringContainsString( 'expired or already used', $e->body );
		}
	}

	public function test_unknown_token_renders_invalid_result(): void {
		$_GET['agnosis_departure'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']             = 'no-such-token'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->departure->handle_departure_confirm();
			$this->fail( 'Expected wp_die (invalid result).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}

	public function test_valid_token_executes_removal_and_renders_success_result(): void {
		global $wpdb;

		[ $user_id, , $token ] = $this->create_artist_with_removal_token( 'shim-success@example.com' );

		$_GET['agnosis_departure'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']             = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->departure->handle_departure_confirm();
			$this->fail( 'Expected wp_die (success result).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'You have left', $e->body );
		}

		$this->assertFalse( get_user_by( 'id', $user_id ), 'The removal must actually have executed — WP user deleted.' );
	}

	public function test_reused_token_after_success_renders_invalid_result(): void {
		[ , , $token ] = $this->create_artist_with_removal_token( 'shim-reuse@example.com' );

		$_GET['agnosis_departure'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']             = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->departure->handle_departure_confirm();
			$this->fail( 'Expected wp_die on first use.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}

		// Second click of the same email link (e.g. a double-click, or a mail
		// client prefetching it twice) must land on the invalid result, not
		// execute removal again or loop.
		try {
			$this->departure->handle_departure_confirm();
			$this->fail( 'Expected wp_die on second use.' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
		}
	}
}
