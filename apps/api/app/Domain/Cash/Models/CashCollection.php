<?php

namespace App\Domain\Cash\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** Staff emptied a bill acceptor's box: expected (system) vs counted (staff) amount, UZS. */
class CashCollection extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'integer',
            'counted_amount' => 'integer',
            'notes_count' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
