<?php
/**
 * Minimal webklex Message stub for UID cursor tests.
 *
 * Implements only the methods that Inbox::process_messages() touches during
 * the cheap-gate phase (before any body download). All senders return an empty
 * From: list, so gate-1 (unregistered sender) fires immediately — the body is
 * never requested, which is the same effect as fetchBody(false) in production.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

/**
 * Fake webklex Message — camelCase methods mirror the library's public API.
 *
 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */
class FakeImapMessage {

	public function __construct( private int $uid ) {}

	public function getUid(): int {
		return $this->uid;
	}

	/**
	 * Return an empty attribute bag so gate-1 (unregistered sender) fires
	 * immediately without trying to look up a real WP user.
	 *
	 * @return object
	 */
	public function getFrom(): object {
		return new class() {
			public function toArray(): array {
				return [];
			}
		};
	}

	public function getTo(): object {
		return new class() {
			public function toArray(): array {
				return [];
			}
		};
	}

	/** Body access must never be called when fetchBody(false) is in effect. */
	public function getTextBody(): never {
		throw new \LogicException( 'Body must not be fetched for header-only messages.' );
	}
}
// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
