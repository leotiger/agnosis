<?php
/**
 * Self-hosted update checker.
 *
 * Hooks into WordPress's plugin-update machinery so wp-admin surfaces
 * "Update available" notices and runs the one-click updater — without
 * Agnosis being listed on WordPress.org (it isn't; see the self-hosted
 * `load_plugin_textdomain()` note in Plugin::load_textdomain()).
 *
 * Flow:
 *  1. On every WordPress update check (`pre_set_site_transient_update_plugins`),
 *     fetch a small JSON manifest from agnosis.art and cache it for
 *     CACHE_TTL seconds (default: 12 h).
 *  2. If the manifest version is newer than the installed version, inject an
 *     entry into `$transient->response`, which is what WordPress reads to show
 *     the update badge and trigger the one-click updater.
 *  3. On the `plugins_api` hook, respond to "plugin_information" requests for
 *     our slug, returning a populated object that fills the "View version
 *     details" modal (changelog, description, etc.).
 *  4. After a successful update, purge the cached manifest so the next check
 *     re-fetches immediately.
 *
 * Manifest format — see docs/agnosis-update-manifest.php for the deployable
 * template (mu-plugin on agnosis.art) and its schema.
 *
 * Modeled directly on Lingua Forge's `Linguaforge_Updater` (the companion
 * plugin's `includes/class-updater.php`) so both self-hosted plugins behave
 * identically for operators running either or both.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class Updater {

	/**
	 * URL of the update manifest JSON on agnosis.art.
	 *
	 * The file at this URL must be publicly readable and return a valid JSON
	 * object. See docs/agnosis-update-manifest.php for the expected schema.
	 */
	private const MANIFEST_URL = 'https://agnosis.art/wp-json/agnosis/v1/update';

	/** WordPress transient key used to cache the manifest. */
	private const CACHE_KEY = 'agnosis_update_manifest';

	/** How long the manifest is cached (seconds). Default: 12 hours. */
	private const CACHE_TTL = 43200; // 12 * 3600

	/** Plugin slug as registered with WordPress ("plugin_information" requests). */
	private const SLUG = 'agnosis';

	/**
	 * Hosts permitted as the origin of the plugin download ZIP.
	 *
	 * A manipulated manifest cannot redirect the update to an arbitrary host.
	 * Subdomains of these hosts (e.g. releases.github.com) are also accepted.
	 *
	 * @var string[]
	 */
	private const ALLOWED_DOWNLOAD_HOSTS = [ 'agnosis.art', 'github.com', 'objects.githubusercontent.com' ];

	/**
	 * Register all WordPress hooks.
	 *
	 * Call once from Plugin::register_services(), inside the is_admin() gate,
	 * so the remote manifest fetch never fires on the frontend.
	 */
	public function register_hooks(): void {
		// Inject (or clear) our entry in the plugin-update transient.
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );

		// Serve plugin information for the "View version details" modal.
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );

		// Purge cached manifest after any plugin update completes, so
		// a subsequent check immediately re-fetches the latest manifest.
		add_action( 'upgrader_process_complete', [ $this, 'purge_cache' ], 10, 2 );

		// Also purge when WordPress itself force-refreshes the update_plugins
		// site transient (e.g. "Check Again" on the Updates screen). Without
		// this, our 12-hour transient keeps injecting stale manifest data into
		// every fresh WordPress update check.
		add_action( 'delete_site_transient_update_plugins', [ $this, 'purge_manifest_cache' ] );

		// Add a "View details" link to the plugin row on the Plugins screen.
		add_filter( 'plugin_row_meta', [ $this, 'add_view_details_link' ], 10, 2 );

		// Intercept our own package download to enforce host pinning and
		// verify the SHA-256 hash declared in the manifest (when present).
		add_filter( 'upgrader_pre_download', [ $this, 'verify_and_download' ], 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Inject update information into the WordPress plugin-update transient.
	 *
	 * WordPress passes the transient to this filter before broadcasting it to
	 * all registered `update_plugins` consumers. We add an entry to
	 * `$transient->response` when a newer version is available, or to
	 * `$transient->no_update` when the installed version is current.
	 *
	 * @param \stdClass $transient The plugins_update transient.
	 * @return \stdClass
	 */
	public function check_for_update( \stdClass $transient ): \stdClass {
		// WordPress only populates $transient->checked after it has queried
		// the installed plugins list. Bail early if it's not ready yet.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$manifest = $this->fetch_manifest();
		if ( ! $manifest ) {
			return $transient;
		}

		// Read the installed version from the plugin file on disk rather than
		// from the AGNOSIS_VERSION constant. When WordPress re-runs this filter
		// immediately after an upgrade (still within the same update.php
		// request), the constant still reflects the old version while the file
		// on disk already contains the new one. Reading from disk prevents us
		// from re-injecting a spurious "update available" entry that would
		// force the user to click Update a second time before the badge clears.
		$file_data         = get_file_data(
			WP_PLUGIN_DIR . '/' . AGNOSIS_BASENAME,
			[ 'Version' => 'Version' ]
		);
		$installed_version = $file_data['Version'] ?? AGNOSIS_VERSION;

		if ( version_compare( $manifest->version, $installed_version, '>' ) ) {
			// Newer version available — WordPress will show the update badge.
			$transient->response[ AGNOSIS_BASENAME ] = $this->build_update_object( $manifest );
		} else {
			// Up-to-date — populate no_update so WP doesn't show a stale notice.
			$transient->no_update[ AGNOSIS_BASENAME ] = $this->build_no_update_object( $manifest );
		}

		return $transient;
	}

	/**
	 * Respond to WordPress's plugin-information API for the details modal.
	 *
	 * WordPress fires `plugins_api` when the user clicks "View version details"
	 * on the plugins list or update screen. We intercept only requests for our
	 * own slug; everything else falls through to the default handler.
	 *
	 * @param false|object|array<string, mixed> $result Pre-existing result (false = not handled yet).
	 * @param string                            $action Requested API action.
	 * @param object                            $args   Request arguments (slug, fields, etc.).
	 * @return false|object|array<string, mixed>
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}

		$manifest = $this->fetch_manifest();

		// Even when the manifest is temporarily unreachable, return a minimal
		// info object from locally known data so WordPress never falls through
		// to the .org API and shows "Plugin not found."
		return $this->build_info_object( $manifest ?: new \stdClass() );
	}

	/**
	 * Purge the cached manifest after a plugin update completes.
	 *
	 * Ensures the next update check fetches a fresh manifest rather than
	 * serving the (now stale) pre-update cached copy.
	 *
	 * @param \WP_Upgrader        $upgrader   The upgrader instance (unused).
	 * @param array<string,mixed> $hook_extra Extra info: type, action, plugins, etc.
	 */
	public function purge_cache( $upgrader, array $hook_extra ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $upgrader required by upgrader_process_complete's filter signature.
		if (
			isset( $hook_extra['type'], $hook_extra['action'] ) &&
			'plugin' === $hook_extra['type'] &&
			'update' === $hook_extra['action']
		) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Purge the cached manifest whenever WordPress force-refreshes its own
	 * plugin-update site transient (e.g. "Check Again" on the Updates screen,
	 * or any code that calls `delete_site_transient( 'update_plugins' )`).
	 *
	 * Without this, our 12-hour transient keeps serving stale manifest data
	 * into every fresh WordPress update check.
	 */
	public function purge_manifest_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Append a "View details" link to the plugin row meta on the Plugins screen.
	 *
	 * Clicking the link opens the standard WordPress plugin-information thickbox,
	 * which is populated by our `plugins_api` callback above.
	 *
	 * @param string[] $links       Existing row-meta links.
	 * @param string   $plugin_file Plugin basename (folder/file.php).
	 * @return string[]
	 */
	public function add_view_details_link( array $links, string $plugin_file ): array {
		if ( AGNOSIS_BASENAME !== $plugin_file ) {
			return $links;
		}

		// Scan existing links so we never create duplicates. WordPress may add
		// its own "View details" thickbox link when plugins_api returns data
		// for our slug, and always adds "Visit plugin site" from the Plugin
		// URI header — but only when it considers the plugin "known".
		$has_details     = false;
		$has_plugin_site = false;

		foreach ( $links as $link ) {
			if ( str_contains( $link, 'TB_iframe' ) || str_contains( $link, 'open-plugin-details-modal' ) ) {
				$has_details = true;
			}
			if ( str_contains( $link, 'github.com/leotiger/agnosis' ) ) {
				$has_plugin_site = true;
			}
		}

		// Add "View details" only if WordPress hasn't already inserted one.
		if ( ! $has_details ) {
			$url = add_query_arg(
				[
					'tab'       => 'plugin-information',
					'plugin'    => self::SLUG,
					'TB_iframe' => 'true',
					'width'     => '600',
					'height'    => '550',
				],
				admin_url( 'plugin-install.php' )
			);

			$links[] = sprintf(
				'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s">%s</a>',
				esc_url( $url ),
				esc_attr__( 'View Agnosis details', 'agnosis' ),
				esc_html__( 'View details', 'agnosis' )
			);
		}

		// Guarantee the GitHub repository link is always present. WordPress
		// generates it from Plugin URI, but drops it for self-hosted plugins
		// when the update transient doesn't include a .org slug.
		if ( ! $has_plugin_site ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( 'https://github.com/leotiger/agnosis' ),
				esc_html__( 'Visit plugin site', 'agnosis' )
			);
		}

		return $links;
	}

	// -------------------------------------------------------------------------
	// Package integrity verification
	// -------------------------------------------------------------------------

	/**
	 * Hook: upgrader_pre_download
	 *
	 * Intercepts the download of our own update package to:
	 *  1. Pin the download host — a manipulated manifest cannot redirect the
	 *     upgrade to an arbitrary server.
	 *  2. Verify the SHA-256 hash declared in the manifest (when the field is
	 *     present). The check is skipped gracefully when the manifest omits
	 *     `sha256` so that existing releases without the field still update.
	 *
	 * We download the ZIP ourselves, verify it, and return the local temp-file
	 * path. WordPress then uses that path directly instead of re-downloading,
	 * so the file is only fetched once.
	 *
	 * Returns false for any package that is not ours, letting WordPress handle
	 * it normally. Returns a WP_Error to abort the update on a failed check.
	 *
	 * @param false|string|\WP_Error $pre       Pre-existing result (false = not handled yet).
	 * @param string                 $package   Download URL passed to the upgrader.
	 * @param \WP_Upgrader           $_upgrader The upgrader instance (unused).
	 * @return false|string|\WP_Error
	 */
	public function verify_and_download( $pre, string $package, \WP_Upgrader $_upgrader ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_upgrader required by upgrader_pre_download's filter signature.
		// If a prior filter already handled this, respect it.
		if ( false !== $pre ) {
			return $pre;
		}

		$manifest = $this->fetch_manifest();
		if ( ! $manifest || empty( $manifest->download_url ) ) {
			return false;
		}

		// Only intercept our own package URL.
		if ( $package !== $manifest->download_url ) {
			return false;
		}

		// Host pinning — block if the download URL resolves to an unexpected host.
		$host = wp_parse_url( $package, PHP_URL_HOST );
		if ( ! $this->is_allowed_download_host( (string) $host ) ) {
			return new \WP_Error(
				'agnosis_updater_host_blocked',
				sprintf(
					/* translators: %s: download URL hostname */
					__( 'Agnosis update blocked: download host "%s" is not on the allowlist.', 'agnosis' ),
					(string) $host
				)
			);
		}

		// Download to a temp file.
		$tmp = download_url( $package );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// SHA-256 verification — skipped when the manifest omits the field so
		// existing releases without a hash still update cleanly.
		if ( ! empty( $manifest->sha256 ) ) {
			$actual   = hash_file( 'sha256', $tmp );
			$expected = strtolower( trim( (string) $manifest->sha256 ) );

			if ( ! hash_equals( $expected, (string) $actual ) ) {
				wp_delete_file( $tmp );
				return new \WP_Error(
					'agnosis_updater_checksum_mismatch',
					__( 'Agnosis update blocked: SHA-256 of the downloaded package does not match the manifest. The file may have been tampered with.', 'agnosis' )
				);
			}
		}

		return $tmp;
	}

	/**
	 * Returns true when the given hostname is on the download allowlist.
	 *
	 * Exact matches and subdomains of allowlisted hosts are both accepted
	 * (e.g. "releases.github.com" matches "github.com").
	 *
	 * @param string $host Hostname to check.
	 * @return bool
	 */
	private function is_allowed_download_host( string $host ): bool {
		foreach ( self::ALLOWED_DOWNLOAD_HOSTS as $allowed ) {
			if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Manifest fetch + cache
	// -------------------------------------------------------------------------

	/**
	 * Fetch the update manifest from agnosis.art, with transient caching.
	 *
	 * Returns false on network error, bad HTTP status, or invalid JSON.
	 * Negative results are cached for one hour to avoid hammering the server
	 * on repeated failures; positive results are cached for CACHE_TTL.
	 *
	 * The cached value is always an object. A sentinel `{ "error": true }`
	 * object is stored for negative results so `get_transient() === false`
	 * always means "not cached", not "cached failure".
	 *
	 * @return \stdClass|false Decoded manifest, or false on failure.
	 */
	private function fetch_manifest() {
		$cached = get_transient( self::CACHE_KEY );

		if ( false !== $cached ) {
			// Sentinel object means previous fetch failed; don't retry yet.
			return ! empty( $cached->error ) ? false : $cached;
		}

		$response = wp_remote_get(
			self::MANIFEST_URL,
			[
				'timeout'    => 10,
				'user-agent' => 'Agnosis/' . AGNOSIS_VERSION
					. '; WordPress/' . get_bloginfo( 'version' )
					. '; ' . home_url(),
				'sslverify'  => true,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, (object) [ 'error' => true ], HOUR_IN_SECONDS );
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! is_object( $data ) || empty( $data->version ) ) {
			set_transient( self::CACHE_KEY, (object) [ 'error' => true ], HOUR_IN_SECONDS );
			return false;
		}

		/** @var \stdClass $data — json_decode() without $associative produces stdClass for a JSON object; is_object() above already confirmed it isn't a scalar/array. */
		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	// -------------------------------------------------------------------------
	// Object builders
	// -------------------------------------------------------------------------

	/**
	 * Build the stdClass that WordPress expects in $transient->response.
	 *
	 * @param \stdClass $manifest Decoded manifest JSON.
	 * @return object
	 */
	private function build_update_object( \stdClass $manifest ): object {
		return (object) [
			'id'           => 'agnosis/agnosis',
			'slug'         => self::SLUG,
			'plugin'       => AGNOSIS_BASENAME,
			'new_version'  => $manifest->version,
			'url'          => $manifest->details_url ?? 'https://agnosis.art',
			'package'      => $manifest->download_url ?? '',
			'requires'     => $manifest->requires     ?? AGNOSIS_MIN_WP,
			'requires_php' => $manifest->requires_php ?? AGNOSIS_MIN_PHP,
			'tested'       => $manifest->tested        ?? '',
			'icons'        => (array) ( $manifest->icons   ?? new \stdClass() ),
			'banners'      => (array) ( $manifest->banners ?? new \stdClass() ),
		];
	}

	/**
	 * Build the stdClass that WordPress expects in $transient->no_update.
	 *
	 * Populating no_update prevents stale update notices from persisting
	 * after the user has already updated.
	 *
	 * @param \stdClass $manifest Decoded manifest JSON.
	 * @return object
	 */
	private function build_no_update_object( \stdClass $manifest ): object {
		return (object) [
			'id'           => 'agnosis/agnosis',
			'slug'         => self::SLUG,
			'plugin'       => AGNOSIS_BASENAME,
			'new_version'  => $manifest->version,
			'url'          => $manifest->details_url ?? 'https://agnosis.art',
			'package'      => '',
			'requires'     => $manifest->requires     ?? AGNOSIS_MIN_WP,
			'requires_php' => $manifest->requires_php ?? AGNOSIS_MIN_PHP,
			'tested'       => $manifest->tested        ?? '',
			'icons'        => (array) ( $manifest->icons   ?? new \stdClass() ),
			'banners'      => (array) ( $manifest->banners ?? new \stdClass() ),
		];
	}

	/**
	 * Build the stdClass that WordPress expects from a `plugins_api` callback.
	 *
	 * Populates the fields WordPress renders in the plugin-information modal
	 * (the thickbox/dialog that appears when you click "View version details").
	 *
	 * @param \stdClass $manifest Decoded manifest JSON.
	 * @return object
	 */
	private function build_info_object( \stdClass $manifest ): object {
		$sections = (array) ( $manifest->sections ?? [] );

		// Provide a sensible fallback if the manifest omits sections entirely
		// (including the case where the manifest was temporarily unreachable).
		if ( empty( $sections ) ) {
			$sections['description'] = '<p>Art blooming out of oblivion. Email your art, AI polishes it, the world sees it.</p>'
				. '<p><a href="https://agnosis.art">agnosis.art</a></p>';
		}

		return (object) [
			'name'           => 'Agnosis',
			'slug'           => self::SLUG,
			'version'        => $manifest->version       ?? AGNOSIS_VERSION,
			'author'         => '<a href="https://agnosis.art">Uli Hake</a>',
			'author_profile' => 'https://agnosis.art',
			'homepage'       => 'https://agnosis.art',
			'requires'       => $manifest->requires      ?? AGNOSIS_MIN_WP,
			'requires_php'   => $manifest->requires_php  ?? AGNOSIS_MIN_PHP,
			'tested'         => $manifest->tested         ?? '',
			'download_link'  => $manifest->download_url   ?? '',
			'last_updated'   => $manifest->last_updated   ?? '',
			'sections'       => $sections,
			'icons'          => (array) ( $manifest->icons   ?? new \stdClass() ),
			'banners'        => (array) ( $manifest->banners ?? new \stdClass() ),
		];
	}
}
