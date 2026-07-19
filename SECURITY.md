# Security Policy

## Supported versions

Agnosis is a single actively-developed plugin with no maintained legacy branches — the latest release on the [self-hosted update channel](docs/agnosis-update-manifest.php) (and on the GitHub [Releases](https://github.com/leotiger/agnosis/releases) page) is the only supported version. Security fixes land in the next release, not backported.

## Reporting a vulnerability

Please **don't** open a public issue for a security vulnerability. Use GitHub's private [Security Advisory](https://github.com/leotiger/agnosis/security/advisories/new) form on this repository instead, so a fix can be prepared before the details are public.

Include as much of the following as you can:

- The affected version (`Version:` header in `agnosis.php`, or the plugin's own Settings screen).
- Steps to reproduce, or a proof of concept.
- The class of issue (e.g. authentication bypass, SQL injection, SSRF, XSS) and the affected file/function if you've narrowed it down.
- The potential impact as you understand it.

You should get an initial response within a few days. This is a solo-maintained project, not a company with a dedicated security team — response times are best-effort, but every report is read and taken seriously.

## Scope

In scope: the Agnosis plugin itself (`includes/`, `blocks/`, `agnosis.php`, `uninstall.php`). Its companion repositories — [`lingua-forge`](https://github.com/leotiger/lingua-forge) and `agnosis-theme` — are separate projects; please report issues in those repos directly if the report is specific to their own code, rather than to how Agnosis integrates with them.

Out of scope: vulnerabilities that require an already-compromised WordPress admin account, issues in WordPress core itself, or issues in a third-party plugin/theme not maintained by this project.

## Disclosure

Agnosis follows coordinated disclosure: please give a reasonable window to develop and ship a fix before any public write-up. A credit/acknowledgment is happy to be included in the fix's `CHANGELOG.md` entry if you'd like one — say so in your report.
