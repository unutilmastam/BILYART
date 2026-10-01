# DATABASE

Engine: portable — MySQL 8.0 / MariaDB ≥ 10.6 (InnoDB, utf8mb4) **or** PostgreSQL ≥ 13 (see ARCHITECTURE §2a); CI tests all three. All timestamps stored in **UTC** (`DATETIME(3)` or `TIMESTAMP`). Money = `BIGINT UNSIGNED` in UZS. IDs = `BIGINT UNSIGNED` auto-increment internally + `public_id CHAR(26)` (ULID) exposed in APIs/URLs so IDs are not guessable.

## 1. Tenant isolation at the DB level
- Every tenant-owned table has `tenant_id NOT NULL` + index.
- Parent tables expose a composite unique key `(tenant_id, id)`.
- Children reference parents with **composite foreign keys** `(tenant_id, parent_id) → parent(tenant_id, id)`. This makes it physically impossible to link Tenant A's table to Tenant B's branch, even with an application bug.
- App layer: `BelongsToTenant` trait adds a global scope + sets `tenant_id` from the resolved tenant on create (never from input).

## 2. Tables

### Platform
| table | key columns |
|---|---|
| `tenants` | id, public_id, name, contact_phone, status_flag (ACTIVE/SUSPENDED/DEACTIVATED), subscription_expires_at, branch_limit, table_limit NULL, device_limit NULL, user_limit NULL, timezone default 'Asia/Tashkent', created_at |
| `subscriptions` | id, tenant_id, starts_at, expires_at, days, source (PAYMENT/MANUAL_ADJUST), payment_id NULL, created_by |
| `subscription_payments` | id, tenant_id, amount, currency 'UZS', method (CASH/BANK_TRANSFER/CARD_TRANSFER/OTHER), note, paid_at, recorded_by |
| `subscription_events` | id, tenant_id, type (ACTIVATED/EXTENDED/SUSPENDED/RESUMED/EXPIRED/LIMIT_CHANGED), old_value JSON, new_value JSON, actor_user_id, created_at |
| `system_settings` | key PK, value JSON, updated_by |
| `firmware_releases` | id, version (semver, unique), sha256, file_path, size, notes, is_published, created_by |

### Identity
| table | key columns |
|---|---|
| `users` | id, public_id, tenant_id NULL (NULL only for SUPER_ADMIN), role (SUPER_ADMIN/CLIENT_OWNER/CLIENT_MANAGER/CLIENT_OPERATOR), name, login unique (lowercase, global), password (argon2id/bcrypt), is_active, failed_logins, locked_until, last_login_at. CHECK: role=SUPER_ADMIN ⇔ tenant_id IS NULL |
| `user_branch_access` | tenant_id, user_id, branch_id (optional restriction of managers/operators to branches) |
| `sessions` (Laravel web sessions) | rename Laravel default to `web_sessions` to avoid clash with billiard sessions |
| ~~`personal_access_tokens`~~ | not used: tablets have their own hashed token (`tablets.token_hash`), like devices |

### Business
| table | key columns |
|---|---|
| `branches` | id, public_id, tenant_id, name, address, timezone, is_active, report_time (e.g. 23:30), settings JSON · UNIQUE(tenant_id,id) |
| `working_hours` | tenant_id, branch_id, weekday **1–7 (ISO, 1 = Monday)**, opens_at TIME, closes_at TIME, is_closed, crosses_midnight (derived) |
| `branch_closed_days` | tenant_id, branch_id, date, reason |
| `billiard_tables` | id, public_id, tenant_id, branch_id, number, name, is_active, pricing_plan_id, device_id NULL, device_channel NULL (1..8, both or neither — CHECK) · UNIQUE(device_id, device_channel) (one table per relay channel) · FK (tenant_id, branch_id, device_id) → devices(tenant_id, branch_id, id) (lamp driven by an ESP32 of the same branch and tenant) · UNIQUE(tenant_id,branch_id,number) · UNIQUE(tenant_id,id) · UNIQUE(tenant_id,branch_id,id) (target of 3-column FKs so a session/device can never mix branch and table) |
| `pricing_plans` | id, tenant_id, branch_id NULL, name, type ENUM('HOURLY', …future), price_per_hour, rounding_step, rules JSON (future models), allowed_durations JSON (e.g. [30,60,90,120]), is_active |
| `game_sessions` | id, public_id, tenant_id, branch_id, table_id (3-column FK), device_id NULL, device_channel NULL (relay channel at start; STOP goes to the same one), tablet_id NULL, pricing_plan_id NULL, rounding_step_snapshot, stopped_by, status ENUM(RESERVED,STARTING,ACTIVE,COMPLETING,COMPLETED,CANCELLED,FAILED), duration_minutes, reserved_until, start_at, end_at, ended_at, ended_early, warned_at, price_per_hour_snapshot, amount, payment_status ENUM(UNPAID,PAID,WAIVED), payment_marked_by, payment_marked_at, failure_reason, created_at |
| `session_photos` | id, public_id, tenant_id, session_id UNIQUE, storage_path, mime_type, size, width, height, sha256, created_at, deleted_at, deleted_by |
| `session_events` | tenant_id, session_id, from_status, to_status, actor_type, actor_id, reason, created_at (state-machine history) |

**Double-booking guard (spec §32):** MySQL/MariaDB: `game_sessions.table_lock` = generated column
`CASE WHEN status IN ('RESERVED','STARTING','ACTIVE','COMPLETING') THEN table_id ELSE NULL END` with **UNIQUE(table_lock)**. PostgreSQL: partial unique index on `(table_id) WHERE status IN (…)`. Plus `SELECT … FOR UPDATE` on the `billiard_tables` row inside the start transaction. Expired-but-not-finalized sessions on that table are finalized inside the same transaction before insert.

### Devices & tablets
| table | key columns |
|---|---|
| `devices` | **registration row**: id, public_id, hardware_id (eFuse MAC), active_hardware_id NULL UNIQUE, device_code (ESP32-XXXXXX), tenant_id NULL until paired then **immutable**, branch_id, channel_count (1..8, CHECK), status (UNPAIRED/PAIRED/REVOKED, CHECK-enforced consistency), token_hash, firmware_version, last_seen_at, last_state JSON, last_ip, registered/paired/revoked at+by. Unpair = REVOKED (actives cleared); re-pairing creates a new row, so FKs from old sessions/commands stay valid. FK (tenant_id, branch_id) → branches(tenant_id, id) · UNIQUE(tenant_id, branch_id, id) (target of the tables' FK). One device per branch drives several tables (migration `2026_10_05_000001_multi_channel_devices`; older 1:1 pairings became channel 1) |
| `device_pairings` | id, device_id, code_hash (HMAC of the 6-digit code), poll_token_hash UNIQUE, expires_at, used_at, used_by, tenant_id NULL, token_delivered_at. Brute force is limited by rate limits per tenant/IP (a wrong code identifies no pairing) |
| `device_heartbeats` | device_id, tenant_id, received_at, state, session_public_id, rssi, uptime, fw — **rolled up**: keep 7 days raw, pruned by cron |
| `device_commands` | id, public_id (= commandId), tenant_id, device_id, session_id NULL, type (START_SESSION/STOP_SESSION/WARNING/SYNC/PING/CONFIG_UPDATE/OTA), payload JSON, status (PENDING/SENT/ACKNOWLEDGED/FAILED/EXPIRED), attempts, created_at, sent_at, acked_at, expires_at · INDEX(device_id,status) |
| `tablets` | registration row like devices: id, public_id, device_code (TABLET-XXXXXX) UNIQUE, tenant_id NULL until paired then immutable, branch_id, name, status, token_hash UNIQUE, app_version, device_model, last_seen_at |
| `tablet_pairings` | same shape as device_pairings |

### Integrations, notifications, audit, infra
| table | key columns |
|---|---|
| `telegram_integrations` | id, tenant_id UNIQUE, bot_token_encrypted, bot_username, webhook_secret_hash, is_active, link_code_hash, link_code_expires_at |
| `telegram_chats` | tenant_id, integration_id, chat_id, branch_id NULL, receives (daily_report, alerts) |
| `notifications` | id, tenant_id NULL, type, dedupe_key UNIQUE, payload JSON, created_at, read_at |
| `notification_logs` | notification_id, channel (IN_APP/TELEGRAM), status, error, sent_at |
| `audit_logs` | id, tenant_id NULL, actor_type (USER/DEVICE/TABLET/SYSTEM), actor_id, action, entity_type, entity_id, metadata JSON (no secrets, no photos), ip, created_at · INDEX(tenant_id, created_at) |
| `idempotency_keys` | key, principal_type, principal_id, route, request_hash, response_code, response_body, created_at · UNIQUE(principal_type,principal_id,key) · pruned after 48 h |
| `jobs`, `failed_jobs`, `cache`, `cache_locks` | Laravel defaults |

### Enforcement summary (tested in `tests/Feature/Database`)
- Enum-like columns are strings with named **CHECK** constraints (portable, no native ENUM).
- `users_role_tenant_chk`: SUPER_ADMIN ⇔ tenant_id IS NULL.
- `game_sessions`: status/payment CHECKs, duration 1–720, `end_at > start_at`, started statuses require `start_at`.
- `devices_paired_chk`: PAIRED ⇒ tenant/branch set and active_hardware_id set; UNPAIRED ⇒ no tenant; REVOKED ⇒ active_hardware_id cleared.
- `devices_channel_count_chk`: 1 ≤ channel_count ≤ 8. `billiard_tables_device_chk`: device_id and device_channel both NULL, or channel 1..8 (the upper bound per device — channel_count — is checked by `DeviceRegistry::assignChannel`).
- All timestamps are `DATETIME`/`timestamp without time zone` holding **UTC** (app timezone is UTC); no 2038 limit.

## 3. Ownership chains (spec §49)
Session → Table → Branch → Tenant; Photo → Session → Tenant; Device → Table → Branch → Tenant. Enforced by composite FKs + policies + tests.

## 4. Migrations
Laravel migrations only; never edit a merged migration — add a new one. Seeders: `PlatformSeeder` (super admin from env), `DemoSeeder` (local/test only, never production).

## 5. Retention
- Photos: tenant setting `photo_retention_days` (default 30) → cron soft-deletes file + row mark, audit logged.
- Heartbeats 7 days, idempotency keys 48 h, device commands 90 days, audit logs ≥ 1 year.
