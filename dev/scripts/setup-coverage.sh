#!/usr/bin/env bash
# =============================================================================
# setup-coverage.sh — install pcov in the wp-env tests-wordpress container.
#
# Run once after `npm run env:start`. pcov is lost when the container is
# rebuilt; re-run this script whenever you restart the environment.
#
# 2026-07-06: integration coverage moved from tests-cli (Alpine, wp-cli image
# — Imagick there registers zero coders, see composer.json's test:integration
# history) to tests-wordpress (Debian, wordpress:php8.3-apache). Confirmed via
# `php --ini` inside tests-wordpress that it uses the same upstream
# docker-library/php layout as tests-cli: a monolithic php.ini plus
# conf.d/docker-php-ext-<name>.ini per extension — so this script writes
# pcov's own conf.d file (matching how imagick/gd/etc. are already set up in
# that image) rather than appending to the shared php.ini.
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

CONTAINER=$("$DOCKER" ps --filter "name=tests-wordpress" --format "{{.Names}}" 2>/dev/null | head -1)
if [[ -z "$CONTAINER" ]]; then
    echo "❌  wp-env tests-wordpress container is not running." >&2
    echo "    Run: npm run env:start" >&2
    exit 1
fi

echo "ℹ  Container: $CONTAINER"

# NOTE: the composer.json integration-coverage command now also passes
# `-d pcov.enabled=1 -d pcov.directory=...` directly on the CLI, so it no
# longer *depends* on the ini state this script writes — that was the root
# cause of a real regression (coverage silently collapsing to ~0% while
# tests kept passing): this script used to short-circuit the moment the pcov
# *extension* was loaded, without checking whether pcov.directory was still
# pointing at the right path, or present at all. The CLI flags on the
# integration command now make coverage correct regardless of this script's
# state, but this script is kept idempotent and self-correcting too, since
# other invocations (e.g. running phpunit by hand inside the container) still
# rely on the conf.d file being right.

if "$DOCKER" exec "$CONTAINER" php -m 2>/dev/null | grep -q pcov; then
    echo "ℹ  pcov extension already loaded — verifying config is still correct…"
else
    echo "ℹ  Installing pcov (this compiles from source, ~30 s)…"
    if ! "$DOCKER" exec --user root "$CONTAINER" bash -c 'pecl install pcov 2>&1 | tail -5'; then
        echo "ℹ  Plain install failed — this Debian-based image likely stripped its"
        echo "   build toolchain after building its own extensions (standard"
        echo "   docker-library/php practice). Installing build deps and retrying…"
        "$DOCKER" exec --user root "$CONTAINER" bash -c '
            apt-get update -qq
            apt-get install -y --no-install-recommends \
                autoconf dpkg-dev file g++ gcc libc-dev make pkg-config re2c \
                2>&1 | tail -5
        '
        "$DOCKER" exec --user root "$CONTAINER" bash -c 'pecl install pcov 2>&1 | tail -5'
    fi
fi

# Always (re)assert pcov's own conf.d file — never trust a pre-existing line
# still has the correct value (a stale "pcov.directory=." from a prior run
# would otherwise survive forever, since it technically matches a plain
# `grep -q pcov.directory` check).
"$DOCKER" exec --user root "$CONTAINER" bash -c '
    PCOV_INI=/usr/local/etc/php/conf.d/docker-php-ext-pcov.ini
    EXPECTED_DIR=/var/www/html/wp-content/plugins/agnosis

    cat > "$PCOV_INI" <<EOF
extension=pcov.so
pcov.enabled=1
pcov.directory=${EXPECTED_DIR}
EOF

    echo "--- Active pcov config ---"
    php -i | grep -i "^pcov" || echo "⚠️  pcov module still not visible in php -i — check the install output above."
'

if "$DOCKER" exec "$CONTAINER" php -m 2>/dev/null | grep -q pcov; then
    echo "✅  pcov installed, enabled, and pointed at the plugin directory in $CONTAINER"
else
    echo "❌  pcov still not active after setup — coverage will silently read as ~0%. Investigate manually." >&2
    exit 1
fi
