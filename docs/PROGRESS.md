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
