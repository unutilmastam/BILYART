# PROGRESS

Updated by Claude Code at the end of every session.

| Phase | Status | Notes |
|---|---|---|
| 0. Architecture pack | DONE | CLAUDE.md + docs prepared before coding |
| 1. Inspect repo & hosting | IN PROGRESS | Skeleton, CI, hosting-check tooling done. Waiting for owner's hosting report (DEPLOYMENT.md §1). |
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

## Open questions for the owner
1. Platform domain/subdomain (e.g. `billiard.<domain>`)? Can its document root be set to a custom folder in cPanel → Domains?
2. First ESP32 flash method: USB-OTG from an Android phone, or one-time on any computer?
3. Tablet model and Android version for kiosk testing? Can it be factory-reset (needed for Device Owner QR provisioning)?
4. How many halls/tables for the pilot, and the lamp power per table (for relay/contactor sizing)?
5. Repository is **public**. Recommend making it private (GitHub → Settings → Danger Zone → Change visibility). Note: private repos have a monthly free Actions-minutes limit.
6. Hostmaster plan name (for LVE limits) — visible in the Hostmaster client area.

## Known issues
- Repository is public: `hosting-check` artifact (1-day retention) is downloadable by any signed-in GitHub user. Mitigated: domain not included in the artifact, file self-deletes on first view and after 24 h, report contains no secrets.
