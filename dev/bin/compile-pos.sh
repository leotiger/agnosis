#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# compile-pos.sh — Compile .po translation files into .mo binaries and
#                  .l10n.php performant format (WP 6.5+).
#
# Usage (from dev/):
#   bash bin/compile-pos.sh          # compile every .po
#   bash bin/compile-pos.sh --check  # staleness guard only, no compiling —
#                                     # exits 1 if any .mo/.l10n.php is older
#                                     # than its .po (i.e. compile-pos.sh was
#                                     # never (re)run after the .po changed —
#                                     # see AUDIT-0.9.38.md §6b, "stale .mo").
#                                     # Put this on the release checklist.
#
# Requires WP-CLI (downloaded by make-pot.sh or here if absent).
# ---------------------------------------------------------------------------
set -euo pipefail

CHECK_ONLY=0
if [ "${1:-}" = "--check" ]; then
    CHECK_ONLY=1
fi

PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DEV_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LANG_DIR="${PLUGIN_DIR}/languages"
WP_CLI="${DEV_DIR}/wp-cli.phar"

PO_FILES=( "${LANG_DIR}"/agnosis-*.po )

if [ ${#PO_FILES[@]} -eq 0 ] || [ ! -f "${PO_FILES[0]}" ]; then
    echo "ℹ No .po files found in languages/. Nothing to compile."
    exit 0
fi

# ---- Guard: never compile (or ship) a fuzzy-flagged entry. msgfmt/make-php
#      silently skip fuzzy entries, so this is defense in depth for whoever
#      runs this script without going through make-pot.sh's automated
#      clear-fuzzy.awk step first (e.g. after a manual .po edit). ----
FUZZY_FOUND=0
for PO in "${PO_FILES[@]}"; do
    COUNT=$(grep -c '^#,.*fuzzy' "${PO}" || true)
    if [ "${COUNT}" -gt 0 ]; then
        echo "✗ ${COUNT} fuzzy entr$( [ "${COUNT}" -eq 1 ] && echo y || echo ies ) still in $(basename "${PO}") — run make-pot.sh (clears fuzzy automatically) or dev/bin/clear-fuzzy.awk before compiling."
        FUZZY_FOUND=1
    fi
done
if [ "${FUZZY_FOUND}" -eq 1 ]; then
    exit 1
fi

# ---- --check: staleness guard only, no WP-CLI/compiling needed ----
if [ "${CHECK_ONLY}" -eq 1 ]; then
    STALE=0
    for PO in "${PO_FILES[@]}"; do
        LOCALE=$(basename "${PO}" .po | sed 's/^agnosis-//')
        MO="${LANG_DIR}/agnosis-${LOCALE}.mo"
        L10N="${LANG_DIR}/agnosis-${LOCALE}.l10n.php"
        for COMPILED in "${MO}" "${L10N}"; do
            if [ ! -f "${COMPILED}" ] || [ "${PO}" -nt "${COMPILED}" ]; then
                echo "✗ stale: $(basename "${COMPILED}") is older than $(basename "${PO}") — run compile-pos.sh."
                STALE=1
            fi
        done
    done
    if [ "${STALE}" -eq 1 ]; then
        exit 1
    fi
    echo "✓ All .mo/.l10n.php files are up to date with their .po source."
    exit 0
fi

# ---- 1. Ensure WP-CLI ----
if [ ! -f "${WP_CLI}" ]; then
    echo "→ Downloading WP-CLI..."
    curl -sS https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
         -o "${WP_CLI}"
    chmod +x "${WP_CLI}"
fi

# ---- 2. Compile .po → .mo + .l10n.php ----
echo "→ Compiling .po files in ${LANG_DIR}..."

for PO in "${PO_FILES[@]}"; do
    LOCALE=$(basename "${PO}" .po | sed 's/^agnosis-//')
    MO="${LANG_DIR}/agnosis-${LOCALE}.mo"
    L10N="${LANG_DIR}/agnosis-${LOCALE}.l10n.php"

    # .mo binary (WP < 6.5 fallback)
    msgfmt -o "${MO}" "${PO}"
    echo "  ✓ ${MO}"

    # .l10n.php (WP 6.5+ performant format). `wp i18n make-php` takes
    # <source> [<destination>] — no --locale flag; it derives the locale (and
    # therefore the output filename, agnosis-<locale>.l10n.php) from the .po
    # filename itself. An earlier version of this script passed --locale,
    # which this command has never accepted — every run failed this step for
    # every single locale with "unknown --locale parameter" (caught, not
    # silent, via the `|| echo` below), so no .l10n.php was ever regenerated
    # by this script until now; every existing agnosis-*.l10n.php in
    # languages/ was produced some other way (hand-run wp-cli, or an editor).
    php "${WP_CLI}" i18n make-php "${PO}" "${LANG_DIR}" && \
        echo "  ✓ ${L10N}" || \
        echo "  ⚠ make-php failed for ${LOCALE} (WP-CLI may need updating)"
done

echo "✓ Compilation complete."
