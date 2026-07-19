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

	$version      = '0.9.39';
	$download_url = 'https://github.com/leotiger/agnosis/releases/download/v0.9.39/agnosis-0.9.39.zip';
	$last_updated = ''; // TODO(release): fill in once this version actually ships (YYYY-MM-DD).
	$tested       = '7.0';

	// SHA-256 of the release ZIP — dev/bin/build-zip.sh now fills this in
	// automatically at the end of a successful build (cleared to '' at the
	// start of every run, so a failed/superseded build never leaves a stale
	// digest here). Empty string = verification skipped (safe default for a
	// manifest between builds).
	// TODO(release): pending the built agnosis-0.9.39.zip — run
	// dev/bin/build-zip.sh, upload the result to the v0.9.39 GitHub release,
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
		'<h4>0.9.39</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> The &#8220;Sync all translations&#8221; button on the Tags/Mediums screens no longer risks silently timing out on a large vocabulary. It now stops cleanly after about 20 seconds of work and tells you how many terms are left &#8212; click it again to continue exactly where it stopped.</li>' .
			'<li><strong>Fixed:</strong> Two languages that happen to translate a term to the same word (e.g. &#8220;Fotografie&#8221; in both German and Dutch) no longer permanently fail that language&#8217;s sync &#8212; it&#8217;s now resolved automatically, and any translation that genuinely couldn&#8217;t be created is now called out in the notice instead of silently disappearing.</li>' .
			'<li><strong>Fixed:</strong> Corrected several wrong-match translations left over from an earlier automated translation-file update. The translation build process now automatically clears any uncertain matches going forward so they get retranslated properly instead of shipping a plausible-looking but wrong guess.</li>' .
			'<li><strong>Fixed:</strong> A few small polish issues on the Tags/Mediums language filter added last version &#8212; an &#8220;All languages (unfiltered)&#8221; escape hatch for a term flagged with a since-removed language, search no longer resets the language view, and syncing a term no longer bounces you back to page 1 of the list.</li>' .
			'<li><strong>Fixed:</strong> A remote Fediverse account that deletes itself is now cleaned up from your followers list even when its signing key can no longer be fetched at all (a known Mastodon-ecosystem timing quirk) &#8212; previously that follower record could be left behind indefinitely.</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/agnosis/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>0.9.38</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> The Tags and Mediums admin screens (Posts &#8594; Artwork &#8594; Mediums, etc.) now show only your own primary-language terms by default, with a new dropdown to switch to any other configured language &#8212; no more hundreds of AI-translated duplicates mixed into one list. Mediums also got a &#8220;Sync translations&#8221; action to create a term&#8217;s translation in every configured language on demand, and editing an artwork&#8217;s medium after publishing now correctly updates its already-translated sibling posts too, instead of leaving them stuck on the old term.</li>' .
			'<li><strong>Added:</strong> A &#8220;Sync all translations&#8221; button next to the Tags/Mediums language dropdown syncs every primary-language term to every configured language in one click, instead of the per-term &#8220;Sync translations&#8221; row action. Safe to click again if a large vocabulary times out partway through &#8212; it resumes rather than redoing work.</li>' .
			'<li><strong>Added:</strong> A new &#8220;Medium translations&#8221; box on each artwork&#8217;s edit screen, plus a matching bulk action on the artwork list screen, pushes an artwork&#8217;s medium onto its already-translated sibling posts on demand.</li>' .
			'<li><strong>Fixed:</strong> Term translations are now linked to their source term by a stable ID instead of by matching names, so re-syncing after a re-translation no longer creates near-duplicate terms.</li>' .
			'<li><strong>Fixed:</strong> Tags and mediums created automatically while AI-tagging a submission in a non-primary language are now correctly recorded as translations, instead of silently joining the primary-language vocabulary.</li>' .
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
