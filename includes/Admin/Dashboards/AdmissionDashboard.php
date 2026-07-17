<?php
/**
 * "Pending Applications" dashboard card (Community tab) — rendering plus its
 * own admin-post handlers for the admin-override Admit/Reject actions.
 *
 * Split out of Admin\Settings (2026-07-17, AUDIT-1.0.0.md §4d — the "god
 * class" finding): this render method and its two handlers were already a
 * self-contained cluster with no cross-references to anything else Settings
 * did, so this is a pure move — same behavior, same hook names
 * (`admin_post_agnosis_admit_application`/`admin_post_agnosis_reject_application`,
 * rewired in Core\Plugin to this class instead of Settings).
 *
 * @package Agnosis\Admin\Dashboards
 */

declare(strict_types=1);

namespace Agnosis\Admin\Dashboards;

use Agnosis\Artist\Admission;

class AdmissionDashboard {

	/**
	 * Render the pending applications table on the Community tab.
	 *
	 * Shows every application in status='pending' with vouch counts and
	 * admin-override Admit / Reject buttons.
	 */
	public function render(): void {
		global $wpdb;

		$admission = new Admission();
		$required  = $admission->calculate_required();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$applications = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}agnosis_applications
			 WHERE status = 'pending'
			 ORDER BY applied_at ASC"
		);

		// Double opt-in (security audit §3a/§4a): a row sits as 'unverified'
		// between apply() and the artist clicking the confirm link in their
		// email — invisible to the table below by design (it isn't open for
		// community review yet). Surfaced as a plain count here only so an
		// operator fielding "I applied but nothing happened" doesn't have to
		// guess why an applicant isn't listed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$unverified_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_applications WHERE status = 'unverified'"
		);

		?>
		<div class="card" style="max-width:900px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Pending Applications', 'agnosis' ); ?></h2>

			<?php if ( $unverified_count > 0 ) : ?>
				<p class="description" style="margin-bottom:1rem">
					<?php
					printf(
						esc_html(
							/* translators: %d: number of applications awaiting email confirmation */
							_n(
								'%d application is awaiting email confirmation from the applicant and isn\'t listed below yet.',
								'%d applications are awaiting email confirmation from the applicant and aren\'t listed below yet.',
								$unverified_count,
								'agnosis'
							)
						),
						(int) $unverified_count
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( empty( $applications ) ) : ?>
				<p style="color:#666"><?php esc_html_e( 'No pending applications.', 'agnosis' ); ?></p>
			<?php else : ?>
				<p class="description" style="margin-bottom:1rem">
					<?php
					printf(
						/* translators: %d: number of positive votes currently required for admission */
						esc_html__( '%d positive vote(s) currently required for admission.', 'agnosis' ),
						(int) $required
					);
					?>
				</p>
				<table class="widefat striped" style="border-radius:4px;overflow:hidden">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Applicant', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Applied', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Votes', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Bio / Portfolio', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'agnosis' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $applications as $app ) : ?>
						<?php
						/** @var object{id: string, display_name: string, email: string, applied_at: string, bio: string|null, portfolio_url: string|null} $app */
						$app_id  = (int) $app->id;
						$yes     = $admission->count_positive_vouches( $app_id );
						$bar_pct = $required > 0 ? min( 100, (int) round( $yes / $required * 100 ) ) : 100;
						$bar_col = $yes >= $required ? '#00a32a' : '#2271b1';
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $app->display_name ); ?></strong><br>
								<span style="color:#666;font-size:12px"><?php echo esc_html( $app->email ); ?></span>
							</td>
							<td style="white-space:nowrap"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $app->applied_at ) ) ); ?></td>
							<td style="min-width:120px">
								<div style="background:#ddd;border-radius:3px;height:6px;margin-bottom:4px">
									<div style="background:<?php echo esc_attr( $bar_col ); ?>;width:<?php echo esc_attr( (string) $bar_pct ); ?>%;height:6px;border-radius:3px"></div>
								</div>
								<?php
								printf(
									/* translators: 1: yes votes received, 2: total votes required */
									esc_html__( '%1$d / %2$d', 'agnosis' ),
									(int) $yes,
									(int) $required
								);
								?>
							</td>
							<td style="max-width:260px;font-size:12px">
								<?php if ( $app->bio ) : ?>
									<span title="<?php echo esc_attr( $app->bio ); ?>"><?php echo esc_html( wp_trim_words( $app->bio, 20 ) ); ?></span>
								<?php endif; ?>
								<?php if ( $app->portfolio_url ) : ?>
									<br><a href="<?php echo esc_url( $app->portfolio_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Portfolio ↗', 'agnosis' ); ?></a>
								<?php endif; ?>
							</td>
							<td style="white-space:nowrap">
								<!-- Admit -->
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="agnosis_admit_application">
									<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $app_id ); ?>">
									<?php wp_nonce_field( 'agnosis_admit_' . $app_id, 'agnosis_nonce' ); ?>
									<?php submit_button( __( 'Admit', 'agnosis' ), 'small', 'submit', false ); ?>
								</form>
								<!-- Reject -->
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:4px">
									<input type="hidden" name="action" value="agnosis_reject_application">
									<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $app_id ); ?>">
									<?php wp_nonce_field( 'agnosis_reject_' . $app_id, 'agnosis_nonce' ); ?>
									<?php submit_button( __( 'Reject', 'agnosis' ), 'small delete', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * admin-post handler: admit an applicant, bypassing the vouch threshold.
	 */
	public function handle_admit_application(): void {
		$app_id = absint( wp_unslash( $_POST['application_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_admit_' . $app_id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$admission = new Admission();
		$ok        = $admission->admin_admit( $app_id );

		$redirect = add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'community',
				'subtab'          => 'members',
				'agnosis_message' => $ok ? 'admitted' : 'admit_failed',
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * admin-post handler: reject an applicant.
	 */
	public function handle_reject_application(): void {
		$app_id = absint( wp_unslash( $_POST['application_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_reject_' . $app_id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$admission = new Admission();
		$ok        = $admission->admin_reject( $app_id );

		$redirect = add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'community',
				'subtab'          => 'members',
				'agnosis_message' => $ok ? 'rejected' : 'reject_failed',
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
