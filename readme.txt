=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.18
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

= 0.9.18 =
* Added: The Inbox admin table now shows which email address a submission was sent to (artwork, photo, pure, biography, event, replace, remove, promote, goodbye, or community) — previously you could only guess from the subject line or the resulting post type.
* Fixed: The 8 default artwork medium categories (Oil Painting, Watercolour, Photography, etc.) are now created automatically whenever the plugin updates to a new version, not just on first activation — a site that was already running before this feature existed never got them.
* Fixed: A biography submitted with a photo could fail to process entirely if that artist's biography page was originally auto-created from their admission application rather than from an email — a missing internal field crashed the pipeline. Biography posts also now correctly hold at most one photo: a new one replaces the old rather than accumulating alongside it.
* Fixed: Sending an update to an already-published biography (or replacing/re-sending an already-published artwork or event) generated an "approve this" email whose link could never actually be clicked through — it always showed "Link expired or already used," even seconds after arriving. Updating already-published content now stages your changes separately with their own working approve link, while the currently-published page keeps showing exactly as it is, unaffected, the whole time you're deciding whether to approve — no downtime either way.

= 0.9.17 =
* Added: Artists can now mute community broadcast messages, or switch application-vote emails to a once-daily digest instead of one email per application — a link to manage these is included in vote, broadcast, and welcome emails. Previously the only way to reduce mail volume was to leave the network entirely.
* Added: A `replace@` or event-update email whose subject doesn't exactly match one of your existing titles still publishes as a new post (as before), but now flags in the review email if it looks like you meant to update something else — so a typo in the subject line no longer creates a duplicate that goes unnoticed.
* Added: Applying again shortly after withdrawing, being declined, or leaving now has a one-per-week limit per email address, and the join form's bio/statement/name fields have sensible length limits — previously uncapped.
* Added: A new "Deliverability" card on Settings → Email Inbox checks whether your sending addresses are likely to actually reach inboxes (domain match, SPF/DMARC records, SMTP plugin detection) and lets you send a real test email — diagnostic only, nothing is changed automatically.
* Fixed: If your mailbox is ever rebuilt or migrated to a new provider, incoming mail is no longer silently missed. The plugin now detects this and automatically catches up instead of requiring a manual fix.
* Added: The Inbox admin table now has status/reason filters and pagination, and messages from unregistered senders are collapsed into a single summary line instead of flooding the list — a busy weekend of spam no longer buries the real events.
* Fixed: Nine files used a PHP 8.2-only type declaration, which would fatal-error on a genuine PHP 8.1 site — the exact minimum version this plugin has always claimed to support. Rate limiting, webhook checks, front-end editing, review/removal endpoints, ActivityPub signatures, newsletters, and invitations are all affected files; no behavior changed, only the type declarations.
* Added: Settings → General now shows a warning if Cloudflare Turnstile is not configured, since the Join application form is always public and otherwise relies only on a per-IP rate limit that a distributed bot can work around. Nothing is configured automatically — this only makes the current state visible.
* Fixed: On hosts where a misconfigured reverse proxy or CDN setup makes every visitor look like the same IP address, an unauthenticated flood of garbage webhook requests could previously slow down real, correctly-signed mail from your email provider. Verified and unverified webhook requests are now rate-limited separately, so this can no longer happen. Also, webhook email attachments now have their real file type checked the same way inbox (IMAP) attachments already are, instead of trusting the sender's own label.
* Added: The event review form's Timezone field is now a proper dropdown of real timezones instead of free text — previously an unrecognized entry was silently discarded with no indication anything went wrong. A community broadcast email with no readable subject or body now gets bounced back to the sender with an explanation, instead of just vanishing. The newsletter dashboard also gained a "Retry Failed" button so recipients that permanently failed to send (e.g. during a mail outage) can be resent without a database edit.
* Fixed: The internal AI prompt used to pull structured event details (venue, date, timezone) out of an artist's email now clearly marks that email's content as data, not instructions — a consistency fix bringing it in line with how the plugin already treats fetched web page content elsewhere. No visible behavior change.
* Fixed: Internal code-quality cleanup — recipient-address parsing and shared reason text for the goodbye@/community@ email addresses were previously duplicated between the two mail intake methods (IMAP and webhook), risking drift between them over time. This logic now lives in one place. No visible behavior change.
* Fixed: A documentation/code mismatch meant a fresh install with no saved Inbox settings could clean up old messages on a 30-day schedule instead of the documented 7-day one — now aligned. Also some minor internal documentation and code cleanup in the rate-limiting logic, with no visible behavior change.

For the complete version history, see CHANGELOG.md in the plugin's source repository.

