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
# Also maintains docs/agnosis-update-manifest.php's $sha256 field, the same
# way the companion Lingua Forge plugin's own dev/bin/build-zip.sh does:
#   1. Cleared to '' at the very START of the run, before the zip is even
#      built. A stale digest left over from a previous run is worse than an
#      empty one — an empty $sha256 just skips verification (a documented,
#      safe default); a stale one looks valid but silently stops matching
#      the zip that actually gets uploaded if this run fails partway through
#      or the resulting zip is rebuilt/replaced afterward. Clearing first
#      means any failure below leaves the manifest in the same safe "unset"
#      state it would be in before a release was ever attempted, not a
#      misleading leftover from a previous one.
#   2. Set to the freshly-built zip's real sha256sum once the build succeeds.
#
# The manifest's $version / $download_url / $last_updated and the changelog
# HTML block are NOT touched here — those are still updated by hand as part
# of the version bump, same as always.
#
# Output: ../agnosis-deploy/agnosis-<version>.zip  (or [output-dir]/agnosis-<version>.zip)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
PLUGIN_SLUG="agnosis"
MANIFEST="$PLUGIN_DIR/docs/agnosis-update-manifest.php"

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

# --- Clear the manifest's $sha256 before doing anything else ----------------
clear_manifest_sha() {
    if [ ! -f "$MANIFEST" ]; then
        echo "WARNING: manifest not found at $MANIFEST — skipping sha256 clear." >&2
        return
    fi
    if ! grep -qE "^[[:space:]]*[$]sha256[[:space:]]*=" "$MANIFEST"; then
        echo "WARNING: could not find a \$sha256 assignment in $(basename "$MANIFEST") — skipping clear." >&2
        return
    fi
    # NOTE: the dollar sign is matched via the bracket expression [$], not \$ —
    # \$ is not reliable as a literal-dollar escape in `sed -E`'s extended
    # regex (confirmed on Lingua Forge's own build-zip.sh: it worked fine in
    # `grep -E` above but silently failed to match in `sed -E`, so the
    # substitution never fired at all despite sed exiting 0). [$] matches a
    # literal '$' unambiguously in both grep and sed, GNU and BSD alike.
    sed -i.bak -E "s/^([[:space:]]*[$]sha256[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;)/\1''\2/" "$MANIFEST"
    rm -f "$MANIFEST.bak"
    echo "--> Cleared \$sha256 in $(basename "$MANIFEST")"
}
clear_manifest_sha

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

# Build the ZIP. Remove any pre-existing file at the destination first —
# `zip -r` merges into an existing archive rather than replacing it, which
# would leave stale entries behind from a previous build at the same path.
rm -f "$ZIP_PATH"
( cd "$BUILD_DIR" && zip -r "$ZIP_PATH" "$PLUGIN_SLUG/" >/dev/null )

# Clean up.
rm -rf "$BUILD_DIR"

echo "✓ Built: $ZIP_PATH"

# --- Compute sha256 and write it into the manifest ---------------------------
write_manifest_sha() {
    if [ ! -f "$MANIFEST" ]; then
        return
    fi
    if ! grep -qE "^[[:space:]]*[$]sha256[[:space:]]*=" "$MANIFEST"; then
        echo "WARNING: could not find a \$sha256 assignment in $(basename "$MANIFEST") — set it manually." >&2
        return
    fi
    local digest
    digest=$(shasum -a 256 "$ZIP_PATH" 2>/dev/null | awk '{print $1}')
    if [ -z "$digest" ]; then
        digest=$(sha256sum "$ZIP_PATH" | awk '{print $1}')
    fi
    # See the [$] note in clear_manifest_sha() above — same reasoning applies here.
    sed -i.bak -E "s/^([[:space:]]*[$]sha256[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;)/\1'${digest}'\2/" "$MANIFEST"
    rm -f "$MANIFEST.bak"
    echo "--> SHA-256: $digest"
    echo "✓ Wrote sha256 into $(basename "$MANIFEST")"
}
write_manifest_sha

echo ""
echo "  Contents preview:"
unzip -l "$ZIP_PATH" | awk 'NR>3 && NR<=33'

echo ""
echo "Remaining manual steps:"
echo "  1. Upload $ZIP_NAME to the v$VERSION GitHub release."
echo "  2. Confirm \$version / \$download_url / \$last_updated in $(basename "$MANIFEST") match this release."
echo "  3. Deploy $(basename "$MANIFEST") to wp-content/mu-plugins/ on agnosis.art."
