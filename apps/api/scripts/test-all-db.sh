#!/usr/bin/env bash
# Runs the API test suite against local MySQL 8, MariaDB 10.6 and PostgreSQL 13 containers.
# Start them once with: scripts/test-all-db.sh --start
set -euo pipefail
cd "$(dirname "$0")/.."

if [ "${1:-}" = "--start" ]; then
  docker run -d --name bl-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=bilyart_test -p 33060:3306 mysql:8.0
  docker run -d --name bl-maria -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=bilyart_test -p 33061:3306 mariadb:10.6
  docker run -d --name bl-pg -e POSTGRES_PASSWORD=root -e POSTGRES_DB=bilyart_test -p 54320:5432 postgres:13
  exit 0
fi

args=("$@")
status=0
for spec in "mysql 33060 root" "mariadb 33061 root" "pgsql 54320 postgres"; do
  set -- $spec
  echo "== $1"
  DB_CONNECTION=$1 DB_PORT=$2 DB_USERNAME=$3 php artisan test "${args[@]}" || status=1
done
exit $status
