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
 * On every release: update $version, $download_url, and prepend the new
 * entry to $sections['changelog']. $sha256/$sha256_note/$last_updated are a
 * machine-managed trio — see their own comment below; a hand version-bump
 * only needs to reset all three to their "not built yet" defaults, never
 * write real values into any of them by hand.
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

	$version      = '0.9.54';
	$download_url = 'https://github.com/leotiger/agnosis/releases/download/v0.9.54/agnosis-0.9.54.zip';
	$tested       = '7.0';

	// SHA-256 of the release ZIP, a one-line human-readable status note, and
	// the date this version's zip was actually built — all three fields are
	// exclusively maintained by dev/bin/build-zip.sh, never by hand. The
	// script clears $sha256/$sha256_note to their "not built" defaults at the
	// START of every run (so a failed or superseded build never leaves a
	// stale digest behind — empty $sha256 = verification skipped, a safe
	// documented default; a stale one would silently BREAK update
	// verification instead, since WordPress would hash the newly-downloaded
	// zip and compare it against a digest belonging to a DIFFERENT zip, which
	// can never match), then writes all three real values once the build
	// succeeds. $last_updated is intentionally NOT cleared at the start the
	// way $sha256 is — there's no "unsafe stale value" risk for a plain
	// display date the way there is for a digest silently mismatching, so a
	// failed build simply leaves the previous successful build's date in
	// place rather than blanking it.
	//
	// $sha256_note exists specifically so this file can never again say
	// "pending"/"cleared" in hand-written prose while $sha256 itself already
	// disagrees — exactly the self-contradiction fourteenth-audit finding 5b
	// caught (a filled digest sitting next to a comment insisting no build had
	// happened yet, because that comment was hand-written at version-bump time
	// and never re-synced once a real build actually ran days later). Now
	// there is only one thing to say, and only the script says it.
	//
	// $last_updated used to be a separate hand-set-at-ship-time field (per its
	// own now-removed TODO comment) — questioned directly: since build-zip.sh
	// already knows today's date (it's already in $sha256_note's own text),
	// there was no real reason to keep this one manual when the documented
	// release process (CONTRIBUTING.md) already builds the zip immediately
	// before shipping it. The date recorded is "when this zip was last built
	// locally," used as a stand-in for "when this version shipped" — accurate
	// for the intended same-session build-then-ship workflow; if a real gap
	// ever opens up between building and actually uploading/deploying, just
	// re-run build-zip.sh right before uploading to refresh the date, the
	// same way you'd re-run it to refresh $sha256 for a changed zip.
	//
	// Hand version-bumps still must reset all three fields to the values
	// below — build-zip.sh only runs at build time, not at version-bump time,
	// so it can't do that part for you. Never write a real digest, a
	// "verified" note, or a real date into any of them by hand.
	//
	// 6a fix (fifteenth audit, 2026-07-24): $sha256's own trailing inline
	// `// comment` (distinct from $sha256_note above) is ALSO now rewritten
	// by build-zip.sh at both the clear and the write step — same self-
	// contradiction 5b closed for $sha256_note (a verified digest sitting
	// next to prose insisting no build had happened) could otherwise recur
	// one line up, since the two comments are separate pieces of text. Hand-
	// editing $sha256 is therefore the same as hand-editing $sha256_note:
	// don't — the trailing comment is part of what build-zip.sh owns now.
	$sha256       = '0e4255feb93f585179dc03bef53d2ee9a7dbc0871700caadc441850b4b590bee'; // Verified — see $sha256_note above for build date/version.
	$sha256_note  = 'Verified — sha256 written by build-zip.sh on 2026-07-25 for agnosis-0.9.54.zip.';
	$last_updated = '2026-07-25';

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
		'<h4>0.9.54</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> Tags redesign completed. Tag and medium-category translations into other languages now happen automatically and propagate to already-translated posts on their own. A new one-click &#8220;Re-tag&#8221; button on each artwork (and an optional bulk backfill script) can (re-)generate tags for posts that don&#8217;t have them yet. Renaming or deleting a tag/medium term now correctly updates or removes its translations too, and the proposal-review expiry setting is now shared between tags and medium categories.</li>' .
			'<li><strong>Added:</strong> Federated posts now carry proper language metadata, so followers using Mastodon&#8217;s per-account language filter see them correctly.</li>' .
				'<li><strong>Added:</strong> A translated-language version of an artwork is now correctly reachable and kept up to date over ActivityPub &#8212; editing or removing it now federates properly, instead of only the original-language post doing so.</li>' .
				'<li><strong>Added:</strong> An artwork now federates to the Fediverse once its tags and medium category are actually settled, so it arrives with real hashtags instead of none; a genuinely new tag/medium proposal briefly delays federation until reviewed (or a configurable timeout passes).</li>' .
				'<li><strong>Added:</strong> A new &#8220;Federate which languages&#8221; setting (Settings &#8594; Behavior) can turn on sending each artwork&#8217;s translated versions to the Fediverse too, one post per language &#8212; off by default, so nothing changes unless you opt in. Every federated post now also links its other available languages.</li>' .
		'</ul>' .
		'<h4>0.9.53</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> Tags are back, rebuilt from scratch. New submissions once again get AI-proposed tags in the site&#8217;s main language, reusing an existing tag when one already fits instead of creating a near-duplicate. A brand-new tag name now waits for an admin&#8217;s Approve/Reject on the Tags screen rather than being created automatically; an unreviewed proposal is auto-rejected after 7 days (configurable).</li>' .
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
