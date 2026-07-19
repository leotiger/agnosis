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

	$version      = '0.9.38';
	$download_url = 'https://github.com/leotiger/agnosis/releases/download/v0.9.38/agnosis-0.9.38.zip';
	$last_updated = ''; // TODO(release): fill in once this version actually ships (YYYY-MM-DD).
	$tested       = '7.0';

	// SHA-256 of the release ZIP — dev/bin/build-zip.sh now fills this in
	// automatically at the end of a successful build (cleared to '' at the
	// start of every run, so a failed/superseded build never leaves a stale
	// digest here). Empty string = verification skipped (safe default for a
	// manifest between builds).
	// TODO(release): pending the built agnosis-0.9.38.zip — run
	// dev/bin/build-zip.sh, upload the result to the v0.9.38 GitHub release,
	// then deploy this manifest.
	$sha256 = '';

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
		'<h4>0.9.38</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> The Tags and Mediums admin screens (Posts &#8594; Artwork &#8594; Mediums, etc.) now show only your own primary-language terms by default, with a new dropdown to switch to any other configured language &#8212; no more hundreds of AI-translated duplicates mixed into one list. Mediums also got a &#8220;Sync translations&#8221; action to create a term&#8217;s translation in every configured language on demand, and editing an artwork&#8217;s medium after publishing now correctly updates its already-translated sibling posts too, instead of leaving them stuck on the old term.</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/agnosis/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>0.9.37</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> New &#8220;Artwork Copyright&#8221; block automatically shows a &#8220;&#169; {year} {artist name}&#8221; credit line on every single artwork page &#8212; the year comes from the artwork&#8217;s own publish date, the name from the artist. Font size, color, and font family are all configurable from the block&#8217;s own Inspector panel.</li>' .
			'<li><strong>Fixed:</strong> The gallery overview&#8217;s medium-filter pills mixed every language&#8217;s translated medium term into one row, offered pills for mediums that had no artwork behind them in the current gallery, and reloaded the whole page on every click. The filter now only shows pills that actually apply to what you&#8217;re browsing, and clicking a pill or a page number updates the gallery instantly instead of reloading the page.</li>' .
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
