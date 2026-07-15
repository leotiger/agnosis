<?php
/**
 * Agnosis uninstall — remove the plugin's infrastructure footprint.
 *
 * Runs only when the plugin is *deleted* (not on deactivate). It removes
 * everything Agnosis creates that the operator would not expect to linger:
 *
 *   • the 17 custom tables (queue, applications, contact_messages, vouches,
 *     application_vouches, nodes, transactions, removal_requests,
 *     removal_votes, cap_proposals, cap_votes, log, newsletter_subscribers,
 *     newsletter_issues, newsletter_queue, followers, ap_delivery_queue);
 *   • every `agnosis_*` option and transient;
 *   • the `agnosis_artist` role (its capabilities go with it);
 *   • the managed pages (/join/, My Submissions);
 *   • all scheduled cron events;
 *   • the uploads/agnosis-queue/ working directory;
 *   • the wp-content/agnosis-debug/ directory (raw pipeline-tracing dumps —
 *     contain artists' full raw emails when Settings → General → "Enable
 *     debug logging" was ever turned on; fourth audit §5c).
 *
 * Deliberately PRESERVED — this is the artists'/operator's content, not the
 * plugin's to destroy on delete:
 *
 *   • published artwork / biography / event posts and their media attachments;
 *   • WordPress user accounts (removing the role drops its caps; the accounts stay).
 *
 * Multisite-aware: on a network the teardown runs for every site.
 *
 * @package Agnosis
 */

// Only ever run in WordPress's uninstall context.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove all Agnosis infrastructure for the current site.
 *
 * @return void
 */
function agnosis_uninstall_site(): void {
	global $wpdb;

	// 1. Custom tables.
	$tables = [
		'agnosis_queue',
		'agnosis_applications',
		'agnosis_contact_messages',
		'agnosis_application_vouches',
		'agnosis_vouches',
		'agnosis_nodes',
		'agnosis_transactions',
		'agnosis_removal_requests',
		'agnosis_removal_votes',
		'agnosis_cap_proposals',
		'agnosis_cap_votes',
		'agnosis_log',
		'agnosis_newsletter_subscribers',
		'agnosis_newsletter_issues',
		'agnosis_newsletter_queue',
		'agnosis_followers',
		'agnosis_ap_delivery_queue',
	];

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a hardcoded literal; DROP TABLE cannot use placeholders.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
	}

	// 2. Options + transients — every agnosis_* key.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk option cleanup on uninstall; LIKE patterns are literals.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE 'agnosis\\_%'
		    OR option_name LIKE '\\_transient\\_agnosis\\_%'
		    OR option_name LIKE '\\_transient\\_timeout\\_agnosis\\_%'"
	);

	// 3. Managed pages (/join/, My Submissions) — keyed by _agnosis_managed_page.
	$managed_pages = get_posts(
		[
			'post_type'        => 'page',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'meta_key'         => '_agnosis_managed_page', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off uninstall query.
			'meta_value'       => '1',                      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-off uninstall query.
			'suppress_filters' => true,
		]
	);

	foreach ( $managed_pages as $page_id ) {
		wp_delete_post( (int) $page_id, true );
	}

	// 4. Role (drops its capabilities; existing user accounts are kept).
	remove_role( 'agnosis_artist' );

	// 5. Scheduled cron events.
	$cron_hooks = [
		'agnosis_poll_inbox',
		'agnosis_cleanup_inbox',
		'agnosis_check_admissions',
		'agnosis_check_bans',
		'agnosis_check_removal_votes',
		'agnosis_check_cap_votes',
		'agnosis_publish_submission',
		'agnosis_dispatch_lf_translations',
		'agnosis_prepare_newsletters',
		'agnosis_send_newsletter_queue',
		'agnosis_ap_retry_deliveries',
	];

	foreach ( $cron_hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// 6. Artist usermeta — every _agnosis_* key (audit §2c). Not covered by
	// the options cleanup above (step 2) because it lives in wp_usermeta,
	// not wp_options. A prefix-wide delete, not a single named key: this
	// plugin writes six of these (_agnosis_newsletter_optout,
	// _agnosis_bounce_count, _agnosis_bounce_last_at,
	// _agnosis_broadcast_optout, _agnosis_contact_optout,
	// _agnosis_vote_email_mode — see Artist\NotificationPreferences and
	// Email\BounceHandler), and all six share this prefix, so a prefix wipe
	// is exactly the right scope rather than naming each one and risking a
	// future addition being missed the same way five of these six were.
	// User accounts themselves are deliberately preserved (see this file's
	// own header) — only the plugin's own meta on those accounts goes.
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_agnosis\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk usermeta cleanup on uninstall; LIKE pattern is a literal.

	// 7. Uploads working directory (uploads/agnosis-queue/).
	$upload = wp_upload_dir();

	if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
		$queue_dir = trailingslashit( $upload['basedir'] ) . 'agnosis-queue';

		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;
		if ( WP_Filesystem() && $wp_filesystem->is_dir( $queue_dir ) ) {
			$wp_filesystem->delete( $queue_dir, true ); // recursive.
		}
	}

	// 8. Debug-tracing directory (wp-content/agnosis-debug/) — fourth audit §5c.
	// Deliberately outside uploads/ (see Core\Debug::dir()'s own docblock), so
	// it isn't touched by step 7 above; can hold raw pipeline dumps, including
	// artists' full raw emails, whenever debug logging was ever turned on.
	// Not routed through Core\Debug::dir() itself — uninstall.php intentionally
	// loads none of the plugin's classes, matching this file's existing
	// dependency-free style — so a site that filtered `agnosis_debug_dir` to a
	// non-default location (via a still-active mu-plugin) would need that
	// filter to still be registered for this step to find it; the default
	// location is what's covered here, same as step 7 covers only the default
	// uploads-based queue path.
	$debug_dir = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/agnosis-debug';

	require_once ABSPATH . 'wp-admin/includes/file.php';

	global $wp_filesystem;
	if ( WP_Filesystem() && $wp_filesystem->is_dir( $debug_dir ) ) {
		$wp_filesystem->delete( $debug_dir, true ); // recursive.
	}
}

/**
 * Run the teardown for every site on a network, or just the current site.
 *
 * Wrapped in a function so its loop variables are not flagged as un-prefixed
 * plugin globals at file scope.
 *
 * @return void
 */
function agnosis_uninstall_run(): void {
	if ( is_multisite() ) {
		$site_ids = get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			agnosis_uninstall_site();
			restore_current_blog();
		}
	} else {
		agnosis_uninstall_site();
	}
}

agnosis_uninstall_run();
