<?php

namespace App\Domain\Sessions\Models;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** State-machine history row (append-only). */
class SessionEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['session_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'reason', 'metadata'];

    protected function casts(): array
    {
        return [
            'from_status' => SessionStatus::class,
            'to_status' => SessionStatus::class,
            'actor_type' => ActorType::class,
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
