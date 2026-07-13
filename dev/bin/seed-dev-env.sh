#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# seed-dev-env.sh — Populate the wp-env development environment with sample
#                   artists, artworks and plugin options for manual testing.
#
# Idempotent — safe to re-run at any time. All create commands are guarded
# with existence checks so re-seeding never duplicates data.
#
# Usage (from dev/):
#   npm run env:seed          ← standalone
#   npm run env:start         ← runs automatically after start
# ---------------------------------------------------------------------------
set -euo pipefail

WP="./node_modules/.bin/wp-env run cli wp"

echo "── Agnosis dev seed ─────────────────────────────────────────────────────"

# ── Permalink structure ───────────────────────────────────────────────────────
echo "  Setting permalink structure …"
$WP option update permalink_structure '/%postname%/' --quiet
$WP rewrite flush --quiet

# ── Plugin activation ─────────────────────────────────────────────────────────
# Lingua Forge must activate BEFORE Agnosis: agnosis.php declares
# `Requires Plugins: lingua-forge` (0.9.22+), and WordPress's Plugin
# Dependencies mechanism (6.5+) refuses to activate a dependent plugin
# before its dependency is already active — `wp plugin activate` processes
# its arguments in the given order, so getting this backwards here fails
# agnosis's activation with "Only activated 1 of 2 plugins," not the
# "not found" the old error-swallowing below assumed.
echo "  Activating plugins …"
$WP plugin activate lingua-forge --quiet 2>/dev/null || true
$WP plugin activate agnosis      --quiet 2>/dev/null || echo "    ↳ agnosis failed to activate — not found, or its lingua-forge dependency isn't active."

# ── Artist accounts ───────────────────────────────────────────────────────────
echo "  Creating artist accounts …"

create_user_if_missing() {
    local login="$1"
    local email="$2"
    local display="$3"

    local existing
    existing=$($WP user get "$login" --field=ID --quiet 2>/dev/null || true)

    if [ -n "$existing" ]; then
        echo "    ↳ $login already exists (ID $existing), skipping." >&2
        echo "$existing"
        return
    fi

    local id
    id=$($WP user create "$login" "$email" \
        --role=subscriber \
        --user_pass=password \
        --display_name="$display" \
        --porcelain \
        --quiet)
    echo "    ↳ Created $login ($display) → ID $id" >&2
    echo "$id"
}

U1=$(create_user_if_missing artist1 "artist1@agnosis.test" "Mara Okonkwo")
U2=$(create_user_if_missing artist2 "artist2@agnosis.test" "Theo Vasquez")
U3=$(create_user_if_missing artist3 "artist3@agnosis.test" "Yuki Tanaka")

# Grant artist role to artist1 (simulates admitted artist).
$WP user add-role artist1 agnosis_artist --quiet 2>/dev/null || true
echo "    ↳ Granted agnosis_artist role to artist1"

# ── Sample artwork post ───────────────────────────────────────────────────────
echo "  Creating sample artwork …"

EXISTING_POST=$($WP post list \
    --post_type=agnosis_artwork \
    --post_status=publish \
    --name="still-life-with-forgotten-things" \
    --fields=ID \
    --format=ids \
    --quiet 2>/dev/null || true)

if [ -n "$EXISTING_POST" ]; then
    echo "    ↳ Artwork already exists (ID $EXISTING_POST), skipping."
    POST_ID="$EXISTING_POST"
else
    POST_ID=$($WP post create \
        --post_type=agnosis_artwork \
        --post_status=publish \
        --post_title="Still Life with Forgotten Things" \
        --post_name="still-life-with-forgotten-things" \
        --post_excerpt="A meditation on what we leave behind, rendered in oil on reclaimed wood." \
        --post_content="<p>Mara Okonkwo works from her studio in Lagos, painting the objects that survive us — chipped cups, worn shoes, letters never sent. This piece is part of her ongoing series on domestic archaeology.</p>" \
        --post_author="$U1" \
        --porcelain \
        --quiet)
    echo "    ↳ Created artwork → ID $POST_ID"
fi

# ── Plugin options ────────────────────────────────────────────────────────────
echo "  Setting Agnosis options …"
$WP option update agnosis_node_label         "Agnosis Dev Node" --quiet
$WP option update agnosis_ai_provider        "openai"           --quiet
$WP option update agnosis_activitypub_enabled 1                 --quiet
$WP option update agnosis_admission_percent      10                 --quiet
$WP option update agnosis_admission_minimum      3                  --quiet
$WP option update agnosis_admission_window_days  7                  --quiet
$WP option update agnosis_tx_fee_percent     7                  --quiet

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo "  Artists   : $U1  $U2  $U3"
echo "  Artwork   : $POST_ID"
echo ""
echo "  Dev login:"
echo "    URL:      http://localhost:8890/wp-login.php"
echo "    Admin:    admin / password"
echo "    Artists:  artist1 / artist2 / artist3  (pass: password)"
echo "    artist1 is admitted (has agnosis_artist role)"
echo ""
echo "  ✓ Agnosis dev environment seeded."
echo "────────────────────────────────────────────────────────────────────────"
