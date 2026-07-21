# ✦ Agnosis

> Art blooming out of oblivion. Email your art, AI polishes it, the world sees it.

Agnosis is a free, federated WordPress plugin for independent artists. Artists who are great at creating — but not at promoting — send an email with their artwork. Agnosis handles the rest.

The name is Greek: γνῶσις (*gnōsis*) means "knowledge"; the prefix ἀ- negates it, giving ἄγνωσις (*agnōsis*) — "without knowledge," unknown.

## Who is this for?

Agnosis was designed for one shape — an open artist collective that vouches its own members in — but the underlying workflow (email in → AI polish → curated review → multilingual publish → federate) fits a few others too:

- **Artist collectives / open communities** — the designed case, and the default out of the box. Existing artists vouch new ones in by email vote; departures work the same way, by community vote or self-removal.
- **Galleries and curated programs** — turn community voting off (Settings → Community → "Admin approval only") and every admission and removal becomes a direct admin call instead of a community vote. Pair it with the sensitive-medium flag for explicit work, and reshape the medium vocabulary — an ordinary taxonomy, not hardcoded, and manageable per-language from the Tags/Mediums admin screens — to match the gallery's actual program.
- **Theatre/art schools and student showcases** — admin-approval mode fits an instructor-curated flow, and `agnosis_event` covers performances and exhibitions directly; mediums can become "Stage Design / Costume / Performance / …" in any of the 18 supported languages. Two things worth knowing before using Agnosis for a school: the platform assumes adult artists (public attribution, a public email workflow, and federation of published work) — a deployment involving minors needs its own consent/guardian handling, which is a policy decision for the school to make, not something Agnosis enforces; and there's no built-in cohort/semester structure — a "Class of 2027" grouping is a plain custom taxonomy away, but nothing ships it today.
- **Writers' collectives and literary magazines** — already a real fit, not a stretch: Poetry and Essay are canonical medium terms, a poster generator gives text-only submissions the same visual presence in galleries and federation that a photograph gets, and the HTML-only-email fallback matters most for exactly these submitters.

**Honest limits:** one Agnosis install is one community — a school with several departments needs either separate installs or one shared vocabulary across all of them; the medium taxonomy is flat, so a deployment that wants nested categories (e.g. Performance > Dance / Drama) has to model that itself; and Agnosis isn't a marketplace — donations are planned, but art sales/checkout are left to dedicated plugins (see Features below).

## How it works

1. **Receive** — artist emails to a dedicated address (IMAP or webhook); separate endpoints for `submit@`, `bio@`, `event@`, `replace@`, `remove@`, and `goodbye@`
2. **Enhance** — images with low photo quality scores are corrected via AI (OpenAI); good photographs are left untouched
3. **Describe** — title, description, tags, and medium category are written by AI (Claude / GPT-4o Vision)
4. **Review** — artist receives an email with a preview; one click to publish, edit, or discard
5. **Publish** — a gallery post is created automatically as an `agnosis_artwork`
6. **Broadcast** — the post is federated to Mastodon, Pixelfed and the wider Fediverse via ActivityPub

## Features

- **Email-to-post** — IMAP polling or webhook push (Mailgun, SendGrid, Postmark); dedicated addresses for artwork, biography, events, replacement, removal, and account departure (`goodbye@`)
- **Zero-login workflow** — submitting, editing, and removing work all happen by email; no WordPress account is required. A login is optional, only needed for the online submissions-preview dashboard, and is set up via WordPress's own self-service password recovery whenever an artist wants it
- **AI pipeline** — pluggable provider interface; OpenAI, Anthropic, and WordPress's own built-in AI Client (WP 7.0+, Settings → Connectors, no API key stored by Agnosis) out of the box — the WordPress AI Client covers description/text generation only, not image enhancement
- **HEIC/HEIF support** — the default photo format on modern iPhones is accepted at intake and converted to JPEG before AI processing, instead of being silently dropped; degrades gracefully when the server's ImageMagick build can't decode it
- **Photo quality gate** — vision AI scores each photograph independently, even across a multi-photo gallery submission; enhancement only runs below a configurable threshold; targeted correction from detected issues
- **Submission-failure notifications** — an artist is emailed if their message couldn't be turned into a post at all (no usable attachment found, or every attachment failed to convert), instead of it silently vanishing
- **Medium taxonomy** — 10 canonical terms (Oil Painting, Watercolor, Drawing & Illustration, Photography, Digital Art, Sculpture, Printmaking, Mixed Media, Poetry, Essay), covering written as well as visual submissions; AI assigns one per artwork; seeded on activation
- **Artist review workflow** — draft posted, artist reviews via email link (approve / edit / discard)
- **Visitor contact form** — a visitor can message an artist directly from their page; content-moderated, and rate-limited both per IP/sender and per (artist, visitor) pair (configurable), so one visitor can't flood one artist's inbox. The form is replaced with a static "already contacted" notice after sending, not just hidden client-side
- **Biography social links** — a portfolio link plus three optional social links, rendered as an icon row below the featured photo (WordPress core Social Icons block under the hood); an admin can also force a fixed, site-wide biography title (with an option to append the artist's own name) instead of each artist's own
- **Artist-driven removal** — `remove@` email triggers a signed confirmation link; artist trashes their own artwork; no admin needed
- **Artist departure** — three independent paths: self-removal (`goodbye@` alias or REST), admin suspend/delete, community vote; confirmation required before any deletion
- **Community admission** — artists vouch for new artists with yes/no email votes; dynamic threshold (% of active artists); admin can admit or reject directly from the dashboard; an optional configurable page can greet applicants right after they apply, explaining what happens next
- **Multilingual** — powered by the required companion plugin Lingua Forge (see Requirements below); language is a required field at application (enforced client- and server-side); per-recipient locale switching on all emails; artwork slugs and titles preserved in the artist's own language. Native-language fidelity (an artist's own words kept verbatim rather than translated back and forth) applies to submissions from 0.9.19 onward only — posts published before that keep whatever native-language sibling Lingua Forge had already machine-translated, which is not retroactively rebuilt
- **Dual-title artwork** — `post_title` is always the artist's original title; AI-generated site-language title stored separately in `_agnosis_translated_title` meta
- **ActivityPub federation** — each installation is a Fediverse actor; peers discover each other via `/.well-known/agnosis-node`
- **Node identity** — RSA key pair per node; signed peer-to-peer communication
- **Donations (planned)** — a simple way for visitors to support an artist directly is planned, with no platform fee; the mechanism itself hasn't been built yet, so running a node has no donation feature to enable today. Agnosis is not a marketplace — art sales, checkout, and other commerce functionality are left to dedicated plugins (e.g. WooCommerce). Always free for artists to participate
- **Agnosis Theme** — companion FSE block theme with black-and-white palette, CPT templates, and a gallery overview block
- **Composer dependencies** — the plugin ZIP ships `webklex/php-imap` (the IMAP intake transport) along with its own dependency tree (`illuminate/*`, `symfony/*`, `nesbot/carbon`, `doctrine/inflector`, `voku/portable-ascii`) — all MIT-licensed, GPL-compatible. These ship namespace-prefixed (via [Strauss](https://github.com/BrianHenryIE/strauss), since 0.9.30) into `vendor-prefixed/` under `Agnosis\Vendor\*`, so they can never collide with another active plugin bundling a different version of the same packages

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.6 |
| PHP | 8.2 |
| MySQL | 5.7 / MariaDB 10.3 |
| [Lingua Forge](https://github.com/leotiger/lingua-forge) | Required companion plugin — must be installed and active before Agnosis can be activated (declared via the `Requires Plugins` header, WP 6.5+) |

## Server configuration

### Artist subdomains

Agnosis routes each artist to their own subdomain (e.g. `artyart.agnosis.art`). Two server-level settings are required:

**DNS** — a wildcard A/CNAME record pointing `*.agnosis.art` to the same server as the main domain.

**wp-config.php** — add the following **before** the `/* That's all, stop editing! */` line:

```php
define( 'COOKIE_DOMAIN', '.agnosis.art' ); // leading dot = all subdomains
```

Without this, WordPress sets auth cookies for the exact host only. Artists who log in on `agnosis.art` will not be recognised on `artyart.agnosis.art` and vice versa. The leading dot makes the session cookie valid across all subdomains of the root domain.

Set `agnosis_base_domain` to your root domain in **Agnosis → Settings → General** after activation.

### nginx: protecting the queue and debug directories

Agnosis writes two directories it never wants served directly: `wp-content/uploads/agnosis-queue/` (raw, pre-publication submission files — originals under a short-lived per-submission directory) and `wp-content/agnosis-debug/` (raw diagnostic copies, only present if debug logging is turned on). Both are guarded with an Apache `.htaccess` (`Deny from all`) written automatically on first use — **which nginx does not read**. On nginx, add the equivalent `location` block yourself:

```nginx
location ^~ /wp-content/uploads/agnosis-queue/ {
    deny all;
    return 404;
}

location ^~ /wp-content/agnosis-debug/ {
    deny all;
    return 404;
}
```

Without this, a guessed path is served on nginx even though the `.htaccess` file sits right there doing nothing. The practical exposure is bounded — files are on short TTLs (queue: a few days; debug: two weeks by default) and a request would need to guess both the per-submission directory and the original, sanitized filename — but there's no reason to rely on that when a two-line server config closes it outright.

## Installation

Agnosis requires [Lingua Forge](https://github.com/leotiger/lingua-forge) to be installed and active first (`agnosis.php` declares it via the `Requires Plugins` header) — WordPress will refuse to activate Agnosis otherwise.

```bash
# Lingua Forge first (required dependency)
wp plugin install lingua-forge-<version>.zip --activate

# Then Agnosis, from a release zip (see the Releases page for the current version)
wp plugin install agnosis-<version>.zip --activate

# Or both from source — note the order
git clone https://github.com/leotiger/lingua-forge wp-content/plugins/lingua-forge
git clone https://github.com/leotiger/agnosis wp-content/plugins/agnosis
cd wp-content/plugins/agnosis && composer install && cd -
wp plugin activate lingua-forge agnosis
```

A plain `git clone` alone has no `webklex/php-imap` — the plugin's fallback autoloader only covers its own `Agnosis\` classes, so email intake via IMAP silently doesn't exist until `composer install` runs (it also handles the required namespace-prefixing step automatically). If you just want to run a real site rather than develop the plugin, using the release ZIP above avoids this step entirely.

Then open **Agnosis** in the admin sidebar and configure your email inbox and AI API keys.

### Optional: API keys via wp-config.php

By default, the OpenAI/Anthropic API keys, the inbound-webhook HMAC secret, and both Cloudflare Turnstile keys are stored the same way every mainstream WordPress plugin stores them — as plain `wp_options` rows, editable from Settings → Agnosis. If you'd rather keep these alongside the rest of your secrets in `wp-config.php` (an environment variable, a secrets manager writing PHP constants at deploy time, etc.), define any of the following before the `/* That's all, stop editing! */` line:

```php
define( 'AGNOSIS_OPENAI_KEY', '...' );
define( 'AGNOSIS_ANTHROPIC_KEY', '...' );
define( 'AGNOSIS_WEBHOOK_SECRET', '...' );
define( 'AGNOSIS_TURNSTILE_SITE_KEY', '...' );
define( 'AGNOSIS_TURNSTILE_SECRET_KEY', '...' );
```

A defined, non-empty constant always wins over whatever's saved in the database — its matching Settings field switches to a locked, read-only notice so you can't accidentally type a value there that would then be silently ignored. Define only the ones you want to manage this way; anything left undefined keeps working from Settings exactly as before.

## Development

```bash
# Install Node and PHP dev dependencies
cd dev
npm install
composer install

# Start the wp-env test environment
npm run env:start

# Run unit tests
composer test:unit

# Run integration tests (requires env:start)
composer test:integration

# Full coverage report (unit + integration + merge)
composer coverage

# Lint PHP
composer lint

# Static analysis
composer analyse

# Everything above except integration tests, in one shot (pre-PR gate)
composer qa
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full contributor workflow — coding standards, commit/PR conventions, and how a version bump and changelog entry are expected to look.

The integration suite (and the integration half of `composer coverage`) runs inside wp-env's **`tests-wordpress`** container, not `tests-cli`. `tests-cli`'s Imagick build registers zero coders at all (`Imagick::queryFormats()` returns an empty array — a documented Alpine issue), so any test touching image handling would fail there regardless of format; `tests-wordpress` (Debian, `wordpress:php8.3-apache`) has a fully working Imagick. Plugin activation still runs on `tests-cli`, since it's the only container with the `wp` CLI binary — both containers share the same WP database, so this split is safe.

### Coverage

```bash
composer coverage
# → coverage/combined/summary.txt
```

Combined unit + integration coverage is around 70% as of 2026-07-20 — re-run `composer coverage` locally for the current, exact figure rather than trusting this line as it ages.

Coverage is collected with pcov and merged from unit + integration Clover XMLs via `phpunit/phpcov`. `composer coverage` needs pcov in **two** places:

- **Inside `tests-wordpress`** — installed automatically by `composer coverage:setup` (runs as the first step of `coverage:run`). Lost on container rebuild; the script re-installs it idempotently every run.
- **On your host PHP** (the unit-test half runs directly via your local `php`, no container) — install manually, matched to your PHP version and CPU architecture:
  ```bash
  # Apple Silicon (arm64) — /opt/homebrew
  brew tap shivammathur/php
  brew install shivammathur/extensions/pcov@8.3   # match `php -v`

  # Intel — /usr/local
  arch -x86_64 brew install shivammathur/extensions/pcov@8.3
  ```
  Verify with `php -m | grep -i pcov`. If a machine has both an Intel and an Apple Silicon Homebrew install, check `which php` / `file $(which php)` first to be sure you're installing pcov for the one actually on your `PATH`. `merge-coverage.php` only checks that the unit/integration Clover files *exist*, not that they're fresh — if host pcov is missing, PHPUnit silently skips writing `coverage/unit/clover.xml` and the merge step will quietly reuse a stale one from a previous run instead of erroring.

## Architecture

```
agnosis.php              Plugin entry point, constants, autoloader
includes/
  Core/                  Plugin bootstrap, loader, activator
  Email/                 IMAP inbox, webhook handler, email parser
  AI/                    Pipeline, provider interface, value objects
  AI/Providers/          OpenAI, Anthropic, WordPressAI adapters
  Publishing/            PostCreator, ReviewEndpoints, RemovalEndpoints,
                         Notification, SubmissionsPage, GalleryOverview
  Artist/                Admission/vouching, artist profile & role
  Network/               Node identity, ActivityPub federation
  Admin/                 Tabbed settings page (WP Settings API)
  Compat/                LinguaForge integration
blocks/                  Gutenberg blocks (dynamic PHP render + frontend JS/CSS)
languages/               Translations (.pot/.po/.mo + compiled .l10n.php caches)
dev/                     Dev tooling (never ships in release zip)
tests/                   PHPUnit unit + integration suites
```

[`agnosis-theme`](https://github.com/leotiger/agnosis-theme) (the companion FSE block theme) is a separate sibling repository and zip, not a directory inside this one.

## Contributing

Bug reports, feature requests, and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for the dev environment setup, coding standards, and PR process.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the complete version history.

## License

[GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html)
