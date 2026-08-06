<?php
/**
 * Integration tests — AdmissionConfirm's template_redirect handler.
 *
 * This class had **zero coverage** before 0.9.67, alone among the eight
 * unauthenticated token landing pages in the plugin (its siblings sit at
 * 86–96%): VouchConfirm, RemovalVoteConfirm, CommunityCapVote,
 * NotificationPreferences, SubscriptionConfirm, InteractionGateway and
 * ReviewConfirm all had suites; this one did not. It is also the front door —
 * an applicant confirming their own application — so a silent failure here is
 * invisible by construction: the only person who notices is someone who never
 * got in, and they have no way to tell you.
 *
 * `AdmissionIntegrationTest`'s own helper says as much in passing: *"the real
 * confirmation path (AdmissionConfirm's template_redirect handler) isn't
 * reachable through rest_do_request(), so tests call the same method it
 * calls."* That is exactly right, and it is why `Admission::confirm_application()`
 * was well covered while the handler wrapping it was not covered at all. This
 * suite drives `handle()` directly with simulated superglobals, the same way
 * `VouchConfirmTest` does.
 *
 * Covered here:
 *
 *   handle() — guards:
 *     - No-op (no wp_die) when agnosis_admission is absent
 *     - Error page for a non-'confirm' action, and for an empty token
 *
 *   handle() — GET vs POST (the §2a mail-scanner rule):
 *     - GET renders the interstitial, returns 200, and leaves the row 'unverified'
 *     - The interstitial carries the token as a hidden POST field
 *     - POST confirms: row flips to 'pending', success page names the applicant
 *
 *   Single-use:
 *     - A second POST with the same token errors (the token is cleared on use)
 *     - An unknown token errors without touching any row
 *
 *   Waitlist branch:
 *     - When the community is full, the row becomes 'waitlisted' and the page
 *       says so rather than claiming the application is open for review
 *
 * The scanner-prefetch assertion is the load-bearing one. If GET ever started
 * confirming, corporate mail security scanning an inbound email would silently
 * confirm applications on the applicant's behalf — the same class of bug 0.9.57
 * fixed for reply links, where a prefetch could approve or discard a reply
 * before a human ever saw it.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Admission;
use Agnosis\Artist\AdmissionConfirm;
use Agnosis\Tests\Integration\Support\DieCapture;

class AdmissionConfirmTest extends \WP_UnitTestCase {

	private AdmissionConfirm $confirm;

	protected function setUp(): void {
		parent::setUp();

		$this->confirm = new AdmissionConfirm( new Admission() );

		// Intercept wp_die() — throw instead of outputting HTML and exiting.
		// Same harness as VouchConfirmTest.
		$die_interceptor = static function (): callable {
			return static function ( string|\WP_Error $message, string $title = '', array $args = [] ): never {
				// Only $message needs narrowing — wp_die() passes either a string
				// or a WP_Error. $title is already typed string by the signature,
				// so an is_string() guard on it is dead code (PHPStan says so).
				throw new DieCapture(
					is_string( $message ) ? $message : (string) $message->get_error_message(),
					$title,
					(int) ( $args['response'] ?? 200 )
				);
			};
		};
		add_filter( 'wp_die_handler', $die_interceptor );
		add_filter( 'wp_die_ajax_handler', $die_interceptor );
	}

	protected function tearDown(): void {
		unset( $_GET['agnosis_admission'], $_GET['action'], $_GET['token'] );   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_POST['agnosis_admission'], $_POST['action'], $_POST['token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $_SERVER['REQUEST_METHOD'] );
		delete_option( \Agnosis\Artist\CommunityCap::OPTION );

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Insert an 'unverified' application directly and return its confirm token.
	 *
	 * Direct insert rather than driving the REST apply() endpoint: this suite is
	 * about the confirm handler, and apply()'s own validation (language required,
	 * duplicate email, ban windows) is covered thoroughly in
	 * AdmissionIntegrationTest. Coupling to it here would make these tests fail
	 * for reasons that have nothing to do with what they assert.
	 */
	private function seed_unverified( string $email = 'applicant@example.com', string $name = 'Rosa Bonheur' ): string {
		global $wpdb;

		$token = 'tok_' . wp_generate_password( 24, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'         => $email,
				'display_name'  => $name,
				'bio'           => 'I paint animals.',
				'statement'     => 'I would like to join.',
				'language'      => 'en',
				'status'        => 'unverified',
				'confirm_token' => $token,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $token;
	}

	/** The stored status for an application, by email. */
	private function status_of( string $email = 'applicant@example.com' ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		return (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
			$email
		) );
	}

	/** @param array<string, string> $params */
	private function simulate_get( array $params ): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		foreach ( $params as $k => $v ) {
			$_GET[ $k ] = $v; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/** @param array<string, string> $params */
	private function simulate_post( array $params ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		foreach ( $params as $k => $v ) {
			$_POST[ $k ] = $v; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	// -------------------------------------------------------------------------
	// handle() — guards
	// -------------------------------------------------------------------------

	public function test_handle_is_a_no_op_without_the_agnosis_admission_param(): void {
		$this->confirm->handle();
		$this->addToAssertionCount( 1 ); // Reached this line = no wp_die() fired.
	}

	public function test_handle_errors_on_an_action_other_than_confirm(): void {
		$this->simulate_get( [
			'agnosis_admission' => '1',
			'action'            => 'delete',
			'token'             => 'anything',
		] );

		try {
			$this->confirm->handle();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
			$this->assertStringContainsString( 'Invalid or incomplete link', $e->body );
		}
	}

	public function test_handle_errors_on_an_empty_token(): void {
		$this->simulate_get( [
			'agnosis_admission' => '1',
			'action'            => 'confirm',
			'token'             => '',
		] );

		try {
			$this->confirm->handle();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
			$this->assertStringContainsString( 'Invalid or incomplete link', $e->body );
		}
	}

	// -------------------------------------------------------------------------
	// GET renders the interstitial and changes nothing (§2a scanner rule)
	// -------------------------------------------------------------------------

	public function test_get_renders_the_interstitial_without_confirming(): void {
		$token = $this->seed_unverified();

		$this->simulate_get( [
			'agnosis_admission' => '1',
			'action'            => 'confirm',
			'token'             => $token,
		] );

		try {
			$this->confirm->handle();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Confirming your application', $e->body );
		}

		$this->assertSame(
			'unverified',
			$this->status_of(),
			'A GET — which is all a mail-security scanner performs — must never confirm the application.'
		);
	}

	public function test_the_interstitial_carries_the_token_as_a_hidden_post_field(): void {
		$token = $this->seed_unverified();

		$this->simulate_get( [
			'agnosis_admission' => '1',
			'action'            => 'confirm',
			'token'             => $token,
		] );

		try {
			$this->confirm->handle();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertStringContainsString( 'method="post"', $e->body );
			$this->assertStringContainsString( 'name="token" value="' . $token . '"', $e->body );
			$this->assertStringNotContainsString(
				'action="' . home_url( '/' ) . '?',
				$e->body,
				'The token must travel in the POST body, never in the form action URL.'
			);
		}
	}

	// -------------------------------------------------------------------------
	// POST confirms
	// -------------------------------------------------------------------------

	public function test_post_confirms_the_application_and_names_the_applicant(): void {
		$token = $this->seed_unverified( 'applicant@example.com', 'Rosa Bonheur' );

		$this->simulate_post( [
			'agnosis_admission' => '1',
			'action'            => 'confirm',
			'token'             => $token,
		] );

		try {
			$this->confirm->handle();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'Application confirmed', $e->title );
			$this->assertStringContainsString( 'Rosa Bonheur', $e->body );
		}

		$this->assertSame( 'pending', $this->status_of(), 'A confirmed application must open for community review.' );
	}

	public function test_a_confirm_token_is_single_use(): void {
		$token = $this->seed_unverified();

		$this->simulate_post( [
			'agnosis_admission' => '1',
			'action'            => 'confirm',
			'token'             => $token,
		] );

		try {
			$this->confirm->handle();
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status ); // First use succeeds.
		}

		try {
			$this->confirm->handle();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
			$this->assertStringContainsString( 'already been used', $e->body );
		}

		$this->assertSame( 'pending', $this->status_of(), 'A replayed confirm must not disturb the row it already confirmed.' );
	}

	public function test_an_unknown_token_errors_and_touches_nothing(): void {
		$this->seed_unverified();

		$this->simulate_post( [
			'agnosis_admission' => '1',
			'action'            => 'confirm',
			'token'             => 'tok_not_a_real_token',
		] );

		try {
			$this->confirm->handle();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 400, $e->http_status );
			$this->assertStringContainsString( 'invalid or has already been used', $e->body );
		}

		$this->assertSame( 'unverified', $this->status_of() );
	}

	// -------------------------------------------------------------------------
	// Waitlist branch
	// -------------------------------------------------------------------------

	/**
	 * A full community waitlists rather than rejects, and the page must say so —
	 * telling an applicant their application is "open for community review" when
	 * it is actually queued behind a cap would be a small but real lie, and the
	 * two outcomes share one code path apart from this branch.
	 */
	public function test_a_full_community_waitlists_the_application_and_says_so(): void {
		// CommunityCap counts WP users holding the agnosis_artist role against
		// its own option (agnosis_community_max_artists — NOT agnosis_community_cap,
		// which is a different setting entirely); a cap of 0 means unlimited.
		update_option( \Agnosis\Artist\CommunityCap::OPTION, 1 );
		$artist = self::factory()->user->create( [ 'role' => 'agnosis_artist' ] );
		$this->assertIsInt( $artist );
		$this->assertTrue( ( new \Agnosis\Artist\CommunityCap() )->is_full(), 'Fixture precondition: the community must actually be full.' );

		$token = $this->seed_unverified( 'waitlisted@example.com', 'Berthe Morisot' );

		$this->simulate_post( [
			'agnosis_admission' => '1',
			'action'            => 'confirm',
			'token'             => $token,
		] );

		try {
			$this->confirm->handle();
			$this->fail( 'Expected wp_die().' );
		} catch ( DieCapture $e ) {
			$this->assertSame( 200, $e->http_status );
			$this->assertStringContainsString( 'waitlist', $e->body );
			$this->assertStringNotContainsString( 'can now review your application', $e->body );
		}

		$this->assertSame( 'waitlisted', $this->status_of( 'waitlisted@example.com' ) );
	}
}
