#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# make-theme-pot.sh — Extract translatable strings from Agnosis Theme and
# update its .po files. Same tool as this repo's own make-pot.sh, scoped to
# the sibling agnosis-theme repo instead (T-3, fifteenth audit — the theme
# never had a .pot/.po pipeline of its own; every string sat gettext-wrapped
# but with no catalog behind it, so it silently rendered as English on every
# one of the platform's 18 locales).
#
# Lives here, not in agnosis-theme/, for the same reason build-theme-zip.sh
# does: the theme repo deliberately carries no build tooling of its own
# (see its own README.md), and this repo's dev/ environment is already the
# one place both products' release tooling is maintained side by side.
#
# Usage (from dev/):
#   bash bin/make-theme-pot.sh
#
# Requires WP-CLI (shared dev/wp-cli.phar with make-pot.sh, downloaded here
# too if somehow absent). Sandbox note: `wp i18n make-pot` needs to reach
# svn.wordpress.org for some of its metadata lookups — in a network-
# restricted sandbox this fails with a 403 from the outbound proxy, same
# known limitation make-pot.sh already has (see this project's own i18n
# workflow notes). Run this on a normal developer machine with full network
# access; it is not expected to succeed inside a locked-down CI/sandbox.
# ---------------------------------------------------------------------------
set -euo pipefail

DEV_DIR="$(cd "$(dirname "$0")/.." && pwd)"
THEME_DIR="$(cd "$DEV_DIR/../../agnosis-theme" && pwd)"
POT_FILE="${THEME_DIR}/languages/agnosis-theme.pot"
WP_CLI="${DEV_DIR}/wp-cli.phar"

# ---- 1. Ensure WP-CLI (shared with make-pot.sh) ----
if [ ! -f "${WP_CLI}" ]; then
    echo "→ Downloading WP-CLI..."
    curl -sS https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
         -o "${WP_CLI}"
    chmod +x "${WP_CLI}"
fi

# ---- 2. Create languages/ dir ----
mkdir -p "${THEME_DIR}/languages"

# ---- 3. Generate .pot ----
echo "→ Extracting strings to ${POT_FILE}..."
php "${WP_CLI}" i18n make-pot "${THEME_DIR}" "${POT_FILE}" \
    --domain=agnosis-theme \
    --exclude="languages" \
    --headers='{"Report-Msgid-Bugs-To":"https://github.com/leotiger/agnosis-theme/issues","POT-Creation-Date":"'"$(date -u +"%Y-%m-%dT%H:%M+00:00")"'"}'

echo "✓ POT file updated: ${POT_FILE}"

# ---- 4. Merge into existing .po files (msgmerge) ----
LANG_DIR="${THEME_DIR}/languages"
PO_FILES=( "${LANG_DIR}"/agnosis-theme-*.po )

if [ ${#PO_FILES[@]} -gt 0 ] && [ -f "${PO_FILES[0]}" ]; then
    echo "→ Merging into existing .po files..."
    for PO in "${PO_FILES[@]}"; do
        echo "  msgmerge: ${PO}"
        msgmerge --quiet --update --backup=off "${PO}" "${POT_FILE}"
    done
    echo "✓ All .po files merged."

    # ---- 5. Clear fuzzy matches — same policy and same shared script as
    #         make-pot.sh (see clear-fuzzy.awk's own header for the
    #         reasoning: msgmerge's fuzzy guesses are frequently wrong, and
    #         a fuzzy entry is skipped at compile time anyway, so clearing
    #         it back to genuinely-untranslated is strictly more honest than
    #         leaving a stale, possibly-wrong guess sitting in the .po). ----
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
    echo "✓ ${TOTAL_CLEARED} fuzzy entries cleared. Translate freshly in Loco Translate, or re-run translate-missing.php-style tooling, for anything newly empty."
else
    echo "ℹ No agnosis-theme-*.po files found in languages/ — add translations and re-run."
fi
