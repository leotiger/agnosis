#!/usr/bin/env bash
# build-zip.sh — package Agnosis for distribution.
#
# Run via: composer build-zip  (from dev/)
#
# Uses `git archive` so .gitattributes export-ignore rules are automatically
# respected — dev/, tests/, build artefacts, etc. are stripped from the zip.
# No vendor/ to ship: Agnosis has zero runtime Composer dependencies.
#
# Output: ../agnosis-deploy/agnosis-<version>.zip

set -euo pipefail

DEV_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_ROOT="$(cd "$DEV_DIR/.." && pwd)"
PLUGIN_SLUG="agnosis"

# --- Read version from the main plugin file ---
VERSION="$(grep "Version:" "$PLUGIN_ROOT/$PLUGIN_SLUG.php" | head -1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
if [ -z "$VERSION" ]; then
    echo "ERROR: could not read plugin version from $PLUGIN_SLUG.php" >&2
    exit 1
fi

BUILD_DIR="$PLUGIN_ROOT/../agnosis-deploy"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="$BUILD_DIR/$ZIP_NAME"

echo "==> Building $ZIP_NAME"

mkdir -p "$BUILD_DIR"
rm -f "$ZIP_PATH"

# git archive respects export-ignore in .gitattributes.
# The prefix puts everything inside an agnosis/ top-level folder in the zip.
cd "$PLUGIN_ROOT"
git archive HEAD \
    --format=zip \
    --prefix="${PLUGIN_SLUG}/" \
    --output="$ZIP_PATH"

echo "==> Done: $ZIP_PATH"
echo ""
echo "  Contents preview:"
unzip -l "$ZIP_PATH" | tail -n +4 | head -30
