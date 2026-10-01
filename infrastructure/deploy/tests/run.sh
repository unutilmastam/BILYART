#!/usr/bin/env bash
# End-to-end test of the release package and the server scripts, in a throw-away $HOME:
#   build → first activate (creates .env, stops) → fill DB → activate → health → update → rollback.
# Needs a reachable empty database given by DB_CONNECTION/DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD.
set -euo pipefail
root="$(cd "$(dirname "$0")/../../.." && pwd)"
work="$(mktemp -d)"
server_pid=""
cleanup() { [ -n "$server_pid" ] && kill "$server_pid" 2>/dev/null || true; rm -rf "$work"; }
trap cleanup EXIT
fail() {
  echo "FAIL: $*" >&2
  tail -n 30 "$work/serve.log" 2>/dev/null >&2 || true
  tail -c 3000 "$work"/home/billiard/shared/storage/logs/*.log 2>/dev/null >&2 || true
  exit 1
}

# The PWA builds are tested elsewhere; stub them when absent so this test stays fast.
for app in web-admin tablet; do
  [ -f "$root/apps/$app/dist/index.html" ] || { mkdir -p "$root/apps/$app/dist"; echo '<!doctype html><title>stub</title>' > "$root/apps/$app/dist/index.html"; }
done
SKIP_WEB_BUILD=1 "$root/infrastructure/release/build.sh" 0.0.1-test "$work/out" > "$work/build.log" 2>&1 || { tail -40 "$work/build.log"; fail "package build"; }
zip="$work/out/bilyart-0.0.1-test.zip"
(cd "$work/out" && sha256sum -c bilyart-0.0.1-test.zip.sha256 >/dev/null) || fail "checksum"
unzip -l "$zip" | grep -q 'apps/api/.env$' && fail ".env must never be packaged"
unzip -l "$zip" | grep -q 'apps/api/tests/' && fail "tests must not be packaged"
unzip -l "$zip" | grep -q '/vendor/.*/\.git/' && fail "vendor git histories must not be packaged"

home="$work/home"; mkdir -p "$home"; base="$home/billiard"
unpack() { (cd "$home" && rm -rf "bilyart-$1" && unzip -q "$zip" && { [ "$1" = 0.0.1-test ] || mv bilyart-0.0.1-test "bilyart-$1"; } && echo "$1" > "bilyart-$1/VERSION"); }

echo "== first run creates .env and stops"
unpack 0.0.1-test
set +e; HOME="$home" bash "$home/bilyart-0.0.1-test/activate.sh" > "$work/a1.log" 2>&1; rc=$?; set -e
[ $rc -eq 2 ] || { cat "$work/a1.log"; fail "first run exit $rc (expected 2)"; }
[ "$(stat -c %a "$base/shared/.env")" = 600 ] || fail ".env permissions"
grep -qE '^APP_KEY=base64:.{40,}' "$base/shared/.env" || fail "APP_KEY generated"
grep -qE '^BACKUP_ENCRYPTION_KEY=.{40,}' "$base/shared/.env" || fail "backup key generated"
[ ! -e "$base/current" ] || fail "nothing may be switched before the database is configured"

for k in DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do sed -i "s|^$k=.*|$k=${!k}|" "$base/shared/.env"; done
sed -i -e 's|^APP_URL=.*|APP_URL=http://127.0.0.1:8199|' -e 's|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|' "$base/shared/.env"

echo "== install"
HOME="$home" bash "$home/bilyart-0.0.1-test/activate.sh" > "$work/a2.log" 2>&1 || { cat "$work/a2.log"; fail "install"; }
[ "$(cat "$base/current/VERSION")" = 0.0.1-test ] || fail "current → 0.0.1"
[ -L "$base/current/apps/api/storage" ] && [ -L "$base/current/apps/api/.env" ] || fail "shared links"
[ -f "$base/current/apps/api/bootstrap/cache/config.php" ] || fail "config cached"
# cPanel's web server is another user: it must traverse to public/ and read .htaccess + index.php.
[ "$(stat -c %a "$base")" = 711 ] || fail "base dir must be 711 (was $(stat -c %a "$base"))"
pub="$(readlink -f "$base/current/apps/api/public")"
d="$pub"; while [ "$d" != "$base" ] && [ "$d" != / ]; do [ $((0$(stat -c %a "$d") & 1)) -eq 1 ] || fail "others cannot traverse $d"; d="$(dirname "$d")"; done
for f in .htaccess index.php; do [ $((0$(stat -c %a "$pub/$f") & 4)) -eq 4 ] || fail "others cannot read public/$f"; done

echo "== serve + health (db, storage)"
(cd "$base/current/apps/api/public" && exec php -S 127.0.0.1:8199 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php > "$work/serve.log" 2>&1) & server_pid=$!
sleep 2
token="$(grep ^HEALTH_TOKEN= "$base/shared/.env" | cut -d= -f2)"
curl -fsS -H "Authorization: Bearer $token" http://127.0.0.1:8199/health/db | grep -q '"status":"ok"' || fail "health/db"
[ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8199/admin/)" = 200 ] || fail "admin shell"
[ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8199/tablet/)" = 200 ] || fail "tablet shell"
php "$base/current/apps/api/artisan" schedule:list > /dev/null || fail "scheduler with cached config"

echo "== update to 0.0.2 keeps shared data"
touch "$base/shared/storage/app/private/keep.txt"
unpack 0.0.2-test
HOME="$home" bash "$home/bilyart-0.0.2-test/activate.sh" > "$work/a3.log" 2>&1 || { cat "$work/a3.log"; fail "update"; }
[ "$(cat "$base/current/VERSION")" = 0.0.2-test ] || fail "current → 0.0.2"
[ -f "$base/current/apps/api/storage/app/private/keep.txt" ] || fail "shared storage kept"
[ ! -e "$base/current/../../shared/storage/framework/down" ] || fail "left in maintenance mode"

echo "== rollback"
HOME="$home" bash "$base/current/rollback.sh" > "$work/r.log" 2>&1 || { cat "$work/r.log"; fail "rollback"; }
[ "$(cat "$base/current/VERSION")" = 0.0.1-test ] || fail "rollback → 0.0.1"

echo "OK: package + activate + update + rollback"
