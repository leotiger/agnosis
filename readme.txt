=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Art blooming out of oblivion. Email your art, AI polishes it, the world sees it.

== Description ==

**Agnosis** is a free, federated publishing network for independent artists.

Artists who are great at creating — but not at promoting — can simply **send an email** with their artwork and a short description. Agnosis takes it from there:

1. **Receives** the email (via IMAP or webhook)
2. **Enhances** the images with AI (OpenAI / Stability AI)
3. **Writes** a title, description and tags with AI (Claude / GPT-4o)
4. **Publishes** a gallery post automatically
5. **Broadcasts** to the Fediverse (Mastodon, Pixelfed) via ActivityPub

**Community-first admission.** New artists are vouched in by existing artists — no gatekeepers, no committees. The community grows itself.

**Zero cost to artists.** Agnosis is free. Revenue comes only from optional visitor donations and art sales (a small transaction fee). Artists never pay to participate.

**Rhizome network.** Any site can run an Agnosis node. Nodes federate with each other and with the broader Fediverse. No central server. No single point of failure.

= Core Features =

* Email-to-post: IMAP + webhook support
* AI image enhancement (OpenAI gpt-image-1, Stability AI)
* AI artwork description (Claude, GPT-4o Vision)
* Community vouching / admission system
* ActivityPub federation (Mastodon-compatible)
* Node identity & peer discovery
* Donation + art store with configurable transaction fee
* `agnosis_artwork` custom post type with Gutenberg gallery blocks
* Settings: General | Email | AI Providers | Network | Commerce

== Installation ==

1. Upload the `agnosis` folder to `/wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Go to **Agnosis** in the admin sidebar and configure your email inbox and AI API keys
4. Artists apply at `/wp-json/agnosis/v1/admission/apply` and are vouched in by peers

== Frequently Asked Questions ==

= Is it really free for artists? =
Yes. Always. Revenue comes from a small fee on donations and art sales — only when money changes hands.

= Do I need to run my own server? =
No. Node hosts in the network can offer space to artists. But if you want your own node, install this plugin on any WordPress site.

= Which AI providers do I need? =
At minimum one API key. OpenAI alone covers both description and enhancement. Claude gives richer artistic descriptions if you have both.

= Does this work with Mastodon? =
Yes. Once ActivityPub is enabled, your node is a Fediverse actor. Mastodon users can follow `@agnosis@yoursite.com` and see new artworks in their feed.

== Changelog ==

= 0.9.8 =
* Added: A newly admitted artist now starts with a first Biography draft, built automatically from the bio, artist statement, and portfolio link they already gave on their application — instead of that information sitting unused. The portfolio link, when approved for embedding (see below), is added as an embed at the end. Like every other Agnosis post, this draft is sent to the artist for review — they approve, edit, or discard it, nothing is published without them.
* Added: The trusted platforms list for embedding artist-submitted links (YouTube, Vimeo, SoundCloud, Bandcamp, etc.) is now editable under Settings → Behaviour, instead of being fixed in code. Site admins can also turn on AI-powered review for links to other, non-listed sites: Agnosis looks at the page and checks it against categories you choose to disallow — pornographic content (on by default), commercial/promotional sites, gambling sites, or your own custom description. If a link can't be safely checked, it's left out rather than embedded. This is off by default, so existing sites see no change unless it's turned on.
* Added: A "Trust all admitted artists" setting under Settings → Behaviour, for admins who consider the community vouching process itself sufficient — turning it on embeds any artist-submitted link immediately, skipping both the trusted platforms list and AI review entirely. Off by default.
* Added: A "Reset to default" button on the System prompt, Artist prompt template, Enhancement instructions, Trusted embed platforms, and Invitation intro fields under Settings, so overwriting one of these no longer means losing the plugin's original text for good.
* Added: The AI is now explicitly told to ignore email footers (e.g. "Sent from my iPhone"), signatures, and other text unrelated to the submission when writing artwork descriptions, polishing biography/event text, or merging biography updates.
* Added: The Artist/Public newsletter intro fields no longer have to be written by hand — a configurable number of hours (default 24) before an issue is due, Agnosis drafts one from what's new (artwork, events, tags, mediums, new members, open votes) and saves it to the field, then emails you to review, edit, or clear it before it sends. Two new settings under Settings → Newsletter control it: an on/off switch ("Auto-draft newsletter intros", on by default) and the lead time in hours.

= 0.9.7 =
* Fixed: The "goodbye" self-removal email workflow had three bugs on the same admin Inbox row: the sender's address never showed up, a successful request was shown with a red "Failed" badge, and — in one case — the row claimed a confirmation email had been sent when it actually hadn't (the artist had no active membership at the time). All three are fixed: the sender's address now displays correctly, successful requests show a neutral "Skipped" badge instead of "Failed", and the no-membership case is now labeled accurately instead of falsely claiming success.

For the complete version history, see CHANGELOG.md in the plugin's source repository.
