=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.9
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

= 0.9.9 =
* Added: The "After applying, send artists to" setting under Settings → Community is now a page picker — choose a page from a dropdown of your site's own pages instead of hand-typing its URL. A previously typed-in URL keeps working until you next save that setting.
* Fixed: One more email missed by the "WordPress" sender fix below — the admin notice reviewing an AI-drafted newsletter intro before it sends. Now uses the Newsletter sender address like the rest of the newsletter's mail.
* Fixed: Clicking the self-removal confirmation link in the "goodbye" email could send your browser into an endless redirect loop ("Too many redirects"), even though the removal itself had already gone through. You'll now see a plain confirmation page instead.
* Fixed: An artist who confirmed self-removal was never actually told their account and content were gone for good — only the site admin was notified. They now get their own confirmation email, in their own language, spelling out that nothing of theirs is stored on the site anymore.
* Fixed: The Inbox admin table showed the same gray "Skipped" badge for a self-removal request as for a routine ignored email, which read as if nothing had happened. Removal, broadcast, and bounce requests now get their own clearly labeled badge.
* Fixed: Adding, renaming, or removing a Medium term under Artwork → Mediums had no real effect — the AI could only ever pick from a fixed built-in list of 8, and anything else was silently rejected even if you'd added it yourself. The AI now picks from whatever Medium terms actually exist, so custom ones you add are usable right away.
* Added: Tags and the artwork Medium are now translated onto every translated post. Lingua Forge never copied taxonomy terms onto a translation at all, so a translated artwork, biography, or event previously published with no tags, and a translated artwork with no Medium either — both now translated automatically (AI) and assigned, matching the original.
* Added: A new "Community announcement" email address under Settings → Email — e.g. community@agnosis.art. Any active artist can email it to send a message to every other community member, translated automatically into each recipient's own language. It's never published as a post. The sender's name and email are shown in the message, but replying goes back through the community address itself rather than straight to the sender — so the reply gets translated too, instead of arriving in a language the original sender may not read. A daily limit per artist (default 3) keeps it from being used to flood everyone's inbox, and a length limit (default 2000 characters, adjustable under Settings → Community → Rules) bounces an overly long message back to its sender instead of sending it out — every recipient's copy has to be translated individually, so a very long message is costly to send to the whole community.
* Added: A dedicated sender email address for community emails — application updates, vote links, welcome messages, departures, and submission reviews — under Settings → Community → Rules ("Sender name" / "Sender email address"). Separate from the Newsletter sender, since these are one-off actions, not a digest.
* Fixed: Several emails (community vote links, admission summaries, departure/removal notices) had no configured sender at all and could arrive looking like they came from "WordPress" at an address that doesn't exist. A couple of others (the admission welcome email, invitations) were sent from the Newsletter address by mistake. All of these now use the new Community sender address above.
* Added: The "Publish this artwork?" confirmation page an artist reaches from their review email now lets them make final text edits — title, short description, and full text — right there, instead of always publishing exactly what the AI drafted or having to visit the full submissions page for changes. Fields are shown in the artist's own language and translated back automatically before publishing. A blank title or body cancels the whole action rather than publishing empty content, and the same email link still works if that happens.
* Fixed: Approving, discarding, or editing-then-publishing a Biography or Event submission from its review email could incorrectly show "Link expired or already used", even on a perfectly valid link. Artwork was unaffected. This is now fixed for all three content types.
* Added: How long the Approve & Publish / Discard links in a review email stay valid is now a setting under Settings → Behaviour ("Review link expiry (days)", default 7), instead of being fixed in code.
* Fixed: The biography draft automatically created for a newly admitted artist (see 0.9.8 below) no longer includes their "Why do you want to join?" answer from the application form — that's addressed to the community reviewing admission, not part of an artist's public biography, and won't appear there anymore. It's still visible to admission reviewers as before.

= 0.9.8 =
* Added: A newly admitted artist now starts with a first Biography draft, built automatically from the bio, artist statement, and portfolio link they already gave on their application — instead of that information sitting unused. The portfolio link, when approved for embedding (see below), is added as an embed at the end. Like every other Agnosis post, this draft is sent to the artist for review — they approve, edit, or discard it, nothing is published without them.
* Added: The trusted platforms list for embedding artist-submitted links (YouTube, Vimeo, SoundCloud, Bandcamp, etc.) is now editable under Settings → Behaviour, instead of being fixed in code. Site admins can also turn on AI-powered review for links to other, non-listed sites: Agnosis looks at the page and checks it against categories you choose to disallow — pornographic content (on by default), commercial/promotional sites, gambling sites, or your own custom description. If a link can't be safely checked, it's left out rather than embedded. This is off by default, so existing sites see no change unless it's turned on.
* Added: A "Trust all admitted artists" setting under Settings → Behaviour, for admins who consider the community vouching process itself sufficient — turning it on embeds any artist-submitted link immediately, skipping both the trusted platforms list and AI review entirely. Off by default.
* Added: A "Reset to default" button on the System prompt, Artist prompt template, Enhancement instructions, Trusted embed platforms, and Invitation intro fields under Settings, so overwriting one of these no longer means losing the plugin's original text for good.
* Added: The AI is now explicitly told to ignore email footers (e.g. "Sent from my iPhone"), signatures, and other text unrelated to the submission when writing artwork descriptions, polishing biography/event text, or merging biography updates.
* Added: The Artist/Public newsletter intro fields no longer have to be written by hand — a configurable number of hours (default 24) before an issue is due, Agnosis drafts one from what's new (artwork, events, tags, mediums, new members, open votes) and saves it to the field, then emails you to review, edit, or clear it before it sends. Two new settings under Settings → Newsletter control it: an on/off switch ("Auto-draft newsletter intros", on by default) and the lead time in hours.

For the complete version history, see CHANGELOG.md in the plugin's source repository.
