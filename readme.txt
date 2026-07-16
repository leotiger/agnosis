=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.9.30
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
* Settings: General | Branding | Email | AI Providers | Behavior | Network | Community | Commerce | Newsletter | Logs

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

= 0.9.30 =
* Changed: Minimum required PHP raised from 8.1 to 8.2. A dependency update had already moved the plugin's actual PHP requirement to 8.2 without the plugin's own "Requires PHP" header being updated to match; this release corrects the header to reflect reality rather than rolling the dependency back.
* Added: A new Branding tab (Settings → Branding, right after General) now holds the email logo, header background, and accent color settings previously on General — plus ten new controls: email width, page/card/footer background color, primary and secondary text color and size, divider/border color, button text color, notice-box background color, and footer label color, so the whole look of your outgoing email — every box, divider, and footer element, not just the header and accent — can be made to match your site.
* Added: A "Send test email" button on Settings → Branding sends yourself a sample email built from your saved settings — header, body, a button, and the footer card — so you can see how your colors actually look without waiting for a real email to go out.
* Fixed: The header background and accent color settings only actually applied to 4 of the 10 places Agnosis sends outgoing HTML email. The other 6 — submission review, removal/rejection notices, both newsletter templates, the invitation email, and the artist vote digest — carried their own hardcoded copies of the original colors and silently ignored your settings, and even the 4 that worked showed the old accent color in the footer's contact-address links. All 10 now read the same configured values everywhere in the email, and the new Branding controls above apply across every one of them too.
* Fixed: If you set a light "Header background" color, the header text (your site name) rendered white-on-white and disappeared — including on the very first email a new applicant needs to read and act on. The header text now automatically switches to dark when your chosen background is light, so it's always readable no matter what color you pick. The same fix now also covers the small subtitle line under the header on newsletter and invitation emails.

= 0.9.29 =
* Fixed: Several automated emails (departure, ban/reinstatement, and community-vote notices; the community broadcast's "too long"/"empty" bounce notices; the admin new-application summary) were still plain, unstyled text while every other Agnosis email was already nicely formatted. They're now all styled to match.
* Added: New Settings → General fields let you customize the header background color and the accent color (buttons and links) used across every outgoing HTML email, so your emails can match your site's own branding. Destructive actions (like a removal vote) always keep a fixed red regardless of this setting, so they stay easy to spot.
* Added: New docs/guides/ folder in the plugin's source repository with a plain-text reference for the Artist Guide, About page, medium categories, join page, privacy policy, and email templates — useful if you want to review or adapt this copy without digging through the code.

For the complete version history, see CHANGELOG.md in the plugin's source repository.

