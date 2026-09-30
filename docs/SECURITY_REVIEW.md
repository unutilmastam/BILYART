# SECURITY REVIEW (Phase 15)

Manual review checklist, done on 2026-09-30 against `main` + `phase-15-security`. Automated proof for spec §43 is in `docs/TESTING.md` §2. Re-run this review before Phase 16 (production) and after any change to auth, tenancy or file handling.

Legend: ✅ checked and OK · 🔧 fixed in this phase · ⏳ open, planned phase named

## 1. Authentication & sessions
- ✅ Passwords hashed (bcrypt cost 12 / argon2id), never logged, never returned (`$hidden`, log redaction, `PasswordNeverLoggedTest`).
- ✅ Lockout after 5 failures (15 min) + per-IP/login throttle; unknown login ≡ wrong password.
- 🔧 Optional TOTP 2FA for every admin user, recovery codes, replay protection, console last resort (SECURITY.md §1a).
- 🔧 Admin UI had no way to change one's own password → new "Hisobim" (Account) page.
- 🔧 Session cookie is `Secure` in production even when `SESSION_SECURE_COOKIE` is missing from `.env`.
- ✅ Session encrypted, `HttpOnly`, `SameSite=Lax`; CSRF token + Origin check on every cookie-authenticated write; session regenerated on login, invalidated on logout; other devices logged out on password change.
- ✅ Tablet and device tokens: random, shown once, stored as SHA-256, revocable; not interchangeable with each other or with a browser session (`RouteSweepTest`).

## 2. Authorization & tenant isolation
- ✅ `tenant_id` only from the authenticated principal; tenant scope fails closed without a context; `tenant_id` is guarded and immutable.
- ✅ Composite foreign keys make cross-tenant references impossible at the database level.
- 🔧 Automatic route sweep: every `/api/admin/*` route with a model parameter answers 404 to another tenant, for every HTTP method, and tenant B's rows are provably unchanged.
- 🔧 Role gates now run before route-model binding (a client user got 404 instead of 403 on one Super Admin URL — no data leaked, but the order is now strict).
- ✅ Permissions per role via `perm:` middleware; Super Admin has no access to client photos.
- ✅ Branch-restricted staff only see their branches (`BranchAccess`).

## 3. Input handling
- ✅ All request input validated (FormRequests / `validate()`); unknown fields ignored; tenant fields in input ignored.
- ✅ No raw SQL with user input: the only raw statements use constants (CHECK constraints, health `select 1`, PostgreSQL sequence reset over a fixed table list).
- ✅ No `eval`, `unserialize`, `exec`/`shell_exec` in application code.
- ✅ Photo upload: MIME sniffed from bytes (finfo + getimagesize), size/dimension limits, GD re-encode strips metadata and payloads, fixed storage path pattern (no user-controlled path), private disk.
- ✅ Backup archives are authenticated (AES-256-GCM) before they are unpacked, so a tampered archive is rejected before extraction.
- ✅ Telegram token format validated; only `https://api.telegram.org` is ever called (no SSRF); webhook requires the secret header.

## 4. Output & browser
- 🔧 CSP on every response (strict SPA policy with no `unsafe-inline`/`unsafe-eval`; JSON gets `default-src 'none'`); verified in Chromium with zero violations across all Super Admin pages.
- 🔧 `Permissions-Policy` (camera, mic, geolocation… off), `Cross-Origin-Opener-Policy`, `X-Powered-By` removed.
- ✅ React escapes all output; no `dangerouslySetInnerHTML`.
- ✅ Errors: stable code + Uzbek message + request id; never stack traces (`APP_DEBUG=false` in `.env.example`).

## 5. Transport & server
- 🔧 `.htaccess`: HTTPS redirect, HSTS, dotfiles blocked (`.env`, `.git`), directory listing off, security headers for static files.
- ✅ Document root = `apps/api/public`; `storage/`, `vendor/`, `.env` are outside it (DEPLOYMENT.md).
- ⏳ Phase 16: confirm AutoSSL certificate on itcode.uz before enabling the redirect in production; confirm `mod_headers` is active on the hosting (static files then carry the CSP/headers — check `/admin/` with a header viewer after the first deploy; PHP responses carry them regardless).

## 6. Rate limiting & abuse
- 🔧 The `admin` limiter (300/min per user) was defined but unused → now applied to `/api/admin/*` and `/api/super/*`.
- ✅ Login, pairing codes, device/tablet registration, tablet API, photo upload, device poll, Telegram webhook each have their own limiter.
- ✅ Idempotency keys on state-changing tablet/device calls; replay returns the first response.

## 7. Secrets & supply chain
- ✅ gitleaks over the full history in CI; only `.env.example` tracked.
- ✅ Telegram tokens encrypted at rest (APP_KEY), write-only in the API; backup key only in `.env` (BACKUP.md §2).
- 🔧 `npm audit --omit=dev --audit-level=high` for web-admin and protocol in CI (next to `composer audit --no-dev`). Current result: 0 vulnerabilities.
- ✅ Lockfiles committed (`composer.lock`, `package-lock.json`); CI installs with `composer install` / `npm ci`.

## 8. Data protection
- ✅ Photos: private disk, served only through an authorized, audited endpoint; retention job; deletion removes the file.
- ✅ Encrypted daily backups with verification and tested restore (BACKUP.md).
- ✅ Audit log for logins, 2FA changes, limits, payments, photo views/deletions, device pairing.

## 9. Open items (not blockers for Phase 15)
- ⏳ Phase 8: tablet PWA CSP (`camera=(self)`, local MediaPipe WASM with `'wasm-unsafe-eval'`) on its own path.
- ⏳ Phase 11: ESP32 firmware — TLS certificate pinning/validation for the API host and token storage in NVS (DEVICE_PROTOCOL.md).
- ⏳ Phase 16: run the hosting check on the real server; check response headers of the live site (`curl -I`); enable Super Admin 2FA right after the first login.
