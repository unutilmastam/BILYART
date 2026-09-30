#!/usr/bin/env bash
# Integration test for hostcheck.php using PHP's built-in web server.
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
work="$(mktemp -d)"
port="${HOSTCHECK_TEST_PORT:-18765}"
pid=""
cleanup() { [ -n "$pid" ] && kill "$pid" 2>/dev/null || true; rm -rf "$work"; }
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "ok - $*"; }

php -l "$here/hostcheck.php" >/dev/null

built="$("$here/build.sh" "$work/dist")"
file="$(basename "$built")"
token="$(sed -n "s/^const HOSTCHECK_TOKEN = '\([0-9a-f]*\)';/\1/p" "$built")"
[ "${#token}" -eq 48 ] || fail "token not injected"
grep -q "$token" "$work/dist/OCHISH.txt" || fail "instructions missing token"
pass "build injects random token and writes instructions"

docroot="$work/public"
mkdir -p "$docroot"
cp "$built" "$docroot/$file"
cp "$here/hostcheck.php" "$docroot/unbuilt.php"
cp "$built" "$docroot/expired.php"
touch -d '2 days ago' "$docroot/expired.php"

HOSTCHECK_SKIP_OUTBOUND=1 php -S "127.0.0.1:$port" -t "$docroot" >/dev/null 2>&1 &
pid=$!
for _ in $(seq 1 50); do curl -s "http://127.0.0.1:$port/" >/dev/null 2>&1 && break; sleep 0.1; done

code() { curl -s -o "$work/body" -w '%{http_code}' "http://127.0.0.1:$port/$1"; }

[ "$(code unbuilt.php?t=__HOSTCHECK_TOKEN__)" = 403 ] || fail "unbuilt file must refuse"
pass "unbuilt file refuses to run"

[ "$(code "$file")" = 404 ] || fail "missing token must 404"
[ "$(code "$file?t=wrong")" = 404 ] || fail "wrong token must 404"
[ -f "$docroot/$file" ] || fail "file must survive bad token"
pass "missing/wrong token returns 404 and keeps file"

[ "$(code "expired.php?t=$token")" = 410 ] || fail "expired must 410"
[ ! -f "$docroot/expired.php" ] || fail "expired file must be deleted"
pass "expired file returns 410 and deletes itself"

[ "$(code "$file?t=$token")" = 200 ] || fail "valid token must 200"
grep -q '&quot;version&quot;: &quot;' "$work/body" || fail "report missing php version"
grep -q '<th>PHP</th>' "$work/body" || fail "summary table missing"
if grep -qiE 'DB_PASSWORD|APP_KEY|PATH=' "$work/body"; then fail "report leaks environment"; fi
sleep 0.2
[ ! -f "$docroot/$file" ] || fail "file must delete itself after view"
pass "valid token shows report and file deletes itself"

[ "$(code "$file?t=$token")" = 404 ] || fail "second view must be gone"
pass "second view is not possible"

echo "all hostcheck tests passed"
