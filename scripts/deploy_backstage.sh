#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/forge/backstage.theforestrystudio.com/current}"

cd "$APP_DIR"

echo "== git pull =="
git pull --ff-only

echo "== composer =="
composer install --no-dev --prefer-dist --optimize-autoloader

echo "== migrations =="
php artisan migrate --force

echo "== clear caches =="
php artisan optimize:clear

echo "== cache for prod =="
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "== build assets =="
# Forge reruns this script in-place on the active release, so a failed `npm ci`
# can leave a partially removed node_modules tree behind. Move the asset dirs
# out of the way first so reruns converge instead of dying on ENOTEMPTY while
# trying to clean a broken tree in place.
if [ -d node_modules ]; then
  OLD_NODE_MODULES="node_modules.__old__.$(date +%Y%m%d%H%M%S)"
  mv node_modules "$OLD_NODE_MODULES"
  echo "Moved previous node_modules to $OLD_NODE_MODULES"
fi

if [ -d public/build ]; then
  OLD_PUBLIC_BUILD="public/build.__old__.$(date +%Y%m%d%H%M%S)"
  mv public/build "$OLD_PUBLIC_BUILD"
  echo "Moved previous public/build to $OLD_PUBLIC_BUILD"
fi

run_npm_install() {
  npm install --no-audit --no-fund
}

if ! run_npm_install; then
  echo "WARN: npm install failed; clearing the fresh partial asset tree and retrying once"
  rm -rf node_modules public/build
  npm cache verify || true
  run_npm_install
fi

npm run build

echo "== restart queues =="
php artisan queue:restart || true

echo "== reload services =="
if sudo -n true 2>/dev/null; then
  sudo systemctl reload nginx || true
  PHP_FPM_SERVICE="$(systemctl list-units --type=service --all | awk '/php[0-9.]*-fpm\.service/ {print $1; exit}')"
  if [ -n "${PHP_FPM_SERVICE:-}" ]; then
    sudo systemctl reload "$PHP_FPM_SERVICE" || true
  else
    echo "WARN: Could not find php*-fpm.service to reload"
  fi
else
  echo "WARN: sudo requires a password; skipping service reloads"
fi

echo "== done =="
