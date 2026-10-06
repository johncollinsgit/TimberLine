#!/usr/bin/env bash

set -euo pipefail

database_name="${DB_DATABASE:-}"
if [ "${DB_CONNECTION:-}" != "mysql" ] || ! printf '%s' "$database_name" | grep -Eiq '(^|[_-])(ci|test|testing|sandbox)([_-]|$)'; then
  echo "Refusing to verify a schema baseline outside a disposable MySQL database." >&2
  exit 1
fi

test -s database/schema/mysql-schema.sql
test -s database/schema/mysql-schema.sha256

# First prove the current migration tree produces the approved signature.
php artisan migrate:fresh --force --no-interaction
php artisan schema:fingerprint --expect-file=database/schema/mysql-schema.sha256 --no-interaction

# Then prove Laravel can boot a blank MySQL database from the committed dump
# and finish with the same schema. The database-name guard above prevents this
# destructive setup from targeting any production-like database.
MYSQL_PWD="${DB_PASSWORD:-}" mysql --protocol=TCP --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USERNAME:-root}" -e "DROP DATABASE IF EXISTS \`${database_name}\`; CREATE DATABASE \`${database_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --force --no-interaction
php artisan schema:fingerprint --expect-file=database/schema/mysql-schema.sha256 --no-interaction
