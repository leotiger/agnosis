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

	$version      = '0.9.42';
	$download_url = 'https://github.com/leotiger/agnosis/releases/download/v0.9.42/agnosis-0.9.42.zip';
	$last_updated = ''; // TODO(release): fill in once this version actually ships (YYYY-MM-DD).
	$tested       = '7.0';

	// SHA-256 of the release ZIP — dev/bin/build-zip.sh now fills this in
	// automatically at the end of a successful build (cleared to '' at the
	// start of every run, so a failed/superseded build never leaves a stale
	// digest here). Empty string = verification skipped (safe default for a
	// manifest between builds).
	// TODO(release): pending the built agnosis-0.9.42.zip — run
	// dev/bin/build-zip.sh, upload the result to the v0.9.42 GitHub release,
	// then deploy this manifest.
	$sha256 = '55edac8a93aefd132d6cf19f57dd7f60c503e8707f6def5f42db4f4b8f9ba142';

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
		'<h4>0.9.42</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> Translation passes &#8212; Agnosis&#8217;s own pre-publish pass and Lingua Forge&#8217;s (2.6.6+) multi-language fan-out pass &#8212; now leave embedded other-language text (a quotation, epigraph, or title deliberately given in its original language) untranslated, instead of flattening it into the target language along with everything else.</li>' .
			'<li><strong>Fixed:</strong> A text-only submission (&#8220;pure@&#8221; &#8212; poetry, an essay, no photo/audio/video) with valid content was wrongly rejected as having no usable attachment. Pure-lane submissions never required one; the real cause was an email-parsing bug that skipped fetching the message body under certain conditions.</li>' .
			'<li><strong>Fixed:</strong> The poster image generated for a text-only submission now fills the frame with the artist&#8217;s own body text (preserving their line breaks), instead of stopping after the title.</li>' .
			'<li><strong>Fixed:</strong> A published post&#8217;s line breaks could silently disappear the first time it was opened in the block editor. Post content is now written as valid native block markup from the moment it&#8217;s created, so there&#8217;s nothing left for the editor to reinterpret.</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/agnosis/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>0.9.41</h4>' .
		'<ul>' .
			'<li><strong>Changed:</strong> Replaced the planned &#8220;Commerce&#8221; revenue layer (visitor donations and art sales with a configurable platform fee) with a simpler plan: a no-fee way for visitors to support an artist directly. Settings &#8594; Commerce is renamed Settings &#8594; Donations, and its old fee-percentage field (never actually used) is gone. Agnosis is not a marketplace &#8212; art sales and checkout are left to dedicated plugins. Nothing was ever live here before, so this doesn&#8217;t change how any existing site behaves.</li>' .
			'<li><strong>Fixed:</strong> The &#8220;Mediums&#8221; checklist on the Artworks Quick Edit panel (and the artwork edit screen) no longer mixes every language&#8217;s translation of every medium into one list &#8212; it now shows only the mediums for that artwork&#8217;s own language (Quick Edit follows the list&#8217;s own language filter; the edit screen follows that specific artwork). Previously all of them appeared together (e.g. seven different-language versions of &#8220;Watercolor&#8221; at once), which also made it possible to accidentally assign a wrong-language medium to an artwork. (A first attempt at this fix shipped earlier the same day and didn&#8217;t actually work &#8212; this is the corrected version.)</li>' .
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
