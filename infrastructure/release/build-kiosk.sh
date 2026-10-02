#!/usr/bin/env bash
# Builds the signed tablet kiosk APK (apps/android-kiosk) for the release package:
#   infrastructure/release/build-kiosk.sh <version> <out-dir>
# Needs: JDK 17, Android SDK (ANDROID_HOME, preinstalled on GitHub runners), gradle on PATH, and the
# signing key from GitHub secrets: KIOSK_KEYSTORE_BASE64 + KIOSK_KEYSTORE_PASSWORD (docs/TABLET_SETUP.md §A).
# Without the key nothing is built (exit 0 with a warning): an APK signed with a throw-away key could
# never be updated and would not match the provisioning QR.
# Output (served by the hosting at /kiosk/): nbx-kiosk.apk, provisioning.json, .htaccess.
set -euo pipefail
version="${1:?usage: build-kiosk.sh <version> <out-dir>}"
out="${2:?usage: build-kiosk.sh <version> <out-dir>}"
[[ "$version" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)(-[0-9A-Za-z.-]+)?$ ]] || { echo "version must be semver" >&2; exit 1; }
# Monotonic versionCode from the version: 1.0.6 → 10006 (minor/patch < 100).
version_code=$(( BASH_REMATCH[1] * 10000 + BASH_REMATCH[2] * 100 + BASH_REMATCH[3] ))
root="$(cd "$(dirname "$0")/../.." && pwd)"
kiosk_url="${KIOSK_BASE_URL:-https://nbx.itcode.uz}/tablet/"

if [ -z "${KIOSK_KEYSTORE_BASE64:-}" ] || [ -z "${KIOSK_KEYSTORE_PASSWORD:-}" ]; then
  echo "::warning::KIOSK_KEYSTORE_BASE64 / KIOSK_KEYSTORE_PASSWORD secrets are not set — the kiosk APK is not built (docs/TABLET_SETUP.md §A)."
  exit 0
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
ks="$work/kiosk.jks"
printf '%s' "$KIOSK_KEYSTORE_BASE64" | tr -d ' \r\n' | base64 -d > "$ks"
export KIOSK_KEYSTORE_FILE="$ks"

echo "== gradle assembleRelease ($version / $version_code → $kiosk_url)"
(cd "$root/apps/android-kiosk" && gradle --no-daemon -q assembleRelease \
  -PversionName="$version" -PversionCode="$version_code" -PkioskUrl="$kiosk_url")
apk="$root/apps/android-kiosk/app/build/outputs/apk/release/app-release.apk"
test -f "$apk" || { echo "release APK missing (unsigned?)" >&2; exit 1; }

# Provisioning checksum = SHA-256 of the signing certificate, URL-safe base64 without padding.
keytool -exportcert -alias nbx-kiosk -keystore "$ks" -storepass "$KIOSK_KEYSTORE_PASSWORD" > "$work/cert.der"
cert_hex=$(sha256sum "$work/cert.der" | cut -d' ' -f1)
checksum=$(openssl dgst -binary -sha256 "$work/cert.der" | base64 | tr '+/' '-_' | tr -d '=\n')
# The APK must really be signed with that certificate.
apksigner="$(ls -d "$ANDROID_HOME"/build-tools/*/ | sort -V | tail -1)apksigner"
signed_hex=$("$apksigner" verify --print-certs "$apk" | sed -n 's/.*certificate SHA-256 digest: //p' | head -1)
[ "$signed_hex" = "$cert_hex" ] || { echo "APK certificate ($signed_hex) != keystore certificate ($cert_hex)" >&2; exit 1; }

mkdir -p "$out"
cp "$apk" "$out/nbx-kiosk.apk"
apk_sha=$(sha256sum "$out/nbx-kiosk.apk" | cut -d' ' -f1)
cat > "$out/provisioning.json" <<JSON
{
  "version": "$version",
  "versionCode": $version_code,
  "apk": "nbx-kiosk.apk",
  "apkSha256": "$apk_sha",
  "signatureChecksum": "$checksum",
  "component": "uz.nbx.kiosk/uz.nbx.kiosk.AdminReceiver"
}
JSON
cat > "$out/.htaccess" <<'HT'
AddType application/vnd.android.package-archive .apk
AddType application/json .json
<IfModule mod_headers.c>
    Header always set Cache-Control "no-cache"
</IfModule>
HT
echo "kiosk: $out/nbx-kiosk.apk ($(du -h "$out/nbx-kiosk.apk" | cut -f1)), sha256 $apk_sha, signature checksum $checksum"
