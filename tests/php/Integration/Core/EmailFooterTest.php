<?php
/**
 * Integration tests — EmailFooter::edit_reminder_plain_text() / edit_reminder_html().
 *
 * These wrap a private has_published_work() gate backed by WordPress's own
 * count_user_posts(), so they need a real DB (Integration, not Unit).
 *
 * Tests cover:
 *   - '' for an artist with zero posts of any kind
 *   - '' for an artist whose only post is a draft (not yet published)
 *   - non-empty once the artist has a published agnosis_artwork
 *   - non-empty for a published agnosis_biography or agnosis_event too
 *     (not artwork-only)
 *   - '' for an invalid / non-existent user ID
 *   - edit_reminder_html() agrees with edit_reminder_plain_text() (both gated
 *     the same way, html() just escapes)
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\EmailFooter;

class EmailFooterTest extends \WP_UnitTestCase {

	private function create_artist(): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_user_by( 'id', $id );
		$user->add_role( 'agnosis_artist' );
		return $id;
	}

	public function test_edit_reminder_empty_for_artist_with_no_posts(): void {
		$artist_id = $this->create_artist();

		$this->assertSame( '', EmailFooter::edit_reminder_plain_text( $artist_id ) );
		$this->assertSame( '', EmailFooter::edit_reminder_html( $artist_id ) );
	}

	public function test_edit_reminder_empty_when_only_post_is_a_draft(): void {
		$artist_id = $this->create_artist();
		self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'draft',
			'post_author' => $artist_id,
		] );

		$this->assertSame( '', EmailFooter::edit_reminder_plain_text( $artist_id ) );
	}

	public function test_edit_reminder_non_empty_once_artwork_is_published(): void {
		$artist_id = $this->create_artist();
		self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist_id,
		] );

		$this->assertNotSame( '', EmailFooter::edit_reminder_plain_text( $artist_id ) );
	}

	public function test_edit_reminder_non_empty_for_published_biography(): void {
		$artist_id = $this->create_artist();
		self::factory()->post->create( [
			'post_type'   => 'agnosis_biography',
			'post_status' => 'publish',
			'post_author' => $artist_id,
		] );

		$this->assertNotSame( '', EmailFooter::edit_reminder_plain_text( $artist_id ) );
	}

	public function test_edit_reminder_non_empty_for_published_event(): void {
		$artist_id = $this->create_artist();
		self::factory()->post->create( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $artist_id,
		] );

		$this->assertNotSame( '', EmailFooter::edit_reminder_plain_text( $artist_id ) );
	}

	public function test_edit_reminder_empty_for_nonexistent_user(): void {
		$this->assertSame( '', EmailFooter::edit_reminder_plain_text( 999999 ) );
	}

	public function test_edit_reminder_empty_for_zero_or_negative_id(): void {
		$this->assertSame( '', EmailFooter::edit_reminder_plain_text( 0 ) );
		$this->assertSame( '', EmailFooter::edit_reminder_plain_text( -1 ) );
	}

	public function test_edit_reminder_html_matches_gating_of_plain_text(): void {
		$artist_id = $this->create_artist();

		// Not published yet — both empty.
		$this->assertSame( '', EmailFooter::edit_reminder_plain_text( $artist_id ) );
		$this->assertSame( '', EmailFooter::edit_reminder_html( $artist_id ) );

		self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist_id,
		] );

		// Now published — both non-empty.
		$this->assertNotSame( '', EmailFooter::edit_reminder_plain_text( $artist_id ) );
		$this->assertNotSame( '', EmailFooter::edit_reminder_html( $artist_id ) );
	}
}
