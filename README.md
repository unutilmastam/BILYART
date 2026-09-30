# Billiard SaaS + IoT Platform

Multi-tenant SaaS for billiard halls: Super Admin (licensing), Client Admin, Android kiosk tablet, ESP32 table-light controllers, Telegram reports.

- Start here: [CLAUDE.md](CLAUDE.md)
- Requirements: [docs/SPEC.md](docs/SPEC.md)
- Architecture: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Plan: [docs/ROADMAP.md](docs/ROADMAP.md) · Status: [docs/PROGRESS.md](docs/PROGRESS.md)

## Repository layout
| path | what |
|---|---|
| `apps/api` | Laravel 11 backend (Phase 3+) |
| `apps/web-admin` | Super Admin + Client Admin SPA (Phase 5+) |
| `apps/tablet` | Tablet kiosk PWA (Phase 8) |
| `apps/android-kiosk` | Kotlin kiosk shell, APK built by CI (Phase 8) |
| `devices/esp32` | ESP32 firmware, `.bin` built by CI (Phase 11) |
| `packages/protocol` | JSON Schemas + TS types (Phase 2) |
| `infrastructure/` | deploy, cron, backup, hosting-check scripts |
| `docs/` | specification and design docs |

All builds and tests run in GitHub Actions (`.github/workflows/`).
