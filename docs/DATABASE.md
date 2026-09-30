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
| `users` | id, public_id, tenant_id NULL (NULL only for SUPER_ADMIN), role (SUPER_ADMIN/CLIENT_OWNER/CLIENT_MANAGER/CLIENT_OPERATOR), name, phone/login unique, password (argon2id/bcrypt), is_active, failed_logins, locked_until, last_login_at. CHECK: role=SUPER_ADMIN ⇔ tenant_id IS NULL |
| `user_branch_access` | tenant_id, user_id, branch_id (optional restriction of managers/operators to branches) |
| `sessions` (Laravel web sessions) | rename Laravel default to `web_sessions` to avoid clash with billiard sessions |
| `personal_access_tokens` | Sanctum tokens for tablets (hashed) |

### Business
| table | key columns |
|---|---|
| `branches` | id, public_id, tenant_id, name, address, timezone, is_active, report_time (e.g. 23:30), settings JSON · UNIQUE(tenant_id,id) |
| `working_hours` | tenant_id, branch_id, weekday 0–6, opens_at TIME, closes_at TIME, is_closed, crosses_midnight (derived) |
| `branch_closed_days` | tenant_id, branch_id, date, reason |
| `billiard_tables` | id, public_id, tenant_id, branch_id, number, name, is_active, pricing_plan_id · UNIQUE(tenant_id,branch_id,number) · UNIQUE(tenant_id,id) |
| `pricing_plans` | id, tenant_id, branch_id NULL, name, type ENUM('HOURLY', …future), price_per_hour, rounding_step, rules JSON (future models), allowed_durations JSON (e.g. [30,60,90,120]), is_active |
| `game_sessions` | id, public_id, tenant_id, branch_id, table_id, device_id NULL, tablet_id, status ENUM(RESERVED,STARTING,ACTIVE,COMPLETING,COMPLETED,CANCELLED,FAILED), duration_minutes, reserved_until, start_at, end_at, ended_at, ended_early, warned_at, price_per_hour_snapshot, amount, payment_status ENUM(UNPAID,PAID,WAIVED), payment_marked_by, payment_marked_at, failure_reason, created_at |
| `session_photos` | id, public_id, tenant_id, session_id UNIQUE, storage_path, mime_type, size, width, height, sha256, created_at, deleted_at, deleted_by |
| `session_events` | tenant_id, session_id, from_status, to_status, actor_type, actor_id, reason, created_at (state-machine history) |

**Double-booking guard (spec §32):** MySQL/MariaDB: `game_sessions.table_lock` = generated column
`CASE WHEN status IN ('RESERVED','STARTING','ACTIVE','COMPLETING') THEN table_id ELSE NULL END` with **UNIQUE(table_lock)**. PostgreSQL: partial unique index on `(table_id) WHERE status IN (…)`. Plus `SELECT … FOR UPDATE` on the `billiard_tables` row inside the start transaction. Expired-but-not-finalized sessions on that table are finalized inside the same transaction before insert.

### Devices & tablets
| table | key columns |
|---|---|
| `devices` | id, public_id, hardware_id UNIQUE (from eFuse MAC), device_code (e.g. ESP32-A8F4C1), tenant_id NULL (NULL = unpaired), branch_id NULL, table_id NULL UNIQUE, token_hash, firmware_version, last_seen_at, last_state JSON, last_ip, status (UNPAIRED/PAIRED/REVOKED), paired_at |
| `device_pairings` | id, device_id, code_hash, expires_at, used_at, used_by_user_id, tenant_id NULL, attempts |
| `device_heartbeats` | device_id, tenant_id, received_at, state, session_public_id, rssi, uptime, fw — **rolled up**: keep 7 days raw, pruned by cron |
| `device_commands` | id, public_id (= commandId), tenant_id, device_id, session_id NULL, type (START_SESSION/STOP_SESSION/WARNING/SYNC/PING/CONFIG_UPDATE/OTA), payload JSON, status (PENDING/SENT/ACKNOWLEDGED/FAILED/EXPIRED), attempts, created_at, sent_at, acked_at, expires_at · INDEX(device_id,status) |
| `tablets` | id, public_id, device_code (TABLET-XXXX), tenant_id NULL, branch_id NULL, token_id, status, app_version, last_seen_at |
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

## 3. Ownership chains (spec §49)
Session → Table → Branch → Tenant; Photo → Session → Tenant; Device → Table → Branch → Tenant. Enforced by composite FKs + policies + tests.

## 4. Migrations
Laravel migrations only; never edit a merged migration — add a new one. Seeders: `PlatformSeeder` (super admin from env), `DemoSeeder` (local/test only, never production).

## 5. Retention
- Photos: tenant setting `photo_retention_days` (default 30) → cron soft-deletes file + row mark, audit logged.
- Heartbeats 7 days, idempotency keys 48 h, device commands 90 days, audit logs ≥ 1 year.
