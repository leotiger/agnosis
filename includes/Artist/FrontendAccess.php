<?php
/**
 * Frontend-only access enforcement for the agnosis_artist role.
 *
 * Artists submit work by email and interact exclusively with the site's
 * public-facing pages. They have no reason to see the WordPress admin
 * dashboard, the admin bar, or the standard login landing page. This class
 * enforces that boundary with three targeted hooks:
 *
 *   1. admin_init    — redirect artists who land on any /wp-admin/ URL back
 *                      to the site's front page.
 *   2. show_admin_bar — hide the toolbar for artists on the frontend.
 *   3. login_redirect — after a successful login, send artists to the front
 *                       page instead of the dashboard.
 *
 * Admins who also happen to have the agnosis_artist capability (e.g. the site
 * owner who is also a member of their own community) are intentionally
 * excluded from all three restrictions.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use WP_User;
use WP_Error;

class FrontendAccess {

	/**
	 * Redirect an artist who lands on any wp-admin page to the front page.
	 *
	 * Skips AJAX requests so that any admin-ajax.php calls (even if not used
	 * by the plugin today) are not broken by a redirect response.
	 *
	 * Hooked to: admin_init
	 */
	public function block_admin_access(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		if ( $this->is_frontend_only_artist() ) {
			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}
	}

	/**
	 * Hide the WordPress admin bar for artists on every frontend page.
	 *
	 * Hooked to: show_admin_bar (filter)
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public function hide_admin_bar( bool $show ): bool {
		if ( $this->is_frontend_only_artist() ) {
			return false;
		}
		return $show;
	}

	/**
	 * After a successful login, redirect artists to the front page.
	 *
	 * WordPress's default is to send all users to the dashboard (/wp-admin/).
	 * This filter intercepts that for artists so they never even see a flash
	 * of the admin screen.
	 *
	 * Hooked to: login_redirect (filter)
	 *
	 * @param string           $redirect_to           The redirect destination URL.
	 * @param string           $requested_redirect_to The requested redirect URL from the login form.
	 * @param WP_User|WP_Error $user                  The logged-in user, or WP_Error on failure.
	 * @return string
	 */
	public function redirect_after_login( string $redirect_to, string $requested_redirect_to, WP_User|WP_Error $user ): string {
		if ( $user instanceof WP_User && $this->is_frontend_only_artist( $user->ID ) ) {
			return home_url( '/' );
		}
		return $redirect_to;
	}

	// -------------------------------------------------------------------------
	// Private helper
	// -------------------------------------------------------------------------

	/**
	 * Return true when the given user (defaults to the current user) is an
	 * admitted artist but NOT an administrator.
	 *
	 * Using user_can() with the primitive 'agnosis_artist' capability rather
	 * than checking $user->roles ensures the test works even when the role is
	 * not registered in the global WP_Roles registry (e.g. cold test envs).
	 *
	 * @param int|null $user_id WP user ID, or null to use the current user.
	 */
	private function is_frontend_only_artist( ?int $user_id = null ): bool {
		$uid = $user_id ?? get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		return user_can( $uid, 'agnosis_artist' )
			&& ! user_can( $uid, 'manage_options' );
	}
}
