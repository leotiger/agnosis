<?php
/**
 * "Deliverability" diagnostic card (Email tab) — SPF/DMARC/DKIM/Spamhaus DBL
 * checks, the four status-pill helpers behind them, and the admin-post
 * handler for its "send a real test email" form.
 *
 * Split out of Admin\Settings (2026-07-17, AUDIT-1.0.0.md §4d — the "god
 * class" finding): the render method, its four private badge helpers (used
 * only by this render method), and its one handler were already a
 * self-contained cluster, so this is a pure move — same behavior, same hook
 * name (`admin_post_agnosis_send_deliverability_test`, rewired in Core\Plugin
 * to this class instead of Settings).
 *
 * @package Agnosis\Admin\Dashboards
 */

declare(strict_types=1);

namespace Agnosis\Admin\Dashboards;

use Agnosis\Admin\Deliverability;
use Agnosis\Core\CommunityMailer;

class DeliverabilityCard {

	/**
	 * Diagnostic-only deliverability panel: SPF/DMARC records per From
	 * identity used by the plugin, a best-effort DKIM lookup, and whether the
	 * domain is listed on Spamhaus's DBL — a confirmed real-world cause of
	 * "sent successfully but never arrived", especially for a domain still
	 * building sending reputation. Also flags a detected SMTP-sending
	 * plugin, and offers the same one-click "send a test to my address"
	 * pattern the newsletter dashboard already uses
	 * (NewsletterDashboard::render_newsletter_test_form()) via
	 * CommunityMailer's own headers, so the test reflects the same identity
	 * workflow mail actually sends from.
	 */
	public function render(): void {
		$rows        = Deliverability::identity_report();
		$smtp_plugin = Deliverability::detected_smtp_plugin();
		$current_user = wp_get_current_user();
		?>
		<div class="card" style="max-width:960px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Deliverability', 'agnosis' ); ?></h2>
			<p class="description" style="margin-top:0">
				<?php esc_html_e( 'A diagnostic check only — nothing here changes how mail is sent. On ordinary hosting (PHP mail(), no SMTP plugin), mail sent from an address that doesn\'t match your site\'s own domain, or from a domain with no SPF/DMARC record at all, is increasingly likely to land in spam or be rejected outright by large mailbox providers. If any row below looks wrong, the most reliable fix is installing an SMTP-sending plugin (WP Mail SMTP, Post SMTP, FluentSMTP, and others all work) configured against a real transactional mail provider, and adding SPF/DMARC records for the domain you send from at your DNS host.', 'agnosis' ); ?>
			</p>

			<table class="widefat striped" style="margin-bottom:.5rem">
				<thead>
					<tr>
						<th><?php esc_html_e( 'From identity', 'agnosis' ); ?></th>
						<th><?php esc_html_e( 'Domain matches site?', 'agnosis' ); ?></th>
						<th><?php esc_html_e( 'SPF record', 'agnosis' ); ?></th>
						<th><?php esc_html_e( 'DMARC record', 'agnosis' ); ?></th>
						<th><?php esc_html_e( 'DKIM record', 'agnosis' ); ?></th>
						<th><?php esc_html_e( 'Spamhaus DBL', 'agnosis' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $row['label'] ); ?></strong><br>
								<code><?php echo esc_html( $row['email'] ); ?></code>
							</td>
							<td><?php echo wp_kses_post( $this->deliverability_domain_badge( $row['domain_matches_site'], $row['domain'] ) ); ?></td>
							<td><?php echo wp_kses_post( $this->deliverability_status_badge( $row['spf']['status'] ) ); ?></td>
							<td><?php echo wp_kses_post( $this->deliverability_status_badge( $row['dmarc']['status'] ) ); ?></td>
							<td><?php echo wp_kses_post( $this->deliverability_dkim_badge( $row['dkim'] ) ); ?></td>
							<td><?php echo wp_kses_post( $this->deliverability_dbl_badge( $row['dbl'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<td><strong><?php esc_html_e( 'SMTP plugin', 'agnosis' ); ?></strong></td>
						<td colspan="5">
							<?php if ( $smtp_plugin ) : ?>
								✅
								<?php
								echo esc_html( sprintf(
									/* translators: %s: detected plugin name, e.g. "WP Mail SMTP" */
									__( 'Detected: %s', 'agnosis' ),
									$smtp_plugin
								) );
								?>
							<?php else : ?>
								⚠️ <?php esc_html_e( 'None of the common SMTP-sending plugins were detected — this site is likely relying on PHP\'s built-in mail(), which most hosts deliver poorly through.', 'agnosis' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="description" style="margin-top:0;margin-bottom:1rem">
				<?php esc_html_e( 'DKIM is best-effort: there\'s no fixed location for a DKIM record the way SPF and DMARC have, so this only checks a handful of common provider selectors. "Not found" here does not mean DKIM isn\'t configured — only that it isn\'t under one of the names this check happens to try. If you already know your DKIM is set up correctly (your provider\'s dashboard confirmed it), trust that over this row.', 'agnosis' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:.4rem;align-items:center">
				<input type="hidden" name="action" value="agnosis_send_deliverability_test">
				<?php wp_nonce_field( 'agnosis_send_deliverability_test' ); ?>
				<label for="agnosis_deliverability_test_email" style="margin:0"><?php esc_html_e( 'Send a real test email to:', 'agnosis' ); ?></label>
				<input type="email" id="agnosis_deliverability_test_email" name="test_email" value="<?php echo esc_attr( $current_user->user_email ); ?>" required class="small-text" style="width:14rem">
				<?php submit_button( __( 'Send Test', 'agnosis' ), 'secondary small', 'submit', false ); ?>
			</form>
			<p class="description">
				<?php esc_html_e( 'Sends one plain-text email from the same community identity used for review/vote/vouch mail. Reaching your inbox is a good sign; landing in spam or not arriving at all confirms a real deliverability problem regardless of what the checks above say.', 'agnosis' ); ?>
			</p>
		</div>
		<?php
	}

	/** Small inline status pill for an SPF/DMARC lookup result. */
	private function deliverability_status_badge( string $status ): string {
		return match ( $status ) {
			'found'         => '<span style="color:#1a7f37">✅ ' . esc_html__( 'Found', 'agnosis' ) . '</span>',
			'not_found'     => '<span style="color:#b32d2e">❌ ' . esc_html__( 'Not found', 'agnosis' ) . '</span>',
			'lookup_failed' => '<span style="color:#996800">⚠️ ' . esc_html__( 'DNS lookup failed', 'agnosis' ) . '</span>',
			default         => '<span style="color:#888">— ' . esc_html__( 'Unavailable on this host', 'agnosis' ) . '</span>',
		};
	}

	/**
	 * Small inline status pill for the best-effort DKIM lookup result.
	 * "Found" additionally names the matched selector, since that's the one
	 * piece of information from this check worth an admin double-checking
	 * against their provider's own dashboard. Every other status reuses the
	 * same SPF/DMARC pill (deliverability_status_badge()) — the underlying
	 * status vocabulary is identical ('found'/'not_found'/'lookup_failed'/
	 * anything else -> "unavailable").
	 *
	 * @param array{status: string, selector: string, records: string[]} $dkim Deliverability::dkim_check()'s return value.
	 */
	private function deliverability_dkim_badge( array $dkim ): string {
		if ( 'found' === $dkim['status'] ) {
			return '<span style="color:#1a7f37">✅ ' . esc_html( sprintf(
				/* translators: %s: DKIM selector, e.g. "google" */
				__( 'Found (selector: %s)', 'agnosis' ),
				$dkim['selector']
			) ) . '</span>';
		}
		if ( 'not_found' === $dkim['status'] ) {
			// Amber, not red — see the description note under this table:
			// this specifically means "not found under a guessed selector",
			// not a confirmed absence, unlike SPF/DMARC's "not_found".
			return '<span style="color:#996800">❓ ' . esc_html__( 'Not found under common selectors', 'agnosis' ) . '</span>';
		}
		return $this->deliverability_status_badge( $dkim['status'] );
	}

	/**
	 * Small inline status pill for the Spamhaus DBL lookup — deliberately an
	 * inverted palette from every other row on this card: here "not listed"
	 * (nothing wrong) is the green/good outcome, and "listed" (a real,
	 * confirmed problem) is red, with the specific listing reason shown when
	 * Spamhaus supplies one.
	 *
	 * @param array{status: string, reason: string} $dbl Deliverability::dbl_check()'s return value.
	 */
	private function deliverability_dbl_badge( array $dbl ): string {
		return match ( $dbl['status'] ) {
			'not_listed'    => '<span style="color:#1a7f37">✅ ' . esc_html__( 'Not listed', 'agnosis' ) . '</span>',
			'listed'        => '<span style="color:#b32d2e">🚫 ' . esc_html__( 'LISTED', 'agnosis' ) . '</span>'
				. ( '' !== $dbl['reason'] ? '<br><code style="font-size:.85em">' . esc_html( $dbl['reason'] ) . '</code>' : '' ),
			'lookup_failed' => '<span style="color:#996800">⚠️ ' . esc_html__( 'DNS lookup failed', 'agnosis' ) . '</span>',
			default         => '<span style="color:#888">— ' . esc_html__( 'Unavailable on this host', 'agnosis' ) . '</span>',
		};
	}

	/** Small inline status pill for the From-domain-vs-site-domain comparison. */
	private function deliverability_domain_badge( bool $matches, string $domain ): string {
		if ( '' === $domain ) {
			return '<span style="color:#888">— ' . esc_html__( 'No address configured', 'agnosis' ) . '</span>';
		}
		return $matches
			? '<span style="color:#1a7f37">✅ ' . esc_html( $domain ) . '</span>'
			: '<span style="color:#996800">⚠️ ' . esc_html( $domain ) . '</span>';
	}

	/**
	 * admin-post handler: send a one-off deliverability test email using the
	 * same community/transactional identity real workflow mail sends from.
	 * Diagnostic only — does not touch any subscriber, queue, or setting.
	 */
	public function handle_send_deliverability_test(): void {
		check_admin_referer( 'agnosis_send_deliverability_test' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$test_email = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
		$result     = false;

		if ( is_email( $test_email ) ) {
			$result = wp_mail(
				$test_email,
				sprintf(
					/* translators: %s: site name */
					__( '[TEST] Deliverability check from %s', 'agnosis' ),
					get_bloginfo( 'name' )
				),
				__( "This is a one-off deliverability test from your Agnosis Settings → Email Inbox page. If you're reading this in your inbox (not spam), the community/transactional From identity is deliverable to this address.", 'agnosis' ),
				CommunityMailer::text_headers()
			);
		}

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'email',
				'agnosis_message' => $result ? 'deliverability_test_sent' : 'deliverability_test_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
