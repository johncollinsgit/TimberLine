#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/forge/backstage.theforestrystudio.com/current}"
DEPLOY_REF="${DEPLOY_REF:-}"

cd "$APP_DIR"

echo "== sync git =="
git fetch origin main
git reset --hard
git clean -fd

if [ -n "$DEPLOY_REF" ]; then
  git checkout -B main "$DEPLOY_REF"
else
  git checkout -B main FETCH_HEAD
fi

echo "== composer =="
composer install --no-dev --prefer-dist --optimize-autoloader

echo "== migrations =="
php artisan migrate --force

echo "== clear caches =="
php artisan optimize:clear

echo "== build assets =="
# Forge reruns this script in-place on the active release, so a failed `npm ci`
# can leave a partially removed node_modules tree behind. Move the asset dirs
# out of the way first so reruns converge instead of dying on ENOTEMPTY while
# trying to clean a broken tree in place.
move_dir_if_exists() {
  local dir="$1"
  local prefix="$2"

  if [ -d "$dir" ]; then
    local target="${prefix}.$(date +%Y%m%d%H%M%S).$$"
    mv "$dir" "$target"
    echo "Moved $dir to $target"
  fi
}

move_dir_if_exists node_modules node_modules.__old__
move_dir_if_exists public/build public/build.__old__

run_npm_install() {
  npm install --no-audit --no-fund
}

if ! run_npm_install; then
  echo "WARN: npm install failed; moving the fresh partial asset tree aside and retrying once"
  move_dir_if_exists node_modules node_modules.__failed__
  move_dir_if_exists public/build public/build.__failed__
  npm cache verify || true
  run_npm_install
fi

npm run build
rm -f public/hot

# Finalize runtime caches after the asset build. Keeping this step last prevents
# active PHP workers from retaining compiled-view paths that an in-place deploy
# has cleared or replaced while npm is rebuilding the public bundle.
echo "== finalize runtime caches =="
php artisan config:cache
php artisan route:cache
php artisan view:clear
php artisan view:cache

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

echo "== verify live health =="
HEALTH_URL="${DEPLOY_HEALTH_URL:-https://app.theeverbranch.com/up}"
HEALTHY=false
for attempt in 1 2 3 4 5; do
  if curl --fail --silent --show-error --max-time 15 "$HEALTH_URL" >/dev/null; then
    HEALTHY=true
    break
  fi

  echo "WARN: health check attempt $attempt failed"
  sleep 2
done

if [ "$HEALTHY" != true ]; then
  echo "ERROR: live health check failed: $HEALTH_URL"
  exit 1
fi

echo "== done =="
