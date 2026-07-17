<?php
/**
 * "Invite an Artist" dashboard card (Community tab) — rendering plus its own
 * admin-post handlers for the real send and the test send.
 *
 * Split out of Admin\Settings (2026-07-17, AUDIT-1.0.0.md §4d — the "god
 * class" finding): this render method and its two handlers were already a
 * self-contained cluster (a single-recipient, untracked, unqueued invitation
 * flow, distinct from the newsletter system), so this is a pure move — same
 * behavior, same hook names (`admin_post_agnosis_send_invitation`/
 * `admin_post_agnosis_send_invitation_test`, rewired in Core\Plugin to this
 * class instead of Settings).
 *
 * @package Agnosis\Admin\Dashboards
 */

declare(strict_types=1);

namespace Agnosis\Admin\Dashboards;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Artist\Invitation;

class InvitationCard {

	/**
	 * "Invite an Artist" card — a single-recipient, language-selectable
	 * invitation email. Rendered on the Community tab, alongside the
	 * admission/members dashboards it's most closely related to.
	 */
	public function render(): void {
		$languages = SubmissionTranslator::language_names();
		ksort( $languages );
		$current_user = wp_get_current_user();
		?>
		<div class="card" style="max-width:900px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Invite an Artist', 'agnosis' ); ?></h2>
			<p class="description" style="margin-top:0">
				<?php esc_html_e( 'Send a one-off email inviting someone to apply. Nothing is tracked or scheduled — this sends immediately to the address below.', 'agnosis' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin-bottom:.8rem">
				<input type="hidden" name="action" value="agnosis_send_invitation">
				<?php wp_nonce_field( 'agnosis_send_invitation' ); ?>
				<input type="email" name="invitation_email" placeholder="<?php esc_attr_e( 'artist@example.com', 'agnosis' ); ?>" required class="regular-text" style="width:16rem">
				<select name="invitation_language">
					<?php foreach ( $languages as $code => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $code ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Send Invitation', 'agnosis' ), 'primary small', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
				<input type="hidden" name="action" value="agnosis_send_invitation_test">
				<?php wp_nonce_field( 'agnosis_send_invitation_test' ); ?>
				<input type="email" name="invitation_test_email" value="<?php echo esc_attr( $current_user->user_email ); ?>" required class="regular-text" style="width:16rem">
				<select name="invitation_language">
					<?php foreach ( $languages as $code => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $code ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Send Test', 'agnosis' ), 'secondary small', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * admin-post handler: send a real invitation.
	 */
	public function handle_send_invitation(): void {
		check_admin_referer( 'agnosis_send_invitation' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$email    = sanitize_email( wp_unslash( $_POST['invitation_email'] ?? '' ) );
		$language = sanitize_key( wp_unslash( $_POST['invitation_language'] ?? '' ) );

		$result = ( new Invitation() )->send( $email, $language );

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'community',
				'subtab'          => 'members',
				'agnosis_message' => true === $result ? 'invitation_sent' : 'invitation_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * admin-post handler: send a preview of the invitation to one address.
	 */
	public function handle_send_invitation_test(): void {
		check_admin_referer( 'agnosis_send_invitation_test' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$email    = sanitize_email( wp_unslash( $_POST['invitation_test_email'] ?? '' ) );
		$language = sanitize_key( wp_unslash( $_POST['invitation_language'] ?? '' ) );

		$result = ( new Invitation() )->send_test( $email, $language );

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'community',
				'subtab'          => 'members',
				'agnosis_message' => true === $result ? 'invitation_test_sent' : 'invitation_test_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
