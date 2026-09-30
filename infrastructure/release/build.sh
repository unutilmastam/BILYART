#!/usr/bin/env bash
# Builds the production package: bilyart-<version>.zip (+ .sha256) in <out>.
#   infrastructure/release/build.sh <version> [out-dir]
# Contents: apps/api (no tests), vendor (--no-dev), the admin + tablet PWA builds in public/,
# the deploy scripts and a VERSION file. Nothing secret: .env is never packaged.
set -euo pipefail
version="${1:?usage: build.sh <version> [out-dir]}"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || { echo "version must be semver" >&2; exit 1; }
root="$(cd "$(dirname "$0")/../.." && pwd)"
out="$(mkdir -p "${2:-$root/release-out}" && cd "${2:-$root/release-out}" && pwd)"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
pkg="$stage/bilyart-$version"
mkdir -p "$pkg"

echo "== web builds"
for app in web-admin tablet; do
  if [ "${SKIP_WEB_BUILD:-0}" != "1" ]; then (cd "$root/apps/$app" && npm ci --no-audit --no-fund && npm run build); fi
  test -f "$root/apps/$app/dist/index.html" || { echo "apps/$app/dist missing" >&2; exit 1; }
done

echo "== copy tracked API files (working tree, tests excluded)"
(cd "$root" && git ls-files -z --cached --others --exclude-standard apps/api \
  | grep -zv -e '^apps/api/tests/' -e '^apps/api/phpunit.xml' -e '^apps/api/scripts/' \
  | tar --null -T - -cf -) | tar -x -C "$pkg"

echo "== composer (production)"
(cd "$pkg/apps/api" && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative --no-progress)
# Source installs (fallback when dist zips are unreachable) bring whole git histories — never ship them.
find "$pkg/apps/api/vendor" -name .git -type d -prune -exec rm -rf {} +

echo "== PWA builds into public/"
cp -a "$root/apps/web-admin/dist" "$pkg/apps/api/public/admin"
cp -a "$root/apps/tablet/dist" "$pkg/apps/api/public/tablet"

echo "== storage skeleton (on the server storage/ is replaced by a link to shared/storage)"
for d in app/private app/public framework/cache/data framework/sessions framework/views logs; do mkdir -p "$pkg/apps/api/storage/$d"; done
rm -f "$pkg/apps/api/bootstrap/cache/"*.php

cp "$root/infrastructure/deploy/"*.sh "$pkg/"
chmod +x "$pkg/"*.sh
echo "$version" > "$pkg/VERSION"
git -C "$root" rev-parse HEAD > "$pkg/COMMIT" 2>/dev/null || true

echo "== smoke: the framework boots and every route resolves"
(cd "$pkg/apps/api" && APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" php artisan route:list --json > /dev/null)

(cd "$stage" && zip -qr -X "$out/bilyart-$version.zip" "bilyart-$version")
(cd "$out" && sha256sum "bilyart-$version.zip" > "bilyart-$version.zip.sha256" && cat "bilyart-$version.zip.sha256")
echo "package: $out/bilyart-$version.zip ($(du -h "$out/bilyart-$version.zip" | cut -f1))"
