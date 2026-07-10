=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.15
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Art blooming out of oblivion. Email your art, AI polishes it, the world sees it.

== Description ==

**Agnosis** is a free, federated publishing network for independent artists.

Artists who are great at creating — but not at promoting — can simply **send an email** with their artwork, a biography, or an event, and a short description. Agnosis takes it from there:

1. **Receives** the email (via IMAP or webhook) — images, sound, and video are all supported
2. **Enhances** the images with AI (OpenAI / Stability AI)
3. **Writes** a title, description and tags with AI (Claude / GPT-4o)
4. **Publishes** a gallery post automatically
5. **Broadcasts** to the Fediverse (Mastodon, Pixelfed) via ActivityPub

Once published, an artist can also correct their own title, text, or photos directly on the site — no need to compose another email. Pair Agnosis with the companion Lingua Forge plugin for automatic multi-language translation of every post and taxonomy term. Followers can get a digest newsletter of what's new instead of checking back manually, and an in-site Artist Guide walks new members through the whole email-driven workflow in plain language.

**Community-first admission.** New artists are vouched in by existing artists — no gatekeepers, no committees. The community grows itself.

**Zero cost to artists — for now.** Agnosis doesn't charge artists to participate. A future revenue layer of optional visitor donations and art sales (with a configurable transaction fee) is planned and partly scaffolded in Settings → Commerce, but that donation/store mechanism itself hasn't been built yet — today, running a node has no revenue feature to enable.

**Rhizome network.** Any site can run an Agnosis node. Nodes federate with each other and with the broader Fediverse. No central server. No single point of failure. Nodes can also run in a subdomain mode, giving each artist their own scoped `artistname.yoursite.com` space.

= Core Features =

* Email-to-post: IMAP + webhook support, for artwork, biographies, and events
* Image, sound, and video submissions (HEIC/PDF normalization, video poster-frame extraction)
* AI image enhancement (OpenAI gpt-image-1, Stability AI)
* AI artwork description (Claude, GPT-4o Vision)
* Front-end correction: artists can edit their own published title, text, or photos afterward, no re-submission needed
* Community vouching / admission system
* ActivityPub federation (Mastodon-compatible)
* Node identity & peer discovery
* Per-artist subdomain mode
* Digest newsletter for followers; in-site Artist Guide page
* Settings → Commerce holds a configurable transaction-fee percentage for a planned donation/store revenue layer — not yet a working checkout
* `agnosis_artwork`/`agnosis_biography`/`agnosis_event` custom post types with Gutenberg gallery blocks
* Settings: General | Email | AI Providers | Behaviour | Network | Community | Commerce | Newsletter | Logs

== Installation ==

1. Upload the `agnosis` folder to `/wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Go to **Agnosis** in the admin sidebar and configure your email inbox and AI API keys
4. Artists apply at the **`/join/`** page and are vouched in by peers

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

= 0.9.15 =
* Added: When approving an event submission, you can now edit its Date, Hour, Location, Timezone, and Address before it goes live — previously only the written description was editable at that step. A new Address field/block and Timezone field are also new: the AI now tries to pull them from your email automatically.
* Added: When approving a biography submission, you can now correct the portfolio link before it goes live — handy if it was misspelled or otherwise didn't get included automatically. This also fixes a bug where editing the biography text on that same screen could silently remove an already-working portfolio link.
* Fixed: If a link you included — a join application's portfolio link, or a link mentioned in an artwork, biography, or event email — couldn't be automatically added to your page, you previously had no way of knowing. The relevant review email now tells you which link was left out and why (for example, that its site isn't on the trusted list, or automatic review couldn't confirm it was appropriate), for all submission types, not just biography.
* Fixed: The "new application, please vote" email sent to existing artists was still plain text, unlike every other Agnosis email. It now uses the same styled look as the rest of the plugin's emails.
* Fixed: The "Biography"/"Events" links next to an artist's name (on a subdomain) could overflow or wrap awkwardly on mobile in some languages. Both are now compact icons instead of translated text, with the word still available on hover or to screen readers.
* Fixed: The Community announcement address didn't appear in the "here's every address you can use" reference card included in notification emails, unlike every other configured address. It now does.
* Changed: The Promote address's description was unclear about which gallery it affects — it's the shared, cross-artist gallery on the main site, not your own subdomain (which already shows everything you've published). Wording only; the underlying feature already worked this way.
* Fixed: The footer's newsletter bell and social icons rendered too small by default. Both are now bigger (requires agnosis-theme 0.5.8+ for the matching social-icons size).
* Added: The artist-breadcrumb's Biography/Events icons can now be customized from the block's editor panel — pick between a couple of icon styles for each, set their size, and optionally set a color independent of the block's own text color (requires agnosis-theme 0.5.9+ so the theme's own CSS stops overriding the size).

= 0.9.14 =
* Fixed: The artist name in the artist-breadcrumb line always showed a "|" before "Biography" even when there was no Events link after it, and the name couldn't be styled on the opposite side from those links. The name and the Biography/Events links are now separate, and the "|" only appears between Biography and Events when both are present.
* Fixed: The "Biography" link in that same breadcrumb line didn't follow the page's current language on multilingual (Lingua Forge) sites — it could link to a translated biography in the wrong language regardless of which language you were actually viewing. It now always links to the biography in your current language when one exists.

For the complete version history, see CHANGELOG.md in the plugin's source repository.

