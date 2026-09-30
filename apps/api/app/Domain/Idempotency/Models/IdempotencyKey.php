<?php

namespace App\Domain\Idempotency\Models;

use Illuminate\Database\Eloquent\Model;

/** Stored response for a (principal, Idempotency-Key) pair; pruned after 48 h. */
class IdempotencyKey extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'principal_id' => 'integer',
            'response_code' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
