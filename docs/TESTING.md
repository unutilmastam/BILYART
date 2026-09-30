# TESTING

How the project is tested and where each security requirement is proven.

## 1. Test runners
| What | Where it runs | Command |
|---|---|---|
| API (PHPUnit, ~200 tests) | CI matrix: MySQL 8 / MariaDB 10.6 / PostgreSQL 13 on PHP 8.3, MySQL on PHP 8.4 | `cd apps/api && php artisan test` (all engines locally: `scripts/test-all-db.sh`) |
| Admin PWA (Vitest + Testing Library) | CI | `cd apps/web-admin && npm test` |
| Protocol schemas | CI | `cd packages/protocol && npm test` |
| Hosting check script | CI | `infrastructure/hosting-check/tests/run.sh` |
| Secret scan | CI (full history) | `gitleaks git --redact .` |
| Dependency audit | CI | `composer audit --no-dev`, `npm audit --omit=dev --audit-level=high` |
| Code style | CI | `vendor/bin/pint --test` |

Migrations are also checked in CI: `migrate` → `migrate:rollback` → `migrate` on every engine.

## 2. Spec §43 — security testing map
All tests below are in `apps/api/tests/Feature/` and run on all three database engines.

| # | Requirement | Proven by |
|---|---|---|
| 1 | Client A cannot access Client B | `Security/TenantIsolationApiTest::item_1_client_a_only_sees_its_own_data`, `Tenancy/TenantScopeTest` (fail-closed scope), `Database/TenantIsolationConstraintsTest` (composite FKs), `Sessions/ReportsAndDashboardTest::reports_never_include_other_tenants` |
| 2 | …not by changing the URL | `Security/RouteSweepTest::every_client_admin_route_with_a_model_parameter_returns_404_for_another_tenants_record` (sweeps **every** admin route, all HTTP methods, incl. own-parent/foreign-child nesting, and proves tenant B's rows are unchanged), `…::a_tablet_cannot_read_or_act_on_another_tenants_session`, `Security/TenantIsolationApiTest::item_2_…` |
| 3 | …not by modifying API parameters | `Security/TenantIsolationApiTest::item_3_tenant_parameters_in_query_body_or_headers_are_ignored`, `Tenancy/TenantScopeTest::no_tenant_owned_model_allows_mass_assigning_tenant_id`, `Admin/TablesAndPricingTest::another_tenants_branch_or_plan_cannot_be_referenced`, `Devices/DeviceProtocolTest::device_supplied_tenant_fields_are_ignored_and_online_status_is_real` |
| 4 | …cannot register Client B's device | `Devices/DeviceProtocolTest::item_4_client_a_cannot_take_client_b_device`, `Database/TenantIsolationConstraintsTest::a_device_cannot_be_paired_to_another_tenants_table` |
| 5 | Expired client cannot start sessions | `Sessions/SessionFlowTest::spec_43_item_5_expired_client_cannot_start_sessions_and_keeps_data` |
| 6 | Expired client data remains intact | same test + `Security/TenantIsolationApiTest::expired_or_suspended_clients_are_blocked_from_business_routes_but_keep_their_data`, `SuperAdmin/TenantManagementTest::suspend_blocks_the_client_and_activate_restores_without_data_loss` |
| 7 | Branch limit cannot be bypassed | `Admin/LicenseLimitsTest::item_7_branch_limit_cannot_be_bypassed_by_disabling_and_re_enabling`, `…::spec_61_branch_limit_scenario` |
| 8 | Table limit cannot be bypassed | `Admin/LicenseLimitsTest::item_8_table_limit_cannot_be_bypassed` |
| 9 | Duplicate session requests → one session | `Sessions/ConcurrentStartTest::parallel_prepare_on_one_table_has_exactly_one_winner` (8 real parallel processes), `Database/DoubleBookingConstraintTest`, `Sessions/SessionFlowTest::the_same_idempotency_key_never_creates_two_sessions`, `Http/IdempotencyTest` |
| 10 | Unauthorized device commands rejected | `Devices/DeviceProtocolTest::item_10_unauthorized_device_calls_are_rejected`, `Security/RouteSweepTest::tablet_and_device_routes_require_their_own_credentials` |
| 11 | Unauthorized photo access rejected | `Photos/PhotoTest::item_11_only_authorized_users_of_the_same_tenant_can_view_and_views_are_audited`, `Auth/PermissionMatrixTest::super_admin_has_only_platform_permissions_and_no_photo_access`, `Photos/PhotoTest::another_tablet_cannot_upload_to_a_foreign_session` |
| 12 | Deleted photos no longer accessible | `Photos/PhotoTest::item_12_deleted_photos_are_gone_and_the_deletion_is_audited`, `…::retention_job_deletes_old_photos_per_tenant_setting` |
| 13 | Passwords never logged | `Security/PasswordNeverLoggedTest::passwords_never_reach_logs_audit_rows_or_the_database_in_clear` |
| 14 | Secrets never committed | CI job **Secret scan (gitleaks)** over the full history; only `.env.example` files are tracked |
| 15 | Telegram credentials protected | `Telegram/TelegramTest::item_15_the_token_never_leaves_the_server`, `…::configuring_verifies_the_token_sets_a_secret_webhook_and_stores_the_token_encrypted`, `…::webhook_requires_the_secret_and_links_chats_with_a_one_time_code` |

Additional hardening tests: `Security/RouteSweepTest` (guests get 401 on every admin/super/account route; client users get 403 on every Super Admin route), `Security/SecurityHeadersTest` (CSP and headers; `.htaccess` copies in sync), `Auth/LoginTest` (lockout, throttling), `Auth/TwoFactorTest` (TOTP, RFC 6238 vectors, replay, recovery codes).

## 3. End-to-end without hardware
`tests/Support/DeviceSimulator.php` speaks the real device protocol (register → pair → poll → ack, local end-at, offline). The spec §61 scenarios run against it (`Devices/*`, `Sessions/*`). Real ESP32 hardware tests follow in Phase 11 (`pio test`).
