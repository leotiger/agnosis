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
 * On every release: update $version, $download_url, $last_updated, and
 * prepend the new entry to $sections['changelog']. $sha256/$sha256_note are
 * a machine-managed pair — see their own comment below; a hand version-bump
 * only needs to reset both to their "not built yet" defaults, never write
 * real values into either by hand.
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

	$version      = '0.9.45';
	$download_url = 'https://github.com/leotiger/agnosis/releases/download/v0.9.45/agnosis-0.9.45.zip';
	$last_updated = ''; // TODO(release): fill in once this version actually ships (YYYY-MM-DD).
	$tested       = '7.0';

	// SHA-256 of the release ZIP, plus a one-line human-readable status note —
	// both fields are exclusively maintained by dev/bin/build-zip.sh, never by
	// hand. The script clears both to their "not built" defaults at the START
	// of every run (so a failed or superseded build never leaves a stale
	// digest behind — empty $sha256 = verification skipped, a safe documented
	// default; a stale one would silently BREAK update verification instead,
	// since WordPress would hash the newly-downloaded zip and compare it
	// against a digest belonging to a DIFFERENT zip, which can never match),
	// then writes the freshly-built zip's real digest once the build succeeds.
	//
	// $sha256_note exists specifically so this file can never again say
	// "pending"/"cleared" in hand-written prose while $sha256 itself already
	// disagrees — exactly the self-contradiction fourteenth-audit finding 5b
	// caught (a filled digest sitting next to a comment insisting no build had
	// happened yet, because that comment was hand-written at version-bump time
	// and never re-synced once a real build actually ran days later). Now
	// there is only one thing to say, and only the script says it.
	//
	// Hand version-bumps still must reset BOTH fields to the values below —
	// build-zip.sh only runs at build time, not at version-bump time, so it
	// can't do that part for you. Never write a real digest or a "verified"
	// note into either field by hand.
	$sha256      = '';
	$sha256_note = 'Not yet built for this version — dev/bin/build-zip.sh has not run since the version bump.';

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
		'<h4>0.9.45</h4>' .
		'<ul>' .
			'<li><strong>Changed:</strong> Six places describing the &#8220;Pure&#8221; email lane said AI never touches it &#8220;at all&#8221; &#8212; no longer accurate now that a classification call runs to categorize the piece. Reworded to the real promise: your words and photo are never touched or rewritten by AI, which is only ever used to classify the medium and a few tags so your post stays findable.</li>' .
			'<li><strong>Changed:</strong> The in-site Artist Guide now has a section explaining the Pure lane directly &#8212; it wasn&#8217;t documented there before.</li>' .
			'<li><strong>Fixed:</strong> A submission sent to the Pure address published with no tags at all, since the classification step computed a medium but silently dropped the tags from the very same AI response. Both are now kept, with no extra AI call needed.</li>' .
			'<li><strong>Fixed:</strong> A gender-neutral phrasing rule for AI translation (added 0.9.39, to stop things like &#8220;artist&#8221; defaulting to a gendered word in German/French/Spanish) was applied to Agnosis&#8217;s own translations but never passed through to Lingua Forge&#8217;s own translation pass &#8212; so an LF-retranslated biography or artwork could regress to a gendered default. Both AI translation instructions now travel together.</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/agnosis/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>0.9.44</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> A new Settings &#8594; AI Providers option (Max AI response tokens) lets you raise the AI response length ceiling for translation and other chat-based AI tasks, instead of a fixed limit &#8212; useful if you&#8217;re translating long text into several configured languages.</li>' .
			'<li><strong>Fixed:</strong> On a site with several configured languages, the artist&#8217;s own artwork/event title in the last-configured language would silently never get auto-translated. AI translation calls now correctly size their response budget for however many languages are configured.</li>' .
			'<li><strong>Fixed:</strong> Two small input-handling hardening improvements to the medium-proposal review screen (Artwork &#8594; Mediums), found by WordPress.org&#8217;s Plugin Check tool.</li>' .
			'<li><strong>Fixed:</strong> Medium taxonomy term auto-assignment (the &#8220;Poetry&#8221;/&#8220;Photography&#8221;/etc. filter tabs) had silently never worked for any AI-described artwork or photo submission &#8212; the AI&#8217;s own answer was computed correctly but never actually reached the post. Also fixed: the audio and video-fallback description branches were classifying against the wrong vocabulary and not carrying the result through either, and the &#8220;pure@&#8221; (zero-AI) lane now runs one narrowly-scoped classification call so its submissions get a medium too.</li>' .
			'<li><strong>Fixed:</strong> An AI-proposed medium that doesn&#8217;t match your site&#8217;s configured vocabulary is no longer silently discarded. A new review queue on Artwork &#8594; Mediums shows each pending proposal, which submission(s) it&#8217;s for, and lets you Approve (creates/reuses the term and assigns it) or Reject it.</li>' .
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
