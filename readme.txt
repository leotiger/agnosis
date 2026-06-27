=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.8
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
