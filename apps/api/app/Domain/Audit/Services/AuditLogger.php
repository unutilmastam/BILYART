<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\CurrentPrincipal;
use App\Domain\Tenancy\TenantContext;
use App\Support\Logging\Redactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Writes the append-only audit trail (spec §30). Metadata is redacted; never pass photos or secrets. */
final class AuditLogger
{
    public function __construct(
        private readonly CurrentPrincipal $principal,
        private readonly TenantContext $context,
        private readonly Request $request,
    ) {}

    /**
     * @param  Model|null  $entity  must expose public_id (or pass entityType/entityId in $overrides)
     * @param  array{tenant_id?: int|null, actor_type?: ActorType, actor_id?: int|null, entity_type?: string, entity_id?: string}  $overrides
     */
    public function log(string $action, ?Model $entity = null, array $metadata = [], array $overrides = []): AuditLog
    {
        $principal = $this->principal->get();

        $tenantId = array_key_exists('tenant_id', $overrides)
            ? $overrides['tenant_id']
            : ($this->context->hasTenant() ? $this->context->tenantId() : ($entity?->getAttribute('tenant_id') ?? $principal?->tenantId));

        $row = [
            'tenant_id' => $tenantId,
            'actor_type' => ($overrides['actor_type'] ?? $principal?->type ?? ActorType::SYSTEM)->value,
            'actor_id' => $overrides['actor_id'] ?? $principal?->id,
            'action' => $action,
            'entity_type' => $overrides['entity_type'] ?? ($entity ? class_basename($entity) : null),
            'entity_id' => $overrides['entity_id'] ?? $entity?->getAttribute('public_id'),
            'metadata' => $metadata === [] ? null : Redactor::redact($metadata),
            'ip' => $this->request->ip(),
            'request_id' => $this->request->attributes->get('request_id'),
            'created_at' => now(),
        ];

        return $this->context->runAsSystem(function () use ($row): AuditLog {
            $log = new AuditLog;
            $log->forceFill($row)->save();

            return $log;
        });
    }
}
