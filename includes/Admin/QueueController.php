<?php
/**
 * Admin POST handlers for queue management.
 *
 * Handles all admin-post actions that operate on the submission queue:
 * polling the IMAP inbox, processing items, force-reprocessing, and deletion.
 * Each handler validates the nonce and capability before acting, then
 * redirects back to the Inbox admin page.
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

use Agnosis\Email\Inbox;
use Agnosis\Publishing\PostCreator;

class QueueController {

	// -------------------------------------------------------------------------
	// admin_post handlers
	// -------------------------------------------------------------------------

	/** admin_post handler — immediately poll the IMAP inbox for new messages. */
	public function handle_poll_now(): void {
		check_admin_referer( 'agnosis_poll_now' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;

		// Snapshot queue size before poll so we can report how many were added.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin action; real-time count of custom table.
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue" );

		$inbox = new Inbox();
		$inbox->poll();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue" );

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis', 'polled' => max( 0, $after - $before ) ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** admin_post handler — process a single queue row immediately. */
	public function handle_process_one(): void {
		check_admin_referer( 'agnosis_process_one' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$queue_id = isset( $_POST['queue_id'] ) ? absint( wp_unslash( $_POST['queue_id'] ) ) : 0;

		if ( $queue_id > 0 ) {
			// Reset status to 'pending' so handle() picks it up (it checks for pending rows).
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'agnosis_queue',
				[ 'status' => 'pending', 'error' => null ],
				[ 'id' => $queue_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);

			$publisher = new PostCreator();
			$publisher->handle( $queue_id );
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis', 'processed_one' => '1', 'queue_id' => $queue_id ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** admin_post handler — delete a single queue row. */
	public function handle_delete_queue_row(): void {
		check_admin_referer( 'agnosis_delete_queue_row' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$queue_id = isset( $_POST['queue_id'] ) ? absint( wp_unslash( $_POST['queue_id'] ) ) : 0;

		if ( $queue_id > 0 ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->prefix . 'agnosis_queue', [ 'id' => $queue_id ], [ '%d' ] );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => 'agnosis', 'deleted' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** admin_post handler — force-reprocess the IMAP inbox. */
	public function handle_force_reprocess(): void {
		check_admin_referer( 'agnosis_force_reprocess' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;

		$inbox = new Inbox();

		// 1. Reset queue rows + clear IMAP \Seen flags.
		$imap_count = $inbox->force_reprocess();

		// 2. Immediately poll so the now-UNSEEN messages are enqueued.
		//    Snapshot queue size before/after to report how many were added.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue WHERE status = 'pending'" );
		$inbox->poll();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$after    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue WHERE status = 'pending'" );
		$enqueued = max( 0, $after - $before );

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis', 'reprocessed' => $imap_count, 'enqueued' => $enqueued ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** admin_post handler — synchronously process all pending queue items. */
	public function handle_process_queue(): void {
		check_admin_referer( 'agnosis_process_queue' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin action; real-time read of custom table.
		$ids = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}agnosis_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 20"
		);

		$publisher = new PostCreator();
		foreach ( $ids as $id ) {
			$publisher->handle( (int) $id );
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis', 'processed' => count( $ids ) ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
