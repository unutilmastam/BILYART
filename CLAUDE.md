# CLAUDE.md — Billiard SaaS + IoT Platform

You are the lead architect and engineer for this repository. Read this file fully before every task.

## 0. Communication with the owner
- The owner (Bakhrullo) speaks **Uzbek (Latin script)**. Reply to him in Uzbek, short and action-oriented.
- Code, comments, commit messages and docs stay in **English**.
- The owner has **no computer** — he works from a phone/iPad. Therefore:
  - Never ask him to run commands locally. You run tests yourself; GitHub Actions is the second test runner.
  - Every build (web bundles, Android APK, ESP32 firmware .bin) must be produced by **GitHub Actions** as downloadable artifacts.
  - Setup instructions for him must be click-by-click (cPanel UI, GitHub web UI, Android settings).

## 1. Source of truth (read in this order)
1. `docs/SPEC.md` — the original master specification (business requirements). **Never violate it.**
2. `docs/ARCHITECTURE.md` — chosen stack and the decisions resolving contradictions between the spec and the hosting.
3. `docs/DATABASE.md`, `docs/SECURITY.md`, `docs/DEVICE_PROTOCOL.md`, `docs/DEPLOYMENT.md`.
4. `docs/ROADMAP.md` — phases and per-phase acceptance criteria.
5. `docs/PROGRESS.md` — what is done. **Update it at the end of every work session.**

If the SPEC and ARCHITECTURE disagree, ARCHITECTURE wins only where it explicitly documents the reason (hosting limits). Otherwise stop and ask the owner.

## 2. How the owner drives work
The owner will write messages like **"Phase 3 ni boshla"** or **"Phase 3 davom et"**.
For each phase:
1. Read ROADMAP.md for that phase + PROGRESS.md.
2. Write a short plan (in Uzbek) of what you will build in this session.
3. Implement in small commits.
4. Run the tests (`composer test`, `npm test`, `pio test` as relevant). Fix failures.
5. Update docs touched by the phase + PROGRESS.md (done / remaining / known issues).
6. Push to a branch `phase-N-short-name` and open a PR into `main` with a checklist of acceptance criteria.
   **The owner authorized Claude (2026-09-30) to merge the PR itself** — the owner does not merge. Merge only when CI is green on the PR head and no critical errors remain; use a merge commit. A PR may also be merged mid-phase when the owner needs something from `main` (e.g. a manual workflow), as long as CI is green.
7. **Do not start the next phase while critical errors or failing tests remain.**

## 3. Non-negotiable rules
- **Multi-tenant isolation is enforced in the backend and database**, never only in the UI. tenant_id is resolved from the authenticated user/tablet/device — never from request input.
- **No fake features**: no fake payment verification, no CCTV integration of any kind, no face *recognition* (only face *detection* to time the photo), no fake online status, no mocked storage in production code. If a dependency is missing, build an interface + adapter and mark it clearly.
- **Tablet camera = yes (one photo per session). Cash/CCTV camera = not part of this system. Never integrate it.**
- **Backend timestamps are authoritative** (UTC). Device/tablet clocks are only for display using a server-time offset.
- **ESP32 must turn the light OFF on its own at endAt**, even with no network.
- **Money** = integer (UZS), never float.
- **No secrets in Git.** Only `.env.example`. Telegram tokens are encrypted at rest.
- **No localStorage as a database.** Only UI prefs and non-critical cache.
- **One huge file is forbidden.** Follow the module layout in ARCHITECTURE.md.
- Never show raw errors/stack traces to users; log them server-side with an error code.
- Every tenant-owned table has `tenant_id` + composite FKs (see DATABASE.md).
- Concurrency: session start uses a DB transaction + row lock + unique constraint. Idempotency-Key required on all state-changing tablet/device endpoints.

## 4. Repository layout (target)
```
apps/api            Laravel 13 backend (REST API, scheduler, Telegram, storage)
apps/web-admin      React + Vite + TS SPA (Super Admin + Client Admin)
apps/tablet         React + Vite + TS PWA (customer kiosk UI)
apps/android-kiosk  Kotlin WebView shell (Lock Task / kiosk, auto-start, camera)
devices/esp32       PlatformIO (Arduino-ESP32) firmware
packages/protocol   JSON Schemas + TS types for tablet & device protocol (single source)
infrastructure/     deployment scripts, cron definitions, backup scripts
docs/               all documentation
.github/workflows/  CI (tests), build (web, apk, firmware), deploy
```

## 5. Commands (keep this section updated as the project grows)
- API: `cd apps/api && composer install && php artisan test` (needs a real DB; phpunit.xml defaults to MySQL on 127.0.0.1:3306 root/root db `bilyart_test`). All three engines locally: `scripts/test-all-db.sh --start` once, then `scripts/test-all-db.sh`. Style: `vendor/bin/pint --test`
- Admin: `cd apps/web-admin && npm ci && npm test && npm run build`
- Tablet: `cd apps/tablet && npm ci && npm test && npm run build`
- Firmware: `cd devices/esp32 && pio run && pio test -e native`
- Protocol: `cd packages/protocol && npm ci && npm test && npm run typecheck && npm run check-generated` (after editing schemas: `npm run generate`)
- Hosting check script: `infrastructure/hosting-check/tests/run.sh` (build for owner: Actions → "Hosting check (build file)")
- Secret scan: `gitleaks git --redact .` (runs in CI)

## 6. Definition of done for any feature
- Backend authorization + tenant scoping + subscription check applied via central middleware/policies.
- Feature test proving another tenant cannot access it.
- Audit log entry for important actions.
- User-facing strings in Uzbek (Latin) with i18n keys (RU later).
- Docs updated.
