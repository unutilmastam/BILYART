# PROGRESS

Updated by Claude Code at the end of every session.

| Phase | Status | Notes |
|---|---|---|
| 0. Architecture pack | DONE | CLAUDE.md + docs prepared before coding |
| 1. Inspect repo & hosting | IN PROGRESS | Skeleton, CI, hosting-check tooling done. cPanel Tools screenshot + domain received. Waiting for hostcheck page screenshot + Resource Usage / Domains / PHP screenshots. |
| 2–16 | TODO | see ROADMAP.md |

## Phase 1 — session 1 (2026-09-30)
Done:
- Repo skeleton: `apps/{api,web-admin,tablet,android-kiosk}`, `devices/esp32`, `packages/protocol`, `infrastructure/` (README per folder), `.editorconfig`, root README layout table.
- `infrastructure/hosting-check/hostcheck.php`: one-time, token-protected, self-deleting (first view / 24 h) capability report. `build.sh` injects random name + token. Integration tests `tests/run.sh` (PHP built-in server).
- Workflows: `ci.yml` (gitleaks full history + hosting-check tests), `hosting-check.yml` (manual, produces the upload artifact).
- Owner guide in Uzbek: `docs/HOSTING_CHECK_UZ.md`. DEPLOYMENT.md §1 results table expanded with what each item affects.

Remaining for Phase 1:
- Owner sends cPanel screenshots + hostcheck JSON → record in DEPLOYMENT.md §1, adjust ARCHITECTURE.md if anything differs (e.g. no SSH → FTPS deploy, MariaDB version vs generated column/CHECK support, cron PHP path).
- Owner answers the open questions below.

## Phase 2 — session 3 (2026-09-30)
- Owner decisions: build server/admin first, tablet + ESP32 later; first ESP32 flash from a computer.
- Stack update: Laravel 13 (Laravel 11 is out of security support), DB layer portable across MySQL 8 / MariaDB 10.6 / PostgreSQL 13 (both engines exist on the hosting; versions unknown).
- `packages/protocol`: 17 schemas (device + tablet + common), examples (valid/invalid), generated `src/generated/protocol.ts`, Ajv validator, OpenAPI validation test. CI job `protocol`.
- `docs/API.md` (all endpoints, conventions, error codes) + `docs/openapi.yaml` (3.1 skeleton referencing the protocol schemas).

## Phase 3 — session 4 (2026-09-30)
- Owner: no deployment now (all work in GitHub, move to hosting at the end); apps are PWAs (no native kiosk shell).
- `apps/api`: Laravel 13.34 skeleton trimmed to API; migrations for every table in DATABASE.md with composite tenant FKs, CHECK constraints, double-booking guard (generated column on MySQL/MariaDB, partial unique index on PostgreSQL).
- Design changes recorded in DATABASE.md: devices/tablets are registration rows (tenant set once, re-pair = new row), tablet bearer token hashed in `tablets.token_hash` (no Sanctum PATs), 3-column FKs (tenant, branch, table), ISO weekdays.
- `TenantContext` + `TenantScope` (fails closed) + `BelongsToTenant`; `TenantAgnosticUserProvider` for auth only.
- Tests: DB-level cross-tenant rejection (tables, sessions, devices, photos, payment marker), user role/tenant CHECK, double booking for all 16 occupying pairs, session integrity CHECKs, scope behaviour, subscription status maths.
- CI job `api`: MySQL 8 / MariaDB 10.6 / PostgreSQL 13 on PHP 8.3 + MySQL on PHP 8.4; pint + composer audit; migrate → rollback → migrate.

## Phase 4 — session 4 (2026-09-30)
- Auth: `POST /api/auth/login|logout`, `GET /api/me`, `PUT /api/me/password`, `GET /api/auth/csrf`; `LoginService` lockout (5 → 15 min), per-IP+login throttle, audit `auth.*`.
- Pipeline middlewares: `ResolveUserTenant` (before route binding), `EnsureTenantUser`, `EnsureSuperAdmin`, `RequirePermission` (`perm:`), `EnsureActiveSubscription` (402), `EnsureIdempotency`, `AuthenticateTablet`, `AssignRequestId`, `SecurityHeaders`.
- `config/permissions.php` (pinned by a test), `ApiErrorRenderer` + `ErrorCode` + `lang/uz/errors.php`, `Redactor` + Monolog processor, `AuditLogger`, rate limiters for every principal type, `idempotency:prune` scheduled hourly.
- Fixed: Laravel's `/storage/{path}` file serving disabled (would have exposed private files).
- First read-only admin endpoints: `GET /api/admin/branches[/{branch}]` (used by the isolation tests).

## Phase 5 — session 4 (2026-09-30)
- API `/api/super/*`: dashboard, tenants list (derived-status filter, search, usage counts), create (owner + optional first payment), update, suspend/activate/deactivate, payments (base = max(now, expiry)), extend, set expiry (new event type via migration 2026_10_02_000001), limits (LIMIT_CHANGED old/new), subscription history, owner password reset (temporary password shown once), payments list with sum, audit log with filters, platform settings. `/api/admin/subscription` shows status + payment instructions even when inactive.
- `apps/web-admin`: PWA shell, login, Super Admin pages (dashboard, clients list/create/detail with all actions, payments, audit, settings), client home with subscription status. 12 vitest tests (format, API client, login flow, guards).
- Laravel serves the PWA shell for `/admin/*` deep links (`SpaController`). CI job `web-admin` uploads the `web-admin-dist` artifact.

## Phase 6 — session 4 (2026-09-30)
- `LimitGuard` (tenant row `FOR UPDATE`, only active rows count, re-activation re-checked) for branches, tables, users (devices in Phase 10).
- Branches CRUD (+ default 24/7 hours), working hours (ISO weekdays, past-midnight shifts), closed days, `WorkingHoursCalendar` (branch timezone, shift belongs to start day).
- Tables CRUD (unique number per branch, plan must be global or same branch), pricing plans CRUD with live quotes (`PriceCalculator`: integer maths, round *up* to step), staff (owner/manager rules, last-owner protection, no self-deactivation, per-branch restriction via `BranchAccess`), tenant settings (privacy notice, retention, photo required, warning text/minutes, operators-can-view-photos), tenant audit log.
- Bug caught by the 3-DB matrix: PostgreSQL rejects `COUNT(*) … FOR UPDATE` → lock rows then count.
- web-admin client area: branches (+ hours editor, closed days), tables (plan assignment, device status), pricing plans (quotes), staff (roles, branch picker), settings; nav filtered by permissions.

## Phase 7 — session 4 (2026-09-30)
- Server: `SessionStateMachine`, `SessionService` (prepare/start/cancel/confirmStarted/failStart/stop/complete/markPayment), `SessionFinalizer` + `sessions:finalize` (every minute), `TableStatusResolver`, `DeviceCommandBus` (queue only; delivery = Phase 10), `ReportService` (branch-timezone day/month ranges, played minutes, per-table, utilization).
- Tablet API: bootstrap, tables, heartbeat, sessions prepare/start/cancel/show (Idempotency-Key required) — responses validated against `packages/protocol` schemas in tests (opis/json-schema).
- Admin API: sessions list/show (events), stop, manual payment status, live dashboard, daily/monthly reports. web-admin: live dashboard (10 s refresh), sessions list/detail with stop + payment, reports.
- Bugs caught: missing `public_id` in a narrowed select (strict mode), MySQL JSON key reordering in tests.
- Known limitation until Phase 10: nothing ACKs START yet on a real device, so without the device API a started session fails after 20 s (by design).

## Phase 9 (server) — session 4 (2026-09-30)
- `POST /api/tablet/sessions/{id}/photo` (multipart `photo`, throttled, Idempotency-Key), `ImageSanitizer` (finfo + getimagesize, 320–2560 px, ≤ 2 MB, GD re-encode), `PhotoStorage`/`LocalPrivateDisk`, `PhotoService` (store/replace, view, delete, retention), `photos:prune` daily 01:30.
- Admin: `GET/DELETE /api/admin/photos/{photo}`; web-admin session page shows the photo on demand and can delete it.
- Tests: spec §43.11 (other tenant 404, operator 403 unless allowed, super admin 403, guest 401), §43.12 (deleted → 404, file removed, audit), polyglot/EXIF stripping, invalid uploads leave no file, retention per tenant.

## Phase 10 — session 4 (2026-09-30)
- `/device/v1`: register (secret + rate limit), pairing-status (token once), poll (heartbeat + commands, retry 6 s × 3, expiry), ack (idempotent, START→ACTIVE, ERROR→FAILED+STOP, STOP→COMPLETED), state, firmware download (published only, sha256 header).
- `AuthenticateDevice` (hashed token, tenant from device), `DeviceRegistry` (register/pair/move/unpair with LimitGuard), `DeviceGateway`, `TabletRegistry` + `/api/tablet/register|pairing-status`, admin devices/tablets endpoints, Super Admin firmware upload/publish (ESP32 magic byte check).
- Tests: `DeviceSimulator` speaks the real protocol; spec §61 end-to-end (pair → 10-min session → START → warning → internet loss → local OFF → server completes → resync), §43.4, §43.10, retries/expiry, poll ≤ 6 queries, tablet pairing, OTA download rules. 160 API tests on 3 DBs.
- web-admin: Devices page (pair ESP32 by code to a free table, pair tablet to a branch, live online status, unpair/revoke).

## Phase 12 — session 4 (2026-09-30)
- `TelegramClient`/`HttpTelegramClient`, `TelegramService` (configure → getMe + setWebhook with secret, disable, link code, webhook handling, daily reports), `NotificationService` (dedupe_key UNIQUE, in-app + per-chat Telegram logs, delivery), `DeviceMonitor` (+ migration `devices.offline_since`), session-failed alert in `SessionService::failStart`.
- Commands: `telegram:daily-reports` (5 min), `notifications:deliver` (1 min), `devices:monitor` (1 min).
- Tests (Telegram HTTP faked only): token encrypted at rest and absent from every API response/audit/log (§43.15), webhook secret, one-time linking, /report shows only own tenant, daily report exactly once after report_time, alerts once per episode, cross-tenant isolation. 167 API tests on 3 DBs.
- web-admin Telegram page (token write-only, link code with deep link, chats with branch/report/alert toggles, test message, disable).

## Phase 13 — session 4 (2026-09-30)
- `SubscriptionMonitor` + `subscriptions:check` (hourly): calendar-day reminders in the tenant timezone at the platform `reminder_days` (default 5/3/1/0), deduplicated per expiry instant (an extension restarts the cycle), `subscription_expired` + `EXPIRED` event + audit exactly once, mirrored as Super Admin (platform) notifications.
- In-app notifications API for clients and the Super Admin (list, unread count, read, read-all); owner data export (streamed JSON, no photos) allowed while inactive.
- Bug fixed: a streamed download runs after the middleware stack unwinds → tenant context re-established inside the stream callback (would otherwise export nothing — fail-closed scope).
- web-admin: 🔔 notifications with unread badge (client + super), notifications page, export link.

## Open questions for the owner
1. ~~Platform domain~~ → **itcode.uz**. Still open: root domain or a subdomain (e.g. `billiard.itcode.uz`)? Its document root in cPanel → Domains?
2. ~~First ESP32 flash method~~ → from a computer (owner, 2026-09-30).
3. Tablet model and Android version for kiosk testing? Can it be factory-reset (needed for Device Owner QR provisioning)?
4. How many halls/tables for the pilot, and the lamp power per table (for relay/contactor sizing)?
5. Repository is **public**. Recommend making it private (GitHub → Settings → Danger Zone → Change visibility). Note: private repos have a monthly free Actions-minutes limit.
6. Hostmaster plan name (for LVE limits) — visible in the Hostmaster client area.

## Phase 1 — session 2 (2026-09-30)
- Owner sent the cPanel Tools screenshot + domain `itcode.uz` → recorded in DEPLOYMENT.md §1 (SSH, Terminal, Cron, Git VC, MySQL **and PostgreSQL**, Node/Python apps, CloudLinux LVE).
- Owner could not copy JSON → hostcheck page now shows a full screenshot-friendly table; JSON moved to an optional collapsed block.
- Owner authorized Claude to merge PRs itself (CLAUDE.md §2).

## Known issues
- Repository is public: `hosting-check` artifact (1-day retention) is downloadable by any signed-in GitHub user. Mitigated: domain not included in the artifact, file self-deletes on first view and after 24 h, report contains no secrets.
