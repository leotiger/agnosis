# Guides — in-plugin copy, mirrored

This folder mirrors the actual text content Agnosis ships and displays to
artists and visitors — the Artist Guide page, the About page, the medium
taxonomy, the Join form, the privacy-policy/consent copy, and the
transactional emails. It exists so this copy can be read, reviewed, and
diffed without spinning up a site.

**Source of truth is always the PHP, not this folder.** Every file below
names its source method(s). If the plugin's copy changes, these files need
a matching update — they are not generated automatically, and Lingua Forge
translation of the live pages/emails has no bearing on this English source
copy.

- [`artist-guide.md`](./artist-guide.md) — the in-site `/artist-guide/` page (`Activator::help_page_content()`)
- [`about-page.md`](./about-page.md) — the public `/about/` page (`Activator::about_page_content()`)
- [`medium-categories.md`](./medium-categories.md) — the seeded `agnosis_medium` taxonomy terms (`PromptConfig::CANONICAL_MEDIUMS`)
- [`join-page.md`](./join-page.md) — the `/join/` application form's field copy and success/error messages (`Artist\JoinPage`)
- [`privacy-policy-and-consent.md`](./privacy-policy-and-consent.md) — the suggested Privacy Policy content and the Join/Contact form consent notices (`Core\Privacy`)
- [`email-templates.md`](./email-templates.md) — the admission, departure, and community-vote transactional emails (`Artist\AdmissionNotification`, `Artist\DepartureNotification`, `Artist\CommunityCapNotification`)
