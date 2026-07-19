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
    echo "✓ All .po files merged."

    # ---- 5. Clear fuzzy matches (policy: never keep a guessed translation
    #         in a .po — msgmerge's fuzzy guesses are frequently wrong, see
    #         AUDIT-0.9.38.md §6b). Blank each fuzzy entry back to a clean
    #         untranslated string so Loco Translate offers it fresh; the
    #         .mo/.l10n.php compile step already skips fuzzy entries anyway,
    #         so nothing user-facing changes — this only stops misleading
    #         "translated-looking" text from sitting in the .po source. ----
    echo "→ Clearing fuzzy matches..."
    TOTAL_CLEARED=0
    CLEAR_COUNT_FILE="$(mktemp)"
    for PO in "${PO_FILES[@]}"; do
        awk -f "${DEV_DIR}/bin/clear-fuzzy.awk" "${PO}" > "${PO}.new" 2>"${CLEAR_COUNT_FILE}"
        CLEARED="$(cat "${CLEAR_COUNT_FILE}")"
        mv "${PO}.new" "${PO}"
        if [ "${CLEARED}" -gt 0 ]; then
            echo "  cleared ${CLEARED}: ${PO}"
        fi
        TOTAL_CLEARED=$(( TOTAL_CLEARED + CLEARED ))
    done
    rm -f "${CLEAR_COUNT_FILE}"
    echo "✓ ${TOTAL_CLEARED} fuzzy entries cleared. Run compile-pos.sh next, then translate freshly in Loco Translate."
else
    echo "ℹ No .po files found in languages/ — add translations and re-run."
fi
