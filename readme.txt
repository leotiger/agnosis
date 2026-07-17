=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.9.34
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Art blooming out of oblivion. Email your art, AI polishes it, the world sees it.

== Description ==

**Agnosis** is a free, federated publishing network for independent artists.

Artists who are great at creating — but not at promoting — can simply **send an email** with their artwork, a biography, or an event, and a short description. Agnosis takes it from there:

1. **Receives** the email (via IMAP or webhook) — images, sound, and video are all supported
2. **Enhances** the images with AI (OpenAI)
3. **Writes** a title, description and tags with AI (Claude / GPT-4o)
4. **Publishes** a gallery post automatically
5. **Broadcasts** to the Fediverse (Mastodon, Pixelfed) via ActivityPub

Once published, an artist can also correct their own title, text, or photos directly on the site — no need to compose another email. Agnosis requires the companion Lingua Forge plugin (installed and active before Agnosis can be activated) for automatic multi-language translation of every post and taxonomy term. Followers can get a digest newsletter of what's new instead of checking back manually, and an in-site Artist Guide walks new members through the whole email-driven workflow in plain language.

**Community-first admission.** New artists are vouched in by existing artists — no gatekeepers, no committees. The community grows itself.

**Zero cost to artists — for now.** Agnosis doesn't charge artists to participate. A future revenue layer of optional visitor donations and art sales (with a configurable transaction fee) is planned and partly scaffolded in Settings → Commerce, but that donation/store mechanism itself hasn't been built yet — today, running a node has no revenue feature to enable.

**Rhizome network.** Any site can run an Agnosis node. Nodes federate with each other and with the broader Fediverse. No central server. No single point of failure. Nodes can also run in a subdomain mode, giving each artist their own scoped `artistname.yoursite.com` space.

= Core Features =

* Email-to-post: IMAP + webhook support, for artwork, biographies, and events
* Image, sound, and video submissions (HEIC/PDF normalization, video poster-frame extraction)
* AI image enhancement (OpenAI gpt-image-1)
* AI artwork description (Claude, GPT-4o Vision)
* Front-end correction: artists can edit their own published title, text, or photos afterward, no re-submission needed
* Visitor contact form: message an artist directly from their page, spam-limited per IP/sender and per artist
* Biography social links: a portfolio link plus three optional social links, shown as an icon row on the artist's biography page; an optional site-wide preset biography title is also available
* Community vouching / admission system
* ActivityPub federation (Mastodon-compatible)
* Node identity & peer discovery
* Per-artist subdomain mode
* Digest newsletter for followers; in-site Artist Guide page
* Settings → Commerce holds a configurable transaction-fee percentage for a planned donation/store revenue layer — not yet a working checkout
* `agnosis_artwork`/`agnosis_biography`/`agnosis_event` custom post types with Gutenberg gallery blocks
* Settings: General | Branding | Email | AI Providers | Behavior | Network | Community | Commerce | Newsletter | Logs

== Installation ==

1. Install and activate **Lingua Forge** first — Agnosis declares it as a required plugin (WordPress 6.5+ Plugin Dependencies) and will refuse to activate with only a generic error if it isn't already installed and active.
2. Upload the `agnosis` folder to `/wp-content/plugins/`
3. Activate via **Plugins → Installed Plugins**
4. Go to **Agnosis** in the admin sidebar and configure your email inbox and AI API keys
5. Artists apply at the **`/join/`** page and are vouched in by peers

== Frequently Asked Questions ==

= Is it really free for artists? =
Yes. Agnosis never charges artists to participate. A donation/art-sale revenue model with a configurable transaction fee is planned (Settings → Commerce holds the fee setting), but that mechanism hasn't been built yet, so no money currently changes hands through the plugin either way.

= Do I need to run my own server? =
No. Node hosts in the network can offer space to artists. But if you want your own node, install this plugin on any WordPress site.

= Which AI providers do I need? =
At minimum one API key. OpenAI alone covers both description and enhancement. Claude gives richer artistic descriptions if you have both.

= Does this work with Mastodon? =
Yes. Once ActivityPub is enabled, your node is a Fediverse actor. Mastodon users can follow `@agnosis@yoursite.com` and see new artworks in their feed.

== Changelog ==

= 0.9.34 =
* Fixed: Agnosis's three hand-maintained lists of scheduled background tasks (WP-Cron hooks) had drifted out of sync — plugin deletion wasn't clearing two of them (including one that could leave a stale scheduled task behind indefinitely), and deactivation wasn't clearing three others. Reconciled all three lists against a single source of truth, with an automated test that keeps them from drifting apart again.
* Fixed: The self-hosted update-check feed had gone eleven versions stale, still describing version 0.9.22 — brought current, and added to the standing release checklist so it can't silently drift again.
* Fixed: The fediverse followers list (visible to Mastodon and other federated software) now identifies each follower by their own address, instead of an internal delivery detail it was publishing by mistake.
* Fixed: An email with no plain-text version at all — some webmail "rich text" modes, several mobile mail apps, and most marketing/newsletter composers send this way — no longer loses its description text. Agnosis now reads the message's formatted content instead when there's no plain text to fall back on.
* Fixed: When a follower's fediverse account is deleted, Agnosis now removes them from its follower list right away instead of continuing to (unsuccessfully) deliver to them for days. A confirmed-dead delivery address is also now recognized immediately rather than retried for the full waiting period.
* Changed: The Installation section now notes that Lingua Forge must be installed and active before Agnosis, since Agnosis won't activate without it. Also a code-comment spelling cleanup (behaviour→behavior, colour→color) — no functional change.
* Changed: Added automated test coverage for three previously hand-verified-only or deferred safety checks — the newsletter/fediverse delivery queues' overlap protection, every outgoing email actually honoring a configured accent color, and the header text-color contrast switch — no functional change.
* Changed: Split the large internal Settings-page code file into several smaller, focused files, organized by which admin dashboard/card each one renders. Purely internal code organization — no change to how the Settings page looks or behaves. (A follow-up static-analysis check caught and fixed one small code-correctness slip in this same split before it shipped.)

= 0.9.33 =
* Added: The artist breadcrumb now shows the artist's native language as a two-letter code next to the biography/events/contact icons, with the language's own native name shown on hover.
* Fixed: Clicking the "confirm your application" link in the join email landed artists on a page that still needed one more click to actually confirm — several first applicants got stuck there, not realizing anything more was needed. That page now confirms automatically the moment it loads in a real browser, while still rejecting a bare prefetch/scan of the link, so the protection against mail-scanner false-positives stays intact.

For the complete version history, see CHANGELOG.md in the plugin's source repository.

