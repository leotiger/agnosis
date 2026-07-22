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

	$version      = '0.9.47';
	$download_url = 'https://github.com/leotiger/agnosis/releases/download/v0.9.47/agnosis-0.9.47.zip';
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
	$sha256       = ''; // Not yet built — dev/bin/build-zip.sh computes this at release time.
	$sha256_note  = 'Build started 2026-07-22T18:36:26Z by build-zip.sh for v0.9.47 — will be replaced once the build succeeds, or left here (safe: an empty sha256 already skips verification) if it fails.';
	$last_updated = '2026-07-22';

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
		'<h4>0.9.47</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> A deliberately embedded other-language passage &#8212; e.g. a Latin quotation inside a Catalan poem &#8212; could get translated right along with its surrounding text, and from there spread (already wrong) to every other configured language. A more precise instruction now tells the AI to leave it untouched. Lingua Forge&#8217;s own translation to your other configured languages also now uses a stronger AI model for this specific case.</li>' .
			'<li><strong>Fixed:</strong> A text-only submission&#8217;s poster could end up completely broken after being resent to a different address with unchanged content &#8212; the resend&#8217;s dedupe logic could reuse the existing poster&#8217;s id while a related cleanup step simultaneously deleted it. Poster ids kept for the new gallery are no longer eligible for that cleanup.</li>' .
			'<li><strong>Fixed:</strong> Published images never had alt text in the actual page markup, even when a real description was already stored for them &#8212; an accessibility gap affecting every artwork/biography/event image. Now included, falling back to the artwork&#8217;s own title when nothing more specific is available.</li>' .
			'<li><strong>Changed:</strong> Translating a submission into your site&#8217;s primary language now uses its own, separate AI model setting (Settings &#8594; AI Providers), independent from the cheap/fast model used for medium classification and contact-message moderation.</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/agnosis/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>0.9.46</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> A text-only submission&#8217;s (poetry, essays) auto-generated placeholder image used to pile up a new copy in the gallery every time you corrected and resent it, instead of replacing the outdated one &#8212; and the old, uncorrected version could still show as the featured image. Now only the latest one is kept.</li>' .
			'<li><strong>Fixed:</strong> Correcting a typo on a text-only post through the on-site editor didn&#8217;t update its placeholder image at all before &#8212; the corrected text and the image could disagree indefinitely. The image is now regenerated to match.</li>' .
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
