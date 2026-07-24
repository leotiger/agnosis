=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.9.51
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

**Zero cost to artists.** Agnosis doesn't charge artists to participate, and never will. A simple visitor-donation feature, with no platform fee, is planned but not yet built — today, running a node has no donation feature to enable. Agnosis is not a marketplace; art sales and checkout are left to dedicated plugins.

**Rhizome network.** Any site can run an Agnosis node. Nodes federate with each other and with the broader Fediverse. No central server. No single point of failure. Nodes can also run in a subdomain mode, giving each artist their own scoped `artistname.yoursite.com` space.

= Who is this for? =

Agnosis was designed for one shape — an open artist collective that vouches its own members in — but it fits a few other deployments too:

* **Artist collectives / open communities** — the default out of the box. Existing artists vouch new ones in by email vote.
* **Galleries and curated programs** — turn community voting off (Settings → Community → "Admin approval only") and every admission and removal becomes a direct admin call instead of a community vote. The medium vocabulary is a manageable taxonomy, not hardcoded, so it can be reshaped to match your program.
* **Theatre/art schools and student showcases** — admin-approval mode fits an instructor-curated flow. Two things to know: Agnosis assumes adult artists (public attribution and email workflow), so a deployment involving minors needs its own consent/guardian handling; and there's no built-in cohort/semester structure today.
* **Writers' collectives and literary magazines** — already a real fit: Poetry and Essay are canonical medium terms, and text-only submissions get the same visual presence as photography in galleries and federation.

One Agnosis install is one community — a deployment with several departments or classes needs either separate installs or one shared vocabulary across all of them.

= Core Features =

* Email-to-post: IMAP + webhook support, for artwork, biographies, and events
* Image, sound, and video submissions (HEIC/PDF normalization, video poster-frame extraction)
* AI image enhancement (OpenAI gpt-image-1 or gpt-image-2, configurable)
* AI artwork description (Claude, GPT-4o Vision)
* Front-end correction: artists can edit their own published title, text, or photos afterward, no re-submission needed
* Visitor contact form: message an artist directly from their page, spam-limited per IP/sender and per artist
* Biography social links: a portfolio link plus three optional social links, shown as an icon row on the artist's biography page; an optional site-wide preset biography title is also available
* Community vouching / admission system
* ActivityPub federation (Mastodon-compatible)
* Node identity & peer discovery
* Per-artist subdomain mode
* Digest newsletter for followers; in-site Artist Guide page
* Settings → Donations holds a status note for a planned, no-fee visitor-donation feature — not yet a working mechanism; Agnosis leaves marketplace/checkout functionality to dedicated plugins
* `agnosis_artwork`/`agnosis_biography`/`agnosis_event` custom post types with Gutenberg gallery blocks
* Settings: General | Branding | Email | AI Providers | Behavior | Network | Community | Donations | Newsletter | Logs

== Installation ==

1. Install and activate **Lingua Forge** first — Agnosis declares it as a required plugin (WordPress 6.5+ Plugin Dependencies) and will refuse to activate with only a generic error if it isn't already installed and active.
2. Upload the `agnosis` folder to `/wp-content/plugins/`
3. Activate via **Plugins → Installed Plugins**
4. Go to **Agnosis** in the admin sidebar and configure your email inbox and AI API keys
5. Artists apply at the **`/join/`** page and are vouched in by peers

== Frequently Asked Questions ==

= Is it really free for artists? =
Yes. Agnosis never charges artists to participate, and takes no fee. A simple way for visitors to donate directly to an artist is planned (Settings → Donations), but that mechanism hasn't been built yet, so no money currently changes hands through the plugin. Agnosis is not a marketplace — if you want to sell art through your site, pair it with a dedicated commerce plugin.

= Do I need to run my own server? =
No. Node hosts in the network can offer space to artists. But if you want your own node, install this plugin on any WordPress site.

= Which AI providers do I need? =
At minimum one API key. OpenAI alone covers both description and enhancement. Claude gives richer artistic descriptions if you have both.

= Does this work with Mastodon? =
Yes. Once ActivityPub is enabled, your node is a Fediverse actor. Mastodon users can follow `@agnosis@yoursite.com` and see new artworks in their feed.

== Screenshots ==

1. The Agnosis Settings screen, where you configure your email inbox, AI provider keys, and community rules.
2. A published artwork post, showing the AI-generated title, description, and tags alongside the artist's photo.
3. Front-end correction: an artist editing their own published title and text directly on the page, no re-submission needed.
4. The in-site Artist Guide, walking new members through the email-driven submission workflow in plain language.
5. A biography page showing an artist's portfolio link and social icons.

== Changelog ==

= 0.9.51 =
* Added: Artworks now show how many times they've been liked and boosted (reshared) across the fediverse — a small, independent count of each below the artwork itself, not a separate page. No setup needed; boosts and likes are always counted, with nothing for artists or admins to approve.

= 0.9.50 =
* Fixed: The contact, join, and newsletter-signup forms' success/error messages weren't announced to screen readers after submitting — sighted visitors saw them appear normally, but a screen-reader user heard nothing. Each notice now announces itself as soon as it appears.
* Fixed: A handful of translations (Arabic, Russian, Catalan, and 12 other languages) had a plural form come out wrong — a missing number placeholder, or two forms accidentally merged into one — so some notification/count text could show up wrong or blank in those languages. All are corrected now.
* Fixed: Internal dev tooling used to keep translations up to date (`translate-missing`) had a few bugs that could cause a translation batch to fail silently, or in some cases write garbled text into a translation file — fixed, plus two safeguards added so those specific failures can't happen again.
* Added: The Agnosis Theme's translation catalog now has the same AI-fill and compile tooling the plugin already had (dev-only, no user-facing change).
* Fixed: Six admin-dashboard inputs (invite/test-send email fields, a ban-until date, a title translation field) relied on placeholder text only, so screen readers had no accessible name for them. Each now has a proper (visually-hidden) label.
* Fixed: The newsletter digest's artwork thumbnail had no alt text, so a screen reader had nothing to announce for that link. Now uses the artwork's title.
* Hardened: Internal cleanup that removes a superseded auto-generated placeholder image now double-checks it's actually a placeholder before deleting it — extra protection against ever deleting a real photo by mistake (no known instance of this happening).
* Fixed: A cosmetic inconsistency in the internal release-packaging notes (dev-only, no user-facing change).
* Fixed: An internal translation tool could silently skip strings that still needed translating under certain conditions (dev-only, no user-facing change).

For the complete version history, see CHANGELOG.md in the plugin's source repository.

== Upgrade Notice ==

= 0.9.51 =
New database table for like/boost counts is created automatically on upgrade; no action needed.

