#!/usr/bin/env bash
# build-theme-zip.sh — Package Agnosis Theme for distribution.
#
# Usage (from dev/):
#   composer build-theme-zip
# or directly:
#   ./dev/bin/build-theme-zip.sh [output-dir]
#
# Uses rsync to copy the theme source so uncommitted changes are included.
# Exclusions are read from agnosis-theme/.distignore (audit AUDIT-0.9.38.md
# §7c) the same way build-zip.sh reads the plugin's own .distignore, instead
# of the hardcoded list this script used to carry — one file to update when
# what should ship changes, not two scripts that can silently drift apart.
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

# Build rsync exclusions from .distignore (same parsing rule as build-zip.sh:
# dot-only filenames, e.g. .DS_Store, match anywhere in the tree; everything
# else is anchored to the theme root with a leading slash).
RSYNC_EXCLUDES=()
if [ -f "$THEME_DIR/.distignore" ]; then
    while IFS= read -r line; do
        [[ "$line" =~ ^[[:space:]]*$ ]] && continue
        [[ "$line" =~ ^# ]] && continue
        if [[ "$line" == .* && "$line" != */* ]]; then
            RSYNC_EXCLUDES+=(--exclude="$line")
        else
            RSYNC_EXCLUDES+=(--exclude="/${line}")
        fi
    done < "$THEME_DIR/.distignore"
else
    echo "WARNING: no .distignore found in $THEME_DIR — nothing will be excluded from the ZIP." >&2
fi

# Always exclude macOS metadata, even if .distignore is ever edited to drop it.
RSYNC_EXCLUDES+=(--exclude=".DS_Store")

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
