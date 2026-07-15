<?php
/**
 * Integration tests — AdmissionNotification email dispatch and content.
 *
 * Tests cover:
 *
 *   on_application_received():
 *     - Sends one email per current artist (personalised)
 *     - Each artist email contains yes and no vote links
 *     - Vote links contain voter, app, vote, and token query params
 *     - Vote links use correct voter_id for each recipient
 *     - Email body includes applicant's display name
 *     - Email body includes bio, portfolio URL, and statement when present
 *     - Admin summary is sent to admin_email when no artists exist yet
 *     - No email sent to artists when application row does not exist
 *
 *   vote_url() (public static):
 *     - Returns URL with required query parameters
 *     - Token matches expected hash_hmac value
 *     - Yes and no votes produce different tokens
 *     - Different voter IDs produce different tokens
 *
 *   on_application_expired():
 *     - Sends rejection email to the applicant's email address
 *     - Sends community closure notice (BCC) when artists exist
 *     - Skips sending when application row does not exist
 *
 *   on_artist_admitted():
 *     - Sends welcome email to the newly created WP user
 *     - Welcome email contains a password-reset link
 *     - Skips sending when user_id does not match any user
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\AdmissionNotification;

class AdmissionNotificationTest extends \WP_UnitTestCase {

	private AdmissionNotification $notification;

	/** All wp_mail() calls captured during a test (keys: to, subject, message, headers). */
	private array $sent_mails = [];

	/** The pre_wp_mail filter closure registered for the current test. */
	private ?\Closure $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->notification = new AdmissionNotification();

		// Predictable threshold so tests don't depend on active artist count.
		update_option( 'agnosis_admission_percent', 0 );
		update_option( 'agnosis_admission_minimum', 2 );
		update_option( 'agnosis_admission_window_days', 7 );
	}

	protected function tearDown(): void {
		$this->remove_mail_capture();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Start capturing wp_mail() calls into $this->sent_mails.
	 * Returns after installing the filter so callers can invoke notification methods.
	 */
	private function start_mail_capture(): void {
		$this->sent_mails = [];
		$this->mail_filter = function ( $pre, array $atts ): bool {
			$this->sent_mails[] = $atts;
			return true; // Short-circuit — do not actually send.
		};
		add_filter( 'pre_wp_mail', $this->mail_filter, 10, 2 );
	}

	private function remove_mail_capture(): void {
		if ( $this->mail_filter ) {
			remove_filter( 'pre_wp_mail', $this->mail_filter, 10 );
			$this->mail_filter = null;
		}
	}

	/** Create a WP user with the agnosis_artist role and return their ID. */
	private function create_artist( string $email = '' ): int {
		$args = [ 'role' => 'subscriber' ];
		if ( $email ) {
			$args['user_email'] = $email;
		}
		$id   = self::factory()->user->create( $args );
		$user = get_userdata( $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	/** Insert a row into agnosis_applications and return the application ID. */
	private function insert_application(
		string $email = 'app@example.com',
		string $display_name = 'Test Applicant',
		string $bio = 'My bio.',
		string $portfolio = 'https://example.com/portfolio',
		string $statement = 'Why I want to join.'
	): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'         => $email,
				'display_name'  => $display_name,
				'bio'           => $bio,
				'portfolio_url' => $portfolio,
				'statement'     => $statement,
				'status'        => 'pending',
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Return all emails captured whose 'to' field matches $email exactly.
	 *
	 * @return array<array<string,mixed>>
	 */
	private function mails_to( string $email ): array {
		return array_values(
			array_filter( $this->sent_mails, fn( array $m ) => $m['to'] === $email )
		);
	}

	// =========================================================================
	// vote_url() — token generation
	// =========================================================================

	public function test_vote_url_contains_required_params(): void {
		$url = AdmissionNotification::vote_url( 5, 12, 'yes' );

		$parsed = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );

		$this->assertArrayHasKey( 'agnosis_vouch', $parsed );
		$this->assertArrayHasKey( 'voter',         $parsed );
		$this->assertArrayHasKey( 'app',           $parsed );
		$this->assertArrayHasKey( 'vote',          $parsed );
		$this->assertArrayHasKey( 'token',         $parsed );
	}

	public function test_vote_url_token_matches_expected_hmac(): void {
		$voter_id       = 7;
		$application_id = 3;
		$vote           = 'yes';

		$url = AdmissionNotification::vote_url( $voter_id, $application_id, $vote );
		$parsed = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );

		$expected = hash_hmac(
			'sha256',
			"{$voter_id}|{$application_id}|{$vote}",
			wp_salt( 'auth' )
		);

		$this->assertSame( $expected, $parsed['token'] );
	}

	public function test_vote_url_yes_and_no_produce_different_tokens(): void {
		$yes_url = AdmissionNotification::vote_url( 5, 12, 'yes' );
		$no_url  = AdmissionNotification::vote_url( 5, 12, 'no' );

		$yes_parsed = [];
		$no_parsed  = [];
		parse_str( (string) parse_url( $yes_url, PHP_URL_QUERY ), $yes_parsed );
		parse_str( (string) parse_url( $no_url,  PHP_URL_QUERY ), $no_parsed );

		$this->assertNotSame( $yes_parsed['token'], $no_parsed['token'] );
	}

	public function test_vote_url_different_voters_produce_different_tokens(): void {
		$url_a = AdmissionNotification::vote_url( 1, 5, 'yes' );
		$url_b = AdmissionNotification::vote_url( 2, 5, 'yes' );

		$a_parsed = [];
		$b_parsed = [];
		parse_str( (string) parse_url( $url_a, PHP_URL_QUERY ), $a_parsed );
		parse_str( (string) parse_url( $url_b, PHP_URL_QUERY ), $b_parsed );

		$this->assertNotSame( $a_parsed['token'], $b_parsed['token'] );
	}

	public function test_vote_url_voter_param_matches_voter_id(): void {
		$url = AdmissionNotification::vote_url( 42, 7, 'no' );
		$parsed = [];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );

		$this->assertSame( '42', $parsed['voter'] );
		$this->assertSame( '7',  $parsed['app'] );
		$this->assertSame( 'no', $parsed['vote'] );
	}

	// =========================================================================
	// on_application_received()
	// =========================================================================

	public function test_received_sends_one_email_per_artist(): void {
		$artist1        = $this->create_artist( 'artist1@example.com' );
		$artist2        = $this->create_artist( 'artist2@example.com' );
		$application_id = $this->insert_application( 'new@example.com', 'New Person' );

		$this->start_mail_capture();
		$this->notification->on_application_received( $application_id, 'new@example.com', 'New Person' );
		$this->remove_mail_capture();

		$this->assertNotEmpty( $this->mails_to( 'artist1@example.com' ) );
		$this->assertNotEmpty( $this->mails_to( 'artist2@example.com' ) );
	}

	public function test_received_email_contains_yes_and_no_links(): void {
		$artist         = $this->create_artist( 'voterA@example.com' );
		$application_id = $this->insert_application( 'joinme@example.com', 'Join Me' );

		$this->start_mail_capture();
		$this->notification->on_application_received( $application_id, 'joinme@example.com', 'Join Me' );
		$this->remove_mail_capture();

		$mails_to_artist = $this->mails_to( 'voterA@example.com' );
		$this->assertNotEmpty( $mails_to_artist );

		$body = $mails_to_artist[0]['message'];
		$this->assertStringContainsString( 'agnosis_vouch=1', $body );
		$this->assertStringContainsString( 'vote=yes',        $body );
		$this->assertStringContainsString( 'vote=no',         $body );
	}

	public function test_received_email_uses_correct_voter_id_in_link(): void {
		$artist         = $this->create_artist( 'voterB@example.com' );
		$application_id = $this->insert_application( 'tester@example.com', 'Tester' );

		$this->start_mail_capture();
		$this->notification->on_application_received( $application_id, 'tester@example.com', 'Tester' );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'voterB@example.com' )[0]['message'];
		$this->assertStringContainsString( "voter={$artist}", $body );
	}

	public function test_received_email_contains_applicant_display_name(): void {
		$artist         = $this->create_artist( 'voterC@example.com' );
		$application_id = $this->insert_application( 'applicant@example.com', 'Maria Doe' );

		$this->start_mail_capture();
		$this->notification->on_application_received( $application_id, 'applicant@example.com', 'Maria Doe' );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'voterC@example.com' )[0]['message'];
		$this->assertStringContainsString( 'Maria Doe', $body );
	}

	public function test_received_email_includes_bio_and_portfolio(): void {
		$artist = $this->create_artist( 'voterD@example.com' );
		$application_id = $this->insert_application(
			email:       'withbio@example.com',
			display_name:'Bio Artist',
			bio:         'I paint landscapes.',
			portfolio:   'https://portfolio.example.com',
			statement:   'Excited to share my art.'
		);

		$this->start_mail_capture();
		$this->notification->on_application_received( $application_id, 'withbio@example.com', 'Bio Artist' );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'voterD@example.com' )[0]['message'];
		$this->assertStringContainsString( 'I paint landscapes.',         $body );
		$this->assertStringContainsString( 'https://portfolio.example.com', $body );
		$this->assertStringContainsString( 'Excited to share my art.',    $body );
	}

	public function test_received_sends_admin_summary_when_no_artists(): void {
		// No artists exist — only admin summary should fire.
		$application_id = $this->insert_application( 'lone@example.com', 'Lone Wolf' );

		$this->start_mail_capture();
		$this->notification->on_application_received( $application_id, 'lone@example.com', 'Lone Wolf' );
		$this->remove_mail_capture();

		// Some email must have been sent (the admin summary).
		$this->assertNotEmpty( $this->sent_mails );

		// One of the emails goes to the admin address.
		$admin_email = get_option( 'admin_email' );
		$this->assertNotEmpty( $this->mails_to( $admin_email ) );
	}

	public function test_received_sends_no_email_when_application_does_not_exist(): void {
		$this->start_mail_capture();
		$this->notification->on_application_received( 99999, 'ghost@example.com', 'Ghost' );
		$this->remove_mail_capture();

		$this->assertEmpty( $this->sent_mails );
	}

	public function test_received_skips_instant_email_for_digest_mode_artist(): void {
		// Security audit §5b/§4a: an artist who switched to digest mode must
		// not also get the instant per-application email — Artist\VoteDigest's
		// daily cron is what delivers it to them instead.
		$digest_artist  = $this->create_artist( 'digest-mode@example.com' );
		$instant_artist = $this->create_artist( 'instant-mode@example.com' );
		update_user_meta( $digest_artist, '_agnosis_vote_email_mode', 'digest' );
		$application_id = $this->insert_application( 'digestapp@example.com', 'Digest Applicant' );

		$this->start_mail_capture();
		$this->notification->on_application_received( $application_id, 'digestapp@example.com', 'Digest Applicant' );
		$this->remove_mail_capture();

		$this->assertEmpty( $this->mails_to( 'digest-mode@example.com' ), 'A digest-mode artist must not receive the instant vote email.' );
		$this->assertNotEmpty( $this->mails_to( 'instant-mode@example.com' ), 'An instant-mode (default) artist must still receive it immediately.' );
	}

	public function test_received_vote_mode_value_other_than_digest_still_gets_instant_email(): void {
		// Mirrors the same "only the literal expected value opts out" convention
		// used by the broadcast-mute meta and Scheduler::artist_recipients().
		$artist = $this->create_artist( 'stale-vote-meta@example.com' );
		update_user_meta( $artist, '_agnosis_vote_email_mode', 'nonsense' );
		$application_id = $this->insert_application( 'staleapp@example.com', 'Stale Meta Applicant' );

		$this->start_mail_capture();
		$this->notification->on_application_received( $application_id, 'staleapp@example.com', 'Stale Meta Applicant' );
		$this->remove_mail_capture();

		$this->assertNotEmpty( $this->mails_to( 'stale-vote-meta@example.com' ) );
	}

	public function test_received_admin_summary_mentions_deferred_digest_count(): void {
		$this->create_artist( 'instant-a@example.com' );
		$digest_artist = $this->create_artist( 'digest-a@example.com' );
		update_user_meta( $digest_artist, '_agnosis_vote_email_mode', 'digest' );
		$application_id = $this->insert_application( 'summary@example.com', 'Summary Applicant' );

		$this->start_mail_capture();
		$this->notification->on_application_received( $application_id, 'summary@example.com', 'Summary Applicant' );
		$this->remove_mail_capture();

		// The default WP test admin's user_email typically equals the
		// admin_email option, so mails_to() can return two distinct emails
		// here: get_admin_user_id()'s vote-email fallback (sent first, since
		// that admin account isn't an agnosis_artist) AND the plain-text
		// admin summary (sent last) — both to the same address. Locate the
		// summary specifically by its unique opening line rather than
		// assuming index 0, since send order isn't this test's concern.
		$admin_email = get_option( 'admin_email' );
		$admin_mails = $this->mails_to( $admin_email );
		$this->assertNotEmpty( $admin_mails );

		$summary_mail = null;
		foreach ( $admin_mails as $mail ) {
			if ( str_contains( $mail['message'], 'Application ID:' ) ) {
				$summary_mail = $mail;
				break;
			}
		}
		$this->assertNotNull( $summary_mail, 'Admin summary email (identified by its "Application ID:" opening line) was not found among mails to admin_email.' );
		$this->assertStringContainsString( 'Notified 1 artist(s) immediately.', $summary_mail['message'] );
		$this->assertStringContainsString( '1 more will see it in their next daily digest.', $summary_mail['message'] );

		// Audit-adjacent finding, not a numbered audit item (2026-07-15, see
		// CHANGELOG.md 0.9.29): the admin summary was plain text and untranslated
		// before this pass — now HTML via the shared Core\EmailTemplate shell.
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $summary_mail['headers'] ) );
		$this->assertStringContainsString( '<!DOCTYPE html>', $summary_mail['message'] );
	}

	// =========================================================================
	// on_application_expired()
	// =========================================================================

	public function test_expired_sends_rejection_to_applicant(): void {
		$application_id = $this->insert_application( 'expired@example.com', 'Old Applicant' );

		$this->start_mail_capture();
		$this->notification->on_application_expired( $application_id );
		$this->remove_mail_capture();

		$this->assertNotEmpty( $this->mails_to( 'expired@example.com' ) );
	}

	public function test_expired_rejection_body_mentions_community_declined(): void {
		$application_id = $this->insert_application( 'expired2@example.com', 'Old Applicant' );

		$this->start_mail_capture();
		$this->notification->on_application_expired( $application_id );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'expired2@example.com' )[0]['message'];
		// Message should communicate that the threshold was not reached.
		$this->assertStringContainsString( 'enough votes', $body );
	}

	public function test_expired_sends_community_notice_when_artists_exist(): void {
		$this->create_artist( 'member1@example.com' );
		$application_id = $this->insert_application( 'expired3@example.com', 'Old Applicant' );

		$this->start_mail_capture();
		$this->notification->on_application_expired( $application_id );
		$this->remove_mail_capture();

		// Community notice goes to admin_email with BCC headers for each artist.
		// There should be at least 2 emails: rejection to applicant + community notice.
		$this->assertGreaterThanOrEqual( 2, count( $this->sent_mails ) );
	}

	public function test_expired_sends_no_email_when_application_does_not_exist(): void {
		$this->start_mail_capture();
		$this->notification->on_application_expired( 99999 );
		$this->remove_mail_capture();

		$this->assertEmpty( $this->sent_mails );
	}

	/**
	 * Audit-adjacent finding, not a numbered audit item (2026-07-15, see
	 * CHANGELOG.md 0.9.29): the expiry emails were the last plain-text
	 * bodies in this class, now converted to the shared Core\EmailTemplate
	 * HTML shell alongside the admin summary below.
	 */
	public function test_expired_applicant_email_is_html(): void {
		$application_id = $this->insert_application( 'expiredhtml@example.com', 'Old Applicant' );

		$this->start_mail_capture();
		$this->notification->on_application_expired( $application_id );
		$this->remove_mail_capture();

		$mail = $this->mails_to( 'expiredhtml@example.com' )[0];
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $mail['headers'] ) );
		$this->assertStringContainsString( '<!DOCTYPE html>', $mail['message'] );
	}

	public function test_expired_community_notice_is_html(): void {
		$this->create_artist( 'member2@example.com' );
		$application_id = $this->insert_application( 'expired4@example.com', 'Old Applicant' );

		$this->start_mail_capture();
		$this->notification->on_application_expired( $application_id );
		$this->remove_mail_capture();

		$admin_mail = $this->mails_to( get_option( 'admin_email' ) )[0];
		$this->assertStringContainsString( 'Content-Type: text/html', implode( "\n", (array) $admin_mail['headers'] ) );
	}

	// =========================================================================
	// on_artist_admitted()
	// =========================================================================

	public function test_admitted_sends_welcome_email_to_new_user(): void {
		$user_id        = self::factory()->user->create( [ 'user_email' => 'welcome@example.com' ] );
		$application_id = $this->insert_application( 'welcome@example.com', 'New Artist' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$this->assertNotEmpty( $this->mails_to( 'welcome@example.com' ) );
	}

	public function test_admitted_welcome_email_contains_reset_link(): void {
		$user_id        = self::factory()->user->create( [ 'user_email' => 'reset@example.com' ] );
		$application_id = $this->insert_application( 'reset@example.com', 'Reset Artist' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'reset@example.com' )[0]['message'];
		// Welcome email must contain either a reset URL or the login URL as fallback.
		$this->assertTrue(
			str_contains( $body, 'action=rp' ) || str_contains( $body, 'wp-login.php' ),
			'Welcome email must contain a login or password-reset link.'
		);
	}

	public function test_admitted_sends_no_email_when_user_does_not_exist(): void {
		$this->start_mail_capture();
		$this->notification->on_artist_admitted( 99999, 1 );
		$this->remove_mail_capture();

		$this->assertEmpty( $this->sent_mails );
	}

	// =========================================================================
	// Welcome email — alias list (zero-configuration onboarding)
	// =========================================================================

	public function test_admitted_welcome_includes_configured_alias_emails(): void {
		update_option( 'agnosis_email_submit',  'submit@agnosis.test' );
		update_option( 'agnosis_email_promote', 'promote@agnosis.test' );

		$user_id        = self::factory()->user->create( [ 'user_email' => 'newbie@example.com' ] );
		$application_id = $this->insert_application( 'newbie@example.com', 'Newbie' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'newbie@example.com' )[0]['message'];

		$this->assertStringContainsString( 'submit@agnosis.test',  $body, 'Submit alias must appear in the welcome email.' );
		$this->assertStringContainsString( 'promote@agnosis.test', $body, 'Promote alias must appear in the welcome email.' );
	}

	public function test_admitted_welcome_omits_alias_section_when_none_configured(): void {
		// Clear all alias options so none are set.
		foreach ( [ 'submit', 'bio', 'event', 'replace', 'remove', 'promote' ] as $slug ) {
			update_option( "agnosis_email_{$slug}", '' );
		}

		$user_id        = self::factory()->user->create( [ 'user_email' => 'clean@example.com' ] );
		$application_id = $this->insert_application( 'clean@example.com', 'Clean Slate' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'clean@example.com' )[0]['message'];
		$this->assertStringNotContainsString( 'How to share your work', $body,
			'Alias section must not appear when no addresses are configured.' );
	}

	public function test_admitted_welcome_omits_unconfigured_aliases(): void {
		// Only submit is configured — others should not appear.
		update_option( 'agnosis_email_submit', 'submit@agnosis.test' );
		update_option( 'agnosis_email_bio',    '' );
		update_option( 'agnosis_email_event',  '' );

		$user_id        = self::factory()->user->create( [ 'user_email' => 'partial@example.com' ] );
		$application_id = $this->insert_application( 'partial@example.com', 'Partial Setup' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'partial@example.com' )[0]['message'];
		$this->assertStringContainsString( 'submit@agnosis.test', $body );
		// bio and event are empty — their labels must not appear.
		$this->assertStringNotContainsString( 'Submit biography', $body );
		$this->assertStringNotContainsString( 'Submit event',     $body );
	}

	// =========================================================================
	// Welcome email — gallery URL and /my-submissions/ link
	// =========================================================================

	public function test_admitted_welcome_includes_my_submissions_link(): void {
		$user_id        = self::factory()->user->create( [ 'user_email' => 'subs@example.com' ] );
		$application_id = $this->insert_application( 'subs@example.com', 'Subs Artist' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'subs@example.com' )[0]['message'];
		$this->assertStringContainsString( '/my-submissions/', $body,
			'Welcome email must include a link to /my-submissions/.' );
	}

	public function test_admitted_welcome_includes_gallery_url(): void {
		// No base domain configured — SubdomainRouter falls back to home_url().
		delete_option( 'agnosis_base_domain' );

		$user_id        = self::factory()->user->create( [ 'user_email' => 'gallery@example.com' ] );
		$application_id = $this->insert_application( 'gallery@example.com', 'Gallery Artist' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$body       = $this->mails_to( 'gallery@example.com' )[0]['message'];
		$home       = rtrim( home_url(), '/' );

		$this->assertStringContainsString( $home, $body,
			'Welcome email must include the artist gallery URL.' );
	}

	public function test_admitted_welcome_includes_subject_conventions_when_aliases_configured(): void {
		update_option( 'agnosis_email_submit', 'submit@agnosis.test' );

		$user_id        = self::factory()->user->create( [ 'user_email' => 'conv1@example.com' ] );
		$application_id = $this->insert_application( 'conv1@example.com', 'Conv Artist' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'conv1@example.com' )[0]['message'];
		$this->assertStringContainsString( '[Biography]', $body,
			'Subject convention for biography must appear when aliases are configured.' );
		$this->assertStringContainsString( '[Event]', $body,
			'Subject convention for event must appear when aliases are configured.' );
	}

	public function test_admitted_welcome_omits_subject_conventions_when_no_aliases(): void {
		foreach ( [ 'submit', 'bio', 'event', 'replace', 'remove', 'promote' ] as $slug ) {
			update_option( "agnosis_email_{$slug}", '' );
		}

		$user_id        = self::factory()->user->create( [ 'user_email' => 'conv2@example.com' ] );
		$application_id = $this->insert_application( 'conv2@example.com', 'No Alias Artist' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'conv2@example.com' )[0]['message'];
		$this->assertStringNotContainsString( '[Biography]', $body,
			'Subject conventions must not appear when no aliases are configured.' );
	}

	// =========================================================================
	// Welcome email — goodbye alias
	// =========================================================================

	public function test_admitted_welcome_includes_goodbye_address_when_configured(): void {
		update_option( 'agnosis_email_goodbye', 'goodbye@agnosis.test' );

		$user_id        = self::factory()->user->create( [ 'user_email' => 'bye1@example.com' ] );
		$application_id = $this->insert_application( 'bye1@example.com', 'Bye Artist 1' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'bye1@example.com' )[0]['message'];
		$this->assertStringContainsString( 'goodbye@agnosis.test', $body,
			'Welcome email must include the configured goodbye alias address.' );
	}

	public function test_admitted_welcome_includes_goodbye_confirmation_note(): void {
		update_option( 'agnosis_email_goodbye', 'goodbye@agnosis.test' );

		$user_id        = self::factory()->user->create( [ 'user_email' => 'bye2@example.com' ] );
		$application_id = $this->insert_application( 'bye2@example.com', 'Bye Artist 2' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'bye2@example.com' )[0]['message'];
		$this->assertStringContainsString( 'confirmation link', $body,
			'Welcome email must explain that a confirmation link is sent before deletion.' );
	}

	public function test_admitted_welcome_omits_goodbye_section_when_not_configured(): void {
		delete_option( 'agnosis_email_goodbye' );

		$user_id        = self::factory()->user->create( [ 'user_email' => 'bye3@example.com' ] );
		$application_id = $this->insert_application( 'bye3@example.com', 'Bye Artist 3' );

		$this->start_mail_capture();
		$this->notification->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$body = $this->mails_to( 'bye3@example.com' )[0]['message'];
		$this->assertStringNotContainsString( 'leave the network', $body,
			'Goodbye section must not appear when the alias is not configured.' );
	}
}
