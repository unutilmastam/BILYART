# SECURITY

## 1. Principals & authentication
| Principal | Mechanism |
|---|---|
| SUPER_ADMIN | Laravel session cookie (`web` guard, same-origin SPA — Sanctum is not needed), separate route group `/api/super/*`, optional TOTP 2FA (Phase 15) |
| CLIENT_OWNER / MANAGER / OPERATOR | Same cookie auth, tenant-scoped |
| TABLET | Random bearer token issued once at pairing, stored as SHA-256 (`tablets.token_hash`), bound to one branch; revoke = status REVOKED (re-pair issues a new row + token) |
| DEVICE (ESP32) | Device token (hashed SHA-256 in DB), `Authorization: Device …`, see DEVICE_PROTOCOL.md |

- Passwords: `Hash::make` (argon2id if available, else bcrypt cost ≥ 12). Never logged (`$hidden`, log redaction processor).
- Cookies: `Secure`, `HttpOnly`, `SameSite=Lax`, session encrypted; CSRF via Laravel `PreventRequestForgery` (token + Origin check). SPA calls `GET /api/auth/csrf` once to receive `XSRF-TOKEN`.
- Lockout: 5 consecutive failed logins → 15 min lock per account (`LoginService`) + throttle 5/min per IP+login and 20/min per IP. Unknown login and wrong password return the same `INVALID_CREDENTIALS`. Deactivated users/tenants → `ACCOUNT_DISABLED`; a user deactivated mid-session is logged out on the next request.
- Request pipeline (implemented in `bootstrap/app.php` + `routes/api.php`): `AssignRequestId` → `web` group (session, CSRF) → `auth:web` → `tenant.user` (`ResolveUserTenant`: TenantContext + Principal from the user; runs before route-model binding) → `tenant.member` / `super.admin` → `perm:<permission>` → `subscription.active` → `idempotency` → controller. Tablets: `auth.tablet` (hashed bearer token) sets the same context.
- HTTPS only (`.htaccess` redirect + HSTS).

## 2. Authorization
Permissions are explicit strings mapped to roles in `config/permissions.php` (single source), checked with Gates/Policies:

| permission | OWNER | MANAGER | OPERATOR |
|---|---|---|---|
| tables.view, sessions.view, sessions.create | ✓ | ✓ | ✓ |
| sessions.stop, sessions.mark_payment | ✓ | ✓ | ✓ |
| photos.view | ✓ | ✓ | – (configurable) |
| photos.delete | ✓ | ✓ | – |
| reports.view | ✓ | ✓ | – |
| tables.manage, pricing.manage, working_hours.manage, devices.manage | ✓ | ✓ | – |
| users.manage | ✓ | ✓ (not owners) | – |
| branches.manage, telegram.manage, tenant.settings | ✓ | – | – |

SUPER_ADMIN has only `platform.*` permissions — it does **not** automatically see tenant session photos (spec §41). Support access, if ever added, must be explicit, time-limited and audited.

## 3. Tenant isolation checklist
- tenant_id never read from request body/query/header.
- Global scope on every tenant model + composite FKs (DATABASE.md).
- Route model binding by `public_id` inside tenant scope → cross-tenant IDs return **404**, not 403 (no existence leak).
- Tests: for every admin endpoint, a generated test that Tenant B's token gets 404 on Tenant A's resources (spec §43).

## 4. Subscription enforcement
Middleware `subscription.active` on all tenant business routes, tablet routes and session-start. EXPIRED/SUSPENDED → 402 `SUBSCRIPTION_INACTIVE`; allowed: login, `/api/me`, `/api/subscription`, data export. Active sessions already running are allowed to finish (light must still turn off); device endpoints keep working for STOP/sync.

## 5. Rate limits
login 5/min/IP+login, pairing code entry 5/15 min/tenant, device register 10/h/IP, tablet API 120/min/tablet, photo upload 10/min/tablet, device poll 40/min/device.

## 6. Photos
- Upload: max 2 MB, `image/jpeg|png|webp` by content sniffing (`finfo`) cross-checked with `getimagesize`, dimensions 320–2560 px, **re-encoded to JPEG with GD** (strips EXIF/GPS, destroys polyglots — tested with an appended `<?php` payload and a fake GPS EXIF segment), server-generated name `{ulid}.jpg`. Only while the session is RESERVED (a retake replaces the previous file); tablet must own the session.
- Path: `storage/app/private/tenants/{tenantId}/sessions/{sessionPublicId}/{ulid}.jpg` — outside document root.
- Served only via `GET /api/admin/photos/{publicId}`: tenant scope (other tenant → 404) + branch access + `photos.view` (operators only if the tenant enables `operators_can_view_photos`; SUPER_ADMIN never); `Cache-Control: no-store, private`; every view audited (`photo.viewed`). The web-admin loads the image only when staff press "Suratni ko'rish".
- Storage adapter `PhotoStorage` (v1 `LocalPrivateDisk`), paths re-validated against a strict pattern (no traversal).
- Deletion: file removed + row `deleted_at`, audit `photo.deleted`; deleted → 404.
- Retention job per tenant setting. Privacy notice text configurable per tenant and shown on tablet camera screen.

## 7. Secrets
`.env` only (never committed; `.env.example` with placeholders). Telegram bot tokens encrypted with `Crypt` (APP_KEY) in DB. GitHub Actions secrets for deploy credentials. `gitleaks` runs in CI.

## 8. Error handling & logging
- JSON error shape: `{ "error": { "code": "TABLE_UNAVAILABLE", "message": "<uz user-friendly>", "requestId": "…" } }` rendered by `ApiErrorRenderer` for every exception — no stack traces even with `APP_DEBUG=true`. Unexpected errors are logged as `SERVER_ERROR` with the request id.
- Laravel's local-disk file serving (`/storage/{path}`) is disabled (`serve => false`): private files are never reachable by URL.
- Daily log files (14 days) with request id; `RedactSensitiveProcessor` masks any key containing password/secret/…token, `authorization`, `cookie` in log context (tested, spec §43.13). Audit metadata goes through the same `Redactor`.

## 9. Web hardening
CSP (self + MediaPipe CDN/WASM hosted locally preferred), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy: camera=(self)` on tablet only. Disable directory listing. `storage/` and `.env` unreachable (document root = `public/`).

## 10. Security test list
Spec §43 items 1–15 become `tests/Feature/Security/*` and must all pass before Phase 16.
