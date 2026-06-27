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

class InboxPage {

	private const PAGE = 'agnosis';

	/** Mirrors Parser::ALLOWED_MIME — used to validate stored mime types before rendering data URIs. */
	private const ALLOWED_MIME = [
		'image/jpeg', 'image/jpg', 'image/png',
		'image/webp', 'image/gif', 'image/tiff',
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

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, message_uid, artist_id, status, raw_email, error, created_at, updated_at
			 FROM {$wpdb->prefix}agnosis_queue
			 ORDER BY id DESC
			 LIMIT 100"
		);

		?>
		<div class="wrap agnosis-inbox">
			<h1>
				<span style="color:#7c6af7">✦</span>
				<?php esc_html_e( 'Inbox', 'agnosis' ); ?>
			</h1>

			<?php $this->render_toolbar(); ?>

			<?php if ( empty( $rows ) ) : ?>
				<p style="margin-top:2rem;color:#666;">
					<?php esc_html_e( 'No messages in the queue yet. Click "Poll Inbox" to fetch messages from your IMAP account.', 'agnosis' ); ?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped agnosis-queue-table">
					<thead>
						<tr>
							<th style="width:3.5rem"><?php esc_html_e( '#', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'From', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'agnosis' ); ?></th>
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
								<td><?php $this->render_status_badge( $status, $wp_post_status ); ?></td>
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
				<?php if ( count( $rows ) === 100 ) : ?>
					<p style="color:#666;margin-top:.5rem;font-size:12px">
						<?php esc_html_e( 'Showing the 100 most recent queue entries.', 'agnosis' ); ?>
					</p>
				<?php endif; ?>
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

	private function render_status_badge( string $status, ?string $wp_post_status = null ): void {
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
}
