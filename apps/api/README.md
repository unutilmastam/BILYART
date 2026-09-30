# apps/api

Laravel 13 backend: REST API (admin, tablet, device), scheduler, Telegram, private storage.

- Layout: `app/Domain/<Domain>/{Models,Enums,Services,...}` — see [ARCHITECTURE §4](../../docs/ARCHITECTURE.md).
- Tenancy: `App\Domain\Tenancy\TenantContext` (NONE → fail closed, TENANT, SYSTEM) + `BelongsToTenant` trait (global scope, tenant_id from context, immutable, never mass-assignable).
- Database: portable MySQL 8 / MariaDB 10.6 / PostgreSQL 13 — [DATABASE](../../docs/DATABASE.md). Helpers for CHECKs: `App\Support\Database\Constraints`.

```
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan test                      # MySQL at 127.0.0.1:3306 (see phpunit.xml)
scripts/test-all-db.sh --start        # once: local MySQL/MariaDB/PostgreSQL containers
scripts/test-all-db.sh                # full suite on all three engines
vendor/bin/pint --test
```
