# infrastructure

Deployment scripts, cron definitions, backup scripts.

| path | purpose |
|---|---|
| `hosting-check/` | One-time Phase 1 script that reports the real Hostmaster PHP/hosting capabilities. See [docs/HOSTING_CHECK_UZ.md](../docs/HOSTING_CHECK_UZ.md). |
| `release/build.sh` | Builds `bilyart-<version>.zip` (API + vendor + PWA builds + deploy scripts). Used by the "Release package" workflow. |
| `deploy/activate.sh` | Runs on the hosting (cPanel Terminal): install/update with shared `.env`/storage, migrate, cache, atomic switch. Owner guide: [docs/DEPLOY_UZ.md](../docs/DEPLOY_UZ.md). |
| `deploy/rollback.sh` | Switches back to the previous release. |
| `deploy/tests/run.sh` | CI test: package → install → update → rollback in a throw-away `$HOME` against a real database. |
