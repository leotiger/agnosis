<?php
/**
 * Plugin activation and deactivation routines.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class Activator {

	/**
	 * Run any pending schema migrations.
	 *
	 * Called on every page load when `agnosis_db_version` doesn't match
	 * `AGNOSIS_VERSION`. `dbDelta()` is additive-only — it adds missing columns
	 * and indexes but never drops or modifies existing ones, making it safe to
	 * run repeatedly on a live database.
	 */
	public static function maybe_upgrade(): void {
		global $wpdb;

		// Explicitly add post_id if missing — dbDelta can silently skip new columns
		// on existing tables when the CURRENT_TIMESTAMP default confuses its differ.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_post_id = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM information_schema.columns
			 WHERE table_schema = DATABASE()
			 AND table_name   = '{$wpdb->prefix}agnosis_queue'
			 AND column_name  = 'post_id'"
		);
		if ( ! $has_post_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query(
				"ALTER TABLE {$wpdb->prefix}agnosis_queue
				 ADD COLUMN post_id BIGINT UNSIGNED DEFAULT NULL AFTER artist_id"
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query(
				"ALTER TABLE {$wpdb->prefix}agnosis_queue
				 ADD INDEX idx_post_id (post_id)"
			);
		}

		self::create_tables();
	}

	/** Runs on plugin activation. */
	public static function activate(): void {
		self::create_tables();
		self::seed_options();
		self::seed_medium_terms();
		self::register_roles();
		self::schedule_events();
		self::create_submissions_page();

		// Flush rewrite rules after registering CPTs.
		flush_rewrite_rules();
	}

	/** Runs on plugin deactivation. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'agnosis_poll_inbox' );
		wp_clear_scheduled_hook( 'agnosis_cleanup_inbox' );
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
			post_id      BIGINT UNSIGNED DEFAULT NULL,
			raw_email    LONGTEXT        NOT NULL,
			status       ENUM('pending','processing','published','failed') NOT NULL DEFAULT 'pending',
			error        TEXT            DEFAULT NULL,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY   uq_message_uid (message_uid),
			KEY          idx_status (status),
			KEY          idx_post_id (post_id)
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

		// Pipeline activity log — surfaced in Settings → Logs.
		$sql_log = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agnosis_log (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level      ENUM('info','warning','error') NOT NULL DEFAULT 'info',
			context    VARCHAR(64)     NOT NULL DEFAULT 'system',
			message    TEXT            NOT NULL,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_level (level),
			KEY idx_created (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_queue );
		dbDelta( $sql_nodes );
		dbDelta( $sql_tx );
		dbDelta( $sql_vouches );
		dbDelta( $sql_log );

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
			'agnosis_email_submit'        => '',
			'agnosis_email_bio'           => '',
			'agnosis_email_event'         => '',
			'agnosis_email_replace'       => '',
			'agnosis_email_remove'        => '',
			'agnosis_imap_host'           => '',
			'agnosis_imap_port'           => 993,
			'agnosis_imap_user'           => '',
			'agnosis_imap_pass'           => '',
			'agnosis_imap_ssl'            => true,
			'agnosis_imap_cleanup_days'   => 7,
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

	/**
	 * Seed the agnosis_medium taxonomy with the canonical list from PromptConfig.
	 *
	 * Idempotent — uses wp_insert_term() which is a no-op when the slug already
	 * exists. Safe to call on every activation or upgrade.
	 */
	private static function seed_medium_terms(): void {
		// The taxonomy must be registered before we can insert terms. On activation
		// the CPT/taxonomy registration hooks have not fired yet, so we register
		// directly here rather than depending on the 'init' hook being fired first.
		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			register_taxonomy( 'agnosis_medium', [ 'agnosis_artwork' ] );
		}

		foreach ( \Agnosis\AI\PromptConfig::CANONICAL_MEDIUMS as $name ) {
			if ( ! term_exists( $name, 'agnosis_medium' ) ) {
				wp_insert_term( $name, 'agnosis_medium' );
			}
		}
	}

	/**
	 * Create the /my-submissions/ page the first time the plugin activates.
	 *
	 * Uses the agnosis/submissions block as page content. The page ID is stored
	 * in wp_options so subsequent activations are no-ops. Artists receive a
	 * direct link to this page in their notification emails.
	 */
	private static function create_submissions_page(): void {
		$existing_id = (int) get_option( 'agnosis_submissions_page_id' );

		// If the stored page still exists and is published, nothing to do.
		if ( $existing_id ) {
			$existing = get_post( $existing_id );
			if ( $existing && 'publish' === $existing->post_status ) {
				return;
			}
		}

		$page_id = wp_insert_post( [
			'post_title'   => __( 'My Submissions', 'agnosis' ),
			'post_name'    => 'my-submissions',
			// Block markup — works with FSE and classic themes via render_callback.
			'post_content' => '<!-- wp:agnosis/submissions /-->',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => 1,
			'meta_input'   => [ '_agnosis_managed_page' => '1' ],
		], false );

		if ( $page_id ) {
			update_option( 'agnosis_submissions_page_id', $page_id );
		}
	}

	private static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'agnosis_poll_inbox' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'agnosis_poll_inbox' );
		}
		// The cron_schedules filter that defines 'every_five_minutes' must be
		// registered on every request, not just on activation. It lives in
		// Inbox::register_interval() and is wired via Plugin::register_services().
	}
}
