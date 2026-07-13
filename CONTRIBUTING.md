# Contributing to Agnosis

Thanks for taking the time to contribute. This document covers how to report bugs, propose features, and submit code changes.

## Table of contents

- [Ways to contribute](#ways-to-contribute)
- [Before you start](#before-you-start)
- [Development setup](#development-setup)
- [Project structure](#project-structure)
- [Coding standards](#coding-standards)
- [Testing](#testing)
- [Making changes](#making-changes)
- [Changelog and readme conventions](#changelog-and-readme-conventions)
- [Submitting a pull request](#submitting-a-pull-request)
- [Reporting security issues](#reporting-security-issues)
- [License](#license)

## Ways to contribute

- **Bug reports** — open an issue. Include your WordPress/PHP version, the relevant Agnosis settings (Email driver, which AI providers are configured), steps to reproduce, and what you expected vs. what happened. Log excerpts from Settings → Logs are usually more useful than a screenshot.
- **Feature requests** — open an issue describing the problem you're trying to solve, not just the feature you have in mind. The "why" helps evaluate whether it fits the plugin's scope (a free, federated, email-driven publishing network for artists) before anyone writes code.
- **Translations** — the plugin is fully translatable (`Text Domain: agnosis`, `.pot`/`.po`/`.mo` under `languages/`). Contributing or improving a translation is welcome even without touching any code — see `composer make-pot` / `composer compile-pos` in [Development setup](#development-setup) if you're regenerating source strings.
- **Code** — bug fixes and features via pull request. For anything nontrivial, open an issue first to agree on the approach before investing time in an implementation.

## Before you start

Agnosis has two companion repositories that its dev environment expects as **sibling directories** (same parent folder as `agnosis/`):

- [`lingua-forge`](https://github.com/leotiger/lingua-forge) — the multi-language plugin Agnosis integrates with (`includes/Compat/LinguaForge.php`). Referenced by `dev/.wp-env.json` and required for the integration test suite to activate cleanly. As of 0.9.22 this is also a hard runtime dependency, not just a dev/test convenience: `agnosis.php` declares `Requires Plugins: lingua-forge`, so WordPress itself refuses to activate Agnosis on any site (including a wp-env test instance) until Lingua Forge is installed and active.
- `agnosis-theme` — the companion FSE block theme. Also referenced by `dev/.wp-env.json`.

If you don't have access to one or both, say so in your pull request — a maintainer can run the full integration suite before merging. Unit tests, PHPCS, and PHPStan (`composer qa`, see below) don't need either sibling and cover most contributions on their own.

## Development setup

**Prerequisites:** PHP 8.1+, Composer, Node.js, and Docker (for `wp-env`).

```bash
# Clone alongside its companion repos (see "Before you start")
git clone https://github.com/leotiger/agnosis
git clone https://github.com/leotiger/lingua-forge   # optional, for integration tests
# ...and agnosis-theme similarly, if you have access

cd agnosis/dev
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

Run `composer qa` from `dev/` before opening a PR — it's the fastest single check that mirrors what CI (or a maintainer, if CI isn't wired up yet) will run.

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

## Submitting a pull request

- Describe **what** changed and **why** — link the issue it addresses if one exists.
- Confirm `composer qa` passes (and integration tests, if you were able to run them).
- Include a `CHANGELOG.md` entry (see above) for any user-facing change.
- Don't bump the version number in `agnosis.php`/`readme.txt` yourself — a maintainer does this at merge time, alongside trimming `readme.txt`'s changelog.
- Be ready to answer questions about non-obvious choices — given this codebase's docblock conventions, "why did you do it this way" is a completely normal review question, not a sign something's wrong.

## Reporting security issues

Please **don't** open a public issue for a security vulnerability. Use GitHub's private [Security Advisory](https://github.com/leotiger/agnosis/security/advisories/new) reporting on this repository instead, so a fix can be prepared before the details are public.

## License

Agnosis is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html). By contributing, you agree your contributions are licensed under the same terms.
