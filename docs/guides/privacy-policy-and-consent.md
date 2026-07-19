# Privacy policy content & consent notices

> Mirrors `Core\Privacy`'s suggested Privacy Policy Guide content and the short front-end consent notices shown on the Join and Contact forms. Source: `Privacy::add_privacy_policy_content()` and `Privacy::consent_notice_html()` in `includes/Core/Privacy.php`. Both are translatable (`esc_html__()`). If this copy changes, update `Privacy.php` first and mirror the change here.

## Suggested Privacy Policy content

Registered via WordPress's own Privacy Policy Guide (`wp_add_privacy_policy_content()`), under the section title **"Agnosis"**. This is explicitly a *starting point* for the site operator, not final legal text:

> Agnosis is a federated art-publishing plugin. The following is a starting point for describing what it collects — review and adapt it to your site's actual configuration before publishing.

### What personal data we collect and why

> When you apply to join, we store your email address, display name, biography, statement, and portfolio link to run the community vouching process. When you email us artwork, a biography, or an event, the content of that email (including your address) is stored while it is processed and reviewed. When you subscribe to our newsletter or message an artist through their contact form, we store your email address (and, for contact messages, your IP address) to deliver that message or send you future issues.

### Who we share it with

> Submitted artwork descriptions and images may be sent to a third-party AI provider (OpenAI or Anthropic, depending on this site's configuration) to help write publication text and enhance images. If this site instead uses WordPress's own built-in AI Client (configured under Settings → Connectors), submitted content is sent to whichever AI service that connector points to instead. Approved posts are also broadcast publicly over ActivityPub (the Fediverse) as part of this plugin's core federation feature.

### Security check (Cloudflare Turnstile)

Shown only when Turnstile is actually configured on this install (`Turnstile::is_enabled()` — both a site key and a secret key must be set under Settings → General). When it's off, this section is omitted entirely rather than shown as inapplicable boilerplate.

> This site uses Cloudflare Turnstile, an automated security check, on its Join, contact, and newsletter-signup forms to block spam submissions. Completing it sends your IP address to Cloudflare for verification.

### Software updates

> This site checks agnosis.art roughly every 12 hours for available plugin updates. That check includes this site's own web address so the update server can confirm compatibility; no visitor data is involved.

### How long we retain your data

> By default: contact-form messages are kept for 90 days, and the sender's IP address on them is cleared sooner, after 30 days. Membership applications that are rejected, withdrawn, or leave the network are anonymized after 180 days. Raw submission emails are deleted from the processing queue after 7 days, and diagnostic copies kept only if this site's administrator has debug logging turned on are deleted after 14 days. This site's administrator can adjust each of these periods under Settings. You can request a copy of, or the erasure of, the personal data described above at any time using this site's standard WordPress data request tools.

These map to `agnosis_contact_message_retention_days` (90, `ContactForm.php`), `ContactForm::IP_RETENTION_DAYS` (30), `agnosis_application_retention_days` (180, `Admission.php`), `agnosis_imap_cleanup_days` (7, `Inbox.php` — also what drives the operational log-pruning cron, despite `Logger::prune()`'s own unused default parameter of 30), and `agnosis_debug_retention_days` (14, `Inbox.php` → `Debug::prune()`).

## DSAR position: Fediverse followers

Mirrors the class-level docblock in `includes/Core/Privacy.php` (privacy audit P-2, `AUDIT-0.9.39.md` §3c) — this isn't suggested policy copy for a visitor, it's the reasoned answer for an administrator who receives a data request naming a Fediverse actor.

`agnosis_followers` (remote accounts that follow this site) is deliberately outside `register_exporters()`/`register_erasers()` entirely. A remote follower's actor URI and inbox URL are personal data, but a WordPress DSAR is keyed by email address, and a remote follower has no email this site ever sees — there's no key to export or erase *by*.

Erasure here is protocol-native instead of request-driven: an `Undo Follow` activity removes the row when the follower unfollows, and an actor `Delete` removes it when the account itself is deleted — including the previously-unverifiable case (signing key already 410 Gone), now resolved via `HttpSignature`'s key-410 corroboration in `Network\ActivityPub::verify_inbox_signature()`. A deleted remote account's row does not persist indefinitely. This is the same position every other Fediverse server implementation takes.

If a data request names a specific actor URI, the answer is a manual row deletion by this site's administrator — `agnosis_followers` is a small, directly queryable table — rather than anything the automated Tools → Export/Erase Personal Data flow can key on.

## Front-end consent notices

Short, always-visible disclosures shown directly on the form itself — the Privacy Policy content above only ever surfaces on whatever page an operator links to it, so these exist to make sure a visitor sees *something* before submitting. Each links to the site's Privacy Policy page ("See our Privacy Policy for details.") when the operator has set one, and degrades to plain text when they haven't.

### Join form consent notice

> Submitted text and images may be processed by a third-party AI provider. Approved posts are published publicly and shared across the Fediverse — this cannot be fully undone later.

### Contact form consent notice

> Your message may be reviewed by an automated content filter before being sent to the artist.
