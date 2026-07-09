=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.13
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

= 0.9.13 =
* Added: A free, no-AI safety net that double-checks translated artwork/biography/event pages are using the correct language-specific page template, on sites running Lingua Forge 2.6.1 or later. Runs automatically after every translation; does nothing on older Lingua Forge versions.

= 0.9.12 =
* Fixed: Emailing `remove@` or `promote@` with a title that didn't exactly match one of your artworks or events used to do nothing at all, with no explanation — you'd think it worked and never find out it hadn't. You'll now get an email explaining the title wasn't found, listing your current titles, and — when we can make a good guess — suggesting the one you probably meant (with a one-click confirmation link, for removals). Promoting an artwork to featured now also sends a confirmation email when it works, which it never did before.
* Fixed: The `[Biography]`, `[Event]`, `[Photo]`, and `[Pure]` subject-line shortcuts only worked in English. They now also recognize their equivalents in every language this plugin ships translations for.
* Fixed: Emailing `remove@`, `promote@`, or any other address-based shortcut while also CCing someone else could cause the wrong thing to happen, depending on which address your mail app listed first. Every address you sent to or CC'd is now checked.
* Fixed: Clicking an expired or already-used "discard" or "remove" link from an email used to show a normal-looking confirmation page before failing on the next click. It now tells you right away that the link has expired.
* Fixed: A hardening fix for self-hosted nodes using the webhook email intake option — a captured, validly-signed submission could previously be resubmitted over and over, each time repeating the AI processing. Signed submissions now expire after a few minutes and can't be resubmitted once processed.
* Fixed: On nodes using the webhook email intake option, a self-removal ("goodbye") email could be actioned for an account that wasn't actually an admitted artist, and self-removal/community-announcement emails sent this way didn't show up in the Inbox admin table the way they already did for the other intake option (IMAP) — both are now consistent.
* Fixed: A tag or medium name that was purely a number (like a year, e.g. "2026") could, in rare cases with Lingua Forge active, get attached to the wrong term on a translated page instead of the correctly-named one. This now works correctly.
* Changed: Community announcements now translate each language only once instead of once per member — on a large, multi-language community this noticeably lowers the AI cost of sending one. No change to what any member receives.
* Changed: The review email an artist gets when their submission needs approval is now translated more efficiently, and the confirmation page they click through to reuses that same translation instead of doing its own — lowering the AI cost of your most common artist-facing email without changing what it says.
* Changed: Translating an artwork's title into every language your site supports is now done in a single AI request instead of one request per language, lowering the AI cost of every artwork you publish on a multilingual site.
* Changed: Submitting several images in one artwork email is now noticeably cheaper to process — only the image actually used for the post's title and description gets the full AI analysis; the other images in the gallery get a lighter pass that still generates their accessibility text, tags, and photo-quality check, just without the parts that were never published anyway.

For the complete version history, see CHANGELOG.md in the plugin's source repository.
