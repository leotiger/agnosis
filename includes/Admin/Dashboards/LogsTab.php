<?php
/**
 * "Pipeline Logs" tab (Logs tab) — rendering plus its own admin-post handler
 * for the "Clear All" button.
 *
 * Split out of Admin\Settings (2026-07-17, AUDIT-1.0.0.md §4d — the "god
 * class" finding): this render method and its handler were already a
 * self-contained cluster, so this is a pure move — same behavior, same hook
 * name (`admin_post_agnosis_clear_logs`, rewired in Core\Plugin to this class
 * instead of Settings).
 *
 * Note: the Logs tab's other two admin-post handlers
 * (`agnosis_clear_debug_files`, `agnosis_clear_term_translations_cache`)
 * stay on Settings — their render methods (render_debug_panel(),
 * render_term_translation_cache_panel()) live on the General tab as shared
 * framework content, not here.
 *
 * @package Agnosis\Admin\Dashboards
 */

declare(strict_types=1);

namespace Agnosis\Admin\Dashboards;

use Agnosis\Core\Logger;

class LogsTab {

	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- integer page offset for display only.
		$page    = max( 1, (int) sanitize_key( wp_unslash( $_GET['log_page'] ?? '1' ) ) );
		$per     = 50;
		$offset  = ( $page - 1 ) * $per;
		$total   = Logger::count();
		$entries = Logger::get_entries( $per, $offset );
		$pages   = (int) ceil( $total / $per );

		$level_colours = [
			'info'    => '#0a7c48',
			'warning' => '#8a6d3b',
			'error'   => '#c0392b',
		];
		$level_bg = [
			'info'    => '#ecfdf5',
			'warning' => '#fef9e7',
			'error'   => '#fdf2f2',
		];

		?>
		<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
			<h2 style="margin:0"><?php esc_html_e( 'Pipeline Logs', 'agnosis' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="agnosis_clear_logs">
				<?php wp_nonce_field( 'agnosis_clear_logs' ); ?>
				<button type="submit" class="button button-secondary"
					onclick="return confirm('<?php echo esc_js( __( 'Clear all log entries?', 'agnosis' ) ); ?>')">
					<?php esc_html_e( 'Clear All', 'agnosis' ); ?>
				</button>
			</form>
		</div>

		<p class="description" style="margin-bottom:1rem">
			<?php
			printf(
				/* translators: %d: total log entry count */
				esc_html__( '%d entries total. Logs are pruned automatically according to the Inbox retention setting.', 'agnosis' ),
				(int) $total
			);
			?>
		</p>

		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'No log entries yet.', 'agnosis' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1200px">
				<thead>
					<tr>
						<th style="width:160px"><?php esc_html_e( 'Time', 'agnosis' ); ?></th>
						<th style="width:80px"><?php esc_html_e( 'Level', 'agnosis' ); ?></th>
						<th style="width:120px"><?php esc_html_e( 'Context', 'agnosis' ); ?></th>
						<th><?php esc_html_e( 'Message', 'agnosis' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $entries as $entry ) :
						$lvl = $entry['level'] ?? 'info';
						$bg  = $level_bg[ $lvl ]  ?? '';
						$fg  = $level_colours[ $lvl ] ?? '#000';
						?>
						<tr style="background:<?php echo esc_attr( $bg ); ?>">
							<td style="white-space:nowrap;font-family:monospace;font-size:.85em">
								<?php echo esc_html( $entry['created_at'] ); ?>
							</td>
							<td>
								<span style="color:<?php echo esc_attr( $fg ); ?>;font-weight:600;text-transform:uppercase;font-size:.8em">
									<?php echo esc_html( $lvl ); ?>
								</span>
							</td>
							<td style="font-family:monospace;font-size:.85em;color:#555">
								<?php echo esc_html( $entry['context'] ); ?>
							</td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div style="margin-top:1rem">
					<?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
						<?php if ( $p === $page ) : ?>
							<strong>[<?php echo (int) $p; ?>]</strong>
						<?php else : ?>
							<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'agnosis-settings', 'tab' => 'logs', 'log_page' => $p ], admin_url( 'admin.php' ) ) ); ?>">
								<?php echo (int) $p; ?>
							</a>
						<?php endif; ?>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/** admin_post handler — clear all pipeline log entries. */
	public function handle_clear_logs(): void {
		check_admin_referer( 'agnosis_clear_logs' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		Logger::clear();

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis-settings', 'tab' => 'logs', 'cleared' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
