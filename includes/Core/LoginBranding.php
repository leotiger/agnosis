<?php
/**
 * Basic branding for wp-login.php — the one screen in the whole login/
 * password-recovery flow that SubmissionsPage's themed inline form
 * (wp_login_form()) can't cover, since "Lost your password?" / "Reset
 * password" necessarily leave the front-end page and land here.
 *
 * Deliberately light-touch: this does not attempt a full themed rebuild of
 * wp-login.php (a much bigger, higher-risk undertaking with its own
 * maintenance burden across WP core updates). It only replaces the two
 * things a non-technical artist would otherwise see that look completely
 * unrelated to the site they applied to — the WordPress logo/link, and the
 * stock button/link colors — with the site's own identity and basic
 * palette. Applies to every wp-login.php visit (including admins'), which is
 * correct: there is no reason an admin's password-reset screen should look
 * different from an artist's.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class LoginBranding {

	/**
	 * Point the logo link at the site's homepage instead of wordpress.org.
	 *
	 * Hooked to: login_headerurl (filter)
	 */
	public function header_url( string $url ): string {
		return home_url( '/' );
	}

	/**
	 * The logo link's title/alt text — the site name instead of "Powered by WordPress".
	 *
	 * Hooked to: login_headertext (filter)
	 */
	public function header_text( string $text ): string {
		return get_bloginfo( 'name' );
	}

	/**
	 * Inline CSS for wp-login.php: swaps the WordPress logo for the site's own
	 * logo (Settings → General → Email logo, the same one EmailBranding uses)
	 * or plain site-name text when none is configured, and restyles the
	 * submit button / links to match SubmissionsPage's inline login form
	 * instead of WordPress's default blue.
	 *
	 * Hooked to: login_enqueue_scripts (action)
	 */
	public function enqueue_styles(): void {
		$logo_id = (int) get_option( 'agnosis_email_logo_id', 0 );
		$logo    = $logo_id ? wp_get_attachment_image_src( $logo_id, 'medium' ) : false;

		echo '<style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS + escaped values only, no user data.

		if ( $logo ) {
			// Computed ahead of printf() rather than inlined — PHPCS's
			// OutputNotEscaped sniff doesn't recognise min()/round() as a
			// provably-safe expression when nested directly as a printf()
			// argument. printf() (unlike sprintf(), which just returns a
			// string) is treated as a direct-output construct, so the sniff
			// still requires the plain variable itself to be visibly cast at
			// the call site below — no data-flow analysis back to where it
			// was computed — even though it can only ever hold an int.
			$logo_height = (int) $logo[2] > 0
				? min( 90, (int) round( 90 * $logo[2] / max( 1, $logo[1] ) ) )
				: 84;

			printf(
				'#login h1 a {
					background-image: url(%s);
					background-size: contain;
					width: 100%%;
					max-width: 320px;
					height: %dpx;
				}',
				esc_url( $logo[0] ),
				(int) $logo_height
			);
		} else {
			// Standard "replace the WP logo with site-name text" reset: undo the
			// image-replacement technique's text-hiding (text-indent/width/height)
			// so the anchor's own text content becomes visible instead.
			echo '#login h1 a {
				background-image: none;
				text-indent: 0;
				width: auto;
				height: auto;
				font-size: 22px;
				font-weight: 700;
				color: #1d1d1f;
				text-decoration: none;
			}';
		}

		echo '
			body.login {
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			}
			.login form {
				border-radius: 0;
				border: 1px solid #ddd;
				box-shadow: none;
			}
			.login input[type="text"],
			.login input[type="password"],
			.login input[type="email"] {
				border-radius: 0;
			}
			.login .button-primary {
				background: #000;
				border-color: #000;
				border-radius: 0;
				text-shadow: none;
				box-shadow: none;
				text-transform: uppercase;
				letter-spacing: .05em;
				font-weight: 600;
			}
			.login .button-primary:hover,
			.login .button-primary:focus {
				background: #333;
				border-color: #333;
			}
			.login #nav a,
			.login #backtoblog a {
				color: #1d1d1f;
			}
		';

		echo '</style>';
	}
}
