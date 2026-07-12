<?php
/**
 * Enforces an optional, site-wide preset title on every agnosis_biography post.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_User;

/**
 * Settings → General → "Preset biography title" (`agnosis_biography_preset_title`,
 * default '') lets an operator force every biography page to use one fixed
 * title instead of the artist's own — regardless of how or where that title
 * was last set: ApplicationBiography's admission-derived "About {name}"
 * draft, PostCreator's email-subject-as-title on creation or a later
 * bio@ resend, an artist's own edit on the approve-confirm form (routed
 * through ReviewEndpoints::save()), or a front-end post-publish edit via
 * Artist\ContentEditor. Left blank (the default), nothing here changes
 * anything and every one of those paths keeps working exactly as before —
 * the artist's own title, whatever it is.
 *
 * Deliberately hooked on WordPress core's own `wp_insert_post_data` filter
 * rather than any one of those individual write paths. That filter runs
 * inside every single `wp_insert_post()`/`wp_update_post()` call — insert or
 * update alike — immediately before the row is written, regardless of which
 * of the paths above (or any future one) triggered it. That includes Lingua
 * Forge's own translated-sibling creation, so a translated biography page
 * gets the exact same preset title too: shown as-is, in whatever language it
 * was typed into the setting, not machine-translated per sibling — a fixed
 * override is assumed to be intentionally fixed. This is one choke point
 * instead of separately patching every title-setting call site above, which
 * would be easy to leave inconsistent the next time a new one is added.
 *
 * Settings → General → "Include artist's name in preset title"
 * (`agnosis_biography_preset_title_include_name`, default off) appends the
 * post author's own WordPress display name to the preset title when it's
 * active. Has no effect while the preset title itself is blank.
 *
 * Note for anyone editing a biography's title afterward (approve-confirm
 * form, front-end ContentEditor): while a preset title is configured, that
 * field remains visible and editable but has no visible effect — this class
 * overrides it again on the very next save, the same as it overrides
 * whatever those forms pre-filled the field with. This is intentional: the
 * setting is meant to always win, not just seed a default an artist can
 * still override.
 */
class BiographyTitle {

	public function register_hooks(): void {
		add_filter( 'wp_insert_post_data', [ $this, 'apply_preset_title' ], 10, 2 );
	}

	/**
	 * @param array<string, mixed> $data    Sanitized post fields about to be saved.
	 * @param array<string, mixed> $postarr Raw args passed to wp_insert_post()/wp_update_post() — unused, $data already carries the merged, sanitized post_author/post_type.
	 * @return array<string, mixed>
	 */
	public function apply_preset_title( array $data, array $postarr ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedFunctionParameter -- required by the wp_insert_post_data filter signature.
		if ( 'agnosis_biography' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$preset_title = trim( (string) get_option( 'agnosis_biography_preset_title', '' ) );
		if ( '' === $preset_title ) {
			return $data;
		}

		if ( (bool) get_option( 'agnosis_biography_preset_title_include_name', false ) ) {
			$artist = get_userdata( (int) ( $data['post_author'] ?? 0 ) );
			if ( $artist instanceof WP_User && '' !== $artist->display_name ) {
				/* translators: 1: preset biography title (Settings → General); 2: artist's WordPress display name */
				$preset_title = sprintf( __( '%1$s — %2$s', 'agnosis' ), $preset_title, $artist->display_name );
			}
		}

		$data['post_title'] = $preset_title;

		return $data;
	}
}
