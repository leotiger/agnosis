<?php
/**
 * "Partner Nodes" panel (Settings → Rhizome tab) — RN1,
 * RHIZOME-NETWORK-ROADMAP.md §4/§8, built 2026-07-30.
 *
 * `includes/Network/Node.php` has quietly exposed a full self-registration
 * flow (`POST /wp-json/agnosis/v1/node/peers`) since 0.9.28 — any Agnosis
 * install can ask to become a trusted peer — but nothing anywhere ever
 * promoted a `pending` row to `trusted`, or gave an admin any way to see
 * what had registered at all. This is that front door: list every row in
 * `agnosis_nodes` regardless of status, and let an admin approve, block,
 * unblock, remove, or re-scope trust — plus a manual "add a trusted peer
 * directly" path for a non-Agnosis Fediverse actor an admin wants to vouch
 * for by hand, gated behind its own settings toggle
 * (`agnosis_rhizome_allow_manual_trust`, SettingsFields.php).
 *
 * Approving a self-registered Agnosis peer resolves its real actor id and
 * inbox URL via `Node::resolve_peer_node_card()` (a live HTTP fetch of that
 * peer's own node card) — those fields don't exist yet on a freshly
 * `pending` row, only `register_peer()`'s bare site `url`. A manual
 * third-party add skips that fetch entirely: the admin pastes the actor and
 * inbox URLs directly.
 *
 * Mirrors RelayManager.php's exact shape (per-row admin-post forms, nonces,
 * `current_user_can('manage_options')` guard, a shared redirect back to this
 * tab) — nothing here invents a new admin-UI pattern. Trust-decision logic
 * itself (nothing here yet actually RELAYS anything — that's RN3, not built)
 * has no effect on federation until RN3 ships; this panel only builds and
 * curates the list RN3 will eventually check against.
 *
 * @package Agnosis\Admin\Dashboards
 */

declare(strict_types=1);

namespace Agnosis\Admin\Dashboards;

use Agnosis\Network\Node;

class RhizomeManager {

	private const TABLE  = 'agnosis_nodes';
	private const OPTION = 'agnosis_rhizome_allow_manual_trust';

	/** Row sort order: rows needing a decision surface first. */
	private const STATUS_ORDER = [ 'pending' => 0, 'trusted' => 1, 'blocked' => 2 ];

	/** @return array<int, object{id: string, url: string, label: string|null, description: string|null, trust_scope: string, actor_id: string|null, inbox_url: string|null, status: string, reciprocal: string, reciprocity_checked_at: string|null, last_seen: string|null, created_at: string}> */
	private function peers(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- self::TABLE is a hardcoded class constant, never request input; nothing here needs a placeholder.
		$rows = $wpdb->get_results( 'SELECT id, url, label, description, trust_scope, actor_id, inbox_url, status, reciprocal, reciprocity_checked_at, last_seen, created_at FROM ' . $wpdb->prefix . self::TABLE . ' ORDER BY created_at DESC' );

		usort( $rows, static fn( $a, $b ) => ( self::STATUS_ORDER[ $a->status ] ?? 9 ) <=> ( self::STATUS_ORDER[ $b->status ] ?? 9 ) );

		return $rows;
	}

	public function render(): void {
		$peers              = $this->peers();
		$allow_manual_trust = (bool) get_option( self::OPTION, false );
		?>
		<div class="card" style="max-width:960px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Partner Nodes', 'agnosis' ); ?></h2>
			<p class="description" style="margin-bottom:1rem">
				<?php esc_html_e( 'A trusted partner’s boosts also reach this node’s own followers, extending its reach through your node the same way one artist can already boost another’s work. Nothing here shows another instance’s content on your own site — it only affects what leaves this node toward the wider Fediverse. Every peer starts here as "Pending" until approved.', 'agnosis' ); ?>
			</p>

			<?php $this->render_notices(); ?>

			<?php if ( empty( $peers ) ) : ?>
				<p style="color:#666"><?php esc_html_e( 'No peers have registered or been added yet.', 'agnosis' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="border-radius:4px;overflow:hidden">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Peer', 'agnosis' ); ?></th>
							<th style="width:110px"><?php esc_html_e( 'Status', 'agnosis' ); ?></th>
							<th style="width:130px"><?php esc_html_e( 'Trust scope', 'agnosis' ); ?></th>
							<th style="width:150px"><?php esc_html_e( 'Reciprocal?', 'agnosis' ); ?></th>
							<th style="width:1%"></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $peers as $peer ) : ?>
						<?php $this->render_row( $peer ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $allow_manual_trust ) : ?>
				<?php $this->render_manual_add_form(); ?>
			<?php else : ?>
				<p class="description" style="margin-top:1rem">
					<?php
					printf(
						/* translators: %s: link to the Rhizome settings toggle */
						esc_html__( 'Manually adding a non-Agnosis Fediverse actor as a trusted peer is currently off. Enable "Allow manually trusting non-Agnosis Fediverse actors" %s to add one directly.', 'agnosis' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=agnosis-settings&tab=rhizome' ) ) . '">' . esc_html__( 'above', 'agnosis' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param object{id: string, url: string, label: string|null, description: string|null, trust_scope: string, actor_id: string|null, inbox_url: string|null, status: string, reciprocal: string, reciprocity_checked_at: string|null, last_seen: string|null, created_at: string} $peer */
	private function render_row( object $peer ): void {
		$id = (int) $peer->id;
		?>
		<tr>
			<td>
				<a href="<?php echo esc_url( $peer->url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $peer->label ?: $peer->url ); ?></a>
				<br><code style="font-size:11px;color:#666"><?php echo esc_html( $peer->url ); ?></code>
				<?php if ( ! empty( $peer->description ) ) : ?>
					<p class="description" style="margin:.35rem 0 0"><?php echo esc_html( $peer->description ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $peer->actor_id ) ) : ?>
					<p class="description" style="margin:.15rem 0 0;font-size:11px">
						<?php esc_html_e( 'Resolved actor:', 'agnosis' ); ?> <code><?php echo esc_html( $peer->actor_id ); ?></code>
					</p>
				<?php endif; ?>
			</td>
			<td><?php $this->render_status_badge( $peer->status ); ?></td>
			<td>
				<?php if ( 'trusted' === $peer->status ) : ?>
					<?php $this->render_trust_scope_form( $id, $peer->trust_scope, (string) ( $peer->label ?: $peer->url ) ); ?>
				<?php else : ?>
					<span style="color:#999">&mdash;</span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( 'trusted' === $peer->status ) : ?>
					<?php $this->render_reciprocity_cell( $id, $peer->reciprocal, $peer->reciprocity_checked_at ); ?>
				<?php else : ?>
					<span style="color:#999">&mdash;</span>
				<?php endif; ?>
			</td>
			<td style="white-space:nowrap">
				<?php $this->render_row_actions( $id, $peer->status ); ?>
			</td>
		</tr>
		<?php
	}

	/** RN4 (RHIZOME-NETWORK-ROADMAP.md §4/§8) — badge + on-demand "Check" button, only ever shown for a `trusted` row (see render_row()); reciprocity has no meaning for a `pending`/`blocked` one. Live-checked on click, not on every page load, mirroring the on-demand-fetch precedent RN1's own "Approve" button already set for resolve_peer_node_card(). */
	private function render_reciprocity_cell( int $id, string $reciprocal, ?string $checked_at ): void {
		$map = [
			'mutual'          => [ 'bg' => '#d1fae5', 'color' => '#065f46', 'label' => __( 'Mutual', 'agnosis' ) ],
			'one_directional' => [ 'bg' => '#fef3c7', 'color' => '#92400e', 'label' => __( 'One-directional', 'agnosis' ) ],
			'unknown'         => [ 'bg' => '#f3f4f6', 'color' => '#4b5563', 'label' => __( 'Unknown', 'agnosis' ) ],
		];
		$b = $map[ $reciprocal ] ?? $map['unknown'];
		printf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;background:%s;color:%s">%s</span>',
			esc_attr( $b['bg'] ),
			esc_attr( $b['color'] ),
			esc_html( $b['label'] )
		);
		if ( ! empty( $checked_at ) ) {
			printf(
				'<p class="description" style="margin:.15rem 0 0;font-size:11px">%s %s</p>',
				esc_html__( 'Checked:', 'agnosis' ),
				esc_html( (string) mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $checked_at ) )
			);
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.35rem">
			<input type="hidden" name="action" value="agnosis_rhizome_check_reciprocity">
			<input type="hidden" name="peer_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( 'agnosis_rhizome_check_reciprocity_' . $id, 'agnosis_nonce' ); ?>
			<?php submit_button( __( 'Check', 'agnosis' ), 'secondary button-small', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_status_badge( string $status ): void {
		$map = [
			'pending' => [ 'bg' => '#fef3c7', 'color' => '#92400e', 'label' => __( 'Pending', 'agnosis' ) ],
			'trusted' => [ 'bg' => '#d1fae5', 'color' => '#065f46', 'label' => __( 'Trusted', 'agnosis' ) ],
			'blocked' => [ 'bg' => '#fee2e2', 'color' => '#991b1b', 'label' => __( 'Blocked', 'agnosis' ) ],
		];
		$b = $map[ $status ] ?? $map['pending'];
		printf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;background:%s;color:%s">%s</span>',
			esc_attr( $b['bg'] ),
			esc_attr( $b['color'] ),
			esc_html( $b['label'] )
		);
	}

	/**
	 * @param string $peer_name This peer's own display name, used to give the
	 *                          select a distinct accessible name per row —
	 *                          sixteenth audit, A-2 (2026-07-31).
	 */
	private function render_trust_scope_form( int $id, string $current_scope, string $peer_name = '' ): void {
		// A-2: the control had no accessible name at all — no id, no <label>, no
		// aria-label — so a screen-reader user in this table heard "combo box,
		// Domain" with nothing to say WHICH peer's trust scope it governs, on a
		// screen whose whole purpose is per-peer trust decisions. Same per-row
		// shape the fifteenth audit's own A-2 solved for MembersDashboard's
		// banned_until and BiographyTitleCache's translation field: a real
		// visually-hidden <label> (not aria-label — see that finding for why),
		// with an id unique per row and a name that identifies the peer.
		$select_id = 'agnosis-rhizome-trust-scope-' . $id;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:4px;align-items:center">
			<input type="hidden" name="action" value="agnosis_rhizome_set_trust_scope">
			<input type="hidden" name="peer_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( 'agnosis_rhizome_set_trust_scope_' . $id, 'agnosis_nonce' ); ?>
			<label class="screen-reader-text" for="<?php echo esc_attr( $select_id ); ?>">
				<?php
				if ( '' !== $peer_name ) {
					printf(
						/* translators: %s: the partner node's own name or URL. */
						esc_html__( 'Trust scope for %s', 'agnosis' ),
						esc_html( $peer_name )
					);
				} else {
					esc_html_e( 'Trust scope', 'agnosis' );
				}
				?>
			</label>
			<select id="<?php echo esc_attr( $select_id ); ?>" name="trust_scope" style="font-size:12px">
				<option value="domain" <?php selected( $current_scope, 'domain' ); ?>><?php esc_html_e( 'Domain', 'agnosis' ); ?></option>
				<option value="actor" <?php selected( $current_scope, 'actor' ); ?>><?php esc_html_e( 'Actor only', 'agnosis' ); ?></option>
			</select>
			<?php submit_button( __( 'Save', 'agnosis' ), 'small', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_row_actions( int $id, string $status ): void {
		if ( 'pending' === $status ) {
			$this->action_button( $id, 'approve', __( 'Approve', 'agnosis' ), 'button-primary' );
			$this->action_button( $id, 'block', __( 'Block', 'agnosis' ) );
		} elseif ( 'trusted' === $status ) {
			$this->action_button( $id, 'block', __( 'Block', 'agnosis' ) );
		} else { // blocked
			$this->action_button( $id, 'unblock', __( 'Unblock', 'agnosis' ) );
		}
		$this->action_button(
			$id,
			'remove',
			__( 'Remove', 'agnosis' ),
			'',
			__( 'Remove this peer entirely? It will need to register again from scratch.', 'agnosis' )
		);
	}

	private function action_button( int $id, string $action, string $label, string $class = '', string $confirm = '' ): void {
		$confirm_attr = '' !== $confirm
			? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"'
			: '';
		printf(
			'<form method="post" action="%1$s" style="display:inline-block" %2$s>'
			. '<input type="hidden" name="action" value="agnosis_rhizome_%3$s">'
			. '<input type="hidden" name="peer_id" value="%4$d">'
			. '%5$s%6$s</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $confirm_attr is built entirely from esc_js() above plus a static attribute wrapper.
			$confirm_attr,
			esc_attr( $action ),
			(int) $id,
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() with $echo=false already escapes/encodes its own output.
			wp_nonce_field( 'agnosis_rhizome_' . $action . '_' . $id, 'agnosis_nonce', true, false ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_submit_button() already escapes its own output.
			get_submit_button( $label, ( '' !== $class ? $class : 'secondary' ) . ' button-small', 'submit', false )
		);
	}

	private function render_manual_add_form(): void {
		?>
		<h3 style="margin-top:1.5rem"><?php esc_html_e( 'Add a trusted peer directly', 'agnosis' ); ?></h3>
		<p class="description" style="margin-bottom:.75rem">
			<?php esc_html_e( 'For a non-Agnosis Fediverse actor (a curator account, a specific instance you already trust) that you want to vouch for by hand, without going through node self-registration. Added as trusted immediately.', 'agnosis' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid;gap:8px;max-width:520px">
			<input type="hidden" name="action" value="agnosis_rhizome_add_manual">
			<?php wp_nonce_field( 'agnosis_rhizome_add_manual', 'agnosis_nonce' ); ?>
			<label>
				<?php esc_html_e( 'Actor URL', 'agnosis' ); ?>
				<input type="url" name="actor_url" placeholder="https://mastodon.example/@curator" style="width:100%" required>
			</label>
			<label>
				<?php esc_html_e( 'Inbox URL', 'agnosis' ); ?>
				<input type="url" name="inbox_url" placeholder="https://mastodon.example/@curator/inbox" style="width:100%" required>
			</label>
			<label>
				<?php esc_html_e( 'Label (optional)', 'agnosis' ); ?>
				<input type="text" name="label" style="width:100%">
			</label>
			<label>
				<?php esc_html_e( 'Description (optional)', 'agnosis' ); ?>
				<textarea name="description" rows="2" style="width:100%"></textarea>
			</label>
			<label>
				<?php esc_html_e( 'Trust scope', 'agnosis' ); ?>
				<select name="trust_scope">
					<option value="actor" selected><?php esc_html_e( 'Actor only (recommended for a third-party server)', 'agnosis' ); ?></option>
					<option value="domain"><?php esc_html_e( 'Domain (every actor on that server)', 'agnosis' ); ?></option>
				</select>
			</label>
			<?php submit_button( __( 'Add Trusted Peer', 'agnosis' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- flag from our own redirect, display only.
		if ( isset( $_GET['rhizome_error'] ) ) {
			$map = [
				'unreachable' => __( 'Could not reach this peer\'s node-discovery endpoint. It may be offline or not running Agnosis.', 'agnosis' ),
				'no_endpoint' => __( 'This peer\'s node-discovery response did not include a node card endpoint.', 'agnosis' ),
				'card_unreachable' => __( 'Could not fetch this peer\'s node card.', 'agnosis' ),
				'card_incomplete' => __( 'This peer\'s node card is missing an actor id or inbox URL.', 'agnosis' ),
				'invalid_manual' => __( 'Actor URL and inbox URL are both required.', 'agnosis' ),
				'reciprocity_unreachable' => __( 'Could not check reciprocity right now — this peer\'s own peer list was unreachable or not in the expected format.', 'agnosis' ),
			];
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- flag from our own redirect, display only.
			$key = sanitize_key( wp_unslash( $_GET['rhizome_error'] ) );
			if ( isset( $map[ $key ] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $map[ $key ] ) . '</p></div>';
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- flag from our own redirect, display only.
		if ( isset( $_GET['rhizome_ok'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Done.', 'agnosis' ) . '</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// admin-post handlers
	// -------------------------------------------------------------------------

	/** admin-post handler: approve a pending peer — resolves its real actor id/inbox via its own node card, then marks it trusted. */
	public function handle_approve(): void {
		$id = isset( $_POST['peer_id'] ) ? absint( wp_unslash( $_POST['peer_id'] ) ) : 0;
		check_admin_referer( 'agnosis_rhizome_approve_' . $id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- self::TABLE is a hardcoded class constant, never request input; the %d placeholder covers the only request-derived value ($id).
		$peer_url = $wpdb->get_var( $wpdb->prepare( 'SELECT url FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d', $id ) );

		if ( ! $peer_url ) {
			$this->redirect();
		}

		$resolved = ( new Node() )->resolve_peer_node_card( (string) $peer_url );

		if ( is_wp_error( $resolved ) ) {
			$error_map = [
				'agnosis_peer_unreachable'      => 'unreachable',
				'agnosis_peer_no_endpoint'      => 'no_endpoint',
				'agnosis_peer_card_unreachable' => 'card_unreachable',
				'agnosis_peer_card_incomplete'  => 'card_incomplete',
			];
			$this->redirect( $error_map[ $resolved->get_error_code() ] ?? 'unreachable' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write.
		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			[
				'status'    => 'trusted',
				'actor_id'  => $resolved['actor_id'],
				'inbox_url' => $resolved['inbox_url'],
				'last_seen' => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		$this->redirect( '', true );
	}

	/** admin-post handler: block a peer (from any status). */
	public function handle_block(): void {
		$id = isset( $_POST['peer_id'] ) ? absint( wp_unslash( $_POST['peer_id'] ) ) : 0;
		check_admin_referer( 'agnosis_rhizome_block_' . $id, 'agnosis_nonce' );
		$this->guard_and_set_status( $id, 'blocked' );
	}

	/** admin-post handler: unblock a peer — the direct inverse of block(), restoring trusted status (and whatever trust_scope/actor_id/inbox_url it already had, untouched by blocking). */
	public function handle_unblock(): void {
		$id = isset( $_POST['peer_id'] ) ? absint( wp_unslash( $_POST['peer_id'] ) ) : 0;
		check_admin_referer( 'agnosis_rhizome_unblock_' . $id, 'agnosis_nonce' );
		$this->guard_and_set_status( $id, 'trusted' );
	}

	/** admin-post handler: remove a peer row entirely. */
	public function handle_remove(): void {
		$id = isset( $_POST['peer_id'] ) ? absint( wp_unslash( $_POST['peer_id'] ) ) : 0;
		check_admin_referer( 'agnosis_rhizome_remove_' . $id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write.
		$wpdb->delete( $wpdb->prefix . self::TABLE, [ 'id' => $id ], [ '%d' ] );

		$this->redirect( '', true );
	}

	/** admin-post handler: change a trusted peer's trust_scope (domain/actor). */
	public function handle_set_trust_scope(): void {
		$id = isset( $_POST['peer_id'] ) ? absint( wp_unslash( $_POST['peer_id'] ) ) : 0;
		check_admin_referer( 'agnosis_rhizome_set_trust_scope_' . $id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$scope = isset( $_POST['trust_scope'] ) ? sanitize_key( wp_unslash( $_POST['trust_scope'] ) ) : 'domain';
		if ( ! in_array( $scope, [ 'domain', 'actor' ], true ) ) {
			$scope = 'domain';
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write.
		$wpdb->update( $wpdb->prefix . self::TABLE, [ 'trust_scope' => $scope ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );

		$this->redirect( '', true );
	}

	/**
	 * admin-post handler: RN4 (RHIZOME-NETWORK-ROADMAP.md §4/§8) — live-check
	 * whether a trusted peer trusts this node back, via
	 * `Node::check_reciprocity()`, and store the result. On failure
	 * (unreachable/malformed — including a manually-added third-party peer,
	 * which never exposes this endpoint at all), `reciprocal` is written
	 * back to `'unknown'` rather than left at its previous value, and
	 * `reciprocity_checked_at` is still updated — a failed check IS a check;
	 * leaving a stale `'mutual'` badge in place after a check just failed
	 * would be misleading.
	 */
	public function handle_check_reciprocity(): void {
		$id = isset( $_POST['peer_id'] ) ? absint( wp_unslash( $_POST['peer_id'] ) ) : 0;
		check_admin_referer( 'agnosis_rhizome_check_reciprocity_' . $id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- self::TABLE is a hardcoded class constant, never request input; the %d placeholder covers the only request-derived value ($id).
		$peer_url = $wpdb->get_var( $wpdb->prepare( 'SELECT url FROM ' . $wpdb->prefix . self::TABLE . " WHERE id = %d AND status = 'trusted'", $id ) );

		if ( ! $peer_url ) {
			$this->redirect();
		}

		$result     = ( new Node() )->check_reciprocity( (string) $peer_url );
		$reciprocal = is_wp_error( $result ) ? 'unknown' : ( $result ? 'mutual' : 'one_directional' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write.
		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			[
				'reciprocal'             => $reciprocal,
				'reciprocity_checked_at' => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'reciprocity_unreachable' );
		}

		$this->redirect( '', true );
	}

	/** admin-post handler: add a non-Agnosis Fediverse actor as trusted directly, no self-registration round trip. Gated by the caller (render()) on the settings toggle, but re-checked here too — never trust a settings-page-only gate for a state-changing POST. */
	public function handle_add_manual(): void {
		check_admin_referer( 'agnosis_rhizome_add_manual', 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		if ( ! get_option( self::OPTION, false ) ) {
			$this->redirect( 'invalid_manual' );
		}

		$actor_url = esc_url_raw( wp_unslash( $_POST['actor_url'] ?? '' ) );
		$inbox_url = esc_url_raw( wp_unslash( $_POST['inbox_url'] ?? '' ) );
		$label     = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$desc      = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$scope     = isset( $_POST['trust_scope'] ) ? sanitize_key( wp_unslash( $_POST['trust_scope'] ) ) : 'actor';

		if ( ! in_array( $scope, [ 'domain', 'actor' ], true ) ) {
			$scope = 'actor';
		}

		if ( '' === $actor_url || '' === $inbox_url || false === filter_var( $actor_url, FILTER_VALIDATE_URL ) || false === filter_var( $inbox_url, FILTER_VALIDATE_URL ) ) {
			$this->redirect( 'invalid_manual' );
		}

		global $wpdb;
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write; caching not applicable to REPLACE.
			$wpdb->prefix . self::TABLE,
			[
				'url'         => $actor_url,
				'label'       => $label,
				'description' => $desc,
				'trust_scope' => $scope,
				'actor_id'    => $actor_url,
				'inbox_url'   => $inbox_url,
				'status'      => 'trusted',
				'last_seen'   => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$this->redirect( '', true );
	}

	// -------------------------------------------------------------------------

	private function guard_and_set_status( int $id, string $status ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write.
		$wpdb->update( $wpdb->prefix . self::TABLE, [ 'status' => $status ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );

		$this->redirect( '', true );
	}

	private function redirect( string $error = '', bool $ok = false ): never {
		$args = [ 'page' => 'agnosis-settings', 'tab' => 'rhizome' ];
		if ( '' !== $error ) {
			$args['rhizome_error'] = $error;
		} elseif ( $ok ) {
			$args['rhizome_ok'] = '1';
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
