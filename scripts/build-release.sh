#!/usr/bin/env bash
#
# Build the AbilityGuard release zip into ./dist/.
#
# Strips dev artifacts (tests/, examples/, stubs/, phpunit*, phpcs*,
# phpstan*, composer*, package*, .github/, .wp-env.json, docs/, README.md,
# .git/, vendor/, node_modules/) and ships only the runtime tree.
#
# Usage:
#   scripts/build-release.sh           # build dist/abilityguard-<version>.zip
#   scripts/build-release.sh --check   # build + run wp plugin check on it
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION=$(grep -E "^ \* Version:" abilityguard.php | awk -F': +' '{print $2}' | tr -d ' ')
if [ -z "$VERSION" ]; then
    echo "Could not read Version from abilityguard.php" >&2
    exit 1
fi

DIST="$ROOT/dist"
STAGE="$DIST/stage"
ZIP="$DIST/abilityguard-${VERSION}.zip"

rm -rf "$DIST"
mkdir -p "$STAGE/abilityguard"

echo "Building release for v${VERSION}..."

# Build the JS bundle if missing or stale.
if [ ! -f assets/admin.js ] || [ assets/admin.jsx -nt assets/admin.js ]; then
    npm run build
fi

rsync -a \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.claude' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='tests' \
    --exclude='examples' \
    --exclude='stubs' \
    --exclude='scripts' \
    --exclude='dist' \
    --exclude='.wp-env.json' \
    --exclude='phpunit*' \
    --exclude='phpcs*' \
    --exclude='phpstan*' \
    --exclude='package*.json' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='.gitignore' \
    --exclude='.gitattributes' \
    --exclude='.phpunit.cache' \
    --exclude='.editorconfig' \
    --exclude='Makefile' \
    --exclude='docs' \
    --exclude='README.md' \
    --exclude='assets/admin.jsx' \
    ./ "$STAGE/abilityguard/"

(cd "$STAGE" && zip -rq "$ZIP" abilityguard)
rm -rf "$STAGE"

echo "Built: $ZIP ($(du -h "$ZIP" | awk '{print $1}'), $(unzip -l "$ZIP" | tail -1 | awk '{print $2}') files)"

if [ "${1:-}" = "--check" ]; then
    if ! command -v wp-env >/dev/null 2>&1; then
        echo "wp-env not found, skipping PCP." >&2
        exit 0
    fi
    CONTAINER=$(docker ps --format '{{.Names}}' | grep -E '^[a-f0-9]+-wordpress-1$' | head -1)
    if [ -z "$CONTAINER" ]; then
        echo "wp-env wordpress container not running, run 'wp-env start' first." >&2
        exit 1
    fi
    SLUG="abilityguard-release-check"
    echo "Running Plugin Check against $SLUG..."
    docker exec "$CONTAINER" rm -rf "/var/www/html/wp-content/plugins/$SLUG"
    TMP=$(mktemp -d)
    unzip -q "$ZIP" -d "$TMP"
    mv "$TMP/abilityguard" "$TMP/$SLUG"
    docker cp "$TMP/$SLUG" "$CONTAINER:/var/www/html/wp-content/plugins/"
    rm -rf "$TMP"
    wp-env run cli wp plugin check "$SLUG" --format=table --fields=type,code,file,line --severity=5 || true
    docker exec "$CONTAINER" rm -rf "/var/www/html/wp-content/plugins/$SLUG"
    echo ""
    echo "NOTE: Any 'WordPress.WP.I18n.TextDomainMismatch' errors are slug-test artifacts."
    echo "      Text domain in code is 'abilityguard'; PCP is matching against the test slug '$SLUG'."
    echo "      They vanish when installed at canonical slug 'abilityguard'."
fi
