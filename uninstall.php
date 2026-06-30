<?php
/**
 * Agnosis uninstall — remove the plugin's infrastructure footprint.
 *
 * Runs only when the plugin is *deleted* (not on deactivate). It removes
 * everything Agnosis creates that the operator would not expect to linger:
 *
 *   • the 9 custom tables (queue, applications, vouches, application_vouches,
 *     nodes, transactions, removal_requests, removal_votes, log);
 *   • every `agnosis_*` option and transient;
 *   • the `agnosis_artist` role (its capabilities go with it);
 *   • the managed pages (/join/, My Submissions);
 *   • all scheduled cron events;
 *   • the uploads/agnosis-queue/ working directory.
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
		'agnosis_application_vouches',
		'agnosis_vouches',
		'agnosis_nodes',
		'agnosis_transactions',
		'agnosis_removal_requests',
		'agnosis_removal_votes',
		'agnosis_cap_proposals',
		'agnosis_cap_votes',
		'agnosis_log',
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
	];

	foreach ( $cron_hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// 6. Uploads working directory (uploads/agnosis-queue/).
	$upload = wp_upload_dir();

	if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
		$queue_dir = trailingslashit( $upload['basedir'] ) . 'agnosis-queue';

		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;
		if ( WP_Filesystem() && $wp_filesystem->is_dir( $queue_dir ) ) {
			$wp_filesystem->delete( $queue_dir, true ); // recursive.
		}
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
