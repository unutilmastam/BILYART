# PROGRESS

Updated by Claude Code at the end of every session.

| Phase | Status | Notes |
|---|---|---|
| 0. Architecture pack | DONE | CLAUDE.md + docs prepared before coding |
| 1. Inspect repo & hosting | IN PROGRESS | Skeleton, CI, hosting-check tooling done. cPanel Tools screenshot + domain received. Waiting for hostcheck page screenshot + Resource Usage / Domains / PHP screenshots. |
| 2–7 | DONE | protocol, Laravel + DB, auth/tenancy, Super Admin, client admin, sessions |
| 8. Tablet PWA | DONE | kiosk PWA + TABLET_SETUP.md (App pinning) |
| 9. Photos | DONE | server + tablet camera (face detection, one photo) |
| 10, 12, 13, 14 | DONE | devices/tablets server side, Telegram, notifications/subscriptions, monitoring/backups |
| 11. ESP32 firmware | DONE (code) | one ESP32 per branch, up to 8 lamps; builds in CI; on-hardware test pending (owner's bench, HARDWARE.md §5) |
| 15. Security testing | DONE | see below, TESTING.md, SECURITY_REVIEW.md |
| 16. Deployment | TOOLING READY | package + scripts + guides done; installing on the hosting waits for the owner |

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

## Phase 14 — session 4 (2026-09-30)
- `HealthService` + `/health[/db|storage|messaging|backups]` (public = status only; details with `HEALTH_TOKEN` or Super Admin), scheduler heartbeat (cache), Super Admin "Tizim holati" page.
- `BackupCrypto` (AES-256-GCM chunked, tamper/truncation detection) + `BackupService` (run/verify/restore/prune/photos) + commands `backup:run [--photos]`, `backup:verify`, `backup:restore --force`, `platform:prune`; daily/weekly schedule; `docs/BACKUP.md`.
- Tests: every DB table is either backed up or deliberately excluded; backup → damage → restore → identical data on all 3 engines; tamper/truncate/wrong key detected; retention rules; encrypted photo archive; health public vs detailed; all key commands scheduled.
- Logs: daily channel now writes JSON lines (request id + redaction). Removed Laravel's default `/up` (replaced by `/health`).

## Owner request — mandatory photo (2026-09-30)
- Owner: "take the photo only after the camera sees the face, remove the continue-without-photo buttons, continue automatically only after the photo — the photo is evidence".
- Tablet: removed "Suratsiz davom etish" and the manual "Suratga olish" fallback; capture only when one well-placed face stays in view for 0.7 s; detector/camera failure → message + "Qayta urinish" (fresh camera/detector) or cancel. Start follows the upload automatically.
- Server: `PHOTO_REQUIRED` on start for every tenant; the `photo_required` tenant setting and its admin checkbox were removed (stale stored values ignored); the kiosk bootstrap always says `photoRequired: true`.
- Tests: API 204 on 3 engines (sessions now start through a photo helper; a test proves a stale `photo_required=false` cannot bypass it); tablet 17 (no skip/manual buttons, no upload without a face, retry reloads the detector).
- Limitation: the face check runs on the tablet; the server cannot tell a face photo from another JPEG. The photo is viewable per session in the admin panel.

## Phase 16 (preparation) — session 4 (2026-09-30)
- Owner: "prepare everything, the hosting part later". Nothing was uploaded to the hosting.
- `infrastructure/release/build.sh` → `bilyart-<version>.zip` (33 MB): API without tests, `vendor --no-dev --classmap-authoritative` (git histories stripped), admin + tablet builds, deploy scripts, VERSION/COMMIT; boots and resolves all routes before zipping.
- `activate.sh` (cPanel Terminal): PHP ≥ 8.3 + extension check, first run creates `shared/.env` with generated APP_KEY/BACKUP_ENCRYPTION_KEY/HEALTH_TOKEN (chmod 600) and stops; then shared storage/.env links, maintenance around `migrate --force`, config/route/event cache, atomic `current` switch, keep 3 releases, `/health` probe. Failure before the switch keeps the old release live. `rollback.sh`.
- Verified here on MySQL **and** PostgreSQL in a throw-away home: first run → install → health (db/storage) → admin + tablet shells → scheduler with cached config → login → backup + verify → health all green → update keeps shared files → rollback. Found and fixed on the way: `optimize` failed on the missing views dir (now config/route/event cache only); PlatformSeeder used `env()` (breaks with cached config) → `config/platform.php`; added `admin:create-super` (hidden password, nothing in .env).
- CI: new job "Release package + server scripts" runs `infrastructure/deploy/tests/run.sh` on every PR. Workflows: `release.yml` (manual, version), `deploy.yml` (manual, typed DEPLOY, secrets-gated SSH: scp + activate + health retry, environment `production`).
- Docs: DEPLOY_UZ.md (click-by-click), DEPLOYMENT.md §2–4a rewritten to the implemented flow, SUPER_ADMIN_GUIDE, CLIENT_ADMIN_GUIDE, TROUBLESHOOTING (Uzbek). Fixed doc/firmware wording "Uzish" for ESP32 (the admin button name). Admin error banner now shows the request id on server errors.
- Waiting for the owner: hosting check report, domain vs subdomain, `DEVICE_REGISTRATION_SECRET` GitHub secret, lamp power; then the real install (DEPLOY_UZ.md) and the §61 hardware scenario.

## Phase 11 — session 4 (2026-09-30)
- `devices/esp32`: `lib/core` (session timer with hard cap, warning flasher, command handling with 16-id idempotency log and expiry, `/state` apply, boot recovery with provisional clock, poll/ack builders) — 17 host tests, fed with the protocol examples. `src/` glue: setup portal (captive, WPA2 random password printed on first boot), registration + pairing with the code on the portal, pinned root CAs (Let's Encrypt + Sectigo), `/state` → `/poll` → `/ack` loop in a network task, relay loop on the other core (never blocks), NVS session + 30 s checkpoint, task watchdog 30 s, BOOT button (3 s portal / 10 s factory reset), status LED, OTA with streaming SHA-256 check + rollback if the new image cannot reach the server in 10 min.
- CI job "ESP32 firmware": host tests, CA bundle freshness, build, version-marker check, app `.bin` + merged factory image + SHA256SUMS as `esp32-firmware` artifact; `workflow_dispatch` with a firmware version input for OTA releases. First CI build: flash 51 %, RAM 15 %.
- Server: `POST /api/super/firmware/{id}/rollout` (OTA to idle paired devices, idempotent), uploads must carry the `BLYFWVER:` marker matching the typed version; firmware download sends `Content-Length`. Web admin: Super Admin → **Proshivka** page (upload / publish / rollout); multipart support in the API client.
- Docs: `HARDWARE.md` (parts, contactor sizing, wiring, electrician note), `ESP32_FLASHING.md` (Uzbek, browser-based first flash, pairing, OTA), DEVICE_PROTOCOL §6b.
- Not verified yet: real hardware (no ESP32 in the dev container). The PlatformIO registry is blocked in the cloud dev container, so host tests there run via `scripts/native-test.sh`; CI runs `pio`.
- Owner action needed: add GitHub secret `DEVICE_REGISTRATION_SECRET` (ESP32_FLASHING.md §0).

## Phase 8 (+ Phase 9 client) — session 4 (2026-09-30)
- `apps/tablet`: React 19 + Vite 8 PWA under `/tablet/` (fullscreen, landscape). Pairing screen (register → big 6-digit code → poll → token in IndexedDB), tables grid with live countdowns, duration quotes, confirm (price, pay-at-desk note, privacy notice), camera with MediaPipe face detection → one JPEG → upload → start → waits for the ESP32 ACK → countdown. Server-time offset, IndexedDB display cache, offline banner (no starts offline), closed/suspended screens, 60 s idle reset (cancels reservation), revoked tablet → re-pair, 5-minute warning (chime + TTS, local timing), wake lock, heartbeat.
- Server: `/tablet/{path?}` shell with its own CSP (`'wasm-unsafe-eval'`, `media-src blob:`) and `camera=(self)`; everything else keeps `camera=()`; `.htaccess` sets the same for static files.
- CI job "Tablet kiosk": audit, typecheck, 16 tests, build, model sha256 + WASM presence check, `tablet-dist` artifact.
- Verified end-to-end in Chromium against the real Laravel app + an HTTP ESP32 simulator: pairing via the admin API, table → 1 soat → start → device ACK → ACTIVE → countdown; manual-capture path uploaded a real 1280×720 JPEG through the sanitizer; the pairing rate limit kicked in after 5 pairings as designed. Found and documented: MediaPipe telemetry is blocked by our CSP.
- Docs: `TABLET_SETUP.md` (Uzbek, click-by-click), ARCHITECTURE tablet notes, SECURITY §9, READMEs.

## Phase 15 — session 4 (2026-09-30)
- `RouteSweepTest`: walks the real route table — every admin route with a model parameter (all methods, nested own-parent/foreign-child) answers 404 to another tenant and tenant B's rows stay byte-identical; guests get 401 on every admin/super/account route; client users 403 on every Super Admin route; tablet/device routes reject missing, browser-session and swapped credentials; a tablet cannot touch another tenant's session. New parameter names without a fixture fail the test.
- Found + fixed: role gates ran after route-model binding (404 instead of 403 for a client on one Super Admin URL) → middleware priority; `admin` rate limiter was unused → applied to admin + super groups; session cookie not `Secure` by default when the env variable is missing; no UI to change one's own password.
- CSP + security headers (`config/security.php`, `SecurityHeaders`, `SpaController`), `.htaccess` HTTPS redirect / dotfile block / static-file headers, admin build ships `.htaccess` with CSP + cache rules (artifact now includes hidden files). Admin UI verified in Chromium under the CSP: zero violations.
- Optional TOTP 2FA for all admin users (RFC 6238 vectors tested, replay protection, recovery codes, lockout integration, resets on admin password reset, `user:2fa-reset` console command) + "Hisobim" page (password change, 2FA with otpauth link + local QR) + login code field.
- `npm audit` (prod deps, high+) in CI for web-admin and protocol. `docs/TESTING.md` maps spec §43 items 1–15 to tests; `docs/SECURITY_REVIEW.md` manual checklist.
- 199 API tests green on MySQL 8, MariaDB 10.6, PostgreSQL 13; 20 web-admin tests.

## Owner request — one ESP32 per branch (2026-10-01)
- Owner: "one ESP32 per branch, e.g. 4 tables → one ESP32 controls the four lamps; lamps are 220 V".
- DB (migration `2026_10_05_000001_multi_channel_devices`, verified up/down/up on MySQL, MariaDB, PostgreSQL): `devices.channel_count` (1..8), device belongs to a branch (new FK (tenant, branch) → branches); `billiard_tables.device_id` + `device_channel` with UNIQUE(device, channel) and FK (tenant, branch, device) → devices; `game_sessions.device_channel`, `device_commands.channel`; old 1:1 pairings became channel 1; backup order devices → tables.
- Protocol: `register.channelCount`, `poll.channels[]`, START/STOP/WARNING `payload.channel`, `state.sessions[]`, ack `channel`.
- Server: pair to a branch, move between branches, wire a table to a channel (table form; needs `devices.manage`), running games block rewiring/moving/unpairing; superseding scoped per channel; rollout "busy" if any of the device's tables plays; offline alerts name all tables of the device.
- Admin: Devices page pairs by branch and shows channels → table + lamp state; Tables page has a "Chiroq" select (free channels of the branch's devices).
- Firmware: per-channel timers/flashers, `BAD_CHANNEL` for channels the board lacks, poll/ack per channel, `RELAY_CHANNELS` build flag (default 4), relay pins 26/27/25/33/32/23/22/21, per-channel NVS + one checkpoint blob, legacy NVS session → channel 1. 19 host tests.
- Docs: HARDWARE (4-channel high-trigger relay module + contactors, single-point-of-failure note, bypass switches), DEVICE_PROTOCOL, ESP32_FLASHING, CLIENT_ADMIN_GUIDE, TROUBLESHOOTING, DATABASE, ARCHITECTURE, API, openapi.
- Tests: 211 API tests on 3 DBs (new `MultiChannelDeviceTest`: independent channels end to end, wiring rules, cross-tenant wiring refused by API and DB, running-session conflicts, unwired table, BAD_CHANNEL fails the start); 25 web-admin tests.

## Owner request — modern professional design (2026-10-01)
- Owner: "the apps must be very beautiful, professional, with modern colours".
- Shared brand: deep billiard-felt green + brass/gold accent, Inter Variable (self-hosted, CSP `font-src 'self'`), new cue-ball logo and app icons, theme colours.
- Admin: design tokens in `index.css`; dark felt sidebar with icons (lucide) and user card on desktop/iPad, felt top bar + slide-in menu on phones; restyled buttons, inputs, custom select chevron, cards, KPI tiles with icon chips, status pills with dots, empty state; dashboard table tiles with status stripe; split login with SVG billiard balls.
- Tablet kiosk: felt background, glass cards, available tables as lit felt with a brass rail, busy/warning tables with countdown + progress bar (warning glows), step badges (1/3…3/3), gold prices, countdown ring after start, restyled pairing/photo screens. Latin Inter files are precached for offline use.
- Verified visually in Chromium (desktop 1280×800, phone 390×844, kiosk 1280×800) against the real API with demo data; all admin (25) and tablet (17) tests green.

## First install on the hosting (2026-10-01)
- Owner set up `nbx.itcode.uz` (AutoSSL), CloudLinux PHP Selector 8.3 with pdo_pgsql/pgsql/mbstring/gd/zip/fileinfo/intl, PostgreSQL 13.23 (db `itcode_nbx`), release 1.0.0 built by Actions and activated (migrations ran), document root → `billiard/current/apps/api/public`, cron added.
- Found: `activate.sh` made `~/billiard` 700 → Apache could not read `.htaccess` (403). Fixed to 711 (+ deploy test asserts that others can traverse to `public/`). Hot-fix on the server: `chmod 711 ~/billiard`.
- DEPLOY_UZ.md updated for the real setup (PHP Selector per domain, pgsql, no trailing `/` in APP_URL, copy-paste commands).
- Done the same day: DB password and APP_KEY rotated, first backup taken, Super Admin created (`admin:create-super`), 2FA on; System health all green (pgsql, storage, cron, backups). Platform live at https://nbx.itcode.uz.
- Next for the owner: first client (hall) → branch → tablet pairing (a tablet pairs to a client's branch, not to the Super Admin).
- Polish after go-live: health detail labels in Uzbek; pairing code no longer clipped on phone-width screens.

## Open questions for the owner
1. ~~Platform domain~~ → **nbx.itcode.uz** (subdomain, PostgreSQL).
2. ~~First ESP32 flash method~~ → from a computer (owner, 2026-09-30).
3. Tablet model and Android version for kiosk testing? Can it be factory-reset (needed for Device Owner QR provisioning)?
4. How many halls/tables for the pilot, and the lamp **power in watts** per table (for contactor/breaker sizing)? The owner confirmed 220 V lamps (voltage); wattage still open.
5. Repository is **public**. Recommend making it private (GitHub → Settings → Danger Zone → Change visibility). Note: private repos have a monthly free Actions-minutes limit.
6. Hostmaster plan name (for LVE limits) — visible in the Hostmaster client area.

## Phase 1 — session 2 (2026-09-30)
- Owner sent the cPanel Tools screenshot + domain `itcode.uz` → recorded in DEPLOYMENT.md §1 (SSH, Terminal, Cron, Git VC, MySQL **and PostgreSQL**, Node/Python apps, CloudLinux LVE).
- Owner could not copy JSON → hostcheck page now shows a full screenshot-friendly table; JSON moved to an optional collapsed block.
- Owner authorized Claude to merge PRs itself (CLAUDE.md §2).

## Known issues
- Repository is public: `hosting-check` artifact (1-day retention) is downloadable by any signed-in GitHub user. Mitigated: domain not included in the artifact, file self-deletes on first view and after 24 h, report contains no secrets.
