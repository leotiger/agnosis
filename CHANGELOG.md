# Changelog

All notable changes to Agnosis are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) —
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html)

## [0.1.5] — 2026-06-27

### Added
- **`Network\SubdomainRouter`** — wildcard subdomain routing (`artistx.agnosis.art` → artist-scoped WP install); resolves subdomain slug via `user_nicename` then `user_login`; rewrites `option_home` so all WP-generated URLs, canonicals, feeds and REST links point to the artist subdomain; scopes `pre_get_posts` to the artist's CPTs only; replaces site name in document title with artist display name; multi-level subdomains silently ignored
- **`agnosis_base_domain` setting** — configurable base domain (General tab) used by SubdomainRouter; defaults to empty (router inactive until set)
- **`SubdomainRouter::url_for_artist()`** — static helper to build the subdomain URL for any artist user ID; used by LinguaForge compat and future federation code
- **LinguaForge subfolder mode** (`Compat\LinguaForge`) — on artist subdomains, fires `linguaforge_url_mode` returning `'subfolder'` and `linguaforge_home_url` returning the artist subdomain URL, so LF generates language variant URLs as `artistx.agnosis.art/en/` rather than sub-subdomains; `set_language_meta` and `request_translations` now cover all Agnosis CPTs (`agnosis_artwork`, `agnosis_biography`, `agnosis_event`)

## [0.1.4] — 2026-06-27

### Added
- **Inbox admin page** (`Admin\InboxPage`) — top-level Agnosis menu with Inbox and Configuration submenus; replaces the former settings-embedded queue view
- **Attachment lightbox** — clickable file count button opens a full-screen overlay with keyboard navigation (← → Esc); data URIs served from base64-stored attachments; no jQuery or external dependencies
- **Per-row queue actions** — Process (pending/failed rows), Reprocess (published rows), Delete, and View Post buttons on each inbox row
- **`chat()` method on AI providers** — lightweight text-only completion (OpenAI `gpt-4o-mini` / Anthropic `claude-haiku-4-5-20251001` / WordPress AI Client) for tasks that don't require vision
- **AI-powered duplicate detection** (`PostCreator::find_duplicate_post()`) — after the pipeline runs, compares new submission title + tags against recent same-artist artwork posts using the cheap text model; merges into the existing post if a match is found
- **Image hash duplicate detection** — MD5 of each attachment's raw binary is stored on the attachment (`_agnosis_image_hash`) and mirrored to the artwork post; checked before the AI call for exact-match resends (most reliable signal, zero cost)
- **Series amplification workflow** — when an artist resubmits a series with old images + new ones, existing attachments are reused (no re-upload), and new images are appended to the existing gallery
- **Subject-line indicators** — `[Biography]` and `[Event]` prefixes in the email subject route submissions to dedicated CPTs; indicator is stripped before the clean subject is used as the post title; unknown indicators fall back to `agnosis_artwork`
- **`agnosis_biography` CPT** — singleton per artist; always updates the single existing biography post; no archive
- **`agnosis_event` CPT** — singleton per artist; always updates the single existing events page; no archive
- **`Pipeline::polish()`** — lightweight AI text pass (spelling + grammar only, no rewrites); used by singleton submissions when polishing is enabled
- **AI polish settings** — two checkboxes in the AI tab ("Polish biography with AI", "Polish events with AI"); off by default; when enabled the email body is polished before saving

### Changed
- **Status badge semantics** — queue status `published` now reflects actual WP post status: "Awaiting Review" (draft), "Live" (publish), or "Processed" (other); was always showing "Published" regardless of post state
- **Admin JS** — rewrote AI provider test from jQuery `$.post()` to vanilla `fetch()` + `URLSearchParams`; script dependency changed from `jquery` to `wp-util`
- **Post idempotency** — `PostCreator::create_post()` queries `_agnosis_queue_id` meta before inserting; reprocessing a queue row updates the existing post in-place instead of creating a duplicate

### Fixed
- `is_already_queued()` SELECT failed on tables missing the `post_id` column (added in 0.1.3), causing `get_row()` to return null and every message to re-enqueue
- `enqueue()` INSERT silently no-oped when `is_already_queued()` false-returned for existing rows; replaced with `INSERT IGNORE` + follow-up SELECT for true idempotency
- Binary attachment data corrupted by `wp_json_encode()` — function runs UTF-8 sanitisation which destroys JPEG/PNG bytes; attachments are now base64-encoded before JSON storage and decoded in `PostCreator::handle()`
- Empty artwork posts (title only, no body/tags/images) caused by the above corruption silently failing the AI pipeline
- `wp_tempnam()` / `wp_handle_sideload()` undefined in cron and `admin-post` contexts — added `require_once ABSPATH . 'wp-admin/includes/file.php'` before first use
- Settings page asset enqueue hook corrected to `agnosis_page_agnosis-settings` after submenu restructure (was `toplevel_page_agnosis-settings`)
- `wp_get_post_tags()` return typed as `array|WP_Error`; guarded with `is_array()` before passing to `implode()`

## [0.1.2] — 2026-06-26

### Fixed
- PHPCS: exclude noisy Squiz.Commenting sub-sniffs (`MissingReturn`, `InvalidReturn`, `IncorrectParamVarName`, `ParamNameNoMatch`, `BlockComment.SingleLine`, `InlineComment.DocBlock`, etc.)
- PHPCS: exclude `Universal.WhiteSpace.PrecisionAlignment` and `WordPress.WhiteSpace.OperatorSpacing.SpacingBefore` to allow column alignment
- PHPCS: exclude `WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_{encode,decode}` — legitimately used for image data and SVG icons
- PHPCS: add test-specific relaxations for `AlternativeFunctions`, `NoSilencedErrors`, `GlobalVariablesOverride`, `EscapeOutput`
- PHPCS: set `basepath=..` in phpcs.xml so phpcbf writes fixed files to the correct path (was doubling `dev/` into the output path)
- `Artist\Admission`: `user_can( $user, 'administrator' )` changed to `manage_options` capability (role name is not a valid capability)
- `Core\Activator`: suppress `CronInterval` sniff — 5-minute poll interval is intentional
- `Email\Inbox`: suppress `NoSilencedErrors` on `@imap_open` — notices are suppressed intentionally, return value is the error signal
- `Compat\LinguaForge`: move `phpcs:ignore` to the string line where the hook-name violation fires
- Dev: add `.eslintrc.json` and `.stylelintrc.json` matching lingua-forge conventions
- Dev: add `--no-error-on-unmatched-pattern` / `--allow-empty-input` to lint scripts so they pass with no `src/` files present
- Dev: exclude `coverage/` from ESLint and stylelint to prevent generated PHPUnit HTML from being linted
- **`Admin\Settings`**: settings cross-tab data loss — saving any one tab was silently zeroing out all other tabs. Root cause: all fields shared a single `agnosis_options` group; `options.php` iterates every option in the group and writes empty for fields absent from the POST body. Fix: each tab now owns its own option group (`agnosis_general_options`, `agnosis_email_options`, etc.)

## [0.1.1] — 2026-06-26

### Changed
- Agnosis settings promoted to its own top-level admin sidebar entry (was nested under Settings → Agnosis)

## [0.1.0] — 2026-06-26

### Added
- Plugin entry point (`agnosis.php`) with PHP/WP version gate and PSR-4 fallback autoloader
- Email ingestion layer: IMAP polling (`Email\Inbox`) and webhook push (`Email\Webhook`)
- Email parser (`Email\Parser`) — extracts sender, description, and attachments; resolves WP user by email
- AI pipeline (`AI\Pipeline`) with pluggable provider interface (`AI\ProviderInterface`)
- AI providers: OpenAI GPT-4o Vision + gpt-image-1 (`AI\Providers\OpenAI`), Anthropic Claude Vision (`AI\Providers\Anthropic`), Stability AI upscaling (`AI\Providers\StabilityAI`)
- Value objects: `AI\DescriptionResult`, `AI\EnhancementResult`
- Auto-publishing (`Publishing\PostCreator`) — creates `agnosis_artwork` CPT posts with Gutenberg gallery blocks
- Artist admission and vouching system (`Artist\Admission`)
- Artist profile management (`Artist\Profile`) — `agnosis_artist` custom role with `read` + `agnosis_artist` caps
- ActivityPub federation (`Network\ActivityPub`) — Mastodon-compatible actor, inbox/outbox endpoints, WebFinger
- Node identity (`Network\Node`) — RSA key pair, `/.well-known/agnosis-node` discovery, peer registration
- Tabbed admin settings page (`Admin\Settings`) — General, Email Inbox, AI Providers, Network, Commerce tabs
- `Core\Activator` — DB table creation, option seeding, role registration, rewrite flush on activate/deactivate
- `Core\Plugin` — singleton bootstrap, action/filter loader, `init`-time role guard
- LinguaForge compatibility layer (`Compat\LinguaForge`)
- Dev tooling: PHPUnit 9.6 unit + integration suites, PHPCS (WordPress Coding Standards), PHPStan, pcov coverage pipeline (`composer coverage`), `wp-env` integration environment
- Combined Clover coverage report via `phpunit/phpcov` merge

[0.1.5]: https://github.com/agnosis/agnosis/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/agnosis/agnosis/compare/v0.1.2...v0.1.4
[0.1.2]: https://github.com/agnosis/agnosis/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/agnosis/agnosis/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/agnosis/agnosis/releases/tag/v0.1.0
