# ✦ Agnosis

> Art blooming out of oblivion. Email your art, AI polishes it, the world sees it.

Agnosis is a free, federated WordPress plugin for independent artists. Artists who are great at creating — but not at promoting — send an email with their artwork. Agnosis handles the rest.

## How it works

1. **Receive** — artist emails to a dedicated address (IMAP or webhook); separate endpoints for `submit@`, `bio@`, `event@`, `replace@`, and `remove@`
2. **Enhance** — images with low photo quality scores are corrected via AI (OpenAI / Stability AI); good photographs are left untouched
3. **Describe** — title, description, tags, and medium category are written by AI (Claude / GPT-4o Vision)
4. **Review** — artist receives an email with a preview; one click to publish, edit, or discard
5. **Publish** — a gallery post is created automatically as an `agnosis_artwork`
6. **Broadcast** — the post is federated to Mastodon, Pixelfed and the wider Fediverse via ActivityPub

## Features

- **Email-to-post** — IMAP polling or webhook push (Mailgun, SendGrid, Postmark); dedicated addresses for artwork, biography, events, replacement, and removal
- **AI pipeline** — pluggable provider interface; OpenAI, Anthropic, and Stability AI out of the box
- **Photo quality gate** — vision AI scores each photograph; enhancement only runs below a configurable threshold; targeted correction from detected issues
- **Medium taxonomy** — 8 canonical terms (Oil, Acrylic, Watercolour, Drawing, Digital, Photography, Sculpture, Mixed Media); AI assigns one per artwork; seeded on activation
- **Artist review workflow** — draft posted, artist reviews via email link (approve / edit / discard)
- **Artist-driven removal** — `remove@` email triggers a signed confirmation link; artist trashes their own artwork; no admin needed
- **Community admission** — artists vouch for new artists; no committees, no gatekeepers
- **ActivityPub federation** — each installation is a Fediverse actor; peers discover each other via `/.well-known/agnosis-node`
- **Node identity** — RSA key pair per node; signed peer-to-peer communication
- **Commerce** — configurable transaction fee on donations and art sales; always free for artists
- **Agnosis Theme** — companion FSE block theme with black-and-white palette, CPT templates, and a gallery overview block
- **Zero runtime dependencies** — no Composer packages ship in the plugin zip

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.4 |
| PHP | 8.1 |
| MySQL | 5.7 / MariaDB 10.3 |

## Installation

```bash
# From a release zip
wp plugin install agnosis-0.1.8.zip --activate

# Or from source
git clone https://github.com/agnosis/agnosis wp-content/plugins/agnosis
wp plugin activate agnosis
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
```

### Coverage

```bash
composer coverage
# → coverage/combined/summary.txt
```

Coverage is collected with pcov and merged from unit + integration Clover XMLs via `phpunit/phpcov`.

## Architecture

```
agnosis.php              Plugin entry point, constants, autoloader
includes/
  Core/                  Plugin bootstrap, loader, activator
  Email/                 IMAP inbox, webhook handler, email parser
  AI/                    Pipeline, provider interface, value objects
  AI/Providers/          OpenAI, Anthropic, Stability AI adapters
  Publishing/            PostCreator, ReviewEndpoints, RemovalEndpoints,
                         Notification, SubmissionsPage, GalleryOverview
  Artist/                Admission/vouching, artist profile & role
  Network/               Node identity, ActivityPub federation
  Admin/                 Tabbed settings page (WP Settings API)
  Compat/                LinguaForge integration
agnosis-theme/           Companion FSE block theme (separate zip)
dev/                     Dev tooling (never ships in release zip)
tests/                   PHPUnit unit + integration suites
```

## License

[GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html)
