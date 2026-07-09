<?php
/**
 * Unit tests — fourth audit §3c: the `Auto-Submitted` mail-loop guard added to
 * both intake paths' community-broadcast handling.
 *
 * `Inbox::is_auto_submitted()` and `Webhook::is_auto_submitted()` are pure
 * logic (no WordPress function calls at all — no get_option(), no Logger),
 * so they're tested here via reflection in the lightweight Unit suite rather
 * than the full Integration/WP_UnitTestCase bootstrap. Neither class needs a
 * real `Webklex\PHPIMAP\Message` for this: Inbox's version is deliberately
 * typed `object` (not `Message`) so a plain duck-typed double is enough, and
 * Webhook's version takes a plain array.
 *
 * Per RFC 3834, a present `Auto-Submitted` value other than `no` marks a
 * message as an automated response — this is what both guards check for.
 *
 * @package Agnosis\Tests\Unit\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Email;

use Agnosis\Email\Inbox;
use Agnosis\Email\Webhook;
use PHPUnit\Framework\TestCase;

class AutoSubmittedGuardTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Minimal duck-typed double for Inbox::is_auto_submitted()'s `object $message`.
	 *
	 * getHeader() mimics Webklex\PHPIMAP\Message's real (camelCase) method name
	 * verbatim, same as FakeAliasImapMessage/FakeImapMessage — see those for the
	 * established phpcs:disable precedent for this exact situation.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private function fake_message( string $auto_submitted_value ): object {
		return new class( $auto_submitted_value ) {
			public function __construct( private string $value ) {}
			public function getHeader(): object {
				$value = $this->value;
				return new class( $value ) {
					public function __construct( private string $value ) {}
					public function get( string $name ): string {
						return 'auto-submitted' === $name ? $this->value : '';
					}
				};
			}
		};
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	private function invoke_inbox_is_auto_submitted( object $message ): bool {
		$inbox = new Inbox();
		$ref   = new \ReflectionMethod( Inbox::class, 'is_auto_submitted' );
		$ref->setAccessible( true );
		return $ref->invoke( $inbox, $message );
	}

	/** @param array<string, mixed> $payload */
	private function invoke_webhook_is_auto_submitted( array $payload ): bool {
		$webhook = new Webhook();
		$ref     = new \ReflectionMethod( Webhook::class, 'is_auto_submitted' );
		$ref->setAccessible( true );
		return $ref->invoke( $webhook, $payload );
	}

	// -------------------------------------------------------------------------
	// Inbox::is_auto_submitted() — IMAP path
	// -------------------------------------------------------------------------

	public function test_inbox_no_header_is_not_auto_submitted(): void {
		$this->assertFalse( $this->invoke_inbox_is_auto_submitted( $this->fake_message( '' ) ) );
	}

	public function test_inbox_explicit_no_is_not_auto_submitted(): void {
		// RFC 3834 allows genuine human mail to explicitly set Auto-Submitted: no.
		$this->assertFalse( $this->invoke_inbox_is_auto_submitted( $this->fake_message( 'no' ) ) );
	}

	public function test_inbox_auto_replied_is_auto_submitted(): void {
		$this->assertTrue( $this->invoke_inbox_is_auto_submitted( $this->fake_message( 'auto-replied' ) ) );
	}

	public function test_inbox_auto_generated_is_auto_submitted(): void {
		$this->assertTrue( $this->invoke_inbox_is_auto_submitted( $this->fake_message( 'auto-generated' ) ) );
	}

	public function test_inbox_value_is_case_and_whitespace_insensitive(): void {
		$this->assertFalse( $this->invoke_inbox_is_auto_submitted( $this->fake_message( '  NO  ' ) ) );
		$this->assertTrue( $this->invoke_inbox_is_auto_submitted( $this->fake_message( '  Auto-Replied  ' ) ) );
	}

	// -------------------------------------------------------------------------
	// Webhook::is_auto_submitted() — webhook path
	// -------------------------------------------------------------------------

	public function test_webhook_no_header_is_not_auto_submitted(): void {
		$this->assertFalse( $this->invoke_webhook_is_auto_submitted( [] ) );
	}

	public function test_webhook_explicit_no_is_not_auto_submitted(): void {
		$this->assertFalse( $this->invoke_webhook_is_auto_submitted( [ 'auto-submitted' => 'no' ] ) );
	}

	public function test_webhook_top_level_field_is_auto_submitted(): void {
		$this->assertTrue( $this->invoke_webhook_is_auto_submitted( [ 'Auto-Submitted' => 'auto-replied' ] ) );
	}

	public function test_webhook_mailgun_message_headers_is_auto_submitted(): void {
		$payload = [
			'message-headers' => json_encode( [
				[ 'From', 'artist@example.com' ],
				[ 'Auto-Submitted', 'auto-generated' ],
			] ),
		];

		$this->assertTrue( $this->invoke_webhook_is_auto_submitted( $payload ) );
	}

	public function test_webhook_value_is_case_and_whitespace_insensitive(): void {
		$this->assertFalse( $this->invoke_webhook_is_auto_submitted( [ 'auto-submitted' => '  NO  ' ] ) );
		$this->assertTrue( $this->invoke_webhook_is_auto_submitted( [ 'auto-submitted' => '  Auto-Replied  ' ] ) );
	}
}
