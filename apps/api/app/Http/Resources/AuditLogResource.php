<?php

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
final class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actorType' => $this->actor_type->value,
            'actorName' => $this->actor_name ?? null,
            'entityType' => $this->entity_type,
            'entityId' => $this->entity_id,
            'metadata' => $this->metadata,
            'ip' => $this->ip,
            'tenant' => $this->tenant_name ?? null,
            'createdAt' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
