#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# compile-pos.sh — Compile .po translation files into .mo binaries and
#                  .l10n.php performant format (WP 6.5+).
#
# Usage (from dev/):
#   bash bin/compile-pos.sh
#
# Requires WP-CLI (downloaded by make-pot.sh or here if absent).
# ---------------------------------------------------------------------------
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DEV_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LANG_DIR="${PLUGIN_DIR}/languages"
WP_CLI="${DEV_DIR}/wp-cli.phar"

# ---- 1. Ensure WP-CLI ----
if [ ! -f "${WP_CLI}" ]; then
    echo "→ Downloading WP-CLI..."
    curl -sS https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
         -o "${WP_CLI}"
    chmod +x "${WP_CLI}"
fi

# ---- 2. Compile .po → .mo + .l10n.php ----
PO_FILES=( "${LANG_DIR}"/agnosis-*.po )

if [ ${#PO_FILES[@]} -eq 0 ] || [ ! -f "${PO_FILES[0]}" ]; then
    echo "ℹ No .po files found in languages/. Nothing to compile."
    exit 0
fi

echo "→ Compiling .po files in ${LANG_DIR}..."

for PO in "${PO_FILES[@]}"; do
    LOCALE=$(basename "${PO}" .po | sed 's/^agnosis-//')
    MO="${LANG_DIR}/agnosis-${LOCALE}.mo"
    L10N="${LANG_DIR}/agnosis-${LOCALE}.l10n.php"

    # .mo binary (WP < 6.5 fallback)
    msgfmt -o "${MO}" "${PO}"
    echo "  ✓ ${MO}"

    # .l10n.php (WP 6.5+ performant format)
    php "${WP_CLI}" i18n make-php "${PO}" --locale="${LOCALE}" && \
        echo "  ✓ ${L10N}" || \
        echo "  ⚠ make-php failed for ${LOCALE} (WP-CLI may need updating)"
done

echo "✓ Compilation complete."
