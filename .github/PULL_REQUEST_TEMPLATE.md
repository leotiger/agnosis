## What & why

<!-- What changed, and why. Link the issue this addresses, if one exists. -->

## Checklist

- [ ] `composer qa` passes (lint + PHPStan + unit tests + JS/CSS lint) — and `composer test:integration` too, if you have the [companion repos](../CONTRIBUTING.md#before-you-start) set up. If you couldn't run the integration suite, say so below rather than leaving it unstated.
- [ ] A `CHANGELOG.md` entry is included for any user-facing change (see [Changelog and readme conventions](../CONTRIBUTING.md#changelog-and-readme-conventions)).
- [ ] No version bump — `agnosis.php`/`readme.txt` version numbers are a maintainer's call at merge time, not a contributor's.
- [ ] Non-obvious choices are explained, either in a docblock or below — this codebase leans on docblocks to carry the *why*, not just the *what*.

## Notes for the reviewer

<!-- Anything that doesn't fit above: things you're unsure about, alternatives you considered, what you couldn't test locally. -->
