<?php
/**
 * Bootstrap for integration tests.
 *
 * Runs inside the wp-env tests-cli container where WordPress is available.
 * wp-env sets WP_PHPUNIT__DIR to the WordPress test library path.
 *
 * Usage (from agnosis/dev/):
 *   composer test:integration
 *
 * @package Agnosis\Tests
 */

declare(strict_types=1);

// Composer autoloader for test helpers.
require_once dirname(__DIR__, 3) . '/dev/vendor/autoload.php';

$_tests_dir = getenv('WP_PHPUNIT__DIR') ?: getenv('WP_TESTS_DIR') ?: '';

if ( ! $_tests_dir || ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// Common fallback path inside wp-env containers.
	$candidates = [
		'/tmp/wordpress-tests-lib',
		'/var/www/html/tests/phpunit',
		'/tmp/wp-phpunit',
	];
	foreach ( $candidates as $path ) {
		if ( file_exists( $path . '/includes/functions.php' ) ) {
			$_tests_dir = $path;
			break;
		}
	}
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "ERROR: WordPress test library not found.\n";
	echo "Run 'npm run env:start' from dev/ to start wp-env first.\n";
	echo 'WP_PHPUNIT__DIR=' . ( getenv('WP_PHPUNIT__DIR') ?: '(not set)' ) . "\n";
	exit( 1 );
}

// functions.php defines tests_add_filter() — must be loaded before calling it.
require_once $_tests_dir . '/includes/functions.php';

// Fake, test-only stand-ins for the handful of linguaforge_*() functions
// Agnosis calls directly (guarded everywhere via function_exists()). Loading
// the *real* Lingua Forge plugin here was tried and reverted: its Router
// singleton self-registers a pre_get_posts filter that scopes every secondary
// WP_Query site-wide once LF_LANG is defined — which it always is, even in
// this CLI/test context (detect_lang_safe() falls through to the source
// language rather than returning empty) — so it would silently affect every
// other integration test in the suite that creates posts without _lf_lang
// meta, not just the newsletter localization tests. See
// tests/php/Integration/Support/FakeLinguaForge.php.
require_once __DIR__ . '/Support/linguaforge-function-stubs.php';

// Load the Agnosis plugin before WordPress finishes booting.
tests_add_filter( 'muplugins_loaded', function (): void {
	require_once dirname( __DIR__, 3 ) . '/agnosis.php';
} );

require_once $_tests_dir . '/includes/bootstrap.php';
