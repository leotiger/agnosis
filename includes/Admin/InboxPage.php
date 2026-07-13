<?php
/**
 * Inbox admin page.
 *
 * Top-level Agnosis → Inbox view: shows every row in the submission queue so
 * administrators can see what arrived, what failed, and trigger processing
 * for individual messages without waiting for the cron.
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

use Agnosis\Email\Inbox;
use Agnosis\Publishing\PostCreator;

class InboxPage {

	private const PAGE = 'agnosis';

	/** Mirrors Parser::ALLOWED_MIME — used to validate stored mime types before rendering data URIs. */
	private const ALLOWED_MIME = [
		'image/jpeg', 'image/jpg', 'image/png',
		'image/webp', 'image/gif', 'image/tiff',
	];

	/**
	 * Rows per page (security/ops audit §4c). Was a flat, unpaginated
	 * `LIMIT 100` — a weekend of dictionary spam (every unregistered-sender
	 * message becomes its own 'failed' row) pushed every real event off the
	 * single page entirely, with no way to see the rest.
	 */
	private const PER_PAGE = 50;

	/** Real ENUM values of agnosis_queue.status — the "All statuses" filter option is the empty string, meaning no WHERE clause on status at all. */
	private const STATUSES = [ 'pending', 'processing', 'published', 'failed', 'skipped' ];

	/**
	 * Short, dropdown-friendly labels for the reason filter — keyed
	 * identically to Inbox::SKIP_REASONS so a selected key can be translated
	 * straight back into that map's prose for the actual `error`-column
	 * match (see fetch_rows()). Deliberately shorter than the full prose:
	 * that's meant for the Error column's tooltip, this is meant to fit in
	 * a <select> without wrapping.
	 *
	 * @var array<string, string>
	 */
	private const REASON_FILTER_LABELS = [
		'unregistered_sender'       => 'Unregistered sender',
		'not_admitted'              => 'Not an admitted artist',
		'throttled'                 => 'Rate-limited',
		'auth_failed'               => 'SPF/DKIM failed',
		'no_attachments'            => 'No valid attachment',
		'goodbye_non_artist'        => 'Goodbye — non-artist sender',
		'goodbye_handled'           => 'Member removed (goodbye)',
		'goodbye_no_membership'     => 'Goodbye — no membership found',
		'goodbye_throttled'         => 'Goodbye — rate-limited',
		'community_non_artist'      => 'Community — non-artist sender',
		'community_throttled'       => 'Community — rate-limited',
		'community_empty'           => 'Community — empty message',
		'community_auto_submitted'  => 'Community — auto-submitted',
		'community_too_long'        => 'Community — too long (bounced)',
		'community_handled'         => 'Community broadcast sent',
		'bounce_handled'            => 'Bounce/complaint processed',
		'bounce_unresolved'         => 'Bounce/complaint — address not found',
	];

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	public function register_menu(): void {
		// Top-level Agnosis entry — clicking it goes straight to the Inbox.
		add_menu_page(
			__( 'Agnosis Inbox', 'agnosis' ),
			__( 'Agnosis', 'agnosis' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render' ],
			$this->menu_icon(),
			58 // Below WooCommerce (56), above Appearance (60).
		);

		// First submenu item uses the same slug → no duplicate "Agnosis" entry.
		add_submenu_page(
			self::PAGE,
			__( 'Inbox', 'agnosis' ),
			__( 'Inbox', 'agnosis' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', $this->page_css() );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filters/pagination, no state mutation; capability already checked above.
		$status_filter = isset( $_GET['status_filter'] ) ? sanitize_key( wp_unslash( $_GET['status_filter'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reason_filter = isset( $_GET['reason_filter'] ) ? sanitize_key( wp_unslash( $_GET['reason_filter'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- int cast makes wp_unslash() redundant (magic-quote slashing never affects digits); cast sits directly against the variable so WPCS's is_safe_casted() check recognizes it.
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		// An unrecognized value from a tampered/stale URL is simply treated
		// as "no filter" rather than erroring or matching nothing.
		if ( ! in_array( $status_filter, self::STATUSES, true ) ) {
			$status_filter = '';
		}
		if ( '' !== $reason_filter && ! isset( Inbox::SKIP_REASONS[ $reason_filter ] ) ) {
			$reason_filter = '';
		}

		$result      = $this->fetch_rows( $status_filter, $reason_filter, $paged );
		$rows        = $result['rows'];
		$total_pages = (int) ceil( $result['total'] / self::PER_PAGE );

		// Spam aggregation (audit §4c): only relevant when the operator
		// hasn't already drilled into a specific reason — once they have
		// (reason_filter === 'unregistered_sender' or anything else), the
		// rows are shown directly instead of collapsed.
		$unregistered_count = '' === $reason_filter ? $this->count_unregistered_sender_rows( $status_filter ) : 0;

		?>
		<div class="wrap agnosis-inbox">
			<h1>
				<span style="color:#7c6af7">✦</span>
				<?php esc_html_e( 'Inbox', 'agnosis' ); ?>
			</h1>

			<?php $this->render_toolbar(); ?>
			<?php $this->render_filters( $status_filter, $reason_filter ); ?>
			<?php $this->render_unregistered_summary( $unregistered_count, $status_filter ); ?>

			<?php if ( empty( $rows ) ) : ?>
				<p style="margin-top:2rem;color:#666;">
					<?php
					echo esc_html(
						( '' === $status_filter && '' === $reason_filter )
							? __( 'No messages in the queue yet. Click "Poll Inbox" to fetch messages from your IMAP account.', 'agnosis' )
							: __( 'No queue rows match this filter.', 'agnosis' )
					);
					?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped agnosis-queue-table">
					<thead>
						<tr>
							<th style="width:3.5rem"><?php esc_html_e( '#', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'From', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'agnosis' ); ?></th>
							<th style="width:7rem"><?php esc_html_e( 'Endpoint', 'agnosis' ); ?></th>
							<th style="width:7rem"><?php esc_html_e( 'Files', 'agnosis' ); ?></th>
							<th style="width:8rem"><?php esc_html_e( 'Status', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Received', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Error', 'agnosis' ); ?></th>
							<th style="width:8rem"><?php esc_html_e( 'Actions', 'agnosis' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$id         = (int) $row->id;
							$data       = json_decode( $row->raw_email ?: '{}', true ) ?: [];
							$from       = $data['from'] ?? '—';
							$subject    = $data['subject'] ?? ( 'UID ' . esc_html( $row->message_uid ) );
							$attach_n   = count( $data['attachments'] ?? [] );
							$status     = $row->status;
							$skip_reason = $data['skip_reason'] ?? '';
							$error      = $row->error ?? '';
							$created    = $row->created_at;
							$artist_id  = (int) $row->artist_id;
							$artist     = $artist_id ? get_userdata( $artist_id ) : null;
							$from_label = $artist ? $artist->display_name . ' &lt;' . esc_html( $from ) . '&gt;' : esc_html( $from );

							// For 'published' queue rows, resolve the real WP post status.
							$wp_post_status = null;
							if ( 'published' === $status ) {
								$linked = get_posts( [
									'post_type'      => 'agnosis_artwork',
									'meta_key'       => '_agnosis_queue_id',
									'meta_value'     => (string) $id,
									'posts_per_page' => 1,
									'post_status'    => 'any',
									'fields'         => 'ids',
									'no_found_rows'  => true,
								] );
								if ( ! empty( $linked ) ) {
									$wp_post = get_post( (int) $linked[0] );
									$wp_post_status = $wp_post ? $wp_post->post_status : null;
								}
							}
							?>
							<tr>
								<td><code style="font-size:11px"><?php echo esc_html( (string) $id ); ?></code></td>
								<td>
									<?php echo wp_kses( $from_label, [ 'span' => [] ] ); ?>
									<?php if ( ! $artist_id ) : ?>
										<br><span style="color:#b00;font-size:11px"><?php esc_html_e( 'unregistered sender', 'agnosis' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $subject ); ?></td>
								<td><?php echo esc_html( $this->resolve_endpoint_label( $data, $skip_reason ) ); ?></td>
								<td>
									<?php
									// Build data-URIs for viewable attachments (base64-encoded rows only).
									$viewable = [];
									foreach ( $data['attachments'] ?? [] as $att ) {
										if ( ( $att['encoding'] ?? '' ) === 'base64' && ! empty( $att['data'] ) ) {
											$mime_safe = in_array( $att['mime'] ?? '', self::ALLOWED_MIME, true )
												? $att['mime']
												: 'image/jpeg';
											$viewable[] = [
												'src'      => 'data:' . $mime_safe . ';base64,' . $att['data'],
												'filename' => $att['filename'] ?? '',
											];
										}
									}
									if ( $attach_n > 0 ) :
										$payload = wp_json_encode( $viewable ) ?: '[]';
										?>
										<button type="button"
												class="agnosis-files-btn button-link"
												data-attachments="<?php echo esc_attr( $payload ); ?>">
											<span class="dashicons dashicons-format-image" style="vertical-align:middle;color:#7c6af7"></span>
											<?php echo esc_html( (string) $attach_n ); ?>
											<?php if ( empty( $viewable ) ) : ?>
												<span title="<?php esc_attr_e( 'Old row — delete and re-poll to preview', 'agnosis' ); ?>" style="color:#999;font-size:10px">⚠</span>
											<?php endif; ?>
										</button>
									<?php else : ?>
										<span style="color:#999">—</span>
									<?php endif; ?>
								</td>
								<td><?php $this->render_status_badge( $status, $wp_post_status, $skip_reason ); ?></td>
								<td>
									<span title="<?php echo esc_attr( $created ); ?>">
										<?php echo esc_html( $this->human_date( $created ) ); ?>
									</span>
								</td>
								<td>
									<?php if ( $error ) : ?>
										<span class="agnosis-error-text" title="<?php echo esc_attr( $error ); ?>">
											<?php echo esc_html( mb_substr( $error, 0, 80 ) . ( mb_strlen( $error ) > 80 ? '…' : '' ) ); ?>
										</span>
									<?php else : ?>
										<span style="color:#999">—</span>
									<?php endif; ?>
								</td>
								<td><?php $this->render_row_actions( $id, $status ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php $this->render_pagination( $paged, $total_pages, $status_filter, $reason_filter ); ?>
			<?php endif; ?>
		</div>

		<!-- Lightbox overlay -->
		<div id="agnosis-lightbox" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Attachment viewer', 'agnosis' ); ?>" style="display:none">
			<div id="agnosis-lightbox-backdrop"></div>
			<div id="agnosis-lightbox-inner">
				<button id="agnosis-lb-close" aria-label="<?php esc_attr_e( 'Close', 'agnosis' ); ?>">✕</button>
				<button id="agnosis-lb-prev" aria-label="<?php esc_attr_e( 'Previous', 'agnosis' ); ?>">&#8592;</button>
				<div id="agnosis-lb-img-wrap">
					<img id="agnosis-lb-img" src="" alt="">
					<p id="agnosis-lb-caption"></p>
				</div>
				<button id="agnosis-lb-next" aria-label="<?php esc_attr_e( 'Next', 'agnosis' ); ?>">&#8594;</button>
			</div>
		</div>

		<script>
		(function() {
			var lb        = document.getElementById('agnosis-lightbox');
			var backdrop  = document.getElementById('agnosis-lightbox-backdrop');
			var img       = document.getElementById('agnosis-lb-img');
			var caption   = document.getElementById('agnosis-lb-caption');
			var btnClose  = document.getElementById('agnosis-lb-close');
			var btnPrev   = document.getElementById('agnosis-lb-prev');
			var btnNext   = document.getElementById('agnosis-lb-next');
			var items     = [];
			var current   = 0;

			function show(index) {
				current = (index + items.length) % items.length;
				img.src = items[current].src;
				img.alt = items[current].filename;
				caption.textContent = items[current].filename
					+ (items.length > 1 ? '  ' + (current + 1) + ' / ' + items.length : '');
				btnPrev.style.display = items.length > 1 ? '' : 'none';
				btnNext.style.display = items.length > 1 ? '' : 'none';
			}

			function open(attachments) {
				items = attachments;
				show(0);
				lb.style.display = 'flex';
				document.body.style.overflow = 'hidden';
				btnClose.focus();
			}

			function close() {
				lb.style.display = 'none';
				document.body.style.overflow = '';
				img.src = '';
			}

			document.querySelectorAll('.agnosis-files-btn').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var data = [];
					try { data = JSON.parse(btn.dataset.attachments || '[]'); } catch(e) {}
					if (data.length) open(data);
				});
			});

			btnClose.addEventListener('click', close);
			backdrop.addEventListener('click', close);
			btnPrev.addEventListener('click', function() { show(current - 1); });
			btnNext.addEventListener('click', function() { show(current + 1); });

			document.addEventListener('keydown', function(e) {
				if (lb.style.display === 'none') return;
				if (e.key === 'Escape')      close();
				if (e.key === 'ArrowLeft')   show(current - 1);
				if (e.key === 'ArrowRight')  show(current + 1);
			});
		})();
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- integer flags from our own redirects, display only.
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Queue row deleted. Poll Inbox to re-fetch the message.', 'agnosis' )
				. '</p></div>';
		}
		if ( isset( $_GET['processed_one'] ) ) {
			$qid = isset( $_GET['queue_id'] ) ? absint( wp_unslash( $_GET['queue_id'] ) ) : 0;
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( sprintf(
					/* translators: %d: queue row ID */
					__( 'Queue #%d sent to pipeline. Check back in a few seconds.', 'agnosis' ),
					$qid
				) )
				. '</p></div>';
		}
		if ( isset( $_GET['polled'] ) ) {
			$n = absint( wp_unslash( $_GET['polled'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( sprintf(
					/* translators: %d: new messages enqueued */
					__( 'Poll complete. %d new message(s) added to the queue.', 'agnosis' ),
					$n
				) )
				. '</p></div>';
		}
		if ( isset( $_GET['reprocessed'] ) ) {
			$reset    = absint( wp_unslash( $_GET['reprocessed'] ) );
			$enqueued = isset( $_GET['enqueued'] ) ? absint( wp_unslash( $_GET['enqueued'] ) ) : 0;
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( sprintf(
					/* translators: 1: rows reset 2: newly enqueued */
					__( 'Force-reprocess: %1$d row(s) reset, %2$d new message(s) enqueued.', 'agnosis' ),
					$reset, $enqueued
				) )
				. '</p></div>';
		}
		if ( isset( $_GET['processed'] ) ) {
			$n = (int) $_GET['processed'];
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( sprintf(
					/* translators: %d: items processed */
					__( 'Processed %d pending item(s).', 'agnosis' ),
					$n
				) )
				. '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Fetch one page of queue rows plus the total matching row count
	 * (security/ops audit §4c). Extracted from render() as its own method so
	 * the filter/pagination logic is directly testable without rendering HTML.
	 *
	 * When $reason_filter is empty, 'unregistered_sender' rows are excluded
	 * from the result — see this class's own docblock on
	 * render_unregistered_summary() for why: they're aggregated into one
	 * summary line instead of listed individually, so a spam flood can't
	 * push real events off the page. Explicitly filtering by
	 * reason_filter === 'unregistered_sender' shows them directly.
	 *
	 * $wpdb returns every column as a string (or null for a NULL column) —
	 * never native int/enum — hence the all-string shape below, matching
	 * agnosis_queue's own schema in Activator::activate(). Callers already
	 * cast id/artist_id to int where needed.
	 *
	 * @return array{rows: array<int, object{id: string, message_uid: string, artist_id: string|null, raw_email: string, status: string, error: string|null, created_at: string, updated_at: string}>, total: int}
	 */
	protected function fetch_rows( string $status_filter, string $reason_filter, int $paged ): array {
		global $wpdb;

		$where  = [];
		$params = [];

		if ( '' !== $status_filter ) {
			$where[]  = 'status = %s';
			$params[] = $status_filter;
		}

		if ( '' !== $reason_filter ) {
			$where[]  = 'error = %s';
			$params[] = Inbox::SKIP_REASONS[ $reason_filter ];
		} else {
			// error IS NULL must stay included here — SQL's != never matches
			// NULL, so a bare "error != %s" would silently drop every
			// pending/processing/published row (none of which ever set an
			// error) right along with the unregistered_sender rows it's
			// actually meant to exclude.
			$where[]  = '( error IS NULL OR error != %s )';
			$params[] = Inbox::SKIP_REASONS['unregistered_sender'];
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where_sql (built just above) is assembled entirely from this method's own hardcoded '%s'-placeholder fragments, never raw input; the actual values are passed through $wpdb->prepare()'s $params array below, same as any other prepared query. The Plugin Check sniff can't trace that composition statically.
		$total = (int) $wpdb->get_var(
			// The literal query text carries no %s of its own — every placeholder
			// lives inside $where_sql, built above from this method's own hardcoded
			// clause fragments (never raw input), so phpcs can't statically count
			// them; hence three placeholder sniffs ignored on the string line below.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql is built entirely from this method's own %s placeholders, never raw input.
				$params
			)
		);

		$offset = ( max( 1, $paged ) - 1 ) * self::PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- same $where_sql composition as the COUNT query above: hardcoded '%s'-placeholder fragments only, values supplied via $wpdb->prepare()'s $params array.
		$rows = $wpdb->get_results(
			// Same reasoning as the COUNT query above: $where_sql's %s placeholders
			// aren't visible to phpcs's static placeholder count, and the interpolation
			// itself sits on its own concatenated line below purely so the same-line
			// ignore comment lands on the token phpcs actually flags.
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->prepare(
				'SELECT id, message_uid, artist_id, status, raw_email, error, created_at, updated_at
				 FROM ' . $wpdb->prefix . 'agnosis_queue '
				. $where_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql is built entirely from this method's own %s placeholders, never raw input.
				. ' ORDER BY id DESC
				 LIMIT %d OFFSET %d',
				array_merge( $params, [ self::PER_PAGE, $offset ] )
			)
		);

		return [ 'rows' => $rows, 'total' => $total ];
	}

	/**
	 * Count of 'unregistered_sender' rows the current status filter would
	 * otherwise include — these rows are always status='failed', so any
	 * OTHER status filter (pending/processing/published/skipped) can never
	 * contain one; only 'failed' or "all statuses" need the real count.
	 */
	protected function count_unregistered_sender_rows( string $status_filter ): int {
		if ( '' !== $status_filter && 'failed' !== $status_filter ) {
			return 0;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue WHERE error = %s",
				Inbox::SKIP_REASONS['unregistered_sender']
			)
		);
	}

	/**
	 * Status/reason filter bar (security/ops audit §4c). A GET form (not
	 * POST) — filtering is idempotent and read-only, so a bookmarked or
	 * shared filtered URL just works, same as any WP list table's own
	 * filter dropdowns.
	 */
	private function render_filters( string $status_filter, string $reason_filter ): void {
		$status_labels = [
			''            => __( 'All statuses', 'agnosis' ),
			'pending'     => __( 'Pending', 'agnosis' ),
			'processing'  => __( 'Processing', 'agnosis' ),
			'published'   => __( 'Published', 'agnosis' ),
			'failed'      => __( 'Failed', 'agnosis' ),
			'skipped'     => __( 'Skipped', 'agnosis' ),
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
					<select name="reason_filter">
						<option value=""><?php esc_html_e( 'All reasons', 'agnosis' ); ?></option>
						<?php foreach ( self::REASON_FILTER_LABELS as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $reason_filter, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'agnosis' ); ?></button>
					<?php if ( '' !== $status_filter || '' !== $reason_filter ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Reset', 'agnosis' ); ?></a>
					<?php endif; ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Collapsed "N messages from unregistered senders" summary line
	 * (security/ops audit §4c). An unregistered-sender message becomes its
	 * own 'failed' queue row (correct — the state machine needs it to avoid
	 * re-fetching the same UID), but a weekend of dictionary spam can mean
	 * hundreds of these, pushing every real event off the default view
	 * entirely. Rather than listing each one, the default (no reason chosen)
	 * view excludes them from the table (see fetch_rows()) and shows this one
	 * line instead, linking through to the same rows filtered explicitly.
	 */
	private function render_unregistered_summary( int $count, string $status_filter ): void {
		if ( $count <= 0 ) {
			return;
		}

		$view_url = add_query_arg(
			array_filter( [
				'page'          => self::PAGE,
				'status_filter' => $status_filter,
				'reason_filter' => 'unregistered_sender',
			] ),
			admin_url( 'admin.php' )
		);

		printf(
			'<p class="agnosis-unregistered-summary" style="background:#fef3c7;border-left:4px solid #d97706;padding:.6rem 1rem;margin:0 0 1rem;font-size:13px">%s</p>',
			wp_kses(
				sprintf(
					/* translators: 1: number of collapsed messages, 2: URL to view them */
					_n(
						'%1$d message from an unregistered sender is hidden from this view. <a href="%2$s">View it</a>.',
						'%1$d messages from unregistered senders are hidden from this view. <a href="%2$s">View them</a>.',
						$count,
						'agnosis'
					),
					number_format_i18n( $count ),
					esc_url( $view_url )
				),
				[ 'a' => [ 'href' => [] ] ]
			)
		);
	}

	/** Prev/Next pager for the queue table (security/ops audit §4c) — replaces the old flat, unpaginated "showing the 100 most recent" notice. */
	private function render_pagination( int $current_page, int $total_pages, string $status_filter, string $reason_filter ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		$base_args = array_filter( [
			'page'          => self::PAGE,
			'status_filter' => $status_filter,
			'reason_filter' => $reason_filter,
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

	private function render_toolbar(): void {
		?>
		<div class="agnosis-toolbar">
			<!-- Poll Inbox -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'agnosis_poll_now' ); ?>
				<input type="hidden" name="action" value="agnosis_poll_now">
				<button type="submit" class="button button-primary">
					<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:-2px"></span>
					<?php esc_html_e( 'Poll Inbox', 'agnosis' ); ?>
				</button>
			</form>

			<!-- Force Reprocess -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'agnosis_force_reprocess' ); ?>
				<input type="hidden" name="action" value="agnosis_force_reprocess">
				<button type="submit" class="button">
					<?php esc_html_e( 'Force Reprocess', 'agnosis' ); ?>
				</button>
			</form>

			<!-- Process All Pending -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'agnosis_process_queue' ); ?>
				<input type="hidden" name="action" value="agnosis_process_queue">
				<button type="submit" class="button">
					<?php esc_html_e( 'Process All Pending', 'agnosis' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Label for the Endpoint column (patch 18) — which email address a
	 * submission was sent to, or which alias it hit.
	 *
	 * goodbye@/community@/bounce events (security audit §5a) are labelled
	 * straight from $skip_reason rather than being handed to
	 * PostCreator::resolve_endpoint_label() — a plain skip_reason string
	 * match is simpler and unambiguous, and keeps working even if the
	 * goodbye@/community@ addresses are reconfigured later. This is not
	 * (or no longer, as of 2026-07-14) a workaround for missing data:
	 * Inbox::mark_no_artwork() stashes subject/to_addresses for every skip
	 * reason it has them for, including these three, so
	 * PostCreator::resolve_endpoint_label() would now actually classify
	 * them correctly too (it recognises the goodbye@/community@ addresses
	 * itself) — it's just redundant to route through address-matching when
	 * the reason string alone already says which alias fired.
	 *
	 * @param array<string, mixed> $data        Decoded raw_email JSON for this row.
	 * @param string               $skip_reason This row's skip_reason, if any (already
	 *                                           extracted by the caller for the status badge).
	 */
	private function resolve_endpoint_label( array $data, string $skip_reason ): string {
		if ( str_starts_with( $skip_reason, 'goodbye_' ) ) {
			return __( 'Goodbye', 'agnosis' );
		}
		if ( str_starts_with( $skip_reason, 'community_' ) ) {
			return __( 'Community', 'agnosis' );
		}
		if ( str_starts_with( $skip_reason, 'bounce_' ) ) {
			return __( 'Bounce', 'agnosis' );
		}
		return PostCreator::resolve_endpoint_label( $data );
	}

	private function render_status_badge( string $status, ?string $wp_post_status = null, string $skip_reason = '' ): void {
		// For queue rows that completed the pipeline, show the *post's* actual status
		// rather than the misleading "Published" queue label.
		if ( 'published' === $status ) {
			switch ( $wp_post_status ) {
				case 'draft':
					$b = [ 'bg' => '#fef3c7', 'color' => '#92400e', 'label' => __( 'Awaiting Review', 'agnosis' ) ];
					break;
				case 'publish':
					$b = [ 'bg' => '#d1fae5', 'color' => '#065f46', 'label' => __( 'Live', 'agnosis' ) ];
					break;
				default:
					$b = [ 'bg' => '#e0e7ff', 'color' => '#3730a3', 'label' => __( 'Processed', 'agnosis' ) ];
					break;
			}
		} elseif ( 'skipped' === $status ) {
			// A flat gray "Skipped" reads as "nothing happened" — wrong for a
			// reason like goodbye_handled, which (once the artist confirms via
			// the emailed link) permanently deletes their entire account and
			// content. Reason-specific labels (2026-07-08) replace that with
			// wording that reflects what actually happened. $skip_reason is
			// only populated for rows created after this fix (see
			// Inbox::mark_no_artwork()) — an older row falls through to the
			// generic 'Handled' label below, still accurate, just less specific.
			$map = [
				'goodbye_handled'    => [ 'bg' => '#ede9fe', 'color' => '#5b21b6', 'label' => __( 'Member Removed', 'agnosis' ) ],
				'community_handled'  => [ 'bg' => '#dbeafe', 'color' => '#1d4ed8', 'label' => __( 'Broadcast Sent', 'agnosis' ) ],
				'community_too_long' => [ 'bg' => '#fef3c7', 'color' => '#92400e', 'label' => __( 'Bounced (too long)', 'agnosis' ) ],
			];
			$b = $map[ $skip_reason ] ?? [ 'bg' => '#e5e7eb', 'color' => '#4b5563', 'label' => __( 'Handled', 'agnosis' ) ];
		} else {
			$map = [
				'pending'    => [ 'bg' => '#dbeafe', 'color' => '#1d4ed8', 'label' => __( 'Pending',    'agnosis' ) ],
				'processing' => [ 'bg' => '#fef3c7', 'color' => '#92400e', 'label' => __( 'Processing', 'agnosis' ) ],
				'failed'     => [ 'bg' => '#fee2e2', 'color' => '#991b1b', 'label' => __( 'Failed',     'agnosis' ) ],
			];
			$b = $map[ $status ] ?? [ 'bg' => '#f3f4f6', 'color' => '#374151', 'label' => ucfirst( $status ) ];
		}

		printf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;background:%s;color:%s">%s</span>',
			esc_attr( $b['bg'] ),
			esc_attr( $b['color'] ),
			esc_html( $b['label'] )
		);
	}

	private function render_row_actions( int $id, string $status ): void {
		if ( in_array( $status, [ 'pending', 'failed' ], true ) ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'agnosis_process_one' ); ?>
				<input type="hidden" name="action"   value="agnosis_process_one">
				<input type="hidden" name="queue_id" value="<?php echo esc_attr( (string) $id ); ?>">
				<button type="submit" class="button button-small button-primary">
					<?php esc_html_e( 'Process', 'agnosis' ); ?>
				</button>
			</form>
			<?php
		} elseif ( 'published' === $status ) {
			// View Post link.
			$posts = get_posts( [
				'post_type'      => 'agnosis_artwork',
				'meta_key'       => '_agnosis_queue_id',
				'meta_value'     => (string) $id,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			] );
			if ( ! empty( $posts ) ) {
				$post_id = (int) $posts[0];
				printf(
					'<a class="button button-small" href="%s" style="margin-right:4px">%s</a>',
					esc_url( get_edit_post_link( $post_id ) ?: '' ),
					esc_html__( 'View Post', 'agnosis' )
				);
			}
			// Reprocess button — resets queue row and re-runs the pipeline.
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'agnosis_process_one' ); ?>
				<input type="hidden" name="action"   value="agnosis_process_one">
				<input type="hidden" name="queue_id" value="<?php echo esc_attr( (string) $id ); ?>">
				<button type="submit" class="button button-small">
					<?php esc_html_e( 'Reprocess', 'agnosis' ); ?>
				</button>
			</form>
			<?php
		} elseif ( 'processing' === $status ) {
			echo '<span style="color:#92400e;font-size:12px">' . esc_html__( 'Running…', 'agnosis' ) . '</span>';
		}

		// Delete button always available — lets you clear corrupted or unwanted rows.
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:4px"
			  onsubmit="return confirm('<?php echo esc_js( __( 'Delete this queue row?', 'agnosis' ) ); ?>')">
			<?php wp_nonce_field( 'agnosis_delete_queue_row' ); ?>
			<input type="hidden" name="action"   value="agnosis_delete_queue_row">
			<input type="hidden" name="queue_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<button type="submit" class="button button-small" style="color:#b00;border-color:#b00">
				<?php esc_html_e( 'Delete', 'agnosis' ); ?>
			</button>
		</form>
		<?php
	}

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

	private function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M10 0l1.8 7.2L19 10l-7.2 1.8L10 20l-1.8-8.2L1 10l8.2-1.8z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	private function page_css(): string {
		return '
		.agnosis-inbox h1 { display:flex; align-items:baseline; gap:.4rem; }
		.agnosis-toolbar { display:flex; gap:.5rem; margin:1rem 0 1.5rem; flex-wrap:wrap; }
		.agnosis-queue-table td { vertical-align:middle; }
		.agnosis-error-text { color:#991b1b; font-size:12px; }
		.agnosis-files-btn { background:none; border:none; cursor:pointer; padding:0; color:inherit; font-size:inherit; }
		.agnosis-files-btn:hover { text-decoration:underline; }

		/* Lightbox */
		#agnosis-lightbox {
			position:fixed; inset:0; z-index:100000;
			display:flex; align-items:center; justify-content:center;
		}
		#agnosis-lightbox-backdrop {
			position:absolute; inset:0; background:rgba(0,0,0,.85);
		}
		#agnosis-lightbox-inner {
			position:relative; z-index:1;
			display:flex; align-items:center; gap:1rem;
			max-width:90vw; max-height:90vh;
		}
		#agnosis-lb-img-wrap {
			display:flex; flex-direction:column; align-items:center; gap:.5rem;
		}
		#agnosis-lb-img {
			max-width:80vw; max-height:80vh;
			object-fit:contain; border-radius:4px;
			box-shadow:0 8px 40px rgba(0,0,0,.6);
		}
		#agnosis-lb-caption { color:#ccc; font-size:12px; margin:0; }
		#agnosis-lb-close {
			position:fixed; top:1.2rem; right:1.5rem; z-index:2;
			background:rgba(255,255,255,.15); border:none; color:#fff;
			font-size:1.4rem; width:2.2rem; height:2.2rem; border-radius:50%;
			cursor:pointer; line-height:1; display:flex; align-items:center; justify-content:center;
		}
		#agnosis-lb-close:hover { background:rgba(255,255,255,.3); }
		#agnosis-lb-prev, #agnosis-lb-next {
			background:rgba(255,255,255,.15); border:none; color:#fff;
			font-size:1.6rem; width:2.8rem; height:2.8rem; border-radius:50%;
			cursor:pointer; flex-shrink:0; display:flex; align-items:center; justify-content:center;
		}
		#agnosis-lb-prev:hover, #agnosis-lb-next:hover { background:rgba(255,255,255,.3); }

		#adminmenu .toplevel_page_agnosis .wp-menu-image img { opacity:.7; }
		#adminmenu .toplevel_page_agnosis.current .wp-menu-image img,
		#adminmenu .toplevel_page_agnosis:hover .wp-menu-image img {
			opacity:1;
			filter:brightness(0) saturate(100%) invert(52%) sepia(60%) saturate(500%) hue-rotate(220deg);
		}
		';
	}

	// -------------------------------------------------------------------------
	// admin_post handler
	// -------------------------------------------------------------------------

	/** admin_post handler — test IMAP connection and report status. */
	public function handle_test_inbox(): void {
		check_admin_referer( 'agnosis_test_inbox' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$inbox  = new \Agnosis\Email\Inbox();
		$result = $inbox->test_connection();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => 'agnosis-settings',
					'tab'           => 'email',
					'inbox_test'    => $result['ok'] ? 'ok' : 'fail',
					'inbox_message' => rawurlencode( $result['message'] ),
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
