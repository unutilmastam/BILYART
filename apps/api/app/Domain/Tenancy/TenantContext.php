<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Exceptions\TenantContextException;
use Closure;

/**
 * The tenant the current request/job acts for. Set only by the central
 * middleware (from the authenticated user/tablet/device) or explicitly by
 * system code (scheduler, device service). Never from request input.
 *
 * States: NONE (fail closed — tenant models return nothing), TENANT(id), SYSTEM (unscoped).
 */
final class TenantContext
{
    private const NONE = 'none';

    private const TENANT = 'tenant';

    private const SYSTEM = 'system';

    private string $mode = self::NONE;

    private ?int $tenantId = null;

    public function setTenant(int $tenantId): void
    {
        $this->mode = self::TENANT;
        $this->tenantId = $tenantId;
    }

    public function setSystem(): void
    {
        $this->mode = self::SYSTEM;
        $this->tenantId = null;
    }

    public function clear(): void
    {
        $this->mode = self::NONE;
        $this->tenantId = null;
    }

    public function hasTenant(): bool
    {
        return $this->mode === self::TENANT;
    }

    public function isSystem(): bool
    {
        return $this->mode === self::SYSTEM;
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function requireTenantId(): int
    {
        if ($this->mode !== self::TENANT) {
            throw new TenantContextException('A tenant context is required.');
        }

        return (int) $this->tenantId;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsTenant(int $tenantId, Closure $callback): mixed
    {
        return $this->runIn(self::TENANT, $tenantId, $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsSystem(Closure $callback): mixed
    {
        return $this->runIn(self::SYSTEM, null, $callback);
    }

    private function runIn(string $mode, ?int $tenantId, Closure $callback): mixed
    {
        [$prevMode, $prevTenant] = [$this->mode, $this->tenantId];
        $this->mode = $mode;
        $this->tenantId = $tenantId;
        try {
            return $callback();
        } finally {
            $this->mode = $prevMode;
            $this->tenantId = $prevTenant;
        }
    }
}
