<?php

namespace App\Domain\Users\Auth;

use App\Domain\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;

/**
 * Authentication must find the user *before* a tenant context exists (the
 * tenant is resolved from the user). Only this provider bypasses TenantScope,
 * and only for loading the authenticated principal by id/credentials.
 */
final class TenantAgnosticUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
