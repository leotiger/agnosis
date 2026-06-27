#!/usr/bin/env bash
# build-zip.sh — Package Agnosis for distribution.
#
# Usage (from dev/):
#   composer build-zip
# or directly:
#   ./dev/bin/build-zip.sh [output-dir]
#
# Uses rsync + .distignore so uncommitted changes are included and
# .gitkeep / other repo-only files are naturally excluded.
# Runs `composer install --no-dev` first so vendor/ is up-to-date and
# included in the ZIP (webklex/php-imap is a runtime dependency).
#
# Output: ../agnosis-deploy/agnosis-<version>.zip  (or [output-dir]/agnosis-<version>.zip)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
PLUGIN_SLUG="agnosis"

# Read version from the main plugin file.
VERSION=$(grep -E "^\s*\*\s*Version:" "$PLUGIN_DIR/$PLUGIN_SLUG.php" | sed "s/.*Version:[[:space:]]*//" | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
    echo "ERROR: could not read plugin version from $PLUGIN_SLUG.php" >&2
    exit 1
fi

# Output directory — first argument or ../agnosis-deploy
OUTPUT_DIR="${1:-$HOME/Github/agnosis-deploy}"
mkdir -p "$OUTPUT_DIR"

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="$OUTPUT_DIR/$ZIP_NAME"

echo "==> Building $ZIP_NAME"

# Install / refresh production Composer dependencies so vendor/ is current.
echo "--> Running composer install --no-dev --optimize-autoloader"
(cd "$PLUGIN_DIR" && composer install --no-dev --optimize-autoloader --quiet)

# Build rsync exclusions from .distignore.
# Dot-only filenames (e.g. .DS_Store, .gitkeep) match anywhere in the tree.
# Everything else is anchored to the plugin root with a leading slash.
RSYNC_EXCLUDES=()
while IFS= read -r line; do
    [[ "$line" =~ ^[[:space:]]*$ ]] && continue
    [[ "$line" =~ ^# ]] && continue
    if [[ "$line" == .* && "$line" != */* ]]; then
        RSYNC_EXCLUDES+=(--exclude="$line")
    else
        RSYNC_EXCLUDES+=(--exclude="/${line}")
    fi
done < "$PLUGIN_DIR/.distignore"

# Always exclude macOS metadata.
RSYNC_EXCLUDES+=(--exclude=".DS_Store")

# Copy into a temp dir named exactly 'agnosis' so the ZIP always
# extracts to agnosis/ regardless of the source path.
BUILD_DIR="$(mktemp -d)/build"
mkdir -p "$BUILD_DIR/$PLUGIN_SLUG"
rsync -a "${RSYNC_EXCLUDES[@]}" "$PLUGIN_DIR/" "$BUILD_DIR/$PLUGIN_SLUG/"

# Normalise permissions: 0755 dirs, 0644 files.
find "$BUILD_DIR/$PLUGIN_SLUG" -type d -exec chmod 0755 {} \;
find "$BUILD_DIR/$PLUGIN_SLUG" -type f -exec chmod 0644 {} \;

# Build the ZIP.
cd "$BUILD_DIR"
zip -r "$ZIP_PATH" "$PLUGIN_SLUG/"

# Clean up.
rm -rf "$BUILD_DIR"

echo "✓ Built: $ZIP_PATH"
echo ""
echo "  Contents preview:"
unzip -l "$ZIP_PATH" | awk 'NR>3 && NR<=33'
