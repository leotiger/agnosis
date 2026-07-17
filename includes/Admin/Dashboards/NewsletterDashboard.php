<?php
/**
 * "Newsletter Status" dashboard card (Newsletter tab) — rendering plus its
 * own admin-post handlers for Send Now / Retry Failed / Send Test.
 *
 * Split out of Admin\Settings (2026-07-17, AUDIT-1.0.0.md §4d — the "god
 * class" finding): this render method, its five private helpers, and its
 * three handlers were already a self-contained cluster, so this is a pure
 * move — same behavior, same hook names (rewired in Core\Plugin to this
 * class instead of Settings).
 *
 * @package Agnosis\Admin\Dashboards
 */

declare(strict_types=1);

namespace Agnosis\Admin\Dashboards;

use Agnosis\Compat\LinguaForge;
use Agnosis\Core\Activator;
use Agnosis\Core\Logger;
use Agnosis\Newsletter\QueueProcessor;
use Agnosis\Newsletter\Scheduler;
use Agnosis\Newsletter\Subscriber;

class NewsletterDashboard {

	public function render(): void {
		// Self-heal a stuck 'Sending…' status the moment an admin views this
		// page, rather than only on WP-Cron's next tick — see
		// QueueProcessor::reconcile_sending_issues()'s docblock. Cheap (a
		// handful of small COUNT queries, no-op when nothing is 'sending').
		( new QueueProcessor() )->reconcile_sending_issues();

		// Also re-check the newsletter cron events themselves, unconditionally
		// (not just on a version bump) — see
		// Activator::ensure_newsletter_cron_scheduled()'s docblock for why:
		// found 2026-07-06, a site's agnosis_send_newsletter_queue event was
		// missing entirely, so nothing had ever reconciled it in the first
		// place. Logged only when something was actually missing, since a
		// re-registration happening at all is itself worth knowing about.
		if ( Activator::ensure_newsletter_cron_scheduled() ) {
			Logger::warning( 'Newsletter dashboard view found a missing newsletter cron event and re-registered it.', 'newsletter' );
		}

		$scheduler        = new Scheduler();
		$sub_counts       = Subscriber::counts();
		$threshold        = (int) get_option( 'agnosis_newsletter_subscriber_warn_threshold', 250 );
		$artist_query     = new \WP_User_Query( [ 'role' => 'agnosis_artist', 'count_total' => true, 'number' => 0, 'fields' => 'ID' ] );
		$artist_total     = (int) $artist_query->get_total();
		$optout_query     = new \WP_User_Query( [
			'role'       => 'agnosis_artist',
			'count_total' => true,
			'number'     => 0,
			'fields'     => 'ID',
			'meta_key'   => '_agnosis_newsletter_optout', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small table (admitted artists only).
			'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		$artist_optouts   = (int) $optout_query->get_total();

		?>
		<div class="card" style="max-width:900px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Newsletter Status', 'agnosis' ); ?></h2>

			<?php if ( $sub_counts['confirmed'] > $threshold ) : ?>
				<div class="notice notice-warning inline" style="margin:0 0 1rem">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: confirmed public subscriber count, 2: configured comfort threshold */
								__( 'You have %1$d confirmed public subscribers, above the configured comfort threshold of %2$d. Self-hosted sending still works, but you may want to connect an email service provider (e.g. Brevo\'s free tier) for better deliverability at this size.', 'agnosis' ),
								(int) $sub_counts['confirmed'],
								(int) $threshold
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<table class="widefat striped" style="border-radius:4px;overflow:hidden;margin-bottom:1.5rem">
				<thead><tr>
					<th><?php esc_html_e( 'Newsletter', 'agnosis' ); ?></th>
					<th><?php esc_html_e( 'Audience', 'agnosis' ); ?></th>
					<th><?php esc_html_e( 'Last sent', 'agnosis' ); ?></th>
					<th><?php esc_html_e( 'Status', 'agnosis' ); ?></th>
					<th><?php esc_html_e( 'Action', 'agnosis' ); ?></th>
					<th><?php esc_html_e( 'Failed recipients', 'agnosis' ); ?></th>
					<th><?php esc_html_e( 'Send a test', 'agnosis' ); ?></th>
				</tr></thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Artist', 'agnosis' ); ?></strong></td>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: admitted artist count, 2: how many of them opted out */
									__( '%1$d admitted (%2$d opted out)', 'agnosis' ),
									$artist_total,
									$artist_optouts
								)
							);
							?>
						</td>
						<td><?php echo esc_html( $this->format_last_sent( $scheduler->last_sent_at( 'artist' ) ) ); ?></td>
						<td><?php echo $scheduler->has_issue_in_flight( 'artist' ) ? esc_html__( 'Sending…', 'agnosis' ) : esc_html__( 'Idle', 'agnosis' ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="agnosis_send_newsletter_now">
								<input type="hidden" name="newsletter_type" value="artist">
								<?php wp_nonce_field( 'agnosis_send_newsletter_artist' ); ?>
								<?php submit_button( __( 'Send Now', 'agnosis' ), 'small', 'submit', false, $scheduler->has_issue_in_flight( 'artist' ) ? [ 'disabled' => 'disabled' ] : [] ); ?>
							</form>
						</td>
						<td><?php $this->render_retry_failed_button( 'artist' ); ?></td>
						<td><?php $this->render_newsletter_test_form( 'artist' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Public', 'agnosis' ); ?></strong></td>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: confirmed subscriber count, 2: pending confirmation count, 3: unsubscribed count, 4: bounced/suppressed count */
									__( '%1$d confirmed, %2$d pending confirmation, %3$d unsubscribed, %4$d bounced', 'agnosis' ),
									(int) $sub_counts['confirmed'],
									(int) $sub_counts['pending'],
									(int) $sub_counts['unsubscribed'],
									(int) $sub_counts['bounced']
								)
							);
							?>
							<?php $locale_breakdown = $this->format_locale_breakdown(); ?>
							<?php if ( '' !== $locale_breakdown ) : ?>
								<div style="margin-top:.4rem;font-size:.85em;color:#666">
									<?php
									printf(
										/* translators: %s: comma-separated "Language (count)" breakdown of confirmed subscribers */
										esc_html__( 'By language: %s', 'agnosis' ),
										esc_html( $locale_breakdown )
									);
									?>
								</div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $this->format_last_sent( $scheduler->last_sent_at( 'public' ) ) ); ?></td>
						<td><?php echo $scheduler->has_issue_in_flight( 'public' ) ? esc_html__( 'Sending…', 'agnosis' ) : esc_html__( 'Idle', 'agnosis' ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="agnosis_send_newsletter_now">
								<input type="hidden" name="newsletter_type" value="public">
								<?php wp_nonce_field( 'agnosis_send_newsletter_public' ); ?>
								<?php submit_button( __( 'Send Now', 'agnosis' ), 'small', 'submit', false, $scheduler->has_issue_in_flight( 'public' ) ? [ 'disabled' => 'disabled' ] : [] ); ?>
							</form>
						</td>
						<td><?php $this->render_retry_failed_button( 'public' ); ?></td>
						<td><?php $this->render_newsletter_test_form( 'public' ); ?></td>
					</tr>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( 'Add the "Newsletter Signup" block to any page to let visitors subscribe to the public newsletter. Artists are enrolled automatically and can unsubscribe from any issue with one click.', 'agnosis' ); ?>
			</p>
		</div>
		<?php
	}

	private function format_last_sent( ?string $mysql_datetime ): string {
		if ( ! $mysql_datetime ) {
			return __( 'Never', 'agnosis' );
		}
		return date_i18n( get_option( 'date_format' ), strtotime( $mysql_datetime ) );
	}

	/**
	 * Locale-coverage metric (audit §8 — "cheap signal for which LF languages
	 * earn their AI translation spend"), built on Subscriber::counts_by_locale().
	 * Returns '' when there are no confirmed subscribers to break down yet.
	 */
	private function format_locale_breakdown(): string {
		$by_locale = Subscriber::counts_by_locale();
		if ( empty( $by_locale ) ) {
			return '';
		}

		$parts = [];
		foreach ( $by_locale as $locale => $count ) {
			$parts[] = sprintf( '%1$s (%2$d)', $this->locale_label( $locale ), $count );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Human-readable language name for a subscriber's stored WP locale (e.g.
	 * 'es_ES' -> 'Spanish'). Uses Lingua Forge's own display-name lookup when
	 * LF is active — the same optional-dependency guard convention as the
	 * rest of Compat\LinguaForge — falling back to the raw locale code when
	 * LF isn't installed. An empty locale (never recorded — e.g. a subscriber
	 * from before the §3c frontend.js fix) is labelled explicitly rather than
	 * silently dropped, matching Subscriber::counts_by_locale()'s own '' bucket.
	 */
	private function locale_label( string $locale ): string {
		if ( '' === $locale ) {
			return __( 'Unknown', 'agnosis' );
		}

		if ( function_exists( 'linguaforge_language_label' ) ) {
			return (string) linguaforge_language_label( LinguaForge::locale_to_lang( $locale ) );
		}

		return $locale;
	}

	/**
	 * "Retry Failed" button for the Newsletter dashboard (fifth/sixth audit
	 * §5e). Shows the count of terminally-failed recipients for $type's most
	 * recent issue and, when that count is above zero, a button that resets
	 * them all back to 'pending' so the next few cron ticks retry them —
	 * see QueueProcessor::retry_failed(). Previously an SMTP outage longer
	 * than MAX_ATTEMPTS worth of cron ticks (~15 minutes) left those
	 * recipients permanently skipped with no resend affordance at all.
	 */
	private function render_retry_failed_button( string $type ): void {
		$scheduler = new Scheduler();
		$failed    = $scheduler->failed_count_for_latest_issue( $type );

		if ( 0 === $failed ) {
			esc_html_e( 'None', 'agnosis' );
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:.4rem;align-items:center">
			<input type="hidden" name="action" value="agnosis_retry_failed_newsletter_recipients">
			<input type="hidden" name="newsletter_type" value="<?php echo esc_attr( $type ); ?>">
			<?php wp_nonce_field( 'agnosis_retry_failed_newsletter_recipients_' . $type ); ?>
			<span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of recipients whose send permanently failed for the most recent issue */
						_n( '%d failed', '%d failed', $failed, 'agnosis' ),
						$failed
					)
				);
				?>
			</span>
			<?php submit_button( __( 'Retry Failed', 'agnosis' ), 'secondary small', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Inline "send a preview to one address" form for the Newsletter dashboard.
	 *
	 * Defaults to the current admin's own email. Sending a test does not touch
	 * the schedule, the issues table, or any subscriber — see Scheduler::send_test().
	 */
	private function render_newsletter_test_form( string $type ): void {
		$current_user = wp_get_current_user();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:.4rem;align-items:center">
			<input type="hidden" name="action" value="agnosis_send_newsletter_test">
			<input type="hidden" name="newsletter_type" value="<?php echo esc_attr( $type ); ?>">
			<?php wp_nonce_field( 'agnosis_send_newsletter_test_' . $type ); ?>
			<input type="email" name="test_email" value="<?php echo esc_attr( $current_user->user_email ); ?>" required class="small-text" style="width:14rem">
			<?php submit_button( __( 'Send Test', 'agnosis' ), 'secondary small', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * admin-post handler: manually trigger a newsletter issue right now.
	 */
	public function handle_send_newsletter_now(): void {
		$type = sanitize_key( wp_unslash( $_POST['newsletter_type'] ?? '' ) );

		check_admin_referer( 'agnosis_send_newsletter_' . $type );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$scheduler = new Scheduler();
		$result    = $scheduler->send_now( $type );

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'newsletter',
				'agnosis_message' => true === $result ? 'newsletter_sent' : 'newsletter_send_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * admin-post handler: reset all permanently-failed recipients of $type's
	 * most recent newsletter issue back to 'pending' so cron retries them
	 * (audit §5e). See QueueProcessor::retry_failed() and
	 * render_retry_failed_button().
	 */
	public function handle_retry_failed_newsletter_recipients(): void {
		$type = sanitize_key( wp_unslash( $_POST['newsletter_type'] ?? '' ) );

		check_admin_referer( 'agnosis_retry_failed_newsletter_recipients_' . $type );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$scheduler = new Scheduler();
		$issue_id  = $scheduler->latest_issue_id( $type );
		$requeued  = null !== $issue_id ? ( new QueueProcessor() )->retry_failed( $issue_id ) : 0;

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'newsletter',
				'agnosis_message' => $requeued > 0 ? 'newsletter_retry_queued' : 'newsletter_retry_none',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * admin-post handler: send a one-off preview of the next issue to a single
	 * address. Does not touch the schedule, issues table, or any subscriber.
	 */
	public function handle_send_newsletter_test(): void {
		$type = sanitize_key( wp_unslash( $_POST['newsletter_type'] ?? '' ) );

		check_admin_referer( 'agnosis_send_newsletter_test_' . $type );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$test_email = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );

		$scheduler = new Scheduler();
		$result    = $scheduler->send_test( $type, $test_email );

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'newsletter',
				'agnosis_message' => true === $result ? 'newsletter_test_sent' : 'newsletter_test_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
