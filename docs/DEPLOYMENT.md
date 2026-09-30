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

To confirm the real values, the owner also runs the one-time check script `infrastructure/hosting-check/hostcheck.php`:
- built by the `hosting-check.yml` workflow (manual trigger) with a random file name and a random 48-hex token → artifact `hosting-check` (retention 1 day);
- owner uploads it via cPanel File Manager and opens `https://<domain>/hostcheck-<rand>.php?t=<token>`;
- it reports PHP version/SAPI, extensions, ini limits, disabled functions, CLI binaries (PHP path for cron, mysqldump, git, node, python), symlink support, writable dirs outside the docroot, disk space, outbound HTTPS (Telegram, GitHub);
- it never prints environment variables or credentials, deletes itself after the first successful view, and refuses to run (and deletes itself) 24 h after upload. Tests: `infrastructure/hosting-check/tests/run.sh`.

Owner-facing click-by-click guide (Uzbek): [HOSTING_CHECK_UZ.md](HOSTING_CHECK_UZ.md).

Record results in the table below and update ARCHITECTURE.md if anything differs.

Status: **partially answered** (Phase 1). cPanel Tools screenshot received 2026-09-30; hostcheck report still pending.

Seen in the cPanel Tools screenshot (theme Jupiter, CloudLinux — "Resource Usage", "X-Ray App", "AccelerateWP" present; cPanel user `unutilmastam`, home `/home/unutilmastam`, AutoSSL active):
- Files: File Manager, FTP Accounts, Backup / Backup Wizard, **Git Version Control**, File and Directory Restoration
- Databases: phpMyAdmin, Manage My Databases (MySQL/MariaDB), Remote Database Access, **PostgreSQL Databases** + phpPgAdmin
- Domains: Domains, Redirects, Zone Editor, Dynamic DNS
- Security: **SSH Access**, SSL/TLS Certificates, Manage API Tokens, Two-Factor Authentication
- Software: MultiPHP Manager, MultiPHP INI Editor, **Select PHP Version** (CloudLinux PHP selector), Setup Node.js App, Setup Python App, Setup Ruby App
- Advanced: **Terminal**, **Cron Jobs**
- Metrics: Resource Usage (LVE limits)

Platform domain: **itcode.uz** (owner, 2026-09-30).

| item | value | affects |
|---|---|---|
| PHP version / SAPI | [VERIFY] | Laravel 13 needs ≥ 8.3 |
| Required extensions | [VERIFY] | pdo_mysql, openssl, mbstring, intl, fileinfo, sodium, curl, zip, bcmath, gd/imagick |
| DB engine + version (MySQL/MariaDB, PostgreSQL?) | Both MySQL (phpMyAdmin) and PostgreSQL (phpPgAdmin) present; versions [VERIFY] | DATABASE.md generated column + CHECK constraints need MySQL ≥ 8.0.16 / MariaDB ≥ 10.6 |
| SSH / Terminal | YES — SSH Access + Terminal present (key-based SSH for CI to be confirmed) | deploy via rsync+SSH vs FTPS + deploy hook |
| Cron Jobs + PHP CLI path | Cron Jobs present; PHP CLI path [VERIFY via hostcheck] | `schedule:run` every minute |
| Git Version Control | YES | informational |
| Node.js app | YES (Setup Node.js App; not used) | informational (not used) |
| SSL (AutoSSL) | SSL certificate active on primary domain; itcode.uz coverage [VERIFY] | HTTPS mandatory, ESP32 pinned root CA |
| Domain / subdomain + custom document root | itcode.uz; subdomain + document root [VERIFY] | server layout §2 |
| memory_limit / max_execution_time / upload_max_filesize | [VERIFY] | photo upload (2 MB), queue worker `--max-time=50` |
| Disk quota / inodes | [VERIFY] | photo retention, backups |
| LVE: entry processes / processes / RAM | CloudLinux LVE (Resource Usage page) — values [VERIFY] | device poll load (~17 req/s at 50 devices) |
| shell_exec / proc_open enabled | [VERIFY] | `mysqldump` backups |
| symlink works | [VERIFY] | `current -> releases/…` layout |
| Outbound HTTPS to api.telegram.org | [VERIFY] | Telegram reports |
| FTP account | [VERIFY] | FTPS fallback deploy |

## 2. Server layout (implemented: `infrastructure/deploy/activate.sh`)
```
/home/<user>/billiard/                   ← NOT web-accessible (chmod 700)
   releases/<version>-<yyyymmddHHMMSS>/  ← one per release (newest 3 kept)
      apps/api/.env      -> ../../../shared/.env
      apps/api/storage   -> ../../../shared/storage
   shared/.env                           ← created on the first run (chmod 600, APP_KEY/BACKUP_ENCRYPTION_KEY/HEALTH_TOKEN generated)
   shared/storage/                       ← photos, backups, logs, sessions: survive every update
   current -> releases/…                 ← switched atomically (ln + mv -T)
Domain document root = billiard/current/apps/api/public
```
`activate.sh` steps: PHP ≥ 8.3 + extension check → shared dirs → `.env` (first run: create and stop) → copy release, link shared files → `artisan about` → maintenance on (old release) → `migrate --force` → `config:cache route:cache event:cache` → switch `current` → maintenance off → prune old releases → `/health` probe. Any failure before the switch leaves the old release live. `rollback.sh` points `current` back to the previous release (migrations are not reverted; data problems → BACKUP.md).
Owner guide (Uzbek, step by step): **[DEPLOY_UZ.md](DEPLOY_UZ.md)**.

## 3. CI/CD (GitHub Actions)
- `ci.yml` (every PR / push to main): API tests on 3 engines, web-admin, tablet, protocol, firmware (host tests + `.bin`), hosting-check tests, gitleaks, **deploy-scripts** (builds the package and runs install → update → rollback against MySQL in a throw-away `$HOME`: `infrastructure/deploy/tests/run.sh`).
- `release.yml` (manual, `version` input): `infrastructure/release/build.sh` → `bilyart-<version>.zip` + `.sha256` artifact. Contents: `apps/api` (no tests), `vendor` (`--no-dev --classmap-authoritative`, git histories stripped), admin + tablet PWA builds in `public/`, `activate.sh`, `rollback.sh`, `VERSION`, `COMMIT`. Never `.env`. Built on PHP 8.3 (lowest supported).
- `deploy.yml` (manual, `version` + typed `DEPLOY`): guard (secrets present) → `release.yml` → checksum → `scp` + `ssh … activate.sh` → `/health` retry. Uses GitHub environment `production`.
  - Secrets: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS` (strict host key checking), optional `DEPLOY_PORT`. Variables: `DEPLOY_URL`, optional `DEPLOY_PHP_BIN`.
  - The first install is manual (DEPLOY_UZ.md) because `.env` must be filled by the owner.
- Firmware: the `firmware` job in `ci.yml` (`workflow_dispatch` input `firmware_version` for OTA releases), secret `DEVICE_REGISTRATION_SECRET`.
- No FTP deploy and no web "deploy hook": cPanel Terminal/SSH are available (§1), so migrations always run from the CLI.

## 4. Cron (cPanel → Cron Jobs)
```
* * * * *  <php path printed by activate.sh> /home/<user>/billiard/current/apps/api/artisan schedule:run >> /dev/null 2>&1
```
The schedule (routes/console.php): session finalization, device monitor, notifications, subscription checks, Telegram daily reports, photo retention, pruning, backups 03:00 Tashkent + weekly photo archive + daily verify, scheduler heartbeat (shown in `/health/messaging`). There is no queue worker: nothing is queued (`QUEUE_CONNECTION` is unused).

## 4a. First Super Admin
`php artisan admin:create-super <login>` asks for the password (hidden, twice, ≥ 12 chars) — nothing is written to `.env`. The older `db:seed --class=PlatformSeeder` path (SUPER_ADMIN_LOGIN/PASSWORD) still works, also with a cached config.

## 5. Backups (spec §44) — implemented as described in [BACKUP.md](BACKUP.md) (portable encrypted logical backups; the mysqldump plan below was replaced because exec() is often disabled on shared hosting)
- Daily 03:00 (Asia/Tashkent): `mysqldump --single-transaction` → gzip → `shared/backups/db/` keep 14 daily + 8 weekly.
- Weekly: tar of `storage/app/private` (photos) keep 4.
- Off-host copy: encrypted (openssl AES-256, key in `.env`) DB dump sent to the Super Admin's Telegram chat (optional, `BACKUP_TELEGRAM_ENABLED`) + cPanel's own backups. Owner downloads a monthly copy from cPanel File Manager.
- Verification: monthly restore test into a temporary database (`php artisan backup:verify`), result shown on Super Admin health page.
- Restore steps documented in `docs/BACKUP.md` (Phase 14).

## 6. Environment
`.env.example` lists all keys: APP_*, DB_*, DEVICE_REGISTRATION_SECRET (= the GitHub secret used for firmware builds), DEVICE_*, BACKUP_* (key generated by activate.sh), HEALTH_TOKEN (generated), TELEGRAM_WEBHOOK_BASE_URL, PHOTO_RETENTION_DEFAULT_DAYS, optional SUPER_ADMIN_LOGIN/PASSWORD (seeder path only).
