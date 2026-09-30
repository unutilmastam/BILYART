<?php

namespace App\Domain\Tablets\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tablets\Enums\TabletStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kiosk tablet registration row; bound to one branch once paired (spec §37). */
class Tablet extends Model
{
    use BelongsToTenant, HasPublicId;

    protected bool $tenantNullable = true;

    protected $fillable = ['name', 'app_version', 'device_model'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'status' => TabletStatus::class,
            'last_seen_at' => 'immutable_datetime',
            'registered_at' => 'immutable_datetime',
            'paired_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
