<?php
/**
 * Contact messages admin page.
 *
 * Submenu under the top-level Agnosis menu (mirrors InboxPage's own
 * registration) listing every row Artist\ContactForm::store() has written to
 * {$wpdb->prefix}agnosis_contact_messages — both sent and rejected, since
 * that class stores every submission, not just the ones it blocked (see
 * ContactForm's own class docblock for why). Gives an admin the audit trail
 * the feature's "reject silently, but store to be able to track" requirement
 * called for, rather than leaving rejected messages invisible in the
 * database with no UI to review them.
 *
 * Deliberately read-mostly: the only mutation this page offers is deleting a
 * row (same "always available, clears clutter" convention InboxPage's own
 * row actions use) — there is no "resend"/"approve" action, since a rejected
 * message was never sent and re-sending it after the fact would bypass the
 * whole point of having reviewed it moderated in the first place. An admin
 * who disagrees with a rejection can always just email the artist directly.
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

class ContactMessagesPage {

	private const PAGE = 'agnosis-contact-messages';

	/** Mirrors InboxPage::PER_PAGE's own reasoning — bounded so one page can't grow unbounded. */
	private const PER_PAGE = 50;

	/** Real ENUM values of agnosis_contact_messages.status. */
	private const STATUSES = [ 'sent', 'rejected' ];

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	public function register_menu(): void {
		add_submenu_page(
			'agnosis',
			__( 'Contact Messages', 'agnosis' ),
			__( 'Contact Messages', 'agnosis' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filter/pagination, no state mutation; capability already checked above.
		$status_filter = isset( $_GET['status_filter'] ) ? sanitize_key( wp_unslash( $_GET['status_filter'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- int cast makes wp_unslash() redundant (magic-quote slashing never affects digits); cast sits directly against the variable so WPCS's is_safe_casted() check recognizes it.
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		if ( ! in_array( $status_filter, self::STATUSES, true ) ) {
			$status_filter = '';
		}

		$result      = $this->fetch_rows( $status_filter, $paged );
		$rows        = $result['rows'];
		$total_pages = (int) ceil( $result['total'] / self::PER_PAGE );

		?>
		<div class="wrap agnosis-contact-messages">
			<h1>
				<span style="color:#7c6af7">✦</span>
				<?php esc_html_e( 'Contact Messages', 'agnosis' ); ?>
			</h1>

			<?php $this->render_filters( $status_filter ); ?>

			<?php if ( empty( $rows ) ) : ?>
				<p style="margin-top:2rem;color:#666;">
					<?php
					echo esc_html(
						'' === $status_filter
							? __( 'No contact messages yet.', 'agnosis' )
							: __( 'No messages match this filter.', 'agnosis' )
					);
					?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped agnosis-contact-messages-table">
					<thead>
						<tr>
							<th style="width:3.5rem"><?php esc_html_e( '#', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Artist', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'From', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Message', 'agnosis' ); ?></th>
							<th style="width:8rem"><?php esc_html_e( 'Status', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Received', 'agnosis' ); ?></th>
							<th style="width:6rem"><?php esc_html_e( 'Actions', 'agnosis' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$id          = (int) $row->id;
							$artist_id   = (int) $row->artist_id;
							$artist      = $artist_id ? get_userdata( $artist_id ) : null;
							$artist_name = $artist ? $artist->display_name : sprintf(
								/* translators: %d: WP user ID of a no-longer-existing artist account */
								__( '(deleted artist #%d)', 'agnosis' ),
								$artist_id
							);
							$visitor_name  = $row->visitor_name ?: '';
							$visitor_email = $row->visitor_email;
							$message       = $row->message;
							$status        = $row->status;
							$reason        = $row->rejection_reason ?: '';
							$created       = $row->created_at;
							?>
							<tr>
								<td><code style="font-size:11px"><?php echo esc_html( (string) $id ); ?></code></td>
								<td><?php echo esc_html( $artist_name ); ?></td>
								<td>
									<?php if ( '' !== $visitor_name ) : ?>
										<?php echo esc_html( $visitor_name ); ?><br>
									<?php endif; ?>
									<span style="color:#666"><?php echo esc_html( $visitor_email ); ?></span>
								</td>
								<td>
									<span title="<?php echo esc_attr( $message ); ?>">
										<?php echo esc_html( mb_substr( $message, 0, 100 ) . ( mb_strlen( $message ) > 100 ? '…' : '' ) ); ?>
									</span>
								</td>
								<td><?php $this->render_status_badge( $status, $reason ); ?></td>
								<td>
									<span title="<?php echo esc_attr( $created ); ?>">
										<?php echo esc_html( $this->human_date( $created ) ); ?>
									</span>
								</td>
								<td><?php $this->render_row_actions( $id ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php $this->render_pagination( $paged, $total_pages, $status_filter ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** admin_post handler — delete a single contact-message row. */
	public function handle_delete_message(): void {
		check_admin_referer( 'agnosis_delete_contact_message' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$message_id = isset( $_POST['message_id'] ) ? absint( wp_unslash( $_POST['message_id'] ) ) : 0;

		if ( $message_id > 0 ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->prefix . 'agnosis_contact_messages', [ 'id' => $message_id ], [ '%d' ] );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'deleted' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- integer flag from our own redirect, display only.
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Message deleted.', 'agnosis' )
				. '</p></div>';
		}
	}

	/**
	 * Fetch one page of contact-message rows plus the total matching row
	 * count — same fetch_rows()/count-then-paginate shape as InboxPage's own
	 * method, scaled down (one filter, not two).
	 *
	 * @return array{rows: array<int, object{id: string, artist_id: string, visitor_name: string|null, visitor_email: string, message: string, translated_message: string|null, status: string, rejection_reason: string|null, ip: string|null, created_at: string}>, total: int}
	 */
	protected function fetch_rows( string $status_filter, int $paged ): array {
		global $wpdb;

		$where  = [];
		$params = [];

		if ( '' !== $status_filter ) {
			$where[]  = 'status = %s';
			$params[] = $status_filter;
		}

		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		if ( $params ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where_sql is built entirely from this method's own hardcoded '%s'-placeholder fragment above, never raw input; the actual value is passed through $wpdb->prepare()'s $params array below.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_contact_messages {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where_sql is built entirely from this method's own %s placeholder, never raw input; phpcs can't see the placeholder inside the interpolated variable.
					$params
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no filter applied; nothing to prepare.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_contact_messages" );
		}

		$offset = ( max( 1, $paged ) - 1 ) * self::PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- same $where_sql composition as the COUNT query above.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql's %s placeholder isn't visible to phpcs's static count.
			$wpdb->prepare(
				'SELECT id, artist_id, visitor_name, visitor_email, message, translated_message, status, rejection_reason, ip, created_at
				 FROM ' . $wpdb->prefix . 'agnosis_contact_messages '
				. $where_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql is built entirely from this method's own %s placeholder, never raw input.
				. ' ORDER BY id DESC
				 LIMIT %d OFFSET %d',
				array_merge( $params, [ self::PER_PAGE, $offset ] )
			)
		);

		return [ 'rows' => $rows, 'total' => $total ];
	}

	private function render_filters( string $status_filter ): void {
		$status_labels = [
			''         => __( 'All statuses', 'agnosis' ),
			'sent'     => __( 'Sent', 'agnosis' ),
			'rejected' => __( 'Rejected', 'agnosis' ),
		];
		?>
		<div class="tablenav top" style="margin:0 0 1rem">
			<div class="alignleft actions">
				<form method="get" style="display:inline-flex;gap:.5rem;align-items:center">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
					<select name="status_filter">
						<?php foreach ( $status_labels as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status_filter, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'agnosis' ); ?></button>
					<?php if ( '' !== $status_filter ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Reset', 'agnosis' ); ?></a>
					<?php endif; ?>
				</form>
			</div>
		</div>
		<?php
	}

	/** Prev/Next pager — same shape as InboxPage::render_pagination(). */
	private function render_pagination( int $current_page, int $total_pages, string $status_filter ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		$base_args = array_filter( [
			'page'          => self::PAGE,
			'status_filter' => $status_filter,
		] );
		?>
		<div class="tablenav-pages" style="margin-top:1rem">
			<span class="displaying-num">
				<?php
				printf(
					/* translators: 1: current page number, 2: total number of pages */
					esc_html__( 'Page %1$d of %2$d', 'agnosis' ),
					(int) $current_page,
					(int) $total_pages
				);
				?>
			</span>
			<span style="margin-left:.75rem">
				<?php if ( $current_page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, [ 'paged' => $current_page - 1 ] ), admin_url( 'admin.php' ) ) ); ?>">&larr; <?php esc_html_e( 'Previous', 'agnosis' ); ?></a>
				<?php endif; ?>
				<?php if ( $current_page < $total_pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, [ 'paged' => $current_page + 1 ] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'agnosis' ); ?> &rarr;</a>
				<?php endif; ?>
			</span>
		</div>
		<?php
	}

	private function render_status_badge( string $status, string $reason ): void {
		if ( 'sent' === $status ) {
			$b = [ 'bg' => '#d1fae5', 'color' => '#065f46', 'label' => __( 'Sent', 'agnosis' ) ];
		} else {
			$b = [ 'bg' => '#fee2e2', 'color' => '#991b1b', 'label' => __( 'Rejected', 'agnosis' ) ];
		}

		printf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;background:%s;color:%s" title="%s">%s</span>',
			esc_attr( $b['bg'] ),
			esc_attr( $b['color'] ),
			esc_attr( $reason ),
			esc_html( $b['label'] )
		);
	}

	private function render_row_actions( int $id ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			  onsubmit="return confirm('<?php echo esc_js( __( 'Delete this message?', 'agnosis' ) ); ?>')">
			<?php wp_nonce_field( 'agnosis_delete_contact_message' ); ?>
			<input type="hidden" name="action"     value="agnosis_delete_contact_message">
			<input type="hidden" name="message_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<button type="submit" class="button button-small" style="color:#b00;border-color:#b00">
				<?php esc_html_e( 'Delete', 'agnosis' ); ?>
			</button>
		</form>
		<?php
	}

	/** Mirrors InboxPage::human_date() exactly. */
	private function human_date( string $datetime ): string {
		$ts  = strtotime( $datetime );
		$age = time() - (int) $ts;

		if ( $age < 60 ) {
			return __( 'just now', 'agnosis' );
		}
		if ( $age < 3600 ) {
			$m = (int) round( $age / 60 );
			/* translators: %d: number of minutes */
			return sprintf( __( '%dm ago', 'agnosis' ), $m );
		}
		if ( $age < 86400 ) {
			$h = (int) round( $age / 3600 );
			/* translators: %d: number of hours */
			return sprintf( __( '%dh ago', 'agnosis' ), $h );
		}
		return wp_date( 'M j', (int) $ts ) ?: $datetime;
	}
}
