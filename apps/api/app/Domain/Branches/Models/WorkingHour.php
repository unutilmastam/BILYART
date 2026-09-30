<?php

namespace App\Domain\Branches\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per ISO weekday (1 = Monday). closes_at <= opens_at means the day crosses midnight. */
class WorkingHour extends Model
{
    use BelongsToTenant;

    protected $fillable = ['branch_id', 'weekday', 'opens_at', 'closes_at', 'is_closed'];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    public function crossesMidnight(): bool
    {
        return ! $this->is_closed && $this->opens_at !== null && $this->closes_at !== null && $this->closes_at <= $this->opens_at;
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
