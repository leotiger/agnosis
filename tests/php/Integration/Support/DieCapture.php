<?php
/**
 * Test-only exception thrown by the wp_die_handler filter.
 *
 * Allows integration tests to intercept wp_die() without outputting HTML,
 * so assertions can be made on the message content and HTTP status code.
 *
 * Note: the message payload is stored as $body rather than $message to avoid
 * conflicting with Exception::$message (which is non-readonly and cannot be
 * redeclared as readonly in a child class).
 *
 * @package Agnosis\Tests\Integration\Support
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Support;

class DieCapture extends \Exception {
	public function __construct(
		public readonly string $body,
		public readonly string $title,
		public readonly int $http_status
	) {
		parent::__construct( $body );
	}
}
