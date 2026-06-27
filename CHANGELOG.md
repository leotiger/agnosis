# Changelog

All notable changes to Agnosis are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) —
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html)

## [0.1.7] — 2026-06-27

### Fixed
- **`agnosis/gallery-overview` block not rendering in FSE template** — block was registered with `"render": "file:./render.php"` in `block.json`, which routes output through WordPress's own `ob_start()/ob_get_clean()` wrapper. Our `render.php` also used `ob_start()/return ob_get_clean()`, creating nested buffering: our inner buffer captured the HTML while WP's outer buffer saw nothing, causing the block to emit an empty string. Fix: removed the `"render"` key from `block.json` and replaced it with a PHP `render_callback` (`GalleryOverview::render_block()`) that owns the `ob_start()/ob_get_clean()` pair and `include`s `render.php` directly. `render.php` now outputs HTML directly; early exits use bare `return`
- **`editor.js` used ES module imports and JSX** — the file required a bundler (webpack/esbuild) to run but no build step existed; WordPress would reject the raw file in the block editor. Rewrote as an IIFE using `window.wp.*` globals (`wp.blocks`, `wp.element`, `wp.blockEditor`, `wp.components`, `wp.i18n`) — identical pattern to the project's other blocks; no build step needed
- **Plugin Check: `InputNotSanitized` on `$_GET`/`$_POST` reads** — `(int)` cast is not on WPCS's recognised sanitisation function list; replaced all affected reads with `absint( wp_unslash( ... ) )` (`queue_id`, `reprocessed`, `enqueued`, `polled` in `InboxPage` and `Plugin`)
- **Plugin Check: `NoCaching` on gallery-overview direct DB query** — `phpcs:ignore` comment was missing `NoCaching`; added alongside the existing `DirectQuery` suppression (result is cached via `wp_cache_set()` immediately after)
- **Plugin Check: `SchemaChange` on `ALTER TABLE` in `Activator`** — `phpcs:ignore` only covered the `$wpdb->query(` line; `SchemaChange` fires on the SQL string line. Collapsed each call to a single line so one ignore comment covers the whole statement
- **Plugin Check: `NonPrefixedVariableFound`** in `render.php` — replaced suppression (`phpcs:disable`) with proper `agnosis_`-prefixed variable names throughout the entire file
- **Plugin Check: `missing_direct_file_access_protection`** in `render.php` — added `if ( ! defined( 'ABSPATH' ) ) exit;` guard
- **Plugin Check: `OutputNotEscaped` on `$columns`** — changed to `(int) $agnosis_columns`
- **Plugin Check: `srand()` discouraged / `date()` timezone-unsafe** — replaced `srand() + shuffle()` with a `usort()` using `crc32()` as the sort key (stable daily order without PRNG state); replaced `date()` with `gmdate()`
- **Plugin Check: `$_GET` inputs missing `wp_unslash()`** — `agnosis_medium` and `agnosis_overview_page` now go through `wp_unslash()` before sanitisation
- **Plugin Check: heredoc syntax** in `Settings::ai_test_js()` and `PostCreator::find_duplicate_post()` — converted to `sprintf()` string construction
- **Plugin Check: `$_SERVER['HTTP_HOST']` missing `wp_unslash()`** in `SubdomainRouter` — now uses `sanitize_text_field( wp_unslash( ... ) )`
- **Plugin Check: `INSERT IGNORE` `NoCaching` in `Inbox`** — added `NoCaching` to existing phpcs:ignore

## [0.1.6] — 2026-06-27

### Added
- **Photo quality detection** — the vision AI now returns a `photo_quality` object (`score` 1–10 + `issues` array) as part of every description call; no extra API cost — the model assesses the photograph while analysing the artwork
- **Conditional image enhancement** — enhancement only runs when the photo quality score falls below a configurable threshold (default 7); good photographs are left untouched; the enhancement prompt is built from the specific detected issues ("underexposed shadows", "camera blur") rather than a generic instruction, keeping corrections targeted and the artwork itself unaltered
- **`agnosis-email` image size** — 420 px wide, proportional (no crop); used in artist notification emails; configurable via Settings → Behaviour
- **Full gallery in review email** — all submitted images (from `_agnosis_gallery_ids`) are rendered at `agnosis-email` size and stacked vertically in the notification; previously only the featured image was shown; falls back to featured image on legacy posts
- **Enhancement notice in review email** — when enhancement ran, the artist's email includes a panel listing the photographic issues that were corrected and reassuring them the artwork itself was not altered
- **Quality score post meta** — `_agnosis_photo_quality_score`, `_agnosis_photo_quality_issues`, `_agnosis_enhanced` stored on every artwork post for future admin display and reporting
- **Enhancement threshold setting** — "Enhancement threshold" field in Settings → AI Providers; score below threshold triggers enhancement; set to 1 to disable automatic enhancement
- **`agnosis-artwork` image size** — width-constrained (default 1920 px), height scales proportionally; used in post content blocks, lightbox, ActivityPub federation, and LinguaForge sharing; WP generates `srcset` automatically
- **`agnosis-thumb` image size** — 512 × 512 px square hard-crop (centred); used in submission cards and dashboard
- **Image size settings** (Behaviour tab) — "Artwork display width", "Thumbnail size", and "Email image width" let admins tune all three sizes without code changes; existing uploads need regeneration after a change
- **Dedicated email endpoints** — routing is now by `To:` header rather than subject-line indicators; five addresses configurable in Settings → Email: `submit@` (artwork), `bio@` (biography), `event@` (event), `replace@` (explicit artwork replacement), `remove@` (takedown request); subject-line indicators remain as a backward-compatible fallback
- **`replace@` endpoint** — skips AI fuzzy duplicate detection entirely; finds the existing post by exact title match across draft/pending/publish statuses and updates it in-place; intended for artists who want a deliberate replacement without ambiguity
- **`agnosis_medium` taxonomy** — 8 canonical terms seeded on activation (Oil, Acrylic, Watercolour, Drawing, Digital, Photography, Sculpture, Mixed Media); AI picks exactly one from the canonical list; term is validated against `PromptConfig::CANONICAL_MEDIUMS` before `wp_set_object_terms()` to prevent hallucinated entries creating rogue taxonomy terms
- **Medium in AI pipeline** — `DescriptionResult` carries a `medium` field; all three providers (OpenAI, Anthropic, WordPress AI) parse and return it; `PromptConfig` exposes `CANONICAL_MEDIUMS` and injects the list into the system prompt via `{medium_list}` substitution
- **Artist-driven removal workflow** — `remove@` email triggers `PostCreator::handle_removal_request()`: generates a signed `_agnosis_removal_token` (7-day expiry), stores the artist's stated reason in `_agnosis_removal_reason`, fires `do_action('agnosis_removal_requested')`; `Notification::on_removal_requested()` sends a confirmation email with a single signed "Yes, remove this artwork" button; `RemovalEndpoints` (`POST /agnosis/v1/removal/{id}/confirm?token=`) validates the token, consumes it, trashes the post, and fires `agnosis_post_removed` — no admin involvement at any step
- **Agnosis Theme** (`agnosis-theme/`) — theme folder renamed from `agnosis-feature`; `readme.txt` updated to Agnosis Theme branding, removed unused Averia Serif Libre and Teko font attributions, kept Labrada and Plus Jakarta Sans; attribution chain documented (Agnosis Theme → Feature → Issue, both Automattic)
- **`composer build-theme-zip`** — new command in `dev/`; reads version from `style.css`; outputs `agnosis-theme-<version>.zip` to `~/Github/agnosis-deploy/`; excludes `.git/`, VCS config, `node_modules/`, and source maps; zip extracts to an `agnosis-theme/` folder ready for WP upload

### Changed
- **Image lightbox** — single-image and gallery image blocks now include `"lightbox":{"enabled":true}` in their block attributes, activating WordPress's built-in lightbox on the frontend without any custom JS
- **Block `sizeSlug`** — `wp:image` and `wp:gallery` blocks now carry `"sizeSlug":"agnosis-artwork"` and `"imageSizeSlug":"agnosis-artwork"` so the block editor and frontend renderer serve the right variant with proper `srcset`
- **Thumbnail references** — `Notification` switched to `agnosis-email` (420 px proportional); `SubmissionsPage` switched to `agnosis-thumb` (512 px square); `ActivityPub` and `LinguaForge` switched from `large` to `agnosis-artwork`
- **Enhancement logic** — previously all photos were enhanced whenever a provider was configured; now only photos below the quality threshold are processed; `pipeline` result carries `enhanced`, `photo_quality_score`, and `photo_quality_issues` fields
- **`max_tokens`** — bumped from 1024 → 1500 in OpenAI and Anthropic providers to accommodate the additional `photo_quality` JSON field
- **`promote_featured` moved to `ReviewEndpoints`** — logic extracted from `GalleryOverview` (a display-layer class) into `ReviewEndpoints` (where approval decisions live); fires on both `approve()` and `save()` with `$should_publish = true`; `GalleryOverview` now handles only block registration, meta registration, and the admin meta box
- **`PostCreator` routing** — `resolve_post_type()` uses `to_address` (the `To:` header) as the primary routing signal; subject-line indicators remain as fallback; `remove@` short-circuits before the AI pipeline

### Fixed
- **PHPStan: `Constant AGNOSIS_PLUGIN_DIR not found`** in `GalleryOverview.php` — reference changed to `\AGNOSIS_DIR` (the actual defined constant); an incorrect `AGNOSIS_PLUGIN_DIR` stub that had been added to the integration test bootstrap was removed
- **`PipelineTest::test_process_uses_enhanced_image_when_provider_succeeds`** — mock `DescriptionResult` now carries `photo_quality_score: 5`; previously the default score of 0 caused the quality gate (`$score > 0`) to skip enhancement, so the test received the raw image instead of the enhanced one

### Tests
- **`ReviewEndpointsIntegrationTest`** — five new tests covering `promote_featured`: approve sets `_agnosis_featured` on the approved post; approve clears the flag on previously featured artworks by the same artist; does not affect featured artworks by other artists; does not clear featured on non-published posts; `save()` with `$should_publish` sets the flag
- **`GalleryOverviewIntegrationTest`** (new file) — eight tests: featured meta is registered; meta is boolean type; meta is REST-exposed; meta box persists the flag; meta box clears the flag when checkbox is absent; meta box is a no-op without a valid nonce; meta box is a no-op on autosave

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

[0.1.7]: https://github.com/agnosis/agnosis/compare/v0.1.6...v0.1.7
[0.1.6]: https://github.com/agnosis/agnosis/compare/v0.1.5...v0.1.6
[0.1.5]: https://github.com/agnosis/agnosis/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/agnosis/agnosis/compare/v0.1.2...v0.1.4
[0.1.2]: https://github.com/agnosis/agnosis/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/agnosis/agnosis/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/agnosis/agnosis/releases/tag/v0.1.0
