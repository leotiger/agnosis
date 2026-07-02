#!/usr/bin/env bash
# =============================================================================
# setup-coverage.sh — install pcov in the wp-env tests-cli container.
#
# Run once after `npm run env:start`. pcov is lost when the container is
# rebuilt; re-run this script whenever you restart the environment.
#
# Usage (from agnosis/dev/):
#   bash scripts/setup-coverage.sh
#   composer coverage:setup          ← calls this script
# =============================================================================

set -euo pipefail

DOCKER="${DOCKER_BIN:-}"
if [[ -z "$DOCKER" ]]; then
    for candidate in \
        /Applications/Docker.app/Contents/Resources/bin/docker \
        /usr/local/bin/docker \
        /opt/homebrew/bin/docker \
        docker
    do
        if command -v "$candidate" &>/dev/null 2>&1 || [[ -x "$candidate" ]]; then
            DOCKER="$candidate"
            break
        fi
    done
fi

if [[ -z "$DOCKER" ]]; then
    echo "❌  docker not found. Start Docker Desktop and try again." >&2
    exit 1
fi

CONTAINER=$("$DOCKER" ps --filter "name=tests-cli" --format "{{.Names}}" 2>/dev/null | head -1)
if [[ -z "$CONTAINER" ]]; then
    echo "❌  wp-env tests-cli container is not running." >&2
    echo "    Run: npm run env:start" >&2
    exit 1
fi

echo "ℹ  Container: $CONTAINER"

# NOTE: the composer.json integration-coverage command now also passes
# `-d pcov.enabled=1 -d pcov.directory=...` directly on the CLI, so it no
# longer *depends* on the php.ini state this script writes — that was the
# root cause of a real regression (coverage silently collapsing to ~0% while
# tests kept passing): this script used to short-circuit the moment the pcov
# *extension* was loaded, without checking whether pcov.directory was still
# pointing at the right path, or present at all. The CLI flags on the
# integration command now make coverage correct regardless of this script's
# state, but this script is kept idempotent and self-correcting too, since
# other invocations (e.g. running phpunit by hand inside the container) still
# rely on the ini file being right.

if "$DOCKER" exec "$CONTAINER" php -m 2>/dev/null | grep -q pcov; then
    echo "ℹ  pcov extension already loaded — verifying ini configuration is still correct…"
else
    echo "ℹ  Installing pcov (this compiles from source, ~30 s)…"
    "$DOCKER" exec --user root "$CONTAINER" bash -c 'pecl install pcov 2>&1 | tail -3'
fi

# Always (re)assert the ini directives — never trust that a pre-existing line
# still has the correct value (a stale "pcov.directory=." from an image
# default would otherwise survive forever, since it technically matches a
# plain `grep -q pcov.directory` check).
"$DOCKER" exec --user root "$CONTAINER" bash -c '
    PHP_INI=/usr/local/etc/php/php.ini
    EXPECTED_DIR=/var/www/html/wp-content/plugins/agnosis

    grep -q "^extension=pcov.so" "$PHP_INI" || echo "extension=pcov.so" >> "$PHP_INI"

    if grep -q "^pcov.enabled=" "$PHP_INI"; then
        sed -i "s|^pcov.enabled=.*|pcov.enabled=1|" "$PHP_INI"
    else
        echo "pcov.enabled=1" >> "$PHP_INI"
    fi

    if grep -q "^pcov.directory=" "$PHP_INI"; then
        sed -i "s|^pcov.directory=.*|pcov.directory=${EXPECTED_DIR}|" "$PHP_INI"
    else
        echo "pcov.directory=${EXPECTED_DIR}" >> "$PHP_INI"
    fi

    echo "--- Active pcov config ---"
    php -i | grep -i "^pcov" || echo "⚠️  pcov module still not visible in php -i — extension may need a server restart."
'

if "$DOCKER" exec "$CONTAINER" php -m 2>/dev/null | grep -q pcov; then
    echo "✅  pcov installed, enabled, and pointed at the plugin directory in $CONTAINER"
else
    echo "❌  pcov still not active after setup — coverage will silently read as ~0%. Investigate manually." >&2
    exit 1
fi
