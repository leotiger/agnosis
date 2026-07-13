<?php
/**
 * Integration tests — Notification email dispatch and content.
 *
 * Tests cover:
 *
 *   on_submission_rejected():
 *     - Sends email when artist exists and has a valid email address
 *     - Skips sending when artist_id does not match any user
 *
 *   issues_to_advice() (via Reflection — private method):
 *     - Dark/lighting keywords → lighting advice sentence
 *     - Blur/focus/motion/shake → sharpness advice sentence
 *     - Resolution/low res/pixelated → resolution advice sentence
 *     - Colour cast, yellow, blue → white balance advice sentence
 *     - Cropped, angle, distort, shadow → composition advice sentence
 *     - Glare, reflection → surface reflection advice sentence
 *     - Unknown issue → verbatim fallback (not silently dropped)
 *     - Duplicate keyword matches → only one advice sentence added
 *
 *   build_rejection_email() (via Reflection — private method):
 *     - HTML contains quality score
 *     - HTML contains artist name
 *     - HTML contains site name
 *     - HTML contains the issue panel when issues present
 *     - HTML omits per-issue advice when issues empty
 *
 *   on_post_drafted():
 *     - Sends email when review token meta is present
 *     - Skips when no review token meta
 *     - Skips when post_id doesn't exist
 *
 *   on_removal_requested():
 *     - Sends email when removal token meta is present
 *     - Skips when no removal token meta
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\Notification;
use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;

require_once __DIR__ . '/../AI/Stubs/WpAiClientTestRegistry.php';
require_once __DIR__ . '/../AI/Stubs/wp_ai_provider_namespace_stubs.php';

class NotificationEmailTest extends \WP_UnitTestCase {

	private Notification $notification;
	private \ReflectionMethod $issues_to_advice;
	private \ReflectionMethod $build_rejection_email;

	protected function setUp(): void {
		parent::setUp();

		$this->notification = new Notification();

		$rc = new \ReflectionClass( Notification::class );

		$this->issues_to_advice = $rc->getMethod( 'issues_to_advice' );
		$this->issues_to_advice->setAccessible( true );

		$this->build_rejection_email = $rc->getMethod( 'build_rejection_email' );
		$this->build_rejection_email->setAccessible( true );
	}

	protected function tearDown(): void {
		WpAiClientTestRegistry::reset();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Intercept wp_mail via pre_wp_mail filter.
	 *
	 * pre_wp_mail passes two arguments: ($return, $atts).  $atts is the array
	 * with keys: to, subject, message, headers, attachments.  We must accept
	 * both args (accepted_args = 2) and capture $atts, not $return.
	 * Returning a non-null value short-circuits wp_mail().
	 *
	 * @param array|null $captured Reference to an array that will receive the mail args.
	 * @return callable  The filter closure (caller must remove it after the test).
	 */
	private function capture_mail( ?array &$captured ): callable {
		$filter = function ( $pre, array $atts ) use ( &$captured ) {
			$captured = $atts;
			return true; // Prevent actual sending.
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		return $filter;
	}

	/** Create a user with a known email address. */
	private function create_artist( string $email = 'artist@example.com' ): \WP_User {
		$id   = self::factory()->user->create( [
			'role'         => 'subscriber',
			'user_email'   => $email,
			'display_name' => 'Test Artist',
		] );
		$user = get_user_by( 'id', $id );
		$user->add_role( 'agnosis_artist' );
		return $user;
	}

	/** Create a minimal artwork post with a review token stored in meta. */
	private function create_post_with_review_token( int $artist_id ): array {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'draft',
			'post_author' => $artist_id,
			'post_title'  => 'Artwork Under Review',
		] );
		$token = bin2hex( random_bytes( 16 ) );
		update_post_meta( $post_id, '_agnosis_review_token', $token );
		return [ 'id' => $post_id, 'token' => $token ];
	}

	/** Create a minimal artwork post with a removal token stored in meta. */
	private function create_post_with_removal_token( int $artist_id ): array {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist_id,
			'post_title'  => 'Artwork To Remove',
		] );
		$token = bin2hex( random_bytes( 16 ) );
		update_post_meta( $post_id, '_agnosis_removal_token', $token );
		return [ 'id' => $post_id, 'token' => $token ];
	}

	/**
	 * Create a minimal event post with a removal token stored in meta.
	 * 2026-07-06: remove@ (and its confirmation email) is no longer
	 * artwork-only — this exercises the same email path for an event.
	 */
	private function create_event_with_removal_token( int $artist_id ): array {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_event',
			'post_status' => 'publish',
			'post_author' => $artist_id,
			'post_title'  => 'Event To Remove',
		] );
		$token = bin2hex( random_bytes( 16 ) );
		update_post_meta( $post_id, '_agnosis_removal_token', $token );
		return [ 'id' => $post_id, 'token' => $token ];
	}

	// =========================================================================
	// on_submission_rejected()
	// =========================================================================

	public function test_on_submission_rejected_sends_email_to_artist(): void {
		$artist  = $this->create_artist( 'painter@example.com' );
		$captured = null;
		$filter  = $this->capture_mail( $captured );

		$this->notification->on_submission_rejected( 1, $artist->ID, 3, [ 'too dark' ] );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured, 'wp_mail() should have been called.' );
		$this->assertSame( 'painter@example.com', $captured['to'] );
	}

	public function test_on_submission_rejected_subject_contains_site_name(): void {
		$artist  = $this->create_artist();
		$captured = null;
		$filter  = $this->capture_mail( $captured );

		$this->notification->on_submission_rejected( 1, $artist->ID, 2, [] );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$site_name = get_bloginfo( 'name' );
		if ( $site_name ) {
			$this->assertStringContainsString( $site_name, $captured['subject'] );
		} else {
			$this->assertNotEmpty( $captured['subject'] );
		}
	}

	public function test_on_submission_rejected_skips_for_invalid_artist_id(): void {
		$called = false;
		$filter = function () use ( &$called ) {
			$called = true;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 1 );

		$this->notification->on_submission_rejected( 999, 999999, 2, [] );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertFalse( $called, 'wp_mail() must not fire for a non-existent artist.' );
	}

	// =========================================================================
	// on_submission_looks_like_reply() (2026-07-15)
	// =========================================================================

	public function test_on_submission_looks_like_reply_sends_email_to_artist(): void {
		$artist   = $this->create_artist( 'replier@example.com' );
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->notification->on_submission_looks_like_reply( $artist->ID, 'uid-1' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured, 'wp_mail() should have been called.' );
		$this->assertSame( 'replier@example.com', $captured['to'] );
	}

	public function test_on_submission_looks_like_reply_subject_contains_site_name(): void {
		$artist   = $this->create_artist();
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->notification->on_submission_looks_like_reply( $artist->ID, 'uid-2' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$site_name = get_bloginfo( 'name' );
		if ( $site_name ) {
			$this->assertStringContainsString( $site_name, $captured['subject'] );
		} else {
			$this->assertNotEmpty( $captured['subject'] );
		}
	}

	public function test_on_submission_looks_like_reply_body_advises_sending_a_fresh_original_message(): void {
		$artist   = $this->create_artist( 'replier2@example.com' );
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->notification->on_submission_looks_like_reply( $artist->ID, 'uid-3' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( 'reply or a forwarded message', $captured['message'] );
		$this->assertStringContainsString( 'Start a brand new message', $captured['message'] );
	}

	public function test_on_submission_looks_like_reply_skips_for_invalid_artist_id(): void {
		$called = false;
		$filter = function () use ( &$called ) {
			$called = true;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 1 );

		$this->notification->on_submission_looks_like_reply( 999999, 'uid-4' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertFalse( $called, 'wp_mail() must not fire for a non-existent artist.' );
	}

	// =========================================================================
	// issues_to_advice() — keyword mapping
	// =========================================================================

	/** @dataProvider lighting_keywords_provider */
	public function test_issues_to_advice_maps_lighting_keywords( string $issue ): void {
		$advice = $this->issues_to_advice->invoke( $this->notification, [ $issue ] );

		$this->assertNotEmpty( $advice, "No advice returned for issue: $issue" );
		// Each lighting issue should mention light/window/exposure in some form.
		$combined = implode( ' ', $advice );
		$this->assertMatchesRegularExpression(
			'/light|window|expos|bright|glare|reflect|flash/i',
			$combined,
			"Advice for '$issue' should mention lighting."
		);
	}

	public static function lighting_keywords_provider(): array {
		return [
			'too dark'          => [ 'too dark' ],
			'underexposed'      => [ 'underexposed' ],
			'overexposed'       => [ 'overexposed image' ],
			'bright and washed' => [ 'very bright' ],
			'glare on surface'  => [ 'glare visible' ],
			'reflection issue'  => [ 'reflection on canvas' ],
		];
	}

	/** @dataProvider sharpness_keywords_provider */
	public function test_issues_to_advice_maps_sharpness_keywords( string $issue ): void {
		$advice   = $this->issues_to_advice->invoke( $this->notification, [ $issue ] );
		$combined = implode( ' ', $advice );

		$this->assertNotEmpty( $advice );
		$this->assertMatchesRegularExpression(
			'/blur|focus|sharp|still|stable|shake|tripod|timer/i',
			$combined,
			"Advice for '$issue' should mention focus/stabilisation."
		);
	}

	public static function sharpness_keywords_provider(): array {
		return [
			'motion blur'  => [ 'motion blur' ],
			'blurry image' => [ 'blurry image' ],
			'out of focus' => [ 'out of focus' ],
			'camera shake' => [ 'camera shake' ],
		];
	}

	/** @dataProvider resolution_keywords_provider */
	public function test_issues_to_advice_maps_resolution_keywords( string $issue ): void {
		$advice   = $this->issues_to_advice->invoke( $this->notification, [ $issue ] );
		$combined = implode( ' ', $advice );

		$this->assertNotEmpty( $advice );
		$this->assertMatchesRegularExpression(
			'/resolution|quality|closer|higher|pixel/i',
			$combined,
			"Advice for '$issue' should mention resolution."
		);
	}

	public static function resolution_keywords_provider(): array {
		return [
			'low resolution' => [ 'low resolution' ],
			'low res image'  => [ 'low res image' ],
			'pixelated'      => [ 'pixelated image' ],
		];
	}

	/** @dataProvider colour_keywords_provider */
	public function test_issues_to_advice_maps_colour_keywords( string $issue ): void {
		$advice   = $this->issues_to_advice->invoke( $this->notification, [ $issue ] );
		$combined = implode( ' ', $advice );

		$this->assertNotEmpty( $advice );
		$this->assertMatchesRegularExpression(
			'/colour|color|white balance|daylight|tint|warm|cool/i',
			$combined,
			"Advice for '$issue' should mention colour/white balance."
		);
	}

	public static function colour_keywords_provider(): array {
		return [
			'colour cast'      => [ 'colour cast' ],
			'color cast'       => [ 'color cast' ],
			'yellow tint'      => [ 'yellow tint' ],
			'blue tinge'       => [ 'blue tinge' ],
		];
	}

	/** @dataProvider composition_keywords_provider */
	public function test_issues_to_advice_maps_composition_keywords( string $issue ): void {
		$advice   = $this->issues_to_advice->invoke( $this->notification, [ $issue ] );
		$combined = implode( ' ', $advice );

		$this->assertNotEmpty( $advice );
		$this->assertMatchesRegularExpression(
			'/crop|angle|distort|shadow|frame|parallel|straight|step back/i',
			$combined,
			"Advice for '$issue' should mention composition."
		);
	}

	public static function composition_keywords_provider(): array {
		return [
			'artwork is cropped'  => [ 'artwork is cropped' ],
			'photographed at angle' => [ 'photographed at angle' ],
			'distortion visible'  => [ 'distortion visible' ],
			'shadow on canvas'    => [ 'shadow on canvas' ],
		];
	}

	public function test_issues_to_advice_unknown_issue_included_verbatim(): void {
		$advice = $this->issues_to_advice->invoke( $this->notification, [ 'totally unrecognised problem xyz' ] );

		$this->assertNotEmpty( $advice );
		// Unknown issues are capitalised and appended with a period.
		$combined = implode( ' ', $advice );
		$this->assertStringContainsString( 'unrecognised problem xyz', $combined );
	}

	public function test_issues_to_advice_deduplicates_matching_sentences(): void {
		// Both 'dark' and 'too dark' match the same advice sentence.
		$advice = $this->issues_to_advice->invoke( $this->notification, [ 'dark', 'too dark' ] );

		// The same sentence should appear only once.
		$this->assertCount( 1, $advice );
	}

	public function test_issues_to_advice_empty_input_returns_empty_array(): void {
		$advice = $this->issues_to_advice->invoke( $this->notification, [] );

		$this->assertSame( [], $advice );
	}

	// =========================================================================
	// build_rejection_email() — HTML content
	// =========================================================================

	public function test_build_rejection_email_contains_score(): void {
		$html = (string) $this->build_rejection_email->invoke(
			$this->notification,
			'Maria',
			5,
			[]
		);

		$this->assertStringContainsString( '5', $html );
	}

	public function test_build_rejection_email_contains_artist_name(): void {
		$html = (string) $this->build_rejection_email->invoke(
			$this->notification,
			'Guillermo',
			3,
			[]
		);

		$this->assertStringContainsString( 'Guillermo', $html );
	}

	public function test_build_rejection_email_issue_panel_present_when_issues_non_empty(): void {
		$html = (string) $this->build_rejection_email->invoke(
			$this->notification,
			'Lena',
			2,
			[ 'motion blur' ]
		);

		// The email should include per-issue advice because we have known issues.
		$this->assertStringContainsString( 'blur', $html );
	}

	public function test_build_rejection_email_shows_fallback_when_no_issues(): void {
		$html = (string) $this->build_rejection_email->invoke(
			$this->notification,
			'Sam',
			1,
			[]
		);

		// When no specific issues, a generic fallback message appears.
		$this->assertStringContainsString( 'too low', $html );
	}

	public function test_build_rejection_email_is_valid_html(): void {
		$html = (string) $this->build_rejection_email->invoke(
			$this->notification,
			'Artist',
			3,
			[ 'too dark', 'blurry' ]
		);

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( '</html>', $html );
	}

	// =========================================================================
	// on_post_drafted()
	// =========================================================================

	public function test_on_post_drafted_sends_email_when_token_present(): void {
		$artist  = $this->create_artist( 'drafter@example.com' );
		$data    = $this->create_post_with_review_token( $artist->ID );
		$captured = null;
		$filter  = $this->capture_mail( $captured );

		$this->notification->on_post_drafted( $data['id'], $artist->ID );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured, 'wp_mail() should fire when review token is present.' );
		$this->assertSame( 'drafter@example.com', $captured['to'] );
	}

	public function test_on_post_drafted_skips_when_no_review_token(): void {
		$artist  = $this->create_artist();
		$post_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'draft',
			'post_author' => $artist->ID,
		] );
		// No _agnosis_review_token meta.

		$called = false;
		$filter = function () use ( &$called ) {
			$called = true;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 1 );

		$this->notification->on_post_drafted( $post_id, $artist->ID );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertFalse( $called, 'wp_mail() must not fire without a review token.' );
	}

	public function test_on_post_drafted_skips_for_invalid_post_id(): void {
		$artist = $this->create_artist();
		$called  = false;
		$filter  = function () use ( &$called ) {
			$called = true;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 1 );

		$this->notification->on_post_drafted( 999999, $artist->ID );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertFalse( $called );
	}

	// =========================================================================
	// on_removal_requested()
	// =========================================================================

	public function test_on_removal_requested_sends_email_when_token_present(): void {
		$artist  = $this->create_artist( 'removal@example.com' );
		$data    = $this->create_post_with_removal_token( $artist->ID );
		$captured = null;
		$filter  = $this->capture_mail( $captured );

		$this->notification->on_removal_requested( $data['id'], $artist->ID );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertSame( 'removal@example.com', $captured['to'] );
	}

	public function test_on_removal_requested_email_says_artwork_for_artwork_post(): void {
		$artist  = $this->create_artist( 'removal@example.com' );
		$data    = $this->create_post_with_removal_token( $artist->ID );
		$captured = null;
		$filter  = $this->capture_mail( $captured );

		$this->notification->on_removal_requested( $data['id'], $artist->ID );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( 'artwork', $captured['message'] );
		$this->assertStringNotContainsString( 'remove this event', $captured['message'] );
	}

	public function test_on_removal_requested_email_says_event_for_event_post(): void {
		$artist  = $this->create_artist( 'removal-event@example.com' );
		$data    = $this->create_event_with_removal_token( $artist->ID );
		$captured = null;
		$filter  = $this->capture_mail( $captured );

		$this->notification->on_removal_requested( $data['id'], $artist->ID );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'remove this event', $captured['message'] );
		$this->assertStringNotContainsString( 'remove this artwork', $captured['message'] );
	}

	public function test_on_removal_requested_skips_when_no_removal_token(): void {
		$artist  = $this->create_artist();
		$post_id = self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist->ID,
		] );
		// No _agnosis_removal_token meta.

		$called = false;
		$filter = function () use ( &$called ) {
			$called = true;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 1 );

		$this->notification->on_removal_requested( $post_id, $artist->ID );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertFalse( $called );
	}

	// =========================================================================
	// on_removal_requested_multiple() — 2026-07-14, an exact remove@ subject
	// matching more than one post (e.g. an artwork and an event sharing a
	// title) gets one email listing every match with its own confirm link,
	// instead of the single-post email above.
	// =========================================================================

	public function test_on_removal_requested_multiple_sends_email_listing_every_match(): void {
		$artist      = $this->create_artist( 'choose@example.com' );
		$artwork     = $this->create_post_with_removal_token( $artist->ID );
		$event       = $this->create_event_with_removal_token( $artist->ID );
		$captured    = null;
		$filter      = $this->capture_mail( $captured );

		$this->notification->on_removal_requested_multiple(
			[
				[ 'id' => $artwork['id'], 'type' => 'agnosis_artwork', 'token' => $artwork['token'] ],
				[ 'id' => $event['id'],   'type' => 'agnosis_event',   'token' => $event['token'] ],
			],
			$artist->ID,
			'Golden Hour'
		);

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertSame( 'choose@example.com', $captured['to'] );
		$this->assertStringContainsString( 'Golden Hour', $captured['subject'] );

		// Both matches' own titles and their own confirm link appear.
		$this->assertStringContainsString( 'Artwork To Remove', $captured['message'] );
		$this->assertStringContainsString( 'Event To Remove', $captured['message'] );
		$this->assertStringContainsString( $artwork['token'], $captured['message'] );
		$this->assertStringContainsString( $event['token'], $captured['message'] );
		$this->assertStringContainsString( (string) $artwork['id'], $captured['message'] );
		$this->assertStringContainsString( (string) $event['id'], $captured['message'] );
	}

	public function test_on_removal_requested_multiple_skips_when_no_matches_resolve(): void {
		$artist = $this->create_artist();

		$called = false;
		$filter = function () use ( &$called ) {
			$called = true;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 1 );

		// Neither ID exists — every entry fails to resolve to a live WP_Post.
		$this->notification->on_removal_requested_multiple(
			[
				[ 'id' => 999999, 'type' => 'agnosis_artwork', 'token' => 'x' ],
				[ 'id' => 999998, 'type' => 'agnosis_event',   'token' => 'y' ],
			],
			$artist->ID,
			'Ghost Title'
		);

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertFalse( $called );
	}

	// =========================================================================
	// EmailFooter::edit_reminder_html() gating (2026-07-06)
	// =========================================================================

	/**
	 * The post being removed is itself published, so has_published_work()
	 * is already true — the reminder should be present.
	 */
	public function test_on_removal_requested_includes_edit_reminder(): void {
		$artist  = $this->create_artist( 'removal-reminder@example.com' );
		$data    = $this->create_post_with_removal_token( $artist->ID );
		$captured = null;
		$filter  = $this->capture_mail( $captured );

		$this->notification->on_removal_requested( $data['id'], $artist->ID );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( 'pencil icon', $captured['message'] );
	}

	/**
	 * on_post_drafted() fires on a fresh, still-unpublished draft — an artist
	 * with no other published work yet should not see the reminder.
	 */
	public function test_on_post_drafted_omits_edit_reminder_for_first_time_artist(): void {
		$artist  = $this->create_artist( 'first-timer@example.com' );
		$data    = $this->create_post_with_review_token( $artist->ID );
		$captured = null;
		$filter  = $this->capture_mail( $captured );

		$this->notification->on_post_drafted( $data['id'], $artist->ID );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringNotContainsString( 'pencil icon', $captured['message'] );
	}

	/**
	 * Same review email, but this artist already has a separate published
	 * artwork from an earlier submission — the reminder should now appear.
	 */
	public function test_on_post_drafted_includes_edit_reminder_for_returning_artist(): void {
		$artist = $this->create_artist( 'returning@example.com' );
		self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist->ID,
		] );
		$data     = $this->create_post_with_review_token( $artist->ID );
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->notification->on_post_drafted( $data['id'], $artist->ID );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( 'pencil icon', $captured['message'] );
	}

	// =========================================================================
	// on_removal_target_not_found() — fifth audit §2b/§2c "we couldn't find
	// that" feedback email. Never previously exercised by any test in this
	// file (or anywhere else — confirmed via grep for the action name).
	// =========================================================================

	public function test_on_removal_target_not_found_sends_email_listing_current_titles(): void {
		$artist   = $this->create_artist( 'notfound@example.com' );
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->notification->on_removal_target_not_found( $artist->ID, 'A typo\'d title', [ 'Golden Hour', 'Blue Study' ], 0, '', '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertSame( 'notfound@example.com', $captured['to'] );
		$this->assertStringContainsString( 'Golden Hour', $captured['message'] );
		$this->assertStringContainsString( 'Blue Study', $captured['message'] );
		$this->assertStringContainsString( 'A typo&#039;d title', $captured['message'] );
	}

	public function test_on_removal_target_not_found_includes_confirm_link_when_suggestion_present(): void {
		$artist   = $this->create_artist( 'suggestion@example.com' );
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->notification->on_removal_target_not_found( $artist->ID, 'Golden Hor (typo)', [ 'Golden Hour' ], 42, 'Golden Hour', 'a-real-token' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( 'agnosis_review', $captured['message'] );
		$this->assertStringContainsString( 'action=remove', $captured['message'] );
		$this->assertStringContainsString( 'token=a-real-token', $captured['message'] );
		$this->assertStringContainsString( 'id=42', $captured['message'] );
	}

	public function test_on_removal_target_not_found_omits_confirm_link_when_no_suggestion(): void {
		$artist   = $this->create_artist( 'nosuggestion@example.com' );
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->notification->on_removal_target_not_found( $artist->ID, 'Nothing close', [ 'Golden Hour' ], 0, '', '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringNotContainsString( 'agnosis_review', $captured['message'] );
	}

	public function test_on_removal_target_not_found_skips_for_invalid_artist_id(): void {
		$called = false;
		$filter = function () use ( &$called ) {
			$called = true;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 1 );

		$this->notification->on_removal_target_not_found( 999999, 'Some title', [], 0, '', '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertFalse( $called );
	}

	// =========================================================================
	// on_promotion_result() — fifth audit §2b/§2c. Same email builder as
	// removal, minus a confirm link. Never previously exercised.
	// =========================================================================

	public function test_on_promotion_result_success_sends_featured_confirmation(): void {
		$artist   = $this->create_artist( 'promoted@example.com' );
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->notification->on_promotion_result( $artist->ID, 'Golden Hour', true, [], '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertNotNull( $captured );
		$this->assertSame( 'promoted@example.com', $captured['to'] );
		$this->assertStringContainsString( 'featured', strtolower( $captured['subject'] ) );
	}

	public function test_on_promotion_result_failure_sends_not_found_email_listing_titles(): void {
		$artist   = $this->create_artist( 'promotefail@example.com' );
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->notification->on_promotion_result( $artist->ID, 'Nonexistent Title', false, [ 'Golden Hour', 'Blue Study' ], '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringContainsString( 'couldn&#039;t find', strtolower( $captured['message'] ) );
		$this->assertStringContainsString( 'Golden Hour', $captured['message'] );
		$this->assertStringContainsString( 'Blue Study', $captured['message'] );
	}

	public function test_on_promotion_result_failure_email_never_includes_a_confirm_link(): void {
		// Unlike removal, promote@ has no confirm step to attach a link to —
		// even with a fuzzy suggestion, no agnosis_review link must appear.
		$artist   = $this->create_artist( 'promotefail2@example.com' );
		$captured = null;
		$filter   = $this->capture_mail( $captured );

		$this->notification->on_promotion_result( $artist->ID, 'Golden Hor (typo)', false, [ 'Golden Hour' ], 'Golden Hour' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertStringNotContainsString( 'agnosis_review', $captured['message'] );
		$this->assertStringContainsString( 'Golden Hour', $captured['message'] );
	}

	public function test_on_promotion_result_skips_for_invalid_artist_id(): void {
		$called = false;
		$filter = function () use ( &$called ) {
			$called = true;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 1 );

		$this->notification->on_promotion_result( 999999, 'Some title', true, [], '' );

		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertFalse( $called );
	}

	// =========================================================================
	// on_post_drafted() — native-language pipeline (agnosis-audit/
	// NATIVE-LANGUAGE-PIPELINE.md, Phase 5, 2026-07-13): on_post_drafted() no
	// longer calls the AI translator at all, for any artist locale — content
	// is already in the artist's own language at rest by the time this runs
	// (Phase 1), so there is nothing left to back-translate for the review
	// email preview. This replaces the four tests that used to cover the
	// fifth-audit-§4b translate-and-cache block (batching, cache warm, cache
	// skip-on-empty-response, same-locale skip) — that block, and the
	// `ReviewConfirm::BACKTRANSLATION_META` cache it wrote into, are both
	// deleted, so those tests no longer have anything to exercise. Replaced
	// with two tests asserting the new invariant directly: never translates,
	// never writes the (now-deleted) cache meta key, regardless of locale.
	// =========================================================================

	private function create_post_for_translation( int $artist_id, string $site_title, string $excerpt, string $body ): int {
		$post_id = self::factory()->post->create( [
			'post_type'    => 'agnosis_artwork',
			'post_status'  => 'draft',
			'post_author'  => $artist_id,
			'post_title'   => 'Amanecer',
			'post_excerpt' => $excerpt,
			'post_content' => $body,
		] );
		update_post_meta( $post_id, '_agnosis_review_token', bin2hex( random_bytes( 16 ) ) );
		update_post_meta( $post_id, '_agnosis_translated_title', $site_title );
		return $post_id;
	}

	public function test_on_post_drafted_never_translates_regardless_of_artist_locale(): void {
		$artist = $this->create_artist( 'translated@example.com' );
		update_user_meta( $artist->ID, 'locale', 'es_ES' );
		add_filter( 'agnosis_translation_languages', fn( array $langs ) => array_replace( $langs, [ 'es' => 'Spanish' ] ) );

		$post_id = $this->create_post_for_translation( $artist->ID, 'Sunrise', 'A vivid excerpt.', '<p>Full body content.</p>' );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [
			'title'   => 'Amanecer',
			'excerpt' => 'Un extracto vívido.',
			'body'    => 'Contenido completo.',
		] );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$this->notification->on_post_drafted( $post_id, $artist->ID );
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertSame(
			[],
			WpAiClientTestRegistry::$prompts,
			'on_post_drafted() must never call the AI translator any more — content is already native at rest (Phase 1), even for an artist whose locale differs from the site\'s.'
		);

		delete_option( 'agnosis_ai_provider' );
	}

	public function test_on_post_drafted_writes_no_backtranslation_cache(): void {
		$artist = $this->create_artist( 'nocachewarm@example.com' );
		update_user_meta( $artist->ID, 'locale', 'es_ES' );
		add_filter( 'agnosis_translation_languages', fn( array $langs ) => array_replace( $langs, [ 'es' => 'Spanish' ] ) );

		$post_id = $this->create_post_for_translation( $artist->ID, 'Sunrise', 'An excerpt.', '<p>Body.</p>' );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'title' => 'Amanecer' ] );

		$captured = null;
		$filter   = $this->capture_mail( $captured );
		$this->notification->on_post_drafted( $post_id, $artist->ID );
		remove_filter( 'pre_wp_mail', $filter, 10 );

		// '_agnosis_review_backtranslation' — the literal meta key
		// ReviewConfirm::BACKTRANSLATION_META used to name before it was
		// deleted (Phase 5) — must never be written by on_post_drafted() any more.
		$this->assertSame( '', (string) get_post_meta( $post_id, '_agnosis_review_backtranslation', true ) );

		delete_option( 'agnosis_ai_provider' );
	}
}
