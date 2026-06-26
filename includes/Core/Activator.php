<?php
/**
 * Plugin activation and deactivation routines.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class Activator {

	/** Runs on plugin activation. */
	public static function activate(): void {
		self::create_tables();
		self::seed_options();
		self::register_roles();
		self::schedule_events();

		// Flush rewrite rules after registering CPTs.
		flush_rewrite_rules();
	}

	/** Runs on plugin deactivation. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'agnosis_poll_inbox' );
		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------

	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Submission queue — tracks email-to-post pipeline state.
		$sql_queue = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agnosis_queue (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			message_uid  VARCHAR(255)    NOT NULL,
			artist_id    BIGINT UNSIGNED DEFAULT NULL,
			raw_email    LONGTEXT        NOT NULL,
			status       ENUM('pending','processing','published','failed') NOT NULL DEFAULT 'pending',
			error        TEXT            DEFAULT NULL,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY   uq_message_uid (message_uid),
			KEY          idx_status (status)
		) $charset_collate;";

		// Rhizome peers — known Agnosis (or ActivityPub) nodes.
		$sql_nodes = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agnosis_nodes (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url          VARCHAR(512)    NOT NULL,
			public_key   TEXT            DEFAULT NULL,
			label        VARCHAR(255)    DEFAULT NULL,
			status       ENUM('pending','trusted','blocked') NOT NULL DEFAULT 'pending',
			last_seen    DATETIME        DEFAULT NULL,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY   uq_url (url)
		) $charset_collate;";

		// Transactions — donations and store sales.
		$sql_tx = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agnosis_transactions (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type         ENUM('donation','sale') NOT NULL,
			artist_id    BIGINT UNSIGNED NOT NULL,
			post_id      BIGINT UNSIGNED DEFAULT NULL,
			amount       DECIMAL(10,2)   NOT NULL,
			currency     CHAR(3)         NOT NULL DEFAULT 'EUR',
			fee          DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			gateway      VARCHAR(64)     DEFAULT NULL,
			gateway_ref  VARCHAR(255)    DEFAULT NULL,
			status       ENUM('pending','completed','refunded','failed') NOT NULL DEFAULT 'pending',
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY          idx_artist (artist_id),
			KEY          idx_status (status)
		) $charset_collate;";

		// Vouching / admission log.
		$sql_vouches = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agnosis_vouches (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			voucher_id   BIGINT UNSIGNED NOT NULL,
			candidate_id BIGINT UNSIGNED NOT NULL,
			message      TEXT            DEFAULT NULL,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY   uq_pair (voucher_id, candidate_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_queue );
		dbDelta( $sql_nodes );
		dbDelta( $sql_tx );
		dbDelta( $sql_vouches );

		update_option( 'agnosis_db_version', AGNOSIS_VERSION );
	}

	private static function register_roles(): void {
		// Only register if not already present (add_role is a no-op when it exists,
		// but this guard makes intent explicit and avoids the PHP notice in older WP).
		if ( null === get_role( 'agnosis_artist' ) ) {
			add_role(
				'agnosis_artist',
				__( 'Agnosis Artist', 'agnosis' ),
				[
					'read'           => true,
					'agnosis_artist' => true, // used as a primitive cap in user_can() checks
				]
			);
		}
	}

	private static function seed_options(): void {
		$defaults = [
			'agnosis_ai_provider'         => 'openai',
			'agnosis_openai_api_key'      => '',
			'agnosis_anthropic_api_key'   => '',
			'agnosis_stability_api_key'   => '',
			'agnosis_email_driver'        => 'imap',
			'agnosis_imap_host'           => '',
			'agnosis_imap_port'           => 993,
			'agnosis_imap_user'           => '',
			'agnosis_imap_pass'           => '',
			'agnosis_imap_ssl'            => true,
			'agnosis_webhook_secret'      => wp_generate_password( 32, false ),
			'agnosis_node_label'          => get_bloginfo( 'name' ),
			'agnosis_vouches_required'    => 2,
			'agnosis_tx_fee_percent'      => 7.0,
			'agnosis_activitypub_enabled' => true,
		];

		foreach ( $defaults as $key => $value ) {
			add_option( $key, $value ); // add_option skips if key already exists.
		}
	}

	private static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'agnosis_poll_inbox' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'agnosis_poll_inbox' );
		}

		// Register the 5-minute cron interval if not present.
		add_filter( 'cron_schedules', function ( array $schedules ): array {
			if ( ! isset( $schedules['every_five_minutes'] ) ) {
				$schedules['every_five_minutes'] = [
					'interval' => 300,
					'display'  => __( 'Every 5 minutes', 'agnosis' ),
				];
			}
			return $schedules;
		} );
	}
}
