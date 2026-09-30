<?php

namespace Tests;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tenantContext(): TenantContext
    {
        return $this->app->make(TenantContext::class);
    }

    /**
     * Test setup helper: run factories/queries in SYSTEM context (unscoped, tenant_id explicit).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function asSystem(Closure $callback): mixed
    {
        return $this->tenantContext()->runAsSystem($callback);
    }
}
