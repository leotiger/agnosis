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
 * Also carries an optional subject (2026-07-14, default '' — every existing
 * positional construction is unaffected), so tests can confirm
 * Inbox::mark_no_artwork()'s subject/to_addresses capture actually reaches a
 * skipped row's raw_email, not just its recipient. Inbox::message_subject()'s
 * own defensive fallback (a double with no getSubject() at all) stays covered
 * by FakeImapMessage and FakeDsnImapMessage, which still omit it entirely.
 *
 * Also implements delete() (2026-07-14), recording whether it was called via
 * the public $deleted flag — lets tests confirm the recognised-recipient/BCC
 * gate in Inbox::process_messages() actually deletes a message it rejects.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

/**
 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */
class FakeAliasImapMessage {

	/**
	 * Set by delete() (2026-07-14) so tests can assert whether
	 * Inbox::process_messages()'s recognised-recipient/BCC gate actually
	 * called it, without needing a real IMAP connection to verify against.
	 */
	public bool $deleted = false;

	public function __construct(
		private int $uid,
		private string $from_email,
		private string $to_email,
		private string $auth_header = '',
		private string $subject = ''
	) {}

	public function getUid(): int {
		return $this->uid;
	}

	/** Mimics webklex's Message::delete( bool $expunge ). */
	public function delete( bool $expunge = false ): bool {
		$this->deleted = true;
		return true;
	}

	/**
	 * Added 2026-07-14 alongside Inbox::message_subject() — optional, default
	 * '' so every existing call site constructing this double positionally
	 * (without a subject) is unaffected. Lets tests confirm a skip reason
	 * actually persists the message's subject, not just its recipients.
	 */
	public function getSubject(): string {
		return $this->subject;
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
