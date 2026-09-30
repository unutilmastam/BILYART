# API

Machine-readable contract: [openapi.yaml](openapi.yaml) (kept in sync by the API test suite from Phase 4 on).
Device and tablet message bodies: JSON Schemas in [`packages/protocol/schemas`](../packages/protocol/schemas) — the single source; the API test suite validates real responses against them.

## 1. Conventions
- Base URL: `https://<domain>` (production `itcode.uz`, see DEPLOYMENT.md). JSON only (`Accept: application/json`), UTF-8.
- IDs in URLs and bodies are **public IDs** (ULID, 26 chars). Internal numeric IDs are never exposed.
- Times: ISO-8601 UTC (`2026-10-01T10:00:00Z`) for admin/tablet APIs; Unix epoch seconds for device APIs. Every tablet/device response carries `serverTime`.
- Money: integer UZS.
- Pagination: `?page=1&perPage=25` (max 100) → `{ "data": [...], "meta": { "page", "perPage", "total" } }`.
- Errors (always this shape, no stack traces):
  ```json
  { "error": { "code": "TABLE_UNAVAILABLE", "message": "Stol band. Boshqa stolni tanlang.", "fields": { "name": ["..."] }, "requestId": "..." } }
  ```
  `message` is Uzbek (Latin) user-facing text; `code` is stable for programs. `fields` only on 422.
- Status codes: 200/201 ok · 204 no content · 401 `UNAUTHENTICATED` · 402 `SUBSCRIPTION_INACTIVE` · 403 `FORBIDDEN` · 404 `NOT_FOUND` (also for other tenants' resources) · 409 conflict (`TABLE_UNAVAILABLE`, `IDEMPOTENCY_KEY_REUSED`, `INVALID_STATE_TRANSITION`) · 422 `VALIDATION_FAILED` / `LIMIT_REACHED` · 423 `ACCOUNT_LOCKED` · 429 `RATE_LIMITED` · 5xx `SERVER_ERROR`.
- `Idempotency-Key` (UUID/ULID, 16–64 chars) is **required** on state-changing tablet and device endpoints and accepted on admin POSTs. Same key + same body → the stored response is replayed (header `Idempotent-Replayed: true`); same key + different body → 409 `IDEMPOTENCY_KEY_REUSED`; still running → 409 `IDEMPOTENCY_IN_PROGRESS`; missing where required → 400 `IDEMPOTENCY_KEY_REQUIRED`. 5xx responses are not stored (safe to retry). Keys are per principal and live 48 h (`idempotency:prune`, hourly).

## 2. Authentication
| Client | Mechanism | Header |
|---|---|---|
| Admin SPA (all roles) | Session cookie (`web` guard) + CSRF (`GET /api/auth/csrf` sets `XSRF-TOKEN` → send `X-XSRF-TOKEN`) | cookie |
| Tablet | Sanctum personal access token issued at pairing (ability `tablet`) | `Authorization: Bearer <token>` |
| Tablet/Device during pairing | one-time poll token | `Authorization: PollToken <pollToken>` |
| ESP32 | device token | `Authorization: Device <deviceCode>.<token>` |

Tenant, branch and table are **always** resolved from the principal, never from input.

## 3. Endpoints

### 3.1 Auth & profile (`/api`)
| Method | Path | Notes |
|---|---|---|
| GET | `/auth/csrf` | 204, sets `XSRF-TOKEN` cookie |
| POST | `/auth/login` | `{login, password, code?}` → same body as `/me`. With 2FA on: missing code → 401 `TWO_FACTOR_REQUIRED`, wrong/used code → 401 `TWO_FACTOR_INVALID` (counts towards the lock); `code` may be a recovery code. 5 fails → 15 min lock (423 `ACCOUNT_LOCKED`); inactive user / deactivated client → 403 `ACCOUNT_DISABLED` |
| POST | `/auth/logout` | |
| GET | `/me` | `{user, permissions[], tenant?: {name, subscription: {status, expiresAt, daysLeft}}}` — allowed when subscription inactive |
| PUT | `/me/password` | `{currentPassword, password}` → 204; other sessions of this user are logged out |
| POST | `/me/2fa/setup` | `{password}` → `{secret, uri}` (otpauth URI); 2FA stays off until confirmed; 409 if already on |
| POST | `/me/2fa/confirm` | `{code}` → `{recoveryCodes[8]}` (shown once) |
| POST | `/me/2fa/disable` | `{password, code}` → 204 |

### 3.2 Super Admin (`/api/super`, role SUPER_ADMIN, permission `platform.*`)
| Method | Path | Notes |
|---|---|---|
| GET | `/dashboard` | client counts by status, recorded revenue (month/total), device health summary, recent audit |
| GET/POST | `/tenants` | create = tenant + owner user + optional first payment |
| GET/PATCH | `/tenants/{tenant}` | name, contact, timezone |
| POST | `/tenants/{tenant}/suspend` · `/activate` · `/deactivate` | status flag, audited |
| POST | `/tenants/{tenant}/payments` | `{amount, method, days, paidAt, note}` → records payment **and** extends (base = max(now, expiresAt)) |
| POST | `/tenants/{tenant}/extend` | `{days, reason}` manual adjustment without payment |
| PUT | `/tenants/{tenant}/expiry` | `{expiresAt, reason}` set the expiry date directly (event `EXPIRY_SET`) |
| PUT | `/tenants/{tenant}/limits` | `{branchLimit, tableLimit?, deviceLimit?, userLimit?}` → `LIMIT_CHANGED` event |
| GET | `/tenants/{tenant}/subscription` | subscriptions + payments + events history |
| POST | `/tenants/{tenant}/owner/reset-password` | issues a temporary password (shown once) |
| GET | `/audit-logs` | filter by tenant/action/date |
| GET | `/payments` | all recorded platform payments |
| GET/PUT | `/settings` | `{supportContact, paymentInstructions, defaultBranchLimit, reminderDays}` (whitelisted keys) |
| GET/POST | `/firmware` · POST `/firmware/{release}/publish` · POST `/firmware/{release}/rollout` | firmware releases: multipart upload of the app `.bin` (sha256 computed server-side; the embedded `BLYFWVER:` marker must equal `version`), publish, rollout = OTA commands to idle paired devices on another version → `{queued, skippedBusy, alreadyCurrent}` (409 if not published) |
| GET | `/health` | detailed health (db, storage, queue, cron heartbeat, backups) |

### 3.3 Client Admin (`/api/admin`, tenant roles; `subscription.active` except where noted)
| Method | Path | Permission |
|---|---|---|
| GET | `/subscription` | any (allowed when inactive) → status, expiresAt, daysLeft, limits, supportContact, paymentInstructions |
| GET | `/dashboard?branchId=` | `sessions.view` |
| GET/POST | `/branches` | view: any · create: `branches.manage` (LimitGuard) |
| GET/PATCH/DELETE | `/branches/{branch}` | `branches.manage` (DELETE = disable) |
| GET/PUT | `/branches/{branch}/working-hours` | `working_hours.manage` — `{days:[{weekday 1–7 (ISO), isClosed, opensAt 'HH:MM', closesAt 'HH:MM'}]}` ×7; 00:00–00:00 = open all day; closesAt ≤ opensAt = past midnight (shift belongs to its start day) |
| GET/POST/DELETE | `/branches/{branch}/closed-days[/{day}]` | `working_hours.manage` |
| GET/POST | `/tables` (`?branchId=`) | view `tables.view` · create `tables.manage` (LimitGuard) |
| GET/PATCH/DELETE | `/tables/{table}` | view `tables.view` · change `tables.manage` (DELETE = disable; re-enable passes LimitGuard) · `{branchId (create only), number, name, pricingPlanId, isActive}` |
| GET/POST/PATCH/DELETE | `/pricing-plans[/{plan}]` | `pricing.manage` |
| GET/POST/PATCH | `/users[/{user}]` · POST `/users/{user}/deactivate` | `users.manage` (LimitGuard; managers cannot manage owners) |
| GET | `/devices` | `tables.view` — real online/offline from heartbeats |
| POST | `/devices/pair` | `{code, tableId}` · `devices.manage` · rate-limited |
| POST | `/devices/{device}/unpair` · `/devices/{device}/ping` | `devices.manage` |
| GET | `/tablets` · POST `/tablets/pair` `{code, branchId, name}` · POST `/tablets/{tablet}/revoke` | `devices.manage` |
| GET | `/sessions` (`?branchId&tableId&status&payment&from&to`) · GET `/sessions/{session}` (incl. events) | `sessions.view` |
| POST | `/sessions/{session}/stop` | `sessions.stop` |
| POST | `/sessions/{session}/payment` | `{status: PAID|UNPAID|WAIVED}` · `sessions.mark_payment` |
| GET | `/photos/{photo}` | `photos.view` — image bytes, `Cache-Control: private, no-store`, audited `photo.viewed` |
| DELETE | `/photos/{photo}` | `photos.delete` — audited |
| GET | `/reports/daily?date=&branchId=` · `/reports/monthly?month=&branchId=` | `reports.view` |
| GET/PUT | `/telegram` · POST `/telegram/link-code` · GET/DELETE `/telegram/chats[/{chat}]` · POST `/telegram/test` | `telegram.manage` — token write-only |
| GET/PUT | `/settings` | `tenant.settings` (privacy notice, photo retention days, warning text/audio, locale, operators can view photos). The customer photo is always required — not a setting |
| POST | `/settings/warning-audio` | upload clip · `tenant.settings` |
| GET | `/audit-logs` | `tenant.settings` |
| GET | `/notifications` · POST `/notifications/{id}/read` | any |
| GET | `/export` | owner only; allowed when inactive (data export, spec §29) |

### 3.4 Tablet (`/api/tablet`)
| Method | Path | Notes |
|---|---|---|
| POST | `/register` | public, 10/h/IP → `TabletRegisterResponse` |
| GET | `/pairing-status` | `PollToken` → `TabletPairingStatusResponse` (token once) |
| GET | `/bootstrap` | `TabletBootstrapResponse` |
| GET | `/tables` | `TabletTablesResponse` (poll ~5 s) |
| POST | `/heartbeat` | `{appVersion}` |
| POST | `/sessions/prepare` | `Idempotency-Key` · `TabletSessionPrepareRequest` → `TabletSession` (RESERVED, TTL 120 s) · checks subscription, working hours, table in tablet's branch, device online, table free |
| POST | `/sessions/{session}/photo` | multipart `photo` (≤ 2 MB), 10/min · RESERVED only |
| POST | `/sessions/{session}/start` | `Idempotency-Key` → STARTING, queues `START_SESSION`. Requires the uploaded photo (422 `PHOTO_REQUIRED` otherwise, for every tenant) |
| POST | `/sessions/{session}/cancel` | `Idempotency-Key` · RESERVED only |
| GET | `/sessions/{session}` | `TabletSession` |

### 3.5 Device (`/device/v1`) — see DEVICE_PROTOCOL.md
`POST /register` · `GET /pairing-status` · `POST /poll` · `POST /ack` · `GET /state` · `GET /firmware/{version}`

### 3.6 Telegram & health
- `POST /telegram/webhook/{integration}` — verified by `X-Telegram-Bot-Api-Secret-Token`.
- `GET /health` (public: `{status}` only) · `/health/db` · `/health/storage` · `/health/messaging` (details require Super Admin or `HEALTH_TOKEN`).

## 4. Error codes (stable)
`UNAUTHENTICATED, FORBIDDEN, NOT_FOUND, METHOD_NOT_ALLOWED, VALIDATION_FAILED, RATE_LIMITED, ACCOUNT_LOCKED, ACCOUNT_DISABLED, INVALID_CREDENTIALS, TWO_FACTOR_REQUIRED, TWO_FACTOR_INVALID, CONFLICT, IDEMPOTENCY_IN_PROGRESS, DEVICE_ALREADY_PAIRED, SUBSCRIPTION_INACTIVE, LIMIT_REACHED, TABLE_UNAVAILABLE, TABLE_DISABLED, BRANCH_CLOSED, DEVICE_OFFLINE, DEVICE_NOT_ASSIGNED, PRICING_NOT_CONFIGURED, DURATION_NOT_ALLOWED, PHOTO_REQUIRED, PHOTO_INVALID, RESERVATION_EXPIRED, INVALID_STATE_TRANSITION, IDEMPOTENCY_KEY_REQUIRED, IDEMPOTENCY_KEY_REUSED, PAIRING_CODE_INVALID, PAIRING_CODE_EXPIRED, DEVICE_UNAUTHORIZED, REPAIR_REQUIRED, DEVICE_REVOKED, SERVER_ERROR`.
