# ARCHITECTURE

Status: v1 proposal. Phase 1 must verify every **[VERIFY]** item on the real Hostmaster account and update this file.
Phase 1 (in progress): verification tooling is ready (`infrastructure/hosting-check`, see DEPLOYMENT.md §1); no decision changed yet — waiting for the owner's hosting report.

## 1. Constraints that shape everything
| Constraint | Consequence |
|---|---|
| Production = **cPanel shared hosting (hostmaster.uz), no VPS** | No long-running processes, no own MQTT broker, WebSockets unreliable, background work only via **cron (1-minute granularity)**. |
| PHP + MySQL are the only things reliably available on cPanel **[VERIFY: PHP ≥ 8.2 + extensions (pdo_mysql, gd/imagick, openssl, mbstring, intl, fileinfo, sodium), MySQL 8 / MariaDB ≥ 10.6, PostgreSQL, SSH/Terminal, Git Version Control, cron, Node.js selector, max upload size, memory_limit, LVE/process limits]** | Backend = **Laravel 11 (PHP)**. Database = **MySQL/MariaDB** (PostgreSQL only if Phase 1 finds it on this account and equally supported). |
| Owner has no computer | All builds in **GitHub Actions**. Deploy from CI (SSH/rsync if available, otherwise FTPS). |
| Timers must be reliable even with 60 s cron | Timing is enforced **locally** (ESP32 + tablet) from server-issued `startAt/endAt`; cron only finalizes records. |

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
   → CI produces `.bin`. First flash: USB-OTG from an Android phone with a flasher app, or one-time pre-flash on any computer **[owner decides in Phase 11]**. All later updates = **OTA** from the platform (spec §55).
8. **Start when ESP32 is offline** — spec shows `SESSION_START_FAILED: DEVICE_OFFLINE`.
   → Session start refused if device `last_seen > 20 s` (configurable). If START is not ACKed within 20 s, session → FAILED, table released, staff notified, and a STOP is queued so a late-arriving command cannot turn the light on (commands also carry `expiresAt`, device ignores expired commands).

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
   ESP32 (1 per table) → optocoupler/driver → relay/contactor → 220V lamp
```

## 4. Backend (apps/api)
Laravel 11, domain-organized: `app/Domain/<Domain>/{Models,Services,Policies,Events,Enums}`; thin controllers in `app/Http/Controllers/{SuperAdmin,Admin,Tablet,Device,Telegram}`.

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
7. Staff early stop (permission `sessions.stop`) → `STOP_SESSION` → `COMPLETED` with `ended_early=true`.
8. Payment status (UNPAID/PAID/WAIVED) is set **manually** by authorized staff. No automatic verification (spec §10, §69).

## 6. Frontends
- **web-admin**: React 18 + Vite + TypeScript + React Router + TanStack Query + Tailwind. Areas: `/admin/super/*` (SUPER_ADMIN), `/admin/*` (tenant roles). Mobile-first.
- **tablet**: React + Vite PWA; large touch targets; screens: Tables → Duration → Price → Camera → Countdown. Caches table list/prices (IndexedDB, cache only); refuses to start sessions offline ("Aloqa yo'q. Iltimos, kuting.").
- i18n: `uz` default, `ru` prepared.
- Both builds are copied into `apps/api/public/{admin,tablet}` by CI → same origin, no CORS.

## 7. Firmware (devices/esp32)
PlatformIO, Arduino-ESP32 core. Modules: `net` (Wi-Fi + captive-portal provisioning), `api` (HTTPS client, pinned root CA), `session` (state + NVS persistence), `relay`, `clock` (NTP + server time), `ota`, `watchdog`, `diag`. Pure logic (session timing, command handling) is in platform-independent C++ so it can be unit-tested with `pio test -e native`. Details: DEVICE_PROTOCOL.md.

## 8. Telegram
Per-tenant bot token (encrypted). Webhook `POST /telegram/webhook/{integrationId}` verified by `X-Telegram-Bot-Api-Secret-Token`. Chat linking via one-time `/start <code>` generated in admin. Daily report per branch at its configured time (branch timezone). Photos never stored in Telegram.

## 9. Deliberate v1 exclusions
Online payment gateway, facial recognition, any CCTV integration, native iOS, multi-currency, MQTT (adapter-ready only).
