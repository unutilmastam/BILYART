<?php

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Filters tenant-owned models by the current TenantContext. Fails closed when no context is set. */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $column = $model->qualifyColumn('tenant_id');

        if ($context->isSystem()) {
            return;
        }

        if ($context->hasTenant()) {
            $builder->where($column, $context->tenantId());

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
