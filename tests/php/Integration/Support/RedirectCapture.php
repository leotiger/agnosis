<?php
/**
 * Test-only exception thrown by the wp_redirect filter.
 *
 * Allows integration tests to intercept wp_safe_redirect() without
 * calling exit, so assertions can be made on the destination URL.
 *
 * @package Agnosis\Tests\Integration\Support
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Support;

class RedirectCapture extends \Exception {
	public function __construct( public readonly string $url, public readonly int $status ) {
		parent::__construct( $url );
	}
}
