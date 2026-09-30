# BACKUP & RESTORE

Spec §44: automated backups, retention, restore, verification. "We have hosting" is not a backup strategy — the hosting's own cPanel backups are an *extra* layer only.

## 1. What is backed up
| What | How | When (Asia/Tashkent) | Kept |
|---|---|---|---|
| Database (all business tables) | `php artisan backup:run` — portable logical export (JSON lines per table, FK order, manifest with row counts + sha256), zipped, **encrypted** (AES-256-GCM, chunked, tamper-evident) | daily 03:00 | 14 newest daily + 8 newest Sunday files |
| Session photos (private storage) | `php artisan backup:run --photos` — encrypted zip | Sundays 04:00 | 4 newest |
| Verification | `php artisan backup:verify` — decrypts the latest file and checks every table against the manifest (read-only) | daily 04:30 | result shown on Super Admin → Tizim holati |

Files: `storage/app/private/backups/db/YYYYMMDD-HHMMSS.blyb`, `.../backups/photos/...` (outside the web root, never served by URL).

Not backed up on purpose: `migrations`, cache tables, queue tables, web sessions, idempotency keys (all regenerated).

Works without `mysqldump`/`pg_dump`/`exec()` (often disabled on shared hosting) and on MySQL, MariaDB and PostgreSQL. CI proves on all three engines: backup → damage the data → restore → data identical (`tests/Feature/Ops/BackupTest.php`).

## 2. The encryption key (critical)
- `.env` → `BACKUP_ENCRYPTION_KEY` = base64 of 32 random bytes. Generate once:
  `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`
- **Keep a copy outside the server** (password manager / printed in a safe). Without it the backups cannot be restored; with it anybody holding a backup file can read it.
- Changing the key: old backups stay readable only with the old key — keep both until the old files expire.

## 3. Off-server copies
1. cPanel → **Backup** (hosting's own full-account backups) — enable if the plan offers it.
2. Monthly: cPanel → **File Manager** → `billiard/shared/storage/app/private/backups/db/` → select the newest `.blyb` → **Download** (a few MB; works from a phone). The file is encrypted, so it is safe to keep in cloud storage.

## 4. Restore (disaster recovery)
Restore replaces **all** business data with the backup's. It requires the same database engine and the same migration version (the command refuses otherwise).

cPanel → **Terminal** (server side, in the browser), then:
```
cd ~/billiard/current/apps/api
php artisan backup:run                        # safety copy of the current state first
ls storage/app/private/backups/db/             # pick a file
php artisan backup:verify backups/db/20261005-220000.blyb
php artisan backup:restore backups/db/20261005-220000.blyb --force
```
The command puts the site into maintenance mode during the restore and brings it back up afterwards.

Restoring onto a **new** server: deploy the same release, set `.env` (including the same `BACKUP_ENCRYPTION_KEY`), run `php artisan migrate --force`, upload the `.blyb` file to `storage/app/private/backups/db/`, then run the restore commands above. Photos: decrypt + unzip the photos archive into `storage/app/private/` (same key; `BackupCrypto::decryptFile`).

## 5. Monitoring
`/health/backups` (details for the Super Admin or `HEALTH_TOKEN` holders): `fail` if the last backup failed or none exists, `warn` if the last one is older than 30 h or the last verification failed.
