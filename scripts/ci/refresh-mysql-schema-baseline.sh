#!/usr/bin/env bash

set -euo pipefail

database_name="${DB_DATABASE:-}"
if [ "${DB_CONNECTION:-}" != "mysql" ] || ! printf '%s' "$database_name" | grep -Eiq '(^|[_-])(ci|test|testing|sandbox)([_-]|$)'; then
  echo "Refusing to create a schema baseline outside a disposable MySQL database." >&2
  exit 1
fi

mkdir -p database/schema
php artisan migrate:fresh --force --no-interaction
php artisan schema:dump --database=mysql --path=database/schema/mysql-schema.sql --no-interaction
php artisan schema:fingerprint --write=database/schema/mysql-schema.sha256 --no-interaction

test -s database/schema/mysql-schema.sql
test -s database/schema/mysql-schema.sha256
