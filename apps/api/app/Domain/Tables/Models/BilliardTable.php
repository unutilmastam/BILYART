<?php

namespace App\Domain\Tables\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Devices\Models\Device;
use App\Domain\Pricing\Models\PricingPlan;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Database\Factories\BilliardTableFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[UseFactory(BilliardTableFactory::class)]
class BilliardTable extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'billiard_tables';

    protected $fillable = ['branch_id', 'number', 'name', 'is_active', 'pricing_plan_id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<PricingPlan, $this> */
    public function pricingPlan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class);
    }

    /** Currently paired ESP32. @return HasOne<Device, $this> */
    public function device(): HasOne
    {
        return $this->hasOne(Device::class, 'active_table_id');
    }
}
