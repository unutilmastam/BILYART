# ARCHITECTURE

Status: v1 — contradictions in §2 accepted by the owner on 2026-09-30 ("hamma narsani qil": proceed with the documented decisions).
Remaining **[VERIFY]** items are checked on the real Hostmaster account before Phase 16.
Phase 1 findings: cPanel has SSH, Terminal, Cron, Git VC, MySQL **and** PostgreSQL → the DB layer is kept portable (§2a) and the engine is picked at deploy. Deploy uses SSH/rsync if key-based SSH works for CI, else FTPS.

## 1. Constraints that shape everything
| Constraint | Consequence |
|---|---|
| Production = **cPanel shared hosting (hostmaster.uz), no VPS** | No long-running processes, no own MQTT broker, WebSockets unreliable, background work only via **cron (1-minute granularity)**. |
| PHP + a relational DB are what cPanel reliably offers. Phase 1 found MySQL **and** PostgreSQL, SSH, Terminal, Cron, Git VC, CloudLinux PHP selector on the account; exact versions/limits **[VERIFY at deploy]** | Backend = **Laravel 13 (PHP ≥ 8.3)**. Database layer is **portable across MySQL 8.0 / MariaDB ≥ 10.6 / PostgreSQL ≥ 13**; CI runs the full suite on all three. Production engine is chosen at deploy (PostgreSQL preferred per spec §21/§47 if its version ≥ 13, else MySQL/MariaDB). |
| Owner has no computer | All builds in **GitHub Actions**. Deploy from CI (SSH/rsync if available, otherwise FTPS). |
| Timers must be reliable even with 60 s cron | Timing is enforced **locally** (ESP32 + tablet) from server-issued `startAt/endAt`; cron only finalizes records. |

Why Laravel 13 and not 11: Laravel 11 security support ended in March 2026; 13 is the supported line for a multi-year product (PHP 8.3+, available in the CloudLinux PHP selector).

Why Laravel and not Node/NestJS (spec §47 lists Node as preferred): on cPanel, Node apps run under Passenger and are stopped when idle, scheduled work inside Node is unreliable, and there is no process manager. PHP runs per request and cron is native — this is the "actually deployable" choice the spec asks for.

## 2. Resolved contradictions (spec vs hosting)
1. **MQTT (spec §16)** — a broker cannot run on shared hosting.
   → **v1 device transport = HTTPS polling** (device → `/device/v1/poll` every 3 s, commands queued in DB, ACK via HTTPS). Spec §47 explicitly allows an HTTPS fallback.
   → Transport is behind a `DeviceTransport` interface. **Optional v2 adapter**: managed MQTT (e.g. EMQX Cloud) used *only for downlink push* via its HTTPS publish API; uplink stays HTTPS. Switch when device count per hosting account > ~100 or start latency becomes a problem. Not built in v1 unless the owner asks.
   → Capacity note: 50 devices × 1 poll / 3 s ≈ 17 req/s. The poll endpoint must be tiny (one indexed query, no framework-heavy middleware). Measure in Phase 10 against hosting limits.
2. **Background timers (spec §13, §31)** — no daemon.
   → Session status is *derived*: an ACTIVE session with `now ≥ end_at` is treated as finished by every read and by the next session start on that table. Cron (`schedule:run` every minute) finalizes: COMPLETED, audit, notifications.
   → Queue = Laravel `database` driver, drained by the scheduler (`queue:work --stop-when-empty --max-time=50`).
3. **5-minute warning (spec §12)** — cannot rely on server push.
   → ESP32 flashes the light 3× locally at `endAt − 5 min`. Tablet plays the audio locally at the same moment (it already holds endAt + server offset). Server records a WARNING event when cron sees it (audit/Telegram), but the physical warning does not depend on it.
4. **Kiosk (spec §7)** — a PWA alone cannot lock Android.
   → `apps/android-kiosk`: minimal Kotlin app = fullscreen WebView loading the tablet PWA, **Lock Task Mode as Device Owner**, `BOOT_COMPLETED` auto-start, crash auto-restart, camera permission bridged to WebView. APK built by GitHub Actions.
   → Device Owner provisioning = one-time **QR provisioning after factory reset** (no computer needed) — documented in `docs/TABLET_SETUP.md` (Phase 8). Fallback without Device Owner: screen pinning (weaker; document the risk).
5. **Uzbek TTS (spec §12)** — Android TTS Uzbek voices are unreliable.
   → Audio = pre-recorded clips per tenant ("N-stol" + "sizda 5 daqiqa vaqtingiz qoldi"), uploadable in admin; Web Speech TTS only as fallback. Language keys configurable.
6. **ESP32 has no display for pairing code (spec §17)**
   → On first boot ESP32 opens Wi-Fi AP `BILLIARD-XXXX` with a captive portal: owner connects with a phone, enters Wi-Fi; the device registers with the server and the portal page shows the **6-digit pairing code**. Owner enters it in Admin → Devices.
7. **First firmware flash without a computer**
   → CI produces `.bin`. **Owner decision (2026-09-30): first flash from a computer** (ESP Web Flasher / esptool, documented in Phase 11). All later updates = **OTA** from the platform (spec §55).
8. **Start when ESP32 is offline** — spec shows `SESSION_START_FAILED: DEVICE_OFFLINE`.
   → Session start refused if device `last_seen > 20 s` (configurable). If START is not ACKed within 20 s, session → FAILED, table released, staff notified, and a STOP is queued so a late-arriving command cannot turn the light on (commands also carry `expiresAt`, device ignores expired commands).

## 2a. Database portability rules
- Migrations use the schema builder; driver-specific SQL only where needed and always behind a `DB::getDriverName()` switch covering `mysql`, `mariadb`, `pgsql`.
- Double-booking guard: MySQL/MariaDB = generated `table_lock` column + UNIQUE; PostgreSQL = partial unique index `WHERE status IN (…)`. Same behaviour, tested on all engines.
- Reports aggregate over UTC ranges computed in PHP from the branch timezone (no DB timezone functions).
- Row locks: `lockForUpdate()` (supported by all three). Money `BIGINT`, times `TIMESTAMP(3)`/`timestamptz` via Laravel `timestampTz` where needed.
- SQLite is **not** used for tests (composite FKs / locks must be real).

## 2c. Owner decisions 2026-09-30 (later message)
- **No deployment for now**: everything is built and tested in GitHub (CI); moving to Hostmaster happens at the end (Phase 16).
- **Apps are PWAs**: admin panel and tablet kiosk are installable PWAs. The native `android-kiosk` shell is **not built** unless the owner asks later. Kiosk lock on the tablet = Android's built-in **App pinning** (+ PWA installed full-screen, auto-open documented in TABLET_SETUP). Known limitation vs Lock Task/Device Owner: a person who knows the unpin gesture + device PIN can leave the app; auto-start after reboot is not guaranteed without a native shell. Documented, not hidden.

## 2b. Build order (owner decision 2026-09-30)
Server side and admin first (Phases 2–7, 9 server part, 10 server part, 12–15). Tablet app + kiosk (Phase 8, 9 client part) and ESP32 firmware (Phase 11) come after, then deployment (16). Hosting check results are collected before Phase 16.

## 3. Components
```
 Android tablet (kiosk shell → tablet PWA)          Admin browser (phone/iPad/PC)
            │ HTTPS (tablet token)                          │ HTTPS (session cookie, Sanctum SPA)
            ▼                                               ▼
 ┌──────────────────────── Hostmaster cPanel ────────────────────────┐
 │ document root = apps/api/public                                   │
 │   /admin/*       → web-admin SPA build                            │
 │   /tablet/*      → tablet PWA build                               │
 │   /api/*         → Laravel API (admin + tablet)                   │
 │   /device/v1/*   → Laravel device API (device token)              │
 │   /telegram/webhook/{integration}                                 │
 │ Laravel app (outside public_html) · MySQL · private storage       │
 │ cron: schedule:run (1 min) · backups (daily)                      │
 └───────────────────────────────────────────────────────────────────┘
            ▲ HTTPS poll/ack/heartbeat (device token)        ▲ Telegram Bot API
            │                                                 │
   ESP32 (1 per branch, 1 relay channel per table) → opto relay module → contactor → 220V lamp
```

## 4. Backend (apps/api)
Laravel 13, domain-organized: `app/Domain/<Domain>/{Models,Services,Policies,Events,Enums}`; thin controllers in `app/Http/Controllers/{SuperAdmin,Admin,Tablet,Device,Telegram}`.

Domains: `Auth`, `Tenancy`, `Subscriptions`, `Branches`, `Tables`, `Pricing`, `WorkingHours`, `Users`, `Devices` (pairing, commands, heartbeats, firmware), `Tablets`, `Sessions` (state machine), `Photos`, `Reports`, `Telegram`, `Notifications`, `Audit`, `Health`.

Central middleware pipeline (never duplicated in controllers):
`auth:{web|tablet|device}` → `resolveTenant` (from principal) → `can:<permission>` → `subscription.active` → tenant-scoped route-model binding (`BelongsToTenant` global scope + policies) → `idempotency` (state-changing tablet/device routes).

Key services:
- `SessionService::prepare/start/confirmStarted/stop/complete/cancel` — the only way to change session state; uses `SessionStateMachine` (allowed transitions table, throws on illegal).
- `PriceCalculator` — strategy per `pricing_plans.type` (v1: `HOURLY`); amount = ceil(price_per_hour × minutes / 60) rounded up to `rounding_step` (default 1000 UZS). Price snapshot stored on session.
- `SubscriptionService::extend(tenant, days, payment)` — base = max(now, expires_at) → supports both spec §4 cases. Status: SUSPENDED (flag) > EXPIRED > EXPIRING_SOON (≤5 days) > ACTIVE.
- `LimitGuard` — branch/table/device/user limits checked inside a transaction with the tenant row locked (`SELECT … FOR UPDATE`).
- `DeviceCommandBus` — creates commands, delivers via `DeviceTransport` (v1: `HttpPollTransport`), retry/expiry policy.
- `PhotoStorage` interface — v1 `LocalPrivateDisk` (outside public_html).
- `TelegramClient` interface — real Bot API adapter; test fake only in tests.

## 5. Session flow (authoritative)
1. Tablet `POST /api/tablet/sessions/prepare` {tableId, durationMinutes} + `Idempotency-Key` → checks subscription, working hours, table belongs to tablet's branch, device online, table free (row lock) → session `RESERVED` (TTL 120 s) → returns quote + sessionId.
2. Tablet runs on-device face **detection** (MediaPipe Face Detector, WASM) → captures **one** JPEG → `POST /api/tablet/sessions/{id}/photo`.
3. Tablet `POST /api/tablet/sessions/{id}/start` → `STARTING`; server sets `start_at=now`, `end_at=start_at+duration`, queues `START_SESSION`.
4. ESP32 polls, applies, ACKs → session `ACTIVE`. Tablet shows countdown from `end_at` using server-time offset.
5. `end_at − 5 min`: ESP32 flashes 3×, tablet plays audio (both local). Server sets `warned_at` when observed.
6. `end_at`: ESP32 OFF locally; tablet shows AVAILABLE; server (read-time derivation + cron) → `COMPLETED`, audit, report data.
7. Staff early stop (permission `sessions.stop`) → `COMPLETING` + `STOP_SESSION` → `COMPLETED` on STOP ACK or after 60 s (the device also stops locally at endAt), `ended_early=true`.

Implementation notes (Phase 7):
- Transitions only via `SessionService` + `SessionStateMachine` (allowed-transition table; illegal → 409 `INVALID_STATE_TRANSITION`); every transition writes `session_events`.
- `prepare` locks the `billiard_tables` row, finalizes stale sessions of that table, checks occupancy, inserts; a `UniqueConstraintViolation` from the DB guard is mapped to `TABLE_UNAVAILABLE`. Proven with 8 forked processes racing on one table (exactly one winner) on MySQL, MariaDB and PostgreSQL.
- `SessionFinalizer` (`sessions:finalize`, every minute): ACTIVE past endAt → COMPLETED (ended_at = end_at), RESERVED past TTL → CANCELLED, STARTING without ACK after 20 s → FAILED + STOP, COMPLETING after 60 s → COMPLETED, `warned_at` recorded.
- `TableStatusResolver` derives AVAILABLE/RESERVED/STARTING/BUSY/WARNING/DISABLED/DEVICE_OFFLINE/CLOSED at read time, so a missed cron run never shows a finished table as busy.
- `DeviceCommandBus`: a newer START/STOP supersedes undelivered commands of other sessions; a STOP expires its own session's undelivered START.
8. Payment status (UNPAID/PAID/WAIVED) is set **manually** by authorized staff. No automatic verification (spec §10, §69).

## 6. Frontends
- **web-admin**: React 18 + Vite + TypeScript + React Router + TanStack Query + Tailwind. Areas: `/admin/super/*` (SUPER_ADMIN), `/admin/*` (tenant roles). Mobile-first.
- **tablet**: React + Vite PWA; large touch targets; screens: Pairing → Tables → Duration → Price → Camera → Start/Countdown. Caches the last bootstrap (IndexedDB, display only); refuses to start sessions offline ("Aloqa yo'q. Iltimos, kuting."). Implementation notes (Phase 8):
  - Server time: offset from `serverTime` in every response; the sample with the lowest round trip wins (`lib/clock.ts`).
  - Idempotency: one key per logical action (the confirm screen keeps its key across retries, so a lost response never creates a second reservation).
  - Camera: face *detection* (MediaPipe BlazeFace, WASM + model shipped with the build, no CDN) times one JPEG; the stream stops right after. The photo is **always required** (owner decision 2026-09-30: it is evidence): it is taken only automatically when exactly one well-placed face stays in view for 0.7 s — no skip button, no manual shutter. Camera or detector failure → error + "Qayta urinish" (fresh camera and detector) or cancel; the game never starts without a photo. The server enforces it too (`PHOTO_REQUIRED` on start for every tenant; the former `photo_required` tenant setting was removed and stale stored values are ignored). The face check is on the tablet only (no server-side image analysis).
  - Start waits for the device ACK (`STARTING` → `ACTIVE`/`FAILED`) by polling the session.
  - 5-minute warning: decided locally from `endAt` + offset (works offline), once per session: chime + device TTS of the tenant's text (`{table}` placeholder).
  - Idle 60 s on any step → back to tables; an open reservation is cancelled (server TTL 120 s is the backstop).
  - Revoked tablet (401/403) → credential wiped → pairing screen. Subscription inactive (402) → "Xizmat vaqtincha to'xtatilgan".
  - Kiosk lock: Android App pinning (docs/TABLET_SETUP.md); screen kept awake with the Wake Lock API.
- i18n: `uz` default, `ru` prepared.
- Both builds are copied into `apps/api/public/{admin,tablet}` by CI → same origin, no CORS.

## 7. Firmware (devices/esp32)
**One ESP32 per branch** (owner decision 2026-10-01): a board has 1–8 relay channels (`RELAY_CHANNELS`, default 4), one per table lamp. The device is paired to a branch; tables are wired to `(device_id, device_channel)` by the admin (DB: unique per channel, composite FK keeps device, table, branch and tenant consistent). Sessions and commands carry the channel; each channel has its own local timer, so channels never affect each other. Trade-off accepted by the owner: a failed controller stops automatic switching for the whole branch — mitigated by keyed bypass switches and a spare pre-flashed board (HARDWARE.md).

PlatformIO, Arduino-ESP32 core. Modules: `net` (Wi-Fi + captive-portal provisioning), `api` (HTTPS client, pinned root CA), `session` (state + NVS persistence), `relay`, `clock` (NTP + server time), `ota`, `watchdog`, `diag`. Pure logic (session timing, command handling) is in platform-independent C++ so it can be unit-tested with `pio test -e native`. Details: DEVICE_PROTOCOL.md.

## 8. Telegram
Per-tenant bot token (encrypted with APP_KEY, write-only in the API). `TelegramClient` port with the real `HttpTelegramClient`. Webhook `POST /telegram/webhook/{integrationPublicId}` (stateless, rate-limited) verified by `X-Telegram-Bot-Api-Secret-Token` (stored as SHA-256); the tenant comes from the integration, never from the update. Chat linking via one-time `/start <code>` (8 chars, 15 min). Commands: `/report` (today, the chat's branch or all), `/help`. Daily report per branch once its `report_time` has passed in the branch timezone (`telegram:daily-reports` every 5 min, deduplicated per branch+date). Alerts: session failed, device offline (>60 s, once per episode) / back online (`devices:monitor`). Delivery via `notifications:deliver` (log row per chat; failures recorded without the token). Photos never sent to Telegram.

## 9. Deliberate v1 exclusions
Online payment gateway, facial recognition, any CCTV integration, native iOS, multi-currency, MQTT (adapter-ready only).
