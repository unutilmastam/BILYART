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
