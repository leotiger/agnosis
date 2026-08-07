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
 * Instance check-in telemetry (which sites are polling this endpoint, and
 * what version they're running) is NOT implemented in this file — it lives
 * in agnosis-manifest-includes/telemetry.php, required below, so this file
 * stays exactly what its own docblock says it is: a short "what to edit on
 * every release" document. See that file's own docblock, and
 * RHIZOME-NETWORK-ROADMAP.md §9/§12 (TEL1) in the agnosis-audit repo, for
 * the full design and privacy reasoning.
 *
 * @package Agnosis
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/agnosis-manifest-includes/telemetry.php';

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

function agnosis_update_manifest_endpoint( WP_REST_Request $request ): WP_REST_Response {

	// Record this check-in (site URL, WP version, Agnosis version — parsed
	// from the request's own User-Agent) before anything else. Never allowed
	// to affect or slow the actual manifest response below; see
	// agnosis_manifest_telemetry_record()'s own docblock.
	agnosis_manifest_telemetry_record( $request );

	// -------------------------------------------------------------------------
	// UPDATE THESE FIELDS ON EVERY RELEASE
	// -------------------------------------------------------------------------

	$version      = '0.9.68';
	$download_url = 'https://github.com/leotiger/agnosis/releases/download/v0.9.68/agnosis-0.9.68.zip';
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
	$sha256       = ''; // Not yet built — dev/bin/build-zip.sh computes this at release time.
	$sha256_note  = 'Build started 2026-08-07T18:12:34Z by build-zip.sh for v0.9.68 — will be replaced once the build succeeds, or left here (safe: an empty sha256 already skips verification) if it fails.';
	$last_updated = '2026-08-06';

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
		'<h4>0.9.68</h4>' .
		'<ul>' .
			'<li><strong>Changed:</strong> Removed four version checks against Lingua Forge that were never doing anything &#8212; the features they guarded hang off hooks Lingua Forge itself fires, so on an older version they simply never run. Agnosis now checks whether the thing it needs exists rather than reading a version number, everywhere except the admin notice that tells you which Lingua Forge version you have. Nothing behaves differently on any version.</li>' .
			'<li><strong>Changed:</strong> Development tooling only &#8212; the static analyser used to check this plugin&#8217;s code was upgraded to its current major version.</li>' .
		'</ul>' .
		'<h4>0.9.67</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> The &#8220;Follow&#8221; button on an artwork could show a Fediverse handle that doesn&#8217;t resolve &#8212; on artworks published by an admin, or by someone who is no longer an artist. The button no longer appears in those cases.</li>' .
			'<li><strong>Fixed:</strong> A review link for a submission that no longer exists silently dropped the artist on the home page. It now explains what happened &#8212; as does a link that arrives incomplete because a mail app cut it in half.</li>' .
			'<li><strong>Fixed:</strong> The email asking you to review the AI-drafted newsletter intro was plain, unstyled text. It now uses the same branded layout as every other Agnosis message.</li>' .
			'<li><strong>Fixed:</strong> The deliverability test email is now sent in the same branded HTML as real messages, so what arrives is what your artists and subscribers receive.</li>' .
			'<li><strong>Changed:</strong> Agnosis now requires WordPress 6.9 or newer, up from 6.6. Nothing behaved differently on older versions; this only stops it claiming support for versions it is not tested against.</li>' .
			'<li><strong>Changed:</strong> The Fediverse code has been reorganized internally &#8212; the single file behind ActivityPub, which had grown past 6,000 lines, is now nine focused ones. Nothing behaves differently and the full test suite passes unchanged.</li>' .
			'<li><strong>Changed:</strong> Removed a piece of dead code left over from the 0.9.54 change that let translated versions of an artwork federate on their own.</li>' .
			'<li><strong>Fixed:</strong> Four admin controls had no accessible name for screen-reader and voice-control users &#8212; the status and skip-reason filters on Submissions, the status filter on Contact Messages, and read-only settings fields.</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/agnosis/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>';

	// -------------------------------------------------------------------------
	// STATIC FIELDS — change rarely
	// -------------------------------------------------------------------------

	$manifest = [
		'version'      => $version,
		'requires'     => '6.9',
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
