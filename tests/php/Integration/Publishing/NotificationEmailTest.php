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
}
