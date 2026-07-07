=== Agnosis ===
Contributors: agnosis
Tags: art, artists, activitypub, federation, ai
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.4
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

= 0.9.4 =
* Fixed: When an artist requested that a piece of art or an event be taken down (via the remove link in their email), only the original-language version was actually removed — any translated copies of it stayed live and visible. Removal now takes every translated version down along with it.
* Fixed: If an artist said goodbye and asked for their account and work to be deleted, a piece they'd already individually removed shortly beforehand (and was still sitting in the trash) could be missed — technically not deleted despite the confirmation saying "this cannot be undone." Departure now removes everything regardless of what state it was already in.

= 0.9.3 =
* Fixed: The list of work-submission addresses (artwork@, biography@, etc.) at the bottom of your emails — added in 0.9.2 with a one-line explanation for each — turned out to never actually translate into an artist's language, even though everything else in the same email correctly did. It's now translatable like the rest of the email. (Note: if you use Loco Translate to provide translations, you'll need to re-scan for translatable text once to pick up these newly-fixable strings.)
* Fixed: After clicking a vote link in an email (voting on a new applicant or on removing a member), the confirmation page you land on showed in the site's default language instead of your own — even though the email you clicked from was correctly in your language. It now matches.
* Changed: Increased the text size further across all outbound emails and their matching confirmation pages, on top of the size increase already made in 0.9.2 — it still read a bit small overall.

= 0.9.1 =
* Fixed: An artist's own subdomain (e.g. artistx.agnosis.art) showed every artist's work on its homepage, instead of just that artist's own. It now correctly shows only their own gallery.
* Fixed: The "More works" section on a single artwork page also showed other artists' work — now fixed the same way, showing only more of that same artist's own work.
* Fixed: A submission with no usable photo could still create an empty, untitled post with nothing in it. Empty submissions are now rejected outright instead of being silently published blank.

= 0.9.2 =
* Fixed: Dates shown on artwork, biography, and event pages didn't read naturally in languages other than English — the day/month/year order and connecting words (like Portuguese's "de") weren't adapting to the page's language, only the month name itself was translated. Dates are now formatted natively for whatever language the page is being viewed in.
* Fixed: On an artwork's page, the translated title shown under the artist's own title (e.g. the site-language version) was nearly impossible to read against the dark header — it's now a legible size and brightness.
* Fixed: A genuinely attached photo (JPG or PNG) could still be rejected as "no valid image attachment found." The actual cause: the plugin's speed optimization for checking incoming mail (reading just the sender/subject before downloading the whole message) never went back to download the full message when it needed to — so attachments were never actually seen, no matter the file. Fixed, and a rejection is now logged with enough detail to diagnose if it ever happens again.
* Fixed: The review email you receive after submitting showed the title and short excerpt in your own language, but the longer description below them stayed in the site's main language — so if that wasn't a language you read, you couldn't actually tell what you were about to approve. That description is now translated into your language too.
* Fixed: The list of work-submission addresses (artwork@, biography@, etc.) at the bottom of your emails was tiny, low-contrast, and gave no hint of what each address actually does. Each address is now on its own line with a short explanation, in a readable size and color. Also increased the text size throughout these emails — it read too small overall.
* Added: A "Debug logging" option under Settings → General, with its own Debug Files panel. When turned on, it writes detailed diagnostic files about how each incoming email was processed — useful for troubleshooting a submission that didn't come through as expected, without needing developer access to the server.

= 0.9.0 =
* Fixed: Submitting the join form in a language other than the site's default could silently fall back to English — the confirmation email arrived untranslated and your WordPress account ended up in the wrong language once admitted. Language is now a required field on the join form, checked both in your browser and on the server, so this can no longer happen.
* Fixed: The Inbox admin page could mislabel a real, registered artist's email as coming from an "unregistered sender," and could separately show a false "not admitted" message for an artist who clearly was admitted. The admin log now always shows the actual reason a submission was skipped.
* Fixed: When an email included several photos, only the first one was checked for quality — a bad first photo could reject the whole submission even though the others were fine, or a bad later photo could slip through unnoticed. Every photo in a submission is now checked individually.
* Fixed: The "we received your application" and "your application was declined" emails no longer show a list of work-submission addresses that don't apply yet — those only make sense once you're actually admitted.
* Added: You can now set a page artists are sent to after applying (Settings → Network → "After applying, send artists to") — handy for explaining what happens next and how long voting takes.
* Added: You're now emailed if a submission couldn't be processed at all — for example, a photo pasted into the message instead of properly attached, or a file in a format the server couldn't convert — so you know to resend it instead of wondering why it never appeared.
* Added: HEIC/HEIF support — the default photo format on modern iPhones. These photos are now accepted and automatically converted before publishing, instead of being silently rejected.
* Added: The "application received" and "welcome, you're admitted" emails are now nicely formatted, matching the look of every other email Agnosis sends.
* Changed: The welcome email no longer leads with "set your password." Working with Agnosis needs no login at all — everything happens by email. Setting a password is now mentioned only as an optional extra, for artists who also want to use the site's online features (like previewing a submission before it publishes).

= 0.8.2 =
* Fixed: On some sites the background task that actually sends queued newsletters could end up not scheduled at all, so a queued send would never go out and never self-correct either. Viewing the Newsletter dashboard now also checks for this and re-enables background sending automatically if it's ever missing.

= 0.8.1 =
* Fixed: A newsletter could get stuck showing "Sending…" with Send Now disabled indefinitely, if any single recipient's email failed to build for an unrelated reason — that one failure was silently blocking the status from ever being corrected, even after the newsletter had actually finished going out to everyone else. The status now also corrects itself the moment you view the Newsletter dashboard, rather than waiting for the next background check.

= 0.8.0 =
* Added: Front-end correction for artists (audit §7, Phase 1). Admitted artists can now correct a typo, update an event's location/date, or tighten a bio directly on the published page — no new intake email, no dashboard, no forms. A small pencil affordance appears next to editable text only when you're viewing your own published artwork, biography, or event page while logged in; click it, edit in place, save.
* Added: Correction is restricted to the language version matching your own account language — never a free language switcher. If your account language differs from the site's primary language, saving your edit automatically translates it into the primary language and re-queues every other configured language in the background, without touching the exact words you wrote.
* Added: `'revisions'` support on artworks, biographies, and events — every front-end correction is now a WordPress revision, giving admins free undo and an audit trail.
* Added: Front-end photo substitution (audit §7, Phase 2). Replace the featured image on your biography or event page, or a specific gallery image on an artwork, with a direct upload — no AI enhancement, no quality gate, your photo as you uploaded it. The replaced photo is kept, not deleted, so it can be restored later.
* Added: Artwork title editing (audit §7, Phase 3). Correct your artwork's title directly on the page — your own words stay exactly as you wrote them on every language version, and the translated subtitle underneath updates automatically to match.
* Added: "Restore earlier photo" one-click (audit §7, Phase 3) — undo a photo replacement without re-uploading, right from the same pencil affordance.
* Added: Newsletter archive (audit §8). Every sent public newsletter issue now has its own permanent page at /newsletter/{id}/, and a /newsletter/ page lists past issues. Every sent email includes a "View it online" link to its own archive page, and the newsletter signup form shows a "See past issues" link once at least one issue has gone out.
* Added: The Newsletter dashboard now shows a subscribers-by-language breakdown for the public newsletter (audit §8) — a quick read on which languages your subscribers actually use.
* Added: The newsletter signup form now has a language selector, like the join form already does. It defaults to the language of the page you're signing up from, but you can change it before subscribing.
* Changed: Corrections are rate-limited to 30 saves per artist per hour.
* Added: Vision-input images are now downscaled before being sent to the AI for description, saving on API cost with no effect on description quality — configurable at AI → "Vision image max width (px)" (default 800px, set to 0 to disable). The photo used for enhancement and the one actually published are never affected, only the copy sent for description.
* Added: A few artist-facing emails (submission review, removal confirmation, photo-quality notice, admission votes, reinstatement, community votes) now include a short reminder that you can fix typos, text, or photos directly on your published page — but only once you actually have something published to fix.
* Added: A "Forgot your password?" link on the artist login form, and basic site branding (your logo, matching colours) on the WordPress password-reset screen — previously the only way back in after a forgotten password was an unbranded, unlinked WordPress page.
* Added: "Invite an Artist" (Settings → Newsletter). Send a one-off invitation email to a prospective artist — pick their language, write (or use the default) intro text about the community, and send. Includes its own test-send button. Separate from the newsletter system: nothing is scheduled or tracked, it just sends.

= 0.7.3 =
* Fixed: On an artist's subdomain, the artist-name link (breadcrumb) and the "back to Agnosis" site logo/title link always pointed at the source-language home, even when the visitor was on a translated page (e.g. /fr/). Both now stay on the visitor's current language.

= 0.7.2 =
* Fixed: On sites using "Your latest posts" as the homepage (Reading Settings), the artist gallery block's pagination count and its actually-rendered artwork could disagree on translated languages — translated posts were counted toward page totals but never shown. The block now filters every query it runs by the current page's language, so the count and the content it produces always agree.

= 0.7.1 =
* Fixed: Email logo could render far smaller than its configured height — the logo source was requested at WordPress's 'large' size (a 1024×1024 bounding box WordPress never upscales past), so a wide banner-style logo's generated file could already be shorter than the configured height before the display cap even applied. Now uses the attachment's full original resolution.
* Changed: Email logo height raised to 150px (was 40px) to properly fit a full banner-style logo (wordmark and tagline baked into the image), not just a small icon.
* Changed: Email header background is now the same dark colour used on the website, replacing the previous purple bar, across every outgoing email (submission review, removal confirmation, rejection notice, subscription confirmation, both newsletters).
* Changed: The logo now sits on its own white panel inside the header, sized to match the email body's content width — the previous transparent-background logo didn't render cleanly against the new dark header.
* Changed: Reduced left/right padding in every email's header, body, and footer, giving the content more usable width within the same overall email size.
* Fixed: The newsletter subscription-confirmation email predated the shared email-branding system and still showed the old plain-text header — it now matches every other outgoing email.

= 0.7.0 =
* Added: Genuine sound and video submission support — audio and video attachments are accepted at intake (widened MIME allowlist, per-type size limits), described by AI (from an automatically extracted poster frame for video, or from the artist's own words when no frame is available), and published with WordPress's native audio/video players — no theme changes needed.
* Added: Artist Guide now explains sound and video submissions and how they're handled.
* Added: A link to an external video/audio platform (YouTube, Vimeo, Dailymotion, SoundCloud, Bandcamp, Archive.org) in an artist's message is now embedded at the bottom of the post — for when the actual file was too large to email. Only this small, maintained set of platforms is ever embedded; any other link is silently omitted, never shown as a raw link either.

= 0.6.4 =
* Added: Email logo setting (Settings → General) — pick an image from the media library to show in the header of every outgoing HTML email (submission review, removal confirmation, rejection notice, both newsletters) instead of the plain "✦ Site Name" text.
* Added: Artist-facing emails (application, admission, vote requests, suspension/reinstatement, community votes) now close with a one-line summary of every configured work-submission address, so artists always have every address one glance away. Newsletter emails and the goodbye/departure email are unaffected.
* Fixed: Artist Guide wrongly implied someone else reviews and approves a submission before it publishes — corrected to make clear the artist alone decides.
* Added: Artist Guide now clarifies the photo-only lane isn't limited to photographers — any artist, in any medium, can use it to stop AI from touching the image representing their piece.

= 0.6.3 =
* Added: New `agnosis/newsletter-popover` block — the subscribe trigger icon and its full-viewport signup popover, previously theme-only markup, are now a self-contained plugin block any theme can drop in.
* Added: Trigger icon is now selectable from the block's Inspector panel — Bell (default), Envelope, Star, or Lightning bolt — so it can be changed without touching CSS or templates.

= 0.6.2 =
* Changed: Restyled the "Log in to view your submissions" form — larger, stacked username/password fields matching the Join/Subscribe forms.
* Added: Optional Cloudflare Turnstile on that same login form, scoped so it never affects wp-admin or any other login on the site.

= 0.6.1 =
* Fixed: Removed the Join form's "Auto-detect (browser language)" option and the Accept-Language guessing behind it — an artist's language must now be explicitly picked from what Lingua Forge actually supports on this site, never guessed.

= 0.6.0 =
* Changed: Join form's language list (and AI translation eligibility) now comes entirely from Lingua Forge's own active-language configuration instead of a hardcoded list — whatever Lingua Forge supports is what appears here.

= 0.5.4 =
* Added: Cloudflare Turnstile human-verification on the public Subscribe and Join forms — opt-in via two new Settings → General fields (site key, secret key); both forms are unaffected until configured.

= 0.5.3 =
* Changed: agnosis/artist-breadcrumb's artist name now links to the artist's own subdomain home, so it also works as a way back from artwork/bio/event pages.

= 0.5.2 =
* Added: agnosis/artist-breadcrumb block now also supports font weight and font family from the Typography inspector panel.
* Changed: agnosis/artist-breadcrumb now renders a div instead of a p tag.

= 0.5.1 =
* Added: agnosis/artist-breadcrumb block now supports text color, background color, and font size, editable per-instance from the block's own Color/Typography inspector panels. Falls back to the theme's default styling when nothing is picked.

= 0.5.0 =
* Added: New agnosis/artist-breadcrumb block — shows the current artist's name, renders nothing on the main site. Lets any theme identify the artist on a subdomain without reimplementing subdomain-detection logic itself.
* Changed: Moved the "link back to the main Agnosis site" fix (Site Logo/Site Title links on artist subdomains) out of the agnosis-theme repo and into this plugin, as Agnosis\Network\SubdomainNavigation — it now applies automatically to any theme using core's Site Logo/Site Title blocks, not just agnosis-theme.

= 0.4.3 =
* Changed: Artist subdomains no longer replace the visible site title with the artist's name (removed the option_blogname filter added in 0.1.9) — the header now keeps showing "Agnosis". The browser tab title still shows the artist's name. The actual navigation gap this was covering for is fixed on the theme side: the Site Logo and Site Title now link back to the main Agnosis site from an artist subdomain instead of the artist's own home.
* Fixed: Email action links (review approve/reject/remove, admission votes, newsletter confirm/unsubscribe) previously acted the moment their URL was fetched with a plain GET — corporate mail-security scanners (Outlook SafeLinks, Mimecast, Proofpoint, etc.) prefetch links in incoming email to scan them, which could silently approve/reject/remove artwork, cast a vote, or confirm/unsubscribe a newsletter recipient before the person ever clicked anything, and could also consume a single-use review token so the artist's real click showed "link expired". Every one of these links now renders a confirm page with a single button on GET; the action is only taken once that button is clicked (a POST).
* Fixed: The newsletter signup block never sent the visitor's language, so every public subscriber's locale was left blank and the newsletter's per-locale rendering (added in 0.4.1) never actually applied to public subscribers in practice. The block now picks up the language of the page the visitor is on automatically — no new form field.
* Fixed: The community removal-vote link in notification emails was completely dead — no handler was ever registered for it, and there was no other way to cast a removal vote. It now works the same way admission-vote links do: a confirm page on open, the vote recorded only once confirmed.
* Fixed: A failed newsletter send (transient SMTP hiccup, momentary host rate-limit) was permanently given up on after a single failure. It's now retried on later cron ticks, up to three attempts, before being marked failed for good.
* Fixed: Newsletter mail now supports RFC 8058 one-click unsubscribe — the `List-Unsubscribe-Post` header is sent alongside `List-Unsubscribe`, and a mail client's automated one-click request unsubscribes immediately with no confirm page, while the visible unsubscribe link in the email body still shows a confirm-button page.
* Fixed: The newsletter signup form let anyone check whether an email address was already subscribed (a distinguishable error response for existing addresses). It now responds identically either way.
* Fixed: Unconfirmed newsletter signups never expired, and resubmitting the same address always resent a confirmation email. Abandoned pending signups now expire after 14 days, and resubmitting within 5 minutes no longer sends a repeat email.
* Fixed: Newsletter scheduling mixed local site time with UTC in a couple of places, which could shift the very first digest's content window and the "is it due yet?" check by the site's UTC offset. Everything now stays on one consistent clock.
* Fixed: A few small hygiene items — the newsletter signup field's label wasn't properly associated with its input for screen readers; an internal database column name was misleading; and the subscriber email column was narrowed slightly to avoid an index-size limit some older hosting setups impose.

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
