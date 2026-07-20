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
# Also maintains agnosis-theme/docs/agnosis-theme-update-manifest.php's
# $sha256 field, the same way this repo's own dev/bin/build-zip.sh does for
# the plugin's docs/agnosis-update-manifest.php:
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
# of the version bump, same as always (CONTRIBUTING.md's Release process).
#
# Output: ../agnosis-deploy/agnosis-theme-<version>.zip  (or [output-dir]/agnosis-theme-<version>.zip)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
THEME_DIR="$(cd "$SCRIPT_DIR/../../../agnosis-theme" && pwd)"
THEME_SLUG="agnosis-theme"
MANIFEST="$THEME_DIR/docs/agnosis-theme-update-manifest.php"

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
    # regex (see build-zip.sh's own note on this, confirmed on Lingua Forge's
    # build-zip.sh: it worked fine in `grep -E` above but silently failed to
    # match in `sed -E`). [$] matches a literal '$' unambiguously in both
    # grep and sed, GNU and BSD alike.
    sed -i.bak -E "s/^([[:space:]]*[$]sha256[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;)/\1''\2/" "$MANIFEST"
    rm -f "$MANIFEST.bak"
    echo "--> Cleared \$sha256 in $(basename "$MANIFEST")"
}
clear_manifest_sha

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

# Build the ZIP. Remove any pre-existing file at the destination first —
# `zip -r` merges into an existing archive rather than replacing it, which
# would leave stale entries behind from a previous build at the same path
# (same fix as build-zip.sh).
rm -f "$ZIP_PATH"
( cd "$BUILD_DIR" && zip -r "$ZIP_PATH" "$THEME_SLUG/" >/dev/null )

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
echo "  1. Upload $ZIP_NAME to the v$VERSION GitHub release on agnosis-theme."
echo "  2. Confirm \$version / \$download_url / \$last_updated in $(basename "$MANIFEST") match this release."
echo "  3. Deploy $(basename "$MANIFEST") to wp-content/mu-plugins/ on agnosis.art."
