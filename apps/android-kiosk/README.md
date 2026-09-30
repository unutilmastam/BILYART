# apps/android-kiosk

**Not built.** Owner decision (2026-09-30): the kiosk is the tablet PWA (`apps/tablet`) locked with Android App pinning — see [docs/TABLET_SETUP.md](../../docs/TABLET_SETUP.md) §5 and its limitations (§7).

A native Kotlin WebView shell (Lock Task as Device Owner, boot auto-start, crash restart) can be added later if the owner asks; the PWA needs no changes for it.
