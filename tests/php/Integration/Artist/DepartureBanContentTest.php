<?php
/**
 * Artist\Departure — the ban CONTENT lifecycle added in 0.9.64.
 *
 * Written for the sixteenth audit's Q-1 (2026-07-31). 0.9.64 shipped with no
 * test coverage at all, and this is the riskiest thing in it: banning an artist
 * now walks every published artwork/biography/event they authored — plus every
 * Lingua Forge translated sibling reachable through each one's translation
 * group — and sets each to 'private', marking exactly which posts it touched
 * with `_agnosis_hidden_for_ban` so a temporary ban's expiry can put back only
 * those.
 *
 * The failure mode this file exists to catch is silent and unrecoverable by the
 * artist: if the flag is written wrong on any post — or if restore republishes
 * something it did not hide — an artist's body of work either stays invisible
 * after their ban expires, or a post an admin deliberately made private gets
 * pushed back out in public. Neither raises an error anywhere.
 *
 * `DepartureTest` already covers admin_ban()'s status/role effects; this file
 * deliberately covers only what happens to the CONTENT.
 *
 * @package Agnosis
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\Departure;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;

class DepartureBanContentTest extends \WP_UnitTestCase {

	private const HIDDEN_META = '_agnosis_hidden_for_ban';

	private Departure $departure;

	protected function setUp(): void {
		parent::setUp();
		$this->departure = new Departure();
		FakeLinguaForge::reset();
	}

	protected function tearDown(): void {
		FakeLinguaForge::reset();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * @param int|\WP_Error $created
	 */
	private static function id( $created ): int {
		if ( $created instanceof \WP_Error ) {
			throw new \RuntimeException( 'Test fixture could not be created: ' . $created->get_error_message() );
		}
		return $created;
	}

	/** @return array{0: int, 1: int} [user id, application id] */
	private function create_admitted_artist( string $email = 'banned-artist@example.com' ): array {
		global $wpdb;

		$user_id = self::id( self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] ) );

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof \WP_User ) {
			throw new \RuntimeException( "Expected user #{$user_id} to exist." );
		}
		$user->add_role( 'agnosis_artist' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'        => $email,
				'display_name' => 'Banned Artist',
				'status'       => 'admitted',
				'wp_user_id'   => $user_id,
				'resolved_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%d', '%s' ]
		);

		return [ $user_id, (int) $wpdb->insert_id ];
	}

	private function create_post( int $user_id, string $type = 'agnosis_artwork', string $status = 'publish' ): int {
		return self::id( self::factory()->post->create( [
			'post_type'   => $type,
			'post_author' => $user_id,
			'post_status' => $status,
		] ) );
	}

	private function status_of( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new \RuntimeException( "Expected post #{$post_id} to exist." );
		}
		return $post->post_status;
	}

	private function is_flagged( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::HIDDEN_META, true );
	}

	/** Expire the ban by backdating banned_until, then run the cron sweep. */
	private function expire_ban( int $application_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
		$wpdb->update(
			$wpdb->prefix . 'agnosis_applications',
			[ 'banned_until' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ],
			[ 'id' => $application_id ],
			[ '%s' ],
			[ '%d' ]
		);

		$this->departure->check_expired_bans();
	}

	// -------------------------------------------------------------------------
	// Hide
	// -------------------------------------------------------------------------

	public function test_ban_privatizes_every_published_agnosis_post_type(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist();

		$artwork   = $this->create_post( $user_id, 'agnosis_artwork' );
		$biography = $this->create_post( $user_id, 'agnosis_biography' );
		$event     = $this->create_post( $user_id, 'agnosis_event' );

		$this->departure->admin_ban( $app_id );

		foreach ( [ $artwork, $biography, $event ] as $id ) {
			$this->assertSame( 'private', $this->status_of( $id ) );
			$this->assertTrue( $this->is_flagged( $id ), 'Every post the ban hid must carry the flag.' );
		}
	}

	public function test_ban_does_not_touch_another_artists_posts(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist();
		[ $other_id ]         = $this->create_admitted_artist( 'innocent@example.com' );

		$mine   = $this->create_post( $user_id );
		$theirs = $this->create_post( $other_id );

		$this->departure->admin_ban( $app_id );

		$this->assertSame( 'private', $this->status_of( $mine ) );
		$this->assertSame( 'publish', $this->status_of( $theirs ) );
		$this->assertFalse( $this->is_flagged( $theirs ) );
	}

	/**
	 * The flag is what makes restore safe, so a post that was ALREADY private
	 * before the ban must never acquire it — otherwise the ban's expiry would
	 * publish something no ban ever hid.
	 */
	public function test_ban_leaves_an_already_private_post_unflagged(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist();

		$already_private = $this->create_post( $user_id, 'agnosis_artwork', 'private' );

		$this->departure->admin_ban( $app_id );

		$this->assertSame( 'private', $this->status_of( $already_private ) );
		$this->assertFalse(
			$this->is_flagged( $already_private ),
			'A post the ban did not hide must not be flagged as if it had.'
		);
	}

	public function test_ban_privatizes_lingua_forge_siblings_too(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist();

		$primary = $this->create_post( $user_id );
		$sibling = $this->create_post( $user_id );
		FakeLinguaForge::link( $primary, 'de', $sibling );

		$this->departure->admin_ban( $app_id );

		$this->assertSame( 'private', $this->status_of( $sibling ) );
		$this->assertTrue( $this->is_flagged( $sibling ) );
	}

	/** A sibling already private before the ban is left alone, same as a primary. */
	public function test_ban_leaves_an_already_private_sibling_unflagged(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist();

		$primary = $this->create_post( $user_id );
		$sibling = $this->create_post( $user_id, 'agnosis_artwork', 'private' );
		FakeLinguaForge::link( $primary, 'de', $sibling );

		$this->departure->admin_ban( $app_id );

		$this->assertFalse( $this->is_flagged( $sibling ) );
	}

	// -------------------------------------------------------------------------
	// Restore — hide → expiry → restore, the full round trip
	// -------------------------------------------------------------------------

	public function test_expired_ban_republishes_what_it_hid_including_siblings(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist();

		$primary   = $this->create_post( $user_id );
		$sibling   = $this->create_post( $user_id );
		$biography = $this->create_post( $user_id, 'agnosis_biography' );
		FakeLinguaForge::link( $primary, 'de', $sibling );

		$this->departure->admin_ban( $app_id, new \DateTimeImmutable( '+1 day' ) );

		$this->assertSame( 'private', $this->status_of( $primary ) );
		$this->assertSame( 'private', $this->status_of( $sibling ) );

		$this->expire_ban( $app_id );

		foreach ( [ $primary, $sibling, $biography ] as $id ) {
			$this->assertSame( 'publish', $this->status_of( $id ), 'A temporary ban must fully restore the artist\'s work.' );
			$this->assertFalse( $this->is_flagged( $id ), 'The flag must be cleared once the post is restored.' );
		}
	}

	/**
	 * The single most consequential assertion in this file: a post an admin
	 * deliberately made private for an unrelated reason must survive a ban's
	 * expiry still private. Republishing it would be the plugin overriding a
	 * human moderation decision, silently.
	 */
	public function test_expired_ban_does_not_publish_a_post_it_never_hid(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist();

		$hidden_by_ban   = $this->create_post( $user_id );
		$private_by_admin = $this->create_post( $user_id, 'agnosis_artwork', 'private' );

		$this->departure->admin_ban( $app_id, new \DateTimeImmutable( '+1 day' ) );
		$this->expire_ban( $app_id );

		$this->assertSame( 'publish', $this->status_of( $hidden_by_ban ) );
		$this->assertSame(
			'private',
			$this->status_of( $private_by_admin ),
			'An admin\'s own private post must not be republished by a ban expiring.'
		);
	}

	/** Same guarantee one level down: an unflagged sibling stays private. */
	public function test_expired_ban_does_not_publish_an_unflagged_sibling(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist();

		$primary          = $this->create_post( $user_id );
		$private_sibling  = $this->create_post( $user_id, 'agnosis_artwork', 'private' );
		FakeLinguaForge::link( $primary, 'de', $private_sibling );

		$this->departure->admin_ban( $app_id, new \DateTimeImmutable( '+1 day' ) );
		$this->expire_ban( $app_id );

		$this->assertSame( 'publish', $this->status_of( $primary ) );
		$this->assertSame( 'private', $this->status_of( $private_sibling ) );
	}

	/**
	 * A permanent ban (no `banned_until`) must never be picked up by the
	 * expiry sweep — its content stays hidden until an admin acts.
	 */
	public function test_permanent_ban_is_not_restored_by_the_expiry_sweep(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist();

		$artwork = $this->create_post( $user_id );

		$this->departure->admin_ban( $app_id ); // no expiry
		$this->departure->check_expired_bans();

		$this->assertSame( 'private', $this->status_of( $artwork ) );
		$this->assertTrue( $this->is_flagged( $artwork ) );
	}

	/** A ban that has not expired yet must likewise be left alone. */
	public function test_unexpired_ban_is_not_restored_by_the_expiry_sweep(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist();

		$artwork = $this->create_post( $user_id );

		$this->departure->admin_ban( $app_id, new \DateTimeImmutable( '+1 day' ) );
		$this->departure->check_expired_bans();

		$this->assertSame( 'private', $this->status_of( $artwork ) );
	}

	/**
	 * The sibling cascade is guarded by `function_exists( 'linguaforge_get_translations' )`.
	 * With no translation group registered, a ban must still work on the post
	 * itself rather than erroring out — the no-Lingua-Forge path.
	 */
	public function test_ban_works_when_the_post_has_no_translation_group(): void {
		[ $user_id, $app_id ] = $this->create_admitted_artist();

		$artwork = $this->create_post( $user_id );

		$this->departure->admin_ban( $app_id );

		$this->assertSame( 'private', $this->status_of( $artwork ) );
		$this->assertTrue( $this->is_flagged( $artwork ) );
	}
}
