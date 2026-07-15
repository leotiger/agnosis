# Privacy policy content & consent notices

> Mirrors `Core\Privacy`'s suggested Privacy Policy Guide content and the short front-end consent notices shown on the Join and Contact forms. Source: `Privacy::add_privacy_policy_content()` and `Privacy::consent_notice_html()` in `includes/Core/Privacy.php`. Both are translatable (`esc_html__()`). If this copy changes, update `Privacy.php` first and mirror the change here.

## Suggested Privacy Policy content

Registered via WordPress's own Privacy Policy Guide (`wp_add_privacy_policy_content()`), under the section title **"Agnosis"**. This is explicitly a *starting point* for the site operator, not final legal text:

> Agnosis is a federated art-publishing plugin. The following is a starting point for describing what it collects — review and adapt it to your site's actual configuration before publishing.

### What personal data we collect and why

> When you apply to join, we store your email address, display name, biography, statement, and portfolio link to run the community vouching process. When you email us artwork, a biography, or an event, the content of that email (including your address) is stored while it is processed and reviewed. When you subscribe to our newsletter or message an artist through their contact form, we store your email address (and, for contact messages, your IP address) to deliver that message or send you future issues.

### Who we share it with

> Submitted artwork descriptions and images may be sent to a third-party AI provider (OpenAI or Anthropic, depending on this site's configuration) to help write publication text and enhance images. If this site instead uses WordPress's own built-in AI Client (configured under Settings → Connectors), submitted content is sent to whichever AI service that connector points to instead. Approved posts are also broadcast publicly over ActivityPub (the Fediverse) as part of this plugin's core federation feature.

### How long we retain your data

> Retention varies by data type; see this site's administrator for specifics. You can request a copy of, or the erasure of, the personal data described above at any time using this site's standard WordPress data request tools.

## Front-end consent notices

Short, always-visible disclosures shown directly on the form itself — the Privacy Policy content above only ever surfaces on whatever page an operator links to it, so these exist to make sure a visitor sees *something* before submitting. Each links to the site's Privacy Policy page ("See our Privacy Policy for details.") when the operator has set one, and degrades to plain text when they haven't.

### Join form consent notice

> Submitted text and images may be processed by a third-party AI provider. Approved posts are published publicly and shared across the Fediverse — this cannot be fully undone later.

### Contact form consent notice

> Your message may be reviewed by an automated content filter before being sent to the artist.
