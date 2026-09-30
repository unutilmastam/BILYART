# ROADMAP

Build order (owner, 2026-09-30): server + admin first — 2, 3, 4, 5, 6, 7, 9 (server), 10, 12, 13, 14, 15 — then 8, 9 (tablet part), 11, 16.

Each phase = one or more PRs. Owner starts a phase by writing **"Phase N ni boshla"**. A phase is done only when all its checkboxes are true and CI is green.

## Phase 1 — Inspect repo & hosting
- [x] Repo skeleton: folders from CLAUDE.md §4, root README, `.gitignore`, `.editorconfig`
- [~] Owner answers the DEPLOYMENT.md §1 checklist — Tools screenshot + domain received; hostcheck report deferred to before Phase 16 (owner decision)
- [x] Results recorded in DEPLOYMENT.md; ARCHITECTURE.md adjusted (portable DB, Laravel 13)
- [x] List of open questions for the owner (domain, first flash method, tablet model) — see PROGRESS.md

## Phase 2 — Architecture docs finalized
- [x] ARCHITECTURE / DATABASE / SECURITY / DEVICE_PROTOCOL / DEPLOYMENT reviewed against SPEC.md, contradictions list confirmed with owner
- [x] `packages/protocol`: JSON Schemas for device + tablet messages, generated TS types
- [x] `docs/API.md` outline + OpenAPI file skeleton

## Phase 3 — Database schema & migrations
- [x] Laravel 13 app in `apps/api`, CI with MySQL 8 + MariaDB 10.6 + PostgreSQL services
- [x] All migrations from DATABASE.md incl. composite FKs, generated `table_lock` unique column (partial unique index on PostgreSQL)
- [x] Models + `BelongsToTenant` trait + factories
- [x] Tests: cross-tenant FK insert fails at DB level; double active session insert fails at DB level

## Phase 4 — Auth & multi-tenancy
- [x] SPA session auth (same-origin, no Sanctum needed), login/logout/me, lockout, rate limits
- [x] `config/permissions.php`, central middleware pipeline (policies for per-resource rules come with each feature)
- [x] Idempotency middleware
- [x] Error shape + log redaction
- [x] Tests: spec §43 items 1–3, 13

## Phase 5 — Super Admin
- [ ] API: tenants CRUD, suspend/activate/deactivate, payments, extend (both rules), limits, subscription history, audit list, dashboard stats
- [ ] web-admin: Super Admin area (mobile-first)
- [ ] Tests: extend from active vs expired, limit change audit

## Phase 6 — Client Admin
- [ ] Branches (LimitGuard), working hours, closed days, users & roles, pricing plans, tenant settings
- [ ] web-admin client area + dashboard
- [ ] Tests: spec §61 branch limit scenario (2 → denied → limit 3 → success), §43 item 7–8

## Phase 7 — Tables & session engine
- [ ] Tables CRUD, SessionStateMachine, SessionService, PriceCalculator, working-hours check
- [ ] prepare/start/stop/complete, derived status, cron finalization, payment status marking
- [ ] Tests: concurrency (parallel start → exactly one wins), duplicate Idempotency-Key, state transitions, pricing rounding

## Phase 8 — Tablet app + kiosk shell
- [ ] Tablet pairing (code on screen → admin binds to branch)
- [ ] PWA screens, server-time offset, offline cache, countdown, warning audio (uploaded clips + TTS fallback)
- [ ] ~~`android-kiosk` Kotlin shell~~ — owner chose PWA only (2026-09-30); kiosk = Android App pinning, documented limitations
- [ ] `docs/TABLET_SETUP.md` (QR provisioning, click-by-click)

## Phase 9 — Photo capture & upload
- [ ] MediaPipe face detection → single capture, privacy notice
- [ ] Secure upload pipeline (validation, re-encode, private storage), authenticated viewing, deletion, retention job, access audit
- [ ] Tests: spec §43 items 11–12

## Phase 10 — Device service (server side)
- [ ] Device register/pairing/poll/ack/state endpoints, token auth, command bus + retry/expiry, online/offline
- [ ] START on session start, ACK → ACTIVE, no-ACK → FAILED + STOP
- [ ] Device simulator script (tests only) to exercise the protocol end-to-end
- [ ] Tests: spec §43 items 4, 10; poll endpoint performance check

## Phase 11 — ESP32 firmware
- [ ] Provisioning portal, pairing, pinned TLS, poll loop, commands, NVS session, local warning/OFF, watchdog, safe boot, OTA with rollback, diagnostics
- [ ] Native unit tests for session timing & command idempotency
- [ ] `docs/ESP32_FLASHING.md`, `docs/HARDWARE.md` (wiring diagram, relay/contactor sizing, electrician review note)

## Phase 12 — Telegram
- [ ] Per-tenant bot setup, webhook with secret, chat linking, daily report per branch, alerts (device offline, session failed)
- [ ] Tests: tenant bot only sees own data; token never returned by API (§43 item 15)

## Phase 13 — Subscription expiry & notifications
- [ ] Status transitions via cron, reminders 5/3/1/0 days (deduped), in-app + Telegram, expired lock-down behaviour
- [ ] Tests: §43 items 5–6

## Phase 14 — Monitoring, logging, backups
- [ ] `/health`, `/health/db`, `/health/storage`, `/health/messaging`, Super Admin health page
- [ ] Backup commands + verify + `docs/BACKUP.md`

## Phase 15 — Security testing
- [ ] All spec §43 tests green, gitleaks clean, dependency audit (`composer audit`, `npm audit`), manual review checklist

## Phase 16 — Production deployment
- [ ] deploy.yml to Hostmaster, cron configured, SSL, first Super Admin, smoke test
- [ ] Full spec §61 scenario on real hardware (incl. internet disconnect during session)
- [ ] Docs: SUPER_ADMIN_GUIDE, CLIENT_ADMIN_GUIDE, TROUBLESHOOTING (Uzbek versions for end users)
