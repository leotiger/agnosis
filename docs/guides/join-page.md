# Join page

> Mirrors the `/join/` application form (the `agnosis/join` block). Source: `Artist\JoinPage::render()` and `enqueue_assets()` in `includes/Artist/JoinPage.php`. Field labels/hints are translatable (`esc_html_e()`); the localized JS strings are set via `wp_localize_script()`. If this form's copy changes, update `JoinPage.php` first and mirror the change here.

If the visitor is already an admitted artist, the block renders this instead of the form:

> You are already an admitted Agnosis artist.

## Form fields

| Field | Label | Required | Hint / notes |
|---|---|---|---|
| `display_name` | Your name | Yes | — |
| `email` | Email address | Yes | — |
| `bio` | Short bio | No | "A few sentences about your practice, medium, and background." |
| `portfolio_url` | Portfolio URL | No | Placeholder `https://` |
| `statement` | Why do you want to join? | No | — |
| `language` | Language you work in | Yes | "Helps us communicate and translate content in your language." Options are exactly the languages Lingua Forge is configured for on this site (falls back to just the site's own locale if Lingua Forge isn't active), sorted alphabetically by ISO code. Placeholder option: "Select your language" (disabled, so it can't be submitted as-is). |

A Cloudflare Turnstile widget renders above the submit button when configured. Directly below it, the [consent notice](./privacy-policy-and-consent.md#join-form-consent-notice) is shown. Submit button label: **Apply**.

## Post-submit messages (localized JS strings)

- **Success** — "Almost there — check your inbox for a confirmation email and click the link to open your application for community review." *(Deliberately points at the applicant's inbox, not at "your application is under review" — `apply()` only ever parks the application and emails a confirmation link; nothing is shown to the community until that link is clicked. This is the double opt-in gate.)*
- **Error** — "Something went wrong. Please try again."
- **Required field** — "Please fill in all required fields."
- **Language required** — "Please select your language."
