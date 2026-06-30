<?php
/**
 * Plugin activation and deactivation routines.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

use Agnosis\Email\AttachmentStore;

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

		// Use SHOW COLUMNS … LIKE instead of information_schema — the latter is
		// restricted on some managed database providers (PlanetScale, Kinsta DB, etc.).
		// SHOW COLUMNS returns an empty result set when the column doesn't exist.

		// Add post_id to agnosis_queue if missing — guard for installs created
		// before 0.1.9 when CREATE TABLE used IF NOT EXISTS (causing dbDelta to
		// silently skip column additions). Kept for backward compatibility.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_post_id = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_queue LIKE 'post_id'" );
		if ( empty( $has_post_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_queue ADD COLUMN post_id BIGINT UNSIGNED DEFAULT NULL AFTER artist_id" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_queue ADD INDEX idx_post_id (post_id)" );
		}

		// Add revoked_at to agnosis_vouches if missing (column added in 0.1.8).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_revoked_at = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_vouches LIKE 'revoked_at'" );
		if ( empty( $has_revoked_at ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_vouches ADD COLUMN revoked_at DATETIME NULL DEFAULT NULL AFTER created_at" );
		}

		// Add vote to agnosis_application_vouches if missing (column added in 0.2.0).
		// Guard for installs that created this table before the vote column existed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$app_vouches_exists = $wpdb->get_results( "SHOW TABLES LIKE '{$wpdb->prefix}agnosis_application_vouches'" );
		if ( ! empty( $app_vouches_exists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_vote = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_application_vouches LIKE 'vote'" );
			if ( empty( $has_vote ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_application_vouches ADD COLUMN vote ENUM('yes','no') NOT NULL DEFAULT 'yes' AFTER voucher_id" );
			}
		}

		// Add language to agnosis_applications if missing (column added in 0.2.0).
		// Stores the applicant's preferred content language (ISO 639-1) for locale
		// switching in notification emails and AI back-translation of post previews.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$app_table_exists = $wpdb->get_results( "SHOW TABLES LIKE '{$wpdb->prefix}agnosis_applications'" );
		if ( ! empty( $app_table_exists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_language = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_applications LIKE 'language'" );
			if ( empty( $has_language ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_applications ADD COLUMN language VARCHAR(10) DEFAULT NULL AFTER statement" );
			}

			// Add banned_until (temporary ban expiry) and removal_token (self-removal
			// confirmation) columns — added in 0.2.0. dbDelta cannot modify ENUMs so
			// the 'banned' status value is added via ALTER TABLE as well.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_banned_until = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_applications LIKE 'banned_until'" );
			if ( empty( $has_banned_until ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_applications ADD COLUMN banned_until DATETIME DEFAULT NULL AFTER wp_user_id" );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_removal_token = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_applications LIKE 'removal_token'" );
			if ( empty( $has_removal_token ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_applications ADD COLUMN removal_token VARCHAR(64) DEFAULT NULL AFTER banned_until" );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_applications ADD UNIQUE KEY uq_removal_token (removal_token)" );
			}

			// Extend the status ENUM to include 'banned' (added in 0.2.0).
			// We detect this by checking whether the column definition already includes
			// 'banned'; SHOW COLUMNS returns the Type as e.g. "enum('pending','admitted',…)".
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$status_col = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_applications LIKE 'status'" );
			if ( $status_col && false === strpos( (string) $status_col->Type, 'banned' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_applications MODIFY COLUMN status ENUM('pending','admitted','rejected','withdrawn','left','banned','waitlisted') NOT NULL DEFAULT 'pending'" );
			}

			// Extend the status ENUM to include 'waitlisted' (community size cap, 0.3.0).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$status_col_wl = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_applications LIKE 'status'" );
			if ( $status_col_wl && false === strpos( (string) $status_col_wl->Type, 'waitlisted' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_applications MODIFY COLUMN status ENUM('pending','admitted','rejected','withdrawn','left','banned','waitlisted') NOT NULL DEFAULT 'pending'" );
			}
		}

		// Finish the upgrade with idempotent provisioning so a version bump fully
		// applies: new tables (dbDelta), new default options, the role, and all
		// scheduled events (incl. agnosis_check_cap_votes). Each step is a no-op when
		// its target already exists. Managed-page creation needs $wp_rewrite
		// (permalinks), which is NOT ready on plugins_loaded, so it is deferred to
		// init in the same request.
		self::create_tables();
		self::seed_options();
		self::register_roles();
		self::schedule_events();
		add_action( 'init', [ self::class, 'create_managed_pages' ], 99 );
	}

	/**
	 * Create the managed content pages (submissions, join, about, artist guide).
	 *
	 * Idempotent. Called directly from activate() (admin context, permalinks ready)
	 * and deferred to init from maybe_upgrade() (where plugins_loaded is too early
	 * for permalink generation).
	 */
	public static function create_managed_pages(): void {
		self::create_submissions_page();
		self::create_join_page();
		self::create_about_page();
		self::create_help_page();
	}

	/** Runs on plugin activation. */
	public static function activate(): void {
		self::create_tables();
		self::seed_options();
		self::seed_medium_terms();
		self::register_roles();
		self::schedule_events();
		self::create_managed_pages();

		// Create the protected attachment queue directory under uploads/.
		AttachmentStore::ensure_protected();

		// Flush rewrite rules after registering CPTs.
		flush_rewrite_rules();
	}

	/** Runs on plugin deactivation. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'agnosis_poll_inbox' );
		wp_clear_scheduled_hook( 'agnosis_cleanup_inbox' );
		wp_clear_scheduled_hook( 'agnosis_check_admissions' );
		wp_clear_scheduled_hook( 'agnosis_check_bans' );
		wp_clear_scheduled_hook( 'agnosis_check_removal_votes' );
		wp_clear_scheduled_hook( 'agnosis_check_cap_votes' );
		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------

	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Submission queue — tracks email-to-post pipeline state.
		$sql_queue = "CREATE TABLE {$wpdb->prefix}agnosis_queue (
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
		$sql_nodes = "CREATE TABLE {$wpdb->prefix}agnosis_nodes (
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
		$sql_tx = "CREATE TABLE {$wpdb->prefix}agnosis_transactions (
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
		$sql_vouches = "CREATE TABLE {$wpdb->prefix}agnosis_vouches (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			voucher_id   BIGINT UNSIGNED NOT NULL,
			candidate_id BIGINT UNSIGNED NOT NULL,
			message      TEXT            DEFAULT NULL,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			revoked_at   DATETIME        NULL      DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY   uq_pair (voucher_id, candidate_id)
		) $charset_collate;";

		// Pipeline activity log — surfaced in Settings → Logs.
		$sql_log = "CREATE TABLE {$wpdb->prefix}agnosis_log (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level      ENUM('info','warning','error') NOT NULL DEFAULT 'info',
			context    VARCHAR(64)     NOT NULL DEFAULT 'system',
			message    TEXT            NOT NULL,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_level (level),
			KEY idx_created (created_at)
		) $charset_collate;";

		// Membership lifecycle — one row per applicant / artist / former member.
		// status: pending → admitted | rejected | withdrawn | left | banned.
		// banned_until: NULL = permanent ban; future datetime = temporary ban (cron reinstates).
		// removal_token: single-use CSPRNG hex token for self-removal email confirmation.
		// resolved_at is set whenever status leaves 'pending'.
		$sql_applications = "CREATE TABLE {$wpdb->prefix}agnosis_applications (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email          VARCHAR(255)    NOT NULL,
			display_name   VARCHAR(255)    NOT NULL,
			bio            TEXT            DEFAULT NULL,
			portfolio_url  VARCHAR(512)    DEFAULT NULL,
			statement      TEXT            DEFAULT NULL,
			language       VARCHAR(10)     DEFAULT NULL,
			status         ENUM('pending','admitted','rejected','withdrawn','left','banned','waitlisted') NOT NULL DEFAULT 'pending',
			wp_user_id     BIGINT UNSIGNED DEFAULT NULL,
			banned_until   DATETIME        DEFAULT NULL,
			removal_token  VARCHAR(64)     DEFAULT NULL,
			applied_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			resolved_at    DATETIME        DEFAULT NULL,
			PRIMARY KEY    (id),
			UNIQUE KEY     uq_email (email),
			UNIQUE KEY     uq_removal_token (removal_token),
			KEY            idx_status (status),
			KEY            idx_wp_user (wp_user_id)
		) $charset_collate;";

		// Community removal requests — tracks open votes to remove an admitted artist.
		// status: nominating (gathering initiators) → open (full community vote) →
		//         passed | failed | cancelled.
		// opened_at / closes_at mark the full-vote window; resolved_at marks the outcome.
		$sql_removal_requests = "CREATE TABLE {$wpdb->prefix}agnosis_removal_requests (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subject_user_id BIGINT UNSIGNED NOT NULL,
			initiated_by    BIGINT UNSIGNED DEFAULT NULL,
			status          ENUM('nominating','open','passed','failed','cancelled') NOT NULL DEFAULT 'nominating',
			opened_at       DATETIME        DEFAULT NULL,
			closes_at       DATETIME        DEFAULT NULL,
			resolved_at     DATETIME        DEFAULT NULL,
			PRIMARY KEY     (id),
			KEY             idx_subject (subject_user_id),
			KEY             idx_status (status)
		) $charset_collate;";

		// Individual votes on a community removal request.
		// A voter may change their vote (UPDATE on the unique key).
		$sql_removal_votes = "CREATE TABLE {$wpdb->prefix}agnosis_removal_votes (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id BIGINT UNSIGNED NOT NULL,
			voter_id   BIGINT UNSIGNED NOT NULL,
			vote       ENUM('yes','no') NOT NULL,
			voted_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY  uq_voter (request_id, voter_id),
			KEY         idx_request (request_id)
		) $charset_collate;";

		// Community size-cap change proposals (member-governed cap).
		// status: nominating (gathering co-signers) → open (full vote) →
		//         passed | failed | cancelled. proposed_cap is the value to adopt.
		$sql_cap_proposals = "CREATE TABLE {$wpdb->prefix}agnosis_cap_proposals (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			proposed_cap  INT             NOT NULL,
			initiated_by  BIGINT UNSIGNED DEFAULT NULL,
			status        ENUM('nominating','open','passed','failed','cancelled') NOT NULL DEFAULT 'nominating',
			opened_at     DATETIME        DEFAULT NULL,
			closes_at     DATETIME        DEFAULT NULL,
			resolved_at   DATETIME        DEFAULT NULL,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY   (id),
			KEY           idx_status (status)
		) $charset_collate;";

		// Individual votes on a cap-change proposal (a voter may change their vote).
		$sql_cap_votes = "CREATE TABLE {$wpdb->prefix}agnosis_cap_votes (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			proposal_id BIGINT UNSIGNED NOT NULL,
			voter_id    BIGINT UNSIGNED NOT NULL,
			vote        ENUM('yes','no') NOT NULL,
			voted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY  uq_voter (proposal_id, voter_id),
			KEY         idx_proposal (proposal_id)
		) $charset_collate;";

		// Pre-admission vouches — artists vouching for a pending application.
		// Kept separate from agnosis_vouches (post-admission community table).
		// revoked_at is set instead of deleting so the audit trail is preserved.
		$sql_application_vouches = "CREATE TABLE {$wpdb->prefix}agnosis_application_vouches (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			application_id BIGINT UNSIGNED NOT NULL,
			voucher_id     BIGINT UNSIGNED NOT NULL,
			vote           ENUM('yes','no') NOT NULL DEFAULT 'yes',
			message        TEXT            DEFAULT NULL,
			created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			revoked_at     DATETIME        NULL      DEFAULT NULL,
			PRIMARY KEY    (id),
			UNIQUE KEY     uq_pair (application_id, voucher_id),
			KEY            idx_application (application_id),
			KEY            idx_voucher (voucher_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_queue );
		dbDelta( $sql_nodes );
		dbDelta( $sql_tx );
		dbDelta( $sql_vouches );
		dbDelta( $sql_log );
		dbDelta( $sql_applications );
		dbDelta( $sql_application_vouches );
		dbDelta( $sql_removal_requests );
		dbDelta( $sql_removal_votes );
		dbDelta( $sql_cap_proposals );
		dbDelta( $sql_cap_votes );

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
			'agnosis_admission_percent'           => 10,  // min % of active artists required
			'agnosis_admission_minimum'           => 3,   // absolute floor regardless of %
			'agnosis_admission_window_days'       => 7,   // days before application expires
			'agnosis_community_max_artists'       => 50,  // community size cap (0 = unlimited)
			'agnosis_cap_proposal_threshold'      => 3,   // co-signers to open a cap-change vote
			'agnosis_cap_vote_window_days'        => 7,   // days a cap-change vote stays open
			'agnosis_tx_fee_percent'              => 7.0,
			'agnosis_activitypub_enabled'         => true,
			'agnosis_quality_threshold'           => 7,
			'agnosis_quality_rejection_threshold' => 3,
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

	/**
	 * Create the public "About Agnosis" page on first activation.
	 *
	 * A plain content page (core blocks). Lingua Forge translates it like any page,
	 * so the copy is authored in the site's source language — no i18n at creation.
	 */
	private static function create_about_page(): void {
		self::create_managed_page( 'agnosis_about_page_id', 'About', 'about', self::about_page_content() );
	}

	/**
	 * Create the public "Artist Guide" help page on first activation.
	 *
	 * Explains the email-driven workflow in plain language. Translated by LF.
	 */
	private static function create_help_page(): void {
		self::create_managed_page( 'agnosis_help_page_id', 'Artist Guide', 'artist-guide', self::help_page_content() );
	}

	/**
	 * Shared creator for a managed content page. Idempotent: stores the new ID in
	 * $option and is a no-op when that page already exists and is published. Tags
	 * the page with _agnosis_managed_page so uninstall.php removes it.
	 *
	 * @param string $option  Option name holding the page ID.
	 * @param string $title   Page title (source language; LF translates it).
	 * @param string $slug    URL slug.
	 * @param string $content Block markup for the page body.
	 */
	private static function create_managed_page( string $option, string $title, string $slug, string $content ): void {
		$existing_id = (int) get_option( $option );
		if ( $existing_id ) {
			$existing = get_post( $existing_id );
			if ( $existing && 'publish' === $existing->post_status ) {
				return;
			}
		}

		$page_id = wp_insert_post( [
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => 1,
			'meta_input'   => [ '_agnosis_managed_page' => '1' ],
		], false );

		if ( $page_id ) {
			update_option( $option, $page_id );
		}
	}

	/** Block markup for the "About Agnosis" page. */
	private static function about_page_content(): string {
		return implode( "\n", [
			'<!-- wp:heading {"level":1} --><h1>About Agnosis</h1><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Agnosis is a community-run home for independent artists. You email your work, AI gives it a clean title, description, and tags, the community decides who joins, and your art appears here and across the open social web (the &#8220;fediverse&#8221;). There are no dashboards to learn, no algorithm deciding who sees you, and no ads.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>How it works</h2><!-- /wp:heading -->',
			'<!-- wp:list --><ul><li>You email your artwork, a biography, or an event.</li><li>AI polishes the <em>presentation</em> &#8212; never the artwork itself, unless you ask.</li><li>Existing members vouch for newcomers; the community admits them.</li><li>Your work is published here and delivered to your followers on the fediverse.</li></ul><!-- /wp:list -->',
			'<!-- wp:heading --><h2>Owned by its members</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>The community admits new members, can vote to part ways with one, and even votes on how large it grows. Agnosis is a commons, not a platform &#8212; small and human by design.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Your work stays yours</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>You can leave whenever you like and take everything with you. No lock-in, ever.</p><!-- /wp:paragraph -->',
		] );
	}

	/** Block markup for the "Artist Guide" help page. */
	private static function help_page_content(): string {
		return implode( "\n", [
			'<!-- wp:heading {"level":1} --><h1>Artist Guide</h1><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Working with Agnosis is as simple as sending an email &#8212; you never have to log in to a dashboard. Here is everything you need to know.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Joining</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Apply from the join page. Once enough members have vouched for you, you are welcomed in and can start sending work by email.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Sending artwork</h2><!-- /wp:heading -->',
			'<!-- wp:list --><ul><li>Attach your image to an email.</li><li>Put the title of the piece in the <strong>subject line</strong>.</li><li>Add a few words in the body if you like &#8212; or nothing at all.</li><li>Send it to the submission address your community shared with you.</li></ul><!-- /wp:list -->',
			'<!-- wp:paragraph --><p>AI writes a clean title, a description, alt text for accessibility, and tags. You will receive an email when your piece is reviewed and published.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Photographs &#8212; published untouched</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>If your photograph <em>is</em> the artwork, send it to the photo address (or put <code>[Photo]</code> at the start of the subject line). It is published exactly as you sent it, with no AI changes to the image.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Your biography</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Email your artist statement to the biography address. The words you write are your statement &#8212; AI only tidies the formatting.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Events</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Send an event announcement to the events address and include the date and place in your message. The date is shown on your event page.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Updating or removing a piece</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Send a new version to replace a piece, or use the remove address to take one down. You can also leave the community at any time and take all of your content with you.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Write in your own language</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Write to us in whatever language you are comfortable with. Your words are published in the community&#8217;s main language and translated automatically, so your work reads naturally for everyone.</p><!-- /wp:paragraph -->',
		] );
	}

	/**
	 * Create the /join/ page the first time the plugin activates.
	 *
	 * Uses the agnosis/join block as page content. The theme's page-join.html
	 * template is automatically applied via the FSE template hierarchy (WordPress
	 * selects page-{slug}.html for any page whose slug matches). The page ID is
	 * stored in wp_options so subsequent activations are no-ops.
	 */
	private static function create_join_page(): void {
		$existing_id = (int) get_option( 'agnosis_join_page_id' );

		if ( $existing_id ) {
			$existing = get_post( $existing_id );
			if ( $existing && 'publish' === $existing->post_status ) {
				return;
			}
		}

		$page_id = wp_insert_post( [
			'post_title'   => __( 'Join', 'agnosis' ),
			'post_name'    => 'join',
			'post_content' => '<!-- wp:agnosis/join /-->',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => 1,
			'meta_input'   => [ '_agnosis_managed_page' => '1' ],
		], false );

		if ( $page_id ) {
			update_option( 'agnosis_join_page_id', $page_id );
		}
	}

	private static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'agnosis_poll_inbox' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'agnosis_poll_inbox' );
		}
		if ( ! wp_next_scheduled( 'agnosis_check_admissions' ) ) {
			wp_schedule_event( time(), 'daily', 'agnosis_check_admissions' );
		}
		if ( ! wp_next_scheduled( 'agnosis_check_bans' ) ) {
			wp_schedule_event( time(), 'daily', 'agnosis_check_bans' );
		}
		if ( ! wp_next_scheduled( 'agnosis_check_removal_votes' ) ) {
			wp_schedule_event( time(), 'daily', 'agnosis_check_removal_votes' );
		}
		if ( ! wp_next_scheduled( 'agnosis_check_cap_votes' ) ) {
			wp_schedule_event( time(), 'daily', 'agnosis_check_cap_votes' );
		}
		// The cron_schedules filter that defines 'every_five_minutes' must be
		// registered on every request, not just on activation. It lives in
		// Inbox::register_interval() and is wired via Plugin::register_services().
	}
}
