#!/usr/bin/env bash
# Builds a one-time hosting check file with a random name and random token.
# Usage: build.sh <out-dir>
# Output: <out-dir>/hostcheck-<rand>.php and <out-dir>/OCHISH.txt (Uzbek instructions with the link path).
set -euo pipefail

out="${1:?usage: build.sh <out-dir>}"
here="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$out"

token="$(openssl rand -hex 24)"
suffix="$(openssl rand -hex 6)"
file="hostcheck-${suffix}.php"

sed "s/__HOSTCHECK_TOKEN__/${token}/" "$here/hostcheck.php" > "$out/$file"
grep -q "$token" "$out/$file"

cat > "$out/OCHISH.txt" <<TXT
1) cPanel -> File Manager -> public_html papkasini oching.
2) Upload tugmasi -> ${file} faylini yuklang.
3) Telefon brauzerida oching (DOMEN o'rniga saytingiz manzili):

   https://DOMEN/${file}?t=${token}

4) Sahifadagi JSON matnni to'liq nusxalab Claude'ga yuboring.
   Fayl birinchi ochilishdan keyin o'zini o'chiradi (24 soatdan keyin ham ishlamaydi).
TXT

echo "$out/$file"
