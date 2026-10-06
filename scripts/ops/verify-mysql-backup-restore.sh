#!/usr/bin/env bash

# Restore a provider-produced backup only into an isolated drill database.
# The script never connects to a database whose name is not unmistakably a
# throwaway target, and it never uploads or retains the backup file.
set -euo pipefail

backup_file="${1:-}"
database_name="${DB_DATABASE:-}"

if [ -z "$backup_file" ] || [ ! -f "$backup_file" ]; then
  echo "Usage: DB_CONNECTION=mysql DB_DATABASE=everbranch_restore_test $0 /secure/path/backup.sql[.gz]" >&2
  exit 64
fi

if [ "${DB_CONNECTION:-}" != "mysql" ] || ! printf '%s' "$database_name" | grep -Eiq '(^|[_-])(restore|ci|test|testing|sandbox)([_-]|$)'; then
  echo "Refusing to restore outside an isolated MySQL drill database." >&2
  exit 1
fi

mysql_args=(--protocol=TCP --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USERNAME:-root}")
export MYSQL_PWD="${DB_PASSWORD:-}"
mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`${database_name}\`; CREATE DATABASE \`${database_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

if [[ "$backup_file" == *.gz ]]; then
  gzip --decompress --stdout "$backup_file" | mysql "${mysql_args[@]}" "$database_name"
else
  mysql "${mysql_args[@]}" "$database_name" < "$backup_file"
fi

table_count="$(mysql "${mysql_args[@]}" --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${database_name}' AND table_type = 'BASE TABLE';")"
if [ "$table_count" -eq 0 ]; then
  echo "Restore completed without any tables; treating the drill as failed." >&2
  exit 1
fi

php artisan migrate:status --no-interaction
echo "Backup restore drill passed: restored ${table_count} tables into ${database_name}."
