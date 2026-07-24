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
# 5b fix (fourteenth audit, 2026-07-21): the manifest's $sha256_note — a
# single human-readable status line — is rewritten at both of those same two
# steps, via write_manifest_note() below. Before this, the surrounding prose
# explaining $sha256's state was hand-written at version-bump time and never
# re-synced once a real build actually ran, so the manifest could (and did)
# end up with a genuine digest sitting next to a comment insisting no build
# had happened yet — a self-contradiction no amount of trusting $sha256's own
# clear-then-fill correctness could catch, since that prose was never this
# script's responsibility until now.
#
# $last_updated, added 2026-07-22: this script already knows today's date
# (it was already in $sha256_note's own text) and the documented release
# process builds the zip immediately before shipping it — so there was no
# real reason to keep this one a separate hand-set-at-ship-time field.
# write_manifest_last_updated() below sets it to today's date once the build
# succeeds, same trigger point as $sha256/$sha256_note. Unlike $sha256 it is
# NOT cleared at the start of a run — a failed/interrupted build should leave
# the previous successful build's date in place, not blank it, since there's
# no "silently wrong" risk for a plain display date the way there is for a
# digest. If a real gap ever opens between building and actually shipping,
# re-running this script right before uploading refreshes the date, same as
# it refreshes $sha256 for a changed zip.
#
# The manifest's $version / $download_url and the changelog HTML block are
# NOT touched here — those are still updated by hand as part of the version
# bump, same as always.
#
# 6a fix (fifteenth audit, 2026-07-24): $sha256's OWN inline `// comment` —
# distinct from the separate $sha256_note field 5b fixed above — used to
# only ever be hand-written at version-bump time (e.g. "Not yet built —
# dev/bin/build-zip.sh computes this at release time.") and was never
# touched by clear_manifest_sha()/write_manifest_sha() below, which only
# ever replaced the quoted value between them. So the very same
# self-contradiction 5b closed for $sha256_note could still happen one line
# up: a real, verified digest sitting right next to a comment insisting no
# build had ever run. Both functions now rewrite that inline comment too,
# each time they rewrite the value next to it.
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
    #
    # 6a fix (fifteenth audit, 2026-07-24): the trailing `.*` swallows
    # whatever inline `// comment` currently sits after the semicolon
    # (write_manifest_sha() below may have replaced it with a "Verified"
    # comment on a previous run) and the replacement puts the canonical
    # "not yet built" comment back — same reasoning as write_manifest_last_
    # updated()'s existing tail-swallow, extended to $sha256's OWN inline
    # comment (distinct from $sha256_note) so it can't survive, contradicting
    # a value it no longer describes, past a single clear/build cycle. Note
    # the `|` delimiter here instead of `/` — the comment text itself
    # contains `/` (dev/bin/build-zip.sh), which would otherwise need
    # escaping against the usual `/` delimiter.
    sed -i.bak -E "s|^([[:space:]]*[$]sha256[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;).*|\1''\2 // Not yet built — dev/bin/build-zip.sh computes this at release time.|" "$MANIFEST"
    rm -f "$MANIFEST.bak"
    echo "--> Cleared \$sha256 in $(basename "$MANIFEST")"
}

# --- Keep $sha256_note in sync with $sha256 at every step it changes --------
# 5b fix: a single script-owned status line, so the manifest never again says
# "pending"/"cleared" in stale hand-written prose while $sha256 itself already
# disagrees. Same [$] bracket-expression reasoning as clear_manifest_sha()
# above for matching the literal dollar sign reliably in `sed -E`. $1 becomes
# the new PHP single-quoted string's contents verbatim — callers must keep it
# free of both `'` (would close the PHP string early) and `/` (the sed
# delimiter used here).
write_manifest_note() {
    local note="$1"
    if [ ! -f "$MANIFEST" ]; then
        return
    fi
    if ! grep -qE "^[[:space:]]*[$]sha256_note[[:space:]]*=" "$MANIFEST"; then
        echo "WARNING: could not find a \$sha256_note assignment in $(basename "$MANIFEST") — skipping note update." >&2
        return
    fi
    sed -i.bak -E "s/^([[:space:]]*[$]sha256_note[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;)/\1'${note}'\2/" "$MANIFEST"
    rm -f "$MANIFEST.bak"
}

clear_manifest_sha
write_manifest_note "Build started $(date -u +%Y-%m-%dT%H:%M:%SZ) by build-zip.sh for v${VERSION} — will be replaced once the build succeeds, or left here (safe: an empty sha256 already skips verification) if it fails."

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
    # 6a fix: same trailing-comment rewrite as clear_manifest_sha() above, in
    # the other direction — the "Not yet built" comment is replaced with one
    # that actually matches a freshly-verified digest, rather than surviving
    # untouched next to it. Points at $sha256_note instead of repeating its
    # date/version text a second time in a second place.
    sed -i.bak -E "s|^([[:space:]]*[$]sha256[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;).*|\1'${digest}'\2 // Verified — see \$sha256_note above for build date/version.|" "$MANIFEST"
    rm -f "$MANIFEST.bak"
    echo "--> SHA-256: $digest"
    echo "✓ Wrote sha256 into $(basename "$MANIFEST")"
}
write_manifest_sha
write_manifest_note "Verified — sha256 written by build-zip.sh on $(date -u +%Y-%m-%d) for ${ZIP_NAME}."

# --- Write today's date into $last_updated -----------------------------------
# Added 2026-07-22, questioned directly rather than left as a manual TODO —
# see the header comment above and the manifest's own comment on $last_updated
# for the reasoning. Not cleared at the start of a run, unlike $sha256/
# $sha256_note — see the header comment above for why.
write_manifest_last_updated() {
    if [ ! -f "$MANIFEST" ]; then
        return
    fi
    if ! grep -qE "^[[:space:]]*[$]last_updated[[:space:]]*=" "$MANIFEST"; then
        echo "WARNING: could not find a \$last_updated assignment in $(basename "$MANIFEST") — set it manually." >&2
        return
    fi
    local today
    today=$(date -u +%Y-%m-%d)
    # Same [$] bracket-expression reasoning as clear_manifest_sha() above —
    # matches the literal dollar sign reliably in `sed -E`. The trailing
    # `[[:space:]]*(//.*)?;` tail also swallows any inline `// comment` after
    # the semicolon (e.g. "Not yet set — will be filled...") so it doesn't
    # linger, now-inaccurate, next to a freshly-written real date.
    sed -i.bak -E "s/^([[:space:]]*[$]last_updated[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;).*/\1'${today}'\2/" "$MANIFEST"
    rm -f "$MANIFEST.bak"
    echo "--> Last updated: $today"
    echo "✓ Wrote last_updated into $(basename "$MANIFEST")"
}
write_manifest_last_updated

echo ""
echo "  Contents preview:"
unzip -l "$ZIP_PATH" | awk 'NR>3 && NR<=33'

echo ""
echo "Remaining manual steps:"
echo "  1. Upload $ZIP_NAME to the v$VERSION GitHub release."
echo "  2. Confirm \$version / \$download_url in $(basename "$MANIFEST") match this release (\$sha256/\$sha256_note/\$last_updated are already written above)."
echo "  3. Deploy $(basename "$MANIFEST") to wp-content/mu-plugins/ on agnosis.art."
