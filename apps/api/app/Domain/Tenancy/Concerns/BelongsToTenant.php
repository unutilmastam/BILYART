<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Exceptions\TenantContextException;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned model: global TenantScope + tenant_id assigned from the
 * TenantContext on create (never from input) + tenant_id is immutable once set.
 *
 * Models whose tenant_id may be NULL (unpaired devices/tablets, platform
 * notifications/audit) set `protected bool $tenantNullable = true;`.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($context->hasTenant()) {
                $given = $model->getAttribute('tenant_id');
                if ($given !== null && (int) $given !== $context->tenantId()) {
                    throw new TenantContextException('Refusing to create a record for another tenant.');
                }
                $model->setAttribute('tenant_id', $context->tenantId());

                return;
            }

            if (! $context->isSystem()) {
                throw new TenantContextException('Tenant-owned record created without tenant context.');
            }

            if ($model->getAttribute('tenant_id') === null && ! $model->tenantIsNullable()) {
                throw new TenantContextException('System context must set tenant_id explicitly.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id') && $model->getOriginal('tenant_id') !== null) {
                throw new TenantContextException('tenant_id is immutable.');
            }
        });
    }

    public function initializeBelongsToTenant(): void
    {
        // tenant_id is never mass-assignable.
        $this->guard(array_values(array_unique(array_merge($this->getGuarded(), ['tenant_id']))));
    }

    public function tenantIsNullable(): bool
    {
        return property_exists($this, 'tenantNullable') && $this->tenantNullable === true;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
