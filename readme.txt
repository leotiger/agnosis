=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.9.39
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
* Settings → Commerce holds a configurable transaction-fee percentage for a planned donation/store revenue layer — not yet a working checkout
* `agnosis_artwork`/`agnosis_biography`/`agnosis_event` custom post types with Gutenberg gallery blocks
* Settings: General | Branding | Email | AI Providers | Behavior | Network | Community | Commerce | Newsletter | Logs

== Installation ==

1. Install and activate **Lingua Forge** first — Agnosis declares it as a required plugin (WordPress 6.5+ Plugin Dependencies) and will refuse to activate with only a generic error if it isn't already installed and active.
2. Upload the `agnosis` folder to `/wp-content/plugins/`
3. Activate via **Plugins → Installed Plugins**
4. Go to **Agnosis** in the admin sidebar and configure your email inbox and AI API keys
5. Artists apply at the **`/join/`** page and are vouched in by peers

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

= 0.9.39 =
* Added: A new "Powered by Agnosis" page is created as a draft the first time the plugin activates or upgrades. It's a short, easy-reading summary of what Agnosis is and how it works, with a link to the GitHub repository for full documentation and installation — meant for a visitor who wants to know what your site runs on, and how to get it for their own. It starts as a draft; publish it (and link it from wherever you like) whenever you're ready.
* Fixed: The "Sync all translations" button on the Tags/Mediums screens no longer risks silently timing out on a large vocabulary. It now stops cleanly after about 20 seconds of work and tells you how many terms are left — click it again to continue exactly where it stopped.
* Fixed: Two languages that happen to translate a term to the same word (e.g. "Fotografie" in both German and Dutch) no longer permanently fail that language's sync. It's now resolved automatically, and any translation that genuinely couldn't be created is now called out in the notice instead of silently disappearing.
* Fixed: Corrected several wrong-match translations left over from an earlier automated translation-file update (mislabeled text like "queue rows" instead of "artwork" in a couple of German UI messages). The translation build process now automatically clears any uncertain matches going forward so they get retranslated properly instead of shipping a plausible-looking but wrong guess.
* Fixed: A few small polish issues on the Tags/Mediums language filter added last version. Added an "All languages (unfiltered)" option so a term flagged for a language you've since removed from your settings is no longer permanently hidden. Searching while viewing a non-primary language no longer resets you back to the primary view. Syncing a term's translations no longer bounces you back to page 1 of the list.
* Fixed: A remote Fediverse account that deletes itself is now cleaned up from your followers list even in the case where its signing key can no longer be fetched at all (a known Mastodon-ecosystem timing quirk) — previously that follower record could be left behind indefinitely.
* Fixed: Promoting an artwork (the promote@ address) now only highlights it on the shared main gallery, as intended. It no longer shows a "Featured" mark or changes anything on your own subdomain gallery, which already shows all of your published work. The Artist Guide page's own description of this was corrected to match.
* Fixed: A medium or tag that failed to translate into a given language (e.g. "Mixed Media" not appearing in German) is no longer stuck that way forever — previously, a failed translation was mistakenly cached as if it had succeeded, so re-running "Sync all translations" never actually retried it. Existing stuck entries are automatically cleared on this update; re-run Sync translations afterward to fill them in. AI translation prompts for these short labels were also improved to reduce how often this happens in the first place.

= 0.9.38 =
* Added: The Tags and Mediums admin screens (Posts → Artwork → Mediums, etc.) now show only your own primary-language terms by default, with a new dropdown to switch to any other configured language — no more hundreds of AI-translated duplicates mixed into one list. Mediums also got a "Sync translations" action to create a term's translation in every configured language on demand, and editing an artwork's medium after publishing now correctly updates its already-translated sibling posts too, instead of leaving them stuck on the old term.
* Added: A "Sync all translations" button next to the Tags/Mediums language dropdown syncs every primary-language term to every configured language in one click, instead of the "Sync translations" row action one term at a time. It's safe to click again if a large vocabulary times out partway through — it resumes rather than redoing work.
* Added: A new "Medium translations" box on each artwork's edit screen, plus a matching bulk action on the artwork list screen, lets you push an artwork's medium onto its already-translated sibling posts on demand — useful for pairs that drifted out of sync before the automatic version above existed.
* Fixed: Term translations are now linked to their source term by a stable ID instead of by matching names, so re-syncing after a re-translation no longer creates near-duplicate terms.
* Fixed: Tags and mediums created automatically while AI-tagging a submission in a non-primary language are now correctly recorded as translations, instead of silently joining your primary-language vocabulary.

For the complete version history, see CHANGELOG.md in the plugin's source repository.

