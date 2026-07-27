<?php
/**
 * "Fediverse Relays" panel (Network tab) — interaction-surface roadmap,
 * Phase 3, WP8 (§7 Q8, §4 Phase 3F item 2). Agnosis could already receive an
 * inbound Follow (ActivityPub::handle_follow()) but had no code path to send
 * one; a relay is a well-known fediverse actor other servers subscribe to,
 * and every artwork this node Creates/Updates/Deletes reaches every OTHER
 * subscriber once this node follows it too — the cheapest real
 * discoverability win that works with the fediverse exactly as deployed
 * today, no FASP or new protocol needed.
 *
 * Decisions locked (§7 Q8): wanted, with an off switch; node-level and
 * admin-only — the one genuinely operator-facing decision in this whole
 * roadmap (every other Phase 3 track is either artist- or visitor-facing).
 *
 * Storage: a single small option (`agnosis_ap_relays`, autoload=false — read
 * only on this admin screen, never per-request), a plain `[ actor_url =>
 * bool $enabled ]` map. Not a database table: relays are hand-configured by
 * an admin and realistically number in the single digits, unlike
 * `agnosis_followers` (real fediverse-scale growth, hence its own table).
 *
 * Follows/Undo{Follow}s (ActivityPub::follow_relay()/unfollow_relay()) fire
 * exactly on the state transitions below — never on every page load or
 * every settings save of anything else on this page:
 *   - Add a relay          → Follow
 *   - Disable a relay      → Undo{Follow}
 *   - Re-enable a relay    → Follow (again)
 *   - Remove a relay       → Undo{Follow} first, if it was enabled, then delete the row
 *
 * Mirrors BiographyTitleCache's exact shape (per-row forms, admin-post
 * handlers, `check_admin_referer()`/`current_user_can()` guard, a shared
 * redirect back to this tab) — nothing here invents a new admin-UI pattern.
 *
 * @package Agnosis\Admin\Dashboards
 */

declare(strict_types=1);

namespace Agnosis\Admin\Dashboards;

use Agnosis\Network\ActivityPub;

class RelayManager {

	private const OPTION = 'agnosis_ap_relays';

	/** @return array<string, bool> */
	private function relays(): array {
		$relays = get_option( self::OPTION, [] );
		return is_array( $relays ) ? $relays : [];
	}

	public function render(): void {
		$relays = $this->relays();
		?>
		<div class="card" style="max-width:800px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Fediverse Relays', 'agnosis' ); ?></h2>
			<p class="description" style="margin-bottom:1rem">
				<?php esc_html_e( 'A relay re-broadcasts everything it receives to every other server subscribed to it — following one is the cheapest way for this node\'s public artwork to reach servers that would otherwise never see it. This is node-level: it applies to every artwork, not a per-artist choice.', 'agnosis' ); ?>
			</p>

			<?php if ( empty( $relays ) ) : ?>
				<p style="color:#666"><?php esc_html_e( 'No relays configured yet.', 'agnosis' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="border-radius:4px;overflow:hidden">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Relay actor URL', 'agnosis' ); ?></th>
							<th style="width:120px"><?php esc_html_e( 'Status', 'agnosis' ); ?></th>
							<th style="width:1%"></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $relays as $url => $enabled ) : ?>
						<tr>
							<td><code><?php echo esc_html( $url ); ?></code></td>
							<td>
								<?php if ( $enabled ) : ?>
									<strong style="color:#0a7c48">✓ <?php esc_html_e( 'Enabled', 'agnosis' ); ?></strong>
								<?php else : ?>
									<strong style="color:#999">✗ <?php esc_html_e( 'Disabled', 'agnosis' ); ?></strong>
								<?php endif; ?>
							</td>
							<td style="white-space:nowrap">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
									<input type="hidden" name="action" value="agnosis_toggle_relay">
									<input type="hidden" name="relay_url" value="<?php echo esc_attr( $url ); ?>">
									<?php wp_nonce_field( 'agnosis_toggle_relay_' . md5( $url ), 'agnosis_nonce' ); ?>
									<?php submit_button( $enabled ? __( 'Disable', 'agnosis' ) : __( 'Enable', 'agnosis' ), 'small', 'submit', false ); ?>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block"
									onsubmit="return confirm('<?php echo esc_js( __( 'Remove this relay? If it\'s currently enabled, an Undo{Follow} is sent first so the relay stops delivering to this node.', 'agnosis' ) ); ?>')">
									<input type="hidden" name="action" value="agnosis_remove_relay">
									<input type="hidden" name="relay_url" value="<?php echo esc_attr( $url ); ?>">
									<?php wp_nonce_field( 'agnosis_remove_relay_' . md5( $url ), 'agnosis_nonce' ); ?>
									<?php submit_button( __( 'Remove', 'agnosis' ), 'small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem;display:flex;gap:6px;align-items:center">
				<input type="hidden" name="action" value="agnosis_add_relay">
				<?php wp_nonce_field( 'agnosis_add_relay' ); ?>
				<label class="screen-reader-text" for="agnosis-relay-url"><?php esc_html_e( 'Relay actor URL', 'agnosis' ); ?></label>
				<input type="url" id="agnosis-relay-url" name="relay_url" placeholder="https://relay.example/actor" style="width:100%;max-width:420px" required>
				<?php submit_button( __( 'Add Relay', 'agnosis' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// admin-post handlers
	// -------------------------------------------------------------------------

	/** admin-post handler: add a new relay and send it a Follow. A no-op (not an error) if the URL is malformed or already present — an admin re-submitting the same URL must never double-Follow or lose the relay's current enabled/disabled state. */
	public function handle_add(): void {
		check_admin_referer( 'agnosis_add_relay' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$url = esc_url_raw( trim( (string) wp_unslash( $_POST['relay_url'] ?? '' ) ) );

		if ( '' !== $url && false !== filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$relays = $this->relays();
			if ( ! isset( $relays[ $url ] ) ) {
				$relays[ $url ] = true;
				update_option( self::OPTION, $relays, false );
				( new ActivityPub() )->follow_relay( $url );
			}
		}

		$this->redirect();
	}

	/** admin-post handler: flip one relay's enabled state, sending Follow (re-enabling) or Undo{Follow} (disabling) accordingly. */
	public function handle_toggle(): void {
		$url = esc_url_raw( wp_unslash( $_POST['relay_url'] ?? '' ) );
		check_admin_referer( 'agnosis_toggle_relay_' . md5( $url ), 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$relays      = $this->relays();
		$activitypub = new ActivityPub();

		if ( isset( $relays[ $url ] ) ) {
			if ( $relays[ $url ] ) {
				$relays[ $url ] = false;
				$activitypub->unfollow_relay( $url );
			} else {
				$relays[ $url ] = true;
				$activitypub->follow_relay( $url );
			}
			update_option( self::OPTION, $relays, false );
		}

		$this->redirect();
	}

	/** admin-post handler: remove a relay entirely, sending Undo{Follow} first if it was enabled — leaving must be clean, not just going quiet on our own end. */
	public function handle_remove(): void {
		$url = esc_url_raw( wp_unslash( $_POST['relay_url'] ?? '' ) );
		check_admin_referer( 'agnosis_remove_relay_' . md5( $url ), 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$relays = $this->relays();

		if ( isset( $relays[ $url ] ) ) {
			if ( $relays[ $url ] ) {
				( new ActivityPub() )->unfollow_relay( $url );
			}
			unset( $relays[ $url ] );
			update_option( self::OPTION, $relays, false );
		}

		$this->redirect();
	}

	private function redirect(): void {
		wp_safe_redirect( add_query_arg( [ 'page' => 'agnosis-settings', 'tab' => 'network' ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
