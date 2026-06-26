# Changelog

All notable changes to Agnosis are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) —
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html)

## [Unreleased]

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

[Unreleased]: https://github.com/agnosis/agnosis/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/agnosis/agnosis/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/agnosis/agnosis/releases/tag/v0.1.0
