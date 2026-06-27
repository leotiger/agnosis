<?php
/**
 * Tests asserting that Notification builds shim URLs, not direct REST URLs.
 *
 * Fix 1c moved email action links from:
 *   /wp-json/agnosis/v1/review/{id}/approve?token=xxx          (REST, logged)
 * to:
 *   /?agnosis_review=1&id={id}&action=approve&token=xxx        (frontend shim)
 *
 * These tests use Reflection to call the private action_url() helper and parse
 * the HTML returned by build_removal_email() to verify the URL shape.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\Notification;

class NotificationUrlTest extends \WP_UnitTestCase {

	private \ReflectionMethod $action_url;
	private \ReflectionMethod $build_removal_email;
	private Notification $notification;

	protected function setUp(): void {
		parent::setUp();

		$this->notification = new Notification();

		$rc = new \ReflectionClass( Notification::class );

		$this->action_url = $rc->getMethod( 'action_url' );
		$this->action_url->setAccessible( true );

		$this->build_removal_email = $rc->getMethod( 'build_removal_email' );
		$this->build_removal_email->setAccessible( true );
	}

	// -------------------------------------------------------------------------
	// action_url()
	// -------------------------------------------------------------------------

	public function test_action_url_is_not_a_rest_url(): void {
		$url = (string) $this->action_url->invoke(
			$this->notification,
			42,
			'approve',
			'test-token-xyz'
		);

		$this->assertStringNotContainsString( 'wp-json', $url );
		$this->assertStringNotContainsString( 'rest_url', $url );
	}

	public function test_action_url_uses_home_url_base(): void {
		$url = (string) $this->action_url->invoke(
			$this->notification,
			42,
			'approve',
			'test-token-xyz'
		);

		$this->assertStringStartsWith( home_url( '/' ), $url );
	}

	public function test_action_url_contains_agnosis_review_flag(): void {
		$url = (string) $this->action_url->invoke(
			$this->notification,
			42,
			'approve',
			'test-token-xyz'
		);

		$this->assertStringContainsString( 'agnosis_review=1', $url );
	}

	public function test_action_url_contains_post_id(): void {
		$url = (string) $this->action_url->invoke(
			$this->notification,
			99,
			'approve',
			'test-token-xyz'
		);

		$this->assertStringContainsString( 'id=99', $url );
	}

	public function test_action_url_contains_action_param(): void {
		$url = (string) $this->action_url->invoke(
			$this->notification,
			42,
			'reject',
			'test-token-xyz'
		);

		$this->assertStringContainsString( 'action=reject', $url );
	}

	public function test_action_url_contains_token_param(): void {
		$url = (string) $this->action_url->invoke(
			$this->notification,
			42,
			'approve',
			'secret-token-abc'
		);

		$this->assertStringContainsString( 'token=secret-token-abc', $url );
	}

	public function test_action_url_uses_action_arg_verbatim(): void {
		foreach ( [ 'approve', 'reject' ] as $action ) {
			$url = (string) $this->action_url->invoke(
				$this->notification,
				1,
				$action,
				'tok'
			);
			$this->assertStringContainsString( 'action=' . $action, $url, "action=$action not in URL" );
		}
	}

	// -------------------------------------------------------------------------
	// build_removal_email() — confirm_url shape
	// -------------------------------------------------------------------------

	public function test_removal_email_confirm_url_is_not_a_rest_url(): void {
		$artist = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$post   = self::factory()->post->create_and_get( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist->ID,
			'post_title'  => 'Test Artwork',
		] );

		$html = (string) $this->build_removal_email->invoke(
			$this->notification,
			$post,
			$artist->display_name,
			'removal-test-token-123'
		);

		$this->assertStringNotContainsString( 'wp-json', $html );
	}

	public function test_removal_email_confirm_url_uses_shim_params(): void {
		$artist = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$post   = self::factory()->post->create_and_get( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist->ID,
			'post_title'  => 'Test Artwork',
		] );

		$html = (string) $this->build_removal_email->invoke(
			$this->notification,
			$post,
			$artist->display_name,
			'removal-test-token-123'
		);

		$this->assertStringContainsString( 'agnosis_review=1', $html );
		$this->assertStringContainsString( 'action=remove', $html );
		$this->assertStringContainsString( 'token=removal-test-token-123', $html );
	}
}
