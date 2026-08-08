#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
base_sha="${1:-${MIGRATION_BASE_SHA:-}}"

if [[ "${DB_CONNECTION:-}" != "mysql" ]]; then
    echo "Migration rehearsal requires DB_CONNECTION=mysql." >&2
    exit 2
fi

if [[ -z "${DB_DATABASE:-}" ]] || [[ ! "${DB_DATABASE}" =~ (^|_)(ci|test|testing)(_|$) ]]; then
    echo "Refusing migration rehearsal against DB_DATABASE='${DB_DATABASE:-unset}'. Use a disposable database whose name contains a ci/test segment." >&2
    exit 2
fi

if [[ -z "$base_sha" ]]; then
    echo "Usage: scripts/ci/rehearse-migrations.sh <base-git-sha>" >&2
    exit 2
fi

if ! git -C "$repository_root" cat-file -e "${base_sha}^{commit}" 2>/dev/null; then
    echo "Could not resolve baseline commit ${base_sha}. Ensure checkout uses fetch-depth: 0." >&2
    exit 2
fi

rehearsal_dir="$(mktemp -d)"
trap 'rm -rf "$rehearsal_dir"' EXIT

git -C "$repository_root" archive --format=tar "$base_sha" database/migrations | tar -xf - -C "$rehearsal_dir"

if [[ ! -d "$rehearsal_dir/database/migrations" ]]; then
    echo "Baseline ${base_sha} did not provide database/migrations." >&2
    exit 2
fi

cd "$repository_root"

echo "Building the prior-release schema from ${base_sha}..."
php artisan migrate:fresh \
    --force \
    --no-interaction \
    --path="$rehearsal_dir/database/migrations" \
    --realpath

echo "Upgrading the prior-release schema with the current migrations..."
php artisan migrate --force --no-interaction

echo "Re-running the migrator to prove the upgraded schema is settled..."
php artisan migrate --force --no-interaction
php artisan migrate:status --no-ansi

echo "Production-like MySQL migration rehearsal passed from ${base_sha} to HEAD."
