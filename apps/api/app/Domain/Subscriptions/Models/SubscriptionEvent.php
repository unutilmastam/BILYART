<?php

namespace App\Domain\Subscriptions\Models;

use App\Domain\Subscriptions\Enums\SubscriptionEventType;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SubscriptionEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['type', 'old_value', 'new_value', 'actor_user_id'];

    protected function casts(): array
    {
        return [
            'type' => SubscriptionEventType::class,
            'old_value' => 'array',
            'new_value' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
