# apps/android-kiosk — NBX Kiosk (Android)

A minimal, dependency-free Kotlin shell that locks a tablet onto the hall kiosk PWA (`https://<domain>/tablet/`).
The PWA does all the work; this app only keeps customers inside it. Owner guide: [docs/TABLET_SETUP.md](../../docs/TABLET_SETUP.md) §A.

| | |
|---|---|
| Lock | Device Owner (QR provisioning) → Lock Task with `LOCK_TASK_FEATURE_NONE`, persistent HOME activity, keyguard + status bar disabled, `STAY_ON_WHILE_PLUGGED_IN`, camera pre-granted, no safe boot. Without Device Owner: Android screen pinning. |
| WebView | Only `https://<domain>/tablet/…` may load; camera granted only to that origin; no file access, no new windows, no long-press menus; renderer crash → restart; main-frame error → offline screen + retry every 10 s. |
| Staff exit | 7 taps in the top-left corner within 4 s → admin PIN (PBKDF2, 5 tries → 5 min lock) → reload / change PIN / Android settings / exit lock / remove kiosk mode. |
| Provisioning | `ProvisioningModeActivity` (fully managed) + `PolicyComplianceActivity` for Android 10+; `AdminReceiver` is the device admin component. |

## Build
Only GitHub Actions builds it (CLAUDE.md §0):
- CI job **Android kiosk** — `gradle assembleRelease lintRelease` (debug-key signed, not for tablets).
- **Release package** — `infrastructure/release/build-kiosk.sh` signs with the `KIOSK_KEYSTORE_BASE64` / `KIOSK_KEYSTORE_PASSWORD` secrets (alias `nbx-kiosk`), checks the APK certificate against the key and writes `nbx-kiosk.apk` + `provisioning.json` into the package at `apps/api/public/kiosk/`. The admin panel (Qurilmalar → Kiosk ilova) builds the provisioning QR from that file.

The signing key must never change: provisioning checks its certificate and Android only installs updates signed with the same key. It lives only in GitHub secrets (and the owner's private backup) — never in Git.
