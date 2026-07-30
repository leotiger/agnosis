<?php
/**
 * Plugin Name:       Agnosis
 * Plugin URI:        https://agnosis.art
 * Description:       Art blooming out of oblivion. Email your art, AI polishes it, the world sees it. A free, federated publishing network for independent artists.
 * Version:           0.9.64
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Requires Plugins:  lingua-forge
 * Author:            Uli Hake
 * Author URI:        https://agnosis.art
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agnosis
 * Domain Path:       /languages
 *
 * @package Agnosis
 */

declare(strict_types=1);

namespace Agnosis;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'AGNOSIS_VERSION', '0.9.64' );
define( 'AGNOSIS_FILE', __FILE__ );
define( 'AGNOSIS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGNOSIS_URL', plugin_dir_url( __FILE__ ) );
define( 'AGNOSIS_BASENAME', plugin_basename( __FILE__ ) );
define( 'AGNOSIS_MIN_PHP', '8.2' );
define( 'AGNOSIS_MIN_WP', '6.6' );

// Autoloader.
if ( file_exists( AGNOSIS_DIR . 'vendor/autoload.php' ) ) {
	require_once AGNOSIS_DIR . 'vendor/autoload.php';
} else {
	// Fallback PSR-4 autoloader (no Composer).
	spl_autoload_register(
		function ( string $classname ): void {
			if ( strpos( $classname, 'Agnosis\\' ) !== 0 ) {
				return;
			}
			$relative = str_replace(
				[ 'Agnosis\\', '\\' ],
				[ '', DIRECTORY_SEPARATOR ],
				$classname
			);
			$file = AGNOSIS_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

/**
 * PHP / WP version gate. Shows admin notice and bails early.
 *
 * @return bool True if requirements are met.
 */
function agnosis_requirements_check(): bool {
	if ( version_compare( PHP_VERSION, AGNOSIS_MIN_PHP, '<' ) ) {
		add_action(
			'admin_notices',
			function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					sprintf(
						/* translators: %s: required PHP version */
						esc_html__( 'Agnosis requires PHP %s or higher.', 'agnosis' ),
						esc_html( AGNOSIS_MIN_PHP )
					)
				);
			}
		);
		return false;
	}
	if ( version_compare( get_bloginfo( 'version' ), AGNOSIS_MIN_WP, '<' ) ) {
		add_action(
			'admin_notices',
			function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					sprintf(
						/* translators: %s: required WP version */
						esc_html__( 'Agnosis requires WordPress %s or higher.', 'agnosis' ),
						esc_html( AGNOSIS_MIN_WP )
					)
				);
			}
		);
		return false;
	}
	return true;
}

// Activation / deactivation hooks — register before any early returns.
register_activation_hook( __FILE__, [ Core\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Core\Activator::class, 'deactivate' ] );

// Schema migration — runs on every load but only executes when the stored
// DB version is behind the plugin version. dbDelta is additive-only (adds
// columns / indexes, never removes), so this is safe on live databases.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( get_option( 'agnosis_db_version' ) !== AGNOSIS_VERSION ) {
			Core\Activator::maybe_upgrade();
		}
	},
	5
);

// Recurring-cron self-heal — deliberately UNCONDITIONAL, unlike the version
// gate just above. Found 2026-07-28: several `every_five_minutes`/`daily`
// cron events (Activator::RECURRING_CRON_SCHEDULE) were missing entirely
// from a live, already-up-to-date site's schedule — because schedule_events()
// only ever (re-)registers them once, at the exact moment a version bump
// makes maybe_upgrade() run, never again afterward on that same version. If
// anything external clears one of those events later (a host's cron-table
// cleanup, a caching/optimisation plugin, a migration, a stray `wp cron
// event delete`), nothing brought it back until the next version bump — the
// same failure mode Activator::ensure_newsletter_cron_scheduled() already
// existed to fix for just the newsletter pair, never generalised. Running
// this on every single request (not version-gated, not tied to an admin
// visiting one specific dashboard page) means the very next page load after
// this ships re-registers whatever's missing, no manual intervention needed.
//
// Hooked to 'init', not 'plugins_loaded', deliberately — every sibling
// self-healing scheduler already in this codebase (Email\Inbox::
// schedule_poll()/schedule_cleanup(), Admin\TagProposals::
// schedule_ttl_sweep(), Admin\MediumProposals::schedule_ttl_sweep(),
// Network\FederationSettlement::schedule_fallback_sweep()) uses 'init' too,
// specifically because the 'every_five_minutes' custom interval itself is
// only registered once Core\Plugin::run() applies its own collected
// 'cron_schedules' filter — which happens later in this same
// 'plugins_loaded' action (priority 10, in the "Boot." block below).
// wp_schedule_event() validates its $recurrence argument against
// wp_get_schedules() at call time, so calling it any earlier than that —
// including from another 'plugins_loaded' callback at a lower priority —
// would silently fail to schedule any 'every_five_minutes' hook every
// single time. 'init' always fires after every 'plugins_loaded' priority
// has run, so the filter is guaranteed to be in place by then.
add_action(
	'init',
	static function (): void {
		Core\Activator::ensure_recurring_crons_scheduled();
	}
);

// Subdomain router — must boot before the main plugin (priority 10) so the
// option_home filter is in place before init runs and WP builds its URL tables.
add_action(
	'plugins_loaded',
	static function (): void {
		( new Network\SubdomainRouter() )->boot();
	},
	7
);

// Boot.
add_action(
	'plugins_loaded',
	function (): void {
		if ( ! agnosis_requirements_check() ) {
			return;
		}
		Core\Plugin::instance()->run();
	},
	10
);
