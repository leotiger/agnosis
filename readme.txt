=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.2
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

= 0.4.2 =
* Fixed: A newsletter issue where every recipient's send failed (e.g. a temporary mail delivery problem) could get stuck showing "Sending…" on the Newsletter settings dashboard forever, with "Send Now" disabled — it's now always reconciled to a finished state once the queue drains, even if nothing was successfully delivered.

= 0.4.1 =
* Fixed: Newsletters are now actually localized. Previously the digest and intro were rendered once per issue in a single language and reused for every recipient — a French subscriber and a German artist both got the same English text, and post titles never used their translated versions even when Lingua Forge had one. Recipients are now grouped by their own locale; the digest links to each post's translated title/page when available (falling back to the original when no translation exists yet), and the admin's intro is machine-translated per locale when an AI provider is configured. Also fixed a related bug where a multi-language site's digest listed the same artwork once per translated copy instead of once.

= 0.4.0 =
* Added: Newsletter system — a monthly (configurable) artist newsletter, auto-enrolled with one-click opt-out, and a separate public newsletter with double opt-in subscription (a `agnosis/newsletter-signup` block for pages). Each issue combines an auto-generated activity digest (new artworks, events, admissions) with an optional admin-written intro, sent in batches by WP-Cron. Self-hosted; no external email service required.
* Added: Settings → Newsletter tab — enable/disable and set frequency per newsletter, write the intro, configure a dedicated "From" name/address for newsletter mail (separate from the site's admin email), see subscriber stats, and trigger a manual "Send Now" or a "Send Test" preview to a single address without affecting subscribers or the schedule.
* Changed: Default "About" and "Artist Guide" pages rewritten with clearer, more complete guidance — how AI is used (image quality enhancement only, never altering the artwork itself), how artwork titles work, and how to submit multiple images as a series. Removed a duplicated heading now that the theme renders the page title itself.
* Fixed: Code-coverage tooling (`composer coverage`) was silently under-reporting integration test coverage due to stale PHP configuration in the local test environment; coverage now reports correctly.

= 0.3.0 =
* Added: Member-governed community size cap — a maximum number of admitted artists (default 50; 0 = unlimited). When full, applications join a FIFO waitlist instead of being rejected, and a freed slot welcomes the next in line. The community can vote to change the cap (propose → co-sign → community vote → daily tally).
* Added: "About" and "Artist Guide" pages, created out of the box, explaining what Agnosis is and how to submit work by email. Translated automatically by Lingua Forge.
* Added: `uninstall.php` — deleting the plugin now fully cleans up (custom tables, options, the artist role, managed pages, scheduled events, and the upload queue directory). Artists' published work and accounts are preserved.
* Changed: Lingua Forge integration overhaul — translated artwork posts now inherit their images, gallery, and per-language titles; the translated description is written at creation; translation is dispatched off the email-intake request (no blocking AI calls inline) and uses Lingua Forge 2.4.0's async queue when available.
* Fixed: The source language tag (`_lf_lang`) now tracks Lingua Forge's configured primary language rather than the WordPress locale, so translations start from the right language.
* Fixed: IMAP polling now fetches only new mail via a UID cursor (headers-first), instead of re-scanning the whole retention window every run.

= 0.2.0 =
* Added: Three independent artist departure paths — self-removal (email-confirmed token link), admin suspend/delete, and community removal vote
* Added: `goodbye@` email alias — artists email to request account deletion; confirmation link sent back before anything is deleted
* Added: Members dashboard in Settings → Network — suspend, delete, or open a community vote on any admitted artist
* Added: Community voting on admission — yes/no votes via HMAC-signed email links; dynamic threshold (% of active artists); 7-day window; daily cron resolution
* Added: `agnosis/join` block — server-side-rendered public application form; `/join/` page auto-created on activation
* Added: Admin admit/reject overrides — bypass vouch threshold directly from the pending applications dashboard
* Added: Applicant acknowledgment email — confirmation sent immediately on application in the applicant's language
* Added: Applicant language capture — `language` param or `Accept-Language` header; stored and mapped to WP locale on admission
* Added: `agnosis_email_goodbye` setting (Email tab) — configurable goodbye alias address
* Added: Dual-title artwork architecture — `post_title` = artist's original title; AI title stored in `_agnosis_translated_title` meta
* Added: `agnosis/artwork-title` block — renders original + translated title as `<hgroup>` when they differ
* Added: Per-recipient locale switching across all notification emails
* Added: URL slugs derived from original submitted title; ICU transliteration for non-Latin scripts
* Added: `SubmissionTranslator::translate_text()` and `from_settings()` — back-translation and no-injection factory
* Added: Welcome email now includes gallery URL, `/my-submissions/` link, alias reference, and goodbye instructions
* Added: Language list on join form driven by installed WP language packs (no hardcoded list)
* Fixed: LinguaForge translation trigger was a silent no-op — replaced missing hook with direct `linguaforge_trigger_translation()` API call

= 0.1.9 =
* Added: `/my-submissions` works on artist subdomains — scope_query fix + COOKIE_DOMAIN documentation
* Added: Inline login form on `/my-submissions` via wp_login_form() — no wp-login.php exposure
* Added: Artist name in subdomain site title via option_blogname filter
* Added: Multilingual submission translation (SubmissionTranslator) with Lingua Forge integration
* Added: Media type expansion — audio (Whisper), video stills (ffmpeg), PDF portfolios (Imagick)
* Added: Automatic quality rejection gate with artist-facing tips email
* Added: promote@ alias workflow; PostCreator::set_featured() + handle_promotion_request()
* Added: Pipeline::extract_event_fields(); _agnosis_event_location post meta; agnosis/event-location block
* Fixed: CREATE TABLE IF NOT EXISTS removed from all dbDelta() calls — was silently skipping new columns
* Fixed: ReviewEndpoints::reject() incorrect action arg count
* Fixed: render.php unescaped attachment ID wrapped with absint()
* Fixed: Minimum WordPress version bumped to 6.6

= 0.1.8 =
* Security: admission status endpoint now requires authentication; non-admins can only read their own status (was fully public)
* Security: per-IP rate limiting on apply (5/min), vouch (20/min), and email webhook (60/min) endpoints via new Core\RateLimiter
* Security: ActivityPub inbox HTTP Signature verification (RSA-SHA256, Date freshness, Digest body hash)
* Security: vouch revocation — revoked_at column on agnosis_vouches; revoked vouches excluded from admission count
* Fixed: queue drain automated — drain_pending() runs every 5 min via poll(); covers webhook-sourced rows whose async event was missed
* Fixed: maybe_upgrade() uses SHOW COLUMNS instead of information_schema (managed DB compatibility)
* Fixed: handle_test_ai() duplicate HTTP logic extracted to ping_provider(); adding a fourth provider now costs 5 lines
* Fixed: upload_size_limit filter removal scoped to own closure (was remove_all_filters)
* Fixed: no_found_rows => true added to 8 get_posts() calls that never read found_posts
* Changed: PostCreator constructor accepts optional Pipeline injection for testability
* Changed: PostCreator::create_post() refactored from 157 to 67 lines (3 extracted helpers)

= 0.1.7 =
* Fixed gallery-overview block not rendering in FSE template — output-buffer conflict between render.php and WP's block wrapper; switched to PHP render_callback
* Rewrote editor.js as IIFE (window.wp.* globals) — no build step required
* Fixed all remaining Plugin Check warnings: absint() sanitisation, SchemaChange suppression, ABSPATH guard, prefixed variables, heredoc → sprintf, wp_unslash() on superglobals

= 0.1.6 =
* Dedicated email endpoints — submit@, bio@, event@, replace@, remove@ — routing by To: header
* Artist-driven removal workflow — artist confirms via signed email link; no admin action needed
* Medium auto-categorisation — AI assigns one of 8 canonical medium terms (Oil, Acrylic, Watercolour, Drawing, Digital, Photography, Sculpture, Mixed Media) to every artwork; taxonomy seeded on activation
* replace@ endpoint — bypasses AI fuzzy detection for explicit artwork replacement
* Photo quality detection — vision AI scores photographs 1–10; enhancement only runs below the configured threshold
* Conditional image enhancement — targeted correction based on detected issues; good photographs untouched
* Full gallery in review email — all submitted images rendered at agnosis-email size
* Agnosis Theme — new theme folder with Agnosis branding; build-theme-zip composer command

= 0.1.5 =
* Artist subdomain routing (SubdomainRouter)
* LinguaForge subfolder compatibility on artist subdomains

= 0.1.4 =
* Inbox admin page with per-row actions and attachment lightbox
* AI-powered and image-hash duplicate detection
* Subject-line indicators for biography and event submissions
* Biography and event CPTs (singleton per artist)
* AI polish for bio and event submissions

= 0.1.2 =
* Agnosis promoted to top-level sidebar menu (was Settings → Agnosis)

= 0.1.0 =
* Initial release — core plugin scaffold
* Email ingestion (IMAP + webhook)
* AI pipeline (OpenAI, Anthropic, Stability AI)
* Auto-publishing with Gutenberg gallery blocks
* Artist admission / vouching system
* ActivityPub federation + WebFinger
* Node identity with RSA key pair
* Admin settings (tabbed)
