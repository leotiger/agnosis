=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.28
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
* Settings: General | Email | AI Providers | Behavior | Network | Community | Commerce | Newsletter | Logs

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

= 0.9.28 =
* Added: Removing or editing an artwork now carries across the Fediverse. When a piece is taken down — by the artist, by a community removal vote, or as part of a data-erasure request — followers' servers are told to drop their copy, and anyone re-checking the old address gets a proper "gone" answer. Corrections to a published piece (new title, text, or photo) now reach followers too, instead of leaving stale copies out there forever.
* Security: A crafted Fediverse request could pretend to be another person's account — making us deliver artworks to an inbox that never followed us, or silently un-follow a real follower. The identity that signs a request must now match the identity the request claims to act as; anything else is rejected.
* Security: Registering a network node with our site accepted the request without checking anything, so it could be spammed with fake registrations. Registration is now rate-limited, capped so it can't grow without bound, and must be cryptographically signed by whoever is registering.
* Fixed: Fediverse apps that look up an artwork by its web address (for example when someone boosts it, or pastes the link into Mastodon's search) now receive the artwork's federation data instead of a regular web page — and on sites without pretty permalinks, those addresses no longer point at a broken page.
* Fixed: Following the site from Mastodon (and most other Fediverse apps) never actually worked — our replies and new-artwork posts were missing a security header Mastodon requires, so it silently rejected everything we sent while the follow sat "pending" forever on the follower's side. Deliveries now carry and sign that header, and any delivery a remote server rejects is now recorded in Settings → Logs instead of failing invisibly.
* Fixed: Deleting the plugin didn't remove visitor contact-form messages (names, email addresses, and, briefly, IP addresses) — that table is now included in the cleanup along with everything else the plugin removes.
* Fixed: Fediverse tools that browse through your full list of published artworks (for example, when Mastodon first loads your profile) couldn't page through past the first batch. Browsing your full artwork history from the Fediverse now works as expected.
* Added: Artworks now federate with much richer detail — the AI-written alt text, real hashtags from your tags and medium (so Mastodon users can actually discover your work), and the full description instead of a 50-word snippet. You (or, per medium, the site operator) can also now flag a piece as sensitive content, which adds a Fediverse content warning.
* Fixed: Deleting the plugin left behind some leftover bookkeeping data tied to individual accounts (bounce tracking, notification opt-outs) — that's now cleaned up along with everything else the plugin removes. Your account itself is never touched.
* Fixed: A Fediverse delivery that failed (for example, a Mastodon instance that was briefly offline) was gone for good after one try, with only a log entry to show for it. Failed deliveries are now retried automatically over the next few days before being given up on. Follower storage was also hardened against growing unbounded on very large or very active nodes.
* Added: Every artist now has their own identity on the Fediverse. Instead of Mastodon (and similar apps) only ever seeing "the site" post, people can follow an individual artist directly by searching `@theirname@yoursite.com` — and get just that artist's new work in their feed. Following the site as a whole still works exactly as before. Also added the standard "site info" endpoint (NodeInfo) that federation directories and health-check tools look for.
* Changed: Corrected two more leftover British spellings ("Watercolour" → "Watercolor" in the medium list, "Behaviour" → "Behavior" in the Settings tab list) and reworded the Commerce feature description so it no longer reads as already available — it's a planned addition, not yet built.

= 0.9.27 =
* Added: If an email to us looks like a reply or forward, we now check for your own original text (or an attachment) above the quoted part and use that instead of rejecting the message outright — replying is still not the recommended way to reach us, but it now works when it carries real content. You'll see a small note on your review email when this happens.
* Fixed: The admin "Open Vote" button on Settings → Community → Members always silently failed — it queried a database column that never existed, so it could never resolve the target artist and never actually opened a community removal vote.
* Fixed: A banned artist could reapply through the public join form and silently overwrite their own ban record back to a fresh, unconfirmed application — the reapplication check never accounted for a banned status at all. A ban now blocks reapplication (a temporary ban that has already expired is allowed to reapply, same as any other resolved application).
* Changed: Corrected the developer-facing README's inaccurate "zero runtime dependencies" claim — the plugin ZIP has always shipped the IMAP library and its dependency tree, now documented accurately along with their (all GPL-compatible) licenses.
* Fixed: Forwarded emails weren't reliably caught by the "no replies or forwards" intake rule — a Gmail forward with no added comment could slip through and publish someone else's quoted content. Forwarded messages from Gmail and Apple Mail/iOS Mail are now detected the same way replies already were, and "Fwd:"/"Fw:" subjects are rejected the same as "Re:".
* Changed: The in-site Artist Guide now explains the email rules directly — that Cc/Bcc addresses are never read, that only the first address you send to counts, and how a reply or forward can still get through if your own text sits above the quoted part. Also corrected a leftover British spelling ("Colours" → "Colors") on the guide and About page.
* Changed: Removed mentions of "Stability AI" as an image-enhancement or AI provider option — it's never actually been available. Our privacy policy text and documentation now correctly list OpenAI and Anthropic, plus WordPress's own built-in AI Client where applicable (WordPress 7.0+, configured under Settings → Connectors).
* Fixed: A long biography could fail to translate — silently publishing untranslated text — because the AI response was cut off before it finished. Raised the response size limit so this shouldn't happen anymore, and it's now clearly logged when it does.
* Fixed: Our automated emails (submission rejections, review-ready notices, and similar) didn't identify themselves as automated to the recipient's mail server. If your vacation auto-reply happened to fire back at us, it could bounce between our system and yours. These emails now carry standard headers so mail servers know not to auto-reply to them.
* Added: New Settings → AI Providers fields to choose which OpenAI/Anthropic model handles translation and message moderation (separate from the model used for artwork descriptions) — previously fixed and not adjustable.

For the complete version history, see CHANGELOG.md in the plugin's source repository.

