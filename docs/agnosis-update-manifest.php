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

	$version      = '0.9.50';
	$download_url = 'https://github.com/leotiger/agnosis/releases/download/v0.9.50/agnosis-0.9.50.zip';
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
	$sha256_note  = 'Pending — not yet built by build-zip.sh.';
	$last_updated = ''; // Not yet built — dev/bin/build-zip.sh writes today's date at release time.

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
		'<h4>0.9.50</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> The contact, join, and newsletter-signup forms&#8217; success/error messages weren&#8217;t announced to screen readers after submitting &#8212; sighted visitors saw them appear normally, but a screen-reader user heard nothing. Each notice now announces itself as soon as it appears.</li>' .
			'<li><strong>Fixed:</strong> A handful of translations (Arabic, Russian, Catalan, and 12 other languages) had a plural form come out wrong &#8212; a missing number placeholder, or two forms accidentally merged into one &#8212; so some notification/count text could show up wrong or blank in those languages. All are corrected now.</li>' .
			'<li><strong>Fixed:</strong> Internal dev tooling used to keep translations up to date (<code>translate-missing</code>) had a few bugs that could cause a translation batch to fail silently, or in some cases write garbled text into a translation file &#8212; fixed, plus two safeguards added so those specific failures can&#8217;t happen again.</li>' .
			'<li><strong>Added:</strong> The Agnosis Theme&#8217;s translation catalog now has the same AI-fill and compile tooling the plugin already had (dev-only, no user-facing change).</li>' .
			'<li><strong>Fixed:</strong> Six admin-dashboard inputs (invite/test-send email fields, a ban-until date, a title translation field) relied on placeholder text only, so screen readers had no accessible name for them. Each now has a proper label.</li>' .
			'<li><strong>Fixed:</strong> The newsletter digest&#8217;s artwork thumbnail had no alt text, so a screen reader had nothing to announce for that link. Now uses the artwork&#8217;s title.</li>' .
			'<li><strong>Hardened:</strong> Internal cleanup that removes a superseded auto-generated placeholder image now double-checks it&#8217;s actually a placeholder before deleting it &#8212; extra protection against ever deleting a real photo by mistake (no known instance of this happening).</li>' .
			'<li><strong>Fixed:</strong> A cosmetic inconsistency in the internal release-packaging notes (dev-only, no user-facing change).</li>' .
			'<li><strong>Fixed:</strong> An internal translation tool could silently skip strings that still needed translating under certain conditions (dev-only, no user-facing change).</li>' .
		'</ul>' .
		'<h4>0.9.49</h4>' .
		'<ul>' .
			'<li><strong>Removed:</strong> The temporary debug trace added in 0.9.48 (native-language-to-primary translation) logged full submission text and its translation to Settings &#8594; Logs. A real multi-language submission has since confirmed the underlying translation fix holds, so the trace is removed &#8212; no submission content is logged anymore.</li>' .
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
