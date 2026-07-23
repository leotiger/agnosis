=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.9.47
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

= 0.9.47 =
* Fixed: A deliberately embedded other-language passage — e.g. a Latin quotation inside a Catalan poem — could get translated right along with its surrounding text, and from there spread (already wrong) to every other configured language. A more precise instruction now tells the AI to leave it untouched. Lingua Forge's own translation to your other configured languages also now uses a stronger AI model for this specific case.
* Fixed: A text-only submission's poster could end up completely broken after being resent to a different address with unchanged content — the resend's dedupe logic could reuse the existing poster's id while a related cleanup step simultaneously deleted it. Poster ids kept for the new gallery are no longer eligible for that cleanup.
* Fixed: Published images never had alt text in the actual page markup, even when a real description was already stored for them — an accessibility gap affecting every artwork/biography/event image. Now included, falling back to the artwork's own title when nothing more specific is available.
* Fixed: A native-language artist's own medium category could show up in the wrong language on their native-language sibling post (e.g. the English word "Poetry" instead of its own language's equivalent). Now reuses the same translate-once-and-cache mechanism every other configured language already gets, instead of copying the primary-language word directly.
* Fixed: Translating a submission into your site's primary language could pick the wrong one of two languages to preserve versus translate when a submission deliberately mixed two languages (e.g. a quotation in a third language) — occasionally translating the part that should stay untouched while leaving the part that needed translating unchanged. The AI is now told explicitly which language the submission itself is written in, not just which language to translate it into.
* Changed: Translating a submission into your site's primary language now uses its own, separate AI model setting (Settings → AI Providers), independent from the cheap/fast model used for medium classification and contact-message moderation.

= 0.9.46 =
* Fixed: A text-only submission's (poetry, essays) auto-generated placeholder image used to pile up a new copy in the gallery every time you corrected and resent it, instead of replacing the outdated one — and the old, uncorrected version could still show as the featured image. Now only the latest one is kept.
* Fixed: Correcting a typo on a text-only post through the on-site editor didn't update its placeholder image at all before — the corrected text and the image could disagree indefinitely. The image is now regenerated to match.

For the complete version history, see CHANGELOG.md in the plugin's source repository.

== Upgrade Notice ==

= 0.9.47 =
Fixes embedded-quotation mistranslation, a poster-breaking bug on resend, missing image alt text, and a native-language artist's medium category showing in the wrong language. Adds a separate AI model setting for translation.

