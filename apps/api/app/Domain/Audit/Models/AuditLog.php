<?php

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail (spec §30). Written only through AuditLogger. */
class AuditLog extends Model
{
    use BelongsToTenant;

    protected bool $tenantNullable = true;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
