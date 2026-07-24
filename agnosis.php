<?php
/**
 * Plugin Name:       Agnosis
 * Plugin URI:        https://agnosis.art
 * Description:       Art blooming out of oblivion. Email your art, AI polishes it, the world sees it. A free, federated publishing network for independent artists.
 * Version:           0.9.50
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
define( 'AGNOSIS_VERSION', '0.9.50' );
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
