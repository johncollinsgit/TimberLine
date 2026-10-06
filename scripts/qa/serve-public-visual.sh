#!/bin/sh
set -eu

# Visual checks need host-resolution tables even though their target is public.
# Keep that database in an ignored, test-only location.
visual_db="${PWD}/storage/framework/testing/public-visual.sqlite"
mkdir -p "${PWD}/storage/framework/testing"
touch "$visual_db"

export APP_ENV=testing
export APP_KEY="base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI="
export DB_CONNECTION=sqlite
export DB_DATABASE="$visual_db"
export SESSION_DRIVER=array
export CACHE_STORE=array

php artisan migrate --force --no-interaction >/dev/null
exec php artisan serve --host=127.0.0.1 --port=4177
