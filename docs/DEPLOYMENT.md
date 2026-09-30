# DEPLOYMENT (Hostmaster cPanel)

Owner works from a phone. Everything below is either done by GitHub Actions or by clicking in cPanel.

## 1. Phase 1 — hosting inspection (owner fills, Claude records here)
Owner opens cPanel and reports (screenshots are fine):
- [ ] cPanel → **Select PHP Version**: available versions, extensions list
- [ ] cPanel → **MySQL Databases** (and is there **PostgreSQL Databases**?)
- [ ] cPanel → **Terminal** or **SSH Access** present?
- [ ] cPanel → **Cron Jobs** present?
- [ ] cPanel → **Git Version Control** present?
- [ ] cPanel → **Setup Node.js App** present? (informational only)
- [ ] cPanel → **SSL/TLS Status** (AutoSSL / Let's Encrypt)
- [ ] cPanel → **Domains/Subdomains**: which domain/subdomain for the platform (e.g. `billiard.<domain>`)
- [ ] Disk quota, inode limit, upload_max_filesize, memory_limit, max_execution_time
- [ ] FTP account (for FTPS fallback)

Claude also deploys a temporary `phpinfo`-style check script (protected by a random token, deleted after) to confirm the real values.

Record results in the table below and update ARCHITECTURE.md if anything differs.

| item | value |
|---|---|
| PHP | [VERIFY] |
| DB | [VERIFY] |
| SSH/Terminal | [VERIFY] |
| Cron | [VERIFY] |
| Git VC | [VERIFY] |

## 2. Server layout
```
/home/<user>/billiard/            ← release root (NOT web-accessible)
   current -> releases/2026xxxx   (symlink if SSH available; otherwise single dir)
   shared/.env
   shared/storage/                (private photos, logs, backups)
/home/<user>/billiard.<domain>/   ← subdomain document root → points to billiard/current/public
```
If the document root cannot point outside `public_html`, use a subdomain whose root is set to `billiard/current/public` in cPanel → Domains (cPanel allows custom document roots for subdomains).

## 3. CI/CD (GitHub Actions)
- `ci.yml` (every PR): PHP tests (MySQL service container), web-admin/tablet tests + build, firmware native tests + build, gitleaks.
- `build-apk.yml`: builds `android-kiosk` debug/release APK artifact (signing keystore in GitHub secrets).
- `build-firmware.yml`: builds `.bin` artifact + sha256.
- `deploy.yml` (manual trigger on `main`, `workflow_dispatch`):
  1. `composer install --no-dev --optimize-autoloader`
  2. build web-admin + tablet → copy into `apps/api/public/admin`, `public/tablet`
  3. upload: **SSH/rsync** if available, else **FTPS (lftp mirror)**
  4. run `php artisan migrate --force && php artisan optimize` via SSH; if no SSH, via a one-time signed deploy URL (`/_deploy/migrate?token=…`, token from secret, disabled when `DEPLOY_HOOK_ENABLED=false`).
  5. hit `/health` and fail the job if not OK.

GitHub secrets: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY` or `DEPLOY_FTP_PASSWORD`, `DEPLOY_PATH`, `DEPLOY_HOOK_TOKEN`, `ANDROID_KEYSTORE_*`, `FIRMWARE_REGISTRATION_SECRET`.

## 4. Cron (cPanel → Cron Jobs)
```
* * * * *  /usr/local/bin/php /home/<user>/billiard/current/apps/api/artisan schedule:run >> /dev/null 2>&1
```
(PHP binary path verified in Phase 1.) Scheduler handles: session finalization, reservation expiry, command retry/expiry, device online/offline notifications, subscription status + reminders (5/3/1 days), Telegram daily reports, photo retention, pruning, queue draining, backups.

## 5. Backups (spec §44)
- Daily 03:00 (Asia/Tashkent): `mysqldump --single-transaction` → gzip → `shared/backups/db/` keep 14 daily + 8 weekly.
- Weekly: tar of `storage/app/private` (photos) keep 4.
- Off-host copy: encrypted (openssl AES-256, key in `.env`) DB dump sent to the Super Admin's Telegram chat (optional, `BACKUP_TELEGRAM_ENABLED`) + cPanel's own backups. Owner downloads a monthly copy from cPanel File Manager.
- Verification: monthly restore test into a temporary database (`php artisan backup:verify`), result shown on Super Admin health page.
- Restore steps documented in `docs/BACKUP.md` (Phase 14).

## 6. Environment
`.env.example` lists all keys: APP_*, DB_*, SUPER_ADMIN_LOGIN/PASSWORD (first seed only), DEVICE_REGISTRATION_SECRET, DEVICE_TRANSPORT=http, BACKUP_*, TELEGRAM_WEBHOOK_BASE_URL, PHOTO_RETENTION_DEFAULT_DAYS.
