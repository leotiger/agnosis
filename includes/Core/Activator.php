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

		// Extend agnosis_queue.status ENUM to include 'skipped' (0.9.7). Needed for
		// two independent reasons that both landed at the same time: (1) an intake-
		// gate skip that represents a genuine success (e.g. a goodbye@ self-removal
		// request) was previously forced into 'failed', showing a false "Failed"
		// badge in the Inbox admin table; (2) PostCreator::handle() already called
		// $this->mark($queue_id, 'skipped') when an artist's admission status changed
		// between enqueue and processing, but 'skipped' was never a valid ENUM value
		// for this column — that write silently failed (or was coerced to '' under
		// non-strict SQL modes). dbDelta cannot modify ENUMs, so — same as the
		// agnosis_applications.status extensions below — this is done via ALTER TABLE.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$queue_status_col = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_queue LIKE 'status'" );
		if ( $queue_status_col && false === strpos( (string) $queue_status_col->Type, 'skipped' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_queue MODIFY COLUMN status ENUM('pending','processing','published','failed','skipped') NOT NULL DEFAULT 'pending'" );
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

			// Double opt-in on applications (0.9.16 — security audit §3a/§4a): an
			// applicant must prove control of the email address before apply()
			// triggers the acknowledgment email + community vote blast. Add the
			// single-use confirm_token column and extend status to include
			// 'unverified', the parking state a row sits in between apply() and
			// the artist clicking the confirmation link (Admission::confirm_application()).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_confirm_token = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_applications LIKE 'confirm_token'" );
			if ( empty( $has_confirm_token ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_applications ADD COLUMN confirm_token VARCHAR(64) DEFAULT NULL AFTER removal_token" );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_applications ADD UNIQUE KEY uq_confirm_token (confirm_token)" );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$status_col_uv = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_applications LIKE 'status'" );
			if ( $status_col_uv && false === strpos( (string) $status_col_uv->Type, 'unverified' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_applications MODIFY COLUMN status ENUM('unverified','pending','admitted','rejected','withdrawn','left','banned','waitlisted') NOT NULL DEFAULT 'unverified'" );
			}
		}

		// Add attempts to agnosis_newsletter_queue if missing (column added in 0.4.3,
		// bounded send-retry — see security audit §3d).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$queue_table_exists = $wpdb->get_results( "SHOW TABLES LIKE '{$wpdb->prefix}agnosis_newsletter_queue'" );
		if ( ! empty( $queue_table_exists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_attempts = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_newsletter_queue LIKE 'attempts'" );
			if ( empty( $has_attempts ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_newsletter_queue ADD COLUMN attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER status" );
			}

			// Rename sent_at to resolved_at on existing installs (renamed in 0.4.3
			// — "sent_at" was a misleading name for a column also stamped on a
			// terminal failure, not just a real send; see security audit §3f).
			// dbDelta() can't rename columns (it would just add resolved_at
			// alongside the untouched old one), so this needs an explicit ALTER.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_resolved_at = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_newsletter_queue LIKE 'resolved_at'" );
			if ( empty( $has_resolved_at ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$has_old_sent_at = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_newsletter_queue LIKE 'sent_at'" );
				if ( ! empty( $has_old_sent_at ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
					$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_newsletter_queue CHANGE COLUMN sent_at resolved_at DATETIME DEFAULT NULL" );
				} else {
					// Table exists but predates both names somehow — add fresh.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
					$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_newsletter_queue ADD COLUMN resolved_at DATETIME DEFAULT NULL" );
				}
			}
		}

		// Shorten agnosis_newsletter_subscribers.email from VARCHAR(255) to
		// VARCHAR(191) on existing installs (security audit §3f/§2d) — a 255-char
		// UNIQUE index under utf8mb4 exceeds the 767-byte limit on older
		// MariaDB/MyISAM configs; 191 stays safely under it everywhere. Only
		// runs if the column is still at its old, wider definition.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$subscribers_table_exists = $wpdb->get_results( "SHOW TABLES LIKE '{$wpdb->prefix}agnosis_newsletter_subscribers'" );
		if ( ! empty( $subscribers_table_exists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$email_col = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_newsletter_subscribers LIKE 'email'" );
			if ( $email_col && false !== strpos( (string) $email_col->Type, 'varchar(255)' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_newsletter_subscribers MODIFY COLUMN email VARCHAR(191) NOT NULL" );
			}

			// Bounce/complaint suppression (security audit §5a): a subscriber whose
			// address hard-bounces or files a spam complaint is flipped to
			// 'bounced' instead of being mailed forever — see
			// Newsletter\Subscriber::suppress() and Email\BounceHandler::record().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_bounced_at = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_newsletter_subscribers LIKE 'bounced_at'" );
			if ( empty( $has_bounced_at ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_newsletter_subscribers ADD COLUMN bounced_at DATETIME DEFAULT NULL AFTER unsubscribed_at" );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$sub_status_col = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_newsletter_subscribers LIKE 'status'" );
			if ( $sub_status_col && false === strpos( (string) $sub_status_col->Type, 'bounced' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_newsletter_subscribers MODIFY COLUMN status ENUM('pending','confirmed','unsubscribed','bounced') NOT NULL DEFAULT 'pending'" );
			}
		}

		// Finish the upgrade with idempotent provisioning so a version bump fully
		// applies: new tables (dbDelta), new default options, the role, all
		// scheduled events (incl. agnosis_check_cap_votes), and the default
		// agnosis_medium terms. Each step is a no-op when its target already
		// exists. Managed-page creation needs $wp_rewrite (permalinks), which is
		// NOT ready on plugins_loaded, so it is deferred to init in the same
		// request.
		//
		// seed_medium_terms() belongs in this list — its own docblock has always
		// said "safe to call on every activation or upgrade" — but was only ever
		// wired into activate() below. On any install that was already active
		// before the agnosis_medium taxonomy shipped, activate() never runs
		// again, so the 8 default terms never appeared even though this method
		// (and the version-aware maybe_upgrade() gate itself) has been sitting
		// right here the whole time.
		self::create_tables();
		self::seed_options();
		self::register_roles();
		self::schedule_events();
		self::seed_medium_terms();
		add_action( 'init', [ self::class, 'create_managed_pages' ], 99 );

		// New rewrite rules (e.g. Newsletter\Archive's /newsletter/ routes,
		// added 2026-07-06) only take effect on an existing install once
		// WordPress's rewrite rules are regenerated. Flushing on every
		// plugins_loaded would be needlessly expensive, so — like
		// create_managed_pages() above — this is deferred to run once on
		// init, after the plugin's own add_rewrite_rule() calls (registered
		// via Loader at the default init priority) have run.
		add_action( 'init', 'flush_rewrite_rules', 100 );
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
		wp_clear_scheduled_hook( 'agnosis_vote_digest' );
		wp_clear_scheduled_hook( 'agnosis_prepare_newsletters' );
		wp_clear_scheduled_hook( 'agnosis_send_newsletter_queue' );
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
			status       ENUM('pending','processing','published','failed','skipped') NOT NULL DEFAULT 'pending',
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
		// status: unverified → pending | waitlisted → admitted | rejected | withdrawn | left | banned.
		// 'unverified' (0.9.16, security audit §3a/§4a) is the double opt-in parking
		// state: apply() writes the row here and emails only a confirm-your-application
		// link — the acknowledgment + community vote blast never fire until
		// Admission::confirm_application() flips it to pending/waitlisted.
		// banned_until: NULL = permanent ban; future datetime = temporary ban (cron reinstates).
		// removal_token: single-use CSPRNG hex token for self-removal email confirmation.
		// confirm_token: single-use CSPRNG hex token for the double opt-in confirmation link; cleared on confirm.
		// resolved_at is set whenever status leaves 'pending'.
		$sql_applications = "CREATE TABLE {$wpdb->prefix}agnosis_applications (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email          VARCHAR(255)    NOT NULL,
			display_name   VARCHAR(255)    NOT NULL,
			bio            TEXT            DEFAULT NULL,
			portfolio_url  VARCHAR(512)    DEFAULT NULL,
			statement      TEXT            DEFAULT NULL,
			language       VARCHAR(10)     DEFAULT NULL,
			status         ENUM('unverified','pending','admitted','rejected','withdrawn','left','banned','waitlisted') NOT NULL DEFAULT 'unverified',
			wp_user_id     BIGINT UNSIGNED DEFAULT NULL,
			banned_until   DATETIME        DEFAULT NULL,
			removal_token  VARCHAR(64)     DEFAULT NULL,
			confirm_token  VARCHAR(64)     DEFAULT NULL,
			applied_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			resolved_at    DATETIME        DEFAULT NULL,
			PRIMARY KEY    (id),
			UNIQUE KEY     uq_email (email),
			UNIQUE KEY     uq_removal_token (removal_token),
			UNIQUE KEY     uq_confirm_token (confirm_token),
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

		// Public newsletter subscribers (double opt-in). Admitted artists are NOT
		// stored here — the artist newsletter audience is derived live from
		// WP_User_Query (role agnosis_artist) minus the _agnosis_newsletter_optout
		// user meta flag, so there is never a second, driftable copy of who is a member.
		// email is VARCHAR(191), not 255: under utf8mb4 (4 bytes/char), a 255-char
		// UNIQUE index needs 1020 bytes, over the 767-byte limit on older
		// MariaDB/MyISAM configs; 191 (WordPress core's own long-standing
		// convention for indexed email/login columns) stays safely under it in
		// every configuration — see security audit §3f/§2d.
		$sql_newsletter_subscribers = "CREATE TABLE {$wpdb->prefix}agnosis_newsletter_subscribers (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email           VARCHAR(191)    NOT NULL,
			status          ENUM('pending','confirmed','unsubscribed','bounced') NOT NULL DEFAULT 'pending',
			token           VARCHAR(64)     NOT NULL,
			locale          VARCHAR(10)     DEFAULT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			confirmed_at    DATETIME        DEFAULT NULL,
			unsubscribed_at DATETIME        DEFAULT NULL,
			bounced_at      DATETIME        DEFAULT NULL,
			PRIMARY KEY     (id),
			UNIQUE KEY      uq_email (email),
			UNIQUE KEY      uq_token (token),
			KEY             idx_status (status)
		) $charset_collate;";

		// One row per newsletter send ("issue"). intro/digest_html hold the
		// site-default-locale rendering (used as the base/fallback render, and by
		// the "Send Test" preview). locale_content holds a JSON map of
		// locale => {intro, digest_html} for every other locale actually present
		// among this issue's recipients, rendered once per locale at prepare time
		// (not per recipient) and reused for everyone sharing that locale, so what
		// was queued is exactly what goes out even if posts change while sending
		// is in progress.
		$sql_newsletter_issues = "CREATE TABLE {$wpdb->prefix}agnosis_newsletter_issues (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			newsletter_type ENUM('artist','public') NOT NULL,
			status          ENUM('draft','sending','sent') NOT NULL DEFAULT 'draft',
			intro           TEXT            DEFAULT NULL,
			digest_html     LONGTEXT        DEFAULT NULL,
			locale_content  LONGTEXT        DEFAULT NULL,
			recipient_count INT UNSIGNED    NOT NULL DEFAULT 0,
			scheduled_at    DATETIME        DEFAULT NULL,
			sent_at         DATETIME        DEFAULT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY     (id),
			KEY             idx_type_status (newsletter_type, status)
		) $charset_collate;";

		// Per-recipient send queue for a single issue. Processed in small batches by
		// the agnosis_send_newsletter_queue cron tick so a 200-300 recipient send
		// never blocks a single request or trips a host's outbound mail rate limit.
		// locale is the recipient's resolved send-locale (their own locale, or the
		// site default when none is known) — set once at fan-out time so send-time
		// localization never needs a repeat per-recipient DB/meta lookup.
		// attempts counts failed wp_mail() calls; a row stays 'pending' (retried on
		// a later tick) until QueueProcessor::MAX_ATTEMPTS is reached, then flips to
		// 'failed' for good — see security audit §3d.
		// resolved_at (named sent_at before 0.4.3) is stamped for BOTH a real send
		// and a terminal failure — "sent_at" was a misleading name for a failure
		// timestamp; see security audit §3f.
		$sql_newsletter_queue = "CREATE TABLE {$wpdb->prefix}agnosis_newsletter_queue (
			id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			issue_id          BIGINT UNSIGNED NOT NULL,
			recipient_id      BIGINT UNSIGNED DEFAULT NULL,
			recipient_email   VARCHAR(255)    NOT NULL,
			recipient_type    ENUM('artist','public') NOT NULL,
			unsubscribe_token VARCHAR(64)     NOT NULL,
			locale            VARCHAR(10)     DEFAULT NULL,
			status            ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
			attempts          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			resolved_at       DATETIME        DEFAULT NULL,
			PRIMARY KEY       (id),
			KEY               idx_issue (issue_id),
			KEY               idx_status (status)
		) $charset_collate;";

		// Contact-form submissions — a visitor's message to an artist, sent through
		// the breadcrumb contact popover (Artist\ContactForm). Every submission is
		// stored, accepted or not, so an admin has a real audit trail (not just the
		// rejected ones) — see that class's own docblock for the full flow.
		// message is the visitor's own words, exactly as submitted, in whatever
		// language they wrote it; translated_message is the AI translation into the
		// artist's own language actually emailed to them (empty when rejected, or
		// when the artist's language couldn't be resolved and the original was sent
		// as-is). rejection_reason is empty for a sent message.
		$sql_contact_messages = "CREATE TABLE {$wpdb->prefix}agnosis_contact_messages (
			id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			artist_id          BIGINT UNSIGNED NOT NULL,
			visitor_name       VARCHAR(255)    DEFAULT NULL,
			visitor_email      VARCHAR(255)    NOT NULL,
			message            TEXT            NOT NULL,
			translated_message TEXT            DEFAULT NULL,
			status             ENUM('sent','rejected') NOT NULL DEFAULT 'sent',
			rejection_reason   VARCHAR(255)    DEFAULT NULL,
			ip                 VARCHAR(45)     DEFAULT NULL,
			created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY        (id),
			KEY                idx_artist (artist_id),
			KEY                idx_status (status)
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
		dbDelta( $sql_newsletter_subscribers );
		dbDelta( $sql_newsletter_issues );
		dbDelta( $sql_newsletter_queue );
		dbDelta( $sql_contact_messages );

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
			// Newsletters — self-hosted digest sending. Frequencies are in days
			// (default 30 ≈ monthly); each has its own enable flag and pending
			// intro text, set independently from Settings → Newsletter.
			// Dedicated From: address for digest mail, separate from admin_email —
			// blank falls back to the site name / admin_email at send time.
			'agnosis_newsletter_from_name'                 => '',
			'agnosis_newsletter_from_email'                => '',
			'agnosis_newsletter_artist_enabled'            => true,
			'agnosis_newsletter_artist_frequency_days'     => 30,
			'agnosis_newsletter_artist_intro'              => '',
			'agnosis_newsletter_public_enabled'            => true,
			'agnosis_newsletter_public_frequency_days'     => 30,
			'agnosis_newsletter_public_intro'              => '',
			// Recipients emailed per agnosis_send_newsletter_queue cron tick (every
			// 5 minutes) — keeps self-hosted sending under shared-host outbound caps.
			'agnosis_newsletter_batch_size'                => 20,
			// Once confirmed public subscribers exceed this count, the Newsletter
			// settings tab shows a banner suggesting an ESP; self-hosted sending
			// still works above it, this is advisory only.
			'agnosis_newsletter_subscriber_warn_threshold' => 250,
		];

		foreach ( $defaults as $key => $value ) {
			add_option( $key, $value ); // add_option skips if key already exists.
		}
	}

	/**
	 * Seed the agnosis_medium taxonomy with the default list from
	 * PromptConfig::CANONICAL_MEDIUMS.
	 *
	 * This only ever ADDS these terms if missing — it never removes, renames, or
	 * resets a term an admin has since changed under Artwork → Mediums. Once
	 * seeded, the taxonomy itself is the live vocabulary from here on: the AI
	 * prompt and PostCreator's hallucination guard both read the current term
	 * list at runtime (PromptConfig::medium_terms()), not this constant.
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
			'<!-- wp:paragraph --><p>Agnosis is a community-run home for independent artists. You email your work, AI gives it a clean presentation &#8212; a title, a description, tags, and alt text &#8212; the community decides who joins, and your art appears here and across the open social web (the &#8220;fediverse&#8221;). There are no dashboards to learn, no algorithm deciding who sees you, and no ads.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>How it works</h2><!-- /wp:heading -->',
			'<!-- wp:list --><ul><li>You email your artwork, a biography, or an event &#8212; no account or upload form needed.</li><li>AI writes the parts you leave blank: a title suggestion, a description, tags, and alt text. Your own words, wherever you give them, always come first.</li><li>Existing members vouch for newcomers; the community decides who joins.</li><li>Your work is published here and delivered to your followers on the fediverse.</li></ul><!-- /wp:list -->',
			'<!-- wp:heading --><h2>How we use AI &#8212; and what it won&#8217;t do</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Handing your work to an algorithm can feel uneasy, so here is exactly what happens, with nothing hidden.</p><!-- /wp:paragraph -->',
			'<!-- wp:list --><ul><li><strong>Writing, not judging.</strong> AI reads your email and image and writes a title suggestion, a description, tags, and factual alt text &#8212; grounded only in what you actually sent, never invented.</li><li><strong>Photo correction, only when needed.</strong> If a photograph has a technical problem &#8212; blur, poor lighting, heavy noise &#8212; that gets in the way of seeing the work clearly, AI may correct that problem alone.</li><li><strong>Never the artwork itself.</strong> Colours, textures, composition, and every artistic choice you made are preserved exactly. This is treated as a camera-and-lighting fix, not an artistic edit &#8212; and a well-photographed piece is never touched at all.</li><li><strong>Your call, always.</strong> Send through the photo-only lane at any time and your image is published exactly as sent, with no automatic correction whatsoever. The Artist Guide explains how.</li></ul><!-- /wp:list -->',
			'<!-- wp:heading --><h2>Owned by its members</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>The community admits new members, can vote to part ways with one, and even votes on how large it grows. Agnosis is a commons, not a platform &#8212; small and human by design.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Your work stays yours</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>You can leave whenever you like and take everything with you. No lock-in, ever.</p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph --><p>Curious how the email workflow actually works day to day &#8212; including sending a series of images, asking to be featured, or writing in your own language? The <a href="/artist-guide/">Artist Guide</a> walks through every step.</p><!-- /wp:paragraph -->',
		] );
	}

	/** Block markup for the "Artist Guide" help page. */
	private static function help_page_content(): string {
		return implode( "\n", [
			'<!-- wp:paragraph --><p>Working with Agnosis is as simple as sending an email &#8212; you never have to log in to a dashboard. Here is everything you need to know.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Joining</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Apply from the join page. Once enough members have vouched for you, you are welcomed in and can start sending work by email.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Sending artwork</h2><!-- /wp:heading -->',
			'<!-- wp:list --><ul><li>Attach your image (or several &#8212; see below) to an email.</li><li>Put the title of the piece in the <strong>subject line</strong> &#8212; this becomes the artwork&#8217;s title exactly as you write it.</li><li>Add a few words in the body if you like &#8212; or nothing at all.</li><li>Send it to the submission address your community shared with you.</li></ul><!-- /wp:list -->',
			'<!-- wp:paragraph --><p>AI writes a description, alt text for accessibility, and tags around what you send. If your subject line already gives a title, it is kept exactly as you wrote it &#8212; AI never overwrites it, only fills in a suggestion when you leave it blank. You&#8217;ll then get an email with a preview to look over &#8212; you decide whether to publish it or discard it. Nobody else reviews or approves your work; it only goes live once you say so.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Sending a series or set of images</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Working in a series, or want to show a piece from a few angles &#8212; a detail shot, an alternate view? Attach more than one image to the <em>same</em> email. They are published together as a single piece, shown as a gallery in the order you attached them, under the one title in your subject line.</p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph --><p>Want to add more images to something you&#8217;ve already published? Send them again to the replace address with the <em>exact same title</em> in the subject line &#8212; the new images join the gallery alongside what is already there.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Sound and video</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Work in sound or video? Attach the file to your email the same way, sent to the same address you already use for images. AI writes a title suggestion, description, tags, and alt text for it just as it would for a photograph &#8212; for video, from a frame it pulls from the file automatically when it can, or from your message alone when it can&#8217;t.</p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph --><p>The file itself is always published exactly as you sent it &#8212; audio and video are never edited, re-encoded, or otherwise altered by AI, only described. If a long or high-resolution file doesn&#8217;t arrive, your email provider or our email receiver may be capping attachment size before it ever reaches us; try a shorter clip or a more compressed export. For larger videos include a link to your preferred video platform.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>About AI &#8212; what it does, and what it never does</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>It is fair to wonder exactly what AI is allowed to touch. Here is the honest answer.</p><!-- /wp:paragraph -->',
			'<!-- wp:list --><ul><li>It writes the presentation: a title suggestion (only used if you did not give one), a description, tags, and factual alt text &#8212; always grounded in what you actually sent, never invented.</li><li>It may correct a technical photo problem &#8212; blur, poor lighting, heavy noise &#8212; but only when the photo needs it, and only enough to make the artwork easier to see.</li><li>It does not alter the artwork itself. Colours, textures, composition, and every artistic choice you made are preserved exactly &#8212; this is a camera fix, not an artistic edit.</li><li>A well-photographed piece is never touched at all.</li><li>Want zero automatic correction, always? Use the photo-only lane below &#8212; your image is published pixel-for-pixel as you sent it.</li></ul><!-- /wp:list -->',
			'<!-- wp:heading --><h2>Your title, kept in your own words</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Whatever you put in the subject line becomes your artwork&#8217;s title &#8212; exactly as written, in whatever language you wrote it in. If your community publishes in a different main language, the translated title is shown alongside yours, never in place of it.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Photographs &#8212; published untouched</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>If your photograph <em>is</em> the artwork, send it to the photo address (or put <code>[Photo]</code> at the start of the subject line). It is published exactly as you sent it, with no AI changes to the image &#8212; a deliberately dark, grainy, or low-fi photograph is your artistic choice, not a defect to fix.</p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph --><p>This lane isn&#8217;t only for photographers. Whatever medium you work in &#8212; painting, sculpture, drawing, anything &#8212; you can send it the same way whenever you simply don&#8217;t want AI touching the photograph that represents your piece. It skips automatic correction no matter what the artwork itself is; a description, tags, and alt text are still written for you as usual.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Your biography</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Email your artist statement to the biography address. The words you write are your statement &#8212; AI only tidies the formatting.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Events</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Send an event announcement to the events address and include the date and place in your message. Both are shown on your event page.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Asking to be featured</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Want a published piece highlighted on your gallery? Email the promote address with that artwork&#8217;s exact title in the subject line &#8212; no message needed. It becomes your featured piece until you feature another.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Updating, removing, or leaving</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Send a new version &#8212; or new images &#8212; to the replace address using the piece&#8217;s exact title to update it. Use the remove address to take a single piece down. You can also leave the community entirely at any time via the goodbye address and take everything you&#8217;ve published with you &#8212; no lock-in, ever.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Write in your own language</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Write to us in whatever language you are comfortable with. Your words are published in the community&#8217;s main language and translated automatically, so your work reads naturally for everyone.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading --><h2>Your own space</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Every admitted artist gets their own address on the web showing only their published work. Visit <code>/my-submissions</code> any time to check the status of everything you&#8217;ve sent &#8212; sign in there directly, with no dashboard to learn.</p><!-- /wp:paragraph -->',
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
		// Daily vote-email digest for artists in digest mode (security audit
		// §5b/§4a) — see Artist\VoteDigest's own docblock.
		if ( ! wp_next_scheduled( 'agnosis_vote_digest' ) ) {
			wp_schedule_event( time(), 'daily', 'agnosis_vote_digest' );
		}
		self::ensure_newsletter_cron_scheduled();
		// The cron_schedules filter that defines 'every_five_minutes' must be
		// registered on every request, not just on activation. It lives in
		// Inbox::register_interval() and is wired via Plugin::register_services().
	}

	/**
	 * Schedule the two newsletter cron events if either is missing, and
	 * report whether anything had to be (re)registered.
	 *
	 * Normally only called from schedule_events() (activate() / maybe_upgrade(),
	 * both version-gated — see maybe_upgrade()'s docblock). That gating is a
	 * real gap on its own: if `agnosis_send_newsletter_queue` ever gets
	 * deregistered on an already-up-to-date site for a reason that has
	 * nothing to do with a version bump — a host's cron-table reset/cleanup
	 * tool, a migration between servers, a manual `wp cron event delete`,
	 * WP-Cron silently losing an entry — nothing would ever re-register it
	 * again until the next plugin update, and the symptom is invisible
	 * ("Sending…" stuck forever with no error anywhere). Found 2026-07-06:
	 * exactly this — production had no `agnosis_send_newsletter_queue` cron
	 * event scheduled at all, `wp cron event run` failed with "Invalid cron
	 * event", and the newsletter that had been queued had never been
	 * attempted even once. Public and called unconditionally (not
	 * version-gated) from Settings::render_newsletter_dashboard() on every
	 * page view, so this specific class of silent freeze self-heals the
	 * moment an admin looks at the dashboard — the same self-healing
	 * philosophy QueueProcessor::reconcile_sending_issues() already uses for
	 * a stuck issue *status*, extended to cover a missing cron *event* too.
	 *
	 * @return bool True if either event had to be (re)scheduled.
	 */
	public static function ensure_newsletter_cron_scheduled(): bool {
		$rescheduled = false;

		// Newsletters: daily check for due issues, then a frequent small-batch send.
		// 'every_five_minutes' is registered by Inbox::register_interval(),
		// wired via Plugin::register_services() — runs on every request, so
		// it's already in place by the time this executes on 'plugins_loaded'
		// or an admin page load.
		if ( ! wp_next_scheduled( 'agnosis_prepare_newsletters' ) ) {
			wp_schedule_event( time(), 'daily', 'agnosis_prepare_newsletters' );
			$rescheduled = true;
		}
		if ( ! wp_next_scheduled( 'agnosis_send_newsletter_queue' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'agnosis_send_newsletter_queue' );
			$rescheduled = true;
		}

		return $rescheduled;
	}
}
