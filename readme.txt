=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.6
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

= 0.9.6 =
* Changed: Added a new "Community" tab in Settings and moved everything about admitting and managing artists there — the vote thresholds, community size cap, removal-vote settings, the Pending Applications list, the Members list, and the Invite an Artist card. These used to be split between the Network and Newsletter tabs, which never really fit. Nothing about how any of it works has changed, just where you find it.
* Changed: The Community tab is now split into two sub-tabs — "Members" (Pending Applications, the Members list, Invite an Artist) and "Rules" (the vote thresholds and other settings). A large community would otherwise turn this into an endless scroll before ever reaching the settings.

= 0.9.5 =
* Added: A new "Pure" work-submission address (Settings → Email). Artwork sent there is published exactly as received — no AI touches the text or the image at all (not even the description/title/tag pass Photo-only still runs). What the artist wrote and attached is what gets published, word for word, pixel for pixel.
* Added: Every artwork post now remembers which address it was submitted to (Artwork, Photo-only, or Pure). Sending a replacement version via the Replace address now reuses that same original approach automatically, instead of always running full AI on the replacement.
* Fixed: The welcome email sent to newly admitted artists never actually mentioned the Photo-only address, and would have had the same gap for the new Pure address — both are now listed alongside the other work-submission addresses in that email, correctly translated into the artist's language like the rest of that email. (Note: if you use Loco Translate, you'll need to re-scan once to pick up these two new lines.)

For the complete version history, see CHANGELOG.md in the plugin's source repository.
