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

	$version      = '0.9.22';
	$download_url = 'https://github.com/leotiger/agnosis/releases/download/v0.9.22/agnosis-0.9.22.zip';
	$last_updated = ''; // TODO(release): fill in once this version actually ships (YYYY-MM-DD).
	$tested       = '7.0';

	// SHA-256 of the release ZIP — dev/bin/build-zip.sh now fills this in
	// automatically at the end of a successful build (cleared to '' at the
	// start of every run, so a failed/superseded build never leaves a stale
	// digest here). Empty string = verification skipped (safe default for a
	// manifest between builds).
	// TODO(release): pending the built agnosis-0.9.22.zip — run
	// dev/bin/build-zip.sh, upload the result to the v0.9.22 GitHub release,
	// then deploy this manifest.
	$sha256 = '988ec8f6c4d1a2459708eb1f9d23cc20397b5d7ee1c34e0a0ed1e3ec821b03ae';

	// Two most recent releases only — do not accumulate history here; it
	// bloats the manifest. Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>0.9.22</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> Two new default medium categories, Poetry and Essay, so the built-in list now covers written submissions, not just visual ones.</li>' .
			'<li><strong>Added:</strong> Agnosis now checks for updates itself and shows the standard WordPress &#8220;Update available&#8221; badge with one-click updating, the same way the companion Lingua Forge plugin already does &#8212; no more manually re-uploading a ZIP for each new release. Package downloads are host-pinned and SHA-256 verified for safety.</li>' .
			'<li><strong>Changed:</strong> Lingua Forge is now a required plugin, not an optional one &#8212; WordPress won&#8217;t let you activate Agnosis until Lingua Forge is installed and active (and won&#8217;t let you deactivate Lingua Forge while Agnosis is active). Reflects how much of the plugin already depends on it.</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/agnosis/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>0.9.21</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> A visitor can no longer message the same artist more than a configurable number of times per hour &#8212; previously the contact form only limited by IP address and, separately, by sender email address across every artist, so nothing stopped repeated messages to one specific artist. Configurable at Settings &#8594; Email (&#8220;Per-artist contact limit&#8221; and its time window). Applies the same regardless of which language version of the artist&#8217;s page is used.</li>' .
			'<li><strong>Added:</strong> A new Settings &#8594; General &#8220;Preset biography title&#8221; field lets you force every artist&#8217;s biography page to use the same fixed title instead of their own, with an optional checkbox to append the artist&#8217;s name to it (e.g. &#8220;Meet the Artist &#8212; Jane Doe&#8221;). Leave it blank to keep using each artist&#8217;s own title, exactly as before. Applies to every Lingua Forge translated version of a biography page too.</li>' .
			'<li><strong>Fixed:</strong> The contact form no longer just hides itself in the browser after a message is sent, since that could be undone to send more. Submitting now reloads the page; the form is then replaced with a &#8220;message sent&#8221; notice until the per-artist limit above allows another message.</li>' .
			'<li><strong>Fixed:</strong> Resending a biography, artwork, or event with a new photo now actually updates the published post&#8217;s featured image (previously it silently kept the old one), and the new photo now also reaches every Lingua Forge translated version of that page instead of just the primary language.</li>' .
			'<li><strong>Fixed:</strong> A biography&#8217;s three optional social links, and corrections to its portfolio link, now reach every Lingua Forge translated version of the page &#8212; previously they only ever showed up on the primary language.</li>' .
			'<li><strong>Fixed:</strong> A biography&#8217;s portfolio link no longer appears twice &#8212; once as a social icon below the photo, once again as a duplicate embedded preview inside the text. It now shows only as the icon.</li>' .
			'<li><strong>Fixed:</strong> An artist could sometimes receive a second &#8220;please approve this&#8221; email for a submission they&#8217;d already approved and published, or already discarded &#8212; triggered by an admin&#8217;s &#8220;heal the queue&#8221; action, or automatically after a mailbox migration, with no action needed from the artist. This is now fixed at the source.</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/agnosis/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>';

	// -------------------------------------------------------------------------
	// STATIC FIELDS — change rarely
	// -------------------------------------------------------------------------

	$manifest = [
		'version'      => $version,
		'requires'     => '6.6',
		'requires_php' => '8.1',
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
