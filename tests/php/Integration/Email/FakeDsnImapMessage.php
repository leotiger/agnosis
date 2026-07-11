<?php
/**
 * Configurable webklex Message stub for the bounce/complaint DSN detection
 * and extraction tests (security audit §5a).
 *
 * Like FakeAliasImapMessage, this deliberately does NOT extend or implement
 * Webklex\PHPIMAP\Message — Inbox::is_bounce_dsn() and handle_bounce_dsn()
 * both accept a plain `object` for the same reason passes_email_auth() does
 * (see that method's docblock): a real Message requires a live IMAP
 * connection to construct, so these gates are exercised via a lightweight
 * double instead. This one adds a Content-Type header, an
 * X-Failed-Recipients header, and a raw text body — the three inputs
 * is_bounce_dsn()/handle_bounce_dsn() actually read.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

/**
 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */
class FakeDsnImapMessage {

	public function __construct(
		private string $content_type = '',
		private string $failed_recipients_header = '',
		private string $text_body = ''
	) {}

	/** Mimics webklex's getHeader()->get($name) chain for the two headers these gates read. */
	public function getHeader(): object {
		$content_type = $this->content_type;
		$failed       = $this->failed_recipients_header;
		return new class( $content_type, $failed ) {
			public function __construct( private string $content_type, private string $failed ) {}
			public function get( string $name ): string {
				return match ( strtolower( $name ) ) {
					'content-type' => $this->content_type,
					'x-failed-recipients' => $this->failed,
					default => '',
				};
			}
		};
	}

	public function getTextBody(): string {
		return $this->text_body;
	}
}
// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
