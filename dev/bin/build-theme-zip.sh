#!/usr/bin/env bash
# build-theme-zip.sh — Package Agnosis Theme for distribution.
#
# Usage (from dev/):
#   composer build-theme-zip
# or directly:
#   ./dev/bin/build-theme-zip.sh [output-dir]
#
# Uses rsync to copy the theme source so uncommitted changes are included.
# Excludes dev-only files (.git, node_modules, .DS_Store, etc.).
#
# Output: ../agnosis-deploy/agnosis-theme-<version>.zip  (or [output-dir]/agnosis-theme-<version>.zip)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
THEME_DIR="$(cd "$SCRIPT_DIR/../../../agnosis-theme" && pwd)"
THEME_SLUG="agnosis-theme"

# Read version from style.css.
VERSION=$(grep -E "^Version:" "$THEME_DIR/style.css" | sed "s/Version:[[:space:]]*//" | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
    echo "ERROR: could not read theme version from style.css" >&2
    exit 1
fi

# Output directory — first argument or ../agnosis-deploy
OUTPUT_DIR="${1:-$HOME/Github/agnosis-deploy}"
mkdir -p "$OUTPUT_DIR"

ZIP_NAME="${THEME_SLUG}-${VERSION}.zip"
ZIP_PATH="$OUTPUT_DIR/$ZIP_NAME"

echo "==> Building $ZIP_NAME"

# Exclusions — dev-only files that should not ship.
RSYNC_EXCLUDES=(
    --exclude=".git/"
    --exclude=".gitignore"
    --exclude=".gitattributes"
    --exclude=".editorconfig"
    --exclude=".DS_Store"
    --exclude="node_modules/"
    --exclude="*.map"
)

# Copy into a temp dir named exactly 'agnosis-theme' so the ZIP always
# extracts to agnosis-theme/ regardless of the source path.
BUILD_DIR="$(mktemp -d)/build"
mkdir -p "$BUILD_DIR/$THEME_SLUG"
rsync -a "${RSYNC_EXCLUDES[@]}" "$THEME_DIR/" "$BUILD_DIR/$THEME_SLUG/"

# Normalise permissions: 0755 dirs, 0644 files.
find "$BUILD_DIR/$THEME_SLUG" -type d -exec chmod 0755 {} \;
find "$BUILD_DIR/$THEME_SLUG" -type f -exec chmod 0644 {} \;

# Build the ZIP.
cd "$BUILD_DIR"
zip -r "$ZIP_PATH" "$THEME_SLUG/"

# Clean up.
rm -rf "$BUILD_DIR"

echo "✓ Built: $ZIP_PATH"
echo ""
echo "  Contents preview:"
unzip -l "$ZIP_PATH" | awk 'NR>3 && NR<=33'
