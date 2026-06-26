#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# make-pot.sh — Extract translatable strings from Agnosis and update .po files.
#
# Usage (from dev/):
#   bash bin/make-pot.sh
#
# Requires WP-CLI (downloaded here if absent).
# ---------------------------------------------------------------------------
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DEV_DIR="$(cd "$(dirname "$0")/.." && pwd)"
POT_FILE="${PLUGIN_DIR}/languages/agnosis.pot"
WP_CLI="${DEV_DIR}/wp-cli.phar"

# ---- 1. Ensure WP-CLI ----
if [ ! -f "${WP_CLI}" ]; then
    echo "→ Downloading WP-CLI..."
    curl -sS https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
         -o "${WP_CLI}"
    chmod +x "${WP_CLI}"
fi

# ---- 2. Create languages/ dir ----
mkdir -p "${PLUGIN_DIR}/languages"

# ---- 3. Generate .pot ----
echo "→ Extracting strings to ${POT_FILE}..."
php "${WP_CLI}" i18n make-pot "${PLUGIN_DIR}" "${POT_FILE}" \
    --domain=agnosis \
    --exclude="dev,vendor,node_modules,build,tests,.wp-env" \
    --headers='{"Report-Msgid-Bugs-To":"https://github.com/agnosis/agnosis/issues","POT-Creation-Date":"'"$(date -u +"%Y-%m-%dT%H:%M+00:00")"'"}'

echo "✓ POT file updated: ${POT_FILE}"

# ---- 4. Merge into existing .po files (msgmerge) ----
LANG_DIR="${PLUGIN_DIR}/languages"
PO_FILES=( "${LANG_DIR}"/agnosis-*.po )

if [ ${#PO_FILES[@]} -gt 0 ] && [ -f "${PO_FILES[0]}" ]; then
    echo "→ Merging into existing .po files..."
    for PO in "${PO_FILES[@]}"; do
        echo "  msgmerge: ${PO}"
        msgmerge --quiet --update --backup=off "${PO}" "${POT_FILE}"
    done
    echo "✓ All .po files merged. Review fuzzy strings, then run compile-pos.sh."
else
    echo "ℹ No .po files found in languages/ — add translations and re-run."
fi
