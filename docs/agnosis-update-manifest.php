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

	$version      = '0.9.44';
	$download_url = 'https://github.com/leotiger/agnosis/releases/download/v0.9.44/agnosis-0.9.44.zip';
	$last_updated = ''; // TODO(release): fill in once this version actually ships (YYYY-MM-DD).
	$tested       = '7.0';

	// SHA-256 of the release ZIP — dev/bin/build-zip.sh now fills this in
	// automatically at the end of a successful build (cleared to '' at the
	// start of every run, so a failed/superseded build never leaves a stale
	// digest here). Empty string = verification skipped (safe default for a
	// manifest between builds).
	// TODO(release): pending the built agnosis-0.9.44.zip — run
	// dev/bin/build-zip.sh, upload the result to the v0.9.44 GitHub release,
	// then deploy this manifest.
	//
	// Cleared 2026-07-21: the previous value here was the real sha256 for the
	// 0.9.43 build — now stale against this version bump to 0.9.44 (a
	// different zip, a different hash). Left in place it would silently break
	// update verification: WordPress would hash the newly-downloaded 0.9.44
	// zip and compare it against the OLD 0.9.43 zip's digest, which can never
	// match. Per this file's own documented invariant (below) and
	// build-zip.sh's clear_manifest_sha(), a stale digest is worse than an
	// empty one — empty just skips verification; stale actively fails it.
	$sha256 = '4cb2ad368dfeaf7a840ff6e774fe9813608eef95ee76dd7bc11a6382a5574f3e';

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
		'<h4>0.9.44</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> Medium taxonomy term auto-assignment (the &#8220;Poetry&#8221;/&#8220;Photography&#8221;/etc. filter tabs) had silently never worked for any AI-described artwork or photo submission &#8212; the AI&#8217;s own answer was computed correctly but never actually reached the post. Also fixed: the audio and video-fallback description branches were classifying against the wrong vocabulary and not carrying the result through either, and the &#8220;pure@&#8221; (zero-AI) lane now runs one narrowly-scoped classification call so its submissions get a medium too.</li>' .
			'<li><strong>Fixed:</strong> An AI-proposed medium that doesn&#8217;t match your site&#8217;s configured vocabulary is no longer silently discarded. A new review queue on Artwork &#8594; Mediums shows each pending proposal, which submission(s) it&#8217;s for, and lets you Approve (creates/reuses the term and assigns it) or Reject it.</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/agnosis/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>0.9.43</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> Manually discarding a draft submission from the review screen sent a completely wrong &#8220;photo quality too low&#8221; email &#8212; for every post type, every reason, with or without a real photo involved (e.g. a discarded text-only poem got a &#8220;retake your photo&#8221; bounce). It now sends a plain, honest &#8220;your submission wasn&#8217;t published&#8221; message instead; the photo-quality email is reserved for the real, automatic AI quality-gate rejection it was built for.</li>' .
			'<li><strong>Fixed:</strong> Line breaks could still be lost from a published post even after the 0.9.42 fix, because that fix only covered draft creation &#8212; not the actual review-and-publish flow every submission is approved through, or the native-language translation/sibling-post paths. All of these now preserve the artist&#8217;s own line breaks consistently.</li>' .
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
