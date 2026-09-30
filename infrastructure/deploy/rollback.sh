#!/usr/bin/env bash
# Switches ~/billiard/current back to the previous release (cPanel → Terminal):
#   bash ~/billiard/current/rollback.sh
# Database migrations are NOT reverted: releases only add backward-compatible migrations.
# For a data problem use a backup instead (docs/BACKUP.md §4).
set -euo pipefail
PHP="${PHP_BIN:-php}"
BASE="${BILYART_HOME:-$HOME/billiard}"
cur="$(readlink -f "$BASE/current")"
prev="$(ls -1dt "$BASE"/releases/*/ | sed 's:/$::' | grep -vx "$cur" | head -n 1 || true)"
[ -n "$prev" ] || { echo "Oldingi versiya topilmadi." >&2; exit 1; }
for c in config:cache route:cache event:cache; do "$PHP" "$prev/apps/api/artisan" "$c"; done
ln -sfn "$prev" "$BASE/current.new"
mv -Tf "$BASE/current.new" "$BASE/current"
echo "Faol versiya endi: $(cat "$prev/VERSION") ($prev)"
