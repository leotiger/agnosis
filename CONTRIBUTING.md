# Contributing to Agnosis

Thanks for taking the time to contribute. This document covers how to report bugs, propose features, and submit code changes. Participation in this project is governed by the [Code of Conduct](CODE_OF_CONDUCT.md).

## Table of contents

- [Ways to contribute](#ways-to-contribute)
- [Before you start](#before-you-start)
- [Development setup](#development-setup)
- [Project structure](#project-structure)
- [Coding standards](#coding-standards)
- [Testing](#testing)
- [Making changes](#making-changes)
- [Changelog and readme conventions](#changelog-and-readme-conventions)
- [Translation (i18n) workflow](#translation-i18n-workflow)
- [Submitting a pull request](#submitting-a-pull-request)
- [Release process](#release-process)
- [Reporting security issues](#reporting-security-issues)
- [License](#license)

## Ways to contribute

- **Bug reports** — open an issue. Include your WordPress/PHP version, the relevant Agnosis settings (Email driver, which AI providers are configured), steps to reproduce, and what you expected vs. what happened. Log excerpts from Settings → Logs are usually more useful than a screenshot.
- **Feature requests** — open an issue describing the problem you're trying to solve, not just the feature you have in mind. The "why" helps evaluate whether it fits the plugin's scope (a free, federated, email-driven publishing network for artists) before anyone writes code.
- **Translations** — the plugin is fully translatable (`Text Domain: agnosis`, `.pot`/`.po`/`.mo` under `languages/`). Contributing or improving a translation is welcome even without touching any code — see `composer make-pot` / `composer compile-pos` in [Development setup](#development-setup) if you're regenerating source strings.
- **Code** — bug fixes and features via pull request. For anything nontrivial, open an issue first to agree on the approach before investing time in an implementation.

## Before you start

Agnosis has two companion repositories that its dev environment expects as **sibling directories** (same parent folder as `agnosis/`):

- [`lingua-forge`](https://github.com/leotiger/lingua-forge) — the multi-language plugin Agnosis integrates with (`includes/Compat/LinguaForge.php`). Referenced by `dev/.wp-env.json` and required for the integration test suite to activate cleanly. As of 0.9.22 this is also a hard runtime dependency, not just a dev/test convenience: `agnosis.php` declares `Requires Plugins: lingua-forge`, so WordPress itself refuses to activate Agnosis on any site (including a wp-env test instance) until Lingua Forge is installed and active. Public, with its own [README](https://github.com/leotiger/lingua-forge#readme), [CONTRIBUTING.md](https://github.com/leotiger/lingua-forge/blob/main/CONTRIBUTING.md), [SECURITY.md](https://github.com/leotiger/lingua-forge/blob/main/SECURITY.md), and a self-hosted release/update channel (not distributed via the WordPress.org directory — see its README for why).
- [`agnosis-theme`](https://github.com/leotiger/agnosis-theme) — the companion FSE block theme. Also referenced by `dev/.wp-env.json`. Public, with its own [README](https://github.com/leotiger/agnosis-theme#readme), [CONTRIBUTING.md](https://github.com/leotiger/agnosis-theme/blob/main/CONTRIBUTING.md), and release ZIPs.

Unit tests, PHPCS, and PHPStan (`composer qa`, see below) don't need either sibling and cover most contributions on their own.

## Development setup

**Prerequisites:** PHP 8.2+, Composer, Node.js, and Docker (for `wp-env`).

```bash
# Clone alongside its companion repos (see "Before you start")
git clone https://github.com/leotiger/agnosis
git clone https://github.com/leotiger/lingua-forge   # required for wp-env to activate Agnosis at all; see above
git clone https://github.com/leotiger/agnosis-theme  # optional, for integration tests

cd agnosis
composer install   # builds vendor-prefixed/ — dev/bootstrap.php requires it for
                    # Inbox.php/Parser.php's prefixed webklex/php-imap classes,
                    # even for the WordPress-free unit suite (C-item/§4e)

cd dev
npm install
composer install

# Start the wp-env test environment (WordPress + a tests-cli and tests-wordpress container)
npm run env:start
```

Useful scripts from `dev/`:

```bash
composer lint            # PHPCS — WordPress Coding Standards
composer lint:fix        # phpcbf — auto-fix what it can
composer analyse          # PHPStan, level 7
composer test:unit        # Unit suite — no WordPress needed
composer test:integration # Integration suite — requires npm run env:start
composer coverage         # Combined unit + integration coverage report
composer qa                # lint + analyse + test:unit + JS/CSS lint — the pre-PR gate

npm run lint:js           # ESLint (blocks/ source)
npm run lint:css          # Stylelint
npm run test:e2e          # Playwright end-to-end tests
```

Run `composer qa` from `dev/` before opening a PR — it's exactly what the [`QA` GitHub Actions workflow](.github/workflows/qa.yml) runs automatically on every PR (PHP 8.2/8.3 matrix), so running it locally first catches anything CI would flag before you push. CI runs the unit half only — the integration suite needs Docker plus the two companion repos as sibling checkouts, which a public runner doesn't have; integration stays maintainer-run, per [Before you start](#before-you-start).

## Project structure

See the [Architecture](README.md#architecture) section of the README for the `includes/` layout. A few things specific to contributing:

- `agnosis.php` — plugin entry point, header, and the `AGNOSIS_VERSION` constant.
- `includes/` — all plugin source, PSR-4 autoloaded under the `Agnosis\` namespace, one class per concern (e.g. `Agnosis\Artist\ContactForm`, `Agnosis\Publishing\PostCreator`). Each class that needs WordPress hooks exposes a `register_hooks()` method, wired up once in `includes/Core/Plugin.php`.
- `blocks/` — Gutenberg block source (dynamic PHP render callbacks + minimal frontend JS/CSS).
- `tests/php/Unit/` and `tests/php/Integration/` — PHPUnit, mirroring the `includes/` namespace structure. Unit tests run with no WordPress bootstrap; integration tests run inside the `tests-wordpress` wp-env container against a real WordPress instance.
- `dev/` — everything above (tooling, tests config, `node_modules`) never ships in the release zip (`.distignore` excludes it).

## Coding standards

- **PHP**: tabs for indentation (see `.editorconfig`), `declare(strict_types=1)` at the top of every file, [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards) enforced via `dev/phpcs.xml`, and [PHPStan](https://phpstan.org/) at level 7 (`dev/phpstan.neon`). Run `composer lint` and `composer analyse` before pushing.
- **Docblocks matter here.** This codebase leans heavily on docblocks to explain *why* code does something non-obvious — a past audit finding, a bug it fixes, a deliberate trade-off — not just a restatement of the method signature. When you fix a bug or make a non-obvious choice, write down the reasoning; a future contributor (quite possibly you) will need it more than a one-line "what."
- **JS/CSS**: `wp-scripts`' bundled ESLint/Stylelint configs (`dev/.eslintrc.json`, `dev/.stylelintrc.json`). Run `npm run lint:js` / `npm run lint:css`.
- **`typescript` is a devDependency despite this codebase having zero TypeScript code.** `@wordpress/eslint-plugin`'s `recommended` config (which `dev/.eslintrc.json` extends) pulls in `@typescript-eslint`/`ts-api-utils` regardless of whether any `.ts` file exists, and `ts-api-utils` is version-sensitive about the `typescript` it finds — with no explicit pin, a fresh `npm install` resolves whatever's newest (TypeScript 7.x as of 2026-07-18), well past `@typescript-eslint`'s own `<6.1.0` peer ceiling, and crashes ESLint outright (`Cannot read properties of undefined (reading 'Intrinsic')` in `ts-api-utils`) rather than failing to install or warning cleanly. Pinned to `^5.9.3` — comfortably inside every constraint in the chain — specifically to stop `npm install` from silently drifting onto an incompatible major again. `dev/package-lock.json` is gitignored (deliberately — see `dev/.gitignore`), so this pin in `package.json` is the only thing anchoring the resolution; there is no committed lockfile to fall back on if it drifts again.
- **`blocks/*/view.js` files are real ES modules** (`import`/`export`), unlike every other plain IIFE `editor.js`/`frontend.js` in this codebase — required by WordPress's Script Modules API for a block's `viewScriptModule` (block.json), which is how the Interactivity API (`@wordpress/interactivity`, `@wordpress/interactivity-router`) is consumed. `dev/.eslintrc.json`'s own `parserOptions.sourceType` is `"script"` project-wide by deliberate convention (matches every other block script's ES5-ish, no-build-step style) and ESLint's legacy config format can't scope an `overrides` block to files living outside `dev/`'s own directory tree (`blocks/` is a sibling, not a descendant) — so `lint:js`/`lint:js:fix` in `dev/package.json` run ESLint twice: once over everything except `blocks/*/view.js` with the project-wide script config, and once over just `blocks/*/view.js` with `--parser-options=sourceType:module` passed on the CLI. `@wordpress/interactivity`/`@wordpress/interactivity-router` are dev-only devDependencies purely so `eslint-plugin-import` can resolve their named exports — WordPress itself supplies the real modules at runtime via its own import map; nothing from `node_modules` is ever bundled or shipped (this plugin has no build step).
- Zero runtime Composer dependencies ship in the plugin zip beyond what's declared in the root `composer.json` (currently just `webklex/php-imap`) — anything dev-only belongs in `dev/composer.json`, not the plugin root.

## Testing

- **Unit tests** (`tests/php/Unit/`) run directly against your local PHP, no WordPress required. Fast — this is where most logic (parsing, formatting, pure functions) should be covered.
- **Integration tests** (`tests/php/Integration/`) run inside wp-env's `tests-wordpress` container (Debian, PHP 8.3, a working Imagick build) rather than `tests-cli` (Alpine's Imagick registers zero coders — any image-handling test would fail there regardless of format). Both containers share the same WP database; plugin activation still runs on `tests-cli` since it's the only one with the `wp` CLI binary.
- New code that touches WordPress APIs (hooks, `$wpdb`, post/meta/taxonomy handling) needs an integration test, not just a unit test. New code that's pure logic can usually be unit-tested more cheaply.
- If your change fixes a bug, add a regression test for it where practical — several classes in this codebase (`ContactForm`, `PostCreator`, `ReviewEndpoints`) have gaps that were only found because a *previous* fix lacked one.
- `composer coverage` needs `pcov` installed both inside the `tests-wordpress` container (`composer coverage:setup` does this automatically) and on your host PHP — see the [Coverage](README.md#coverage) section of the README for platform-specific install notes.

## Making changes

1. Fork the repo and create a branch off `main` for your change.
2. Keep pull requests focused — one bug fix or one feature per PR is much easier to review than a bundle of unrelated changes.
3. Write commit messages in the imperative mood ("Fix X", not "Fixed X" or "Fixes X"), and explain *why* in the body when the *what* isn't self-evident from the diff.
4. Run `composer qa` (and `composer test:integration` if you have the companion repos set up) before opening the PR.
5. Update `CHANGELOG.md` as part of your PR — see the next section for the expected format.

## Changelog and readme conventions

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/). When your PR changes behavior:

- Add an entry under the **top** version heading, in an `### Added` / `### Changed` / `### Fixed` section as appropriate. If there's no in-progress version heading yet, ask in your PR rather than inventing a version number — **version bumps are handled by a maintainer at merge time**, not by contributors.
- Entries in this project tend to be a bold one-line summary followed by the full reasoning — what was broken or missing, why, and what changed — closing with the affected file paths in backticks, e.g.:
  ```markdown
  - **Short bold summary of the fix.** Fuller explanation of the bug/gap, why it mattered, and what the fix actually does. (`includes/Path/To/File.php`)
  ```
  This is more verbose than a typical changelog, deliberately — it's meant to be readable months later without needing to dig through the diff or the original issue.
- `readme.txt` (the WordPress.org-format readme) keeps only the **two most recent** version blocks in its `== Changelog ==` section, plus a pointer line to `CHANGELOG.md` for full history — trim the oldest block when a maintainer adds a new one. Its entries are shorter and user-facing (`* Added: ...` / `* Fixed: ...`, plain text, no markdown), aimed at someone reading it in the Plugins screen, not a developer reading a diff.
- `docs/agnosis-update-manifest.php` — the self-hosted update endpoint's source (deployed separately to `agnosis.art` by a maintainer) — gets the **same treatment on every version bump**: `$version`, `$download_url`, and `$last_updated` updated, and a new `<h4>X.Y.Z</h4>` block prepended to `$changelog`, trimmed to the two most recent releases (same two-version rule as `readme.txt`, stated in the file's own comment). This file went eleven versions stale before an audit caught it (§4b, `AUDIT-1.0.0.md`) — `dev/bin/build-zip.sh` already automates the `$sha256` field at build time, but `$version`/`$download_url`/`$last_updated`/`$changelog` are deliberately still hand-edited (its own docblock explains why), so they only stay current if this step is actually done as part of the bump, not treated as optional.

## Translation (i18n) workflow

When a PR changes any translatable string (adds, edits, or removes one), the `.po`/`.mo`/`.l10n.php` files need to be re-synced as part of the release, not left to drift — a stale sync went unnoticed for two release cycles before an audit caught it (§6b, `AUDIT-0.9.38.md`: 90 wrong-match fuzzy entries, 324 untranslated strings, `.mo` files older than their `.po` source). Release checklist:

1. `bash dev/bin/make-pot.sh` — regenerates `languages/agnosis.pot` from source and `msgmerge`s it into every locale's `.po`. This step **automatically clears any fuzzy matches** it introduces (via `dev/bin/clear-fuzzy.awk`) — see the policy note below.
2. Translate the newly-blanked strings for each locale (Loco Translate, or hand-editing the `.po`).
3. `bash dev/bin/compile-pos.sh` — compiles every `.po` into `.mo` (WP < 6.5) and `.l10n.php` (WP 6.5+). Refuses to run if any fuzzy entry is still present in a `.po` (defense in depth — `msgfmt`/`make-php` would otherwise silently skip it).
4. `bash dev/bin/compile-pos.sh --check` — staleness guard, no compiling: exits non-zero if any `.mo`/`.l10n.php` is older than its `.po`. Run this before tagging a release; it's cheap enough to also be a CI step.

**Never leave a fuzzy-flagged entry in a `.po` file.** `msgmerge` marks an entry fuzzy when it guesses a translation from a similar old string rather than carrying over a confirmed match, and the guess is often wrong — a "translated-looking" string that's actually incorrect is worse than an honestly-untranslated one, because nothing flags it for review. `.mo`/`.l10n.php` compilation already skips fuzzy entries (so nothing wrong ever reaches a live site), but the `.po` source itself should never carry one either: `make-pot.sh` clears them automatically, and `compile-pos.sh` refuses to compile if it finds one anyway. If you ever run `msgmerge` by hand outside `make-pot.sh`, run `awk -f dev/bin/clear-fuzzy.awk file.po > file.po.new && mv file.po.new file.po` afterward.

## Submitting a pull request

- Describe **what** changed and **why** — link the issue it addresses if one exists.
- Confirm `composer qa` passes (and integration tests, if you were able to run them).
- Include a `CHANGELOG.md` entry (see above) for any user-facing change.
- Don't bump the version number in `agnosis.php`/`readme.txt` yourself — a maintainer does this at merge time, alongside trimming `readme.txt`'s changelog.
- Be ready to answer questions about non-obvious choices — given this codebase's docblock conventions, "why did you do it this way" is a completely normal review question, not a sign something's wrong.

## Release process

Maintainer-only — documented here so it isn't tribal knowledge (audit §7a, `AUDIT-0.9.38.md`).

1. Bump the version: `agnosis.php` (`Version:` header and `AGNOSIS_VERSION` constant), `readme.txt` (`Stable tag:`, plus trim its changelog to the two most recent releases), `docs/agnosis-update-manifest.php` (`$version`), per [Changelog and readme conventions](#changelog-and-readme-conventions).
2. If any translatable string changed, run the [Translation (i18n) workflow](#translation-i18n-workflow) above and finish with `bash dev/bin/compile-pos.sh --check`.
3. `composer build-zip` (from `dev/`) — packages the plugin per `.distignore`, and handles `docs/agnosis-update-manifest.php`'s `$sha256` field itself (cleared at the start of the run, recomputed once the zip is built, so a failed run never leaves a stale digest behind). Output lands in `../agnosis-deploy/agnosis-<version>.zip`.
4. Tag the release (`vX.Y.Z`) and create a GitHub Release, attaching the zip from step 3.
5. Confirm `$version`/`$download_url`/`$last_updated` in `docs/agnosis-update-manifest.php` match the release just created, then deploy that file to `wp-content/mu-plugins/` on `agnosis.art` — it's what every installed site's own update check reads. This file going stale for eleven versions once, unnoticed, is exactly what made it a standing "update every bump" rule (§4b, `AUDIT-1.0.0.md`).

## Reporting security issues

See [SECURITY.md](SECURITY.md) — please **don't** open a public issue for a security vulnerability.

## License

Agnosis is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html). By contributing, you agree your contributions are licensed under the same terms.
