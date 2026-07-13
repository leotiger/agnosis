<?php
/**
 * Integration tests — VouchConfirm template_redirect vote handler.
 *
 * Since the §2a fix (mail-security scanners prefetching action links),
 * handle() only calls exit() (via wp_die()) after the token is verified — and
 * only *records* the vote on POST. GET renders a confirm interstitial and
 * leaves the DB untouched. wp_die() is intercepted via the 'wp_die_handler'
 * filter (thrown as DieCapture) so both paths can now be exercised end-to-end
 * without killing the test process. This suite covers:
 *
 *   verify_token() (via Reflection — private method):
 *     - Returns true for a correctly-signed token
 *     - Returns false for a tampered token (bit-flipped)
 *     - Returns false when vote value is different from the signed value
 *     - Returns false when voter_id is different from the signed value
 *     - Returns false for an empty token
 *     - Token is consistent with AdmissionNotification::vote_url() production
 *
 *   handle() — no-op guard:
 *     - Returns immediately (no side effects) when agnosis_vouch is absent
 *     - Does not touch the DB when the query string is empty
 *
 *   handle() — GET vs. POST (§2a):
 *     - GET renders the confirm interstitial and does not record a vote
 *     - POST records the vote and renders the success page
 *     - An invalid/tampered token renders an error on GET already (cheap,
 *       stateless HMAC check — no DB write either way)
 *
 *   Token ↔ vote_url() round-trip:
 *     - A token produced by vote_url() passes verify_token() for the same params
 *     - A yes token does not verify for a no vote (and vice-versa)
 *     - Swapping voter IDs causes verify_token() to return false
 *
 * DB-side vote recording is covered by Admission::record_vote(), which is called
 * after a successful verify_token() and is exercised in AdmissionIntegrationTest.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Admission;
use Agnosis\Artist\AdmissionNotification;
use Agnosis\Artist\VouchConfirm;
use Agnosis\Tests\Integration\Support\DieCapture;

class VouchConfirmTest extends \WP_UnitTestCase {

	private VouchConfirm $confirm;
	private Admission $admission;
	private \ReflectionMethod $verify_token;

	protected function setUp(): void {
		parent::setUp();

		$this->admission = new Admission();
		$this->confirm   = new VouchConfirm( $this->admission );

		// Expose the private verify_token() method.
		$rc = new \ReflectionClass( VouchConfirm::class );
		$this->verify_token = $rc->getMethod( 'verify_token' );
		$this->verify_token->setAccessible( true );

		update_option( 'agnosis_admission_percent', 0 );
		update_option( 'agnosis_admission_minimum', 2 );

		// Intercept wp_die() — throw instead of outputting HTML/exiting.
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
		unset( $_GET['agnosis_vouch'], $_GET['voter'], $_GET['app'], $_GET['vote'], $_GET['token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_POST['agnosis_vouch'], $_POST['voter'], $_POST['app'], $_POST['vote'], $_POST['token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_SERVER['REQUEST_METHOD'] );

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function call_verify( int $voter_id, int $application_id, string $vote, string $token ): bool {
		return (bool) $this->verify_token->invoke(
			$this->confirm,
			$voter_id,
			$application_id,
			$vote,
			$token
		);
	}

	private function make_token( int $voter_id, int $application_id, string $vote ): string {
		return hash_hmac(
			'sha256',
			"{$voter_id}|{$application_id}|{$vote}",
			wp_salt( 'auth' )
		);
	}

	private function create_artist(): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_userdata( $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	private function insert_application( string $email ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[ 'email' => $email, 'display_name' => 'Test', 'status' => 'pending' ],
			[ '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	// =========================================================================
	// verify_token() — token logic
	// =========================================================================

	public function test_verify_accepts_correct_token(): void {
		$voter_id = 5;
		$app_id = 12;
		$vote = 'yes';
		$token = $this->make_token( $voter_id, $app_id, $vote );

		$this->assertTrue( $this->call_verify( $voter_id, $app_id, $vote, $token ) );
	}

	public function test_verify_rejects_tampered_token(): void {
		$token   = $this->make_token( 5, 12, 'yes' );
		$flipped = substr( $token, 0, -1 ) . ( $token[-1] === 'a' ? 'b' : 'a' );

		$this->assertFalse( $this->call_verify( 5, 12, 'yes', $flipped ) );
	}

	public function test_verify_rejects_wrong_vote(): void {
		// Token signed for 'yes' must not validate when presented as 'no'.
		$token = $this->make_token( 5, 12, 'yes' );

		$this->assertFalse( $this->call_verify( 5, 12, 'no', $token ) );
	}

	public function test_verify_rejects_wrong_voter(): void {
		// Token signed for voter 5 must not validate for voter 6.
		$token = $this->make_token( 5, 12, 'yes' );

		$this->assertFalse( $this->call_verify( 6, 12, 'yes', $token ) );
	}

	public function test_verify_rejects_wrong_application(): void {
		// Token signed for application 12 must not validate for application 99.
		$token = $this->make_token( 5, 12, 'yes' );

		$this->assertFalse( $this->call_verify( 5, 99, 'yes', $token ) );
	}

	public function test_verify_rejects_empty_token(): void {
		$this->assertFalse( $this->call_verify( 5, 12, 'yes', '' ) );
	}

	// =========================================================================
	// Token round-trip: vote_url() → verify_token()
	// =========================================================================

	public function test_vote_url_token_passes_verify(): void {
		$voter_id = 8;
		$app_id = 20;
		$vote = 'yes';

		$url = AdmissionNotification::vote_url( $voter_id, $app_id, $vote );
		$parsed = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );

		$this->assertTrue(
			$this->call_verify( $voter_id, $app_id, $vote, $parsed['token'] )
		);
	}

	public function test_yes_token_does_not_verify_as_no(): void {
		$url = AdmissionNotification::vote_url( 8, 20, 'yes' );
		$parsed = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );

		// Use the yes-signed token but claim the vote is 'no'.
		$this->assertFalse( $this->call_verify( 8, 20, 'no', $parsed['token'] ) );
	}

	public function test_swapped_voter_id_fails_verify(): void {
		$url = AdmissionNotification::vote_url( 10, 3, 'yes' );
		$parsed = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );

		// Present the token for voter 10 as if it were from voter 11.
		$this->assertFalse( $this->call_verify( 11, 3, 'yes', $parsed['token'] ) );
	}

	// =========================================================================
	// handle() — no-op guard (safe to call without exit concern)
	// =========================================================================

	public function test_handle_is_noop_when_agnosis_vouch_absent(): void {
		global $wpdb;

		// No GET params — handle() must return before touching anything.
		unset( $_GET['agnosis_vouch'] );

		$before = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_application_vouches"
		);

		// handle() returns early when 'agnosis_vouch' is absent — no exit.
		$this->confirm->handle();

		$after = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_application_vouches"
		);

		$this->assertSame( $before, $after, 'handle() must not write to DB when agnosis_vouch is absent.' );
	}

	// =========================================================================
	// handle() — GET renders the confirm interstitial, does not record a vote (§2a)
	// =========================================================================

	public function test_handle_get_renders_interstitial_without_recording_vote(): void {
		$artist         = $this->create_artist();
		$application_id = $this->insert_application( 'interstitial@example.com' );
		$token          = $this->make_token( $artist, $application_id, 'yes' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_vouch'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['voter']         = (string) $artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['app']           = (string) $application_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['vote']          = 'yes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']         = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the confirm interstitial (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			// Source string is British "favour" (VouchConfirm.php); the default
			// WP_UnitTestCase test locale is en_US, and agnosis-en_US.po
			// deliberately Americanizes it to "favor" for that locale — same
			// as "Behaviour" -> "Behavior" elsewhere in that translation file.
			$this->assertStringContainsString( 'favor', $e->body );
		}

		$this->assertSame(
			0,
			$this->admission->count_positive_vouches( $application_id ),
			'GET alone must never record a vote — only the confirm POST may.'
		);
	}

	public function test_handle_post_records_vote_and_renders_success(): void {
		$artist         = $this->create_artist();
		$application_id = $this->insert_application( 'postvote@example.com' );
		$token          = $this->make_token( $artist, $application_id, 'yes' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['agnosis_vouch'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['voter']         = (string) $artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['app']           = (string) $application_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['vote']          = 'yes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['token']         = $token; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the success page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
		}

		$this->assertSame(
			1,
			$this->admission->count_positive_vouches( $application_id ),
			'POST with a valid token must record the vote.'
		);
	}

	public function test_handle_get_with_tampered_token_renders_error_and_does_not_record(): void {
		$artist         = $this->create_artist();
		$application_id = $this->insert_application( 'tampered@example.com' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['agnosis_vouch'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['voter']         = (string) $artist; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['app']           = (string) $application_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['vote']          = 'yes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['token']         = 'not-the-real-token'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$this->confirm->handle();
			$this->fail( 'Expected the error page (wp_die).' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'tampered', $e->body );
		}

		$this->assertSame( 0, $this->admission->count_positive_vouches( $application_id ) );
	}

	// =========================================================================
	// Admission::record_vote() via token — DB side effects
	// =========================================================================

	/**
	 * Verifying token logic and DB write independently is the cleanest approach
	 * given that handle() exits after rendering.
	 *
	 * This test confirms that the token produced by vote_url() is the exact token
	 * that would be verified by handle(), and that record_vote() correctly persists
	 * the vote — i.e. the two halves of the flow are each correct.
	 */
	public function test_valid_token_from_vote_url_would_record_vote(): void {
		$artist         = $this->create_artist();
		$application_id = $this->insert_application( 'verify@example.com' );

		// Build the token exactly as handle() would receive it.
		$url = AdmissionNotification::vote_url( $artist, $application_id, 'yes' );
		$parsed = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );

		$token_valid = $this->call_verify(
			(int) $parsed['voter'],
			(int) $parsed['app'],
			$parsed['vote'],
			$parsed['token']
		);
		$this->assertTrue( $token_valid, 'Token from vote_url() must pass verify_token().' );

		// Simulate handle() calling record_vote() after successful verification.
		$result = $this->admission->record_vote( $artist, $application_id, 'yes' );
		$this->assertFalse( is_wp_error( $result ), 'record_vote() must succeed.' );
		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( 1, $result->get_data()['vouches_received'] );
	}

	public function test_record_vote_with_no_vote_does_not_count_toward_positive(): void {
		$artist         = $this->create_artist();
		$application_id = $this->insert_application( 'novote@example.com' );

		$result = $this->admission->record_vote( $artist, $application_id, 'no' );

		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( 'no', $result->get_data()['vote'] );
		$this->assertSame( 0, $result->get_data()['vouches_received'],
			'A "no" vote must not increment the positive vouch count.' );
	}

	public function test_vote_can_be_changed_from_yes_to_no(): void {
		$artist         = $this->create_artist();
		$application_id = $this->insert_application( 'change@example.com' );

		// First vote: yes.
		$this->admission->record_vote( $artist, $application_id, 'yes' );
		$this->assertSame( 1, $this->admission->count_positive_vouches( $application_id ) );

		// Change to no — ON DUPLICATE KEY UPDATE should overwrite.
		$this->admission->record_vote( $artist, $application_id, 'no' );
		$this->assertSame( 0, $this->admission->count_positive_vouches( $application_id ),
			'Changing vote from yes to no must remove the positive count.' );
	}

	public function test_vote_can_be_changed_back_to_yes(): void {
		$artist         = $this->create_artist();
		$application_id = $this->insert_application( 'changeback@example.com' );

		$this->admission->record_vote( $artist, $application_id, 'no' );
		$this->admission->record_vote( $artist, $application_id, 'yes' );

		$this->assertSame( 1, $this->admission->count_positive_vouches( $application_id ) );
	}
}
