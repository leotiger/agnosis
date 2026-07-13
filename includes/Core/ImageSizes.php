<?php
/**
 * Custom image size registration.
 *
 * Registers the three image sizes used by Agnosis:
 *   agnosis-artwork — uncropped, width-constrained for post content / lightbox.
 *   agnosis-thumb   — square hard-cropped for submission cards and email previews.
 *   agnosis-email   — proportional width for artist notification emails.
 *
 * All widths/heights are configurable via the Behavior settings tab so admins
 * can tune them for their server's disk budget without touching code.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class ImageSizes {

	/** Register custom image sizes on after_setup_theme. */
	public function register(): void {
		$artwork = max( 400, (int) get_option( 'agnosis_artwork_size_px', 1920 ) );
		$thumb   = max( 64,  (int) get_option( 'agnosis_thumb_size_px',  512  ) );
		$email   = max( 200, (int) get_option( 'agnosis_email_size_px',  420  ) );

		// Width only, height scales to preserve aspect ratio.
		add_image_size( 'agnosis-artwork', $artwork, 0, false );

		// Square crop, centred — for submission cards and dashboard.
		add_image_size( 'agnosis-thumb', $thumb, $thumb, true );

		// Email width, proportional — for artist notification emails.
		add_image_size( 'agnosis-email', $email, 0, false );
	}
}
