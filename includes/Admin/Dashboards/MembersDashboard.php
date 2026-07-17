<?php
/**
 * "Members" dashboard card (Community tab) — rendering plus its own
 * admin-post handlers for Ban/Delete/Initiate-removal-vote.
 *
 * Split out of Admin\Settings (2026-07-17, AUDIT-1.0.0.md §4d — the "god
 * class" finding): this render method and its three handlers were already a
 * self-contained cluster, so this is a pure move — same behavior, same hook
 * names (rewired in Core\Plugin to this class instead of Settings).
 *
 * @package Agnosis\Admin\Dashboards
 */

declare(strict_types=1);

namespace Agnosis\Admin\Dashboards;

use Agnosis\Artist\Departure;

class MembersDashboard {

	/**
	 * Render the admitted (and banned) members table on the Community tab.
	 *
	 * Shows every admitted/banned artist with Ban / Delete / Initiate Vote actions.
	 */
	public function render(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$members = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}agnosis_applications
			 WHERE status IN ('admitted', 'banned')
			 ORDER BY display_name ASC"
		);

		?>
		<div class="card" style="max-width:960px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Members', 'agnosis' ); ?></h2>

			<?php if ( empty( $members ) ) : ?>
				<p style="color:#666"><?php esc_html_e( 'No admitted members yet.', 'agnosis' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="border-radius:4px;overflow:hidden">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Artist', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Status', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Joined', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'agnosis' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $members as $member ) : ?>
						<?php
						/** @var object{id: string, wp_user_id: string|null, display_name: string, email: string, status: string, resolved_at: string|null, banned_until: string|null} $member */
						$app_id     = (int) $member->id;
						$is_banned  = 'banned' === $member->status;
						$status_col = $is_banned ? '#c0392b' : '#0a7c48';
						$status_lbl = $is_banned
							? ( $member->banned_until
								? sprintf(
									/* translators: %s: date until which the artist is banned */
									__( 'Banned until %s', 'agnosis' ),
									date_i18n( get_option( 'date_format' ), strtotime( $member->banned_until ) )
								)
								: __( 'Banned', 'agnosis' ) )
							: __( 'Active', 'agnosis' );
						?>
						<?php
						// Bounce counter (security audit §5a): a dead artist inbox
						// otherwise silently stops receiving review links — this
						// count is the only operator-visible sign anything is wrong.
						$bounce_count = $member->wp_user_id ? (int) get_user_meta( (int) $member->wp_user_id, '_agnosis_bounce_count', true ) : 0;
						// Notification preferences (security audit §5b/§4a) — surfaced
						// here for the same reason as the bounce badge: an operator
						// wondering why a particular artist never votes on new
						// applications, or never replies to a broadcast, has an
						// immediate answer instead of having to guess.
						$muted_broadcasts = $member->wp_user_id && '1' === get_user_meta( (int) $member->wp_user_id, '_agnosis_broadcast_optout', true );
						$digest_mode      = $member->wp_user_id && 'digest' === get_user_meta( (int) $member->wp_user_id, '_agnosis_vote_email_mode', true );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $member->display_name ); ?></strong><br>
								<span style="color:#666;font-size:12px"><?php echo esc_html( $member->email ); ?></span>
								<?php if ( $bounce_count > 0 ) : ?>
									<br>
									<span style="color:#b34a4a;font-size:12px;font-weight:600" title="<?php esc_attr_e( 'This address has bounced or been reported as spam — mail may no longer be reaching this artist.', 'agnosis' ); ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: number of recorded bounces/complaints for this artist's address */
												_n( '⚠ %d bounce', '⚠ %d bounces', $bounce_count, 'agnosis' ),
												$bounce_count
											)
										);
										?>
									</span>
								<?php endif; ?>
								<?php if ( $muted_broadcasts ) : ?>
									<br>
									<span style="color:#888;font-size:12px" title="<?php esc_attr_e( 'This artist has muted community broadcast messages.', 'agnosis' ); ?>">
										<?php esc_html_e( '🔇 Broadcasts muted', 'agnosis' ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $digest_mode ) : ?>
									<br>
									<span style="color:#888;font-size:12px" title="<?php esc_attr_e( 'This artist receives one daily digest of open applications instead of an email per application.', 'agnosis' ); ?>">
										<?php esc_html_e( '📬 Vote digest mode', 'agnosis' ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<span style="color:<?php echo esc_attr( $status_col ); ?>;font-weight:600;font-size:12px">
									<?php echo esc_html( $status_lbl ); ?>
								</span>
							</td>
							<td style="white-space:nowrap;font-size:12px">
								<?php echo $member->resolved_at ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $member->resolved_at ) ) ) : '—'; ?>
							</td>
							<td style="white-space:nowrap">
								<?php if ( ! $is_banned ) : ?>
									<!-- Temporary ban -->
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										  style="display:inline;margin-right:4px"
										  onsubmit="return this.querySelector('[name=banned_until]').value !== '' || confirm('<?php echo esc_js( __( 'Leave the date blank to ban indefinitely. Continue?', 'agnosis' ) ); ?>')">
										<input type="hidden" name="action" value="agnosis_ban_artist">
										<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $app_id ); ?>">
										<input type="date" name="banned_until" style="font-size:12px;padding:2px 4px"
											   min="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '+1 day' ) ) ); ?>"
											   title="<?php esc_attr_e( 'Leave blank to ban indefinitely', 'agnosis' ); ?>">
										<?php wp_nonce_field( 'agnosis_ban_' . $app_id, 'agnosis_nonce' ); ?>
										<?php submit_button( __( 'Suspend', 'agnosis' ), 'small', 'submit', false ); ?>
									</form>
								<?php endif; ?>
								<!-- Permanent delete -->
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									  style="display:inline;margin-right:4px"
									  onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this artist and all their content? This cannot be undone.', 'agnosis' ) ); ?>')">
									<input type="hidden" name="action" value="agnosis_delete_artist">
									<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $app_id ); ?>">
									<?php wp_nonce_field( 'agnosis_delete_' . $app_id, 'agnosis_nonce' ); ?>
									<?php submit_button( __( 'Delete', 'agnosis' ), 'small delete', 'submit', false ); ?>
								</form>
								<?php if ( ! $is_banned ) : ?>
									<!-- Initiate community vote -->
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										  style="display:inline"
										  onsubmit="return confirm('<?php echo esc_js( __( 'Open a community removal vote for this artist? All members will be emailed.', 'agnosis' ) ); ?>')">
										<input type="hidden" name="action" value="agnosis_initiate_removal_vote">
										<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $app_id ); ?>">
										<?php wp_nonce_field( 'agnosis_vote_' . $app_id, 'agnosis_nonce' ); ?>
										<?php submit_button( __( 'Open Vote', 'agnosis' ), 'small', 'submit', false ); ?>
									</form>
								<?php endif; ?>
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
	 * admin-post handler: suspend (ban) an artist, optionally until a date.
	 */
	public function handle_ban_artist(): void {
		$app_id = absint( wp_unslash( $_POST['application_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_ban_' . $app_id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$raw_until = sanitize_text_field( wp_unslash( $_POST['banned_until'] ?? '' ) );
		$until     = ( '' !== $raw_until && strtotime( $raw_until ) )
			? ( new \DateTimeImmutable( $raw_until ) )
			: null;

		$departure = new Departure();
		$ok        = $departure->admin_ban( $app_id, $until );

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'community',
				'subtab'          => 'members',
				'agnosis_message' => $ok ? 'banned' : 'ban_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * admin-post handler: permanently delete an artist and all their content.
	 */
	public function handle_delete_artist(): void {
		$app_id = absint( wp_unslash( $_POST['application_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_delete_' . $app_id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$departure = new Departure();
		$ok        = $departure->admin_delete( $app_id );

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'community',
				'subtab'          => 'members',
				'agnosis_message' => $ok ? 'deleted' : 'delete_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * admin-post handler: open a community removal vote for an artist (admin bypass).
	 */
	public function handle_initiate_removal_vote(): void {
		$app_id = absint( wp_unslash( $_POST['application_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_vote_' . $app_id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;

		// Resolve subject user_id from application.
		$user_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT wp_user_id FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$app_id
			)
		);

		if ( ! $user_id ) {
			wp_safe_redirect( add_query_arg(
				[ 'page' => 'agnosis-settings', 'tab' => 'community', 'subtab' => 'members', 'agnosis_message' => 'vote_open_failed' ],
				admin_url( 'admin.php' )
			) );
			exit;
		}

		$departure = new Departure();
		$ok        = $departure->admin_open_removal_vote( $user_id, get_current_user_id() );

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'community',
				'subtab'          => 'members',
				'agnosis_message' => $ok ? 'vote_opened' : 'vote_open_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
