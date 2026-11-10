#!/usr/bin/env bash
# Install this complete script in the production Forge site's Deploy Script.
# Forge expands the three release macros before running it. No active checkout
# is mutated: only the newly-created, inactive release may switch commits.
set -euo pipefail

readonly approved_sha="${FORGE_VAR_RELEASE_SHA:-}"
if [[ ! "$approved_sha" =~ ^[0-9a-f]{40}$ ]]; then
    echo 'Missing approved release SHA. Deploy through the protected GitHub workflow.' >&2
    exit 1
fi

$CREATE_RELEASE()
cd "$FORGE_RELEASE_DIRECTORY"

readonly candidate="$(pwd -P)"
readonly site_root="$(realpath -e "$FORGE_SITE_ROOT")"
readonly declared_release="$(realpath -e "$FORGE_RELEASE_DIRECTORY")"
if [[ "$candidate" != "$declared_release" || "$candidate" != "$site_root"/releases/* || ! -d .git ]]; then
    echo 'Refusing to pin outside a fresh Forge release checkout.' >&2
    exit 1
fi
if [[ -e "$site_root/current" && "$candidate" == "$(realpath -e "$site_root/current")" ]]; then
    echo 'Refusing to change the active release.' >&2
    exit 1
fi

# A branch can advance after CI passed or after Forge started cloning. Fetch
# the immutable approved object and detach only this inactive candidate at it.
git diff --quiet HEAD --
git fetch --quiet --no-tags origin "$approved_sha"
git checkout --quiet --detach "$approved_sha"

verify_approved_source() {
    [[ "$(git rev-parse HEAD)" == "$approved_sha" ]] || {
        echo 'Release commit changed after admission.' >&2; return 1;
    }
    git diff --quiet "$approved_sha" -- || {
        echo 'Tracked release source changed after admission.' >&2; return 1;
    }
}

verify_approved_source
# Readiness reports the checked-out source, never Forge's display-only label.
export RELEASE_ID="$(git rev-parse HEAD)"
echo "Building approved release $RELEASE_ID."

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --no-audit --no-fund
npm run build

$FORGE_PHP artisan config:cache
$FORGE_PHP artisan view:cache
verify_approved_source
$FORGE_PHP artisan migrate --force
verify_approved_source

$ACTIVATE_RELEASE()
$RESTART_QUEUES()
