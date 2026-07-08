<?php
/**
 * Integration tests — Artist departure flows (Departure.php).
 *
 * Tests cover:
 *
 *   admin_ban():
 *     - Sets status='banned' and removes agnosis_artist role
 *     - Stores banned_until when a DateTimeInterface is passed
 *     - Returns false when application is not 'admitted'
 *
 *   admin_delete():
 *     - Deletes the WP user account
 *     - Sets membership status='left' and nulls wp_user_id
 *     - Returns false when application is not found
 *
 *   confirm_self_removal():
 *     - Returns false for an unknown / already-used token
 *     - Returns true and sets status='left' for a valid token
 *     - agnosis_artist_left fires with the artist's email/locale, captured
 *       before the account is deleted (2026-07-08)
 *
 *   delete_artist_content():
 *     - Permanently deletes all agnosis CPT posts by the artist
 *     - Does not touch posts authored by other users
 *     - Does not touch non-Agnosis post types by the same user
 *
 *   check_expired_bans():
 *     - Reinstates an artist whose banned_until is in the past
 *     - Does not reinstate an artist whose banned_until is in the future
 *     - Does not reinstate an artist with an indefinite ban (NULL banned_until)
 *
 *   admin_open_removal_vote():
 *     - Creates a new removal request in 'open' status
 *     - Returns true on success
 *     - Escalates an existing 'nominating' request to 'open' rather than creating a duplicate
 *
 *   record_vote_on_request() — shared core used by both cast_vote() (REST) and
 *   RemovalVoteConfirm (email-link shim, security audit §2e):
 *     - Records a yes/no vote on an open request
 *     - Returns 404 for an unknown request
 *     - Returns an error when the request is not 'open'
 *     - Returns an error when the voter is the subject of the removal
 *     - cast_vote() (REST) delegates to it with the current user as voter
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Departure;

class DepartureTest extends \WP_UnitTestCase {

	private Departure $departure;

	protected function setUp(): void {
		parent::setUp();
		$this->departure = new Departure();
		update_option( 'agnosis_removal_nomination_threshold', 3 );
		update_option( 'agnosis_removal_window_days', 7 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a WP user with the agnosis_artist role, insert an 'admitted'
	 * application row, and return [ user_id, application_id ].
	 *
	 * @return array{0: int, 1: int}
	 */
	private function create_admitted_artist( string $email = 'artist@example.com' ): array {
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

		return [ $user_id, (int) $wpdb->insert_id ];
	}

	/** Create an agnosis_artwork post owned by $user_id. */
	private function create_artwork( int $user_id ): int {
		return (int) self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_author' => $user_id,
			'post_status' => 'publish',
		] );
	}

	/** Insert an 'open' removal request for $subject_id and return its row ID. */
	private function create_open_removal_request( int $subject_id ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_removal_requests',
			[
				'subject_user_id' => $subject_id,
				'status'          => 'open',
				'opened_at'       => current_time( 'mysql' ),
				'closes_at'       => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	// -------------------------------------------------------------------------
	// admin_ban()
	// -------------------------------------------------------------------------

	public function test_admin_ban_sets_status_banned(): void {
		global $wpdb;

		[ , $app_id ] = $this->create_admitted_artist( 'ban1@example.com' );

		$this->departure->admin_ban( $app_id );

		$status = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
			$app_id
		) );

		$this->assertSame( 'banned', $status );
	}

	public function test_admin_ban_removes_artist_role(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist( 'ban2@example.com' );

		$this->departure->admin_ban( $app_id );

		$user = get_user_by( 'id', $user_id );
		$this->assertNotContains( 'agnosis_artist', (array) $user->roles );
	}

	public function test_admin_ban_stores_banned_until(): void {
		global $wpdb;

		[ , $app_id ] = $this->create_admitted_artist( 'ban3@example.com' );

		$until = new \DateTimeImmutable( '+7 days' );
		$this->departure->admin_ban( $app_id, $until );

		$banned_until = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT banned_until FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
			$app_id
		) );

		$this->assertNotNull( $banned_until );
		$this->assertStringStartsWith( $until->format( 'Y-m-d' ), (string) $banned_until );
	}

	public function test_admin_ban_returns_false_for_non_admitted_artist(): void {
		global $wpdb;

		// Insert a pending (not admitted) row.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_applications',
			[ 'email' => 'pending@example.com', 'display_name' => 'Pending', 'status' => 'pending' ],
			[ '%s', '%s', '%s' ]
		);
		$app_id = (int) $wpdb->insert_id;

		$result = $this->departure->admin_ban( $app_id );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// admin_delete()
	// -------------------------------------------------------------------------

	public function test_admin_delete_sets_status_left(): void {
		global $wpdb;

		[ , $app_id ] = $this->create_admitted_artist( 'del1@example.com' );

		$this->departure->admin_delete( $app_id );

		$status = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
			$app_id
		) );

		$this->assertSame( 'left', $status );
	}

	public function test_admin_delete_removes_wp_user(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist( 'del2@example.com' );

		$this->departure->admin_delete( $app_id );

		$this->assertFalse( get_user_by( 'id', $user_id ), 'WP user should be deleted.' );
	}

	public function test_admin_delete_nulls_wp_user_id_on_membership_row(): void {
		global $wpdb;

		[ , $app_id ] = $this->create_admitted_artist( 'del3@example.com' );

		$this->departure->admin_delete( $app_id );

		$user_id_col = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT wp_user_id FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
			$app_id
		) );

		$this->assertNull( $user_id_col );
	}

	public function test_admin_delete_returns_false_for_unknown_application(): void {
		$result = $this->departure->admin_delete( 99999 );
		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// confirm_self_removal()
	// -------------------------------------------------------------------------

	public function test_confirm_self_removal_returns_false_for_unknown_token(): void {
		$result = $this->departure->confirm_self_removal( 'no-such-token' );
		$this->assertFalse( $result );
	}

	public function test_confirm_self_removal_sets_status_left_for_valid_token(): void {
		global $wpdb;

		[ , $app_id ] = $this->create_admitted_artist( 'self1@example.com' );

		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_applications',
			[ 'removal_token' => $token ],
			[ 'id' => $app_id ],
			[ '%s' ], [ '%d' ]
		);

		$result = $this->departure->confirm_self_removal( $token );
		$this->assertTrue( $result );

		$status = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
			$app_id
		) );
		$this->assertSame( 'left', $status );
	}

	/**
	 * 2026-07-08: confirm_self_removal() now captures the artist's email/locale
	 * BEFORE execute_removal() deletes the WP account, and passes them through
	 * agnosis_artist_left — DepartureNotification::on_artist_left() needs them
	 * to send the artist their own removal-confirmation email, since the
	 * account (and therefore get_user_by()) no longer exists by the time that
	 * action fires.
	 */
	public function test_confirm_self_removal_action_includes_artist_email_and_locale(): void {
		global $wpdb;

		[ $user_id, $app_id ] = $this->create_admitted_artist( 'self3@example.com' );
		update_user_meta( $user_id, 'locale', 'es_ES' );

		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_applications',
			[ 'removal_token' => $token ],
			[ 'id' => $app_id ],
			[ '%s' ], [ '%d' ]
		);

		$fired_email  = null;
		$fired_locale = null;
		add_action(
			'agnosis_artist_left',
			static function ( int $uid, int $aid, string $email, string $locale ) use ( &$fired_email, &$fired_locale ): void {
				$fired_email  = $email;
				$fired_locale = $locale;
			},
			10,
			4
		);

		$this->departure->confirm_self_removal( $token );

		$this->assertSame( 'self3@example.com', $fired_email );
		$this->assertSame( 'es_ES', $fired_locale );
	}

	public function test_confirm_self_removal_action_email_empty_when_locale_unset(): void {
		global $wpdb;

		[ , $app_id ] = $this->create_admitted_artist( 'self4@example.com' );

		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_applications',
			[ 'removal_token' => $token ],
			[ 'id' => $app_id ],
			[ '%s' ], [ '%d' ]
		);

		$fired_locale = 'not-set';
		add_action(
			'agnosis_artist_left',
			static function ( int $uid, int $aid, string $email, string $locale ) use ( &$fired_locale ): void {
				$fired_locale = $locale;
			},
			10,
			4
		);

		$this->departure->confirm_self_removal( $token );

		$this->assertSame( '', $fired_locale, 'No locale meta set — must fire with an empty string, not a stale default.' );
	}

	public function test_confirm_self_removal_token_cannot_be_reused(): void {
		global $wpdb;

		[ , $app_id ] = $this->create_admitted_artist( 'self2@example.com' );

		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_applications',
			[ 'removal_token' => $token ],
			[ 'id' => $app_id ],
			[ '%s' ], [ '%d' ]
		);

		// First use succeeds.
		$this->assertTrue( $this->departure->confirm_self_removal( $token ) );

		// Second use with the same token must fail (token cleared + status='left').
		$this->assertFalse( $this->departure->confirm_self_removal( $token ) );
	}

	// -------------------------------------------------------------------------
	// delete_artist_content()
	// -------------------------------------------------------------------------

	public function test_delete_artist_content_removes_agnosis_posts(): void {
		[ $user_id, ] = $this->create_admitted_artist( 'content1@example.com' );

		$post_id = $this->create_artwork( $user_id );
		$this->departure->delete_artist_content( $user_id );

		$this->assertNull( get_post( $post_id ), 'Artwork post must be permanently deleted.' );
	}

	public function test_delete_artist_content_does_not_affect_other_users_posts(): void {
		[ $user_id, ] = $this->create_admitted_artist( 'content2@example.com' );

		$other_id      = self::factory()->user->create();
		$other_post_id = $this->create_artwork( $other_id );

		$this->departure->delete_artist_content( $user_id );

		$this->assertInstanceOf( \WP_Post::class, get_post( $other_post_id ),
			'Another user\'s artwork must not be deleted.' );
	}

	public function test_delete_artist_content_does_not_affect_non_agnosis_post_types(): void {
		[ $user_id, ] = $this->create_admitted_artist( 'content3@example.com' );

		$page_id = (int) self::factory()->post->create( [
			'post_type'   => 'post',
			'post_author' => $user_id,
		] );

		$this->departure->delete_artist_content( $user_id );

		$this->assertInstanceOf( \WP_Post::class, get_post( $page_id ),
			'Non-Agnosis posts by the same user must not be deleted.' );
	}

	/**
	 * WP_Query's 'post_status' => 'any' silently excludes 'trash' (and
	 * 'auto-draft'/'inherit') — a well-documented core behaviour, not a bug in
	 * WordPress, but easy to miss when the intent is "every post this user
	 * has, in whatever state". Found 2026-07-08: an artwork already trashed
	 * (e.g. individually removed via remove@ shortly before the artist
	 * departed, still inside WP's default 30-day trash retention) previously
	 * survived delete_artist_content() untouched — a "permanent and cannot be
	 * undone" departure silently left it recoverable. 'post_status' is now an
	 * explicit list including 'trash' rather than 'any'.
	 */
	public function test_delete_artist_content_removes_already_trashed_posts(): void {
		[ $user_id, ] = $this->create_admitted_artist( 'content4@example.com' );

		$post_id = $this->create_artwork( $user_id );
		wp_trash_post( $post_id );
		$this->assertSame( 'trash', get_post_status( $post_id ), 'Sanity check: post must actually be trashed before the real assertion.' );

		$this->departure->delete_artist_content( $user_id );

		$this->assertNull( get_post( $post_id ), 'An already-trashed artwork must still be permanently deleted on departure.' );
	}

	// -------------------------------------------------------------------------
	// check_expired_bans()
	// -------------------------------------------------------------------------

	public function test_check_expired_bans_reinstates_expired_artist(): void {
		global $wpdb;

		[ $user_id, $app_id ] = $this->create_admitted_artist( 'exp1@example.com' );

		// Ban with an already-passed expiry.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_applications',
			[ 'status' => 'banned', 'banned_until' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ) ],
			[ 'id' => $app_id ],
			[ '%s', '%s' ], [ '%d' ]
		);
		// Remove role to simulate the ban.
		get_user_by( 'id', $user_id )->remove_role( 'agnosis_artist' );

		$this->departure->check_expired_bans();

		$user = get_user_by( 'id', $user_id );
		$this->assertContains( 'agnosis_artist', (array) $user->roles,
			'Artist role must be restored after ban expiry.' );

		$status = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
			$app_id
		) );
		$this->assertSame( 'admitted', $status );
	}

	public function test_check_expired_bans_does_not_reinstate_future_ban(): void {
		global $wpdb;

		[ $user_id, $app_id ] = $this->create_admitted_artist( 'exp2@example.com' );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_applications',
			[ 'status' => 'banned', 'banned_until' => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ) ],
			[ 'id' => $app_id ],
			[ '%s', '%s' ], [ '%d' ]
		);
		get_user_by( 'id', $user_id )->remove_role( 'agnosis_artist' );

		$this->departure->check_expired_bans();

		$status = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
			$app_id
		) );
		$this->assertSame( 'banned', $status, 'Future ban must not be lifted by the cron.' );
	}

	public function test_check_expired_bans_does_not_reinstate_indefinite_ban(): void {
		global $wpdb;

		[ $user_id, $app_id ] = $this->create_admitted_artist( 'exp3@example.com' );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_applications',
			[ 'status' => 'banned', 'banned_until' => null ],
			[ 'id' => $app_id ],
			[ '%s', 'NULL' ], [ '%d' ]
		);
		get_user_by( 'id', $user_id )->remove_role( 'agnosis_artist' );

		$this->departure->check_expired_bans();

		$status = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
			$app_id
		) );
		$this->assertSame( 'banned', $status, 'Indefinite ban (NULL banned_until) must not be lifted.' );
	}

	// -------------------------------------------------------------------------
	// admin_open_removal_vote()
	// -------------------------------------------------------------------------

	public function test_admin_open_removal_vote_returns_true(): void {
		[ $subject_id, ] = $this->create_admitted_artist( 'vote1@example.com' );
		$admin_id = self::factory()->user->create();

		$result = $this->departure->admin_open_removal_vote( $subject_id, $admin_id );

		$this->assertTrue( $result );
	}

	public function test_admin_open_removal_vote_creates_open_request(): void {
		global $wpdb;

		[ $subject_id, ] = $this->create_admitted_artist( 'vote2@example.com' );
		$admin_id = self::factory()->user->create();

		$this->departure->admin_open_removal_vote( $subject_id, $admin_id );

		$status = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status FROM {$wpdb->prefix}agnosis_removal_requests
			  WHERE subject_user_id = %d ORDER BY id DESC LIMIT 1",
			$subject_id
		) );

		$this->assertSame( 'open', $status );
	}

	public function test_admin_open_removal_vote_escalates_existing_nominating(): void {
		global $wpdb;

		[ $subject_id, ] = $this->create_admitted_artist( 'vote3@example.com' );
		$admin_id = self::factory()->user->create();

		// Pre-insert a nominating request.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'agnosis_removal_requests',
			[ 'subject_user_id' => $subject_id, 'status' => 'nominating' ],
			[ '%d', '%s' ]
		);
		$existing_id = (int) $wpdb->insert_id;

		$this->departure->admin_open_removal_vote( $subject_id, $admin_id );

		// The existing row should be escalated, not duplicated.
		$count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_removal_requests
			  WHERE subject_user_id = %d AND status = 'open'",
			$subject_id
		) );

		$this->assertSame( 1, $count, 'Exactly one open request must exist — existing row escalated.' );

		$escalated_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT id FROM {$wpdb->prefix}agnosis_removal_requests
			  WHERE subject_user_id = %d AND status = 'open'",
			$subject_id
		) );

		$this->assertSame( $existing_id, $escalated_id, 'The escalated row ID must match the original.' );
	}

	// =========================================================================
	// initiate_removal_for_user()
	// =========================================================================

	public function test_initiate_removal_for_user_fires_confirmation_action(): void {
		[ $user_id, ] = $this->create_admitted_artist( 'goodbye1@example.com' );

		$fired_user_id = null;
		$fired_token   = null;

		add_action(
			'agnosis_departure_confirmation_requested',
			static function ( int $uid, string $token ) use ( &$fired_user_id, &$fired_token ): void {
				$fired_user_id = $uid;
				$fired_token   = $token;
			},
			10,
			2
		);

		$result = $this->departure->initiate_removal_for_user( $user_id );

		$this->assertTrue( $result );
		$this->assertSame( $user_id, $fired_user_id,
			'agnosis_departure_confirmation_requested must fire with the correct user ID.' );
		$this->assertNotEmpty( $fired_token,
			'A non-empty token must be passed to the action.' );
	}

	public function test_initiate_removal_for_user_returns_false_for_non_admitted(): void {
		// A plain WP user with no membership row.
		$user_id = self::factory()->user->create( [ 'user_email' => 'nobody@example.com' ] );

		$result = $this->departure->initiate_removal_for_user( $user_id );

		$this->assertFalse( $result,
			'initiate_removal_for_user() must return false when there is no active membership.' );
	}

	// =========================================================================
	// record_vote_on_request() — shared core (REST cast_vote() + RemovalVoteConfirm)
	// =========================================================================

	public function test_record_vote_on_request_records_yes_vote(): void {
		[ $subject_id, ] = $this->create_admitted_artist( 'subject1@example.com' );
		[ $voter_id, ]   = $this->create_admitted_artist( 'voter1@example.com' );

		$request_id = $this->create_open_removal_request( $subject_id );

		$result = $this->departure->record_vote_on_request( $request_id, $voter_id, 'yes' );

		$this->assertFalse( is_wp_error( $result ) );
		$data = $result->get_data();
		$this->assertSame( 'recorded', $data['status'] );
		$this->assertSame( 'yes', $data['vote'] );
		$this->assertSame( 1, $data['yes_votes'] );
		$this->assertSame( 0, $data['no_votes'] );
	}

	public function test_record_vote_on_request_records_no_vote(): void {
		[ $subject_id, ] = $this->create_admitted_artist( 'subject2@example.com' );
		[ $voter_id, ]   = $this->create_admitted_artist( 'voter2@example.com' );

		$request_id = $this->create_open_removal_request( $subject_id );

		$result = $this->departure->record_vote_on_request( $request_id, $voter_id, 'no' );

		$data = $result->get_data();
		$this->assertSame( 0, $data['yes_votes'] );
		$this->assertSame( 1, $data['no_votes'] );
	}

	public function test_record_vote_on_request_returns_404_for_unknown_request(): void {
		[ $voter_id, ] = $this->create_admitted_artist( 'voter3@example.com' );

		$result = $this->departure->record_vote_on_request( 999999, $voter_id, 'yes' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_record_vote_on_request_returns_error_when_not_open(): void {
		global $wpdb;

		[ $subject_id, ] = $this->create_admitted_artist( 'subject4@example.com' );
		[ $voter_id, ]   = $this->create_admitted_artist( 'voter4@example.com' );

		$request_id = $this->create_open_removal_request( $subject_id );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'agnosis_removal_requests',
			[ 'status' => 'passed' ],
			[ 'id' => $request_id ],
			[ '%s' ], [ '%d' ]
		);

		$result = $this->departure->record_vote_on_request( $request_id, $voter_id, 'yes' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_record_vote_on_request_rejects_self_vote(): void {
		[ $subject_id, ] = $this->create_admitted_artist( 'subject5@example.com' );

		$request_id = $this->create_open_removal_request( $subject_id );

		$result = $this->departure->record_vote_on_request( $request_id, $subject_id, 'yes' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_cast_vote_rest_delegates_to_record_vote_on_request(): void {
		[ $subject_id, ] = $this->create_admitted_artist( 'subject6@example.com' );
		[ $voter_id, ]   = $this->create_admitted_artist( 'voter6@example.com' );

		$request_id = $this->create_open_removal_request( $subject_id );

		wp_set_current_user( $voter_id );

		$request = new \WP_REST_Request( 'POST', '/agnosis/v1/removal/vote/' . $request_id );
		$request->set_param( 'id', $request_id );
		$request->set_param( 'vote', 'yes' );

		$result = $this->departure->cast_vote( $request );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 1, $result->get_data()['yes_votes'] );
	}
}
