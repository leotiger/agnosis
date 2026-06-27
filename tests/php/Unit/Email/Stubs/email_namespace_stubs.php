<?php
/**
 * Namespace-scoped function overrides for Agnosis\Email unit tests.
 *
 * PHP resolves unqualified function calls in a namespace by first checking the
 * current namespace, then falling back to global. Defining get_option() here
 * means Webhook::verify_signature() (which lives in Agnosis\Email) will use
 * this version instead of the global stub in bootstrap.php.
 *
 * When WebhookSignatureTest::$options does not contain the requested key the
 * function returns $fallback — identical behaviour to the global stub, so no
 * other tests are affected.
 *
 * @package Agnosis\Tests\Unit\Email\Stubs
 */

declare(strict_types=1);

namespace Agnosis\Email;

use Agnosis\Tests\Unit\Email\WebhookSignatureTest;

function get_option( string $key, mixed $fallback = false ): mixed {
	return WebhookSignatureTest::$options[ $key ] ?? $fallback;
}
