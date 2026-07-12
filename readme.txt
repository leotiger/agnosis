=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.21
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

= 0.9.21 =
* Added: A visitor can no longer message the same artist more than a configurable number of times per hour — previously the contact form only limited by IP address and, separately, by sender email address across every artist, so nothing stopped repeated messages to one specific artist. Configurable at Settings → Email ("Per-artist contact limit" and its time window). Applies the same regardless of which language version of the artist's page is used.
* Added: A new Settings → General "Preset biography title" field lets you force every artist's biography page to use the same fixed title instead of their own, with an optional checkbox to append the artist's name to it (e.g. "Meet the Artist — Jane Doe"). Leave it blank to keep using each artist's own title, exactly as before. Applies to every Lingua Forge translated version of a biography page too.
* Fixed: The contact form no longer just hides itself in the browser after a message is sent, since that could be undone to send more. Submitting now reloads the page; the form is then replaced with a "message sent" notice until the per-artist limit above allows another message.
* Fixed: Resending a biography, artwork, or event with a new photo now actually updates the published post's featured image (previously it silently kept the old one), and the new photo now also reaches every Lingua Forge translated version of that page instead of just the primary language.
* Fixed: A biography's three optional social links, and corrections to its portfolio link, now reach every Lingua Forge translated version of the page — previously they only ever showed up on the primary language.
* Fixed: A biography's portfolio link no longer appears twice — once as a social icon below the photo, once again as a duplicate embedded preview inside the text. It now shows only as the icon.
* Fixed: An artist could sometimes receive a second "please approve this" email for a submission they'd already approved and published, or already discarded — triggered by an admin's "heal the queue" action, or automatically after a mailbox migration, with no action needed from the artist. This is now fixed at the source.

= 0.9.18 =
* Added: The Inbox admin table now shows which email address a submission was sent to (artwork, photo, pure, biography, event, replace, remove, promote, goodbye, or community) — previously you could only guess from the subject line or the resulting post type.
* Fixed: The 8 default artwork medium categories (Oil Painting, Watercolour, Photography, etc.) are now created automatically whenever the plugin updates to a new version, not just on first activation — a site that was already running before this feature existed never got them.
* Fixed: A biography submitted with a photo could fail to process entirely if that artist's biography page was originally auto-created from their admission application rather than from an email — a missing internal field crashed the pipeline. Biography posts also now correctly hold at most one photo: a new one replaces the old rather than accumulating alongside it.
* Fixed: Sending an update to an already-published biography (or replacing/re-sending an already-published artwork or event) generated an "approve this" email whose link could never actually be clicked through — it always showed "Link expired or already used," even seconds after arriving. Updating already-published content now stages your changes separately with their own working approve link, while the currently-published page keeps showing exactly as it is, unaffected, the whole time you're deciding whether to approve — no downtime either way.

For the complete version history, see CHANGELOG.md in the plugin's source repository.

