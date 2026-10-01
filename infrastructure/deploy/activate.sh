#!/usr/bin/env bash
# Installs or updates Bilyart on the hosting (cPanel → Terminal). Run from the unpacked package:
#   cd ~ && unzip -q bilyart-1.2.0.zip && bash bilyart-1.2.0/activate.sh
# Layout (docs/DEPLOYMENT.md §2):
#   ~/billiard/releases/<version>-<time>/   one directory per release
#   ~/billiard/shared/.env                  secrets (created on the first run, chmod 600)
#   ~/billiard/shared/storage/              photos, backups, logs — survive every update
#   ~/billiard/current -> releases/…        the domain's document root is ~/billiard/current/apps/api/public
# Safe to re-run. Nothing is switched unless migrations and caches succeed.
set -euo pipefail

PHP="${PHP_BIN:-php}"
BASE="${BILYART_HOME:-$HOME/billiard}"
PKG="$(cd "$(dirname "$0")" && pwd)"
VERSION="$(cat "$PKG/VERSION")"
say() { printf '\n== %s\n' "$*"; }
die() { printf '\nXATO / ERROR: %s\n' "$*" >&2; exit 1; }

say "Bilyart $VERSION → $BASE"

# 1. PHP requirements (Laravel 13).
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || die "PHP >= 8.3 kerak (cPanel → Select PHP Version). Hozir: $("$PHP" -r 'echo PHP_VERSION;')"
missing=""
# From composer.lock (ext-*) + what the app itself uses: gd (photo re-encode), zip (backups).
for ext in pdo openssl mbstring fileinfo ctype tokenizer dom filter hash json session zip gd; do
  "$PHP" -m | grep -qi "^$ext\$" || missing="$missing $ext"
done
"$PHP" -m | grep -qiE '^(pdo_mysql|pdo_pgsql)$' || missing="$missing pdo_mysql"
[ -z "$missing" ] || die "PHP kengaytmalari yetishmaydi:$missing (cPanel → Select PHP Version → Extensions)"

mkdir -p "$BASE/releases" "$BASE/shared"
# 711: the web server (another user on cPanel) must traverse into current/apps/api/public;
# no listing for others. Secrets stay private: shared/.env is 600 and storage is go-rwx.
chmod 711 "$BASE"

# 2. Shared storage (first run: seeded from the package skeleton).
if [ ! -d "$BASE/shared/storage" ]; then
  cp -a "$PKG/apps/api/storage" "$BASE/shared/storage"
  chmod -R u+rwX,go-rwx "$BASE/shared/storage"
fi

# 3. Secrets file. First run: create it with generated keys and stop so the owner fills in the database.
ENV="$BASE/shared/.env"
if [ ! -f "$ENV" ]; then
  cp "$PKG/apps/api/.env.example" "$ENV"
  chmod 600 "$ENV"
  appkey="base64:$("$PHP" -r 'echo base64_encode(random_bytes(32));')"
  backupkey="$("$PHP" -r 'echo base64_encode(random_bytes(32));')"
  health="$("$PHP" -r 'echo bin2hex(random_bytes(24));')"
  sed -i -e "s|^APP_KEY=.*|APP_KEY=$appkey|" -e "s|^BACKUP_ENCRYPTION_KEY=.*|BACKUP_ENCRYPTION_KEY=$backupkey|" -e "s|^HEALTH_TOKEN=.*|HEALTH_TOKEN=$health|" "$ENV"
  say "Yaratildi: $ENV"
  echo "APP_KEY, BACKUP_ENCRYPTION_KEY va HEALTH_TOKEN avtomatik yozildi."
  echo "BACKUP_ENCRYPTION_KEY ni server tashqarisida ham saqlang (docs/BACKUP.md §2)."
  echo "Endi shu faylni oching (File Manager → billiard/shared/.env → Edit) va to'ldiring:"
  echo "  APP_URL, DB_CONNECTION, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DEVICE_REGISTRATION_SECRET, TELEGRAM_WEBHOOK_BASE_URL"
  echo "Keyin shu buyruqni qayta ishga tushiring:  bash $PKG/activate.sh"
  exit 2
fi
for key in APP_KEY APP_URL DB_CONNECTION DB_DATABASE DB_USERNAME BACKUP_ENCRYPTION_KEY; do
  grep -qE "^$key=.+" "$ENV" || die "$ENV ichida $key bo'sh. To'ldirib, qayta ishga tushiring."
done
grep -qE '^DEVICE_REGISTRATION_SECRET=.{16,}' "$ENV" || echo "OGOHLANTIRISH: DEVICE_REGISTRATION_SECRET bo'sh — ESP32 qurilmalar ro'yxatdan o'ta olmaydi (docs/ESP32_FLASHING.md §0)."

# 4. New release directory wired to the shared files.
TARGET="$BASE/releases/$VERSION-$(date +%Y%m%d%H%M%S)"
say "Nusxa: $TARGET"
cp -a "$PKG" "$TARGET"
rm -rf "$TARGET/apps/api/storage"
ln -s "$BASE/shared/storage" "$TARGET/apps/api/storage"
ln -sf "$ENV" "$TARGET/apps/api/.env"
ART=("$PHP" "$TARGET/apps/api/artisan")

"${ART[@]}" about --only=environment >/dev/null || die "Ilova ishga tushmadi. .env ni tekshiring."

# 5. Maintenance window only around the migration.
PREV=""
if [ -L "$BASE/current" ]; then PREV="$(readlink -f "$BASE/current")"; fi
if [ -n "$PREV" ]; then "$PHP" "$PREV/apps/api/artisan" down --retry=15 || true; fi
restore_prev() { if [ -n "$PREV" ]; then "$PHP" "$PREV/apps/api/artisan" up || true; fi; }

say "Ma'lumotlar bazasi (migrate)"
if ! "${ART[@]}" migrate --force; then restore_prev; rm -rf "$TARGET"; die "Migratsiya muvaffaqiyatsiz. Eski versiya ishlashda davom etadi."; fi
# No Blade views in this JSON API + static PWAs, so view:cache is skipped.
if ! { "${ART[@]}" config:cache && "${ART[@]}" route:cache && "${ART[@]}" event:cache; }; then restore_prev; rm -rf "$TARGET"; die "Kesh yaratilmadi. Eski versiya ishlashda davom etadi."; fi

# 6. Atomic switch.
ln -sfn "$TARGET" "$BASE/current.new"
mv -Tf "$BASE/current.new" "$BASE/current"
"${ART[@]}" up || true
say "Faol versiya: $VERSION"

# 7. Keep the newest 3 releases (current + 2 for rollback).
ls -1dt "$BASE"/releases/*/ | tail -n +4 | while read -r old; do
  [ "$(readlink -f "$old")" = "$(readlink -f "$BASE/current")" ] || rm -rf "$old"
done

# 8. Quick health check through the web server (non-fatal: DNS/SSL may still be pending on the first install).
url="$(grep -E '^APP_URL=' "$ENV" | cut -d= -f2- | tr -d '"')"
if command -v curl >/dev/null; then
  say "Tekshiruv: $url/health"
  curl -fsS --max-time 15 "$url/health" && echo || echo "(/health javob bermadi — domen hujjat ildizi va SSL ni tekshiring, docs/DEPLOY_UZ.md)"
fi

cat <<INFO

Tayyor. Birinchi o'rnatishda (bir marta):
  • cPanel → Domains → domen → Document Root:  ${BASE#$HOME/}/current/apps/api/public
  • cPanel → Cron Jobs → Once Per Minute:
      $(command -v "$PHP") $BASE/current/apps/api/artisan schedule:run >> /dev/null 2>&1
  • Super Admin:  $PHP $BASE/current/apps/api/artisan admin:create-super <login>
Orqaga qaytarish (oldingi versiya):  bash $BASE/current/rollback.sh
INFO
rm -rf "$PKG"
