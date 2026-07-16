# ✦ Agnosis

> Art blooming out of oblivion. Email your art, AI polishes it, the world sees it.

Agnosis is a free, federated WordPress plugin for independent artists. Artists who are great at creating — but not at promoting — send an email with their artwork. Agnosis handles the rest.

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
- **Commerce (planned)** — a future revenue layer of optional visitor donations and art sales, with a configurable transaction fee, is planned and partly scaffolded in Settings → Commerce; the donation/store mechanism itself hasn't been built yet, so running a node has no revenue feature to enable today. Always free for artists to participate
- **Agnosis Theme** — companion FSE block theme with black-and-white palette, CPT templates, and a gallery overview block
- **Composer dependencies** — the plugin ZIP ships `webklex/php-imap` (the IMAP intake transport) along with its own dependency tree (`illuminate/*`, `symfony/*`, `nesbot/carbon`, `doctrine/inflector`, `voku/portable-ascii`) — all MIT-licensed, GPL-compatible. These currently ship un-prefixed (a namespace-collision risk if another active plugin bundles a different version of the same packages); prefixing the tree (or replacing webklex with a slimmer IMAP layer) is a planned pre-1.0.0 item — see CHANGELOG.md

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
wp plugin activate lingua-forge agnosis
```

Then open **Agnosis** in the admin sidebar and configure your email inbox and AI API keys.

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
agnosis-theme/           Companion FSE block theme (separate zip)
dev/                     Dev tooling (never ships in release zip)
tests/                   PHPUnit unit + integration suites
```

## Contributing

Bug reports, feature requests, and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for the dev environment setup, coding standards, and PR process.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the complete version history.

## License

[GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html)
