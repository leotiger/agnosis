<?php
/**
 * Agnosis — self-hosted update manifest endpoint.
 *
 * Deploy to: wp-content/mu-plugins/agnosis-update-manifest.php on agnosis.art.
 *
 * Registers GET /wp-json/agnosis/v1/update and returns the plugin update
 * manifest as JSON with no-cache headers so every request fetches live data
 * regardless of server-side or CDN caching.
 *
 * On every release: update $version, $download_url, $last_updated, $sha256,
 * and prepend the new entry to $sections['changelog']. Nothing else needs
 * changing.
 *
 * MANIFEST_URL in agnosis/includes/Core/Updater.php must point to:
 * https://agnosis.art/wp-json/agnosis/v1/update
 *
 * Modeled directly on the companion Lingua Forge plugin's own
 * docs/lf-update-manifest.php (deployed the same way to lingua-forge.com),
 * so both self-hosted plugins are administered identically.
 *
 * @package Agnosis
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	register_rest_route(
		'agnosis/v1',
		'/update',
		[
			'methods'             => 'GET',
			'callback'            => 'agnosis_update_manifest_endpoint',
			'permission_callback' => '__return_true',
		]
	);
} );

function agnosis_update_manifest_endpoint(): WP_REST_Response {

	// -------------------------------------------------------------------------
	// UPDATE THESE FIELDS ON EVERY RELEASE
	// -------------------------------------------------------------------------

	$version      = '0.9.34';
	$download_url = 'https://github.com/leotiger/agnosis/releases/download/v0.9.34/agnosis-0.9.34.zip';
	$last_updated = ''; // TODO(release): fill in once this version actually ships (YYYY-MM-DD).
	$tested       = '7.0';

	// SHA-256 of the release ZIP — dev/bin/build-zip.sh now fills this in
	// automatically at the end of a successful build (cleared to '' at the
	// start of every run, so a failed/superseded build never leaves a stale
	// digest here). Empty string = verification skipped (safe default for a
	// manifest between builds).
	// TODO(release): pending the built agnosis-0.9.34.zip — run
	// dev/bin/build-zip.sh, upload the result to the v0.9.34 GitHub release,
	// then deploy this manifest.
	$sha256 = '53640211c1ac7e17c24e95dbd982dbdae11bbd82c78beaa3085f5ca2ebafd2b7';

	// Two most recent releases only — do not accumulate history here; it
	// bloats the manifest. Full changelog: CHANGELOG.md in the plugin repository.
	//
	// This block (and $version/$download_url/$last_updated above) went
	// eleven versions stale before being caught (audit §4b, AUDIT-1.0.0.md —
	// still describing 0.9.22 while the plugin was at 0.9.33). See
	// CONTRIBUTING.md's "Changelog and readme conventions" section for the
	// standing rule this file is now covered by: update on every version
	// bump, same as CHANGELOG.md and readme.txt.
	$changelog =
		'<h4>0.9.34</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> Agnosis&#8217;s three hand-maintained lists of scheduled background tasks (WP-Cron hooks) had drifted out of sync &#8212; plugin deletion wasn&#8217;t clearing two of them (including one that could leave a stale scheduled task behind indefinitely), and deactivation wasn&#8217;t clearing three others. Reconciled all three lists against a single source of truth, with an automated test that keeps them from drifting apart again.</li>' .
			'<li><strong>Fixed:</strong> The self-hosted update-check feed had gone eleven versions stale, still describing version 0.9.22 &#8212; brought current, and added to the standing release checklist so it can&#8217;t silently drift again.</li>' .
			'<li><strong>Fixed:</strong> The fediverse followers list (visible to Mastodon and other federated software) now identifies each follower by their own address, instead of an internal delivery detail it was publishing by mistake.</li>' .
			'<li><strong>Fixed:</strong> An email with no plain-text version at all &#8212; some webmail &#8220;rich text&#8221; modes, several mobile mail apps, and most marketing/newsletter composers send this way &#8212; no longer loses its description text. Agnosis now reads the message&#8217;s formatted content instead when there&#8217;s no plain text to fall back on.</li>' .
			'<li><strong>Fixed:</strong> When a follower&#8217;s fediverse account is deleted, Agnosis now removes them from its follower list right away instead of continuing to (unsuccessfully) deliver to them for days. A confirmed-dead delivery address is also now recognized immediately rather than retried for the full waiting period.</li>' .
			'<li><strong>Changed:</strong> The Installation section now notes that Lingua Forge must be installed and active before Agnosis, since Agnosis won&#8217;t activate without it. Also a code-comment spelling cleanup (behaviour&#8594;behavior, colour&#8594;color) &#8212; no functional change.</li>' .
			'<li><strong>Changed:</strong> Added automated test coverage for three previously hand-verified-only or deferred safety checks &#8212; the newsletter/fediverse delivery queues&#8217; overlap protection, every outgoing email actually honoring a configured accent color, and the header text-color contrast switch &#8212; no functional change.</li>' .
			'<li><strong>Changed:</strong> Split the large internal Settings-page code file into several smaller, focused files, organized by which admin dashboard/card each one renders. Purely internal code organization &#8212; no change to how the Settings page looks or behaves. (A follow-up static-analysis check caught and fixed one small code-correctness slip in this same split before it shipped.)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/agnosis/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>0.9.33</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> The artist breadcrumb now shows the artist&#8217;s native language as a two-letter code next to the biography/events/contact icons, with the language&#8217;s own native name shown on hover.</li>' .
			'<li><strong>Fixed:</strong> Clicking the &#8220;confirm your application&#8221; link in the join email landed artists on a page that still needed one more click to actually confirm &#8212; several first applicants got stuck there, not realizing anything more was needed. That page now confirms automatically the moment it loads in a real browser, while still rejecting a bare prefetch/scan of the link, so the protection against mail-scanner false-positives stays intact.</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/agnosis/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>';

	// -------------------------------------------------------------------------
	// STATIC FIELDS — change rarely
	// -------------------------------------------------------------------------

	$manifest = [
		'version'      => $version,
		'requires'     => '6.6',
		'requires_php' => '8.2',
		'tested'       => $tested,
		'last_updated' => $last_updated,
		'details_url'  => 'https://agnosis.art',
		'download_url' => $download_url,
		'sha256'       => $sha256,

		'icons' => [
			'1x'  => 'https://agnosis.art/wp-content/uploads/agnosis-icon-128.png',
			'2x'  => 'https://agnosis.art/wp-content/uploads/agnosis-icon-256.png',
			'svg' => 'https://agnosis.art/wp-content/uploads/agnosis-icon.svg',
		],

		'banners' => [
			'low'  => 'https://agnosis.art/wp-content/uploads/agnosis-banner-772x250.jpg',
			'high' => 'https://agnosis.art/wp-content/uploads/agnosis-banner-1544x500.jpg',
		],

		'sections' => [
			'description' =>
				'<p>Agnosis is a free, federated publishing network for independent artists. ' .
				'Artists who are great at creating &#8212; but not at promoting &#8212; can simply send an ' .
				'email with their artwork, biography, or event, and Agnosis receives it, enhances it with ' .
				'AI, writes a title and description, publishes a gallery post, and broadcasts it to the ' .
				'Fediverse (Mastodon, Pixelfed) via ActivityPub.</p>' .
				'<p>Community-first admission, no gatekeepers, no central server &#8212; any site can run an ' .
				'Agnosis node and federate with the network.</p>' .
				'<p><a href="https://github.com/leotiger/agnosis">GitHub repository</a> &middot; ' .
				'<a href="https://agnosis.art">agnosis.art</a></p>',

			'installation' =>
				'<ol>' .
					'<li>Download the latest ZIP from the <a href="https://github.com/leotiger/agnosis/releases">GitHub Releases page</a>.</li>' .
					'<li>In WordPress admin go to <strong>Plugins &#8594; Add New &#8594; Upload Plugin</strong>, choose the ZIP, and click <strong>Install Now</strong>.</li>' .
					'<li>Activate <strong>Agnosis</strong>.</li>' .
					'<li>Go to <strong>Settings &#8594; Agnosis</strong> to configure email intake and your AI provider API key.</li>' .
				'</ol>' .
				'<p><strong>After the first manual install, updates are automatic.</strong> ' .
				'WordPress checks for new releases every 12 hours and displays the standard update badge ' .
				'in Plugins &#8594; Installed Plugins when one is available.</p>',

			'changelog' => $changelog,
		],
	];

	$response = new WP_REST_Response( $manifest, 200 );
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'Pragma', 'no-cache' );
	$response->header( 'Expires', 'Thu, 01 Jan 1970 00:00:00 GMT' );

	return $response;
}
