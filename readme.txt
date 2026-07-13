=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.25
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

Once published, an artist can also correct their own title, text, or photos directly on the site — no need to compose another email. Agnosis requires the companion Lingua Forge plugin (installed and active before Agnosis can be activated) for automatic multi-language translation of every post and taxonomy term. Followers can get a digest newsletter of what's new instead of checking back manually, and an in-site Artist Guide walks new members through the whole email-driven workflow in plain language.

**Community-first admission.** New artists are vouched in by existing artists — no gatekeepers, no committees. The community grows itself.

**Zero cost to artists — for now.** Agnosis doesn't charge artists to participate. A future revenue layer of optional visitor donations and art sales (with a configurable transaction fee) is planned and partly scaffolded in Settings → Commerce, but that donation/store mechanism itself hasn't been built yet — today, running a node has no revenue feature to enable.

**Rhizome network.** Any site can run an Agnosis node. Nodes federate with each other and with the broader Fediverse. No central server. No single point of failure. Nodes can also run in a subdomain mode, giving each artist their own scoped `artistname.yoursite.com` space.

= Core Features =

* Email-to-post: IMAP + webhook support, for artwork, biographies, and events
* Image, sound, and video submissions (HEIC/PDF normalization, video poster-frame extraction)
* AI image enhancement (OpenAI gpt-image-1, Stability AI)
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

= 0.9.25 =
* Added: A remove@/promote@ management email whose subject matches more than one post (e.g. an artwork and an event with the same title) now gets a confirmation email listing every match, each with its own individual removal link — instead of only acting on one of them.
* Added: The IMAP inbox now deletes mail that was never actually addressed to one of this site's own configured endpoints (e.g. BCC'd mail) instead of letting it reach the queue — Agnosis does not accept BCC submissions.
* Added: Cc: is no longer accepted for routing at all, and only the first/primary To: address counts — a message reaching an endpoint only via Cc: or a secondary To: address no longer matches.
* Added: A message that looks like a reply or forwarded/quoted email (e.g. subject starting with "Re:", or a quoted "wrote:" attribution in the body) is now detected and rejected on both transports, with the sender told to resend as a fresh, original message.
* Fixed: The "Inbox retention (days)" cleanup only deleted mail already marked read on the mail server — a lot of mail this plugin fully handles (spam/unregistered senders, throttled resends, goodbye@ requests) never gets marked read at all, so it was piling up in the mailbox regardless of the configured retention. Cleanup now deletes by age alone, read or unread.
* Fixed: A remove@ or promote@ request with no attachment and no body text — just a subject line — was being rejected as unusable, even though neither command has ever needed an attachment or body to work.
* Fixed: Any skipped inbox message, on either the IMAP or webhook transport, showed a misleading "Artwork" label in the Inbox admin table's Endpoint column instead of what it actually was — a remove@/promote@ request, a goodbye@/community@ alias, or, when nothing could be identified at all, an honest "Unknown" rather than a guess.

= 0.9.24 =
* Changed: An event's own title (e.g. an exhibition name) is now kept exactly as the artist wrote it on every language version of the page, with an AI-translated subtitle shown underneath — the same "original + translated subtitle" treatment artwork titles have always had. Previously an event's title was machine-translated outright per language, which could read as an inconsistent or awkward literal translation of what's often effectively a proper noun. Artists can also now correct an event's title after publishing, the same way they already could for artwork.
* Fixed: An artist's Events page could show every language's events mixed together on a single archive view instead of just the current language's — a second, unrelated query filter (sorting events by date) was overwriting Lingua Forge's own language scoping instead of combining with it.
* Fixed: A featured image on an artwork, biography, or event page appeared uncropped (showing the whole photo at its natural shape) instead of the intended fixed crop, but only for the artist who could edit that page — everyone else always saw it correctly. The front-end correction overlay's wrapper around the photo was breaking the sizing the crop depends on.

For the complete version history, see CHANGELOG.md in the plugin's source repository.

