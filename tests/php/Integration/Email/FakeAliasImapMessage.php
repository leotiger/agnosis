<?php
/**
 * Configurable webklex Message stub for the fourth-audit §3b gate-ordering /
 * goodbye-throttle regression tests.
 *
 * Unlike FakeImapMessage (InboxUidCursorTest's fixed always-empty-From/To
 * double), this fake supports a real From/To address pair and an optional
 * Authentication-Results header, so it can drive Inbox::process_messages()
 * through the goodbye@ alias branch and the (now-unconditional)
 * passes_email_auth() gate. It deliberately does NOT extend or implement
 * Webklex\PHPIMAP\Message — Inbox::passes_email_auth() accepts a plain
 * `object` for exactly this reason (see that method's docblock). It must
 * NOT be routed into handle_community_email(), whose parameter is still
 * strictly typed `Message` — these tests only exercise the goodbye@ alias
 * and the general auth gate, never the community@ alias.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

/**
 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */
class FakeAliasImapMessage {

	public function __construct(
		private int $uid,
		private string $from_email,
		private string $to_email,
		private string $auth_header = ''
	) {}

	public function getUid(): int {
		return $this->uid;
	}

	public function getFrom(): object {
		$email = $this->from_email;
		return new class( $email ) {
			public function __construct( private string $email ) {}
			public function toArray(): array {
				return '' === $this->email ? [] : [ (object) [ 'mail' => $this->email ] ];
			}
		};
	}

	public function getTo(): object {
		$email = $this->to_email;
		return new class( $email ) {
			public function __construct( private string $email ) {}
			public function toArray(): array {
				return '' === $this->email ? [] : [ (object) [ 'mail' => $this->email ] ];
			}
		};
	}

	/** Mimics webklex's getHeader()->get('authentication-results') chain. */
	public function getHeader(): object {
		$auth = $this->auth_header;
		return new class( $auth ) {
			public function __construct( private string $auth ) {}
			public function get( string $name ): string {
				return 'authentication-results' === $name ? $this->auth : '';
			}
		};
	}
}
// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
