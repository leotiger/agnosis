=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.9.67
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

= 0.9.67 =
* Fixed: The "Follow" button on an artwork could show a Fediverse handle that doesn't work — on artworks published by an admin, or by someone who is no longer an artist on your site. The handle looked correct and copied cleanly, but wouldn't resolve in anyone's Fediverse app. The button now doesn't appear at all in those cases.
* Fixed: Clicking a review link for a submission that no longer exists — already published, discarded, or replaced by a newer version — silently dropped the artist on the home page with no explanation. It now says what happened and that nothing went wrong on their side. Same for a link that arrives incomplete because a mail app cut it in half.
* Fixed: The email asking you to review the AI-drafted newsletter intro was plain, unstyled text, unlike every other message Agnosis sends. It now uses the same branded layout, with the draft shown as a quotation and a button to review it.
* Fixed: The deliverability test email (Settings → Email Inbox) is now sent in the same branded HTML as real messages, so what arrives in your inbox is what your artists and subscribers actually receive.
* Fixed: Four admin controls had no accessible name for screen-reader and voice-control users — the status and skip-reason filters on Submissions, the status filter on Contact Messages, and read-only settings fields, whose label pointed at nothing.
* Changed: Agnosis now requires WordPress 6.9 or newer, up from 6.6. Nothing in the plugin behaved differently on older versions; this only stops it claiming support for versions it isn't developed or tested against.
* Changed: The Fediverse code has been reorganized internally — the single file behind ActivityPub, which had grown to over 6,000 lines, is now nine focused ones. Nothing behaves differently and the full test suite passes unchanged; it makes future federation work safer to build and easier to review.
* Changed: Removed a piece of dead code left over from the 0.9.54 change that let translated versions of an artwork federate on their own.

= 0.9.66 =
* Changed: The automatic repair for missing scheduled tasks now covers all 18 of them instead of 13 — including the email inbox poll, which is how artwork submissions arrive. No change in normal operation; it means fewer ways for a scheduled task to go missing unnoticed.
* Added: Agnosis now warns in the admin when Lingua Forge is older than the version it was written against, instead of the affected feature just silently not appearing. Agnosis keeps working either way.
* Added: Agnosis posts are now explicitly excluded from Lingua Forge 2.7.0's own comment translation, so replies can never be mirrored twice by both plugins.
* Fixed: The reply form on an artwork had no field labels, only placeholder text, making it hard to use with a screen reader or voice control. Same for the Partner Nodes trust-scope dropdown, which didn't say which peer it belonged to.
* Fixed: The "Follow" popover's error message ("enter your instance domain") was never announced to screen readers.
* Fixed: Hindi-speaking artists received a contact-reply email showing the characters "%s" instead of the sender's name.
* Fixed: The Arabic translation of the open-community-votes line left out the number for counts between 11 and 99.
* Fixed: The artist newsletter read "1 likes on your work" and "From 1 trusted partner nodes" instead of the singular. The English plural forms were wrong in the translation catalog.
* Security: The Partner Nodes panel's "Approve" and "Check" buttons could be used to make your own server contact addresses inside your private network, because anyone can register themselves as a pending peer with any address they like. Those requests now refuse private and loopback addresses, and an unsafe address is rejected at registration so it never appears in the panel at all.
* Fixed: A visitor asking you to erase their data also wiped the artist's own replies to them, and a visitor's data export included those replies as though the visitor had written them. Erasure now keeps the artist's words while removing the visitor's details from them, and reports the result honestly as a partial erasure.
* Fixed: The reply form asked for an email address without showing any privacy notice, unlike the join and contact forms. It now explains that a reply is reviewed by the artist, then published, translated and shared across the Fediverse.
* Added: Data exports and erasure requests now also cover the automatic translations made of a visitor's own reply, which WordPress's built-in comment tools cannot see.
* Added: Records of what partner nodes relayed through your site are now deleted after 90 days, adjustable under Settings → Network.
* Changed: The suggested privacy-policy text (Settings → Privacy → Policy Guide) now covers on-site replies, likes, and the partner-node relay log, and correctly lists the reply form among the forms that use the Cloudflare Turnstile check.

For the complete version history, see CHANGELOG.md in the plugin's source repository.

== Upgrade Notice ==

= 0.9.66 =
Security fix for the new Partner Nodes panel: approving or checking a peer could reach addresses inside your own network. Update if you use Settings → Rhizome.

= 0.9.57 =
Security fix: an email scanner's link prefetch could silently approve or discard a Fediverse reply before you saw it. No action needed.

