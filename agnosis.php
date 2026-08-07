<?php
/**
 * Plugin Name:       Agnosis
 * Plugin URI:        https://agnosis.art
 * Description:       Art blooming out of oblivion. Email your art, AI polishes it, the world sees it. A free, federated publishing network for independent artists.
 * Version:           0.9.68
 * Requires at least: 6.9
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
define( 'AGNOSIS_VERSION', '0.9.68' );
define( 'AGNOSIS_FILE', __FILE__ );
define( 'AGNOSIS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGNOSIS_URL', plugin_dir_url( __FILE__ ) );
define( 'AGNOSIS_BASENAME', plugin_basename( __FILE__ ) );
define( 'AGNOSIS_MIN_PHP', '8.2' );
define( 'AGNOSIS_MIN_WP', '6.9' );

/**
 * Oldest Lingua Forge this version of Agnosis is written against — sixteenth
 * audit, L-1 (2026-07-31).
 *
 * 2.7.1 is where `linguaforge_sitemap_extra_urls` landed, which 0.9.64's
 * artist-subdomain sitemap integration consumes. WordPress's own
 * `Requires Plugins:` header (declared above) enforces that Lingua Forge is
 * INSTALLED AND ACTIVE, but core has no version syntax for it, so nothing
 * previously noticed an install running an older LF.
 *
 * Deliberately advisory, NOT a hard gate — see agnosis_lingua_forge_notice().
 */
define( 'AGNOSIS_MIN_LF', '2.7.1' );

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

/**
 * Advisory notice when Lingua Forge is older than AGNOSIS_MIN_LF — sixteenth
 * audit, L-1 (2026-07-31).
 *
 * **Deliberately not part of agnosis_requirements_check(), and this is the
 * whole design decision.** That function is a hard gate: a false return stops
 * Core\Plugin::run() from ever executing, which unregisters ~195 hooks — all
 * three custom post types, every cron, every REST route. Applying it to a
 * Lingua Forge version mismatch would take a working public site down (every
 * artwork URL 404s, intake and federation stop) in response to a dependency
 * being a point release behind. The audit that raised L-1 suggested reusing
 * that machinery; on reading what bailing actually costs, that is the wrong
 * trade.
 *
 * It is also unnecessary, which is the deciding half: EVERY Lingua Forge call
 * site in this plugin is already individually guarded by `function_exists()`
 * or `class_exists()` — 28 of them across 6 files, re-counted for this fix —
 * so on an older LF the affected features simply do not run. The plugin
 * already degrades gracefully; what it could not do before was TELL anyone.
 * That is exactly what a notice is for, and all this adds.
 *
 * Runs on `admin_notices`, which fires long after every plugin file is loaded,
 * so LINGUAFORGE_VERSION is reliably defined by then regardless of plugin
 * load order (Agnosis sorts before lingua-forge and is therefore included
 * first).
 */
function agnosis_lingua_forge_notice(): void {
	if ( ! defined( 'LINGUAFORGE_VERSION' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( version_compare( (string) LINGUAFORGE_VERSION, AGNOSIS_MIN_LF, '>=' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		sprintf(
			/* translators: 1: required Lingua Forge version, 2: currently installed Lingua Forge version */
			esc_html__( 'Agnosis is written against Lingua Forge %1$s or newer; this site has %2$s. Agnosis keeps working — features that need the newer version simply stay inactive, currently the artist-subdomain entries in the multilingual sitemap. Updating Lingua Forge enables them.', 'agnosis' ),
			esc_html( AGNOSIS_MIN_LF ),
			esc_html( (string) LINGUAFORGE_VERSION )
		)
	);
}
add_action(
	'admin_notices',
	static function (): void {
		agnosis_lingua_forge_notice();
	}
);

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
// Hooked to 'init', not 'plugins_loaded', deliberately. (This used to note
// that the five per-class self-healing schedulers hooked 'init' for the same
// reason; 0.9.66's Q-3 fix folded all five into RECURRING_CRON_SCHEDULE and
// deleted them, so this callback is now the only scheduler there is — but the
// timing constraint below is unchanged and is why it must stay on 'init'.)
// The 'every_five_minutes' custom interval itself is
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
